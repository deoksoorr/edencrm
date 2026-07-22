<?php
/**
 * 목표(KPI) 관리 — 회사 월/분기/연간 목표 + 직원별 월 목표. super_admin(settings.manage).
 * 대시보드 매출 목표 달성률은 이 데이터(company_targets 월)를 기준으로 자동 계산한다.
 */
class TargetsController
{
    public function index(): void
    {
        $year = Util::int('year', (int) date('Y'));
        if ($year < 2000 || $year > 2100) { $year = (int) date('Y'); }

        // 회사 목표 → [period_type][period_no] = row
        $company = [];
        foreach (Db::all("SELECT * FROM company_targets WHERE year=:y", [':y' => $year]) as $r) {
            $company[$r['period_type']][(int) $r['period_no']] = $r;
        }

        View::render('targets/index', [
            'title'   => '목표 관리',
            'year'    => $year,
            'years'   => range((int) date('Y') + 1, (int) date('Y') - 3),
            'company' => $company,
            'scripts' => [],
        ]);
    }

    /** 회사 목표(월12·분기4·연간1) 일괄 저장. */
    public function save(): void
    {
        $year = Util::postInt('year', (int) date('Y'));
        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $rows[] = ['month', $m, $this->pf("m_rev_$m"), $this->pf("m_pft_$m")];
        }
        for ($q = 1; $q <= 4; $q++) {
            $rows[] = ['quarter', $q, $this->pf("q_rev_$q"), $this->pf("q_pft_$q")];
        }
        $rows[] = ['year', 0, $this->pf('y_rev'), $this->pf('y_pft')];

        foreach ($rows as [$type, $no, $rev, $pft]) {
            Db::run(
                "INSERT INTO company_targets(period_type,year,period_no,target_revenue,target_profit)
                 VALUES(:t,:y,:n,:r,:p)
                 ON DUPLICATE KEY UPDATE target_revenue=VALUES(target_revenue), target_profit=VALUES(target_profit)",
                [':t' => $type, ':y' => $year, ':n' => $no, ':r' => $rev, ':p' => $pft]
            );
        }
        Audit::log('company_target_save', 'company_targets', null, null, ['year' => $year]);
        if (Response::wantsJson()) { Response::json(['ok' => true]); }
        Response::redirect('targets.index', ['year' => $year], '회사 목표가 저장되었습니다.');
    }


    private function pf(string $key): float
    {
        return $this->num($_POST[$key] ?? 0);
    }
    private function num($v): float
    {
        return (float) str_replace([',', ' '], '', (string) $v);
    }
}
