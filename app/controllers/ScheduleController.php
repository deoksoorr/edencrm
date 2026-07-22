<?php
/**
 * 일정 스케줄러 — 다중 참여자 + 시간대 슬롯(오전/오후/야간). 시각 입력 없음(날짜+슬롯).
 * 색상은 참여 직원의 개인색(users.color)에서 산출(일정 자체 색 입력 없음).
 * 열람 범위: schedule.view_all 있으면 전체, 없으면 '내가 참여자인 일정'만.
 * 저장/이동/삭제는 라우터가 schedule.manage 를 강제한다.
 */
class ScheduleController
{
    public function index(): void
    {
        $canManageAll = Rbac::can('schedule.view_all');
        $canManage = Rbac::can('schedule.manage');

        $users = Db::all("SELECT id, name, role_key, color FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY name");

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
            'slots'        => Stages::scheduleSlots(),
            'scripts'      => ['js/scheduler.js'],
        ]);
    }

    /** {from,to,user_id?,project_id?} → schedules(+participants) JSON + holidays. */
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

        $where = ['s.event_date BETWEEN :range_from AND :range_to'];
        $params = [':range_from' => $from, ':range_to' => $to];

        if (!$canAll) {
            $where[] = 'EXISTS(SELECT 1 FROM schedule_participants sp WHERE sp.schedule_id=s.id AND sp.user_id=:self_uid)';
            $params[':self_uid'] = Auth::id();
        } elseif ($reqUser) {
            $where[] = 'EXISTS(SELECT 1 FROM schedule_participants sp WHERE sp.schedule_id=s.id AND sp.user_id=:f_uid)';
            $params[':f_uid'] = $reqUser;
        }
        if ($projectId) {
            $where[] = 's.project_id = :f_pid';
            $params[':f_pid'] = $projectId;
        }

        $rows = Db::all(
            "SELECT s.id, s.project_id, s.title, s.event_date, s.slot, s.type, s.status, s.memo,
                    p.name AS project_name, p.project_no AS project_no
             FROM schedules s
             LEFT JOIN projects p ON p.id = s.project_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.event_date, FIELD(s.slot,'am','pm','night'), s.id",
            $params
        );

        // 참여자(직원+개인색) 일괄 로드
        $ids = array_column($rows, 'id');
        $partsBy = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all(
                "SELECT sp.schedule_id, u.id AS user_id, u.name, u.color
                 FROM schedule_participants sp JOIN users u ON u.id = sp.user_id
                 WHERE sp.schedule_id IN ($in) ORDER BY u.name", $ids
            ) as $p) {
                $p['color'] = $p['color'] ?: Stages::defaultColorFor((int) $p['user_id']);
                $partsBy[(int) $p['schedule_id']][] = $p;
            }
        }
        foreach ($rows as &$r) {
            $r['participants'] = $partsBy[(int) $r['id']] ?? [];
            $r['slot_label'] = Stages::slotLabel($r['slot']);
        }
        unset($r);

        $holidays = Db::all(
            "SELECT holiday_date, name FROM holidays WHERE holiday_date BETWEEN :hf AND :ht",
            [':hf' => $from, ':ht' => $to]
        );

        Response::json(['schedules' => $rows, 'holidays' => $holidays, 'slots' => Stages::scheduleSlots()]);
    }

    /** 일정 생성/수정. 참여자 충돌(같은 날짜·슬롯 중복) 시 confirmed 없이는 경고만. */
    public function save(): void
    {
        $id = Util::postInt('id', null) ?: null;
        $projectId = Util::postInt('project_id', null) ?: null;
        $title = Util::postStr('title');
        $eventDate = Util::dateOrNull(Util::postStr('event_date'));
        $slot = Util::postStr('slot');
        $type = Util::postStr('type', 'work');
        $status = Util::postStr('status', 'scheduled');
        $memo = Util::nullIfEmpty(Util::postStr('memo'));
        $confirmed = Util::postStr('confirmed', '') === '1';
        $participants = $this->participantIds();

        if ($title === '' || $eventDate === null || !Stages::isValidSlot($slot)) {
            Response::error('제목, 날짜, 시간대(오전/오후/야간)는 필수입니다.', 422);
        }
        if (!$participants) {
            Response::error('참여 직원을 한 명 이상 선택하세요.', 422);
        }
        $participants = $this->validateUsers($participants);
        if ($projectId && !Db::val("SELECT 1 FROM projects WHERE id=:id AND deleted_at IS NULL", [':id' => $projectId])) {
            Response::error('존재하지 않는 프로젝트입니다.', 422);
        }

        $conflicts = $this->findConflicts($participants, $eventDate, $slot, $id);
        if ($conflicts && !$confirmed) {
            Response::json(['conflict' => true, 'conflicts' => $conflicts]);
        }

        [$startT, $endT] = Stages::slotTimes($slot);
        $data = [
            'project_id'     => $projectId,
            'user_id'        => $participants[0], // 대표 참여자
            'title'          => $title,
            'event_date'     => $eventDate,
            'slot'           => $slot,
            'start_datetime' => $eventDate . ' ' . $startT,  // 호환 산출
            'end_datetime'   => $eventDate . ' ' . $endT,
            'all_day'        => 0,
            'type'           => $type,
            'status'         => $status,
            'memo'           => $memo,
        ];

        Db::transaction(function () use (&$id, $data, $participants) {
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
            $this->syncParticipants($id, $participants);
        });

        Response::json(['id' => $id, 'conflict' => false]);
    }

    /** 드래그 이동: {id, event_date, slot}. 참여자는 유지. */
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

        $eventDate = Util::dateOrNull(Util::postStr('event_date', (string) $row['event_date']));
        $slot = Util::postStr('slot', (string) $row['slot']);
        $confirmed = Util::postStr('confirmed', '') === '1';
        if ($eventDate === null || !Stages::isValidSlot($slot)) {
            Response::error('날짜/시간대 형식이 올바르지 않습니다.', 422);
        }

        $participants = array_map('intval', array_column(
            Db::all("SELECT user_id FROM schedule_participants WHERE schedule_id=:id", [':id' => $id]), 'user_id'
        ));
        if (!$participants) {
            $participants = [(int) $row['user_id']];
        }

        $conflicts = $this->findConflicts($participants, $eventDate, $slot, $id);
        if ($conflicts && !$confirmed) {
            Response::json(['conflict' => true, 'conflicts' => $conflicts]);
        }

        [$startT, $endT] = Stages::slotTimes($slot);
        $data = [
            'event_date'     => $eventDate,
            'slot'           => $slot,
            'start_datetime' => $eventDate . ' ' . $startT,
            'end_datetime'   => $eventDate . ' ' . $endT,
        ];
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
        Db::run("DELETE FROM schedules WHERE id = :id", [':id' => $id]); // participants CASCADE
        Audit::log('schedule_delete', 'schedules', $id, $row, null);
        Response::json(['id' => $id]);
    }

    // ───────────────────── 헬퍼 ─────────────────────

    /** POST participant_ids[] (배열 또는 콤마 문자열) → 정수 배열(중복 제거). */
    private function participantIds(): array
    {
        $raw = $_POST['participant_ids'] ?? '';
        if (is_array($raw)) {
            $ids = $raw;
        } else {
            $ids = $raw === '' ? [] : explode(',', (string) $raw);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        return $ids;
    }

    /** 존재·활성 직원만 남김. 없으면 422. */
    private function validateUsers(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $valid = array_map('intval', array_column(
            Db::all("SELECT id FROM users WHERE id IN ($in) AND status='active' AND deleted_at IS NULL", $ids), 'id'
        ));
        if (!$valid) {
            Response::error('유효한 참여 직원이 없습니다.', 422);
        }
        // 원래 순서 유지(대표 참여자 = 첫 선택)
        return array_values(array_filter($ids, fn($v) => in_array($v, $valid, true)));
    }

    private function syncParticipants(int $scheduleId, array $userIds): void
    {
        Db::run("DELETE FROM schedule_participants WHERE schedule_id=:id", [':id' => $scheduleId]);
        foreach ($userIds as $uid) {
            Db::insert('schedule_participants', ['schedule_id' => $scheduleId, 'user_id' => $uid]);
        }
    }

    /** 참여자 중 같은 날짜·슬롯에 이미 잡힌 일정(자기 제외). [{user_name, title}] */
    private function findConflicts(array $userIds, string $eventDate, string $slot, ?int $excludeId): array
    {
        if (!$userIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $sql = "SELECT DISTINCT u.name AS user_name, s.title
                FROM schedules s
                JOIN schedule_participants sp ON sp.schedule_id = s.id
                JOIN users u ON u.id = sp.user_id
                WHERE s.event_date = ? AND s.slot = ? AND sp.user_id IN ($in)";
        $params = array_merge([$eventDate, $slot], $userIds);
        if ($excludeId) {
            $sql .= " AND s.id != ?";
            $params[] = $excludeId;
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
