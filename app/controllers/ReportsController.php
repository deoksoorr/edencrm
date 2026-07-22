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
            'monthly_trend'       => $this->monthlyTrend($to, $projWhere, $projParams),
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

    /** 월별 매출·순이익·순이익률(최근 6개월, 마감월=선택 기간의 종료월). projects.contract_date 기준. */
    private function monthlyTrend(string $to, string $projWhere, array $projParams): array
    {
        $endTs = strtotime(date('Y-m-01', strtotime($to)));
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime("-$i month", $endTs));
        }
        $rangeStart = $months[0] . '-01';
        $rangeEnd = date('Y-m-t', strtotime(end($months) . '-01'));

        $rows = Db::all(
            "SELECT DATE_FORMAT(p.contract_date, '%Y-%m') AS ym,
                    COALESCE(SUM(p.contract_amount), 0) AS revenue,
                    COALESCE(SUM(p.actual_cost), 0) AS cost
             FROM projects p
             WHERE $projWhere AND p.deleted_at IS NULL
               AND p.contract_date BETWEEN :rs AND :re
             GROUP BY ym",
            $projParams + [':rs' => $rangeStart, ':re' => $rangeEnd]
        );
        $byYm = array_column($rows, null, 'ym');

        $out = [];
        foreach ($months as $ym) {
            $revenue = (float) ($byYm[$ym]['revenue'] ?? 0);
            $cost = (float) ($byYm[$ym]['cost'] ?? 0);
            $out[] = [
                'ym'          => $ym,
                'revenue'     => $revenue,
                'profit'      => Calc::profit($revenue, $cost),
                'profit_rate' => Calc::profitRate($revenue, $cost),
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

    /** 프로젝트별 손익(기간 내 계약일). */
    private function projectPl(string $from, string $to, string $projWhere, array $projParams): array
    {
        $rows = Db::all(
            "SELECT p.id, p.project_no, p.name, p.contract_amount, p.actual_cost, p.status
             FROM projects p
             WHERE $projWhere AND p.deleted_at IS NULL AND p.contract_date BETWEEN :f AND :t
             ORDER BY p.contract_date DESC",
            $projParams + [':f' => $from, ':t' => $to]
        );
        $out = [];
        foreach ($rows as $r) {
            $revenue = (float) $r['contract_amount'];
            $cost = (float) $r['actual_cost'];
            $out[] = [
                'project_no' => $r['project_no'], 'name' => $r['name'], 'status' => $r['status'],
                'revenue' => $revenue, 'cost' => $cost,
                'profit' => Calc::profit($revenue, $cost), 'profit_rate' => Calc::profitRate($revenue, $cost),
            ];
        }
        return $out;
    }

    /** 공사유형별 매출·평균수익률(기간 내 계약일, 집계 기준 수익률). */
    private function byWorkType(string $from, string $to, string $projWhere, array $projParams): array
    {
        $rows = Db::all(
            "SELECT COALESCE(NULLIF(p.work_type,''),'미상') AS work_type,
                    COUNT(*) AS cnt, COALESCE(SUM(p.contract_amount),0) AS revenue, COALESCE(SUM(p.actual_cost),0) AS cost
             FROM projects p
             WHERE $projWhere AND p.deleted_at IS NULL AND p.contract_date BETWEEN :f AND :t
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

    /** 직원별 성과 요약(기간 내 계약일 기준 매출/원가/순이익). */
    private function staffPerformance(string $from, string $to, string $projWhere, array $projParams): array
    {
        $rows = Db::all(
            "SELECT u.id AS user_id, u.name,
                    COUNT(DISTINCT p.id) AS cnt,
                    COALESCE(SUM(p.contract_amount),0) AS revenue, COALESCE(SUM(p.actual_cost),0) AS cost
             FROM users u
             JOIN projects p ON p.sales_user_id = u.id AND $projWhere AND p.deleted_at IS NULL AND p.contract_date BETWEEN :f AND :t
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
        return Db::all(
            "SELECT p.project_no, p.name, p.end_date, p.status, DATEDIFF(CURDATE(), p.end_date) AS days_over,
                    u.name AS site_manager
             FROM projects p
             LEFT JOIN users u ON u.id = p.site_manager_id
             WHERE $projWhere AND p.deleted_at IS NULL AND p.status <> 'completed'
               AND p.end_date IS NOT NULL AND p.end_date < CURDATE() AND p.actual_end_date IS NULL
             ORDER BY p.end_date ASC",
            $projParams
        );
    }

    /** 미수금 현황(현재 스냅샷, 기간 미적용). */
    private function receivables(): array
    {
        $rows = Db::all(
            "SELECT c.id, c.contract_no, cu.name AS customer_name, c.contract_amount,
                    COALESCE((SELECT SUM(pm.amount) FROM payments pm WHERE pm.contract_id = c.id AND pm.status='paid'),0) AS paid
             FROM contracts c
             JOIN customers cu ON cu.id = c.customer_id
             WHERE c.deleted_at IS NULL"
        );
        $out = [];
        $total = 0.0;
        foreach ($rows as $r) {
            $receivable = (float) $r['contract_amount'] - (float) $r['paid'];
            if ($receivable > 0) {
                $total += $receivable;
                $out[] = [
                    'contract_no' => $r['contract_no'], 'customer_name' => $r['customer_name'],
                    'contract_amount' => (float) $r['contract_amount'], 'paid' => (float) $r['paid'],
                    'receivable' => $receivable,
                ];
            }
        }
        usort($out, fn($a, $b) => $b['receivable'] <=> $a['receivable']);
        return ['total' => $total, 'list' => $out];
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

    /** 목표대비 달성률(기간에 포함된 연/월의 targets 합계 vs projects 실적 합계). */
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
                'SELECT COALESCE(SUM(target_revenue),0) AS tr, COALESCE(SUM(target_profit),0) AS tp
                 FROM targets WHERE year=:y AND month=:m',
                [':y' => $mm['y'], ':m' => $mm['m']]
            );
            $targetRevenue += (float) ($t['tr'] ?? 0);
            $targetProfit += (float) ($t['tp'] ?? 0);
        }

        $actual = Db::one(
            "SELECT COALESCE(SUM(contract_amount),0) AS rev, COALESCE(SUM(actual_cost),0) AS cost
             FROM projects WHERE deleted_at IS NULL AND contract_date BETWEEN :f AND :t",
            [':f' => $from, ':t' => $to]
        );
        $actualRevenue = (float) $actual['rev'];
        $actualProfit = Calc::profit($actualRevenue, (float) $actual['cost']);

        return [
            'target_revenue' => $targetRevenue, 'actual_revenue' => $actualRevenue,
            'revenue_rate'   => Calc::achievement($actualRevenue, $targetRevenue),
            'target_profit'  => $targetProfit, 'actual_profit' => $actualProfit,
            'profit_rate'    => Calc::achievement($actualProfit, $targetProfit),
        ];
    }

    // ───────────────────────── CSV 변환 ─────────────────────────

    private function toCsvRows(string $type, array $report): array
    {
        switch ($type) {
            case 'monthly_trend':
                $headers = ['년월', '매출', '순이익', '순이익률(%)'];
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
                $headers = ['프로젝트번호', '이름', '상태', '매출', '원가', '순이익', '순이익률(%)'];
                $rows = array_map(fn($r) => [$r['project_no'], $r['name'], $r['status'], $r['revenue'], $r['cost'], $r['profit'], $r['profit_rate'] ?? ''], $report['project_pl']);
                return [$headers, $rows];
            case 'by_work_type':
                $headers = ['공사유형', '건수', '매출', '원가', '순이익', '평균수익률(%)'];
                $rows = array_map(fn($r) => [$r['work_type'], $r['cnt'], $r['revenue'], $r['cost'], $r['profit'], $r['avg_rate'] ?? ''], $report['by_work_type']);
                return [$headers, $rows];
            case 'staff_performance':
                $headers = ['직원', '프로젝트수', '매출', '원가', '순이익'];
                $rows = array_map(fn($r) => [$r['name'], $r['cnt'], $r['revenue'], $r['cost'], $r['profit']], $report['staff_performance']);
                return [$headers, $rows];
            case 'delayed_projects':
                $headers = ['프로젝트번호', '이름', '준공예정일', '지연일수', '현장책임자'];
                $rows = array_map(fn($r) => [$r['project_no'], $r['name'], $r['end_date'], $r['days_over'], $r['site_manager'] ?? ''], $report['delayed_projects']);
                return [$headers, $rows];
            case 'receivables':
                $headers = ['계약번호', '고객명', '계약금액', '입금액', '미수금'];
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
                    ['매출', $ta['target_revenue'], $ta['actual_revenue'], $ta['revenue_rate'] ?? ''],
                    ['순이익', $ta['target_profit'], $ta['actual_profit'], $ta['profit_rate'] ?? ''],
                ]];
            default:
                return [['항목'], []];
        }
    }
}
