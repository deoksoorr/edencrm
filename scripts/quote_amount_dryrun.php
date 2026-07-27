<?php
/**
 * R5 T2 — 견적 amount 신규 산식 대사 dry-run.
 * 신규 확정 산식: amount = (면적>0 ? 면적×수량×단가 : 수량×단가) + 재료비+인건비+장비비+외주비+기타비.
 * 저장된 quote_items.amount / quote_versions.subtotal 이 신규 산식과 불일치하는 견적을 나열한다.
 *
 * 실행: php scripts/quote_amount_dryrun.php          → 리포트만 (변경 없음)
 *       php scripts/quote_amount_dryrun.php --fix    → 계약 미연결 견적만 자동 보정
 * 정책: 계약 연결 견적은 보정하지 않는다(계약·매출 대사 보호 — 목록 보고만).
 *       VAT 파생은 저장 경로와 동일하게 settings.vat_rate 기준(총액 = 공급가액+VAT−할인).
 */
require __DIR__ . '/tests/bootstrap.php';

$doFix = in_array('--fix', $argv ?? [], true);
$vatRate = (float) (($GLOBALS['settings']['vat_rate'] ?? null) ?: 10);

$rows = Db::all(
    "SELECT qi.id AS item_id, qi.name, qi.area, qi.qty, qi.unit_price,
            qi.material_cost, qi.labor_cost, qi.equipment_cost, qi.outsourcing_cost, qi.etc_cost,
            qi.amount AS stored_amount,
            qv.id AS version_id, qv.version_no, qv.subtotal, qv.vat, qv.discount, qv.total_amount,
            q.id AS quote_id, q.quote_no, q.current_version_id,
            (SELECT c.contract_no FROM contracts c WHERE c.quote_id = q.id AND c.deleted_at IS NULL LIMIT 1) AS contract_no
     FROM quote_items qi
     JOIN quote_versions qv ON qv.id = qi.quote_version_id
     JOIN quotes q ON q.id = qv.quote_id
     WHERE q.deleted_at IS NULL
     ORDER BY q.id, qv.version_no, qi.sort_order, qi.id"
);

$expected = function (array $r): int {
    $area = $r['area'] !== null ? (float) $r['area'] : null;
    $factor = ($area !== null && $area > 0) ? $area : 1;
    return (int) round(
        $factor * (float) $r['qty'] * (float) $r['unit_price']
        + (float) $r['material_cost'] + (float) $r['labor_cost'] + (float) $r['equipment_cost']
        + (float) $r['outsourcing_cost'] + (float) $r['etc_cost'],
        0
    );
};

// 버전별 집계
$versions = []; // vid => ['quote_no','version_no','quote_id','contract_no','discount','subtotal','vat','total_amount','items'=>[], 'newSubtotal'=>int, 'mismatch'=>bool]
foreach ($rows as $r) {
    $vid = (int) $r['version_id'];
    if (!isset($versions[$vid])) {
        $versions[$vid] = [
            'quote_id'     => (int) $r['quote_id'],
            'quote_no'     => $r['quote_no'],
            'version_no'   => (int) $r['version_no'],
            'current'      => (int) $r['current_version_id'] === $vid,
            'contract_no'  => $r['contract_no'],
            'discount'     => (float) $r['discount'],
            'subtotal'     => (int) $r['subtotal'],
            'vat'          => (int) $r['vat'],
            'total_amount' => (int) $r['total_amount'],
            'items'        => [],
            'newSubtotal'  => 0,
        ];
    }
    $exp = $expected($r);
    $stored = (int) $r['stored_amount'];
    $versions[$vid]['items'][] = [
        'item_id' => (int) $r['item_id'], 'name' => $r['name'],
        'stored' => $stored, 'expected' => $exp, 'diff' => $exp - $stored,
    ];
    $versions[$vid]['newSubtotal'] += $exp;
}

$mismatchVersions = 0; $mismatchItems = 0; $linkedQuotes = []; $fixableQuotes = [];
echo "견적 amount 신규 산식 대사 (vat_rate={$vatRate}%) — " . ($doFix ? 'FIX 모드(계약 미연결만 보정)' : 'DRY-RUN(변경 없음)') . "\n";
echo str_repeat('─', 100) . "\n";

foreach ($versions as $vid => $v) {
    $itemMis = array_filter($v['items'], fn($it) => $it['diff'] !== 0);
    $newVat = (int) round($v['newSubtotal'] * $vatRate / 100, 0);
    $newTotal = (int) ($v['newSubtotal'] + $newVat - $v['discount']);
    $subMis = $v['newSubtotal'] !== $v['subtotal'] || $newTotal !== $v['total_amount'];
    if (!$itemMis && !$subMis) { continue; }

    $mismatchVersions++;
    $mismatchItems += count($itemMis);
    $flag = $v['contract_no'] ? "계약연결({$v['contract_no']}) — 보정 제외" : '계약 미연결 — 보정 대상';
    printf("• %s v%d%s [%s]\n", $v['quote_no'], $v['version_no'], $v['current'] ? ' (현재)' : '', $flag);
    foreach ($itemMis as $it) {
        printf("    - 항목#%d %-20s 저장 %s → 신규 %s (차 %+s)\n",
            $it['item_id'], mb_substr($it['name'], 0, 20),
            number_format($it['stored']), number_format($it['expected']), number_format($it['diff']));
    }
    printf("    합계: 공급가액 %s → %s · VAT %s → %s · 총액 %s → %s\n",
        number_format($v['subtotal']), number_format($v['newSubtotal']),
        number_format($v['vat']), number_format($newVat),
        number_format($v['total_amount']), number_format($newTotal));

    if ($v['contract_no']) {
        $linkedQuotes[$v['quote_id']] = $v['quote_no'];
    } else {
        $fixableQuotes[$v['quote_id']] = $v['quote_no'];
        if ($doFix) {
            Db::transaction(function () use ($v, $vid, $newVat, $newTotal) {
                foreach ($v['items'] as $it) {
                    if ($it['diff'] !== 0) {
                        Db::update('quote_items', ['amount' => $it['expected']], 'id = :id', [':id' => $it['item_id']]);
                    }
                }
                Db::update('quote_versions', [
                    'subtotal'     => $v['newSubtotal'],
                    'vat'          => $newVat,
                    'total_amount' => $newTotal,
                ], 'id = :id', [':id' => $vid]);
            });
            echo "    → 보정 완료\n";
        }
    }
}

echo str_repeat('─', 100) . "\n";
printf("불일치: 버전 %d건 · 항목 %d건 | 계약 미연결(보정 대상) 견적 %d건 [%s] | 계약 연결(보고만) 견적 %d건 [%s]\n",
    $mismatchVersions, $mismatchItems,
    count($fixableQuotes), implode(', ', $fixableQuotes),
    count($linkedQuotes), implode(', ', $linkedQuotes));
if ($mismatchVersions === 0) { echo "모든 견적이 신규 산식과 일치합니다.\n"; }
exit(0);
