<?php
/**
 * 목표 원장(goals) 실적 연동 서비스 — R9.
 *
 * 원칙: 달성률·상태는 저장하지 않고 조회 시점에 실제 데이터로 계산한다.
 * 금액 실적은 반드시 AccountingService 공통 산식을 재사용해 대시보드·리포트·반기 현황과
 * 1원 단위로 일치시킨다(임의 재계산 금지). 건수 실적(계약/프로젝트 수)만 이 서비스가
 * 동일 모집단 기준(취소·파기 제외)으로 직접 집계한다.
 *
 * 기간: 모든 목표는 저장 시 start_date/end_date 로 정규화된다(사용자지정 포함).
 * 표시 상태 7종은 stored status(active/ended/cancelled) + 실적 + 날짜로 파생:
 *   cancelled → 중단 · ended → 종료 · 시작 전 → 준비
 *   기간 경과 → 달성률 ≥120% 초과달성 / ≥100% 달성 / 미만 미달
 *   진행 중  → ≥120% 초과달성 / ≥100% 달성 / 미만 진행중(+예상 달성 여부)
 * 초과달성 기준 120% 는 기획 확정값(2026-07-27).
 */
class GoalService
{
    public const METRICS = ['revenue', 'profit', 'contract_amount', 'contract_count', 'payment', 'project_count'];
    public const METRIC_LABELS = [
        'revenue'         => '매출(확정)',
        'profit'          => '순이익(확정)',
        'contract_amount' => '계약 금액(수주)',
        'contract_count'  => '계약 건수',
        'payment'         => '입금액',
        'project_count'   => '프로젝트 수',
    ];
    /** 건수 지표(단위 '건' — 나머지는 '원'). */
    public const COUNT_METRICS = ['contract_count', 'project_count'];

    public const SUBJECTS = ['company', 'department', 'user'];
    public const SUBJECT_LABELS = ['company' => '회사 전체', 'department' => '부서(팀)', 'user' => '직원 개인'];

    public const PERIODS = ['month', 'quarter', 'half', 'year', 'custom'];
    public const PERIOD_LABELS = ['month' => '월간', 'quarter' => '분기', 'half' => '반기', 'year' => '연간', 'custom' => '사용자 지정'];

    /** 초과달성 판정 임계 달성률(%). */
    public const OVER_ACHIEVE_PCT = 120.0;

    /** 표시 상태 라벨·배지 클래스. */
    public const STATE_META = [
        'ready'    => ['준비',     'badge-info'],
        'ongoing'  => ['진행 중',  'badge-info'],
        'achieved' => ['달성',     'badge-ok'],
        'exceeded' => ['초과 달성', 'badge-ok'],
        'missed'   => ['미달',     'badge-danger'],
        'ended'    => ['종료',     'badge-warn'],
        'cancelled'=> ['중단',     'badge-danger'],
    ];

    /**
     * 기간 유형 → 실제 날짜 범위. custom 은 start/end 필수.
     * @return array{from:string, to:string, label:string}
     */
    public static function resolveRange(string $periodType, int $year, int $periodNo, ?string $start = null, ?string $end = null): array
    {
        switch ($periodType) {
            case 'month':
                $from = sprintf('%04d-%02d-01', $year, $periodNo);
                $to   = date('Y-m-t', strtotime($from));
                return ['from' => $from, 'to' => $to, 'label' => sprintf('%d년 %d월', $year, $periodNo)];
            case 'quarter':
                $m1 = ($periodNo - 1) * 3 + 1;
                $from = sprintf('%04d-%02d-01', $year, $m1);
                $to   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $m1 + 2)));
                return ['from' => $from, 'to' => $to, 'label' => sprintf('%d년 %d분기', $year, $periodNo)];
            case 'half':
                $r = Util::halfRange($year, $periodNo);
                return ['from' => $r['from'], 'to' => $r['to'], 'label' => Util::halfLabel($year, $periodNo)];
            case 'year':
                return ['from' => "$year-01-01", 'to' => "$year-12-31", 'label' => "{$year}년 연간"];
            case 'custom':
                return ['from' => (string) $start, 'to' => (string) $end, 'label' => "$start ~ $end"];
        }
        throw new InvalidArgumentException("Unknown period_type: $periodType");
    }

    /** 부서 소속 활성+비활성 직원 id 목록(삭제 제외 — 과거 목표 이력 보존). @return int[] */
    public static function deptUserIds(int $deptId): array
    {
        return array_map('intval', array_column(Db::all(
            'SELECT id FROM users WHERE deleted_at IS NULL AND department_id = :d',
            [':d' => $deptId]
        ), 'id'));
    }

    /**
     * 목표 1건의 현재 실적(원 또는 건).
     * @param array $g goals 행(metric, subject_type, user_id, department_id, start_date, end_date)
     */
    public static function actual(array $g): int
    {
        $from = $g['start_date'];
        $to   = $g['end_date'];
        $subject = $g['subject_type'];
        $uid  = $subject === 'user' ? (int) $g['user_id'] : null;
        $deptIds = $subject === 'department' ? self::deptUserIds((int) $g['department_id']) : null;
        if ($subject === 'department' && !$deptIds) {
            return 0; // 소속 직원 없는 부서
        }

        switch ($g['metric']) {
            case 'revenue':
                if ($uid !== null) {
                    return AccountingService::employeeConfirmedRevenue($uid, $from, $to);
                }
                if ($deptIds !== null) {
                    return self::sumByUsers(AccountingService::employeeConfirmedByUser($from, $to), $deptIds, 'revenue');
                }
                return AccountingService::confirmedRevenue($from, $to);

            case 'profit':
                if ($uid !== null) {
                    $m = AccountingService::employeeConfirmedByUser($from, $to);
                    return (int) ($m[$uid]['contrib'] ?? 0);
                }
                if ($deptIds !== null) {
                    return self::sumByUsers(AccountingService::employeeConfirmedByUser($from, $to), $deptIds, 'contrib');
                }
                return AccountingService::confirmedProfit($from, $to);

            case 'contract_amount':
                if ($uid !== null) {
                    return AccountingService::contractedAmount($from, $to, $uid);
                }
                if ($deptIds !== null) {
                    return self::sumByUsers(AccountingService::contractedAmountByUser($from, $to), $deptIds);
                }
                return AccountingService::contractedAmount($from, $to);

            case 'payment':
                if ($uid !== null) {
                    $m = AccountingService::employeePaidByUser($from, $to);
                    return (int) ($m[$uid] ?? 0);
                }
                if ($deptIds !== null) {
                    return self::sumByUsers(AccountingService::employeePaidByUser($from, $to), $deptIds);
                }
                return AccountingService::paidTotal($from, $to);

            case 'contract_count':
                return self::contractCount($from, $to, $uid, $deptIds);

            case 'project_count':
                return self::projectCount($from, $to, $uid, $deptIds);
        }
        return 0;
    }

    /** per-user 맵에서 대상 직원들 값 합산(값이 배열이면 $key 필드). @param int[] $ids */
    private static function sumByUsers(array $map, array $ids, ?string $key = null): int
    {
        $sum = 0;
        foreach ($ids as $id) {
            $v = $map[$id] ?? 0;
            $sum += (int) ($key !== null ? ($v[$key] ?? 0) : $v);
        }
        return $sum;
    }

    /** 계약 건수 — contractedAmount 와 동일 모집단(취소·파기 제외, 계약일 기준). */
    private static function contractCount(string $from, string $to, ?int $uid, ?array $deptIds): int
    {
        $p = [':f' => $from, ':t' => $to];
        $scope = '';
        if ($uid !== null) {
            $scope = ' AND sales_user_id = :u';
            $p[':u'] = $uid;
        } elseif ($deptIds !== null) {
            $scope = ' AND sales_user_id IN (' . implode(',', $deptIds) . ')'; // int 캐스팅 완료 목록
        }
        return (int) Db::val(
            "SELECT COUNT(*) FROM projects
             WHERE deleted_at IS NULL AND status NOT IN ('cancelled','terminated')
               AND contract_date IS NOT NULL AND contract_date BETWEEN :f AND :t $scope",
            $p
        );
    }

    /** 프로젝트 수 — 반기 현황(BonusController::overview)과 동일 기준: 계약일(없으면 등록일), 취소·파기 제외. */
    private static function projectCount(string $from, string $to, ?int $uid, ?array $deptIds): int
    {
        $p = [':f' => $from, ':t' => $to];
        $scope = '';
        if ($uid !== null) {
            $scope = " AND (p.sales_user_id = :u1 OR p.site_manager_id = :u2
                OR EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id = p.id AND pa.user_id = :u3))";
            $p += [':u1' => $uid, ':u2' => $uid, ':u3' => $uid];
        } elseif ($deptIds !== null) {
            $in = implode(',', $deptIds); // int 캐스팅 완료 목록
            $scope = " AND (p.sales_user_id IN ($in) OR p.site_manager_id IN ($in)
                OR EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id = p.id AND pa.user_id IN ($in)))";
        }
        return (int) Db::val(
            "SELECT COUNT(*) FROM projects p
             WHERE p.deleted_at IS NULL AND p.status NOT IN ('cancelled','terminated')
               AND COALESCE(p.contract_date, DATE(p.created_at)) BETWEEN :f AND :t $scope",
            $p
        );
    }

    /**
     * 목표 1건의 진행 정보 — 실적·달성률·남은 수치·기간 경과율·예상 달성·표시 상태.
     * @return array{actual:int, rate:?float, remaining:int, elapsedPct:float,
     *               projected:?int, expectOk:?bool, state:string, stateLabel:string, stateBadge:string}
     */
    public static function progress(array $g, ?string $today = null): array
    {
        $today  = $today ?: date('Y-m-d');
        $actual = self::actual($g);
        $target = (float) $g['target_value'];
        $rate   = AccountingService::achievement((float) $actual, $target);
        $remaining = max(0, (int) $target - $actual);

        // 기간 경과율(일 기준, 0~100 클램프)
        $startTs = strtotime($g['start_date']);
        $endTs   = strtotime($g['end_date']);
        $nowTs   = strtotime($today);
        $totalDays = max(1, ($endTs - $startTs) / 86400 + 1);
        $elapsedDays = min($totalDays, max(0, ($nowTs - $startTs) / 86400 + 1));
        $elapsedPct = round($elapsedDays / $totalDays * 100, 1);

        // 예상 달성치(현재 페이스 선형 환산) — 시작 전이거나 목표 미설정이면 null
        $projected = null;
        $expectOk  = null;
        if ($elapsedDays > 0 && $target > 0 && $nowTs >= $startTs) {
            $projected = (int) round($actual / ($elapsedDays / $totalDays));
            $expectOk  = $projected >= $target;
        }

        // 표시 상태
        if ($g['status'] === 'cancelled') {
            $state = 'cancelled';
        } elseif ($g['status'] === 'ended') {
            $state = 'ended';
        } elseif ($nowTs < $startTs) {
            $state = 'ready';
        } elseif ($rate !== null && $rate >= self::OVER_ACHIEVE_PCT) {
            $state = 'exceeded';
        } elseif ($rate !== null && $rate >= 100.0) {
            $state = 'achieved';
        } elseif ($nowTs > $endTs) {
            $state = 'missed';   // 기간 종료 후에만 미달 확정
        } else {
            $state = 'ongoing';  // 기간 중에는 미달 확정하지 않음(예상 달성 여부로 구분)
        }
        [$label, $badge] = self::STATE_META[$state];

        return [
            'actual' => $actual, 'rate' => $rate, 'remaining' => $remaining,
            'elapsedPct' => $elapsedPct, 'projected' => $projected, 'expectOk' => $expectOk,
            'state' => $state, 'stateLabel' => $label, 'stateBadge' => $badge,
        ];
    }

    /**
     * 목표 기간 내 월별 실적 추이(상세 모달용).
     * @return array<int, array{ym:string, label:string, value:int}>
     */
    public static function monthlyTrend(array $g): array
    {
        $out = [];
        $cur = strtotime(date('Y-m-01', strtotime($g['start_date'])));
        $endTs = strtotime($g['end_date']);
        $guard = 0;
        while ($cur <= $endTs && $guard++ < 60) {
            $mFrom = date('Y-m-d', $cur);
            $mTo   = date('Y-m-t', $cur);
            // 목표 기간 경계로 잘라 집계(첫/끝 달 부분 기간 반영)
            $slice = array_merge($g, [
                'start_date' => max($mFrom, $g['start_date']),
                'end_date'   => min($mTo, $g['end_date']),
            ]);
            $out[] = [
                'ym'    => date('Y-m', $cur),
                'label' => date('Y년 n월', $cur),
                'value' => self::actual($slice),
            ];
            $cur = strtotime('+1 month', $cur);
        }
        return $out;
    }

    /**
     * 개인 월 목표(매출·순이익) — 대시보드·성과 화면 브리지.
     * goals(월간·개인·active) 우선, 없으면 레거시 targets 행 폴백(과거 데이터 연속성).
     * @return array{revenue:?float, profit:?float}
     */
    public static function personalMonthTarget(int $uid, int $year, int $month): array
    {
        $out = ['revenue' => null, 'profit' => null];
        foreach (Db::all(
            "SELECT metric, target_value FROM goals
             WHERE deleted_at IS NULL AND status = 'active' AND subject_type = 'user'
               AND user_id = :u AND period_type = 'month' AND year = :y AND period_no = :m
               AND metric IN ('revenue','profit')
             ORDER BY id DESC",
            [':u' => $uid, ':y' => $year, ':m' => $month]
        ) as $r) {
            $key = $r['metric'] === 'revenue' ? 'revenue' : 'profit';
            if ($out[$key] === null) {
                $out[$key] = (float) $r['target_value'];
            }
        }
        if ($out['revenue'] === null || $out['profit'] === null) {
            $legacy = Db::one(
                'SELECT target_revenue, target_profit FROM targets WHERE user_id = :u AND year = :y AND month = :m',
                [':u' => $uid, ':y' => $year, ':m' => $month]
            );
            if ($legacy) {
                $out['revenue'] = $out['revenue'] ?? ((float) $legacy['target_revenue'] ?: null);
                $out['profit']  = $out['profit'] ?? ((float) $legacy['target_profit'] ?: null);
            }
        }
        return $out;
    }
}
