<?php
/**
 * 리드-계약/견적 연결 후보 dry-run 리포트 (R4 T7 — planner S1·P-2)
 *
 * 현 데이터는 계약→리드 연결 0건(계약 5건 전부)·견적→리드 연결 0건(planner A-1)이라
 * 파이프라인 자동 산정의 '연결 계약' 신호가 작동하지 않는다. 이 스크립트는
 * 고객+시기+금액 휴리스틱으로 연결 "후보"만 출력한다.
 *
 *   ⚠ 자동 UPDATE 금지(정책 확인 P-2): 금액 불일치(예: 리드4 4,200만 vs C2026-0004 1,540만)로
 *     동일 딜을 데이터만으로 단정할 수 없다. 확정 연결은 사용자 승인 후 별도 작업.
 *
 * 휴리스틱:
 *  · 리드↔계약: 동일 고객 + 계약 성립/파기 시점이 리드 등록 이후
 *      (진행군 계약은 contract_date, 파기/취소는 terminated_date 기준) — 금액 비율은 참고 표시.
 *  · 견적 lead_id 백필: lead_id NULL 견적 + 동일 고객 리드(견적 작성일 ≥ 리드 등록일).
 *
 * 실행: php scripts/lead_link_candidates.php   (읽기 전용 — DB 무변경)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI 전용 스크립트입니다.\n");
    exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
$GLOBALS['config'] = require __DIR__ . '/../app/config/config.php';
foreach (['Util', 'Db'] as $c) {
    require_once APP_PATH . '/core/' . $c . '.php';
}

echo "══════ 리드-계약/견적 연결 후보 리포트 (dry-run 전용 · DB 무변경) ══════\n\n";

// ── A. 연결 실태 요약 ──
$totContracts = (int) Db::val('SELECT COUNT(*) FROM contracts WHERE deleted_at IS NULL');
$linkedContracts = (int) Db::val(
    'SELECT COUNT(*) FROM contracts c JOIN quotes q ON q.id = c.quote_id
     WHERE c.deleted_at IS NULL AND q.deleted_at IS NULL AND q.lead_id IS NOT NULL'
);
$totQuotes = (int) Db::val('SELECT COUNT(*) FROM quotes WHERE deleted_at IS NULL');
$linkedQuotes = (int) Db::val('SELECT COUNT(*) FROM quotes WHERE deleted_at IS NULL AND lead_id IS NOT NULL');
printf("연결 실태: 계약 %d건 중 리드 연결 %d건 · 견적 %d건 중 lead_id 연결 %d건\n\n",
    $totContracts, $linkedContracts, $totQuotes, $linkedQuotes);

// ── B. 리드 ↔ 계약 후보 (동일 고객 + 시기 순방향) ──
$rows = Db::all(
    "SELECT l.id AS lead_id, c2.name AS customer, ps.name AS lead_stage, l.expected_amount,
            DATE(l.created_at) AS lead_created,
            c.id AS contract_id, c.contract_no, c.status AS contract_status, c.contract_amount,
            c.contract_date,
            (SELECT MAX(t.terminated_date) FROM contract_terminations t WHERE t.contract_id = c.id) AS terminated_date
     FROM leads l
     JOIN pipeline_stages ps ON ps.id = l.stage_id
     JOIN customers c2 ON c2.id = l.customer_id
     JOIN contracts c ON c.customer_id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN quotes lq ON lq.id = c.quote_id AND lq.lead_id IS NOT NULL
     WHERE l.deleted_at IS NULL AND lq.id IS NULL
     ORDER BY l.id, c.id"
);
echo "── B. 리드 ↔ 계약 연결 후보 (동일 고객 · 시기 순방향 — 자동 반영 금지) ──\n";
$found = 0;
foreach ($rows as $r) {
    $effective = in_array($r['contract_status'], ['terminated', 'cancelled'], true)
        ? ($r['terminated_date'] ?? $r['contract_date'])
        : $r['contract_date'];
    if ($effective === null || $effective < $r['lead_created']) {
        continue; // 계약 성립/파기가 리드 등록보다 앞서면 별개 딜 가능성이 높아 후보 제외
    }
    $found++;
    $la = (float) $r['expected_amount'];
    $ca = (float) $r['contract_amount'];
    $ratio = $la > 0 ? round($ca / $la * 100) : null;
    printf(
        "  후보: 리드#%d(%s·%s·예상 %s원, 등록 %s) ↔ %s(%s·%s원, 기준일 %s) — 금액비 %s\n",
        $r['lead_id'], $r['customer'], $r['lead_stage'], number_format($la),
        $r['lead_created'], $r['contract_no'], $r['contract_status'], number_format($ca),
        $effective, $ratio === null ? '-' : $ratio . '%'
    );
}
if ($found === 0) {
    echo "  후보 없음\n";
}
echo "\n";

// ── C. quotes.lead_id 백필 후보 (lead_id NULL 견적 + 동일 고객 리드) ──
$qrows = Db::all(
    "SELECT q.id AS quote_id, q.quote_no, q.status AS quote_status, DATE(q.created_at) AS quote_created,
            c2.name AS customer, l.id AS lead_id, ps.name AS lead_stage, DATE(l.created_at) AS lead_created
     FROM quotes q
     JOIN customers c2 ON c2.id = q.customer_id
     JOIN leads l ON l.customer_id = q.customer_id AND l.deleted_at IS NULL
     JOIN pipeline_stages ps ON ps.id = l.stage_id
     WHERE q.deleted_at IS NULL AND q.lead_id IS NULL
     ORDER BY q.id, l.id"
);
echo "── C. quotes.lead_id 백필 후보 (자동 반영 금지) ──\n";
$qfound = 0;
foreach ($qrows as $r) {
    if ($r['quote_created'] < $r['lead_created']) {
        continue;
    }
    $qfound++;
    printf(
        "  후보: %s(%s, 작성 %s) ↔ 리드#%d(%s·%s, 등록 %s)\n",
        $r['quote_no'], $r['quote_status'], $r['quote_created'],
        $r['lead_id'], $r['customer'], $r['lead_stage'], $r['lead_created']
    );
}
if ($qfound === 0) {
    echo "  후보 없음\n";
}

echo "\n결론: 위 후보는 참고용이며 UPDATE 는 수행하지 않았다. 확정 연결 기준(P-2)은 사용자 확인 후 별도 보정.\n";
