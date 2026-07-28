<?php
/**
 * 공정 보드(칸반) — 드래그 앤 드롭 이동 + 이력 기록. 데이터 범위는 Scope 로 강제.
 */
class ProcessController
{
    /**
     * 보드 표시(이동 가능) 대상 프로젝트 상태(R3 결정 — worklog 기록).
     * preparing 은 착공 전 '대기중' 표시, 취소·파기 제외(기존 유지).
     */
    private const BOARD_STATUSES = ['preparing', 'in_progress', 'paused', 'warranty'];

    /** R7-F1: 완료·정산 프로젝트도 보드에 카드 노출(종결 컬럼에서 육안 확인) — 단 이동 불가(locked). */
    private const BOARD_DONE_STATUSES = ['completed', 'settled'];

    /** 칸반 보드 — 원천은 projects.process_stage_id 단일(별도 카드 테이블 금지). R8-A: 도장/인테리어 유형별 탭. */
    public function board(): void
    {
        // R8-A: 최상위 유형 탭(painting|interior, 그 외/미지정 → painting)
        $boardType = Stages::normalizeConstructionType(Util::str('type', 'painting'));

        [$scopeSql, $params] = Scope::projectWhere('p');
        $statusIn = "'" . implode("','", self::BOARD_STATUSES) . "'";
        // 카드 모집단 = 이동 가능 상태 + 완료·정산(노출 전용). 정합 보정($broken)은 이동 가능 상태만 대상.
        $cardStatusIn = "'" . implode("','", array_merge(self::BOARD_STATUSES, self::BOARD_DONE_STATUSES)) . "'";
        // 유형 필터 — 미지정(NULL)은 양쪽 탭에 노출(유실 방지). 지정 프로젝트는 해당 탭에만.
        $typeCond = "(p.construction_type = '" . $boardType . "' OR p.construction_type IS NULL)";

        // 원천 정합 보정: 공정 미배치(NULL)·유효하지 않은 공정 참조는 ProcessService 로 '대기중' 배치.
        // 화면 강제 출력 패치가 아니라 원천 자체를 서비스 경유(is_auto=1, 이력 기록)로 복구한다.
        $broken = Db::all(
            "SELECT p.id, p.process_stage_id FROM projects p
             WHERE p.deleted_at IS NULL AND p.status IN ($statusIn)
               AND (p.process_stage_id IS NULL
                    OR NOT EXISTS (SELECT 1 FROM process_stages ps WHERE ps.id = p.process_stage_id))"
        );
        foreach ($broken as $b) {
            if ($b['process_stage_id'] === null) {
                ProcessService::initWaiting((int) $b['id'], null, true, '보드 정합 보정(공정 미배치)');
            } else {
                ProcessService::moveStage((int) $b['id'], ProcessService::waitingStageId(), null, '보드 정합 보정(유효하지 않은 공정 참조)', true);
            }
        }

        // R8-A: 보드 컬럼 = 선택 유형 + 공통(대기중·하자보수·전체완료) 활성 단계만
        $stages = Db::all(
            "SELECT * FROM process_stages
             WHERE (process_type = :t OR process_type = 'common') AND is_active = 1
             ORDER BY sort_order, id",
            [':t' => $boardType]
        );

        // 카드 정렬: 진입일 내림차순(신규·재진입 최상단). updated_at 정렬 금지(R3 커널).
        $projects = Db::all(
            "SELECT p.id, p.name, p.status, p.process_stage_id, p.process_entered_at, p.created_at, p.construction_type,
                    p.site_address, p.work_type, p.start_date, p.end_date, p.actual_end_date, p.progress, p.is_exception,
                    COALESCE(c.name, p.customer_name_snapshot) AS customer_name, su.name AS sales_name, sm.name AS site_manager_name,
                    (SELECT COUNT(*) FROM project_assignments pa
                       WHERE pa.project_id = p.id AND pa.status = 'active') AS assign_count,
                    (SELECT GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ')
                       FROM project_assignments pa JOIN users u ON u.id = pa.user_id
                      WHERE pa.project_id = p.id AND pa.status = 'active') AS assignee_names
             FROM projects p
             LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN users su ON su.id = p.sales_user_id
             LEFT JOIN users sm ON sm.id = p.site_manager_id
             WHERE p.deleted_at IS NULL AND p.status IN ($cardStatusIn) AND $typeCond AND $scopeSql
             ORDER BY p.process_entered_at DESC, p.created_at DESC, p.id DESC",
            $params
        );

        $photos = [];
        $nextSchedules = [];
        $ids = array_column($projects, 'id');
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = Db::all(
                "SELECT project_id, id FROM (
                    SELECT project_id, id,
                           ROW_NUMBER() OVER (PARTITION BY project_id ORDER BY created_at ASC) AS rn
                    FROM project_files
                    WHERE entity_type = 'project' AND mime LIKE 'image/%' AND project_id IN ($in)
                 ) t WHERE rn = 1",
                $ids
            );
            foreach ($rows as $r) {
                $photos[(int) $r['project_id']] = (int) $r['id'];
            }
            // 다음 일정: 오늘 이후 가장 이른 예정 일정 1건
            $rows = Db::all(
                "SELECT project_id, title, event_date, slot FROM (
                    SELECT s.project_id, s.title, s.event_date, s.slot,
                           ROW_NUMBER() OVER (PARTITION BY s.project_id
                               ORDER BY s.event_date ASC, FIELD(s.slot,'am','morning','pm','afternoon','night'), s.id) AS rn
                    FROM schedules s
                    WHERE s.project_id IN ($in) AND s.status = 'scheduled' AND s.event_date >= CURDATE()
                 ) t WHERE rn = 1",
                $ids
            );
            foreach ($rows as $r) {
                $nextSchedules[(int) $r['project_id']] = $r;
            }
        }

        $byStage = [];
        foreach ($projects as $p) {
            $byStage[(int) $p['process_stage_id']][] = $p;
        }

        // 그룹 매핑(단일 출처 Stages, R8-A 유형별): 대기중 / 착공준비 / 시공 / 마무리 / 하자보수 / 종결
        $s2g = Stages::processStageToGroup($boardType);
        $groupDefs = Stages::processGroups($boardType);
        $groupCols = [];
        foreach ($groupDefs as $gkey => $g) {
            $groupCols[$gkey] = [];
        }
        foreach ($stages as &$st) {
            $gkey = $s2g[$st['stage_key']] ?? 'prep';
            $st['group'] = $gkey;
            $st['group_color'] = $groupDefs[$gkey]['color'] ?? '#9ca3af';
            $groupCols[$gkey][] = $st;
        }
        unset($st);

        // 상단 요약 — 대시보드와 동일 정의(delayedCond)를 보드 모집단에서 계산 (R11: 검수 대기 제거)
        $waitingId = ProcessService::waitingStageId();
        // R10: 요약 정의 단일화 — 카드 이동 응답(move)과 동일 계산기를 사용해 화면·이동 후 숫자를 일치시킨다
        $summary = self::computeSummary($projects, $waitingId);
        // R11: 유형별 위치 번호(1..N) — 그룹 범위 라벨·진행률 분모의 단일 출처(공정 마스터 기준 동적)
        $positions = Stages::processStagePositions($boardType);

        View::render('process/board', [
            'title'         => '공정 보드',
            'stages'        => $stages,
            'positions'     => $positions,
            'byStage'       => $byStage,
            'photos'        => $photos,
            'nextSchedules' => $nextSchedules,
            'groups'        => $groupDefs,
            'groupCols'     => $groupCols,
            'summary'       => $summary,
            'waitingId'     => $waitingId,
            'statusLabels'  => StatusService::PROJECT_LABELS,
            'statusBadge'   => StatusService::PROJECT_BADGE,
            'tabs'          => Stages::processTabs($boardType),
            'boardType'     => $boardType,
            'canMove'       => Rbac::can('process.move'),
            'canManage'     => Rbac::can('project.manage'),
            'scripts'       => ['vendor/Sortable.min.js', 'js/process-board.js'],
        ]);
    }

    /** R14: 상태 → 보드 상태그룹 키(waiting/active/warranty/done). */
    public static function statusGroup(string $status): string
    {
        if ($status === 'preparing') return 'waiting';
        if ($status === 'warranty') return 'warranty';
        if (in_array($status, ['completed', 'settled'], true)) return 'done';
        return 'active'; // in_progress·paused
    }

    /** 접근 가드 — 보드 대상(스코프·삭제 제외) 프로젝트 로드, 실패 시 JSON 에러. */
    private function guardBoardProject(int $projectId): array
    {
        [$scopeSql, $params] = Scope::projectWhere('p');
        $project = Db::one("SELECT p.* FROM projects p WHERE p.id = :id AND p.deleted_at IS NULL AND $scopeSql",
            array_merge([':id' => $projectId], $params));
        if (!$project) {
            Response::error('프로젝트를 찾을 수 없거나 접근 권한이 없습니다.', 404);
        }
        return $project;
    }

    /** R14: 카드 게이지 저장 — 파생 결과(배지·현재공정 라벨 포함)를 즉시 반영용으로 반환. */
    public function progressSet(): void
    {
        $project = $this->guardBoardProject((int) Util::postInt('project_id', 0));
        try {
            $r = ProcessService::setStageProgress((int) $project['id'], (int) Util::postInt('stage_id', 0),
                (int) Util::postInt('pct', 0), Auth::id());
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
        $cur = Db::one("SELECT id, name, color FROM process_stages WHERE id = :id", [':id' => $r['current_stage_id']]);
        $type = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        Response::json([
            'pct' => $r['pct'], 'progress' => $r['progress'], 'status' => $r['status'],
            'status_label' => StatusService::PROJECT_LABELS[$r['status']] ?? $r['status'],
            'badge_class' => StatusService::PROJECT_BADGE[$r['status']] ?? 'badge',
            'group' => self::statusGroup($r['status']),
            'current_stage_id' => $r['current_stage_id'],
            'current_stage_name' => $cur['name'] ?? '대기중',
            'current_stage_color' => $cur['color'] ?? '#64748b',
            'all_done' => $r['all_done'],
            'summary' => $this->boardSummaryFor($type),
        ]);
    }

    /** R14: 전 공정 100% 확인 후 완료 확정 — 서버 재검증. */
    public function completeConfirm(): void
    {
        $project = $this->guardBoardProject((int) Util::postInt('project_id', 0));
        $type = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        $stages = ProcessService::gaugeStages($type);
        foreach ($stages as $st) {
            $v = (int) (Db::val("SELECT pct FROM project_stage_progress WHERE project_id = :p AND stage_id = :s",
                [':p' => (int) $project['id'], ':s' => (int) $st['id']]) ?? 0);
            if ($v < 100) {
                Response::error('아직 100%가 아닌 공정이 있어 완료 처리할 수 없습니다: ' . $st['name'], 422);
            }
        }
        if (!in_array($project['status'], ['completed', 'settled'], true)) {
            Db::transaction(function () use ($project) {
                StatusService::applyProjectStatus($project, 'completed', ['reason' => '공정 게이지 전체 100% 완료 확인']);
            });
        }
        Response::json(['status' => 'completed', 'status_label' => StatusService::PROJECT_LABELS['completed'],
            'badge_class' => StatusService::PROJECT_BADGE['completed'], 'group' => 'done',
            'summary' => $this->boardSummaryFor($type)]);
    }

    /** R14: 하자보수 전환/해제(해제=완료 복귀) — 카드 버튼. */
    public function warrantySet(): void
    {
        $project = $this->guardBoardProject((int) Util::postInt('project_id', 0));
        $on = Util::postStr('action', 'set') !== 'clear';
        $type = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        Db::transaction(function () use ($project, $on) {
            if ($on && $project['status'] !== 'warranty') {
                StatusService::applyProjectStatus($project, 'warranty', ['reason' => '보드 하자보수 전환(버튼)']);
                $wr = ProcessService::stageIdByKey('warranty_repair');
                if ($wr !== null) {
                    ProcessService::moveStage((int) $project['id'], $wr, Auth::id(), '하자보수 전환 보드 이동', true);
                }
            } elseif (!$on && $project['status'] === 'warranty') {
                StatusService::applyProjectStatus($project, 'completed', ['reason' => '하자보수 종료(완료 복귀)']);
            }
        });
        $status = $on ? 'warranty' : 'completed';
        Response::json(['status' => $status, 'status_label' => StatusService::PROJECT_LABELS[$status],
            'badge_class' => StatusService::PROJECT_BADGE[$status], 'group' => self::statusGroup($status),
            'summary' => $this->boardSummaryFor($type)]);
    }

    /** R14: 카드 메모 목록(일자 내림차순, 최근 50). */
    public function memoList(): void
    {
        $project = $this->guardBoardProject((int) Util::postInt('project_id', 0) ?: (int) ($_GET['project_id'] ?? 0));
        $rows = Db::all(
            "SELECT m.id, m.memo_date, m.content, m.created_at, u.name AS user_name
             FROM project_memos m LEFT JOIN users u ON u.id = m.created_by
             WHERE m.project_id = :p ORDER BY m.memo_date DESC, m.id DESC LIMIT 50",
            [':p' => (int) $project['id']]
        );
        Response::json(['memos' => $rows, 'count' => count($rows)]);
    }

    /** R14: 카드 메모 등록. */
    public function memoSave(): void
    {
        $project = $this->guardBoardProject((int) Util::postInt('project_id', 0));
        $date = Util::dateOrNull(Util::postStr('memo_date')) ?? date('Y-m-d');
        $content = trim(mb_substr(Util::postStr('content', ''), 0, 1000));
        if ($content === '') {
            Response::error('메모 내용을 입력하세요.', 422);
        }
        $id = Db::insert('project_memos', ['project_id' => (int) $project['id'], 'memo_date' => $date,
            'content' => $content, 'created_by' => Auth::id() ?: null]);
        Audit::log('project_memo_create', 'project_memos', $id, null, ['memo_date' => $date, 'content' => $content]);
        Response::json(['id' => $id]);
    }

    /** R14: 카드 메모 삭제(물리 — 경량 메모, 감사 로그 보존). */
    public function memoDelete(): void
    {
        $id = (int) Util::postInt('id', 0);
        $memo = Db::one("SELECT * FROM project_memos WHERE id = :id", [':id' => $id]);
        if (!$memo) {
            Response::error('메모를 찾을 수 없습니다.', 404);
        }
        $this->guardBoardProject((int) $memo['project_id']);
        Db::run("DELETE FROM project_memos WHERE id = :id", [':id' => $id]);
        Audit::log('project_memo_delete', 'project_memos', $id, $memo, null);
        Response::json(['id' => $id]);
    }

    /**
     * 상단 요약 계산기(단일 정의) — board() 렌더와 move() 응답이 공유한다(R10).
     * R11: '검수 대기'(requires_confirm) 지표 제거 — 공정 잠금·확인 기능 폐지.
     * @param array $projects 보드 모집단 행(status/process_stage_id/end_date/actual_end_date 필요)
     */
    private static function computeSummary(array $projects, int $waitingId): array
    {
        $today = date('Y-m-d');
        $summary = ['total' => count($projects), 'active' => 0, 'waiting' => 0, 'stages' => 0, 'delayed' => 0, 'done' => 0];
        $stageSet = [];
        foreach ($projects as $p) {
            $sid = (int) $p['process_stage_id'];
            $isDone = in_array($p['status'], self::BOARD_DONE_STATUSES, true);
            if ($isDone) {
                // 완료·정산 카드는 '진행 공정 수'·대기중 집계에서 제외(이동 시 자동 재개되어 재집계)
                $summary['done']++;
                continue;
            }
            if ($p['status'] === 'in_progress') {
                $summary['active']++;
            }
            if ($sid === $waitingId) {
                $summary['waiting']++;
            } else {
                $stageSet[$sid] = true;
            }
            // 지연 = 준공예정 경과 + 준공 미처리(대시보드 delayedCond 와 동일 기준)
            if (!empty($p['end_date']) && $p['end_date'] < $today && empty($p['actual_end_date'])) {
                $summary['delayed']++;
            }
        }
        $summary['stages'] = count($stageSet);
        return $summary;
    }

    /** 유형 보드의 현재 요약(경량 재조회) — move() 응답용. board() 모집단 조건과 동일해야 한다. */
    private function boardSummaryFor(string $boardType): array
    {
        [$scopeSql, $params] = Scope::projectWhere('p');
        $cardStatusIn = "'" . implode("','", array_merge(self::BOARD_STATUSES, self::BOARD_DONE_STATUSES)) . "'";
        $typeCond = "(p.construction_type = '" . $boardType . "' OR p.construction_type IS NULL)";
        $projects = Db::all(
            "SELECT p.status, p.process_stage_id, p.end_date, p.actual_end_date
             FROM projects p
             WHERE p.deleted_at IS NULL AND p.status IN ($cardStatusIn) AND $typeCond AND $scopeSql",
            $params
        );
        return self::computeSummary($projects, ProcessService::waitingStageId());
    }

    /** 프로젝트 공정 변경 이력 (JSON). */
    public function history(): void
    {
        $projectId = (int) Util::int('project_id', 0);
        if (!$projectId || !Scope::canAccessProject($projectId)) {
            Response::error('이 프로젝트에 접근할 권한이 없습니다.', 403);
        }

        $rows = Db::all(
            "SELECT h.*, fs.name AS from_name, ts.name AS to_name, u.name AS changed_by_name
             FROM project_process_history h
             LEFT JOIN process_stages fs ON fs.id = h.from_stage_id
             JOIN process_stages ts ON ts.id = h.to_stage_id
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.project_id = :pid
             ORDER BY h.changed_at DESC, h.id DESC",
            [':pid' => $projectId]
        );

        Response::json(['rows' => $rows]);
    }

    /** 공정 이력의 사유(reason) 수정. */
    public function historyUpdate(): void
    {
        $historyId = Util::postInt('history_id', 0);
        $reason = Util::nullIfEmpty(Util::postStr('reason'));
        $row = Db::one("SELECT * FROM project_process_history WHERE id = :id", [':id' => $historyId]);
        if (!$row) {
            Response::error('이력을 찾을 수 없습니다.', 404);
        }
        if (!Scope::canAccessProject((int) $row['project_id'])) {
            Response::error('이 프로젝트에 접근할 권한이 없습니다.', 403);
        }
        Db::update('project_process_history', ['reason' => $reason], 'id = :id', [':id' => $historyId]);
        Audit::log('process_history_edit', 'project_process_history', $historyId, ['reason' => $row['reason']], ['reason' => $reason]);
        Response::json(['id' => $historyId, 'reason' => $reason]);
    }

    /**
     * R8-A: 프로젝트 공사 유형(도장/인테리어) 지정 (perm project.manage 는 라우터가 강제, POST).
     * 미지정 카드의 '유형 미지정' 배지에서 호출. 지정 후 현재 공정이 반대 유형 전용 단계면
     * ProcessService 가 '대기중'으로 재배치(이력 is_auto=1 기록)한다.
     */
    public function setType(): void
    {
        $projectId = (int) Util::postInt('project_id', 0);
        $type = Util::postStr('construction_type', '');
        if (!$projectId || !array_key_exists($type, Stages::constructionTypes())) {
            Response::error('잘못된 요청입니다.', 400);
        }
        if (!Scope::canAccessProject($projectId)) {
            Response::error('이 프로젝트에 접근할 권한이 없습니다.', 403);
        }
        $project = Db::one(
            "SELECT id, construction_type, process_stage_id FROM projects WHERE id = :id AND deleted_at IS NULL",
            [':id' => $projectId]
        );
        if (!$project) {
            Response::error('프로젝트를 찾을 수 없습니다.', 404);
        }

        Db::update('projects', ['construction_type' => $type], 'id = :id', [':id' => $projectId]);
        Audit::log('project.settype', 'project', $projectId,
            ['construction_type' => $project['construction_type']],
            ['construction_type' => $type]);

        // 현재 공정이 반대 유형 전용(또는 비활성) 단계면 '대기중' 재배치(reason 고정, auto=1)
        $moved = ProcessService::ensureStageMatchesType($projectId, Auth::id() ?: null);

        Response::json(['ok' => true, 'construction_type' => $type, 'moved_to_waiting' => $moved]);
    }

    // ── R4 T3: 하자보수(warranty_repairs) CRUD ──
    //    (전체완료 게이트는 R11 에서 제거 — 공정 이동 잠금·확인 기능 폐지, 미수금은 정산 상태 축이 관리)

    /** 하자보수 상태 enum(라벨은 뷰 공용). */
    public const WARRANTY_STATUSES = ['open' => '접수', 'in_progress' => '처리 중', 'done' => '완료'];

    /** 하자보수 등록·수정 (perm project.manage 는 라우터가 강제). id=0 이면 신규. */
    public function warrantySave(): void
    {
        $projectId = (int) Util::postInt('project_id', 0);
        if (!$projectId || !Scope::canAccessProject($projectId)) {
            Response::error('이 프로젝트에 접근할 권한이 없습니다.', 403);
        }
        if (!Db::val("SELECT id FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId])) {
            Response::error('프로젝트를 찾을 수 없습니다.', 404);
        }
        $id = (int) Util::postInt('id', 0);
        $before = null;
        if ($id) {
            $before = Db::one("SELECT * FROM warranty_repairs WHERE id = :id AND project_id = :p", [':id' => $id, ':p' => $projectId]);
            if (!$before) {
                Response::error('하자보수 건을 찾을 수 없습니다.', 404);
            }
        }

        $content = trim(Util::postStr('content', ''));
        if ($content === '') {
            Response::error('하자 내용을 입력하세요.', 422);
        }
        $status = Util::postStr('status', 'open');
        if (!isset(self::WARRANTY_STATUSES[$status])) {
            Response::error('알 수 없는 하자 상태입니다.', 422);
        }
        $requestedBy = Util::postInt('requested_by', 0) ?: null;
        $assigneeId  = Util::postInt('assignee_id', 0) ?: null;
        foreach ([$requestedBy, $assigneeId] as $uid) {
            if ($uid !== null && !Db::val("SELECT id FROM users WHERE id = :id AND deleted_at IS NULL", [':id' => $uid])) {
                Response::error('존재하지 않는 직원입니다.', 422);
            }
        }
        $completedAt = Util::dateOrNull(Util::postStr('completed_at'));
        if ($status === 'done' && $completedAt === null) {
            $completedAt = date('Y-m-d'); // 완료 처리 시 완료일 미입력이면 오늘로
        }

        $data = [
            'project_id'   => $projectId,
            'content'      => mb_substr($content, 0, 500),
            'requested_at' => Util::dateOrNull(Util::postStr('requested_at')) ?? ($before['requested_at'] ?? date('Y-m-d')),
            'requested_by' => $requestedBy,
            'assignee_id'  => $assigneeId,
            'due_date'     => Util::dateOrNull(Util::postStr('due_date')),
            'completed_at' => $completedAt,
            'memo'         => Util::nullIfEmpty(mb_substr(Util::postStr('memo', ''), 0, 500)),
            'status'       => $status,
        ];
        if ($id) {
            Db::update('warranty_repairs', $data, 'id = :id', [':id' => $id]);
            Audit::log('warranty_update', 'warranty_repairs', $id, $before, $data);
        } else {
            $id = Db::insert('warranty_repairs', $data);
            Audit::log('warranty_create', 'warranty_repairs', $id, null, $data);
        }
        Response::json(['id' => $id]);
    }

    /** 하자보수 삭제 — Audit 에 원본 전체 보존. 첨부 사진(project_files)은 물리 보존. */
    public function warrantyDelete(): void
    {
        $id = (int) Util::postInt('id', 0);
        $row = $id ? Db::one("SELECT * FROM warranty_repairs WHERE id = :id", [':id' => $id]) : null;
        if (!$row) {
            Response::error('하자보수 건을 찾을 수 없습니다.', 404);
        }
        if (!Scope::canAccessProject((int) $row['project_id'])) {
            Response::error('이 프로젝트에 접근할 권한이 없습니다.', 403);
        }
        $photoCnt = (int) Db::val(
            "SELECT COUNT(*) FROM project_files WHERE entity_type = 'warranty_repair' AND entity_id = :id", [':id' => $id]);
        Db::run("DELETE FROM warranty_repairs WHERE id = :id", [':id' => $id]);
        Audit::log('warranty_delete', 'warranty_repairs', $id, $row, ['photos_kept' => $photoCnt]);
        Response::json(['id' => $id]);
    }

    /** 하자보수 사진 업로드 — project_files(entity_type='warranty_repair') 재사용, 이미지 전용. */
    public function warrantyPhoto(): void
    {
        $id = (int) Util::postInt('id', 0);
        $row = $id ? Db::one("SELECT * FROM warranty_repairs WHERE id = :id", [':id' => $id]) : null;
        if (!$row) {
            Response::error('하자보수 건을 찾을 수 없습니다.', 404);
        }
        $projectId = (int) $row['project_id'];
        if (!Scope::canAccessProject($projectId)) {
            Response::error('이 프로젝트에 접근할 권한이 없습니다.', 403);
        }
        $file = $_FILES['file'] ?? null;
        if (!$file) {
            Response::error('업로드할 사진을 선택하세요.', 422);
        }
        try {
            $info = Upload::save($file, 'projects/' . $projectId, Upload::imageExts());
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
        $fileId = Db::insert('project_files', [
            'project_id'    => $projectId,
            'entity_type'   => 'warranty_repair',
            'entity_id'     => $id,
            'original_name' => $info['original_name'],
            'stored_name'   => $info['stored_name'],
            'path'          => $info['path'],
            'size'          => $info['size'],
            'mime'          => $info['mime'],
            'uploaded_by'   => Auth::id(),
        ]);
        Audit::log('warranty_photo', 'project_files', $fileId, null, [
            'warranty_id'   => $id,
            'project_id'    => $projectId,
            'original_name' => $info['original_name'],
        ]);
        Response::json(['id' => $fileId]);
    }
}
