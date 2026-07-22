<?php
/**
 * 일정 스케줄러 (T7). 월 캘린더 + 직원별 주간 타임라인.
 * 열람 범위: schedule.view_all 있으면 전체, 없으면 본인 일정만 (로그인만으로 본인 일정 열람 허용).
 * 저장/이동/삭제는 라우터가 schedule.manage 를 강제한다.
 */
class ScheduleController
{
    public function index(): void
    {
        $canManageAll = Rbac::can('schedule.view_all');
        $canManage = Rbac::can('schedule.manage');

        $users = Db::all("SELECT id, name, role_key FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY name");

        [$pw, $pp] = $this->projectScope($canManageAll);
        $projects = Db::all(
            "SELECT p.id, p.project_no, p.name FROM projects p WHERE p.deleted_at IS NULL AND $pw ORDER BY p.project_no DESC",
            $pp
        );

        View::render('schedule/index', [
            'title'        => '일정',
            'canManageAll' => $canManageAll,
            'canManage'    => $canManage,
            'users'        => $users,
            'projects'     => $projects,
            'scripts'      => ['js/scheduler.js'],
        ]);
    }

    /** {from,to,user_id?,project_id?} → schedules JSON + holidays. */
    public function data(): void
    {
        $from = Util::str('from');
        $to = Util::str('to');
        if ($from === '' || $to === '') {
            Response::error('조회 기간(from, to)이 필요합니다.', 422);
        }

        $canAll = Rbac::can('schedule.view_all');
        $reqUser = Util::int('user_id', null);
        $projectId = Util::int('project_id', null);

        $where = ['s.start_datetime < :range_to', 's.end_datetime > :range_from'];
        $params = [':range_from' => $from . ' 00:00:00', ':range_to' => $to . ' 23:59:59'];

        if (!$canAll) {
            // 본인 일정만 (로그인만으로 허용, view_all 없으면 필터 무시하고 강제)
            $where[] = 's.user_id = :self_uid';
            $params[':self_uid'] = Auth::id();
        } elseif ($reqUser) {
            $where[] = 's.user_id = :f_uid';
            $params[':f_uid'] = $reqUser;
        }
        if ($projectId) {
            $where[] = 's.project_id = :f_pid';
            $params[':f_pid'] = $projectId;
        }

        $rows = Db::all(
            "SELECT s.id, s.project_id, s.user_id, s.title, s.start_datetime, s.end_datetime,
                    s.all_day, s.type, s.color, s.status, s.memo,
                    u.name AS user_name, p.name AS project_name, p.project_no AS project_no
             FROM schedules s
             JOIN users u ON u.id = s.user_id
             LEFT JOIN projects p ON p.id = s.project_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.start_datetime",
            $params
        );

        $holidays = Db::all(
            "SELECT holiday_date, name FROM holidays WHERE holiday_date BETWEEN :hf AND :ht",
            [':hf' => $from, ':ht' => $to]
        );

        Response::json(['schedules' => $rows, 'holidays' => $holidays]);
    }

    /** 일정 생성/수정. 충돌 시 confirmed=1 없이는 경고만 하고 저장하지 않는다. */
    public function save(): void
    {
        $id = Util::postInt('id', null);
        $projectId = Util::postInt('project_id', null) ?: null;
        $userId = Util::postInt('user_id', null);
        $title = Util::postStr('title');
        $start = Util::postStr('start_datetime');
        $end = Util::postStr('end_datetime');
        $allDay = Util::postInt('all_day', 0) ? 1 : 0;
        $type = Util::postStr('type', 'work');
        $color = Util::nullIfEmpty(Util::postStr('color'));
        $status = Util::postStr('status', 'scheduled');
        $memo = Util::nullIfEmpty(Util::postStr('memo'));
        $confirmed = Util::postStr('confirmed', '') === '1';

        if (!$userId || $title === '' || $start === '' || $end === '') {
            Response::error('직원, 제목, 시작/종료 일시는 필수입니다.', 422);
        }
        if (strtotime($end) <= strtotime($start)) {
            Response::error('종료 일시는 시작 일시보다 이후여야 합니다.', 422);
        }
        if ($projectId && !Db::val("SELECT 1 FROM projects WHERE id=:id AND deleted_at IS NULL", [':id' => $projectId])) {
            Response::error('존재하지 않는 프로젝트입니다.', 422);
        }
        if (!Db::val("SELECT 1 FROM users WHERE id=:id AND deleted_at IS NULL", [':id' => $userId])) {
            Response::error('존재하지 않는 직원입니다.', 422);
        }

        $conflicts = $this->findConflicts($userId, $start, $end, $id);
        if ($conflicts && !$confirmed) {
            Response::json(['conflict' => true, 'conflicts' => $conflicts]);
        }

        $data = [
            'project_id'     => $projectId,
            'user_id'        => $userId,
            'title'          => $title,
            'start_datetime' => $start,
            'end_datetime'   => $end,
            'all_day'        => $allDay,
            'type'           => $type,
            'color'          => $color,
            'status'         => $status,
            'memo'           => $memo,
        ];

        if ($id) {
            $before = Db::one("SELECT * FROM schedules WHERE id=:id", [':id' => $id]);
            if (!$before) {
                Response::error('일정을 찾을 수 없습니다.', 404);
            }
            Db::update('schedules', $data, 'id = :id', [':id' => $id]);
            Audit::log('schedule_update', 'schedules', $id, $before, $data);
        } else {
            $id = Db::insert('schedules', $data);
            Audit::log('schedule_create', 'schedules', $id, null, $data);
        }

        Response::json(['id' => $id, 'conflict' => false]);
    }

    /** 드래그 이동: {id, start_datetime, end_datetime, user_id?}. */
    public function move(): void
    {
        $id = Util::postInt('id');
        if (!$id) {
            Response::error('id가 필요합니다.', 422);
        }
        $row = Db::one("SELECT * FROM schedules WHERE id=:id", [':id' => $id]);
        if (!$row) {
            Response::error('일정을 찾을 수 없습니다.', 404);
        }

        $start = Util::postStr('start_datetime', $row['start_datetime']);
        $end = Util::postStr('end_datetime', $row['end_datetime']);
        $userId = Util::postInt('user_id', null) ?: (int) $row['user_id'];
        $confirmed = Util::postStr('confirmed', '') === '1';

        if (strtotime($end) <= strtotime($start)) {
            Response::error('종료 일시는 시작 일시보다 이후여야 합니다.', 422);
        }
        if (!Db::val("SELECT 1 FROM users WHERE id=:id AND deleted_at IS NULL", [':id' => $userId])) {
            Response::error('존재하지 않는 직원입니다.', 422);
        }

        $conflicts = $this->findConflicts($userId, $start, $end, $id);
        if ($conflicts && !$confirmed) {
            Response::json(['conflict' => true, 'conflicts' => $conflicts]);
        }

        $data = ['start_datetime' => $start, 'end_datetime' => $end, 'user_id' => $userId];
        Db::update('schedules', $data, 'id = :id', [':id' => $id]);
        Audit::log('schedule_move', 'schedules', $id, $row, $data);

        Response::json(['id' => $id, 'conflict' => false]);
    }

    public function delete(): void
    {
        $id = Util::postInt('id');
        if (!$id) {
            Response::error('id가 필요합니다.', 422);
        }
        $row = Db::one("SELECT * FROM schedules WHERE id=:id", [':id' => $id]);
        if (!$row) {
            Response::error('일정을 찾을 수 없습니다.', 404);
        }
        Db::run("DELETE FROM schedules WHERE id = :id", [':id' => $id]);
        Audit::log('schedule_delete', 'schedules', $id, $row, null);
        Response::json(['id' => $id]);
    }

    /** 같은 직원의 시간대 겹침 일정 목록(자기 자신 제외). */
    private function findConflicts(int $userId, string $start, string $end, ?int $excludeId): array
    {
        $sql = "SELECT s.id, s.title, s.start_datetime, s.end_datetime, u.name AS user_name
                FROM schedules s JOIN users u ON u.id = s.user_id
                WHERE s.user_id = :uid AND s.start_datetime < :end AND s.end_datetime > :start";
        $params = [':uid' => $userId, ':start' => $start, ':end' => $end];
        if ($excludeId) {
            $sql .= " AND s.id != :ex";
            $params[':ex'] = $excludeId;
        }
        return Db::all($sql, $params);
    }

    /** 필터용 프로젝트 목록 범위(schedule.view_all 없으면 담당/배정 프로젝트만). */
    private function projectScope(bool $canManageAll): array
    {
        if ($canManageAll) {
            return ['1=1', []];
        }
        $uid = Auth::id();
        return [
            "(p.site_manager_id = :sp_uid1 OR p.sales_user_id = :sp_uid2
              OR EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id = p.id AND pa.user_id = :sp_uid3))",
            [':sp_uid1' => $uid, ':sp_uid2' => $uid, ':sp_uid3' => $uid],
        ];
    }
}
