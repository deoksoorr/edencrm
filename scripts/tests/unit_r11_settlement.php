<?php
/**
 * R11 — 예외 프로젝트 입금·정산 + 입금 기준 통일 + 공정 위치 번호 매핑 검증.
 * 트랜잭션 롤백(잔재 0). 커버리지:
 *  1) projectPaySummary 파생(미입금/일부/완납/초과·예정 미설정·pending 카운트)
 *  2) recalcProjectSettlement 자동 전이(미정산↔일부)·hold 유지·settled 미수금 재발생 강등
 *  3) 확정 매출 통일 — 계약 입금 + 예외 직접 입금 합산·환불 차감·취소 행 제외
 *  4) 직원 귀속 — 예외 프로젝트 직접 입금도 기여도 배분(employeePaidByUser)
 *  5) 미수금 — 예외 프로젝트(예정 금액 − 직접 입금) 포함
 *  6) 공정 위치 번호 — 유형별 1..N·waiting 0·비활성 제외·유형 간 독립
 *  7) settled→in_progress 전이 허용(사유 필수) — 보드 자유 이동 정책
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/StatusService.php';
require_once APP_PATH . '/core/Audit.php';
require_once APP_PATH . '/core/Auth.php';
require_once APP_PATH . '/core/Stages.php';

echo "R11 정산·입금기준·위치매핑 (트랜잭션 롤백)\n";

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $cid = Db::insert('customers', ['type' => 'company', 'name' => 'R11대사', 'status' => 'active']);

    // ── 1) 예외 프로젝트 + projectPaySummary 파생 ──
    $xp = Db::insert('projects', ['project_no' => 'R11-X1', 'name' => '예외정산', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => '직접입력 고객', 'contract_amount' => 0,
        'expected_amount' => 10000000, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    $p = Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $xp]);

    $s = AccountingService::projectPaySummary($p);
    t_int('예정 10,000,000 · 입금 0 → 미수금 10,000,000', 10000000, $s['outstanding']);
    t_true('입금 상태 = 미입금(none)', $s['pay_status'] === 'none');

    // 일부 입금 4,000,000 (paid) + 대기 1,000,000 (pending — 집계 제외)
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'etc', 'amount' => 4000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'etc', 'amount' => 1000000, 'status' => 'pending']);
    $s = AccountingService::projectPaySummary($p);
    t_int('일부 입금 → 누적 4,000,000 (pending 제외)', 4000000, $s['paid']);
    t_int('미수금 6,000,000', 6000000, $s['outstanding']);
    t_true('입금 상태 = 일부 입금(partial)', $s['pay_status'] === 'partial');
    t_int('pending 1건 카운트(정산 완료 가드 근거)', 1, $s['pendingCnt']);

    // ── 2) 정산 상태 자동 전이 ──
    t_true('재계산 → 일부 정산(partial)', StatusService::recalcProjectSettlement($xp) === 'partial');
    Db::update('projects', ['settlement_status' => 'hold'], 'id=:id', [':id' => $xp]);
    t_true('hold(정산 보류)는 자동 재계산이 덮지 않음', StatusService::recalcProjectSettlement($xp) === 'hold');
    Db::update('projects', ['settlement_status' => 'partial'], 'id=:id', [':id' => $xp]);

    // 잔금 6,000,000 입금 + pending 정리 → 완납·정산 완료 가드 통과 조건
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'etc', 'amount' => 6000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::run("UPDATE payments SET status='cancelled' WHERE project_id=:p AND status='pending'", [':p' => $xp]);
    $s = AccountingService::projectPaySummary($p);
    t_int('잔금 입금 → 미수금 0', 0, $s['outstanding']);
    t_true('입금 상태 = 완납(paid)', $s['pay_status'] === 'paid');
    t_int('pending 0건 → 정산 완료 처리 가능', 0, $s['pendingCnt']);

    // 수동 settled 후 환불로 미수금 재발생 → partial 자동 강등(감사 기록)
    Db::update('projects', ['settlement_status' => 'settled'], 'id=:id', [':id' => $xp]);
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'etc', 'kind' => 'refund', 'amount' => 2000000,
        'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_true('settled + 미수금 재발생 → partial 자동 강등', StatusService::recalcProjectSettlement($xp) === 'partial');
    $s = AccountingService::projectPaySummary($p);
    t_int('환불 차감 후 누적 입금 8,000,000', 8000000, $s['paid']);
    t_true('환불 발생 플래그(refund 합 2,000,000)', $s['refund'] === 2000000);
    t_true('입금 상태 = 일부 입금(초과 아님)', $s['pay_status'] === 'partial');

    // 초과 입금 판정
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'etc', 'amount' => 3000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    $s = AccountingService::projectPaySummary($p);
    t_true('누적 11,000,000 > 예정 10,000,000 → 초과 입금(over)', $s['pay_status'] === 'over');

    // 예정 금액 미설정 프로젝트 — paid>0 → partial(미수금 0 취급)
    $xp2 = Db::insert('projects', ['project_no' => 'R11-X2', 'name' => '예정미설정', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'ㅇㅇ상사', 'contract_amount' => 0,
        'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    Db::insert('payments', ['project_id' => $xp2, 'pay_type' => 'etc', 'amount' => 500000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    $s2 = AccountingService::projectPaySummary(Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $xp2]));
    t_true('예정 미설정 플래그', $s2['expected_set'] === false);
    t_int('예정 미설정 → 미수금 0 취급', 0, $s2['outstanding']);

    // ── 3) 확정 매출 통일 — 예외 직접 입금 포함·취소 행 제외 ──
    //    현재 예외 입금 순액: X1 = 4+6-2+3 = 11,000,000 · X2 = 500,000
    $rev = AccountingService::confirmedRevenue();
    t_int('projectNetPaid(X1) = 11,000,000', 11000000, AccountingService::projectNetPaid($xp));
    Db::insert('payments', ['project_id' => $xp2, 'pay_type' => 'etc', 'amount' => 700000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_int('예외 직접 입금 +700,000 → 확정 매출 증분 동일', 700000, AccountingService::confirmedRevenue() - $rev);
    // 취소 전환 → 즉시 차감
    $cancelId = (int) Db::val("SELECT id FROM payments WHERE project_id=:p AND amount=700000", [':p' => $xp2]);
    Db::update('payments', ['status' => 'cancelled'], 'id=:id', [':id' => $cancelId]);
    t_int('입금 취소 → 확정 매출 원복', 0, AccountingService::confirmedRevenue() - $rev);

    // ── 4) 직원 귀속 — 예외 프로젝트 입금 × 기여도 ──
    Db::insert('project_assignments', ['project_id' => $xp, 'user_id' => 2, 'role' => '현장책임자', 'contribution_pct' => 80]);
    Db::insert('project_assignments', ['project_id' => $xp, 'user_id' => 3, 'role' => '보조작업자', 'contribution_pct' => 20]);
    $paidBy = AccountingService::employeePaidByUser(date('Y-m-d'), date('Y-m-d'));
    t_true('user2 입금 기여 ≥ 8,800,000 (X1 11M×80%)', ($paidBy[2] ?? 0) >= 8800000);
    t_int('단건==배치 등가(user2)', AccountingService::employeeConfirmedRevenue(2, date('Y-m-d'), date('Y-m-d')), (int) ($paidBy[2] ?? 0));

    // ── 5) 미수금 — 예외 프로젝트 포함 ──
    $recvBefore = AccountingService::receivable();
    $xp3 = Db::insert('projects', ['project_no' => 'R11-X3', 'name' => '예외미수', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => '미수고객', 'contract_amount' => 0,
        'expected_amount' => 3000000, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    t_int('예외 예정 3,000,000 미입금 → 미수금 +3,000,000', 3000000, AccountingService::receivable() - $recvBefore);
    Db::update('projects', ['status' => 'cancelled'], 'id=:id', [':id' => $xp3]);
    t_int('취소된 예외 프로젝트는 미수금 제외', 0, AccountingService::receivable() - $recvBefore);

    // ── 6) 공정 위치 번호 — 유형별 1..N·waiting 0·비활성 제외 ──
    //    (요청 내 static 캐시 — 본 테스트가 첫 호출이 되도록 여기서 처음 조회)
    Db::insert('process_stages', ['stage_key' => 'r11_test_stage', 'process_type' => 'painting',
        'stage_group' => 'build', 'name' => 'R11테스트공정', 'sort_order' => 99, 'is_active' => 1]);
    $pos = Stages::processStagePositions('painting');
    $waitingId = (int) Db::val("SELECT id FROM process_stages WHERE stage_key='waiting'");
    $newId = (int) Db::val("SELECT id FROM process_stages WHERE stage_key='r11_test_stage'");
    $fullId = (int) Db::val("SELECT id FROM process_stages WHERE stage_key='full_complete'");
    t_int('waiting 위치 = 0', 0, $pos['pos'][$waitingId]);
    t_int('도장 실공정 수 = 20 (기존 19 + 신규 1)', 20, $pos['total']);
    t_int('신규 공정(sort 99) 위치 = 20 (자동 매핑)', 20, $pos['pos'][$newId]);
    t_true('full_complete 위치 = 19 (sort 19 < 99)', $pos['pos'][$fullId] === 19);
    $posInt = Stages::processStagePositions('interior');
    t_int('인테리어 실공정 수 = 19 (도장 신규 공정 영향 없음 — 유형 독립)', 19, $posInt['total']);

    // ── 7) settled→in_progress 전이(R11 신설) ──
    t_true('settled → in_progress 허용', StatusService::projectTransitionAllowed('settled', 'in_progress'));
    t_true('settled → in_progress 사유 필수', StatusService::reasonRequired('settled', 'in_progress'));
    t_true('settled → completed 불허(기존 유지)', !StatusService::projectTransitionAllowed('settled', 'completed'));

} finally {
    $pdo->rollBack();
}
exit(t_summary());
