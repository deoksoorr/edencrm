<?php
/**
 * 전체 감사 사이클 — 삭제 가드·금액 검증 회귀. 전부 트랜잭션 내 픽스처로 만들고 롤백한다.
 * 감사에서 실제로 발견된 결함의 재발 방지가 목적이다.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/StatusService.php';
require_once APP_PATH . '/controllers/ContractsController.php';
require_once APP_PATH . '/controllers/CustomersController.php';
require_once APP_PATH . '/controllers/QuotesController.php';

echo "감사 가드 회귀 (트랜잭션 롤백)\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $suf = (string) random_int(100000, 999999);
    $cust = Db::insert('customers', ['name' => 'QAAUDIT고객' . $suf, 'privacy_agreed' => 1]);

    // ══════ ① 계약 삭제: 입금(paid) 기록 시 차단 ══════
    $con = Db::insert('contracts', [
        'contract_no' => 'QAAUDIT-C-' . $suf, 'customer_id' => $cust, 'contract_amount' => 5000000,
    ]);
    t_null('①입금 없는 계약은 삭제 차단 없음', ContractsController::deleteBlockReason($con));

    $payPending = Db::insert('payments', [
        'contract_id' => $con, 'amount' => 1000000, 'status' => 'pending', 'pay_type' => 'balance',
    ]);
    t_null('①pending 입금만 있으면 차단 없음', ContractsController::deleteBlockReason($con));

    Db::update('payments', ['status' => 'paid'], 'id = :id', [':id' => $payPending]);
    $reason = ContractsController::deleteBlockReason($con);
    t_true('①paid 입금이 있으면 삭제 차단', $reason !== null);
    t_true('①차단 사유에 건수·금액 표시', $reason !== null && str_contains($reason, '1,000,000'));

    // 입금을 무효(cancelled) 처리하면 다시 삭제 가능해야 한다
    Db::update('payments', ['status' => 'cancelled'], 'id = :id', [':id' => $payPending]);
    t_null('①입금 무효 처리 후 삭제 허용', ContractsController::deleteBlockReason($con));

    // ══════ ② 고객 삭제: 살아있는 자식 참조 시 차단 ══════
    $cust2 = Db::insert('customers', ['name' => 'QAAUDIT고객2' . $suf, 'privacy_agreed' => 1]);
    t_null('②참조 없는 고객은 삭제 차단 없음', CustomersController::deleteBlockReason($cust2));

    $stageId = (int) Db::val("SELECT id FROM pipeline_stages ORDER BY sort_order LIMIT 1");
    $lead = Db::insert('leads', ['customer_id' => $cust2, 'stage_id' => $stageId, 'memo' => 'QAAUDIT리드' . $suf]);
    $r = CustomersController::deleteBlockReason($cust2);
    t_true('②영업기회 참조 시 차단', $r !== null && str_contains($r, '영업기회'));

    $quote = Db::insert('quotes', [
        'quote_no' => 'QAAUDIT-Q-' . $suf, 'customer_id' => $cust2,
    ]);
    $r = CustomersController::deleteBlockReason($cust2);
    t_true('②견적 참조도 함께 표시', $r !== null && str_contains($r, '견적'));

    // 자식을 소프트 삭제하면 차단이 풀려야 한다
    Db::update('leads',  ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $lead]);
    Db::update('quotes', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $quote]);
    t_null('②자식 전부 휴지통 이동 후 삭제 허용', CustomersController::deleteBlockReason($cust2));

    // ══════ ③ 견적 할인 상한 — 총액 음수 방지 ══════
    $rc = new ReflectionClass('QuotesController');
    $m  = $rc->getMethod('computeTotals');
    $qc = $rc->newInstanceWithoutConstructor();

    $items = [['amount' => 1000000.0]];
    [$sub, $vat, $total, $disc] = $m->invoke($qc, $items, 500000.0, 10.0);
    t_float('③정상 할인은 그대로 적용', 600000.0, $total);   // 1,000,000 + 100,000 - 500,000
    t_float('③정상 할인값 보존',       500000.0, $disc);

    [$sub, $vat, $total, $disc] = $m->invoke($qc, $items, 99999999.0, 10.0);
    t_true('③과다 할인 시 총액이 음수가 아님', $total >= 0);
    t_float('③할인은 공급가+VAT 로 상한', 1100000.0, $disc);
    t_float('③상한 적용 시 총액 0',       0.0, $total);

    [$sub, $vat, $total, $disc] = $m->invoke($qc, [], 32000000.0, 10.0);
    t_float('③운영 재현(공급가 0·할인 3200만) → 총액 0', 0.0, $total);

    [$sub, $vat, $total, $disc] = $m->invoke($qc, $items, -500000.0, 10.0);
    t_float('③음수 할인은 0으로 차단', 0.0, $disc);
    t_float('③음수 할인 시 총액은 공급가+VAT', 1100000.0, $total);

    $pdo->rollBack();
    echo "\n(픽스처 롤백 완료 — 기존 행 무변경)\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "  [FAIL] 예외: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
