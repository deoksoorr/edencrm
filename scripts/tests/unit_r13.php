<?php
/** R13 — 정산 자동완료·보너스 재계산·보드 동기화 회귀 (트랜잭션 롤백). */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/StatusService.php';
require_once APP_PATH . '/core/ProcessService.php';
require_once APP_PATH . '/core/BonusService.php';
require_once APP_PATH . '/core/Audit.php';
require_once APP_PATH . '/core/Auth.php';
require_once APP_PATH . '/core/Stages.php';

echo "R13 회귀 (트랜잭션 롤백)\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    // ── Task 1: 정산 자동 승격 ──
    $xp = Db::insert('projects', ['project_no' => 'R13-X1', 'name' => 'R13예외', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => '직접고객', 'contract_amount' => 0,
        'expected_amount' => 5000000, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    // 전액 입금(계약총액 == 입금)
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'down', 'amount' => 2000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'balance', 'amount' => 3000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_true('전액 입금 → 자동 전액 입금 완료(settled)', StatusService::recalcProjectSettlement($xp) === 'settled');
    // 환불로 미수금 재발생 → 자동 강등
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'refund', 'kind' => 'refund', 'amount' => 1000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_true('환불 후 미수금 재발생 → partial 자동 강등', StatusService::recalcProjectSettlement($xp) === 'partial');
    // 라벨 변경
    t_true("라벨: settled = '전액 입금 완료'", StatusService::SETTLEMENT_LABELS['settled'] === '전액 입금 완료');
    // 계약총액 미설정이면 자동 승격 안 됨
    $xp2 = Db::insert('projects', ['project_no' => 'R13-X2', 'name' => 'R13미설정', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => '직접고객', 'contract_amount' => 0,
        'expected_amount' => null, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    Db::insert('payments', ['project_id' => $xp2, 'pay_type' => 'down', 'amount' => 1000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_true('계약총액 미설정 → settled 안 됨(partial)', StatusService::recalcProjectSettlement($xp2) === 'partial');

    // ── Task 4: 보너스 실시간 재계산 ──
    $u = Db::insert('users', ['login_id' => 'r13emp', 'email' => 'r13emp@edenpaint.co.kr', 'password_hash' => 'x', 'name' => 'R13직원', 'role_id' => 4, 'role_key' => 'staff', 'status' => 'active']);
    $bp = Db::insert('projects', ['project_no' => 'R13-B1', 'name' => 'R13보너스', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => '직접', 'contract_amount' => 0, 'expected_amount' => 11000000,
        'actual_cost' => 0, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    Db::insert('project_assignments', ['project_id' => $bp, 'user_id' => $u, 'role' => '현장책임자', 'contribution_pct' => 100, 'status' => 'active']);
    // 입금 1,100,000 (VAT 10% → 공급가 1,000,000)
    Db::insert('payments', ['project_id' => $bp, 'pay_type' => 'down', 'amount' => 1100000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    // 보너스 원장 1행(기여율 100·보너스율 10). base=공급가 1,000,000.
    $bid = Db::insert('site_bonuses', ['user_id' => $u, 'project_id' => $bp, 'year' => (int) date('Y'), 'half' => (int) date('n') <= 6 ? 1 : 2,
        'base_amount' => 1000000, 'contrib_revenue' => 1000000, 'contrib_profit' => 1000000, 'bonus_rate' => 10.00,
        'calc_amount' => 100000, 'confirmed_bonus' => 100000, 'pay_status' => 'unpaid', 'contribution_pct_at_calc' => 100.00]);
    // 환불 550,000 (공급가 500,000) → base 500,000·calc 50,000 재계산 기대. confirmed_bonus 보존.
    Db::insert('payments', ['project_id' => $bp, 'pay_type' => 'refund', 'kind' => 'refund', 'amount' => 550000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    BonusService::recalcForProject($bp);
    $b = Db::one("SELECT * FROM site_bonuses WHERE id = :id", [':id' => $bid]);
    t_int('환불 후 base_amount 재계산 = 500,000', 500000, (int) $b['base_amount']);
    t_int('환불 후 contrib_revenue = 500,000', 500000, (int) $b['contrib_revenue']);
    t_int('환불 후 calc_amount = 50,000', 50000, (int) $b['calc_amount']);
    t_int('confirmed_bonus 보존(100,000)', 100000, (int) $b['confirmed_bonus']);
    t_int('contribution_pct_at_calc 보존(100)', 100, (int) $b['contribution_pct_at_calc']);

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "  [FAIL] 예외: " . $e->getMessage() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
