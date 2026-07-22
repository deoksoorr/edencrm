<?php
/**
 * 직원 성과/수익 기여도.
 * index: performance.view_all 있으면 전 직원, 없으면 본인만(Scope::canViewUserPerformance).
 * user : 특정 직원 상세 + 수익 기여도(project_assignments.contribution_pct × 프로젝트 실제순이익).
 *
 * 집계 기준(가정 — 스펙 14-1/14-2 를 스키마에 맞춰 구체화):
 * - 담당 프로젝트 = sales_user_id/site_manager_id/project_assignments 중 하나로 연결된 프로젝트(전체 기간 누적).
 * - 총계약금액 = 담당 프로젝트 전체(status 무관) contract_amount 합. 총매출/총원가/총순이익/평균순이익률은 완료(completed) 프로젝트 기준(실현 매출).
 * - 목표매출/목표순이익 및 달성률은 targets 테이블(연/월)과 해당 월 계약(contract_date 기준) 실적으로 계산.
 * - 계약전환율 = 본인이 영업담당인 리드 중 WON 비율.
 * - 작업일지작성률 = 이번달 영업일(일요일·공휴일 제외) 대비 작업일지 작성일수 비율.
 * - 수익 기여액은 프로젝트별 실제순이익(진행 중 포함, 현재까지의 actual_cost 기준) × 본인 contribution_pct 로 계산해
 *   동일 프로젝트 순이익을 여러 담당자가 중복 합산하지 않는다.
 */
class PerformanceController
{
    public function index(): void
    {
        $canAll = Rbac::can('performance.view_all');
        $year   = (int) date('Y');
        $month  = (int) date('n');

        if ($canAll) {
            $users = Db::all(
                "SELECT id, name, role_key, department_id FROM users
                 WHERE deleted_at IS NULL AND status='active' ORDER BY name"
            );
        } else {
            $me = Auth::user();
            $users = [[
                'id' => (int) $me['id'], 'name' => $me['name'], 'role_key' => $me['role'],
                'department_id' => $me['department_id'],
            ]];
        }

        $depMap = array_column(Db::all('SELECT id, name FROM departments'), 'name', 'id');

        $rows = [];
        foreach ($users as $u) {
            $perf = $this->computePerformance((int) $u['id'], $year, $month);
            $rows[] = $perf + [
                'user_id'    => (int) $u['id'],
                'name'       => $u['name'],
                'role_key'   => $u['role_key'],
                'department' => $depMap[$u['department_id']] ?? '-',
            ];
        }

        View::render('performance/index', [
            'title'  => '직원 성과',
            'rows'   => $rows,
            'canAll' => $canAll,
            'year'   => $year,
            'month'  => $month,
        ]);
    }

    public function user(): void
    {
        $id = Util::int('id', 0) ?: Auth::id();

        if (!Scope::canViewUserPerformance($id)) {
            if (Response::wantsJson()) {
                Response::error('해당 직원의 성과를 열람할 권한이 없습니다.', 403);
            }
            http_response_code(403);
            View::renderError(403, '접근 권한 없음', '해당 직원의 성과를 열람할 권한이 없습니다(본인 성과 또는 전체 열람 권한 필요).');
            return;
        }

        $staff = Db::one(
            "SELECT u.*, d.name AS department_name, r.name AS role_name
             FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.deleted_at IS NULL",
            [':id' => $id]
        );
        if (!$staff) {
            http_response_code(404);
            View::renderError(404, '직원을 찾을 수 없음', '요청한 직원 정보를 찾을 수 없습니다.');
            return;
        }

        $year  = Util::int('year', (int) date('Y')) ?: (int) date('Y');
        $month = Util::int('month', (int) date('n')) ?: (int) date('n');

        $summary = $this->computePerformance((int) $id, $year, $month);

        $assignments = Db::all(
            "SELECT pa.project_id, pa.role AS assign_role, pa.contribution_pct,
                    p.project_no, p.name, p.status, p.contract_amount, p.actual_cost
             FROM project_assignments pa
             JOIN projects p ON p.id = pa.project_id
             WHERE pa.user_id = :u AND p.deleted_at IS NULL
             ORDER BY p.created_at DESC",
            [':u' => $id]
        );

        $contributionRows = [];
        $totalContribution = 0.0;
        foreach ($assignments as $a) {
            $projectProfit  = Calc::profit((float) $a['contract_amount'], (float) $a['actual_cost']);
            $myContribution = Calc::contribution($projectProfit, (float) $a['contribution_pct']);
            $totalContribution += $myContribution;
            $contributionRows[] = [
                'project_id'       => (int) $a['project_id'],
                'project_no'       => $a['project_no'],
                'name'             => $a['name'],
                'status'           => $a['status'],
                'assign_role'      => $a['assign_role'],
                'contribution_pct' => (float) $a['contribution_pct'],
                'project_profit'   => $projectProfit,
                'my_contribution'  => $myContribution,
            ];
        }

        // 회사 전체 순이익(전체 프로젝트 누적, contributionRows 와 동일 기준 — 상태 무관 actual_cost 반영분)
        $companyProfit = (float) Db::val(
            "SELECT COALESCE(SUM(contract_amount - actual_cost), 0) FROM projects WHERE deleted_at IS NULL"
        );
        $companyContributionRate = Calc::rate($totalContribution, $companyProfit);

        View::render('performance/user', [
            'title'                   => $staff['name'] . ' 성과',
            'staff'                   => $staff,
            'summary'                 => $summary,
            'year'                    => $year,
            'month'                   => $month,
            'contributionRows'        => $contributionRows,
            'totalContribution'       => $totalContribution,
            'companyProfit'           => $companyProfit,
            'companyContributionRate' => $companyContributionRate,
            'canAll'                  => Rbac::can('performance.view_all'),
        ]);
    }

    /** 직원 1인의 14-1 지표 집계. */
    private function computePerformance(int $uid, int $year, int $month): array
    {
        $projects = Db::all(
            "SELECT DISTINCT p.id, p.status, p.contract_amount, p.actual_cost, p.end_date, p.actual_end_date, p.contract_date
             FROM projects p
             LEFT JOIN project_assignments pa ON pa.project_id = p.id AND pa.user_id = :pa_u
             WHERE p.deleted_at IS NULL AND (p.sales_user_id = :sales_u OR p.site_manager_id = :site_u OR pa.user_id = :assign_u)",
            [':pa_u' => $uid, ':sales_u' => $uid, ':site_u' => $uid, ':assign_u' => $uid]
        );

        $total = count($projects);
        $completed = 0;
        $inProgress = 0;
        $delayed = 0;
        $completedRevenue = 0.0;
        $completedCost = 0.0;
        $totalContractAmount = 0.0;
        $profitRates = [];
        $monthRevenue = 0.0;
        $monthCost = 0.0;
        $today = date('Y-m-d');

        foreach ($projects as $p) {
            $totalContractAmount += (float) $p['contract_amount'];
            if ($p['status'] === 'completed') {
                $completed++;
                $completedRevenue += (float) $p['contract_amount'];
                $completedCost += (float) $p['actual_cost'];
                $rate = Calc::profitRate((float) $p['contract_amount'], (float) $p['actual_cost']);
                if ($rate !== null) {
                    $profitRates[] = $rate;
                }
            } elseif ($p['status'] === 'in_progress') {
                $inProgress++;
            }
            if ($p['status'] !== 'completed' && $p['actual_end_date'] === null
                && $p['end_date'] !== null && $p['end_date'] < $today) {
                $delayed++;
            }
            if ($p['contract_date'] !== null
                && (int) substr((string) $p['contract_date'], 0, 4) === $year
                && (int) substr((string) $p['contract_date'], 5, 2) === $month) {
                $monthRevenue += (float) $p['contract_amount'];
                $monthCost += (float) $p['actual_cost'];
            }
        }

        $totalProfit = Calc::profit($completedRevenue, $completedCost);
        $avgProfitRate = $profitRates ? round(array_sum($profitRates) / count($profitRates), 2) : null;
        $monthProfit = Calc::profit($monthRevenue, $monthCost);

        $target = Db::one(
            'SELECT target_revenue, target_profit FROM targets WHERE user_id=:u AND year=:y AND month=:m',
            [':u' => $uid, ':y' => $year, ':m' => $month]
        );
        $targetRevenue = $target ? (float) $target['target_revenue'] : 0.0;
        $targetProfit  = $target ? (float) $target['target_profit'] : 0.0;

        $leadTotal = (int) Db::val('SELECT COUNT(*) FROM leads WHERE sales_user_id=:u AND deleted_at IS NULL', [':u' => $uid]);
        $leadWon = (int) Db::val(
            "SELECT COUNT(*) FROM leads l JOIN pipeline_stages ps ON ps.id = l.stage_id
             WHERE l.sales_user_id = :u AND l.deleted_at IS NULL AND ps.is_won = 1",
            [':u' => $uid]
        );

        return [
            'total_projects'       => $total,
            'completed_projects'   => $completed,
            'in_progress_projects' => $inProgress,
            'delayed_projects'     => $delayed,
            'total_contract_amount'=> $totalContractAmount,
            'total_revenue'        => $completedRevenue,
            'total_cost'           => $completedCost,
            'total_profit'         => $totalProfit,
            'avg_profit_rate'      => $avgProfitRate,
            'target_revenue'       => $targetRevenue,
            'month_revenue'        => $monthRevenue,
            'revenue_achieve_rate' => Calc::achievement($monthRevenue, $targetRevenue),
            'target_profit'        => $targetProfit,
            'month_profit'         => $monthProfit,
            'profit_achieve_rate'  => Calc::achievement($monthProfit, $targetProfit),
            'conversion_rate'      => Calc::rate($leadWon, $leadTotal),
            'worklog_rate'         => $this->worklogRate($uid, $year, $month),
        ];
    }

    /** 작업일지 작성률 = 이번달(오늘까지) 작성일수 ÷ 영업일수(일요일·공휴일 제외). */
    private function worklogRate(int $uid, int $year, int $month): ?float
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $cap        = min(date('Y-m-d'), $monthEnd);
        if ($cap < $monthStart) {
            return null;
        }

        $holidaySet = array_flip(Db::run(
            'SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN :s AND :e',
            [':s' => $monthStart, ':e' => $cap]
        )->fetchAll(PDO::FETCH_COLUMN));

        $expected = 0;
        $cursor = strtotime($monthStart);
        $endTs  = strtotime($cap);
        while ($cursor <= $endTs) {
            $d = date('Y-m-d', $cursor);
            if ((int) date('N', $cursor) !== 7 && !isset($holidaySet[$d])) {
                $expected++;
            }
            $cursor += 86400;
        }

        $logged = (int) Db::val(
            'SELECT COUNT(DISTINCT work_date) FROM work_logs WHERE user_id=:u AND work_date BETWEEN :s AND :e',
            [':u' => $uid, ':s' => $monthStart, ':e' => $cap]
        );

        return Calc::rate($logged, $expected);
    }
}
