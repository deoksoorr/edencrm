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

    // ── 대사 F: 취소 프로젝트는 확정매출 제외 ──
    $revBefore = AccountingService::confirmedRevenue();
    Db::insert('projects', ['project_no' => 'TP-DONE', 'customer_id' => $cid, 'name' => '완료', 'contract_amount' => 55000000,
        'supply_amount' => 50000000, 'vat_amount' => 5000000, 'actual_cost' => 30000000, 'status' => 'completed', 'actual_end_date' => date('Y-m-d')]);
    Db::insert('projects', ['project_no' => 'TP-CANCEL', 'customer_id' => $cid, 'name' => '취소', 'contract_amount' => 22000000,
        'supply_amount' => 20000000, 'vat_amount' => 2000000, 'actual_cost' => 0, 'status' => 'cancelled', 'actual_end_date' => date('Y-m-d')]);
    t_int('F 확정매출 증분=완료분(50,000,000)만', 50000000, AccountingService::confirmedRevenue() - $revBefore);

    // ── 대사 G: 계약1·입금3·비용5·직원2 → 계약액 중복 합산 안 됨 ──
    $revG = AccountingService::confirmedRevenue();
    $gPid = Db::insert('projects', ['project_no' => 'TP-JOIN', 'customer_id' => $cid, 'name' => 'JOIN', 'contract_amount' => 110000000,
        'supply_amount' => 100000000, 'vat_amount' => 10000000, 'actual_cost' => 70000000, 'status' => 'completed', 'actual_end_date' => date('Y-m-d')]);
    $gCon = Db::insert('contracts', ['contract_no' => 'TC-JOIN', 'customer_id' => $cid, 'contract_amount' => 110000000,
        'supply_amount' => 100000000, 'vat_amount' => 10000000, 'status' => 'completed', 'payment_status' => 'paid']);
    for ($i = 0; $i < 3; $i++) { Db::insert('payments', ['contract_id' => $gCon, 'pay_type' => 'etc', 'amount' => 10000000, 'status' => 'paid']); }
    for ($i = 0; $i < 5; $i++) { Db::insert('costs', ['project_id' => $gPid, 'type' => 'actual', 'category' => '자재비', 'amount' => 14000000]); }
    Db::insert('project_assignments', ['project_id' => $gPid, 'user_id' => 2, 'role' => '현장책임자', 'contribution_pct' => 70]);
    Db::insert('project_assignments', ['project_id' => $gPid, 'user_id' => 3, 'role' => '도장작업자', 'contribution_pct' => 30]);
    t_int('G 확정매출 증분=공급 100,000,000(1회)', 100000000, AccountingService::confirmedRevenue() - $revG);

    // G 기여액: 직원2 = (100,000,000-70,000,000)*70% = 21,000,000 (해당 프로젝트분)
    $c2 = AccountingService::employeeConfirmedContribution(2);
    $c3 = AccountingService::employeeConfirmedContribution(3);
    t_true('G 직원2 기여 ≥ 21,000,000', $c2 >= 21000000);
    t_true('G 직원3 기여 ≥ 9,000,000', $c3 >= 9000000);

} finally {
    $pdo->rollBack();
}
exit(t_summary());
