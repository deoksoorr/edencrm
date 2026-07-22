<?php
/**
 * 공정 보드(칸반) — 드래그 앤 드롭 이동 + 이력 기록. 데이터 범위는 Scope 로 강제.
 */
class ProcessController
{
    /** 칸반 보드. */
    public function board(): void
    {
        [$scopeSql, $params] = Scope::projectWhere('p');

        $stages = Db::all("SELECT * FROM process_stages ORDER BY sort_order");

        $projects = Db::all(
            "SELECT p.*, c.name AS customer_name, sm.name AS site_manager_name,
                    (SELECT COUNT(*) FROM project_assignments pa
                       WHERE pa.project_id = p.id AND pa.status = 'active') AS assign_count
             FROM projects p
             JOIN customers c ON c.id = p.customer_id
             LEFT JOIN users sm ON sm.id = p.site_manager_id
             WHERE p.deleted_at IS NULL AND $scopeSql
             ORDER BY p.end_date IS NULL, p.end_date ASC",
            $params
        );

        $photos = [];
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
        }

        $byStage = [];
        foreach ($projects as $p) {
            $byStage[(int) $p['process_stage_id']][] = $p;
        }

        // 18단계 → 5그룹 매핑(단일 출처 Stages)
        $s2g = Stages::processStageToGroup();
        $groupDefs = Stages::processGroups();
        foreach ($stages as &$st) {
            $gkey = $s2g[$st['stage_key']] ?? 'prep';
            $st['group'] = $gkey;
            $st['group_color'] = $groupDefs[$gkey]['color'] ?? '#9ca3af';
        }
        unset($st);

        View::render('process/board', [
            'title'   => '공정 보드',
            'stages'  => $stages,
            'byStage' => $byStage,
            'photos'  => $photos,
            'groups'  => $groupDefs,
            'tabs'    => Stages::processTabs(),
            'canMove' => Rbac::can('process.move'),
            'scripts' => ['vendor/Sortable.min.js', 'js/process-board.js'],
        ]);
    }

    /** 공정 이동 (perm process.move 는 라우터가 강제). */
    public function move(): void
    {
        $projectId = (int) Util::postInt('project_id', 0);
        $toStageId = (int) Util::postInt('to_stage_id', 0);
        $reason    = Util::nullIfEmpty(Util::postStr('reason', ''));

        if (!$projectId || !$toStageId) {
            Response::error('잘못된 요청입니다.', 400);
        }
        if (!Scope::canAccessProject($projectId)) {
            Response::error('이 프로젝트에 접근할 권한이 없습니다.', 403);
        }

        $project = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$project) {
            Response::error('프로젝트를 찾을 수 없습니다.', 404);
        }
        if (in_array($project['status'], ['completed', 'cancelled'], true)) {
            Response::error('완료(또는 취소)된 프로젝트는 공정을 이동할 수 없습니다.', 400);
        }

        $toStage = Db::one("SELECT * FROM process_stages WHERE id = :id", [':id' => $toStageId]);
        if (!$toStage) {
            Response::error('대상 공정 단계를 찾을 수 없습니다.', 400);
        }

        $fromStageId = $project['process_stage_id'] ? (int) $project['process_stage_id'] : null;
        $fromStage = $fromStageId ? Db::one("SELECT * FROM process_stages WHERE id = :id", [':id' => $fromStageId]) : null;

        $skipWarn = false;
        if ($fromStage) {
            $diff = (int) $toStage['sort_order'] - (int) $fromStage['sort_order'];
            if ($diff >= 2) {
                $skipWarn = true;
            }
        }

        // 공정 단계 이동 시 진행률 자동 산정(단계 순서 비율). 완료/취소가 아니면 갱신.
        $maxSort = (int) (Db::val("SELECT MAX(sort_order) FROM process_stages") ?: 18);
        $progress = max(0, min(100, (int) round((int) $toStage['sort_order'] / $maxSort * 100)));

        Db::transaction(function () use ($projectId, $toStageId, $fromStageId, $reason, $progress) {
            Db::update('projects', ['process_stage_id' => $toStageId, 'progress' => $progress], 'id = :id', [':id' => $projectId]);
            Db::insert('project_process_history', [
                'project_id'   => $projectId,
                'from_stage_id'=> $fromStageId,
                'to_stage_id'  => $toStageId,
                'changed_by'   => Auth::id(),
                'reason'       => $reason,
                'changed_at'   => date('Y-m-d H:i:s'),
            ]);
        });

        Audit::log('process_move', 'project', $projectId,
            ['from_stage_id' => $fromStageId],
            ['to_stage_id' => $toStageId, 'reason' => $reason, 'progress' => $progress]);

        Response::json([
            'project_id'       => $projectId,
            'from_stage_id'    => $fromStageId,
            'to_stage_id'      => $toStageId,
            'progress'         => $progress,
            'requires_confirm' => (bool) $toStage['requires_confirm'],
            'skip_warn'        => $skipWarn,
        ]);
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
}
