<?php
/**
 * 근태(출근) 집계 서비스 — work_logs + attendance_marks 기반, 대시보드·리포트(직원 출근 분석) 공용.
 *
 * R7 확장(T6): 출근 실재 원천 = work_logs ∪ work 유형 일정 참여(schedules+schedule_participants).
 * 운영은 feature_worklog=0(작업일지 OFF)이라 일정이 사실상 유일한 출근 기록 수단이다.
 * 통계는 3종만 사용한다:
 *  - 출근 일수 = (work_logs 날짜 ∪ work 일정 참여 날짜(취소·미래 제외)) DISTINCT
 *      − 무단결근(absent) 마크와 겹치는 날 제외. presenceMatrix() 가 유일한 구현이다.
 *    work_logs 에는 소프트삭제·취소 컬럼이 없어 전 행이 유효 기록이다(도입 시 이곳에서 일괄 제외).
 *  - 지각 횟수 = 해당 기간 late 마크 수(관리자 수동 등록 — 지각한 날도 출근 일수에는 포함).
 *  - 무단결근 횟수 = 해당 기간 absent 마크 수(관리자 수동 등록).
 *  마크 원본은 attendance_marks(UNIQUE(user_id, mark_date) — 같은 날 1상태만).
 *
 * R6 에서 폐지(코드 제거 — 데이터는 보존):
 *  - R5 자동 지각·조퇴 판정(punctualityByUser·workTimes — settings attendance_work_start/end 참조).
 *  - 휴가(vacation 일정) 집계(vacationsByUser)·직원별 분모(sched_user)·미출근 산식(absent 자동 추정).
 *  기존 vacation 일정·설정 키는 DB 에 남지만 화면·통계 어디에도 반영하지 않는다.
 *
 * 유지 정책(R4): 근무 예정(영업일) 계산은 캘린더 렌더링·차트 축 용도로만 사용.
 * 비활성(inactive) 직원 과거 통계 유지, 테스트 계정 제외 없음(전 계정 실계정 취급).
 * 모든 조회는 기간·직원 목록 단위 배치 쿼리(N+1 금지). 호출부는 배열 결과를 조립만 한다.
 */
class AttendanceService
{
    /** 출근 실재로 인정하는 일정 유형(schedules.type) — R7 정책 확정: 작업·회의·현장방문 모두 근무 실재.
     *  'other'(기타)는 성격 불명(개인 일정 가능)이라 제외. 변경 시 docs/ATTENDANCE_RULES.md §4 동기화. */
    private const PRESENCE_SCHEDULE_TYPES = ['work', 'meeting', 'site_visit'];

    /**
     * 출근 실재 매트릭스 [uid => [Y-m-d => true]] — 출근 일수 산식의 **유일한** 구현(T6).
     * 원천 = work_logs(user_id, work_date)
     *      ∪ work 유형 일정 참여자(schedule_participants — 취소(cancelled) 제외, 미래 날짜 제외,
     *        기간 일정(end_date)은 기간 내 각 날짜를 출근으로 확장 — 오늘·조회구간까지로 절단)
     *      − 무단결근(absent) 마크와 겹치는 날.
     * 같은 날 여러 건(작업일지+일정, 일정 2건 등)은 날짜 키 병합으로 1일. 배치 3쿼리(N+1 금지).
     */
    private static function presenceMatrix(string $from, string $to, array $userIds = []): array
    {
        $out = [];

        [$cond, $params] = self::userCond($userIds, [':f' => $from, ':t' => $to], 'w.user_id');
        foreach (Db::all(
            "SELECT DISTINCT w.user_id, w.work_date AS d
             FROM work_logs w
             WHERE w.work_date BETWEEN :f AND :t $cond",
            $params
        ) as $r) {
            $out[(int) $r['user_id']][$r['d']] = true;
        }

        // work 유형 일정 — 예정(미래) 날짜는 아직 출근이 아니므로 제외, 취소 일정 제외
        $typeIn = "'" . implode("','", self::PRESENCE_SCHEDULE_TYPES) . "'";
        [$cond2, $params2] = self::userCond($userIds, [':f' => $from, ':t' => $to, ':t2' => $to], 'sp.user_id');
        foreach (Db::all(
            "SELECT DISTINCT sp.user_id, s.event_date AS d,
                    LEAST(COALESCE(s.end_date, s.event_date), CURDATE(), :t2) AS d_end
             FROM schedules s
             JOIN schedule_participants sp ON sp.schedule_id = s.id
             WHERE s.type IN ($typeIn) AND s.status <> 'cancelled'
               AND s.event_date IS NOT NULL AND s.event_date <= CURDATE()
               AND s.event_date <= :t AND COALESCE(s.end_date, s.event_date) >= :f $cond2",
            $params2
        ) as $r) {
            for ($d = max($r['d'], $from); $d <= $r['d_end']; $d = date('Y-m-d', strtotime('+1 day', strtotime($d)))) {
                $out[(int) $r['user_id']][$d] = true;
            }
        }

        // 무단결근(absent) 마킹일 제외 — §4 산식(지각(late)은 그대로 출근 포함)
        [$cond3, $params3] = self::userCond($userIds, [':f' => $from, ':t' => $to], 'am.user_id');
        foreach (Db::all(
            "SELECT am.user_id, am.mark_date AS d FROM attendance_marks am
             WHERE am.mark_type = 'absent' AND am.mark_date BETWEEN :f AND :t $cond3",
            $params3
        ) as $r) {
            unset($out[(int) $r['user_id']][$r['d']]);
        }

        return $out;
    }

    /** 기간 내 공휴일 [Y-m-d => 이름] (holidays 테이블). */
    public static function holidayMap(string $from, string $to): array
    {
        $rows = Db::all(
            "SELECT holiday_date, name FROM holidays WHERE holiday_date BETWEEN :f AND :t",
            [':f' => $from, ':t' => $to]
        );
        return array_column($rows, 'name', 'holiday_date');
    }

    /** 기간 내 영업일 날짜 목록(토·일 + 공휴일 제외). $from > $to 이면 []. */
    public static function businessDates(string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }
        $holidays = self::holidayMap($from, $to);
        $out = [];
        $ts = strtotime($from);
        $end = strtotime($to);
        for (; $ts !== false && $ts <= $end; $ts = strtotime('+1 day', $ts)) {
            $d = date('Y-m-d', $ts);
            if ((int) date('N', $ts) >= 6 || isset($holidays[$d])) {
                continue; // 주말(토=6, 일=7)·공휴일 제외 — 주말과 겹치는 공휴일은 이중 차감 없음
            }
            $out[] = $d;
        }
        return $out;
    }

    /** 기간 내 영업일 수(캘린더·차트 축 참고용 — 통계 3종에는 미사용). */
    public static function businessDayCount(string $from, string $to): int
    {
        return count(self::businessDates($from, $to));
    }

    /**
     * 직원별 출근 일수 [user_id => 일수] — 1쿼리 배치.
     * 출근 일수 = DISTINCT work_date − absent 마크와 겹치는 날(브리프 §1 확정 산식).
     * $userIds 비우면 기간 내 기록 있는 전 직원.
     */
    public static function daysByUser(string $from, string $to, array $userIds = []): array
    {
        return array_map('count', self::presenceMatrix($from, $to, $userIds));
    }

    /**
     * 직원×일자 출근 매트릭스 [user_id => [Y-m-d => true]] — 1쿼리 배치(그리드 시각화용).
     * daysByUser 와 동일 산식(absent 겹침 제외) — count(matrix[uid]) == days[uid] 가 항상 성립한다.
     */
    public static function matrixByUser(string $from, string $to, array $userIds = []): array
    {
        return self::presenceMatrix($from, $to, $userIds);
    }

    /**
     * 월별 출근 추이(마감월 $endYm 포함 최근 $months 개월) — 1쿼리 배치.
     * 반환: [ym => 총 출근일수(선택 직원의 DISTINCT user_id+work_date 합, absent 겹침 제외)] — 없는 달은 0 채움.
     */
    public static function monthlyTotals(string $endYm, int $months, array $userIds = []): array
    {
        $months = max(1, min(24, $months));
        $endFrom = $endYm . '-01';
        $from = date('Y-m-01', strtotime("-" . ($months - 1) . " month", strtotime($endFrom)));
        $to = date('Y-m-t', strtotime($endFrom));
        $by = [];
        foreach (self::presenceMatrix($from, $to, $userIds) as $dates) {
            foreach ($dates as $d => $_) {
                $ym = substr($d, 0, 7);
                $by[$ym] = ($by[$ym] ?? 0) + 1;
            }
        }
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-$i month", strtotime($endFrom)));
            $out[$ym] = (int) ($by[$ym] ?? 0);
        }
        return $out;
    }

    // ── R6: 수동 마킹(지각·무단결근) 배치 ──

    /**
     * 기간 내 마크 배치(1쿼리) — [uid => [Y-m-d => ['id'=>n, 'type'=>'late'|'absent', 'memo'=>?string]]].
     * UNIQUE(user_id, mark_date) 보장으로 같은 날 키 충돌 없음. 캘린더·그리드 오버레이 공용.
     */
    public static function marksByUser(string $from, string $to, array $userIds = []): array
    {
        [$cond, $params] = self::userCond($userIds, [':f' => $from, ':t' => $to], 'user_id');
        $rows = Db::all(
            "SELECT id, user_id, mark_date, mark_type, memo
             FROM attendance_marks WHERE mark_date BETWEEN :f AND :t $cond",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']][$r['mark_date']] = [
                'id'   => (int) $r['id'],
                'type' => $r['mark_type'],
                'memo' => $r['memo'],
            ];
        }
        return $out;
    }

    /**
     * 한 달 요약 묶음(대시보드·분석 공용) — 통계 3종 + 그리드 원천 배치.
     * 반환: from,to,prev_from,prev_to,scheduled(영업일 — 캘린더·차트 축 참고용),
     *       elapsed(오늘까지 경과 영업일), days[uid](출근 일수 — absent 겹침 제외),
     *       prev_days[uid](전월 출근 일수), matrix[uid][date](출근 매트릭스 — days 와 동일 산식),
     *       marks[uid][date=>{id,type,memo}], late[uid](지각 횟수), absent[uid](무단결근 횟수).
     * 미래 월은 elapsed=0, 과거 월은 elapsed=scheduled.
     */
    public static function monthOverview(int $year, int $month, array $userIds = []): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));
        $pFrom = date('Y-m-01', strtotime('-1 month', strtotime($from)));
        $pTo = date('Y-m-t', strtotime($pFrom));
        $today = date('Y-m-d');
        $elapsedTo = min($to, $today);

        $days = self::daysByUser($from, $to, $userIds);
        $matrix = self::matrixByUser($from, $to, $userIds);
        $marks = self::marksByUser($from, $to, $userIds);

        // 직원 우주 = 요청 목록 ∪ 데이터 존재 직원(요청 비면 데이터 기준)
        $uids = array_unique(array_merge(
            array_values(array_filter(array_map('intval', $userIds), fn($v) => $v > 0)),
            array_keys($days), array_keys($marks)
        ));

        $late = $absent = [];
        foreach ($uids as $uid) {
            $l = $a = 0;
            foreach ($marks[$uid] ?? [] as $m) {
                if ($m['type'] === 'late') { $l++; } else { $a++; }
            }
            $late[$uid] = $l;
            $absent[$uid] = $a;
        }

        return [
            'from'      => $from,
            'to'        => $to,
            'prev_from' => $pFrom,
            'prev_to'   => $pTo,
            'scheduled' => self::businessDayCount($from, $to),
            'elapsed'   => $from > $today ? 0 : self::businessDayCount($from, $elapsedTo),
            'days'      => $days,
            'prev_days' => self::daysByUser($pFrom, $pTo, $userIds),
            'matrix'    => $matrix,
            'marks'     => $marks,
            'late'      => $late,
            'absent'    => $absent,
        ];
    }

    /** user_id IN (...) 조건 조립(빈 목록 = 조건 없음). intval 강제 후 리터럴 IN — 값 바인딩 불필요. */
    private static function userCond(array $userIds, array $params, string $col = 'user_id'): array
    {
        $ids = array_values(array_filter(array_map('intval', $userIds), fn($v) => $v > 0));
        if (!$ids) {
            return ['', $params];
        }
        return ["AND $col IN (" . implode(',', $ids) . ')', $params];
    }
}
