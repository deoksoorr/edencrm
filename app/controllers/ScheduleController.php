<?php
/**
 * 일정 스케줄러 — 다중 참여자 + 시간대 슬롯(오전/오후/야간) **복수 선택**. 시각 입력 없음(날짜+슬롯).
 * 슬롯 원본은 schedule_time_slots(morning/afternoon/night, R3), schedules.slot 은 하위호환 미러
 * (첫=가장 이른 슬롯의 legacy 키 am/pm/night — DashboardController 등 구 소비처 호환용).
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
        // 취소·파기 프로젝트는 새 일정 등록 대상에서 제외(기존 일정 데이터는 보존)
        $projects = Db::all(
            "SELECT p.id, p.project_no, p.name, p.is_exception FROM projects p
             WHERE p.deleted_at IS NULL AND p.status NOT IN ('cancelled','terminated') AND $pw
             ORDER BY p.project_no DESC",
            $pp
        );

        View::render('schedule/index', [
            'title'        => '일정',
            'canManageAll' => $canManageAll,
            'canManage'    => $canManage,
            'users'        => $users,
            'projects'     => $projects,
            'slots'        => Stages::scheduleSlots(),
            'types'        => Stages::scheduleTypes(), // 유형 목록 단일 출처(R6: vacation 제외)
            'scripts'      => ['js/scheduler.js'],
        ]);
    }

    /** {from,to,user_id?,project_id?,slot?} → schedules(+participants+slots) JSON + holidays.
     *  slot 필터(morning/afternoon/night): 복수 시간대 일정은 해당되는 모든 필터 결과에 노출. */
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
        $slotFilter = Stages::normalizeSlot(Util::str('slot')); // 유효하지 않으면 무시(null)

        // R6: 기존 휴가(vacation) 일정은 DB 보존하되 화면(캘린더·타임라인·인라인 목록) 미표시
        // T5 기간 일정: [event_date, end_date] 구간이 조회 범위와 겹치면 노출
        $where = [
            's.event_date <= :range_to',
            'COALESCE(s.end_date, s.event_date) >= :range_from',
            "s.type <> 'vacation'",
        ];
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
        if ($slotFilter !== null) {
            $where[] = 'EXISTS(SELECT 1 FROM schedule_time_slots stf WHERE stf.schedule_id=s.id AND stf.slot=:f_slot)';
            $params[':f_slot'] = $slotFilter;
        }

        $rows = Db::all(
            "SELECT s.id, s.project_id, s.title, s.event_date,
                    COALESCE(s.end_date, s.event_date) AS end_date, s.all_day, s.slot, s.type, s.status, s.memo,
                    p.name AS project_name, p.project_no AS project_no, p.is_exception
             FROM schedules s
             LEFT JOIN projects p ON p.id = s.project_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.event_date, FIELD(s.slot,'am','pm','night'), s.id",
            $params
        );

        // 참여자(직원+개인색) + 슬롯(관계 테이블) 일괄 로드
        $ids = array_column($rows, 'id');
        $partsBy = [];
        $slotsBy = [];
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
            foreach (Db::all(
                "SELECT schedule_id, slot FROM schedule_time_slots
                 WHERE schedule_id IN ($in) ORDER BY FIELD(slot,'morning','afternoon','night')", $ids
            ) as $st) {
                $slotsBy[(int) $st['schedule_id']][] = $st['slot'];
            }
        }
        foreach ($rows as &$r) {
            $r['participants'] = $partsBy[(int) $r['id']] ?? [];
            // 관계 테이블 기준(없으면 legacy 미러에서 유추 — 마이그레이션 전 데이터 방어)
            $slots = $slotsBy[(int) $r['id']]
                ?? (($n = Stages::normalizeSlot($r['slot'])) !== null ? [$n] : ['morning']);
            $r['slots'] = $slots;
            $r['slot'] = $slots[0]; // JS 정렬용 대표(첫) 슬롯 — 표준 키
            $r['slot_label'] = Stages::slotLabels($slots);
            $r['type_label'] = Stages::scheduleTypeLabel($r['type']); // 상세·카드 한글 라벨
            // 예외 프로젝트: 프로젝트명에 '[예외]' 접두 표기(서버 렌더 — 상세 팝업 등, 캘린더 JS 변경 없음)
            if (!empty($r['is_exception']) && $r['project_name'] !== null) {
                $r['project_name'] = '[예외] ' . $r['project_name'];
            }
        }
        unset($r);

        $holidays = Db::all(
            "SELECT holiday_date, name FROM holidays WHERE holiday_date BETWEEN :hf AND :ht",
            [':hf' => $from, ':ht' => $to]
        );

        Response::json(['schedules' => $rows, 'holidays' => $holidays, 'slots' => Stages::scheduleSlots()]);
    }

    /** 일정 생성/수정. 시간대 복수 선택(slots, 최소 1개). 참여자 충돌 시 confirmed 없이는 경고만. */
    public function save(): void
    {
        $id = Util::postInt('id', null) ?: null;
        $projectId = Util::postInt('project_id', null) ?: null;
        $title = Util::postStr('title');
        $eventDate = Util::dateOrNull(Util::postStr('event_date'));
        $slots = $this->requestedSlots();
        // R6: 휴가(vacation) 신규 등록 차단 — 옵션에서 숨겼어도 직접 POST 는 422 로 명시 거부(무단 work 전환 금지)
        if (strtolower(trim(Util::postStr('type', ''))) === 'vacation') {
            Response::error('휴가 유형은 더 이상 사용하지 않습니다.', 422);
        }
        $type = Stages::normalizeScheduleType(Util::postStr('type', 'work')); // 유형 화이트리스트(무효→work)
        $status = Util::postStr('status', 'scheduled');
        $memo = Util::nullIfEmpty(Util::postStr('memo'));
        $confirmed = Util::postStr('confirmed', '') === '1';
        $participants = $this->participantIds();

        if ($title === '' || $eventDate === null) {
            Response::error('제목, 날짜는 필수입니다.', 422);
        }
        // T5: 기간 일정 — 종료일(미지정 시 시작일과 동일 = 단일일)
        $endDate = Util::dateOrNull(Util::postStr('end_date')) ?? $eventDate;
        if ($endDate < $eventDate) {
            Response::error('종료일은 시작일보다 빠를 수 없습니다.', 422);
        }
        $allDay = Util::postStr('all_day', '') === '1';
        if ($allDay) {
            $slots = array_keys(Stages::scheduleSlots()); // 종일 = 전 시간대(오전·오후·야간)
        }
        if (!$slots) {
            Response::error('시간대(오전/오후/야간)를 1개 이상 선택하세요.', 422);
        }
        if (!$participants) {
            Response::error('참여 직원을 한 명 이상 선택하세요.', 422);
        }
        $participants = $this->validateUsers($participants);
        if ($projectId && !Db::val("SELECT 1 FROM projects WHERE id=:id AND deleted_at IS NULL", [':id' => $projectId])) {
            Response::error('존재하지 않는 프로젝트입니다.', 422);
        }

        $conflicts = $this->findConflicts($participants, $eventDate, $endDate, $slots, $id);
        if ($conflicts && !$confirmed) {
            Response::json(['conflict' => true, 'conflicts' => $conflicts]);
        }

        $data = [
            'project_id'     => $projectId,
            'user_id'        => $participants[0], // 대표 참여자
            'title'          => $title,
            'all_day'        => $allDay ? 1 : 0,
            'type'           => $type,
            'status'         => $status,
            'memo'           => $memo,
        ] + $this->slotMirrorFields($eventDate, $endDate, $slots);
        $auditData = $data + ['slots' => implode(',', $slots)];

        Db::transaction(function () use (&$id, $data, $auditData, $participants, $slots) {
            if ($id) {
                $before = Db::one("SELECT * FROM schedules WHERE id=:id", [':id' => $id]);
                if (!$before) {
                    Response::error('일정을 찾을 수 없습니다.', 404);
                }
                Db::update('schedules', $data, 'id = :id', [':id' => $id]);
                Audit::log('schedule_update', 'schedules', $id, $before, $auditData);
            } else {
                $id = Db::insert('schedules', $data);
                Audit::log('schedule_create', 'schedules', $id, null, $auditData);
            }
            $this->syncParticipants($id, $participants);
            $this->syncSlots($id, $slots);
        });

        Response::json(['id' => $id, 'conflict' => false]);
    }

    /** 드래그 이동: {id, event_date, slots(복수) 또는 slot(단일)}. 참여자는 유지. */
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
        $confirmed = Util::postStr('confirmed', '') === '1';
        $slots = $this->requestedSlots();
        if (!$slots) {
            // 슬롯 미지정 → 기존 슬롯 유지(관계 테이블 기준, 없으면 legacy 미러)
            $slots = $this->currentSlots($id, (string) $row['slot']);
        }
        if ($eventDate === null || !$slots) {
            Response::error('날짜/시간대 형식이 올바르지 않습니다.', 422);
        }
        // T5 기간 일정 드래그: 기간(일수)을 유지한 채 시작일만 이동
        $spanDays = 0;
        if (!empty($row['end_date']) && $row['end_date'] > $row['event_date']) {
            $spanDays = (int) ((strtotime((string) $row['end_date']) - strtotime((string) $row['event_date'])) / 86400);
        }
        $endDate = date('Y-m-d', strtotime("+$spanDays day", strtotime($eventDate)));

        $participants = array_map('intval', array_column(
            Db::all("SELECT user_id FROM schedule_participants WHERE schedule_id=:id", [':id' => $id]), 'user_id'
        ));
        if (!$participants) {
            $participants = [(int) $row['user_id']];
        }

        $conflicts = $this->findConflicts($participants, $eventDate, $endDate, $slots, $id);
        if ($conflicts && !$confirmed) {
            Response::json(['conflict' => true, 'conflicts' => $conflicts]);
        }

        $data = $this->slotMirrorFields($eventDate, $endDate, $slots);
        Db::transaction(function () use ($id, $data, $slots) {
            Db::update('schedules', $data, 'id = :id', [':id' => $id]);
            $this->syncSlots($id, $slots);
        });
        Audit::log('schedule_move', 'schedules', $id, $row, $data + ['slots' => implode(',', $slots)]);

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

    /** POST slots(배열/콤마 문자열, legacy 'slot' 단일 파라미터 겸용) → 표준 슬롯 배열(정렬·중복 제거). */
    private function requestedSlots(): array
    {
        $raw = $_POST['slots'] ?? null;
        if ($raw === null || $raw === '' || $raw === []) {
            $raw = $_POST['slot'] ?? ''; // 구 클라이언트 하위호환(단일 슬롯)
        }
        return Stages::parseSlots($raw);
    }

    /** 일정의 현재 슬롯(관계 테이블 기준, 없으면 legacy 미러 유추). */
    private function currentSlots(int $scheduleId, string $legacySlot): array
    {
        $slots = array_column(Db::all(
            "SELECT slot FROM schedule_time_slots WHERE schedule_id=:id
             ORDER BY FIELD(slot,'morning','afternoon','night')", [':id' => $scheduleId]
        ), 'slot');
        if ($slots) {
            return $slots;
        }
        $n = Stages::normalizeSlot($legacySlot);
        return $n !== null ? [$n] : ['morning'];
    }

    /**
     * legacy 호환 미러 필드 산출 — `schedules.slot`(첫 슬롯의 legacy 키)과
     * start/end_datetime([가장 이른 시작~가장 늦은 종료])을 기록하는 유일한 지점.
     * 원본은 schedule_time_slots(syncSlots) — 미러 규칙 변경 시 이 헬퍼만 수정한다.
     */
    private function slotMirrorFields(string $eventDate, ?string $endDate, array $slots): array
    {
        // T5: 종료일(기간 일정) — 단일일이면 시작일과 동일하게 기록
        $endDate = ($endDate !== null && $endDate > $eventDate) ? $endDate : $eventDate;
        [$startT, $endT] = Stages::slotSpanTimes($slots);
        return [
            'event_date'     => $eventDate,
            'end_date'       => $endDate,
            'slot'           => Stages::slotToLegacy($slots[0]),
            'start_datetime' => $eventDate . ' ' . $startT,
            'end_datetime'   => $endDate . ' ' . $endT,
        ];
    }

    /** schedule_time_slots 를 선택 슬롯 집합과 동기화. */
    private function syncSlots(int $scheduleId, array $slots): void
    {
        Db::run("DELETE FROM schedule_time_slots WHERE schedule_id=:id", [':id' => $scheduleId]);
        foreach (Stages::parseSlots($slots) as $slot) {
            Db::insert('schedule_time_slots', ['schedule_id' => $scheduleId, 'slot' => $slot]);
        }
    }

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

    /** 참여자 중 기간 겹침·(선택 슬롯 중 하나라도) 겹치는 일정(자기 제외). [{user_name, title}]
     *  T5: [event_date, end_date] 구간이 요청 구간과 교차하면 충돌 후보. */
    private function findConflicts(array $userIds, string $eventDate, ?string $endDate, array $slots, ?int $excludeId): array
    {
        $endDate = ($endDate !== null && $endDate > $eventDate) ? $endDate : $eventDate;
        $slots = Stages::parseSlots($slots);
        if (!$userIds || !$slots) {
            return [];
        }
        $inU = implode(',', array_fill(0, count($userIds), '?'));
        $inS = implode(',', array_fill(0, count($slots), '?'));
        $sql = "SELECT DISTINCT u.name AS user_name, s.title
                FROM schedules s
                JOIN schedule_time_slots st ON st.schedule_id = s.id
                JOIN schedule_participants sp ON sp.schedule_id = s.id
                JOIN users u ON u.id = sp.user_id
                WHERE s.event_date <= ? AND COALESCE(s.end_date, s.event_date) >= ?
                  AND st.slot IN ($inS) AND sp.user_id IN ($inU)";
        $params = array_merge([$endDate, $eventDate], $slots, $userIds);
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
