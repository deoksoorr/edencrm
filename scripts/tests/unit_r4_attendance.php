<?php
/**
 * R4 attendance(T4) 검증 스위트 — AttendanceService(근태 집계 단일 출처).
 * R6 재기준: 자동 지각·조퇴 판정(workTimes·punctualityByUser)·휴가(vacationsByUser) 폐지 —
 *   관련 테스트 제거, monthOverview 는 R6 키(days/late/absent/marks — 마크 없으면 0)로 재작성.
 *   수동 마킹(attendance_marks) 자체 검증은 unit_r6_attendance_marks.php 가 담당한다.
 *  1) 영업일 계산: 2026-07=23일(공휴일 없음) · 2026-08=20일(평일 21 − 8/17 대체공휴일,
 *     주말과 겹치는 8/15 광복절은 이중 차감 없음) · 2026-06=22일(현충일 6/6=토) · from>to → 0
 *  2) 출근 일수: 동일 user_id+work_date 중복 기록 → 1일(DISTINCT) · 월 경계(3/31 vs 4/1) 분리
 *  3) monthOverview: scheduled(영업일)·prev_days(전월 비교 원천)·미래 월 elapsed=0·마크 없으면 지각/무단결근 0
 *  4) monthlyTotals: 월별 직원×고유일 합 + 없는 달 0 채움
 *  5) 빈 시드(R6 T2) 정합: 작업일지·마크 0건 → 출근 0일·지각/무단결근 0회
 * DB 변경(2031년 가상 데이터·픽스처 프로젝트)은 트랜잭션 안에서 수행 후 롤백한다(시드 대사값 불변).
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "-- 영업일 계산(캘린더·차트 축 참고용) --\n";
t_int('2026-07 영업일 23일(주말 8일 제외·공휴일 없음)', 23, AttendanceService::businessDayCount('2026-07-01', '2026-07-31'));
t_int('2026-08 영업일 20일(평일 21 − 8/17 대체공휴일)', 20, AttendanceService::businessDayCount('2026-08-01', '2026-08-31'));
t_true('주말과 겹치는 공휴일(8/15 토) 이중 차감 없음', !in_array('2026-08-15', AttendanceService::businessDates('2026-08-01', '2026-08-31'), true));
t_int('2026-06 영업일 22일(현충일 6/6=토 → 추가 차감 없음)', 22, AttendanceService::businessDayCount('2026-06-01', '2026-06-30'));
t_int('from>to → 0', 0, AttendanceService::businessDayCount('2026-07-31', '2026-07-01'));
t_true('공휴일 맵: 2026-08-17 광복절 대체공휴일 포함', isset(AttendanceService::holidayMap('2026-08-01', '2026-08-31')['2026-08-17']));

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    echo "\n-- 출근 일수 DISTINCT·월 경계 (2031년 가상 데이터, 롤백) --\n";
    // R6 T2 빈 시드: work_logs FK 용 프로젝트 픽스처 자체 생성(롤백)
    $fxCust = Db::insert('customers', ['type' => 'company', 'name' => 'TEST-R4ATT', 'status' => 'active']);
    $fxP1 = Db::insert('projects', ['project_no' => 'TATT-P1', 'customer_id' => $fxCust, 'name' => '출근픽스처1',
        'contract_amount' => 0, 'supply_amount' => 0, 'vat_amount' => 0, 'actual_cost' => 0, 'status' => 'in_progress']);
    $fxP2 = Db::insert('projects', ['project_no' => 'TATT-P2', 'customer_id' => $fxCust, 'name' => '출근픽스처2',
        'contract_amount' => 0, 'supply_amount' => 0, 'vat_amount' => 0, 'actual_cost' => 0, 'status' => 'in_progress']);
    $mk = function (string $date, int $uid = 2, ?int $pid = null) use ($fxP1) {
        Db::insert('work_logs', ['project_id' => $pid ?? $fxP1, 'user_id' => $uid, 'work_date' => $date, 'content' => 'r4 attendance test']);
    };
    // 동일 날짜 3건(같은 프로젝트 2건 + 다른 프로젝트 1건) → 출근 1일
    $mk('2031-03-10'); $mk('2031-03-10'); $mk('2031-03-10', 2, $fxP2);
    $mk('2031-03-31');                    // 3월 마지막 날
    $mk('2031-04-01');                    // 4월 첫 날 — 월 경계 분리 확인
    $days3 = AttendanceService::daysByUser('2031-03-01', '2031-03-31', [2]);
    $days4 = AttendanceService::daysByUser('2031-04-01', '2031-04-30', [2]);
    t_int('같은 날짜 중복 3건 → 1일 (3월 = 3/10 + 3/31 = 2일)', 2, $days3[2] ?? 0);
    t_int('월 경계: 3/31 은 4월 집계에 미포함(4월 = 4/1 = 1일)', 1, $days4[2] ?? 0);
    $matrix = AttendanceService::matrixByUser('2031-03-01', '2031-03-31', [2]);
    t_true('매트릭스: 3/10·3/31 마킹, 중복 키 없음(고유 2일)', isset($matrix[2]['2031-03-10']) && isset($matrix[2]['2031-03-31']) && count($matrix[2]) === 2);

    echo "\n-- monthOverview(대시보드·분석 공용 묶음 — R6 키) --\n";
    $ov = AttendanceService::monthOverview(2031, 4, [2]);
    t_int('scheduled = 2031-04 영업일 22일', 22, $ov['scheduled']);
    t_int('days: 이번 달(4월) 출근 1일', 1, $ov['days'][2] ?? 0);
    t_int('prev_days: 전월(3월) 출근 2일 — 전월 비교 원천', 2, $ov['prev_days'][2] ?? 0);
    t_int('미래 월 elapsed=0', 0, $ov['elapsed']);
    t_int('마크 없으면 지각 0', 0, $ov['late'][2] ?? -1);
    t_int('마크 없으면 무단결근 0', 0, $ov['absent'][2] ?? -1);
    t_true('마크 없으면 marks 빈 배열', ($ov['marks'][2] ?? []) === []);

    echo "\n-- monthlyTotals(월별 추이) --\n";
    $tot = AttendanceService::monthlyTotals('2031-04', 3, [2]);
    t_true('마감월 포함 3개월 키(2031-02~04)', array_keys($tot) === ['2031-02', '2031-03', '2031-04']);
    t_int('기록 없는 달 0 채움(2031-02)', 0, $tot['2031-02']);
    t_int('2031-03 = 2일(중복 제거)', 2, $tot['2031-03']);
    t_int('2031-04 = 1일', 1, $tot['2031-04']);
} finally {
    $pdo->rollBack();
}

echo "\n-- 시드 정합(R7 T6) — 출근 = work_logs ∪ work 일정 참여(취소·미래 제외) − absent 마킹 --\n";
t_int('시드: work_logs 0건(작업일지 기능 OFF)', 0, (int) Db::val("SELECT COUNT(*) FROM work_logs"));
t_int('시드: attendance_marks 0건', 0, (int) Db::val("SELECT COUNT(*) FROM attendance_marks"));
// 기대값 미러 SQL(서비스 산식과 동일: work_logs ∪ work 일정, 미래·취소 제외, absent 제외)
$expRows = Db::all(
    "SELECT d.user_id, COUNT(DISTINCT d.d) AS days FROM (
        SELECT w.user_id, w.work_date AS d FROM work_logs w WHERE w.work_date BETWEEN :f1 AND :t1
        UNION
        SELECT sp.user_id, s.event_date FROM schedules s
          JOIN schedule_participants sp ON sp.schedule_id = s.id
         WHERE s.type IN ('work','meeting','site_visit') AND s.status <> 'cancelled'
           AND s.event_date <= CURDATE() AND s.event_date BETWEEN :f2 AND :t2
     ) d
     LEFT JOIN attendance_marks am ON am.user_id = d.user_id AND am.mark_date = d.d AND am.mark_type = 'absent'
     WHERE am.id IS NULL GROUP BY d.user_id",
    [':f1' => date('Y-m-01'), ':t1' => date('Y-m-t'), ':f2' => date('Y-m-01'), ':t2' => date('Y-m-t')]
);
$expected = [];
foreach ($expRows as $r) { $expected[(int) $r['user_id']] = (int) $r['days']; }
$curDays = AttendanceService::daysByUser(date('Y-m-01'), date('Y-m-t'));
ksort($expected); ksort($curDays);
t_true('이번 달 출근 일수 맵 = work 일정 기반 기대값과 일치', $curDays === $expected);
$ovNow = AttendanceService::monthOverview((int) date('Y'), (int) date('n'), [1, 2, 3, 4]);
t_int('monthOverview 출근(차윤석 2) = 기대값', $expected[2] ?? 0, $ovNow['days'][2] ?? 0);
t_int('monthOverview 지각 0회', 0, $ovNow['late'][2] ?? -1);
t_int('monthOverview 무단결근 0회', 0, $ovNow['absent'][2] ?? -1);

exit(t_summary());
