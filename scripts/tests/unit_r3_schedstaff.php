<?php
/**
 * R3 schedstaff(T4) 검증 스위트 — 일정 시간대 복수 선택 + 직원 배정 가드.
 *  1) 슬롯 파싱: 0개 → 저장 거부 조건([]), 복수·legacy(am/pm)·중복 정규화, 라벨 "오전 · 야간"
 *  2) DB: 복수 슬롯 저장·조회, 시간대 필터(오전)에 오전 단일 + 오전·오후 복수 일정 모두 포함
 *  3) 배정: 동일 프로젝트·직원 active 중복 거부, 비활성 직원 신규 배정 거부,
 *     DB 백스톱(uq_assign_active_pair) 1062 + 동일 페어 'ended' 다중 행 허용(이력 보존)
 * 모든 DB 변경은 트랜잭션 안에서 수행 후 롤백한다(시드 대사값 불변).
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/Stages.php';
require_once APP_PATH . '/controllers/AssignmentsController.php';

echo "-- 슬롯 파싱(컨트롤러 저장 게이트) --\n";
t_int('슬롯 0개(빈 문자열) → [] (저장 422 거부 조건)', 0, count(Stages::parseSlots('')));
t_int('유효하지 않은 값만 → []', 0, count(Stages::parseSlots('dawn,x')));
t_true("'morning,night' → [morning, night]", Stages::parseSlots('morning,night') === ['morning', 'night']);
t_true("역순 'night,morning' → 표준 순서 정렬", Stages::parseSlots('night,morning') === ['morning', 'night']);
t_true("legacy 'am,pm' → [morning, afternoon]", Stages::parseSlots('am,pm') === ['morning', 'afternoon']);
t_true("중복 'morning,morning' → 1개", Stages::parseSlots('morning,morning') === ['morning']);
t_true("배열 입력 ['pm','night'] 수용", Stages::parseSlots(['pm', 'night']) === ['afternoon', 'night']);
t_true('slotLabels [morning,night] → "오전 · 야간"', Stages::slotLabels(['morning', 'night']) === '오전 · 야간');
t_true('slotLabel legacy pm → 오후', Stages::slotLabel('pm') === '오후');
t_true('slotToLegacy afternoon → pm(미러 키)', Stages::slotToLegacy('afternoon') === 'pm');
t_true('slotSpanTimes [morning,night] → 09~22시', Stages::slotSpanTimes(['morning', 'night']) === ['09:00:00', '22:00:00']);

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    echo "\n-- 복수 슬롯 저장·조회 + 시간대 필터 (schedule_time_slots) --\n";
    $mk = function (string $title, array $slots) {
        $sid = Db::insert('schedules', [
            'project_id' => null, 'user_id' => 2, 'title' => $title,
            'event_date' => '2030-01-15', 'slot' => Stages::slotToLegacy($slots[0]),
            'start_datetime' => '2030-01-15 ' . Stages::slotSpanTimes($slots)[0],
            'end_datetime' => '2030-01-15 ' . Stages::slotSpanTimes($slots)[1],
            'all_day' => 0, 'type' => 'work', 'status' => 'scheduled',
        ]);
        foreach ($slots as $s) {
            Db::insert('schedule_time_slots', ['schedule_id' => $sid, 'slot' => $s]);
        }
        return $sid;
    };
    $a = $mk('T-오전단일', ['morning']);
    $b = $mk('T-오전오후복수', ['morning', 'afternoon']);
    $c = $mk('T-야간단일', ['night']);

    $bSlots = array_column(Db::all(
        "SELECT slot FROM schedule_time_slots WHERE schedule_id=:id ORDER BY FIELD(slot,'morning','afternoon','night')",
        [':id' => $b]
    ), 'slot');
    t_true('복수 슬롯 저장·조회: morning+afternoon', $bSlots === ['morning', 'afternoon']);

    // 컨트롤러(schedule.data)와 동일한 EXISTS 필터
    $filter = fn(string $slot) => (int) Db::val(
        "SELECT COUNT(*) FROM schedules s
         WHERE s.event_date='2030-01-15'
           AND EXISTS(SELECT 1 FROM schedule_time_slots stf WHERE stf.schedule_id=s.id AND stf.slot=:slot)",
        [':slot' => $slot]
    );
    t_int('필터(오전): 오전 단일 + 오전·오후 복수 일정 포함', 2, $filter('morning'));
    t_int('필터(오후): 복수 일정만', 1, $filter('afternoon'));
    t_int('필터(야간): 야간 단일만', 1, $filter('night'));

    echo "\n-- 직원 배정 가드 (AssignmentsController::validateAssignment) --\n";
    // R6 T2 빈 시드: 배정 대상 프로젝트 픽스처 자체 생성(롤백) — 페어(픽스처 P1, 직원 4)로 검증
    $fxCust = Db::insert('customers', ['type' => 'company', 'name' => 'TEST-R3SCHED', 'status' => 'active']);
    $fxP1 = Db::insert('projects', ['project_no' => 'TSCH-P1', 'customer_id' => $fxCust, 'name' => '배정픽스처1',
        'contract_amount' => 0, 'supply_amount' => 0, 'vat_amount' => 0, 'actual_cost' => 0, 'status' => 'in_progress']);
    $fxP2 = Db::insert('projects', ['project_no' => 'TSCH-P2', 'customer_id' => $fxCust, 'name' => '배정픽스처2',
        'contract_amount' => 0, 'supply_amount' => 0, 'vat_amount' => 0, 'actual_cost' => 0, 'status' => 'in_progress']);
    t_null('신규 페어(active) 배정 허용', AssignmentsController::validateAssignment($fxP1, 4, 'active', null));
    $aid = Db::insert('project_assignments', [
        'project_id' => $fxP1, 'user_id' => 4, 'role' => '도장작업자', 'contribution_pct' => 0, 'status' => 'active',
    ]);
    t_true('동일 프로젝트·직원 active 중복 배정 거부(422 메시지)',
        AssignmentsController::validateAssignment($fxP1, 4, 'active', null) !== null);
    t_null('자기 자신 수정(excludeId)은 중복으로 보지 않음',
        AssignmentsController::validateAssignment($fxP1, 4, 'active', ['id' => $aid, 'user_id' => 4]));

    // DB 백스톱: uq_assign_active_pair(생성 컬럼 부분 UNIQUE) — 동시 요청 레이스 방어
    $dup1062 = false;
    try {
        Db::insert('project_assignments', [
            'project_id' => $fxP1, 'user_id' => 4, 'role' => '보조작업자', 'contribution_pct' => 0, 'status' => 'active',
        ]);
    } catch (PDOException $e) {
        $dup1062 = ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_assign_active_pair');
    }
    t_true('DB 백스톱: active 중복 INSERT → 1062(uq_assign_active_pair)', $dup1062);

    // 부분 UNIQUE 가 상태 이력과 충돌하지 않는지: 동일 페어 'ended' 다중 행 허용
    $endedOk = true;
    try {
        Db::insert('project_assignments', ['project_id' => $fxP1, 'user_id' => 4, 'role' => '도장작업자', 'contribution_pct' => 0, 'status' => 'ended']);
        Db::insert('project_assignments', ['project_id' => $fxP1, 'user_id' => 4, 'role' => '도장작업자', 'contribution_pct' => 0, 'status' => 'ended']);
    } catch (PDOException $e) {
        $endedOk = false;
    }
    t_true("동일 페어 'ended' 다중 행 허용(이력 보존, 부분 UNIQUE 안전)", $endedOk);

    // 비활성 직원: 임시 직원 생성 후 신규 배정 거부 + 기존 배정 이력 수정은 허용
    Db::run(
        "INSERT INTO users (login_id, email, password_hash, name, role_id, role_key, status, must_change_password)
         VALUES ('t_inactive', 't_inactive@test.local', 'x', '퇴사자테스트', 4, 'staff', 'inactive', 0)"
    );
    $inactiveId = (int) $pdo->lastInsertId();
    t_true('비활성 직원 신규 배정 거부',
        AssignmentsController::validateAssignment($fxP1, $inactiveId, 'active', null) !== null);
    $histId = Db::insert('project_assignments', [
        'project_id' => $fxP2, 'user_id' => $inactiveId, 'role' => '도장작업자', 'contribution_pct' => 0, 'status' => 'ended',
    ]);
    t_null('과거 비활성 직원의 기존 배정 이력 수정은 허용(보존)',
        AssignmentsController::validateAssignment($fxP2, $inactiveId, 'ended', ['id' => $histId, 'user_id' => $inactiveId]));
} finally {
    $pdo->rollBack(); // 시드 대사값 불변 유지
}

exit(t_summary());
