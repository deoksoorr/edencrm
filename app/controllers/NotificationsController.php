<?php
/**
 * 인앱 알림 — 목록/최근목록(JSON)/읽음처리 + 누락방지 알림 자동 생성기.
 * 생성기는 notifications.index 진입 또는 dashboard.data 호출 시 실행되며,
 * 같은 type+entity 는 당일 1회만 생성되도록 notifications.link_params 를 이용해 중복을 방지한다.
 * (스케줄러 없이 요청 시 생성 방식)
 */
class NotificationsController
{
    /** 내 알림 목록. */
    public function index(): void
    {
        self::generateMissing();

        $uid    = Auth::id();
        $filter = Util::str('filter'); // '' | 'unread'
        $page   = max(1, Util::int('page', 1) ?: 1);

        $where  = 'user_id = :u';
        $params = [':u' => $uid];
        if ($filter === 'unread') {
            $where .= ' AND is_read = 0';
        }

        $total = (int) Db::val("SELECT COUNT(*) FROM notifications WHERE $where", $params);
        $per   = (int) ($GLOBALS['config']['PAGE_SIZE'] ?? 20);
        $pg    = Util::paginate($total, $page, $per);

        $rows = Db::all(
            "SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}",
            $params
        );
        $unread = (int) Db::val('SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0', [':u' => $uid]);

        View::render('notifications/index', [
            'title'  => '알림',
            'rows'   => $rows,
            'pg'     => $pg,
            'filter' => $filter,
            'unread' => $unread,
        ]);
    }

    /** 톱바 드롭다운용 최근 알림 JSON(미읽음수 포함). */
    public function listData(): void
    {
        $uid = Auth::id();
        $rows = Db::all(
            'SELECT id, type, title, message, link_route, link_params, is_read, created_at
             FROM notifications WHERE user_id=:u ORDER BY created_at DESC LIMIT 10',
            [':u' => $uid]
        );
        $unread = (int) Db::val('SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0', [':u' => $uid]);
        Response::json(['items' => $rows, 'unread' => $unread]);
    }

    /** {id} 단건 읽음 처리(본인 알림만). */
    public function read(): void
    {
        $id  = Util::postInt('id', 0) ?: 0;
        $row = Db::one('SELECT * FROM notifications WHERE id=:id AND user_id=:u', [':id' => $id, ':u' => Auth::id()]);
        if (!$row) {
            Response::error('알림을 찾을 수 없습니다.', 404);
        }
        if ((int) $row['is_read'] === 0) {
            Db::update('notifications', ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 'id=:id', [':id' => $id]);
        }
        Response::json(['id' => $id]);
    }

    /** 전체 읽음 처리. */
    public function readAll(): void
    {
        Db::run('UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=:u AND is_read=0', [':u' => Auth::id()]);
        Response::json(['ok' => true]);
    }

    // ───────────────────────── 알림 생성기 ─────────────────────────

    /** 누락방지 알림 일괄 생성(중복은 당일 1회로 제한). */
    public static function generateMissing(): void
    {
        try {
            self::genLeadContactDue();
            self::genPaymentDue();
            self::genPaymentOverdue();
            self::genProjectStartDue();
            self::genProjectEndDue();
            self::genProjectDelayed();
            if (Settings::enabled('feature_worklog')) {
                self::genWorklogMissing();
            }
        } catch (\Throwable $e) {
            // 알림 생성 실패가 페이지 렌더링을 막지 않도록 조용히 로그만 남김
            error_log('[notif-gen] ' . $e->getMessage());
        }
    }

    /**
     * 오늘 생성된 알림의 (user_id|type) → dedup 엔티티 id 집합 — generateMissing 1회당 1쿼리로 프리로드.
     * 후보 건수만큼 SELECT 를 날리던 N+1(대시보드 매 진입마다 수십~수백 쿼리)을 단일 조회로 대체한다.
     * 판정은 link_params 의 `_eid`(정수)를 정확히 비교한다 — sargable 범위 조건으로
     * idx_notifications_created_at 를 활용한다(원본 `DATE(created_at)=CURDATE()` 래핑 미사용).
     */
    private static ?array $todayEid = null;

    private static function loadTodayDedup(): void
    {
        self::$todayEid = [];
        $rows = Db::all(
            "SELECT user_id, type, link_params FROM notifications
             WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY"
        );
        foreach ($rows as $r) {
            $eid = self::extractEid($r['link_params'] ?? null);
            if ($eid === null) { continue; }
            self::$todayEid[$r['user_id'] . '|' . $r['type']][$eid] = true;
        }
    }

    /** link_params(JSON)에서 dedup 키 `_eid`(정수)를 정확히 추출. 키가 없거나 파싱 불가면 null. */
    private static function extractEid(?string $lp): ?int
    {
        if ($lp === null || $lp === '') { return null; }
        $d = json_decode($lp, true);
        if (is_array($d) && isset($d['_eid']) && is_numeric($d['_eid'])) {
            return (int) $d['_eid'];
        }
        return null;
    }

    /**
     * 같은 user+type+entity 알림이 오늘 이미 생성됐는지 판별.
     * link_params 에 항상 dedup 전용 키 `_eid`(원인이 되는 엔티티 id)를 심어두고 그 값으로 판별한다
     * — 화면 이동용 `id`(예: 계약 id)와 dedup 대상 엔티티(예: 결제 id)가 다를 수 있기 때문.
     * `_eid` 정수를 **정확히** 비교한다: 원본 `link_params LIKE '%"_eid":<id>%'` 는 순서 의존
     * 프리픽스 오탐(같은 user+type 에서 eid 5 가 같은 날 먼저 생성된 51 의 부분문자열로 억제되던 결함)이
     * 있었고, 이를 제거한다. 이번 실행 중 생성분도 집합에 반영해 원본의 "push 즉시 DB 반영" 순서 의존
     * (동일 eid 재등장 시 dedup)은 그대로 보존한다.
     */
    private static function notifiedToday(int $userId, string $type, int $entityId): bool
    {
        if (self::$todayEid === null) { self::loadTodayDedup(); }
        $key = $userId . '|' . $type;
        if (isset(self::$todayEid[$key][$entityId])) { return true; }
        self::$todayEid[$key][$entityId] = true; // 곧 push 될 항목(원본의 즉시 DB 반영과 등가)
        return false;
    }

    /** 다음연락예정일 도래(leads.next_contact_date<=오늘, 진행 중 상태만). */
    private static function genLeadContactDue(): void
    {
        $rows = Db::all(
            "SELECT l.id, l.sales_user_id, l.next_contact_date, c.name AS customer_name
             FROM leads l
             JOIN customers c ON c.id = l.customer_id
             JOIN pipeline_stages ps ON ps.id = l.stage_id
             WHERE l.deleted_at IS NULL AND l.next_contact_date IS NOT NULL AND l.next_contact_date <= CURDATE()
               AND ps.is_won = 0 AND ps.is_lost = 0"
        );
        foreach ($rows as $r) {
            $uid = (int) ($r['sales_user_id'] ?? 0);
            if (!$uid || self::notifiedToday($uid, 'lead_contact_due', (int) $r['id'])) {
                continue;
            }
            Notif::push(
                $uid, 'lead_contact_due', '다음 연락 예정일 도래',
                $r['customer_name'] . ' 고객 — 다음 연락 예정일(' . $r['next_contact_date'] . ')이 도래했습니다.',
                'pipeline.show', ['id' => (int) $r['id'], '_eid' => (int) $r['id']]
            );
        }
    }

    /** 입금예정일 도래(payments.due_date=오늘 & pending).
     *  모집단 = 미수금 KPI 와 동일(AccountingService::RECEIVABLE_STATUSES) — 작성중·파기·취소 계약 오탐 알림 방지. */
    private static function genPaymentDue(): void
    {
        $statusIn = "'" . implode("','", AccountingService::RECEIVABLE_STATUSES) . "'";
        $rows = Db::all(
            "SELECT p.id AS payment_id, p.amount, p.due_date, c.id AS contract_id, c.contract_no, c.sales_user_id
             FROM payments p
             JOIN contracts c ON c.id = p.contract_id
             WHERE p.status='pending' AND p.kind='payment' AND p.due_date = CURDATE()
               AND c.deleted_at IS NULL AND c.status IN ($statusIn)"
        );
        foreach ($rows as $r) {
            $uid = (int) ($r['sales_user_id'] ?? 0);
            if (!$uid || self::notifiedToday($uid, 'payment_due', (int) $r['payment_id'])) {
                continue;
            }
            Notif::push(
                $uid, 'payment_due', '입금 예정일 도래',
                $r['contract_no'] . ' 계약 — 오늘 입금 예정(' . money((float) $r['amount']) . '원)입니다.',
                'contracts.show', ['id' => (int) $r['contract_id'], '_eid' => (int) $r['payment_id']]
            );
        }
    }

    /** 미수금 발생(payments.due_date<오늘 & pending — 입금예정일 경과).
     *  모집단 = 미수금 KPI 와 동일(AccountingService::RECEIVABLE_STATUSES) — KPI 에 없는 미수금 알림 발사 금지. */
    private static function genPaymentOverdue(): void
    {
        $statusIn = "'" . implode("','", AccountingService::RECEIVABLE_STATUSES) . "'";
        $rows = Db::all(
            "SELECT p.id AS payment_id, p.amount, p.due_date, c.id AS contract_id, c.contract_no, c.sales_user_id
             FROM payments p
             JOIN contracts c ON c.id = p.contract_id
             WHERE p.status='pending' AND p.kind='payment' AND p.due_date < CURDATE()
               AND c.deleted_at IS NULL AND c.status IN ($statusIn)"
        );
        foreach ($rows as $r) {
            $uid = (int) ($r['sales_user_id'] ?? 0);
            if (!$uid || self::notifiedToday($uid, 'payment_overdue', (int) $r['payment_id'])) {
                continue;
            }
            Notif::push(
                $uid, 'payment_overdue', '미수금 발생',
                $r['contract_no'] . ' 계약 — 입금 예정일(' . $r['due_date'] . ')이 지난 미수금 ' . money((float) $r['amount']) . '원이 있습니다.',
                'contracts.show', ['id' => (int) $r['contract_id'], '_eid' => (int) $r['payment_id']]
            );
        }
    }

    /** 착공예정일 도래(start_date<=오늘, 미착공, 미완료). */
    private static function genProjectStartDue(): void
    {
        $rows = Db::all(
            "SELECT id, project_no, name, site_manager_id, sales_user_id
             FROM projects
             WHERE deleted_at IS NULL AND start_date IS NOT NULL AND start_date = CURDATE()
               AND actual_start_date IS NULL AND status <> 'completed'"
        );
        foreach ($rows as $r) {
            $uid = (int) ($r['site_manager_id'] ?: $r['sales_user_id'] ?: 0);
            if (!$uid || self::notifiedToday($uid, 'project_start_due', (int) $r['id'])) {
                continue;
            }
            Notif::push(
                $uid, 'project_start_due', '착공 예정일 도래',
                $r['project_no'] . ' ' . $r['name'] . ' — 오늘 착공 예정입니다.',
                'projects.show', ['id' => (int) $r['id'], '_eid' => (int) $r['id']]
            );
        }
    }

    /** 준공예정일 도래(end_date<=오늘, 미준공, 미완료 — 지연은 별도 항목). */
    private static function genProjectEndDue(): void
    {
        $rows = Db::all(
            "SELECT id, project_no, name, site_manager_id, sales_user_id
             FROM projects
             WHERE deleted_at IS NULL AND end_date IS NOT NULL AND end_date = CURDATE()
               AND actual_end_date IS NULL AND status <> 'completed'"
        );
        foreach ($rows as $r) {
            $uid = (int) ($r['site_manager_id'] ?: $r['sales_user_id'] ?: 0);
            if (!$uid || self::notifiedToday($uid, 'project_end_due', (int) $r['id'])) {
                continue;
            }
            Notif::push(
                $uid, 'project_end_due', '준공 예정일 도래',
                $r['project_no'] . ' ' . $r['name'] . ' — 오늘 준공 예정입니다.',
                'projects.show', ['id' => (int) $r['id'], '_eid' => (int) $r['id']]
            );
        }
    }

    /** 공정지연(end_date<오늘 & 미완료). */
    private static function genProjectDelayed(): void
    {
        $rows = Db::all(
            "SELECT id, project_no, name, site_manager_id, sales_user_id, end_date
             FROM projects
             WHERE deleted_at IS NULL AND end_date IS NOT NULL AND end_date < CURDATE()
               AND actual_end_date IS NULL AND status <> 'completed'"
        );
        foreach ($rows as $r) {
            $uid = (int) ($r['site_manager_id'] ?: $r['sales_user_id'] ?: 0);
            if (!$uid || self::notifiedToday($uid, 'project_delayed', (int) $r['id'])) {
                continue;
            }
            Notif::push(
                $uid, 'project_delayed', '공정 지연',
                $r['project_no'] . ' ' . $r['name'] . ' — 준공예정일(' . $r['end_date'] . ')이 지났습니다.',
                'projects.show', ['id' => (int) $r['id'], '_eid' => (int) $r['id']]
            );
        }
    }

    /** 작업일지 미작성(진행 중 프로젝트, 전일 작업일지 없음). */
    private static function genWorklogMissing(): void
    {
        $rows = Db::all(
            "SELECT p.id, p.project_no, p.name, p.site_manager_id, p.sales_user_id
             FROM projects p
             WHERE p.deleted_at IS NULL AND p.status = 'in_progress'
               AND (p.start_date IS NULL OR p.start_date <= CURDATE() - INTERVAL 1 DAY)
               AND NOT EXISTS (
                 SELECT 1 FROM work_logs wl WHERE wl.project_id = p.id AND wl.work_date = CURDATE() - INTERVAL 1 DAY
               )"
        );
        foreach ($rows as $r) {
            $uid = (int) ($r['site_manager_id'] ?: $r['sales_user_id'] ?: 0);
            if (!$uid || self::notifiedToday($uid, 'worklog_missing', (int) $r['id'])) {
                continue;
            }
            Notif::push(
                $uid, 'worklog_missing', '작업일지 미작성',
                $r['project_no'] . ' ' . $r['name'] . ' — 전일 작업일지가 작성되지 않았습니다.',
                'worklogs.index', ['id' => (int) $r['id'], '_eid' => (int) $r['id']]
            );
        }
    }
}
