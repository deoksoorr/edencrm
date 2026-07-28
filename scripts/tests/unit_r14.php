<?php
/** R14 — 게이지 파생·자동 상태·계약총액 연동·메모·반기 집계 회귀 (트랜잭션 롤백). */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/StatusService.php';
require_once APP_PATH . '/core/ProcessService.php';
require_once APP_PATH . '/core/BonusService.php';
require_once APP_PATH . '/core/Audit.php';
require_once APP_PATH . '/core/Auth.php';
require_once APP_PATH . '/core/Stages.php';

echo "R14 회귀 (트랜잭션 롤백)\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    // ── Task 2: 게이지 파생 ──
    $stages = ProcessService::gaugeStages('painting');
    $n = count($stages);
    t_true('도장 실공정 목록(공통 제외) 존재', $n >= 10);
    $first = (int) $stages[0]['id']; $second = (int) $stages[1]['id'];

    $gp = Db::insert('projects', ['project_no' => 'R14-G1', 'name' => 'R14게이지', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => '게이지고객', 'contract_amount' => 0,
        'construction_type' => 'painting', 'status' => 'preparing',
        'process_stage_id' => ProcessService::waitingStageId()]);

    // 게이지 시작 → 자동 진행 중 + 현재 공정 파생
    $r = ProcessService::setStageProgress($gp, $first, 30, null);
    t_true('게이지>0 → 자동 진행 중', $r['status'] === 'in_progress');
    t_int('현재 공정 = 시작 공정', $first, $r['current_stage_id']);
    t_int('전체 진행률 = round(30/N)', (int) round(30 / $n), $r['progress']);
    t_true('아직 all_done 아님', $r['all_done'] === false);

    // 뒤 공정 시작 → 현재 공정 전진(pct>0 최후방)
    $r = ProcessService::setStageProgress($gp, $second, 20, null);
    t_int('현재 공정 = 더 뒤 공정', $second, $r['current_stage_id']);
    $row = Db::one("SELECT process_stage_id, progress, status FROM projects WHERE id=:id", [':id' => $gp]);
    t_int('projects.process_stage_id 동기', $second, (int) $row['process_stage_id']);

    // 전부 100 → all_done (상태는 클라 확인 후 별도 완료 — 여기선 파생 플래그만)
    foreach ($stages as $st) { $r = ProcessService::setStageProgress($gp, (int) $st['id'], 100, null); }
    t_true('전 공정 100 → all_done', $r['all_done'] === true);
    t_int('전체 진행률 100', 100, $r['progress']);

    // 완료 확정(컨트롤러 흐름 재현) → R13 T6이 전체완료 이동
    $prow = Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $gp]);
    StatusService::applyProjectStatus($prow, 'completed', ['reason' => 'R14 게이지 완료 확인']);
    $row = Db::one("SELECT status, process_stage_id FROM projects WHERE id=:id", [':id' => $gp]);
    t_true('완료 상태', $row['status'] === 'completed');
    t_int('보드 전체완료 이동', (int) ProcessService::stageIdByKey('full_complete'), (int) $row['process_stage_id']);

    // completed에서 게이지 낮춤 → 자동 재개 + 현재 공정 복귀
    $r = ProcessService::setStageProgress($gp, $second, 60, null);
    t_true('게이지 재수정 → 자동 재개(in_progress)', $r['status'] === 'in_progress');
    t_true('all_done 해제', $r['all_done'] === false);
    $row = Db::one("SELECT process_stage_id FROM projects WHERE id=:id", [':id' => $gp]);
    t_true('보드 위치 실공정 복귀(전체완료 아님)',
        (int) $row['process_stage_id'] !== (int) ProcessService::stageIdByKey('full_complete'));

    // 취소 프로젝트 거부
    $cx = Db::insert('projects', ['project_no' => 'R14-G2', 'name' => 'R14취소', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 0,
        'construction_type' => 'painting', 'status' => 'cancelled']);
    $threw = false;
    try { ProcessService::setStageProgress($cx, $first, 10, null); } catch (RuntimeException $e) { $threw = true; }
    t_true('취소 프로젝트 게이지 거부', $threw);

    // warranty 상태: 게이지만 기록, 보드 위치(warranty_repair)·상태 유지
    $wp = Db::insert('projects', ['project_no' => 'R14-G3', 'name' => 'R14하자', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 0,
        'construction_type' => 'painting', 'status' => 'warranty',
        'process_stage_id' => ProcessService::stageIdByKey('warranty_repair')]);
    $r = ProcessService::setStageProgress($wp, $first, 40, null);
    t_true('warranty: 상태 유지', $r['status'] === 'warranty');
    $row = Db::one("SELECT process_stage_id, status FROM projects WHERE id=:id", [':id' => $wp]);
    t_int('warranty: 보드 위치 warranty_repair 유지', (int) ProcessService::stageIdByKey('warranty_repair'), (int) $row['process_stage_id']);
    t_true('warranty: status 컬럼도 유지', $row['status'] === 'warranty');

    // ── Task 5: 예외 계약총액 연동 ──
    $xp = Db::insert('projects', ['project_no' => 'R14-X1', 'name' => 'R14예외총액', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 33000000,
        'expected_amount' => null, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    $p = Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $xp]);
    $s = AccountingService::projectPaySummary($p);
    t_int('예외 총액 = contract_amount(expected NULL이어도)', 33000000, $s['expected']);
    t_true('expected_set = true', $s['expected_set'] === true);
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'down', 'amount' => 33000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_true('전액 입금 → 자동 전액 입금 완료', StatusService::recalcProjectSettlement($xp) === 'settled');
    // 레거시 fallback: contract_amount=0 + expected_amount만 있는 행
    $lg = Db::insert('projects', ['project_no' => 'R14-X2', 'name' => 'R14레거시', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 0,
        'expected_amount' => 5000000, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    $p2 = Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $lg]);
    t_int('레거시 fallback = expected_amount', 5000000, AccountingService::projectPaySummary($p2)['expected']);

    // ── Task 6: salesPaidByUser — 담당영업 귀속 매출금액(입금) ──
    $su = Db::insert('users', ['login_id' => 'r14sales', 'password_hash' => 'x', 'name' => 'R14영업',
        'role_id' => 4, 'role_key' => 'staff', 'email' => 'r14s@t.t', 'status' => 'active']);
    // 예외 직접 입금 1,100,000 → p.sales_user_id 귀속
    $sp = Db::insert('projects', ['project_no' => 'R14-S1', 'name' => 'R14영업귀속', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 2000000,
        'sales_user_id' => $su, 'status' => 'in_progress']);
    Db::insert('payments', ['project_id' => $sp, 'pay_type' => 'down', 'amount' => 1100000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    // 환불 100,000 차감
    Db::insert('payments', ['project_id' => $sp, 'pay_type' => 'refund', 'kind' => 'refund', 'amount' => 100000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    $map = AccountingService::salesPaidByUser(date('Y-m-01'), date('Y-m-t'));
    t_int('담당영업 귀속 순입금(예외·환불 차감)', 1000000, $map[$su] ?? 0);

    // ── R14-6 회귀: 완료(진행률 100 강제) 후 재개 동기화 → 게이지 평균·현재 공정 복원 ──
    $rp = Db::insert('projects', ['project_no' => 'R14-R1', 'name' => 'R14재개복원', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 0,
        'construction_type' => 'painting', 'status' => 'preparing',
        'process_stage_id' => ProcessService::waitingStageId()]);
    ProcessService::setStageProgress($rp, $first, 40, null);
    $prow = Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $rp]);
    StatusService::applyProjectStatus($prow, 'completed', ['reason' => '회귀 완료']);
    t_int('완료 시 progress=100(정책 유지)', 100, (int) Db::val("SELECT progress FROM projects WHERE id=:id", [':id' => $rp]));
    ProcessService::syncStageFromGauges($rp, null);
    t_int('재개 동기화 → progress 게이지 평균 복원', (int) round(40 / $n), (int) Db::val("SELECT progress FROM projects WHERE id=:id", [':id' => $rp]));
    t_int('재개 동기화 → 현재 공정 복원', $first, (int) Db::val("SELECT process_stage_id FROM projects WHERE id=:id", [':id' => $rp]));

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "  [FAIL] 예외: " . $e->getMessage() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
