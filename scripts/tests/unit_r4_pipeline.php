<?php
/**
 * R4 T7 파이프라인 자동 산정·기간 규약 테스트.
 *  1) 12단계 → 7그룹 fallback 매핑표(sort 위치 자연 매핑) 고정
 *  2) deriveStage 신호 우선순위(종료>보류>계약>견적>현장>상담>신규)
 *  3) 레거시 period 키(7d/30d/90d/month) 하위호환 매핑 + 프리셋 무변형(견적·계약 탭과 동일 경계 보장)
 *  4) 현행 시드 실측: 판교오피스텔 closed(3,300만 과대 해소)·서초상가 contracted 등 파생 결과 고정
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/PipelineStageService.php';
require_once APP_PATH . '/controllers/PipelineController.php';

echo "── 1) 12단계 → 7그룹 fallback 매핑표 ──\n";
$expected = [
    'new_inquiry'      => 'new_inquiry',
    'consult_booked'   => 'consulting',
    'site_survey'      => 'site_check',
    'quote_drafting'   => 'quoting',
    'quote_sent'       => 'quoting',
    'negotiating'      => 'quoting',
    'contract_pending' => 'quoting',
    'contract_won'     => 'contracted',
    'on_hold'          => 'on_hold',
    'no_response'      => 'on_hold',
    'lost'             => 'closed',
    'cancelled'        => 'closed',
];
foreach ($expected as $sk => $g) {
    t_true("fallback $sk → $g", (PipelineStageService::STAGE_FALLBACK[$sk] ?? '') === $g);
}
t_int('fallback 매핑은 12단계 전부 커버', 12, count(PipelineStageService::STAGE_FALLBACK));

echo "\n── 2) deriveStage 신호 우선순위 ──\n";
$d = fn(array $lead) => PipelineStageService::deriveStage($lead);
t_true('연결 계약 terminated → closed (stage 견적이어도)',
    $d(['stage_key' => 'quote_sent', '_link_contract_status' => 'terminated']) === 'closed');
t_true('연결 계약 active → contracted (stage 견적이어도)',
    $d(['stage_key' => 'quote_sent', '_link_contract_status' => 'active']) === 'contracted');
t_true('종료 > 보류: on_hold 단계 + 고객 사망 신호 → closed',
    $d(['stage_key' => 'on_hold', '_cust_signal' => 'closed']) === 'closed');
t_true('보류 > 계약: on_hold 단계 + 고객 계약 추정 → on_hold',
    $d(['stage_key' => 'on_hold', '_cust_signal' => 'contracted']) === 'on_hold');
t_true('유효 견적 연결 → 최소 quoting (상담 단계여도)',
    $d(['stage_key' => 'consult_booked', '_has_valid_quote' => true]) === 'quoting');
t_true('is_won 단계(contract_won) → contracted (신호 없음)',
    $d(['stage_key' => 'contract_won']) === 'contracted');
t_true('신호 전무 + 현장실측 단계 → site_check',
    $d(['stage_key' => 'site_survey']) === 'site_check');
t_true('알 수 없는 stage_key → new_inquiry(보수적)',
    $d(['stage_key' => 'unknown_stage']) === 'new_inquiry');

echo "\n── 3) 기간 규약: 레거시 매핑 + 동일 프리셋=동일 경계 ──\n";
$anchor = '2026-07-23';
[$p, $f, $t] = PipelineController::normalizePeriod('7d', '', '', $anchor);
t_true('레거시 7d → custom from=-7일 개구간', $p === 'custom' && $f === '2026-07-16' && $t === '');
[$p, $f, $t] = PipelineController::normalizePeriod('30d', '', '', $anchor);
t_true('레거시 30d → custom from=-30일', $p === 'custom' && $f === '2026-06-23' && $t === '');
[$p, $f, $t] = PipelineController::normalizePeriod('90d', '', '', $anchor);
t_true('레거시 90d → custom from=-90일', $p === 'custom' && $f === '2026-04-24' && $t === '');
[$p, $f, $t] = PipelineController::normalizePeriod('month', '', '', $anchor);
t_true('레거시 month → this_month 프리셋', $p === 'this_month' && $f === '' && $t === '');
// 공통 프리셋은 무변형 통과 → 견적(QuotesController)·계약과 동일하게 Util::periodRange 단일 계산 사용
foreach (array_keys(Util::PERIOD_PRESETS) as $preset) {
    [$np, $nf, $nt] = PipelineController::normalizePeriod($preset, '2026-01-01', '2026-01-31', $anchor);
    t_true("프리셋 $preset 무변형(periodRange 단일 계산 보장)", $np === $preset && $nf === '2026-01-01' && $nt === '2026-01-31');
}
$r = Util::periodRange('this_month', null, null, $anchor);
t_true('경계 표본: this_month = 7/1~7/31 (견적 탭과 동일 함수·동일 값)', $r['from'] === '2026-07-01' && $r['to'] === '2026-07-31');

echo "\n── 4) DB 실측(파생 단계 — §14 정합성, R6 T2 픽스처 생성-롤백) ──\n";
// 빈 시드 기준선: 리드 0건 → summarize 전부 0
t_int('빈 시드: 리드 0건', 0, (int) Db::val("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL"));
$empty = PipelineStageService::summarize(PipelineStageService::attachSignals([]));
t_int('빈 시드: summarize 전체 0건', 0, $empty['total']);
t_float('빈 시드: 진행 예상 금액 0', 0.0, $empty['open_amount']);

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $mkCust = fn(string $name) => Db::insert('customers', ['type' => 'company', 'name' => $name, 'status' => 'active']);
    $mkLead = function (int $cust, int $stageId, float $amount, string $created = '2026-07-01') {
        return Db::insert('leads', ['customer_id' => $cust, 'sales_user_id' => 1, 'stage_id' => $stageId,
            'expected_amount' => $amount, 'created_at' => $created . ' 09:00:00']);
    };
    // A: 단독 리드 고객 + 리드 이후 체결된 진행 계약 → 고객 단위 추정 contracted
    $cA = $mkCust('TP-단독계약');
    $lA = $mkLead($cA, 5, 30000000); // quote_sent
    Db::insert('contracts', ['contract_no' => 'TPL-A', 'customer_id' => $cA, 'contract_amount' => 33000000,
        'supply_amount' => 30000000, 'vat_amount' => 3000000, 'status' => 'active', 'payment_status' => 'unpaid',
        'contract_date' => '2026-07-10']);
    // B: 실주 단계 → closed (is_lost fallback)
    $cB = $mkCust('TP-실주');
    $lB = $mkLead($cB, 11, 20000000);
    // C: 상담예약, 신호 없음 → consulting (open)
    $cC = $mkCust('TP-상담');
    $lC = $mkLead($cC, 2, 12000000);
    // D: 직접 연결 견적 → 파기 계약 → closed (연결 계약 우선)
    $cD = $mkCust('TP-직접파기');
    $lD = $mkLead($cD, 7, 40000000); // contract_pending
    $qD = Db::insert('quotes', ['quote_no' => 'TPL-Q-D', 'lead_id' => $lD, 'customer_id' => $cD, 'status' => 'accepted']);
    Db::insert('contracts', ['contract_no' => 'TPL-D', 'quote_id' => $qD, 'customer_id' => $cD,
        'contract_amount' => 44000000, 'supply_amount' => 40000000, 'vat_amount' => 4000000,
        'status' => 'terminated', 'payment_status' => 'unpaid', 'contract_date' => '2026-07-05']);

    $leads = Db::all(
        "SELECT l.*, c.name AS customer_name, ps.stage_key, ps.name AS stage_name
         FROM leads l JOIN customers c ON c.id = l.customer_id JOIN pipeline_stages ps ON ps.id = l.stage_id
         WHERE l.deleted_at IS NULL ORDER BY l.id"
    );
    $leads = PipelineStageService::attachSignals($leads);
    $byId = [];
    foreach ($leads as $l) { $byId[(int) $l['id']] = $l; }

    t_true('A 단독 리드+진행 계약(리드 이후 체결) → contracted (고객 단위 추정)',
        ($byId[$lA]['derived_stage'] ?? '') === 'contracted');
    t_true('A 추정 연결 표시(link_contract_estimated)',
        ($byId[$lA]['link_contract_estimated'] ?? false) === true);
    t_true('B 실주 단계 → closed (is_lost)', ($byId[$lB]['derived_stage'] ?? '') === 'closed');
    t_true('C 상담예약(신호 없음) → consulting', ($byId[$lC]['derived_stage'] ?? '') === 'consulting');
    t_true('D 직접 연결 견적의 파기 계약 → closed (연결 계약 기준)',
        ($byId[$lD]['derived_stage'] ?? '') === 'closed');
    t_true('D 산정 근거 = 연결 계약 파기/취소 기준',
        str_contains((string) ($byId[$lD]['derived_source'] ?? ''), 'TPL-D'));

    $s = PipelineStageService::summarize($leads);
    t_int('전체 4건', 4, $s['total']);
    t_float('진행 예상 금액 = C 12,000,000 만(계약·종료 제외)', 12000000.0, $s['open_amount']);
    t_int('계약 전환 1건(A)', 1, $s['contracted_count']);
    t_int('종료 2건(B·D)', 2, $s['closed_count']);
    t_int('보류 0건', 0, $s['on_hold_count']);
} finally {
    $pdo->rollBack();
}

exit(t_summary());
