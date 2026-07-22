<?php
/**
 * 대시보드 골격 (T3). 권한(사장/직원)별 기본 지표를 보여준다. T8 에서 차트로 확장.
 */
class DashboardController
{
    public function index(): void
    {
        $u = Auth::user();
        $isBoss = Rbac::isRole('super_admin', 'sales_manager', 'accountant');

        if ($isBoss) {
            $stats = $this->bossStats();
            View::render('dashboard/boss', ['title' => '대시보드', 'stats' => $stats, 'me' => $u]);
        } else {
            $stats = $this->staffStats((int) $u['id']);
            View::render('dashboard/staff', ['title' => '내 대시보드', 'stats' => $stats, 'me' => $u]);
        }
    }

    /** 대시보드 카드용 요약(간단 버전; T8 에서 확장). */
    private function bossStats(): array
    {
        $s = [];
        $s['customers'] = (int) Db::val("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL");
        $s['new_leads'] = (int) Db::val("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL");
        $s['projects_active'] = (int) Db::val("SELECT COUNT(*) FROM projects WHERE status='in_progress' AND deleted_at IS NULL");
        $s['projects_delayed'] = (int) Db::val(
            "SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL AND status IN('in_progress','preparing')
             AND end_date IS NOT NULL AND end_date < CURDATE()"
        );
        $s['revenue_month'] = (float) Db::val(
            "SELECT COALESCE(SUM(contract_amount),0) FROM contracts
             WHERE deleted_at IS NULL AND YEAR(contract_date)=YEAR(CURDATE()) AND MONTH(contract_date)=MONTH(CURDATE())"
        );
        $s['receivable'] = (float) Db::val(
            "SELECT COALESCE(SUM(c.contract_amount),0) - COALESCE((
                SELECT SUM(p.amount) FROM payments p
                JOIN contracts c2 ON c2.id=p.contract_id
                WHERE p.status='paid' AND c2.deleted_at IS NULL),0)
             FROM contracts c WHERE c.deleted_at IS NULL"
        );
        return $s;
    }

    private function staffStats(int $uid): array
    {
        $s = [];
        $s['my_projects'] = (int) Db::val(
            "SELECT COUNT(DISTINCT p.id) FROM projects p
             LEFT JOIN project_assignments pa ON pa.project_id=p.id
             WHERE p.deleted_at IS NULL AND (pa.user_id=:u OR p.site_manager_id=:u2 OR p.sales_user_id=:u3)",
            [':u' => $uid, ':u2' => $uid, ':u3' => $uid]
        );
        $s['today_schedules'] = (int) Db::val(
            "SELECT COUNT(*) FROM schedules WHERE user_id=:u AND DATE(start_datetime)=CURDATE()",
            [':u' => $uid]
        );
        $s['week_schedules'] = (int) Db::val(
            "SELECT COUNT(*) FROM schedules WHERE user_id=:u AND start_datetime >= CURDATE() AND start_datetime < CURDATE() + INTERVAL 7 DAY",
            [':u' => $uid]
        );
        return $s;
    }

    public function data(): void
    {
        // T8 에서 차트 데이터 JSON 제공
        Response::json(['todo' => 'dashboard data — T8']);
    }
}
