<?php
/**
 * R3 acctverify — 회계 검증 회귀 테스트 (트랜잭션 롤백).
 *  1) 미수금 모집단 = RECEIVABLE_STATUSES(체결 이후): draft 제외 / on_hold 포함
 *  2) 입금 취소(status='cancelled') = 물리 삭제 대체 — 모든 현금 집계에서 제외
 *  3) 준공(completed) 훅: 잔금 pending 예정행 due_date NULL → 준공일 자동 세팅(기존 값·paid 행 불변)
 *  4) 계약·프로젝트 이중 집계 부재: 확정 매출·수주액은 projects 축 1회만(계약 행 미합산)
 *  5) 목표 미설정 = null(0% 아님)
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
foreach (['Auth', 'Audit', 'StatusService', 'ProcessService'] as $c) {
    require_once APP_PATH . '/core/' . $c . '.php';
}

echo "R3 acctverify — 미수금 모집단·입금 취소·준공 due_date 훅·이중 집계 부재\n";

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $cid = Db::insert('customers', ['type' => 'company', 'name' => 'TEST-ACCTVERIFY', 'status' => 'active']);

    // ── 1) 미수금 모집단: draft(작성중) 제외 · on_hold(보류) 포함 ──
    $recvBefore = AccountingService::receivable();
    $cntBefore  = AccountingService::receivableCount();
    $totBefore  = AccountingService::contractTotals();
    Db::insert('contracts', ['contract_no' => 'TAV-DRAFT', 'customer_id' => $cid, 'contract_amount' => 50000000,
        'supply_amount' => 45454546, 'vat_amount' => 4545454, 'status' => 'draft', 'payment_status' => 'unpaid']);
    t_int('draft 계약 → 미수금 KPI 증분 0 (체결 전 제외)', 0, AccountingService::receivable() - $recvBefore);
    t_int('draft 계약 → 미수금 건수 증분 0', 0, AccountingService::receivableCount() - $cntBefore);
    $totAfterDraft = AccountingService::contractTotals();
    t_int('draft 계약 → contractTotals.receivable 증분 0 (KPI 와 동일 모집단)', 0,
        $totAfterDraft['receivable'] - $totBefore['receivable']);
    t_int('draft 계약도 계약 총액(현금 축 요약)에는 포함', 50000000, $totAfterDraft['contract'] - $totBefore['contract']);

    Db::insert('contracts', ['contract_no' => 'TAV-HOLD', 'customer_id' => $cid, 'contract_amount' => 11000000,
        'supply_amount' => 10000000, 'vat_amount' => 1000000, 'status' => 'on_hold', 'payment_status' => 'unpaid']);
    t_int('on_hold(체결 후 보류) 계약 → 미수금 +11,000,000 포함', 11000000, AccountingService::receivable() - $recvBefore);

    // ── 2) 입금 취소 전환: paid → cancelled 시 모든 현금 집계 원복 ──
    $payCon = Db::insert('contracts', ['contract_no' => 'TAV-PAYCXL', 'customer_id' => $cid, 'contract_amount' => 22000000,
        'supply_amount' => 20000000, 'vat_amount' => 2000000, 'status' => 'active', 'payment_status' => 'partial']);
    $recvP0 = AccountingService::receivable();
    $paidP0 = AccountingService::paidTotal();
    $pmId = Db::insert('payments', ['contract_id' => $payCon, 'pay_type' => 'down', 'amount' => 6600000,
        'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_int('입금 등록 → 입금 총액 +6,600,000', 6600000, AccountingService::paidTotal() - $paidP0);
    t_int('입금 등록 → 미수금 −6,600,000', -6600000, AccountingService::receivable() - $recvP0);
    Db::update('payments', ['status' => 'cancelled'], 'id = :id', [':id' => $pmId]); // deletePayment 의 취소 전환과 동일
    t_int('입금 취소 → 입금 총액 원복(증분 0)', 0, AccountingService::paidTotal() - $paidP0);
    t_int('입금 취소 → 미수금 원복(증분 0)', 0, AccountingService::receivable() - $recvP0);
    t_int('입금 취소 → 순입금(contractNetPaid) 0', 0, AccountingService::contractNetPaid($payCon));
    t_int('취소 행은 보존(물리 삭제 아님)', 1,
        (int) Db::val("SELECT COUNT(*) FROM payments WHERE id=:id AND status='cancelled'", [':id' => $pmId]));

    // ── 3) 준공 훅: 잔금 pending 예정행 due_date NULL → 준공일 자동 세팅 ──
    $hookCon = Db::insert('contracts', ['contract_no' => 'TAV-DUE', 'customer_id' => $cid, 'contract_amount' => 33000000,
        'supply_amount' => 30000000, 'vat_amount' => 3000000, 'status' => 'active', 'payment_status' => 'partial']);
    $paidRow = Db::insert('payments', ['contract_id' => $hookCon, 'pay_type' => 'down', 'amount' => 9900000,
        'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    $balNull = Db::insert('payments', ['contract_id' => $hookCon, 'pay_type' => 'balance', 'amount' => 13100000,
        'status' => 'pending', 'due_date' => null]);
    $balSet  = Db::insert('payments', ['contract_id' => $hookCon, 'pay_type' => 'balance', 'amount' => 10000000,
        'status' => 'pending', 'due_date' => '2099-12-31']);
    $hookPid = Db::insert('projects', ['project_no' => 'TAV-P-DUE', 'customer_id' => $cid, 'contract_id' => $hookCon,
        'name' => '준공훅', 'contract_amount' => 33000000, 'supply_amount' => 30000000, 'vat_amount' => 3000000,
        'actual_cost' => 0, 'status' => 'in_progress']);
    $endDate = '2026-07-15';
    $project = Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $hookPid]);
    StatusService::applyProjectStatus($project, 'completed', ['effective_date' => $endDate, 'reason' => '테스트 준공']);
    t_true('준공 → actual_end_date 세팅', Db::val("SELECT actual_end_date FROM projects WHERE id=:id", [':id' => $hookPid]) === $endDate);
    t_true('잔금 pending(due NULL) → 준공일로 자동 세팅',
        Db::val("SELECT due_date FROM payments WHERE id=:id", [':id' => $balNull]) === $endDate);
    t_true('잔금 pending(due 기존값) 불변',
        Db::val("SELECT due_date FROM payments WHERE id=:id", [':id' => $balSet]) === '2099-12-31');
    t_null('paid 행 due_date 불변(NULL 유지)', Db::val("SELECT due_date FROM payments WHERE id=:id", [':id' => $paidRow]));

    // 준공일 기보유 프로젝트: 기존 actual_end_date 기준으로 세팅되는지(effective_date 무시)
    $hookCon2 = Db::insert('contracts', ['contract_no' => 'TAV-DUE2', 'customer_id' => $cid, 'contract_amount' => 11000000,
        'supply_amount' => 10000000, 'vat_amount' => 1000000, 'status' => 'active', 'payment_status' => 'unpaid']);
    $bal2 = Db::insert('payments', ['contract_id' => $hookCon2, 'pay_type' => 'balance', 'amount' => 11000000,
        'status' => 'pending', 'due_date' => null]);
    $hookPid2 = Db::insert('projects', ['project_no' => 'TAV-P-DUE2', 'customer_id' => $cid, 'contract_id' => $hookCon2,
        'name' => '준공훅2', 'contract_amount' => 11000000, 'supply_amount' => 10000000, 'vat_amount' => 1000000,
        'actual_cost' => 0, 'status' => 'in_progress', 'actual_end_date' => '2026-07-01']);
    $project2 = Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $hookPid2]);
    StatusService::applyProjectStatus($project2, 'completed', ['effective_date' => '2026-07-20', 'reason' => '테스트 준공2']);
    t_true('기존 준공일 보유 시 그 날짜로 세팅(처리일 아님)',
        Db::val("SELECT due_date FROM payments WHERE id=:id", [':id' => $bal2]) === '2026-07-01');

    // ── 4) 확정 매출 = 공급가액(VAT 제외, R12) — 입금 시점 인식, 계약 supply/총액 비율 적용 ──
    //     완납(44M, 공급 40M) → 확정 매출 40,000,000. 환불은 비례 차감.
    $revBefore = AccountingService::confirmedRevenue();
    $ctrBefore = AccountingService::contractedAmount();
    $dupCon = Db::insert('contracts', ['contract_no' => 'TAV-DUP', 'customer_id' => $cid, 'contract_amount' => 44000000,
        'supply_amount' => 40000000, 'vat_amount' => 4000000, 'status' => 'active', 'payment_status' => 'unpaid',
        'contract_date' => date('Y-m-d')]);
    $dupPid = Db::insert('projects', ['project_no' => 'TAV-P-DUP', 'customer_id' => $cid, 'contract_id' => $dupCon,
        'name' => '이중집계', 'contract_amount' => 44000000, 'supply_amount' => 40000000, 'vat_amount' => 4000000,
        'actual_cost' => 25000000, 'status' => 'completed', 'actual_end_date' => date('Y-m-d'),
        'contract_date' => date('Y-m-d')]);
    t_int('프로젝트 완료만으로는 확정 매출 증분 0(입금 없음 — R12)', 0,
        AccountingService::confirmedRevenue() - $revBefore);
    t_int('수주액 증분 = 공급가 40,000,000 정확히 1회(projects 축)', 40000000,
        AccountingService::contractedAmount() - $ctrBefore);
    // 입금(계약 총액 44,000,000, 공급 40,000,000) → 확정 매출 = 공급가액 40,000,000(VAT 제외)
    Db::insert('payments', ['contract_id' => $dupCon, 'pay_type' => 'balance', 'amount' => 44000000,
        'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_int('완납 → 확정 매출 증분 = 공급가액 40,000,000(VAT 제외·R12)', 40000000,
        AccountingService::confirmedRevenue() - $revBefore);
    // 계약 상태를 completed 로 바꿔도 확정 매출 불변(이중 집계 없음)
    Db::update('contracts', ['status' => 'completed'], 'id = :id', [':id' => $dupCon]);
    t_int('계약 완료 전환 후에도 확정 매출 증분 불변(이중 집계 없음)', 40000000,
        AccountingService::confirmedRevenue() - $revBefore);
    // 환불 1,000,000 → 공급가 비례 차감(1,000,000 × 40/44 = 909,091) → 40,000,000 − 909,091 = 39,090,909
    $refundRow = Db::insert('payments', ['contract_id' => $dupCon, 'pay_type' => 'etc', 'kind' => 'refund',
        'amount' => 1000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_int('환불 1,000,000 → 확정 매출 증분 39,090,909(공급가 비례 차감)', 39090909,
        AccountingService::confirmedRevenue() - $revBefore);
    Db::run("DELETE FROM payments WHERE id = :id", [':id' => $refundRow]);

    // ── 5) 목표 미설정 = null (0% 로 표시 금지의 근거값) ──
    t_null('목표 0 → 달성률 null', AccountingService::achievement(1000000.0, 0.0));
    t_null('목표 null → 달성률 null', AccountingService::achievement(1000000.0, null));
    t_null('목표 음수 → 달성률 null', AccountingService::achievement(1000000.0, -1.0));
    t_float('목표 50M·실적 29M → 58%', 58.0, AccountingService::achievement(29000000.0, 50000000.0));
} finally {
    $pdo->rollBack();
}
exit(t_summary());
