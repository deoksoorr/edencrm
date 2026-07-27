<?php
/**
 * 리포트 — perm report.view(index/data), report.export(export).
 * index 는 화면 뼈대만 렌더링하고, reports.js 가 reports.data 를 호출해 차트/표를 그린다(대시보드와 동일 패턴).
 *
 * 기간필터: today/week/month/quarter/year/custom. 일부 항목(지연 프로젝트/미수금 현황/원가초과 프로젝트)은
 * 성격상 "현재 스냅샷"이 자연스러워 기간필터를 적용하지 않는다(주석 표기). 월별 추이는 항상 최근 6개월(마감월=선택기간의 종료월)을 보여준다.
 * 데이터 범위는 report.view 보유 역할이 모두 project.view_all 을 함께 갖고 있으나, 방어적으로 Scope 헬퍼를 사용한다.
 */
class ReportsController
{
    private const TYPES = [
        'monthly_trend', 'by_source', 'by_stage', 'sales_conversion', 'quote_conversion',
        'project_pl', 'by_work_type', 'staff_performance', 'delayed_projects', 'receivables', 'cost_overrun',
        'target_achievement',
    ];

    public function index(): void
    {
        View::render('reports/index', [
            'title'   => '리포트',
            'scripts' => ['vendor/chart.umd.js', 'js/reports.js'],
        ]);
    }

    /** 기간 파라미터 기반 리포트 데이터 JSON. */
    public function data(): void
    {
        [$from, $to, $label] = $this->resolveRange(Util::str('period', 'month'), Util::str('from'), Util::str('to'));
        Response::json($this->buildReport($from, $to, $label));
    }

    /** 선택 리포트 CSV(UTF-8 BOM, 엑셀호환). */
    public function export(): void
    {
        $type = Util::str('type', 'monthly_trend');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'monthly_trend';
        }
        [$from, $to, $label] = $this->resolveRange(Util::str('period', 'month'), Util::str('from'), Util::str('to'));
        $report = $this->buildReport($from, $to, $label);
        [$headers, $rows] = $this->toCsvRows($type, $report);

        $filename = 'report_' . $type . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo "\xEF\xBB\xBF"; // UTF-8 BOM(엑셀 호환)
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    // ───────────────────────── 기간 처리 ─────────────────────────

    private function resolveRange(string $period, ?string $from, ?string $to): array
    {
        $today = date('Y-m-d');
        switch ($period) {
            case 'today':
                return [$today, $today, '오늘'];
            case 'week':
                $s = date('Y-m-d', strtotime('monday this week'));
                $e = date('Y-m-d', strtotime('sunday this week'));
                return [$s, $e, '이번 주'];
            case 'quarter':
                $q = (int) ceil(((int) date('n')) / 3);
                $sm = ($q - 1) * 3 + 1;
                $s = date('Y') . '-' . str_pad((string) $sm, 2, '0', STR_PAD_LEFT) . '-01';
                $e = date('Y-m-t', strtotime(date('Y') . '-' . str_pad((string) ($sm + 2), 2, '0', STR_PAD_LEFT) . '-01'));
                return [$s, $e, $q . '분기'];
            case 'year':
                return [date('Y') . '-01-01', date('Y') . '-12-31', date('Y') . '년'];
            case 'custom':
                $f = Util::nullIfEmpty($from) ?? date('Y-m-01');
                $t = Util::nullIfEmpty($to) ?? $today;
                if ($f > $t) {
                    [$f, $t] = [$t, $f];
                }
                return [$f, $t, $f . ' ~ ' . $t];
            case 'month':
            default:
                return [date('Y-m-01'), date('Y-m-t'), date('Y') . '년 ' . date('n') . '월'];
        }
    }

    // ───────────────────────── 데이터 조립 ─────────────────────────

    private function buildReport(string $from, string $to, string $label): array
    {
        [$projWhere, $projParams] = Scope::projectWhere('p');
        [$custWhere, $custParams] = Scope::customerWhere('c');

        return [
            'period'              => ['from' => $from, 'to' => $to, 'label' => $label],
            'monthly_trend'       => $this->monthlyTrend($to),
            'new_customers'       => $this->newCustomers($from, $to, $custWhere, $custParams),
            'by_source'           => $this->bySource($from, $to, $custWhere, $custParams),
            'by_stage'            => $this->byStage(),
            'sales_conversion'    => $this->salesConversion($from, $to),
            'quote_conversion'    => $this->quoteConversion($from, $to),
            'project_pl'          => $this->projectPl($from, $to, $projWhere, $projParams),
            'by_work_type'        => $this->byWorkType($from, $to, $projWhere, $projParams),
            'staff_performance'   => $this->staffPerformance($from, $to, $projWhere, $projParams),
            'delayed_projects'    => $this->delayedProjects($projWhere, $projParams),
            'receivables'         => $this->receivables(),
            'cost_overrun'        => $this->costOverrun($projWhere, $projParams),
            'target_achievement'  => $this->targetAchievement($from, $to),
        ];
    }

    /**
     * 월별 확정매출·확정순이익·순이익률(최근 6개월, 마감월=선택 기간의 종료월).
     * 완료 프로젝트·준공월(actual_end_date)·공급가 기준 — AccountingService::confirmedRevenue/confirmedProfit
     * (대시보드 DashboardController::bossKpi/finance 와 동일 산식). 월별로 서비스 메서드를 호출한다(6회).
     */
    private function monthlyTrend(string $to): array
    {
        $endTs = strtotime(date('Y-m-01', strtotime($to)));
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime("-$i month", $endTs));
        }

        $out = [];
        foreach ($months as $ym) {
            $from = $ym . '-01';
            $end = date('Y-m-t', strtotime($from));
            $revenue = (float) AccountingService::confirmedRevenue($from, $end);
            $profit = (float) AccountingService::confirmedProfit($from, $end);
            $out[] = [
                'ym'          => $ym,
                'revenue'     => $revenue,
                'profit'      => $profit,
                'profit_rate' => Calc::rate($profit, $revenue),
            ];
        }
        return $out;
    }

    /** 신규고객수(customers.created_at 기간 내). */
    private function newCustomers(string $from, string $to, string $custWhere, array $custParams): array
    {
        $count = (int) Db::val(
            "SELECT COUNT(*) FROM customers c WHERE $custWhere AND c.deleted_at IS NULL AND DATE(c.created_at) BETWEEN :f AND :t",
            $custParams + [':f' => $from, ':t' => $to]
        );
        return ['count' => $count];
    }

    /** 유입경로별 고객(기간 내 신규). */
    private function bySource(string $from, string $to, string $custWhere, array $custParams): array
    {
        return Db::all(
            "SELECT COALESCE(NULLIF(c.source,''), '미상') AS source, COUNT(*) AS cnt
             FROM customers c
             WHERE $custWhere AND c.deleted_at IS NULL AND DATE(c.created_at) BETWEEN :f AND :t
             GROUP BY source ORDER BY cnt DESC",
            $custParams + [':f' => $from, ':t' => $to]
        );
    }

    /** 영업단계별 건수(현재 스냅샷, 기간 미적용). */
    private function byStage(): array
    {
        return Db::all(
            "SELECT ps.name AS stage, ps.sort_order, COUNT(l.id) AS cnt, COALESCE(SUM(l.expected_amount),0) AS amount
             FROM pipeline_stages ps
             LEFT JOIN leads l ON l.stage_id = ps.id AND l.deleted_at IS NULL
             GROUP BY ps.id, ps.name, ps.sort_order
             ORDER BY ps.sort_order"
        );
    }

    /** 영업직원별 계약률(기간 내 신규 리드 기준). */
    private function salesConversion(string $from, string $to): array
    {
        return Db::all(
            "SELECT u.id AS user_id, u.name,
                    COUNT(l.id) AS total_leads,
                    SUM(CASE WHEN ps.is_won = 1 THEN 1 ELSE 0 END) AS won_leads
             FROM users u
             JOIN leads l ON l.sales_user_id = u.id AND l.deleted_at IS NULL AND DATE(l.created_at) BETWEEN :f AND :t
             JOIN pipeline_stages ps ON ps.id = l.stage_id
             WHERE u.deleted_at IS NULL
             GROUP BY u.id, u.name
             ORDER BY total_leads DESC",
            [':f' => $from, ':t' => $to]
        );
    }

    /** 견적→계약 전환율(기간 내 발행된 견적 기준). */
    private function quoteConversion(string $from, string $to): array
    {
        $total = (int) Db::val(
            "SELECT COUNT(*) FROM quotes q WHERE q.deleted_at IS NULL AND DATE(q.created_at) BETWEEN :f AND :t",
            [':f' => $from, ':t' => $to]
        );
        $converted = (int) Db::val(
            "SELECT COUNT(*) FROM quotes q
             WHERE q.deleted_at IS NULL AND DATE(q.created_at) BETWEEN :f AND :t
               AND EXISTS (SELECT 1 FROM contracts c WHERE c.quote_id = q.id AND c.deleted_at IS NULL)",
            [':f' => $from, ':t' => $to]
        );
        return ['total_quotes' => $total, 'converted' => $converted, 'rate' => Calc::rate($converted, $total)];
    }

    /** 프로젝트별 손익(기간 내 계약일, 취소·파기 제외 — 브리프 §2). 매출=공급가액, 순이익(률)=AccountingService::projectActualProfit/Rate(공급가 기준). */
    private function projectPl(string $from, string $to, string $projWhere, array $projParams): array
    {
        $rows = Db::all(
            "SELECT p.id, p.project_no, p.name, p.contract_amount, p.supply_amount, p.vat_amount, p.actual_cost, p.status
             FROM projects p
             WHERE $projWhere AND p.deleted_at IS NULL AND p.status NOT IN ('cancelled','terminated')
               AND p.contract_date BETWEEN :f AND :t
             ORDER BY p.contract_date DESC",
            $projParams + [':f' => $from, ':t' => $to]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'project_no' => $r['project_no'], 'name' => $r['name'], 'status' => $r['status'],
                'status_label' => StatusService::PROJECT_LABELS[$r['status']] ?? $r['status'],
                'revenue' => (float) AccountingService::supplyOf($r), 'cost' => (float) $r['actual_cost'],
                'profit' => AccountingService::projectActualProfit($r), 'profit_rate' => AccountingService::projectActualProfitRate($r),
            ];
        }
        return $out;
    }

    /** 공사유형별 매출(공급가)·평균수익률(기간 내 계약일, 취소·파기 제외 — 브리프 §2, 집계 기준 수익률). */
    private function byWorkType(string $from, string $to, string $projWhere, array $projParams): array
    {
        $rows = Db::all(
            "SELECT COALESCE(NULLIF(p.work_type,''),'미상') AS work_type,
                    COUNT(*) AS cnt, COALESCE(SUM(p.supply_amount),0) AS revenue, COALESCE(SUM(p.actual_cost),0) AS cost
             FROM projects p
             WHERE $projWhere AND p.deleted_at IS NULL AND p.status NOT IN ('cancelled','terminated')
               AND p.contract_date BETWEEN :f AND :t
             GROUP BY work_type ORDER BY revenue DESC",
            $projParams + [':f' => $from, ':t' => $to]
        );
        foreach ($rows as &$r) {
            $r['revenue'] = (float) $r['revenue'];
            $r['cost'] = (float) $r['cost'];
            $r['profit'] = Calc::profit($r['revenue'], $r['cost']);
            $r['avg_rate'] = Calc::profitRate($r['revenue'], $r['cost']);
        }
        return $rows;
    }

    /** 직원별 성과 요약(기간 내 계약일 기준, 기여율(contribution_pct) 가중 — T9: 100% 중복 귀속 금지). */
    private function staffPerformance(string $from, string $to, string $projWhere, array $projParams): array
    {
        $rows = Db::all(
            "SELECT u.id AS user_id, u.name,
                    COUNT(DISTINCT p.id) AS cnt,
                    COALESCE(SUM(p.supply_amount * pa.contribution_pct/100),0) AS revenue,
                    COALESCE(SUM(p.actual_cost * pa.contribution_pct/100),0) AS cost
             FROM users u
             JOIN project_assignments pa ON pa.user_id = u.id AND pa.contribution_pct > 0
             JOIN projects p ON p.id = pa.project_id AND $projWhere AND p.deleted_at IS NULL
               AND p.status NOT IN ('cancelled','terminated') AND p.contract_date BETWEEN :f AND :t
             WHERE u.deleted_at IS NULL
             GROUP BY u.id, u.name
             ORDER BY revenue DESC",
            $projParams + [':f' => $from, ':t' => $to]
        );
        foreach ($rows as &$r) {
            $r['revenue'] = (float) $r['revenue'];
            $r['cost'] = (float) $r['cost'];
            $r['profit'] = Calc::profit($r['revenue'], $r['cost']);
        }
        return $rows;
    }

    /** 지연 프로젝트(현재 스냅샷, 기간 미적용). */
    private function delayedProjects(string $projWhere, array $projParams): array
    {
        // 대시보드 delayedCond 와 동일 기준(완료·정산·취소·파기 제외)으로 통일
        return Db::all(
            "SELECT p.project_no, p.name, p.end_date, p.status, DATEDIFF(CURDATE(), p.end_date) AS days_over,
                    u.name AS site_manager
             FROM projects p
             LEFT JOIN users u ON u.id = p.site_manager_id
             WHERE $projWhere AND p.deleted_at IS NULL AND p.status NOT IN ('completed','settled','cancelled','terminated')
               AND p.end_date IS NOT NULL AND p.end_date < CURDATE() AND p.actual_end_date IS NULL
             ORDER BY p.end_date ASC",
            $projParams
        );
    }

    /**
     * 미수금 현황(현재 스냅샷, 기간 미적용). 총액=AccountingService::receivable()
     * — 모집단 = RECEIVABLE_STATUSES(체결 이후 계약: active/on_hold/completed. 작성중·파기·취소·삭제 제외).
     * 목록의 계약별 미수금 = GREATEST(0, 계약총액 − Σ입금)(VAT포함, 현금 축) — 총액과 동일 모집단·동일 기준.
     */
    private function receivables(): array
    {
        $paidSql = AccountingService::PAID_SUM_SQL;
        $statusIn = "'" . implode("','", AccountingService::RECEIVABLE_STATUSES) . "'";
        $rows = Db::all(
            "SELECT c.contract_no, cu.name AS customer_name, c.contract_amount,
                    $paidSql AS paid,
                    GREATEST(0, c.contract_amount - $paidSql) AS receivable
             FROM contracts c
             JOIN customers cu ON cu.id = c.customer_id
             WHERE c.deleted_at IS NULL AND c.status IN ($statusIn)
             HAVING receivable > 0
             ORDER BY receivable DESC"
        );
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'contract_no' => $r['contract_no'], 'customer_name' => $r['customer_name'],
                'contract_amount' => (float) $r['contract_amount'], 'paid' => (float) $r['paid'],
                'receivable' => (float) $r['receivable'],
            ];
        }
        return ['total' => (float) AccountingService::receivable(), 'list' => $list];
    }

    /** 원가초과 프로젝트(실제원가 > 예상원가, 현재 스냅샷). */
    private function costOverrun(string $projWhere, array $projParams): array
    {
        $rows = Db::all(
            "SELECT p.project_no, p.name, p.estimated_cost, p.actual_cost, p.status
             FROM projects p
             WHERE $projWhere AND p.deleted_at IS NULL AND p.actual_cost > p.estimated_cost AND p.estimated_cost > 0
             ORDER BY (p.actual_cost - p.estimated_cost) DESC",
            $projParams
        );
        foreach ($rows as &$r) {
            $est = (float) $r['estimated_cost'];
            $act = (float) $r['actual_cost'];
            $r['over_amount'] = round($act - $est, 0);
            $r['over_rate'] = Calc::rate($act - $est, $est);
        }
        return $rows;
    }

    /** 목표대비 달성률(기간에 포함된 월들의 회사 월목표 합계 vs 확정 실적). 대시보드와 동일 기준(company_targets + AccountingService). */
    private function targetAchievement(string $from, string $to): array
    {
        $months = [];
        $cursor = strtotime(date('Y-m-01', strtotime($from)));
        $endTs = strtotime(date('Y-m-01', strtotime($to)));
        while ($cursor <= $endTs) {
            $months[] = ['y' => (int) date('Y', $cursor), 'm' => (int) date('n', $cursor)];
            $cursor = strtotime('+1 month', $cursor);
        }

        $targetRevenue = 0.0;
        $targetProfit = 0.0;
        foreach ($months as $mm) {
            $t = Db::one(
                "SELECT COALESCE(target_revenue,0) AS tr, COALESCE(target_profit,0) AS tp
                 FROM company_targets WHERE period_type='month' AND year=:y AND period_no=:m",
                [':y' => $mm['y'], ':m' => $mm['m']]
            );
            $targetRevenue += (float) ($t['tr'] ?? 0);
            $targetProfit += (float) ($t['tp'] ?? 0);
        }

        // 실적 = 완료·준공일(actual_end_date)·공급가 기준(AccountingService, 대시보드와 동일 산식).
        $actualRevenue = (float) AccountingService::confirmedRevenue($from, $to);
        $actualProfit = (float) AccountingService::confirmedProfit($from, $to);

        return [
            'target_revenue' => $targetRevenue, 'actual_revenue' => $actualRevenue,
            'revenue_rate'   => AccountingService::achievement($actualRevenue, $targetRevenue),
            'target_profit'  => $targetProfit, 'actual_profit' => $actualProfit,
            'profit_rate'    => AccountingService::achievement($actualProfit, $targetProfit),
        ];
    }

    // ───────────────────────── 직원 출근 분석 (R4 T4 · R6 최종 구조) ─────────────────────────

    /**
     * 직원 출근 분석 탭 — 연/월/직원/부서/재직 필터 + 요약 KPI + 직원×일자 그리드 + 차트(가로 막대·6개월 추이)
     * + 상세 표 + 관리자용 마킹 캘린더(perm attendance.manage — 없으면 조회 전용, 마킹 UI 미노출).
     * 통계는 R6 확정 3종만(AttendanceService — 대시보드 출근 요약과 동일 산식):
     *  출근 일수 = user_id+work_date DISTINCT − 무단결근(absent) 마크와 겹치는 날 제외
     *  지각 횟수 = late 마크 수(수동 등록, 출근 일수에 포함) / 무단결근 횟수 = absent 마크 수.
     * feature_attendance 게이트는 라우터가 강제. 비활성 직원 과거 통계는 재직 필터로 조회 가능.
     */
    public function attendance(): void
    {
        $f = $this->attendanceFilters();
        $allUsers = Db::all("SELECT id, name FROM users WHERE deleted_at IS NULL ORDER BY name");
        $canMark = Rbac::can('attendance.manage');

        // 마킹 캘린더 대상 직원(mark_user > 필터 직원 > 첫 직원) — 실존 직원만 허용
        $markUser = 0;
        if ($canMark && $allUsers) {
            $ids = array_map('intval', array_column($allUsers, 'id'));
            $req = max(0, (int) Util::int('mark_user', 0)) ?: $f['user_id'];
            $markUser = in_array($req, $ids, true) ? $req : $ids[0];
        }

        View::render('reports/attendance', [
            'title'    => '직원 출근 분석',
            'f'        => $f,
            'd'        => $this->attendanceData($f),
            'depts'    => Db::all("SELECT id, name FROM departments ORDER BY sort_order, id"),
            'allUsers' => $allUsers,
            'canMark'  => $canMark,
            'markUser' => $markUser,
            'markCal'  => $markUser ? $this->markCalendarData($f, $markUser) : null,
            'scripts'  => ['vendor/chart.umd.js', 'js/report_attendance.js'],
        ]);
    }

    /** 직원 출근 상세 표 CSV(UTF-8 BOM) — 통계 3종(출근·지각·무단결근)+전월 비교. perm report.export — 라우터 강제. */
    public function attendanceExport(): void
    {
        $f = $this->attendanceFilters();
        $d = $this->attendanceData($f);

        $filename = sprintf('attendance_%04d%02d_%s.csv', $f['year'], $f['month'], date('Ymd_His'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['직원', '부서', '재직 상태', '출근 일수', '지각(회)', '무단결근(회)', '전월 출근 일수', '전월 대비 증감(일)', '출근 일자'], ',', '"', '\\');
        foreach ($d['rows'] as $r) {
            fputcsv($out, [
                $r['name'], $r['dept'] ?? '', $r['status_label'], $r['days'],
                $r['late'], $r['absent'], $r['prev_days'], $r['delta'], implode(' ', $r['dates']),
            ], ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /** 출근 분석 필터 파싱(연/월/직원/부서/재직). 잘못된 값은 기본값으로 강제. */
    private function attendanceFilters(): array
    {
        $year = (int) Util::int('year', (int) date('Y'));
        if ($year < 2000 || $year > 2100) { $year = (int) date('Y'); }
        $month = (int) Util::int('month', (int) date('n'));
        if ($month < 1 || $month > 12) { $month = (int) date('n'); }
        $status = Util::str('status', 'active');
        if (!in_array($status, ['active', 'inactive', 'all'], true)) { $status = 'active'; }
        return [
            'year'    => $year,
            'month'   => $month,
            'user_id' => max(0, (int) Util::int('user_id', 0)),
            'dept'    => max(0, (int) Util::int('dept', 0)),
            'status'  => $status,
        ];
    }

    /**
     * 출근 분석 데이터 조립 — AttendanceService 배치 조회만 사용(N+1 금지).
     * 반환: scheduled(영업일 — 차트 축 참고용), dates[일자 메타], rows[직원별 통계 3종+마크], kpi, trend(6개월), holidays.
     */
    private function attendanceData(array $f): array
    {
        $cond = ['u.deleted_at IS NULL'];
        $params = [];
        if ($f['status'] !== 'all') { $cond[] = 'u.status = :st'; $params[':st'] = $f['status']; }
        if ($f['dept'] > 0)         { $cond[] = 'u.department_id = :dept'; $params[':dept'] = $f['dept']; }
        if ($f['user_id'] > 0)      { $cond[] = 'u.id = :uid'; $params[':uid'] = $f['user_id']; }
        $users = Db::all(
            "SELECT u.id, u.name, u.color, u.role_key, u.status, d.name AS dept
             FROM users u LEFT JOIN departments d ON d.id = u.department_id
             WHERE " . implode(' AND ', $cond) . " ORDER BY u.name", $params
        );
        $ids = array_map('intval', array_column($users, 'id'));

        $ov = AttendanceService::monthOverview($f['year'], $f['month'], $ids);
        $holidays = AttendanceService::holidayMap($ov['from'], $ov['to']);

        $statusLabels = ['active' => '재직', 'inactive' => '비활성'];
        $roleLabels = ['super_admin' => '대표', 'sales_manager' => '영업', 'site_manager' => '현장', 'staff' => '직원', 'accountant' => '회계'];
        $rows = [];
        foreach ($users as $u) {
            $id = (int) $u['id'];
            $days = (int) ($ov['days'][$id] ?? 0);
            $prev = (int) ($ov['prev_days'][$id] ?? 0);
            $attended = array_keys($ov['matrix'][$id] ?? []);
            sort($attended);
            $rows[] = [
                'id'           => $id,
                'name'         => $u['name'],
                'color'        => $u['color'] ?: Stages::defaultColorFor($id),
                'role'         => $roleLabels[$u['role_key']] ?? $u['role_key'],
                'dept'         => $u['dept'],
                'status'       => $u['status'],
                'status_label' => $statusLabels[$u['status']] ?? $u['status'],
                'days'         => $days,                          // 출근 일수(absent 마크 겹침 제외)
                'prev_days'    => $prev,
                'delta'        => $days - $prev,
                'late'         => (int) ($ov['late'][$id] ?? 0),   // 지각 횟수(수동 마크)
                'absent'       => (int) ($ov['absent'][$id] ?? 0), // 무단결근 횟수(수동 마크)
                'marks'        => $ov['marks'][$id] ?? [],         // date => {id,type,memo} (그리드 오버레이)
                'marked'       => $ov['matrix'][$id] ?? [],        // date => true (출근 ●)
                'dates'        => $attended,
            ];
        }
        usort($rows, fn($a, $b) => $b['days'] <=> $a['days']);

        $dayVals = array_column($rows, 'days');
        $total = array_sum($dayVals);
        $prevTotal = array_sum(array_column($rows, 'prev_days'));
        $kpi = [
            'headcount'    => count($rows),
            'total'        => $total,
            'avg'          => $rows ? round($total / count($rows), 1) : null,
            'max'          => $dayVals ? max($dayVals) : null,
            'min'          => $dayVals ? min($dayVals) : null,
            'prev_total'   => $prevTotal,
            'delta'        => $total - $prevTotal,
            'late_total'   => array_sum(array_column($rows, 'late')),
            'absent_total' => array_sum(array_column($rows, 'absent')),
        ];

        return [
            'from'      => $ov['from'],
            'to'        => $ov['to'],
            'scheduled' => $ov['scheduled'],
            'dates'     => $this->monthDateMeta($ov['from'], $ov['to'], $holidays),
            'rows'      => $rows,
            'kpi'       => $kpi,
            'trend'     => $ids ? AttendanceService::monthlyTotals(sprintf('%04d-%02d', $f['year'], $f['month']), 6, $ids) : [],
            'holidays'  => $holidays,
        ];
    }

    /** 일자 메타(그리드 헤더·마킹 캘린더 칸 구분: 요일/주말/공휴일/미래) — 한 달 배열. */
    private function monthDateMeta(string $from, string $to, array $holidays): array
    {
        $today = date('Y-m-d');
        $dates = [];
        $last = (int) date('j', strtotime($to));
        for ($day = 1; $day <= $last; $day++) {
            $date = sprintf('%s-%02d', substr($from, 0, 7), $day);
            $n = (int) date('N', strtotime($date));
            $dates[] = [
                'date'    => $date,
                'day'     => $day,
                'dow'     => $n,
                'weekend' => $n >= 6,
                'holiday' => $holidays[$date] ?? null,
                'future'  => $date > $today,
            ];
        }
        return $dates;
    }

    /**
     * 관리자 마킹 캘린더 데이터(단일 직원×선택 월) — 마크·출근 매트릭스 배치 2쿼리.
     * 조회 전용 사용자는 호출되지 않는다(attendance() 의 canMark 게이트).
     */
    private function markCalendarData(array $f, int $userId): array
    {
        $from = sprintf('%04d-%02d-01', $f['year'], $f['month']);
        $to = date('Y-m-t', strtotime($from));
        $marks = AttendanceService::marksByUser($from, $to, [$userId]);
        $matrix = AttendanceService::matrixByUser($from, $to, [$userId]);
        return [
            'from'     => $from,
            'to'       => $to,
            'marks'    => $marks[$userId] ?? [],
            'attended' => $matrix[$userId] ?? [],
        ];
    }

    // ───────────────────────── CSV 변환 ─────────────────────────

    private function toCsvRows(string $type, array $report): array
    {
        switch ($type) {
            case 'monthly_trend':
                $headers = ['년월', '확정 매출(공급가액)', '확정 순이익', '순이익률(%)'];
                $rows = array_map(fn($r) => [$r['ym'], $r['revenue'], $r['profit'], $r['profit_rate'] ?? ''], $report['monthly_trend']);
                return [$headers, $rows];
            case 'by_source':
                $headers = ['유입경로', '고객수'];
                $rows = array_map(fn($r) => [$r['source'], $r['cnt']], $report['by_source']);
                return [$headers, $rows];
            case 'by_stage':
                $headers = ['영업단계', '건수', '예상금액합계'];
                $rows = array_map(fn($r) => [$r['stage'], $r['cnt'], $r['amount']], $report['by_stage']);
                return [$headers, $rows];
            case 'sales_conversion':
                $headers = ['영업담당자', '전체리드', '계약건수', '계약률(%)'];
                $rows = array_map(fn($r) => [$r['name'], $r['total_leads'], $r['won_leads'], Calc::rate((float) $r['won_leads'], (float) $r['total_leads']) ?? ''], $report['sales_conversion']);
                return [$headers, $rows];
            case 'quote_conversion':
                $headers = ['전체견적', '계약전환', '전환율(%)'];
                $q = $report['quote_conversion'];
                return [$headers, [[$q['total_quotes'], $q['converted'], $q['rate'] ?? '']]];
            case 'project_pl':
                $headers = ['프로젝트번호', '이름', '상태', '공급가액(VAT 제외)', '원가 총액', '순이익', '순이익률(%)'];
                // 상태는 한글 라벨(StatusService 단일 출처) — 화면 표와 표기 통일
                $rows = array_map(fn($r) => [$r['project_no'], $r['name'], $r['status_label'] ?? $r['status'], $r['revenue'], $r['cost'], $r['profit'], $r['profit_rate'] ?? ''], $report['project_pl']);
                return [$headers, $rows];
            case 'by_work_type':
                $headers = ['공사유형', '건수', '공급가액(VAT 제외)', '원가 총액', '순이익', '평균수익률(%)'];
                $rows = array_map(fn($r) => [$r['work_type'], $r['cnt'], $r['revenue'], $r['cost'], $r['profit'], $r['avg_rate'] ?? ''], $report['by_work_type']);
                return [$headers, $rows];
            case 'staff_performance':
                $headers = ['직원', '프로젝트수', '공급가액(VAT 제외)', '원가 총액', '순이익'];
                $rows = array_map(fn($r) => [$r['name'], $r['cnt'], $r['revenue'], $r['cost'], $r['profit']], $report['staff_performance']);
                return [$headers, $rows];
            case 'delayed_projects':
                $headers = ['프로젝트번호', '이름', '준공예정일', '지연일수', '현장책임자'];
                $rows = array_map(fn($r) => [$r['project_no'], $r['name'], $r['end_date'], $r['days_over'], $r['site_manager'] ?? ''], $report['delayed_projects']);
                return [$headers, $rows];
            case 'receivables':
                $headers = ['계약번호', '고객명', '계약 총액(VAT 포함)', '입금 총액(VAT 포함)', '미수금'];
                $rows = array_map(fn($r) => [$r['contract_no'], $r['customer_name'], $r['contract_amount'], $r['paid'], $r['receivable']], $report['receivables']['list']);
                return [$headers, $rows];
            case 'cost_overrun':
                $headers = ['프로젝트번호', '이름', '예상원가', '실제원가', '초과금액', '초과율(%)'];
                $rows = array_map(fn($r) => [$r['project_no'], $r['name'], $r['estimated_cost'], $r['actual_cost'], $r['over_amount'], $r['over_rate'] ?? ''], $report['cost_overrun']);
                return [$headers, $rows];
            case 'target_achievement':
                $headers = ['구분', '목표', '실적', '달성률(%)'];
                $ta = $report['target_achievement'];
                return [$headers, [
                    ['확정 매출(공급가액)', $ta['target_revenue'], $ta['actual_revenue'], $ta['revenue_rate'] ?? ''],
                    ['확정 순이익', $ta['target_profit'], $ta['actual_profit'], $ta['profit_rate'] ?? ''],
                ]];
            default:
                return [['항목'], []];
        }
    }
}
