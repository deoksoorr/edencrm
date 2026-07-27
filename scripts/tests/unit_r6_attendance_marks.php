<?php
/**
 * R6 T1 수동 마킹(attendance_marks) 검증 스위트 — 브리프 §1 확정 산식.
 *  1) 중복 등록 거부: UNIQUE(user_id, mark_date) — 같은 날 2번째 INSERT 는 23000 예외(서버는 422 응답)
 *  2) 같은 날 상태 변경: UPDATE late→absent (행 1개 유지, 타입만 교체)
 *  3) 출근 일수 absent 겹침 제외: work_logs 있어도 absent 마크된 날은 출근 일수·매트릭스·월 추이에서 제외
 *  4) 통계 3종: 출근 일수(DISTINCT−absent 겹침) · 지각(late 마크 수 — 출근 일수에 포함) · 무단결근(absent 마크 수)
 *  5) 해제(DELETE) 후 통계 원상 복귀
 * DB 변경(2031년 가상 데이터)은 트랜잭션 안에서 수행 후 롤백한다(시드 대사값 불변).
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

$pdo = Db::pdo();

echo "-- 1) 중복 등록 거부(UNIQUE) — 자체 트랜잭션(위반 후 롤백) --\n";
$pdo->beginTransaction();
try {
    $mkMark = function (string $date, string $type, int $uid = 2, ?string $memo = null) {
        return Db::insert('attendance_marks', [
            'user_id' => $uid, 'mark_date' => $date, 'mark_type' => $type, 'memo' => $memo, 'created_by' => 1,
        ]);
    };
    $id1 = $mkMark('2031-06-02', 'late', 2, '중복 테스트');
    t_true('1차 등록 성공(id 발급)', $id1 > 0);
    $dupCode = null;
    try {
        $mkMark('2031-06-02', 'absent', 2); // 같은 직원·같은 날 — 상태가 달라도 INSERT 는 거부(1일 1행)
    } catch (\PDOException $e) {
        $dupCode = (string) $e->getCode();
    }
    t_true('같은 날 2번째 INSERT → UNIQUE 위반(23000) — 컨트롤러는 422 로 변환', $dupCode === '23000');
    $cnt = (int) Db::val("SELECT COUNT(*) FROM attendance_marks WHERE user_id=2 AND mark_date='2031-06-02'");
    t_int('해당 날짜 행 수는 여전히 1', 1, $cnt);
} finally {
    $pdo->rollBack();
}

$pdo->beginTransaction();
try {
    // R6 T2 빈 시드: work_logs FK 용 프로젝트 픽스처 자체 생성(롤백)
    $fxCust = Db::insert('customers', ['type' => 'company', 'name' => 'TEST-R6MARK', 'status' => 'active']);
    $fxPid = Db::insert('projects', ['project_no' => 'TMARK-P1', 'customer_id' => $fxCust, 'name' => '마킹픽스처',
        'contract_amount' => 0, 'supply_amount' => 0, 'vat_amount' => 0, 'actual_cost' => 0, 'status' => 'in_progress']);
    $mkLog = function (string $date, int $uid = 2) use ($fxPid) {
        Db::insert('work_logs', ['project_id' => $fxPid, 'user_id' => $uid, 'work_date' => $date, 'content' => 'r6 marks test']);
    };
    $mkMark = function (string $date, string $type, int $uid = 2, ?string $memo = null) {
        return Db::insert('attendance_marks', [
            'user_id' => $uid, 'mark_date' => $date, 'mark_type' => $type, 'memo' => $memo, 'created_by' => 1,
        ]);
    };

    echo "\n-- 2) 같은 날 상태 변경(UPDATE late→absent) --\n";
    $id = $mkMark('2031-06-03', 'late', 2, '지각 10분');
    Db::update('attendance_marks', ['mark_type' => 'absent', 'memo' => '무단결근 전환'], 'id = :id', [':id' => $id]);
    $m = AttendanceService::marksByUser('2031-06-01', '2031-06-30', [2]);
    t_true('상태 변경 반영(absent)', ($m[2]['2031-06-03']['type'] ?? '') === 'absent');
    t_true('메모 갱신 반영', ($m[2]['2031-06-03']['memo'] ?? '') === '무단결근 전환');
    t_int('행 수 1 유지(변경=UPDATE, 신규 행 없음)', 1, (int) Db::val("SELECT COUNT(*) FROM attendance_marks WHERE user_id=2 AND mark_date='2031-06-03'"));
    Db::run("DELETE FROM attendance_marks WHERE id = :id", [':id' => $id]); // 다음 단계 독립성

    echo "\n-- 3·4) 통계 3종 — 2031-06 가상 월(차윤석 2) --\n";
    // 출근 기록 4일(6/2·3·4·5) + 마크: 6/3 지각, 6/4 무단결근(출근 기록과 겹침), 6/9 무단결근(기록 없는 날)
    $mkLog('2031-06-02'); $mkLog('2031-06-03'); $mkLog('2031-06-04'); $mkLog('2031-06-05');
    $mkMark('2031-06-03', 'late', 2, '10분 지각');
    $mkMark('2031-06-04', 'absent', 2, '무단결근(기록 무효)');
    $mkMark('2031-06-09', 'absent', 2);

    $days = AttendanceService::daysByUser('2031-06-01', '2031-06-30', [2]);
    t_int('출근 일수 3 = 기록 4일 − absent 겹침(6/4) 1일 — 지각일(6/3)은 포함', 3, $days[2] ?? 0);
    $matrix = AttendanceService::matrixByUser('2031-06-01', '2031-06-30', [2]);
    t_true('매트릭스에서 absent 겹침일(6/4) 제외', !isset($matrix[2]['2031-06-04']));
    t_true('지각일(6/3)은 매트릭스 유지', isset($matrix[2]['2031-06-03']));
    t_int('매트릭스 일수 == 출근 일수(산식 단일 출처)', 3, count($matrix[2] ?? []));
    $tot = AttendanceService::monthlyTotals('2031-06', 1, [2]);
    t_int('월 추이도 absent 겹침 제외(2031-06 = 3일)', 3, $tot['2031-06'] ?? -1);

    $ov = AttendanceService::monthOverview(2031, 6, [2]);
    t_int('monthOverview 출근 일수 3', 3, $ov['days'][2] ?? -1);
    t_int('지각 횟수 1(late 마크 수)', 1, $ov['late'][2] ?? -1);
    t_int('무단결근 횟수 2(absent 마크 수 — 기록 없는 날 포함)', 2, $ov['absent'][2] ?? -1);
    t_int('marks 맵 3건(그리드·캘린더 오버레이 원천)', 3, count($ov['marks'][2] ?? []));

    echo "\n-- 5) 해제(DELETE) 후 통계 원상 복귀 --\n";
    Db::run("DELETE FROM attendance_marks WHERE user_id=2 AND mark_date='2031-06-04'");
    $days2 = AttendanceService::daysByUser('2031-06-01', '2031-06-30', [2]);
    $ov2 = AttendanceService::monthOverview(2031, 6, [2]);
    t_int('absent 해제 → 출근 일수 4 복귀', 4, $days2[2] ?? 0);
    t_int('무단결근 횟수 1 로 감소', 1, $ov2['absent'][2] ?? -1);
    t_int('지각 횟수 1 유지', 1, $ov2['late'][2] ?? -1);
} finally {
    $pdo->rollBack();
}

exit(t_summary());
