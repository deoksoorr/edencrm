<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "DB 집계 (대사 C·F·G) — 트랜잭션 롤백\n";

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    // 고객 1(픽스처)
    $cid = Db::insert('customers', ['type' => 'company', 'name' => 'TEST대사', 'status' => 'active']);

    // ── 대사 C: 미수금 = 계약총액 100,000,000 − 입금 40,000,000 = 60,000,000 ──
    $recvBefore = AccountingService::receivable();
    $conId = Db::insert('contracts', [
        'contract_no' => 'TC-RECV', 'customer_id' => $cid, 'contract_amount' => 100000000,
        'supply_amount' => 90909091, 'vat_amount' => 9090909, 'status' => 'active', 'payment_status' => 'partial',
    ]);
    Db::insert('payments', ['contract_id' => $conId, 'pay_type' => 'down', 'amount' => 40000000, 'status' => 'paid']);
    Db::insert('payments', ['contract_id' => $conId, 'pay_type' => 'middle', 'amount' => 60000000, 'status' => 'pending']);
    t_int('C 미수금 증분 60,000,000', 60000000, AccountingService::receivable() - $recvBefore);

    // ── 대사 F: 취소 프로젝트는 확정매출 제외 / 확정순이익도 완료분만 ──
    $revBefore = AccountingService::confirmedRevenue();
    $profitBefore = AccountingService::confirmedProfit();
    Db::insert('projects', ['project_no' => 'TP-DONE', 'customer_id' => $cid, 'name' => '완료', 'contract_amount' => 55000000,
        'supply_amount' => 50000000, 'vat_amount' => 5000000, 'actual_cost' => 30000000, 'status' => 'completed', 'actual_end_date' => date('Y-m-d')]);
    Db::insert('projects', ['project_no' => 'TP-CANCEL', 'customer_id' => $cid, 'name' => '취소', 'contract_amount' => 22000000,
        'supply_amount' => 20000000, 'vat_amount' => 2000000, 'actual_cost' => 0, 'status' => 'cancelled', 'actual_end_date' => date('Y-m-d')]);
    t_int('F 확정매출 증분=완료분(50,000,000)만', 50000000, AccountingService::confirmedRevenue() - $revBefore);
    t_int('F 확정순이익 +20,000,000', 20000000, AccountingService::confirmedProfit() - $profitBefore);

    // ── 대사 G: 계약1·입금3·비용5·직원2 → 계약액 중복 합산 안 됨 ──
    $revG = AccountingService::confirmedRevenue();
    $gPid = Db::insert('projects', ['project_no' => 'TP-JOIN', 'customer_id' => $cid, 'name' => 'JOIN', 'contract_amount' => 110000000,
        'supply_amount' => 100000000, 'vat_amount' => 10000000, 'actual_cost' => 70000000, 'status' => 'completed', 'actual_end_date' => date('Y-m-d')]);

    // 미수금(3입금 상관 서브쿼리 경로): 계약액 110,000,000 − 입금 3건×10,000,000=30,000,000
    $recvGBefore = AccountingService::receivable();
    $gCon = Db::insert('contracts', ['contract_no' => 'TC-JOIN', 'customer_id' => $cid, 'contract_amount' => 110000000,
        'supply_amount' => 100000000, 'vat_amount' => 10000000, 'status' => 'completed', 'payment_status' => 'paid']);
    for ($i = 0; $i < 3; $i++) { Db::insert('payments', ['contract_id' => $gCon, 'pay_type' => 'etc', 'amount' => 10000000, 'status' => 'paid']); }
    t_int('G 미수금 +80,000,000 (3입금 중복없음)', 80000000, AccountingService::receivable() - $recvGBefore);

    for ($i = 0; $i < 5; $i++) { Db::insert('costs', ['project_id' => $gPid, 'type' => 'actual', 'category' => '자재비', 'amount' => 14000000]); }
    t_int('G 확정매출 증분=공급 100,000,000(1회)', 100000000, AccountingService::confirmedRevenue() - $revG);

    // G 기여액: 직원2 = (100,000,000-70,000,000)*70% = 21,000,000, 직원3 = 30% = 9,000,000 (정확 델타)
    $c2Before = AccountingService::employeeConfirmedContribution(2);
    $c3Before = AccountingService::employeeConfirmedContribution(3);
    Db::insert('project_assignments', ['project_id' => $gPid, 'user_id' => 2, 'role' => '현장책임자', 'contribution_pct' => 70]);
    Db::insert('project_assignments', ['project_id' => $gPid, 'user_id' => 3, 'role' => '도장작업자', 'contribution_pct' => 30]);
    t_int('G 직원2 확정기여 정확히 +21,000,000', 21000000, AccountingService::employeeConfirmedContribution(2) - $c2Before);
    t_int('G 직원3 확정기여 정확히 +9,000,000', 9000000, AccountingService::employeeConfirmedContribution(3) - $c3Before);

    // ── 예상매출: preparing/in_progress 프로젝트 공급가액 합 ──
    $expBefore = AccountingService::expectedRevenue();
    Db::insert('projects', ['project_no' => 'TP-EXP', 'customer_id' => $cid, 'name' => '예상매출', 'contract_amount' => 11000000,
        'supply_amount' => 10000000, 'vat_amount' => 1000000, 'actual_cost' => 0, 'status' => 'preparing']);
    t_int('예상매출 +10,000,000', 10000000, AccountingService::expectedRevenue() - $expBefore);

    // ── 수주액: 취소 아닌 프로젝트 공급가액 합(계약일 기준) — TP-CANCEL 은 contract_date 없어 미포함 ──
    $ctrBefore = AccountingService::contractedAmount();
    Db::insert('projects', ['project_no' => 'TP-CTR', 'customer_id' => $cid, 'name' => '수주액', 'contract_amount' => 7700000,
        'supply_amount' => 7000000, 'vat_amount' => 700000, 'status' => 'preparing', 'contract_date' => date('Y-m-d')]);
    t_int('수주액 +7,000,000 (계약일 기준)', 7000000, AccountingService::contractedAmount() - $ctrBefore);

} finally {
    $pdo->rollBack();
}
exit(t_summary());
