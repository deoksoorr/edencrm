<?php
/**
 * R15 — 폼 검증 개편(Task 1) + 계약 삭제·휴지통 복원/완전삭제(Task 3) 회귀 (트랜잭션 롤백).
 * Task 3 픽스처는 전부 이 트랜잭션 안에서 생성·롤백되며 기존 행은 절대 건드리지 않는다.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/StatusService.php';
require_once APP_PATH . '/controllers/QuotesController.php';
require_once APP_PATH . '/controllers/ContractsController.php';
require_once APP_PATH . '/controllers/ProjectsController.php';

echo "R15 회귀 (트랜잭션 롤백)\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    // ── Step 1: Util::dateOrNull 안전망 — 잘못된/빈 날짜가 500 대신 NULL 로 흡수되는지 ──
    t_null('잘못된 날짜(월/일 범위 초과) → null', Util::dateOrNull('2026-13-99'));
    t_null('빈 문자열 → null', Util::dateOrNull(''));
    $ok = Util::dateOrNull('2026-07-28');
    t_true('정상 날짜는 정규화되어 통과', $ok === '2026-07-28');

    // ══════════════════════════════════════════════════════════════════
    // Task 3: 계약 삭제(소프트) + 3엔티티 복원·완전삭제 가드 (전부 트랜잭션 내 픽스처)
    // ══════════════════════════════════════════════════════════════════
    $suf = (string) random_int(100000, 999999);
    $cust = Db::insert('customers', ['name' => 'R15T3고객' . $suf]);

    // ── ① ContractsController::deleteBlockReason — live 프로젝트 존재 시 차단, 소프트삭제 후 해제 ──
    $con1 = Db::insert('contracts', ['contract_no' => 'R15T3-C1-' . $suf, 'customer_id' => $cust, 'contract_amount' => 1000000]);
    $proj1 = Db::insert('projects', [
        'project_no' => 'R15T3-P1-' . $suf, 'name' => 'R15T3프로젝트1',
        'customer_id' => $cust, 'contract_id' => $con1, 'contract_amount' => 1000000,
    ]);
    t_true('①계약 삭제 차단(live 프로젝트로 전환된 계약)', ContractsController::deleteBlockReason($con1) !== null);
    Db::update('projects', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $proj1]);
    t_null('①프로젝트 소프트삭제 후에는 계약 삭제 허용', ContractsController::deleteBlockReason($con1));

    // ── ② QuotesController::purgeBlockReason — 연결 계약 존재(삭제분 포함) 시 차단, 없으면 허용 ──
    $qA = Db::insert('quotes', ['quote_no' => 'R15T3-QA-' . $suf, 'customer_id' => $cust]);
    Db::insert('contracts', ['contract_no' => 'R15T3-C2-' . $suf, 'customer_id' => $cust, 'quote_id' => $qA, 'contract_amount' => 500000]);
    t_true('②견적 완전삭제 차단(연결 계약 존재)', QuotesController::purgeBlockReason($qA) !== null);

    $qB = Db::insert('quotes', ['quote_no' => 'R15T3-QB-' . $suf, 'customer_id' => $cust]);
    t_null('②연결 계약 없는 견적은 완전삭제 허용', QuotesController::purgeBlockReason($qB));

    // ── ③ ProjectsController::purgeBlockReason — 참조 존재 시 사유 나열, 무참조는 허용 ──
    $proj3 = Db::insert('projects', [
        'project_no' => 'R15T3-P3-' . $suf, 'name' => 'R15T3프로젝트3', 'customer_id' => $cust,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 0,
    ]);
    Db::insert('payments', ['project_id' => $proj3, 'pay_type' => 'down', 'amount' => 100000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::insert('costs', ['project_id' => $proj3, 'category' => 'material', 'amount' => 50000]);
    $reason3 = ProjectsController::purgeBlockReason($proj3);
    t_true('③참조 있는 프로젝트 완전삭제 차단', $reason3 !== null);
    t_true("③차단 사유에 '입금' 포함", $reason3 !== null && str_contains($reason3, '입금'));
    t_true("③차단 사유에 '비용' 포함", $reason3 !== null && str_contains($reason3, '비용'));

    $proj3b = Db::insert('projects', [
        'project_no' => 'R15T3-P3B-' . $suf, 'name' => 'R15T3프로젝트3b', 'customer_id' => $cust,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 0,
    ]);
    t_null('③무참조 프로젝트는 완전삭제 허용', ProjectsController::purgeBlockReason($proj3b));

    // ── ④ ProjectsController::restoreBlockReason ──
    // 실측 확인: projects.contract_id 는 UNIQUE(uq_projects_contract) 제약이 있어
    // "소프트삭제된 프로젝트 + 동일 계약의 별도 live 프로젝트"가 동시 존재하는 상태는
    // DB 레벨에서 어떤 INSERT/UPDATE 로도 만들 수 없다(직접 검증: 두 번째 행 삽입 시
    // SQLSTATE[23000] Duplicate entry 즉시 발생 — ContractProjectService::createFromContract()의
    // "삭제된 프로젝트가 연결되어 있어 자동 생성할 수 없습니다" 예외와 동일한 근거).
    // 따라서 not-null(충돌) 분기는 미래 변경에 대비한 방어 코드이며, 실사용에서 재현 가능한
    // null(정상) 분기만 픽스처로 검증한다.
    Db::update('projects', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $proj3b]);
    t_null('④contract_id 없는(예외) 소프트삭제 프로젝트는 복원 허용', ProjectsController::restoreBlockReason($proj3b));
    // $proj1 은 ①에서 이미 소프트삭제됨 — 동일 계약($con1)에 다른 live 프로젝트가 없으므로 복원 허용
    t_null('④계약 연결이 유일한 소프트삭제 프로젝트는 복원 허용', ProjectsController::restoreBlockReason($proj1));

    // ── ⑤ QuotesController::purgeQuote — 무참조 견적 완전삭제(물리) 실행 확인 ──
    $qC = Db::insert('quotes', ['quote_no' => 'R15T3-QC-' . $suf, 'customer_id' => $cust]);
    $qcVer = Db::insert('quote_versions', ['quote_id' => $qC, 'version_no' => 1, 'subtotal' => 100000, 'vat' => 10000, 'total_amount' => 110000]);
    Db::insert('quote_items', ['quote_version_id' => $qcVer, 'name' => 'R15항목', 'amount' => 100000]);
    Db::update('quotes', ['current_version_id' => $qcVer], 'id = :id', [':id' => $qC]);

    QuotesController::purgeQuote($qC);
    t_null('⑤purgeQuote 후 quotes 행 물리 삭제', Db::val('SELECT id FROM quotes WHERE id=:id', [':id' => $qC]));
    t_null('⑤purgeQuote 후 quote_versions 행 물리 삭제', Db::val('SELECT id FROM quote_versions WHERE id=:id', [':id' => $qcVer]));
    t_null('⑤purgeQuote 후 quote_items 행 물리 삭제', Db::val('SELECT id FROM quote_items WHERE quote_version_id=:id', [':id' => $qcVer]));

    // ── ⑥ ContractsController::purgeBlockReason — 입금 참조 차단(재무 보존)·소프트삭제 참조 포함·무참조 허용 ──
    $con6a = Db::insert('contracts', ['contract_no' => 'R15T3-C6A-' . $suf, 'customer_id' => $cust, 'contract_amount' => 1100000]);
    Db::insert('payments', ['contract_id' => $con6a, 'pay_type' => 'down', 'amount' => 1100000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    $r6a = ContractsController::purgeBlockReason($con6a);
    t_true('⑥입금 참조 계약 완전삭제 차단', $r6a !== null);
    t_true("⑥차단 사유에 '입금' 포함", $r6a !== null && str_contains($r6a, '입금'));
    // 소프트삭제된 프로젝트 참조도 차단(FK 는 soft 개념 무시 — deleted 무필터 회귀 고정)
    $con6b = Db::insert('contracts', ['contract_no' => 'R15T3-C6B-' . $suf, 'customer_id' => $cust, 'contract_amount' => 500000]);
    $p6b = Db::insert('projects', ['project_no' => 'R15T3-P6B-' . $suf, 'name' => 'R15T3프로젝트6b', 'customer_id' => $cust,
        'contract_id' => $con6b, 'customer_name_snapshot' => 'x', 'contract_amount' => 0,
        'deleted_at' => date('Y-m-d H:i:s')]);
    $r6b = ContractsController::purgeBlockReason($con6b);
    t_true('⑥소프트삭제 프로젝트 참조도 완전삭제 차단(무필터)', $r6b !== null && str_contains($r6b, '프로젝트'));
    // 무참조 계약은 허용
    $con6c = Db::insert('contracts', ['contract_no' => 'R15T3-C6C-' . $suf, 'customer_id' => $cust, 'contract_amount' => 300000]);
    t_null('⑥무참조 계약은 완전삭제 허용', ContractsController::purgeBlockReason($con6c));

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "  [FAIL] 예외: " . $e->getMessage() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
