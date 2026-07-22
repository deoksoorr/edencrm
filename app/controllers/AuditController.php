<?php
/**
 * 감사 로그 열람. perm audit.view 는 라우터가 강제.
 */
class AuditController
{
    public function index(): void
    {
        $userId = (int) Util::int('user_id', 0);
        $action = Util::str('action', '');
        $from   = Util::str('from', '');
        $to     = Util::str('to', '');
        $page   = max(1, (int) Util::int('page', 1));

        $where = ['1=1'];
        $params = [];
        if ($userId > 0) {
            $where[] = 'a.user_id = :uid';
            $params[':uid'] = $userId;
        }
        if ($action !== '') {
            $where[] = 'a.action = :action';
            $params[':action'] = $action;
        }
        if ($from !== '') {
            $where[] = 'a.created_at >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where[] = 'a.created_at <= :to';
            $params[':to'] = $to . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::val("SELECT COUNT(*) FROM audit_logs a WHERE $whereSql", $params);
        $per = (int) setting('page_size', 20);
        $pg  = Util::paginate($total, $page, $per);

        $rows = Db::all(
            "SELECT a.*, u.name AS user_name, u.login_id AS user_login_id
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE $whereSql
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT " . (int) $pg['per'] . ' OFFSET ' . (int) $pg['offset'],
            $params
        );

        $users = Db::all("SELECT id, name, login_id FROM users ORDER BY name");
        $actions = Db::run("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

        View::render('audit/index', [
            'title'   => '감사 로그',
            'rows'    => $rows,
            'pg'      => $pg,
            'userId'  => $userId,
            'action'  => $action,
            'from'    => $from,
            'to'      => $to,
            'users'   => $users,
            'actions' => $actions,
        ]);
    }
}
