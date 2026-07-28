<?php
/** R13 — 정산 자동완료·보너스 재계산·보드 동기화 회귀 (트랜잭션 롤백). */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/StatusService.php';
require_once APP_PATH . '/core/ProcessService.php';
// require_once APP_PATH . '/core/BonusService.php';  // Task 4에서 주석 해제(파일 생성 후)
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

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "  [FAIL] 예외: " . $e->getMessage() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
