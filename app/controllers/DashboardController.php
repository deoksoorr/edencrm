<?php
/**
 * 대시보드(T3 골격 → T8 확장). 권한별(사장 계열/직원) 숫자카드는 index() 에서 동기 렌더링하고,
 * 차트(월별 매출 line, 영업단계 분포 doughnut, 직원별 매출 bar, 목표달성 gauge / 직원용 공정분포+게이지)는
 * dashboard.data 가 JSON 으로 제공해 dashboard.js 가 그린다. 모든 계산은 Calc 사용(0 나눗셈 → null → 화면 '-').
 *
 * 알림 자동 생성기(NotificationsController::generateMissing)는 dashboard.data 호출 시 실행한다(스펙 지시).
 */
class DashboardController
{
    public function index(): void
    {
        $u = Auth::user();
        $isBoss = Rbac::isRole('super_admin', 'sales_manager', 'accountant');

        if ($isBoss) {
            View::render('dashboard/boss', [
                'title' => '대시보드', 'stats' => $this->bossStats(), 'me' => $u,
                'scripts' => ['vendor/chart.umd.js', 'js/dashboard.js'],
            ]);
        } else {
            View::render('dashboard/staff', [
                'title' => '내 대시보드', 'stats' => $this->staffStats((int) $u['id']), 'me' => $u,
                'scripts' => ['vendor/chart.umd.js', 'js/dashboard.js'],
            ]);
        }
    }

    /** 차트 JSON + 알림 자동 생성. */
    public function data(): void
    {
        load_controller('NotificationsController');
        NotificationsController::generateMissing();

        $u = Auth::user();
        $isBoss = Rbac::isRole('super_admin', 'sales_manager', 'accountant');

        Response::json($isBoss ? $this->bossChartData() : $this->staffChartData((int) $u['id']));
    }

    // ───────────────────────── 사장 계열: 숫자카드(스펙 5-1) ─────────────────────────

    private function bossStats(): array
    {
        $s = [];
        $s['customers'] = (int) Db::val("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL");

        $stageCounts = Db::all(
            "SELECT ps.stage_key, COUNT(l.id) AS cnt
             FROM pipeline_stages ps LEFT JOIN leads l ON l.stage_id = ps.id AND l.deleted_at IS NULL
             GROUP BY ps.stage_key"
        );
        $byStage = array_column($stageCounts, 'cnt', 'stage_key');
        $g = fn($keys) => array_sum(array_map(fn($k) => (int) ($byStage[$k] ?? 0), (array) $keys));

        $s['new_inquiry']      = $g('new_inquiry');
        $s['consulting']       = $g(['consult_booked', 'site_survey']);
        $s['quoting']          = $g(['quote_drafting', 'quote_sent', 'negotiating']);
        $s['contract_pending'] = $g('contract_pending');
        $s['contract_won']     = $g('contract_won');

        $s['projects_active']  = (int) Db::val("SELECT COUNT(*) FROM projects WHERE status='in_progress' AND deleted_at IS NULL");
        $s['projects_delayed'] = (int) Db::val(
            "SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL AND status <> 'completed'
             AND end_date IS NOT NULL AND end_date < CURDATE() AND actual_end_date IS NULL"
        );

        $monthRow = Db::one(
            "SELECT COALESCE(SUM(contract_amount),0) AS revenue, COALESCE(SUM(estimated_cost),0) AS est_cost, COALESCE(SUM(actual_cost),0) AS act_cost
             FROM projects
             WHERE deleted_at IS NULL AND YEAR(contract_date) = YEAR(CURDATE()) AND MONTH(contract_date) = MONTH(CURDATE())"
        );
        $s['revenue_month']         = (float) $monthRow['revenue'];
        $s['estimated_cost_month']  = (float) $monthRow['est_cost'];
        $s['expected_profit_month'] = Calc::profit($s['revenue_month'], $s['estimated_cost_month']);
        $s['actual_profit_month']   = Calc::profit($s['revenue_month'], (float) $monthRow['act_cost']);
        $s['profit_rate_month']     = Calc::profitRate($s['revenue_month'], (float) $monthRow['act_cost']);

        // 예정매출(파이프라인 가중 예상매출) = Σ(예상금액 × 성공확률%) — 진행 중(미확정) 리드만
        $activeLeads = Db::all(
            "SELECT l.expected_amount, l.win_probability
             FROM leads l JOIN pipeline_stages ps ON ps.id = l.stage_id
             WHERE l.deleted_at IS NULL AND ps.is_won = 0 AND ps.is_lost = 0"
        );
        $expectedRevenue = 0.0;
        foreach ($activeLeads as $l) {
            $expectedRevenue += Calc::weightedRevenue((float) ($l['expected_amount'] ?? 0), (float) ($l['win_probability'] ?? 0));
        }
        $s['expected_revenue_pipeline'] = $expectedRevenue;

        $s['receivable'] = (float) Db::val(
            "SELECT COALESCE(SUM(c.contract_amount),0) - COALESCE((
                SELECT SUM(p.amount) FROM payments p
                JOIN contracts c2 ON c2.id = p.contract_id
                WHERE p.status = 'paid' AND c2.deleted_at IS NULL), 0)
             FROM contracts c WHERE c.deleted_at IS NULL"
        );

        $leadTotal = (int) Db::val('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL');
        $leadWon = (int) Db::val(
            "SELECT COUNT(*) FROM leads l JOIN pipeline_stages ps ON ps.id = l.stage_id WHERE l.deleted_at IS NULL AND ps.is_won = 1"
        );
        $s['conversion_rate'] = Calc::rate($leadWon, $leadTotal);

        $avgDays = Db::val(
            "SELECT ROUND(AVG(DATEDIFF(l.stage_entered_at, l.created_at)))
             FROM leads l JOIN pipeline_stages ps ON ps.id = l.stage_id
             WHERE ps.stage_key = 'contract_won' AND l.deleted_at IS NULL AND l.stage_entered_at IS NOT NULL"
        );
        $s['avg_contract_days'] = $avgDays !== null ? (float) $avgDays : null;

        return $s;
    }

    // ───────────────────────── 직원: 숫자카드(스펙 5-2) ─────────────────────────

    private function staffStats(int $uid): array
    {
        $s = [];
        $s['my_projects'] = (int) Db::val(
            "SELECT COUNT(DISTINCT p.id) FROM projects p
             LEFT JOIN project_assignments pa ON pa.project_id = p.id
             WHERE p.deleted_at IS NULL AND (pa.user_id = :u1 OR p.site_manager_id = :u2 OR p.sales_user_id = :u3)",
            [':u1' => $uid, ':u2' => $uid, ':u3' => $uid]
        );
        $s['today_schedules'] = (int) Db::val(
            'SELECT COUNT(*) FROM schedules WHERE user_id=:u AND DATE(start_datetime)=CURDATE()',
            [':u' => $uid]
        );
        $s['week_schedules'] = (int) Db::val(
            'SELECT COUNT(*) FROM schedules WHERE user_id=:u AND start_datetime >= CURDATE() AND start_datetime < CURDATE() + INTERVAL 7 DAY',
            [':u' => $uid]
        );

        $myProjects = Db::all(
            "SELECT DISTINCT p.id, p.status, p.contract_amount, p.estimated_cost, p.actual_cost, p.end_date, p.actual_end_date, p.contract_date
             FROM projects p
             LEFT JOIN project_assignments pa ON pa.project_id = p.id AND pa.user_id = :pa_u
             WHERE p.deleted_at IS NULL AND (p.sales_user_id = :sales_u OR p.site_manager_id = :site_u OR pa.user_id = :assign_u)",
            [':pa_u' => $uid, ':sales_u' => $uid, ':site_u' => $uid, ':assign_u' => $uid]
        );

        $delayed = 0;
        $monthRevenue = 0.0;
        $revenueAll = 0.0;
        $estCostAll = 0.0;
        $actCostAll = 0.0;
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');
        foreach ($myProjects as $p) {
            if ($p['status'] !== 'completed' && $p['actual_end_date'] === null
                && $p['end_date'] !== null && $p['end_date'] < $today) {
                $delayed++;
            }
            if ($p['contract_date'] !== null && substr((string) $p['contract_date'], 0, 7) === $thisMonth) {
                $monthRevenue += (float) $p['contract_amount'];
            }
            $revenueAll += (float) $p['contract_amount'];
            $estCostAll += (float) $p['estimated_cost'];
            $actCostAll += (float) $p['actual_cost'];
        }

        $s['delayed_projects']  = $delayed;
        $s['expected_profit']   = Calc::profit($revenueAll, $estCostAll);
        $s['actual_profit']     = Calc::profit($revenueAll, $actCostAll);

        $target = Db::one(
            'SELECT target_revenue FROM targets WHERE user_id=:u AND year=:y AND month=:m',
            [':u' => $uid, ':y' => (int) date('Y'), ':m' => (int) date('n')]
        );
        $s['target_revenue']   = $target ? (float) $target['target_revenue'] : 0.0;
        $s['achieved_revenue'] = $monthRevenue;
        $s['achieve_rate']     = Calc::achievement($monthRevenue, $s['target_revenue']);

        return $s;
    }

    // ───────────────────────── 차트 JSON ─────────────────────────

    private function bossChartData(): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime("-$i month"));
        }
        $rangeStart = $months[0] . '-01';
        $rangeEnd = date('Y-m-t');

        $rows = Db::all(
            "SELECT DATE_FORMAT(contract_date, '%Y-%m') AS ym,
                    COALESCE(SUM(contract_amount), 0) AS revenue, COALESCE(SUM(actual_cost), 0) AS cost
             FROM projects
             WHERE deleted_at IS NULL AND contract_date BETWEEN :s AND :e
             GROUP BY ym",
            [':s' => $rangeStart, ':e' => $rangeEnd]
        );
        $byYm = array_column($rows, null, 'ym');
        $trend = [];
        foreach ($months as $ym) {
            $rev = (float) ($byYm[$ym]['revenue'] ?? 0);
            $cost = (float) ($byYm[$ym]['cost'] ?? 0);
            $trend[] = ['ym' => $ym, 'revenue' => $rev, 'profit' => Calc::profit($rev, $cost)];
        }

        $stageDist = Db::all(
            "SELECT ps.name AS stage, COUNT(l.id) AS cnt
             FROM pipeline_stages ps LEFT JOIN leads l ON l.stage_id = ps.id AND l.deleted_at IS NULL
             GROUP BY ps.id, ps.name ORDER BY ps.sort_order"
        );

        $staffRevenue = Db::all(
            "SELECT u.name, COALESCE(SUM(p.contract_amount), 0) AS revenue
             FROM users u
             JOIN projects p ON p.sales_user_id = u.id AND p.deleted_at IS NULL
               AND YEAR(p.contract_date) = YEAR(CURDATE()) AND MONTH(p.contract_date) = MONTH(CURDATE())
             WHERE u.deleted_at IS NULL
             GROUP BY u.id, u.name
             ORDER BY revenue DESC LIMIT 10"
        );
        foreach ($staffRevenue as &$r) {
            $r['revenue'] = (float) $r['revenue'];
        }
        unset($r);

        $targetRevenue = (float) Db::val(
            'SELECT COALESCE(SUM(target_revenue),0) FROM targets WHERE year=:y AND month=:m',
            [':y' => (int) date('Y'), ':m' => (int) date('n')]
        );
        $actualRevenue = (float) Db::val(
            "SELECT COALESCE(SUM(contract_amount),0) FROM projects
             WHERE deleted_at IS NULL AND YEAR(contract_date)=YEAR(CURDATE()) AND MONTH(contract_date)=MONTH(CURDATE())"
        );

        return [
            'monthly_trend'      => $trend,
            'stage_distribution' => $stageDist,
            'staff_revenue'      => $staffRevenue,
            'goal_gauge'         => [
                'target' => $targetRevenue, 'actual' => $actualRevenue,
                'rate'   => Calc::achievement($actualRevenue, $targetRevenue),
            ],
        ];
    }

    private function staffChartData(int $uid): array
    {
        $processRows = Db::all(
            "SELECT ps.name AS stage, COUNT(DISTINCT p.id) AS cnt
             FROM projects p
             LEFT JOIN project_assignments pa ON pa.project_id = p.id AND pa.user_id = :pa_u
             LEFT JOIN process_stages ps ON ps.id = p.process_stage_id
             WHERE p.deleted_at IS NULL AND p.status = 'in_progress'
               AND (p.sales_user_id = :sales_u OR p.site_manager_id = :site_u OR pa.user_id = :assign_u)
             GROUP BY ps.id, ps.name
             ORDER BY ps.sort_order",
            [':pa_u' => $uid, ':sales_u' => $uid, ':site_u' => $uid, ':assign_u' => $uid]
        );

        $myProjects = Db::all(
            "SELECT DISTINCT p.id, p.contract_amount, p.contract_date
             FROM projects p
             LEFT JOIN project_assignments pa ON pa.project_id = p.id AND pa.user_id = :pa_u2
             WHERE p.deleted_at IS NULL AND (p.sales_user_id = :sales_u2 OR p.site_manager_id = :site_u2 OR pa.user_id = :assign_u2)",
            [':pa_u2' => $uid, ':sales_u2' => $uid, ':site_u2' => $uid, ':assign_u2' => $uid]
        );
        $achieved = 0.0;
        $thisMonth = date('Y-m');
        foreach ($myProjects as $p) {
            if ($p['contract_date'] !== null && substr((string) $p['contract_date'], 0, 7) === $thisMonth) {
                $achieved += (float) $p['contract_amount'];
            }
        }

        $target = Db::one(
            'SELECT target_revenue FROM targets WHERE user_id=:u AND year=:y AND month=:m',
            [':u' => $uid, ':y' => (int) date('Y'), ':m' => (int) date('n')]
        );
        $targetRevenue = $target ? (float) $target['target_revenue'] : 0.0;

        return [
            'process_breakdown' => $processRows,
            'goal_gauge'        => [
                'target' => $targetRevenue, 'actual' => $achieved,
                'rate'   => Calc::achievement($achieved, $targetRevenue),
            ],
        ];
    }
}
