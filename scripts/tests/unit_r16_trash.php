<?php
/**
 * R16 T4 — 고객·영업기회 휴지통(복원·완전삭제) 가드 회귀 (트랜잭션 롤백).
 * 픽스처는 전부 이 트랜잭션 안에서 생성·롤백되며 기존 행은 절대 건드리지 않는다.
 *
 * 검증 대상(백엔드 판정 함수만 — 액션은 Perm/Response 의존이라 QA 프로브가 담당):
 *   CustomersController::purgeBlockReason / purgeResidualReason / purgeCustomer / restoreCustomer
 *   PipelineController::purgeBlockReason / purgeResidualReason / restoreBlockReason / purgeLead / restoreLead
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/controllers/CustomersController.php';
require_once APP_PATH . '/controllers/PipelineController.php';

echo "R16 T4 휴지통(고객·영업기회) 회귀 (트랜잭션 롤백)\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $suf = (string) random_int(100000, 999999);
    $now = date('Y-m-d H:i:s');
    $stageId = (int) Db::val("SELECT id FROM pipeline_stages ORDER BY sort_order ASC LIMIT 1");

    // ── ① 고객 완전삭제 차단 — 활성 영업기회·견적·계약·프로젝트가 참조 중 ──
    $c1 = Db::insert('customers', ['name' => 'R16T4고객1' . $suf]);
    $l1 = Db::insert('leads', ['customer_id' => $c1, 'stage_id' => $stageId, 'work_type' => 'R16T4도장']);
    $q1 = Db::insert('quotes', ['quote_no' => 'R16T4-Q1-' . $suf, 'customer_id' => $c1, 'lead_id' => $l1]);
    $k1 = Db::insert('contracts', ['contract_no' => 'R16T4-C1-' . $suf, 'customer_id' => $c1, 'contract_amount' => 1000000]);
    $p1 = Db::insert('projects', [
        'project_no' => 'R16T4-P1-' . $suf, 'name' => 'R16T4프로젝트1', 'customer_id' => $c1,
        'contract_id' => $k1, 'contract_amount' => 1000000,
    ]);
    $r1 = CustomersController::purgeBlockReason($c1);
    t_true('①활성 참조 고객은 완전삭제 차단', $r1 !== null);
    t_true("①차단 사유에 '영업기회' 포함", $r1 !== null && str_contains($r1, '영업기회'));
    t_true("①차단 사유에 '견적' 포함",     $r1 !== null && str_contains($r1, '견적'));
    t_true("①차단 사유에 '계약' 포함",     $r1 !== null && str_contains($r1, '계약'));
    t_true("①차단 사유에 '프로젝트' 포함", $r1 !== null && str_contains($r1, '프로젝트'));

    // ── ② 참조가 전부 휴지통으로 내려가면 활성 차단 사유는 해제 ──
    //     (단, FK RESTRICT 는 소프트삭제를 모르므로 purgeResidualReason 이 물리 삭제를 계속 막는다)
    foreach ([['projects', $p1], ['contracts', $k1], ['quotes', $q1], ['leads', $l1]] as [$t, $rid]) {
        Db::update($t, ['deleted_at' => $now], 'id = :id', [':id' => $rid]);
    }
    t_null('②참조가 전부 소프트삭제되면 활성 차단 해제', CustomersController::purgeBlockReason($c1));
    $res2 = CustomersController::purgeResidualReason($c1);
    t_true('②휴지통 잔존 참조는 물리 삭제를 계속 차단', $res2 !== null);
    t_true("②잔존 사유에 '휴지통' 안내 포함", $res2 !== null && str_contains($res2, '휴지통'));

    // ── ③ 무참조 고객 완전삭제 — 연락처·활동 이력(소유 하위 데이터)까지 제거 ──
    $c3 = Db::insert('customers', ['name' => 'R16T4고객3' . $suf, 'deleted_at' => $now]);
    Db::insert('customer_contacts', ['customer_id' => $c3, 'name' => 'R16T4연락처', 'is_primary' => 1]);
    // customer_activities.user_id 는 NOT NULL(users FK) — 기존 활성 계정 하나를 빌려 쓴다(행 변경 없음).
    $anyUser = (int) Db::val("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1");
    Db::insert('customer_activities', [
        'customer_id' => $c3, 'user_id' => $anyUser, 'activity_type' => 'call',
        'content' => 'R16T4활동', 'activity_at' => $now,
    ]);
    t_null('③무참조 고객은 완전삭제 허용', CustomersController::purgeBlockReason($c3));
    t_null('③무참조 고객은 잔존 참조도 없음', CustomersController::purgeResidualReason($c3));

    CustomersController::purgeCustomer($c3);
    t_null('③purgeCustomer 후 customers 행 물리 삭제', Db::val('SELECT id FROM customers WHERE id=:id', [':id' => $c3]));
    t_null('③purgeCustomer 후 customer_contacts 제거', Db::val('SELECT id FROM customer_contacts WHERE customer_id=:id', [':id' => $c3]));
    t_null('③purgeCustomer 후 customer_activities 제거', Db::val('SELECT id FROM customer_activities WHERE customer_id=:id', [':id' => $c3]));

    // ── ④ 참조 있는 고객에 purgeCustomer 를 직접 호출하면 예외로 전체 롤백(FK 위반 방지) ──
    $blocked = false;
    try {
        CustomersController::purgeCustomer($c1);
    } catch (\RuntimeException $e) {
        $blocked = true;
    }
    t_true('④차단 대상 purgeCustomer 는 예외로 거부', $blocked);
    t_true('④거부 후 고객 행은 그대로 유지', Db::val('SELECT id FROM customers WHERE id=:id', [':id' => $c1]) !== null);

    // ── ⑤ 영업기회 완전삭제 — 활성 견적 참조 차단 / 무참조 허용 ──
    $c5 = Db::insert('customers', ['name' => 'R16T4고객5' . $suf]);
    $l5 = Db::insert('leads', ['customer_id' => $c5, 'stage_id' => $stageId, 'work_type' => 'R16T4인테리어']);
    Db::insert('quotes', ['quote_no' => 'R16T4-Q5-' . $suf, 'customer_id' => $c5, 'lead_id' => $l5]);
    $r5 = PipelineController::purgeBlockReason($l5);
    t_true('⑤활성 견적 참조 영업기회는 완전삭제 차단', $r5 !== null);
    t_true("⑤차단 사유에 '견적' 포함", $r5 !== null && str_contains($r5, '견적'));

    $l5b = Db::insert('leads', ['customer_id' => $c5, 'stage_id' => $stageId, 'work_type' => 'R16T4무참조', 'deleted_at' => $now]);
    t_null('⑤무참조 영업기회는 완전삭제 허용', PipelineController::purgeBlockReason($l5b));
    t_null('⑤무참조 영업기회는 잔존 참조도 없음', PipelineController::purgeResidualReason($l5b));
    PipelineController::purgeLead($l5b);
    t_null('⑤purgeLead 후 leads 행 물리 삭제', Db::val('SELECT id FROM leads WHERE id=:id', [':id' => $l5b]));

    // ── ⑥ 영업기회 복원 — 고객이 휴지통이면 차단, 고객 복원 후 허용 ──
    $c6 = Db::insert('customers', ['name' => 'R16T4고객6' . $suf, 'deleted_at' => $now]);
    $l6 = Db::insert('leads', ['customer_id' => $c6, 'stage_id' => $stageId, 'work_type' => 'R16T4복원', 'deleted_at' => $now]);
    t_true('⑥고객이 휴지통이면 영업기회 복원 차단', PipelineController::restoreBlockReason($l6) !== null);

    CustomersController::restoreCustomer($c6);
    t_null('⑥restoreCustomer 후 고객 deleted_at 해제', Db::val('SELECT deleted_at FROM customers WHERE id=:id', [':id' => $c6]));
    t_null('⑥고객 복원 후에는 영업기회 복원 허용', PipelineController::restoreBlockReason($l6));

    PipelineController::restoreLead($l6);
    t_null('⑥restoreLead 후 영업기회 deleted_at 해제', Db::val('SELECT deleted_at FROM leads WHERE id=:id', [':id' => $l6]));

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "  [FAIL] 예외: " . $e->getMessage() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
