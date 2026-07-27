<?php
/**
 * 직원 성과/수익 기여도.
 * index: performance.view_all 있으면 전 직원, 없으면 본인만(Scope::canViewUserPerformance).
 * user : 특정 직원 상세 + 수익 기여도(project_assignments.contribution_pct × 프로젝트 실제순이익).
 *
 * 집계 기준(가정 — 스펙 14-1/14-2 를 스키마에 맞춰 구체화):
 * - 담당 프로젝트 = sales_user_id/site_manager_id/project_assignments 중 하나로 연결된 프로젝트(전체 기간 누적).
 * - 총계약금액 = 담당 프로젝트 전체(status 무관) contract_amount 합.
 * - 총매출/총순이익/평균순이익률은 대시보드 staffPerformance 와 동일하게 AccountingService 를 통해
 *   완료(completed)·공급가(supply_amount) 기준, contribution_pct 로 귀속시켜 산출한다(가중 평균 = Σ순이익÷Σ매출).
 * - 목표매출/목표순이익 및 달성률은 targets 테이블(연/월)과 해당 월 수주(contract_date 기준, sales_user 담당) 공급가로 계산.
 * - 계약전환율 = 본인이 영업담당인 리드 중 WON 비율.
 * - 작업일지작성률 = 이번달 영업일(일요일·공휴일 제외) 대비 작업일지 작성일수 비율.
 * - 수익 기여액(user() 의 프로젝트별 기여 표)은 완료 프로젝트만 확정(confirmed)으로 합산하고,
 *   진행 중 프로젝트는 참고용 예상(my_expected)으로만 표시해 대시보드 확정 합계(employeeConfirmedContribution)와 총계가 일치한다.
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

        // 전 직원 순회 시 사용자당 4쿼리(N+1)를 제거하기 위한 배치 프리로드.
        // 배치 메서드는 단건 메서드(employeeConfirmedRevenue/Contribution·contractedAmount)와
        // 동일 산식(모집단·SUM 식 동일, GROUP BY user)이라 값이 1원까지 일치한다(대시보드 staffPerformance 와 동일 패턴).
        $mFrom = sprintf('%04d-%02d-01', $year, $month);
        $mTo   = date('Y-m-t', strtotime($mFrom));
        $bulk = [
            'confirmed'       => AccountingService::employeeConfirmedByUser(),
            'confirmedMonth'  => AccountingService::employeeConfirmedByUser($mFrom, $mTo),
            'contractedMonth' => AccountingService::contractedAmountByUser($mFrom, $mTo),
        ];

        $rows = [];
        foreach ($users as $u) {
            $perf = $this->computePerformance((int) $u['id'], $year, $month, $bulk);
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
                    p.id, p.project_no, p.name, p.status, p.contract_id, p.is_exception,
                    p.contract_amount, p.actual_cost, p.supply_amount, p.actual_end_date
             FROM project_assignments pa
             JOIN projects p ON p.id = pa.project_id
             WHERE pa.user_id = :u AND p.deleted_at IS NULL
             ORDER BY p.created_at DESC",
            [':u' => $id]
        );

        $contributionRows = [];
        $totalContribution = 0.0;
        foreach ($assignments as $a) {
            // R12: 실현 손익 = 프로젝트 확정 매출(공급가액·VAT 제외) − 지출 총액 — 완료 여부 무관
            $confRev     = AccountingService::projectConfirmedRevenue($a);
            $profit      = (int) Calc::profit((float) $confRev, (float) $a['actual_cost']);
            $confirmed   = (in_array($a['status'], ['completed', 'settled'], true) && $a['actual_end_date'] !== null);
            $excluded    = in_array($a['status'], ['cancelled', 'terminated'], true); // 취소·파기 — 예상 기여 제외(실현분은 반영)
            $myConfirmed = AccountingService::contribution($profit, (float) $a['contribution_pct']);
            // 예상 기여(참고) = 미완료 프로젝트의 계획 손익(공급가 − 지출) × 기여도 — 실현 기여와 별도 축
            $myExpected  = ($confirmed || $excluded) ? 0
                : AccountingService::contribution(AccountingService::projectActualProfit($a), (float) $a['contribution_pct']);
            $totalContribution += $myConfirmed;
            $contributionRows[] = [
                'project_id'       => (int) $a['project_id'],
                'project_no'       => $a['project_no'],
                'name'             => $a['name'],
                'status'           => $a['status'],
                'assign_role'      => $a['assign_role'],
                'contribution_pct' => (float) $a['contribution_pct'],
                'confirmed'        => $confirmed,
                'excluded'         => $excluded, // 취소·파기 — 확정·예상 기여 모두 제외(브리프 §2)
                'project_profit'   => $profit,
                'my_contribution'  => $myConfirmed,
                'my_expected'      => $myExpected,
            ];
        }

        // 회사 전체 확정순이익(완료·공급가 기준) — 대시보드 staffPerformance 와 동일 서비스.
        $companyProfit = AccountingService::companyConfirmedProfit();
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

    /**
     * 직원 1인의 14-1 지표 집계.
     * 총매출/총순이익/평균순이익률은 대시보드 staffPerformance 와 동일하게
     * AccountingService(공급가·완료·귀속) 기준으로 산출한다(가중 순이익률).
     */
    private function computePerformance(int $uid, int $year, int $month, ?array $bulk = null): array
    {
        $projects = Db::all(
            "SELECT DISTINCT p.id, p.status, p.contract_amount, p.end_date, p.actual_end_date,
                    (SELECT COALESCE(SUM(pa2.contribution_pct),0) FROM project_assignments pa2
                      WHERE pa2.project_id = p.id AND pa2.user_id = :pct_u) AS my_pct
             FROM projects p
             LEFT JOIN project_assignments pa ON pa.project_id = p.id AND pa.user_id = :pa_u
             WHERE p.deleted_at IS NULL AND (p.sales_user_id = :sales_u OR p.site_manager_id = :site_u OR pa.user_id = :assign_u)",
            [':pct_u' => $uid, ':pa_u' => $uid, ':sales_u' => $uid, ':site_u' => $uid, ':assign_u' => $uid]
        );

        $total = count($projects);
        $completed = 0;
        $inProgress = 0;
        $delayed = 0;
        $totalContractAmount = 0.0;
        $today = date('Y-m-d');

        foreach ($projects as $p) {
            // 취소·파기 프로젝트는 담당 수·총계약금액(업무량·성과)에서 제외(브리프 §2)
            if (in_array($p['status'], ['cancelled', 'terminated'], true)) {
                $total--;
                continue;
            }
            // T9: 전체금액 100% 중복 귀속 금지 — 기여율 가중(기여율 없으면 미반영)
            $totalContractAmount += (float) $p['contract_amount'] * (float) $p['my_pct'] / 100;
            if (in_array($p['status'], ['completed', 'settled'], true)) {
                $completed++;
            } elseif ($p['status'] === 'in_progress') {
                $inProgress++;
            }
            if (!in_array($p['status'], ['completed', 'settled', 'cancelled', 'terminated'], true)
                && $p['actual_end_date'] === null
                && $p['end_date'] !== null && $p['end_date'] < $today) {
                $delayed++;
            }
        }

        // 이번달 범위(수주·당월 기여 스코프)
        $mFrom = sprintf('%04d-%02d-01', $year, $month);
        $mTo   = date('Y-m-t', strtotime($mFrom));

        // 귀속 확정매출/확정순이익(완료·공급가 기준) + 당월 수주·기여 — 대시보드 staffPerformance 와 동일 서비스.
        // index() 처럼 전 직원을 순회할 때는 배치 프리로드(bulk)를 넘겨 사용자당 4쿼리(N+1)를 제거한다.
        // 배치 메서드는 단건 메서드와 동일 산식(모집단·SUM 식 동일, GROUP BY user)이라 값이 1원까지 일치한다.
        if ($bulk !== null) {
            $attrRev      = (int) ($bulk['confirmed'][$uid]['revenue'] ?? 0);
            $contrib      = (int) ($bulk['confirmed'][$uid]['contrib'] ?? 0);
            $monthProfit  = (int) ($bulk['confirmedMonth'][$uid]['contrib'] ?? 0);
            $monthRevenue = (int) ($bulk['contractedMonth'][$uid] ?? 0);
        } else {
            $attrRev      = AccountingService::employeeConfirmedRevenue($uid);
            $contrib      = AccountingService::employeeConfirmedContribution($uid);
            $monthProfit  = AccountingService::employeeConfirmedContribution($uid, $mFrom, $mTo);
            $monthRevenue = AccountingService::contractedAmount($mFrom, $mTo, $uid);
        }
        $totalCost = $attrRev - $contrib;
        $avgProfitRate = Calc::rate($contrib, $attrRev);

        // R9: 목표 원장(goals 월간·개인) 우선, 레거시 targets 폴백 — GoalService 브리지
        $target = GoalService::personalMonthTarget($uid, $year, $month);
        $targetRevenue = (float) ($target['revenue'] ?? 0.0);
        $targetProfit  = (float) ($target['profit'] ?? 0.0);

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
            'total_revenue'        => $attrRev,
            'total_cost'           => $totalCost,
            'total_profit'         => $contrib,
            'avg_profit_rate'      => $avgProfitRate,
            'target_revenue'       => $targetRevenue,
            'month_revenue'        => $monthRevenue,
            'revenue_achieve_rate' => AccountingService::achievement((float) $monthRevenue, $targetRevenue),
            'target_profit'        => $targetProfit,
            'month_profit'         => $monthProfit,
            'profit_achieve_rate'  => AccountingService::achievement((float) $monthProfit, $targetProfit),
            'conversion_rate'      => Calc::rate($leadWon, $leadTotal),
            'worklog_rate'         => Settings::enabled('feature_worklog') ? $this->worklogRate($uid, $year, $month) : null,
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
