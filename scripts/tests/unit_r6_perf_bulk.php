<?php
/**
 * R6 T10 — PerformanceController::index N+1 벌크화 등가 증명 (perf HOLD §5-2).
 *
 * index() 가 직원당 호출하던 4개의 AccountingService 단건 집계를 배치 프리로드로 치환했다.
 * 이 테스트는 배치 메서드(employeeConfirmedByUser / contractedAmountByUser)가 단건 메서드
 * (employeeConfirmedRevenue / employeeConfirmedContribution / contractedAmount)와 **1원까지 동일**함을
 * 픽스처(완료·전월완료·취소 프로젝트 + 배정·기여도)로 증명한다 — 치환이 화면 값을 불변으로 유지함의 근거.
 * 트랜잭션 롤백으로 잔재 0.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "성과 배치==단건 등가 (Performance，트랜잭션 롤백)\n";

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $mf    = date('Y-m-01');
    $mt    = date('Y-m-t');
    $today = date('Y-m-d');
    $lastM = date('Y-m-d', strtotime($mf . ' -15 days')); // 전월 중순(당월 범위 밖)

    $uids = [1, 2, 3, 4, 999]; // 시드 직원 4명 + 미존재(키 부재 → 0 등가)

    // 델타 앵커용 before(빈 시드가 아니어도 견고하도록 증분으로 비교)
    $b2c   = AccountingService::employeeConfirmedContribution(2);
    $b2r   = AccountingService::employeeConfirmedRevenue(2);
    $b3c   = AccountingService::employeeConfirmedContribution(3);
    $b2mc  = AccountingService::employeeConfirmedContribution(2, $mf, $mt);
    $b2ctr = AccountingService::contractedAmount($mf, $mt, 2);
    $b4c   = AccountingService::employeeConfirmedContribution(4);

    $cid = Db::insert('customers', ['type' => 'company', 'name' => 'TEST성과대사', 'status' => 'active']);

    // P1: 완료·당월 — 계약 33M(공급 30M) 완납(당월) / 확정 지출 18,000,000(순이익 12,000,000) · 영업 user2
    //     R12: 확정 매출 = 공급가액(VAT 제외) = 33M × 30/33 = 30,000,000(완납 시 공급가와 일치)
    $c1 = Db::insert('contracts', ['contract_no' => 'PBC-1', 'customer_id' => $cid, 'contract_amount' => 33000000,
        'supply_amount' => 30000000, 'vat_amount' => 3000000, 'status' => 'active', 'payment_status' => 'paid']);
    $p1 = Db::insert('projects', ['project_no' => 'PB-1', 'customer_id' => $cid, 'name' => '당월완료', 'contract_id' => $c1,
        'contract_amount' => 33000000, 'supply_amount' => 30000000, 'vat_amount' => 3000000, 'actual_cost' => 18000000,
        'status' => 'completed', 'actual_end_date' => $today, 'contract_date' => $today, 'sales_user_id' => 2]);
    Db::insert('payments', ['contract_id' => $c1, 'pay_type' => 'etc', 'amount' => 33000000, 'status' => 'paid', 'paid_date' => $today]);
    Db::insert('costs', ['project_id' => $p1, 'type' => 'actual', 'cost_status' => 'confirmed', 'category' => 'material',
        'amount' => 18000000, 'spent_date' => $today]);
    Db::insert('project_assignments', ['project_id' => $p1, 'user_id' => 2, 'role' => '현장책임자', 'contribution_pct' => 60]);
    Db::insert('project_assignments', ['project_id' => $p1, 'user_id' => 3, 'role' => '도장작업자', 'contribution_pct' => 40]);

    // P2: 완료·전월 — 계약 11M(공급 10M) 완납(전월) / 확정 지출 4,000,000(순이익 6,000,000) · 영업 user3 · 배정 user2 100%
    $c2 = Db::insert('contracts', ['contract_no' => 'PBC-2', 'customer_id' => $cid, 'contract_amount' => 11000000,
        'supply_amount' => 10000000, 'vat_amount' => 1000000, 'status' => 'active', 'payment_status' => 'paid']);
    $p2 = Db::insert('projects', ['project_no' => 'PB-2', 'customer_id' => $cid, 'name' => '전월완료', 'contract_id' => $c2,
        'contract_amount' => 11000000, 'supply_amount' => 10000000, 'vat_amount' => 1000000, 'actual_cost' => 4000000,
        'status' => 'completed', 'actual_end_date' => $lastM, 'contract_date' => $lastM, 'sales_user_id' => 3]);
    Db::insert('payments', ['contract_id' => $c2, 'pay_type' => 'etc', 'amount' => 11000000, 'status' => 'paid', 'paid_date' => $lastM]);
    Db::insert('costs', ['project_id' => $p2, 'type' => 'actual', 'cost_status' => 'confirmed', 'category' => 'material',
        'amount' => 4000000, 'spent_date' => $lastM]);
    Db::insert('project_assignments', ['project_id' => $p2, 'user_id' => 2, 'role' => '현장책임자', 'contribution_pct' => 100]);

    // P3: 취소 — 배정 user4(양쪽 집계에서 제외되어야 함)
    $p3 = Db::insert('projects', ['project_no' => 'PB-3', 'customer_id' => $cid, 'name' => '취소',
        'contract_amount' => 99000000, 'supply_amount' => 90000000, 'vat_amount' => 9000000, 'actual_cost' => 0,
        'status' => 'cancelled', 'actual_end_date' => $today]);
    Db::insert('project_assignments', ['project_id' => $p3, 'user_id' => 4, 'role' => '도장작업자', 'contribution_pct' => 100]);

    // ── 배치 프리로드(PerformanceController::index 와 동일) ──
    $allC = AccountingService::employeeConfirmedByUser();
    $monC = AccountingService::employeeConfirmedByUser($mf, $mt);
    $ctrC = AccountingService::contractedAmountByUser($mf, $mt);

    echo "── 배치==단건 등가(전 직원) ──\n";
    foreach ($uids as $u) {
        t_int("uid $u 귀속매출: 배치==단건", AccountingService::employeeConfirmedRevenue($u),          (int) ($allC[$u]['revenue'] ?? 0));
        t_int("uid $u 확정기여: 배치==단건", AccountingService::employeeConfirmedContribution($u),      (int) ($allC[$u]['contrib'] ?? 0));
        t_int("uid $u 당월기여: 배치==단건", AccountingService::employeeConfirmedContribution($u, $mf, $mt), (int) ($monC[$u]['contrib'] ?? 0));
        t_int("uid $u 당월수주: 배치==단건", AccountingService::contractedAmount($mf, $mt, $u),          (int) ($ctrC[$u] ?? 0));
    }

    echo "\n── 픽스처 비자명성(델타 앵커) ──\n";
    t_int('user2 확정기여 델타 = 13,200,000 (P1 7.2M + P2 6M)', 13200000, AccountingService::employeeConfirmedContribution(2) - $b2c);
    t_int('user2 귀속매출 델타 = 28,000,000 (18M + 10M)',       28000000, AccountingService::employeeConfirmedRevenue(2) - $b2r);
    t_int('user3 확정기여 델타 = 4,800,000 (P1 40%)',            4800000,  AccountingService::employeeConfirmedContribution(3) - $b3c);
    t_int('user2 당월기여 델타 = 7,200,000 (전월 P2 제외)',      7200000,  AccountingService::employeeConfirmedContribution(2, $mf, $mt) - $b2mc);
    t_int('user2 당월수주 델타 = 30,000,000 (P1 계약일=당월)',   30000000, AccountingService::contractedAmount($mf, $mt, 2) - $b2ctr);
    t_int('user4 확정기여 델타 = 0 (취소 프로젝트 배정 제외)',   0,        AccountingService::employeeConfirmedContribution(4) - $b4c);

} finally {
    $pdo->rollBack();
}
exit(t_summary());
