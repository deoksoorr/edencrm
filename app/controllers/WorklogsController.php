<?php
/**
 * 작업일지 (T7). 목록/작성/상세/사진업로드/관리자확인.
 * 쓰기 범위: worklog.view_all 이 있으면 전체 프로젝트 대상 작성 가능, 없으면
 * 본인이 배정(project_assignments) 되었거나 담당(site_manager_id/sales_user_id)인 프로젝트만 선택 가능.
 */
class WorklogsController
{
    public function index(): void
    {
        $viewAll = Rbac::can('worklog.view_all');
        $uid = Auth::id();
        $page = max(1, Util::int('page', 1));
        $projectQ = Util::str('project');
        $authorQ = Util::str('author');
        $dateFrom = Util::str('date_from');
        $dateTo = Util::str('date_to');

        $where = ['1=1'];
        $params = [];
        if (!$viewAll) {
            $where[] = "(w.user_id = :me OR w.project_id IN (SELECT project_id FROM project_assignments WHERE user_id = :me2))";
            $params[':me'] = $uid;
            $params[':me2'] = $uid;
        }
        if ($projectQ !== '') {
            $where[] = "(p.name LIKE :pq OR p.project_no LIKE :pq2)";
            $params[':pq'] = "%$projectQ%";
            $params[':pq2'] = "%$projectQ%";
        }
        if ($authorQ !== '') {
            $where[] = "u.name LIKE :aq";
            $params[':aq'] = "%$authorQ%";
        }
        if ($dateFrom !== '') {
            $where[] = "w.work_date >= :df";
            $params[':df'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "w.work_date <= :dt";
            $params[':dt'] = $dateTo;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::val(
            "SELECT COUNT(*) FROM work_logs w JOIN projects p ON p.id = w.project_id JOIN users u ON u.id = w.user_id WHERE $whereSql",
            $params
        );
        $pg = Util::paginate($total, $page, (int) ($GLOBALS['config']['PAGE_SIZE'] ?? 20));

        $rows = Db::all(
            "SELECT w.id, w.work_date, w.progress, w.confirmed_by, w.confirmed_at,
                    p.id AS project_id, p.name AS project_name, p.project_no,
                    u.name AS author_name, ps.name AS stage_name
             FROM work_logs w
             JOIN projects p ON p.id = w.project_id
             JOIN users u ON u.id = w.user_id
             LEFT JOIN process_stages ps ON ps.id = w.process_stage_id
             WHERE $whereSql
             ORDER BY w.work_date DESC, w.id DESC
             LIMIT {$pg['per']} OFFSET {$pg['offset']}",
            $params
        );

        View::render('worklogs/index', [
            'title'   => '작업일지',
            'rows'    => $rows,
            'pg'      => $pg,
            'q'       => ['project' => $projectQ, 'author' => $authorQ, 'date_from' => $dateFrom, 'date_to' => $dateTo],
            'viewAll' => $viewAll,
        ]);
    }

    public function form(): void
    {
        $id = Util::int('id', null);
        $row = null;
        if ($id) {
            $row = Db::one("SELECT * FROM work_logs WHERE id = :id", [':id' => $id]);
            if (!$row || (int) $row['user_id'] !== Auth::id()) {
                Response::redirect('worklogs.index', [], '본인이 작성한 작업일지만 수정할 수 있습니다.', 'error');
            }
        }

        $projects = $this->myProjects();
        $stages = Db::all("SELECT id, name FROM process_stages ORDER BY sort_order");

        View::render('worklogs/form', [
            'title'    => $row ? '작업일지 수정' : '작업일지 작성',
            'row'      => $row,
            'projects' => $projects,
            'stages'   => $stages,
        ]);
    }

    public function save(): void
    {
        $id = Util::postInt('id', null);
        $projectId = Util::postInt('project_id');
        $workDate = Util::postStr('work_date');
        $startTime = Util::nullIfEmpty(Util::postStr('start_time'));
        $endTime = Util::nullIfEmpty(Util::postStr('end_time'));
        $stageId = Util::postInt('process_stage_id', null) ?: null;
        $content = Util::postStr('content');
        $materials = Util::nullIfEmpty(Util::postStr('materials'));
        $materialQty = Util::nullIfEmpty(Util::postStr('material_qty'));
        $equipment = Util::nullIfEmpty(Util::postStr('equipment'));
        $weather = Util::nullIfEmpty(Util::postStr('weather'));
        $progress = Util::postInt('progress', null);
        $issues = Util::nullIfEmpty(Util::postStr('issues'));
        $delayReason = Util::nullIfEmpty(Util::postStr('delay_reason'));
        $nextWork = Util::nullIfEmpty(Util::postStr('next_work'));

        if (!$projectId || $workDate === '' || $content === '') {
            Response::error('프로젝트, 작업일, 작업 내용은 필수입니다.', 422);
        }
        if (!$this->canWriteProject($projectId)) {
            Response::error('본인이 배정된 프로젝트에만 작업일지를 작성할 수 있습니다.', 403);
        }
        if ($progress !== null && ($progress < 0 || $progress > 100)) {
            Response::error('진행률은 0~100 사이여야 합니다.', 422);
        }
        if ($stageId && !Db::val("SELECT 1 FROM process_stages WHERE id = :id", [':id' => $stageId])) {
            Response::error('존재하지 않는 공정 단계입니다.', 422);
        }

        $data = [
            'project_id'       => $projectId,
            'user_id'          => Auth::id(),
            'work_date'        => $workDate,
            'start_time'       => $startTime,
            'end_time'         => $endTime,
            'process_stage_id' => $stageId,
            'content'          => $content,
            'materials'        => $materials,
            'material_qty'     => $materialQty,
            'equipment'        => $equipment,
            'weather'          => $weather,
            'progress'         => $progress,
            'issues'           => $issues,
            'delay_reason'     => $delayReason,
            'next_work'        => $nextWork,
        ];

        $before = null;
        if ($id) {
            $before = Db::one("SELECT * FROM work_logs WHERE id = :id", [':id' => $id]);
            if (!$before || (int) $before['user_id'] !== Auth::id()) {
                Response::error('본인이 작성한 작업일지만 수정할 수 있습니다.', 403);
            }
            Db::update('work_logs', $data, 'id = :id', [':id' => $id]);
        } else {
            $id = Db::insert('work_logs', $data);
        }
        Audit::log($before ? 'worklog_update' : 'worklog_create', 'work_logs', $id, $before, $data);

        Response::json(['id' => $id]);
    }

    public function show(): void
    {
        $id = Util::int('id');
        $row = Db::one(
            "SELECT w.*, p.name AS project_name, p.project_no, u.name AS author_name,
                    ps.name AS stage_name, cu.name AS confirmed_by_name
             FROM work_logs w
             JOIN projects p ON p.id = w.project_id
             JOIN users u ON u.id = w.user_id
             LEFT JOIN process_stages ps ON ps.id = w.process_stage_id
             LEFT JOIN users cu ON cu.id = w.confirmed_by
             WHERE w.id = :id",
            [':id' => $id]
        );
        if (!$row) {
            http_response_code(404);
            View::renderError(404, '작업일지 없음', '요청한 작업일지를 찾을 수 없습니다.');
            return;
        }

        $viewAll = Rbac::can('worklog.view_all');
        if (!$viewAll && (int) $row['user_id'] !== Auth::id() && !$this->canWriteProject((int) $row['project_id'])) {
            http_response_code(403);
            View::renderError(403, '접근 권한 없음', '이 작업일지를 열람할 권한이 없습니다.');
            return;
        }

        $photos = Db::all(
            "SELECT pf.id AS file_id, pf.path, pf.original_name
             FROM work_log_photos wp JOIN project_files pf ON pf.id = wp.file_id
             WHERE wp.work_log_id = :wid ORDER BY wp.id",
            [':wid' => $id]
        );

        $canConfirm = Rbac::can('worklog.confirm');
        $canUpload = Rbac::can('worklog.create') && (int) $row['user_id'] === Auth::id();

        $inline = '';
        if ($canConfirm && !$row['confirmed_by']) {
            $inline = "
document.getElementById('btnConfirm')?.addEventListener('click', async function () {
  var ok = await EDEN.confirm('이 작업일지를 관리자 확인 처리하시겠습니까?');
  if (!ok) return;
  try {
    await api('worklogs.confirm', {id: {$id}});
    toast('확인 처리되었습니다.', 'success');
    location.reload();
  } catch (err) { toast(err.message, 'error'); }
});
";
        }

        View::render('worklogs/show', [
            'title'       => '작업일지 상세',
            'row'         => $row,
            'photos'      => $photos,
            'canConfirm'  => $canConfirm,
            'canUpload'   => $canUpload,
            'inlineScript'=> $inline,
        ]);
    }

    public function confirm(): void
    {
        $id = Util::postInt('id');
        if (!$id) {
            Response::error('id가 필요합니다.', 422);
        }
        $row = Db::one("SELECT * FROM work_logs WHERE id = :id", [':id' => $id]);
        if (!$row) {
            Response::error('작업일지를 찾을 수 없습니다.', 404);
        }
        if ($row['confirmed_by']) {
            Response::error('이미 관리자 확인이 완료된 작업일지입니다.', 422);
        }

        $data = ['confirmed_by' => Auth::id(), 'confirmed_at' => date('Y-m-d H:i:s')];
        Db::update('work_logs', $data, 'id = :id', [':id' => $id]);
        Audit::log('worklog_confirm', 'work_logs', $id, $row, $data);
        Notif::push(
            (int) $row['user_id'],
            'worklog',
            '작업일지 확인 완료',
            '작성하신 작업일지가 관리자 확인되었습니다.',
            'worklogs.show',
            ['id' => $id]
        );

        Response::json(['id' => $id]);
    }

    /** 사진 업로드: 본인 작업일지에만, 이미지 확장자만 허용. */
    public function uploadPhoto(): void
    {
        $workLogId = Util::postInt('work_log_id');
        if (!$workLogId) {
            Response::error('work_log_id가 필요합니다.', 422);
        }
        $row = Db::one("SELECT * FROM work_logs WHERE id = :id", [':id' => $workLogId]);
        if (!$row) {
            Response::error('작업일지를 찾을 수 없습니다.', 404);
        }
        if ((int) $row['user_id'] !== Auth::id()) {
            Response::error('본인 작업일지에만 사진을 업로드할 수 있습니다.', 403);
        }
        if (empty($_FILES['photo'])) {
            Response::error('업로드할 사진 파일이 없습니다.', 422);
        }

        try {
            $saved = Upload::save($_FILES['photo'], 'worklogs', Upload::imageExts());
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }

        $fileId = Db::insert('project_files', [
            'project_id'    => $row['project_id'],
            'entity_type'   => 'work_log',
            'entity_id'     => $workLogId,
            'original_name' => $saved['original_name'],
            'stored_name'   => $saved['stored_name'],
            'path'          => $saved['path'],
            'size'          => $saved['size'],
            'mime'          => $saved['mime'],
            'uploaded_by'   => Auth::id(),
        ]);
        $photoId = Db::insert('work_log_photos', [
            'work_log_id' => $workLogId,
            'file_id'     => $fileId,
        ]);
        Audit::log('worklog_photo_upload', 'work_log_photos', $photoId, null, ['work_log_id' => $workLogId, 'file_id' => $fileId]);

        Response::json(['id' => $photoId, 'file_id' => $fileId, 'path' => $saved['path']]);
    }

    /** 작성 권한 있는 프로젝트 목록 (worklog.view_all 없으면 담당/배정만). */
    private function myProjects(): array
    {
        [$w, $p] = $this->projectWriteScope();
        return Db::all(
            "SELECT p.id, p.project_no, p.name FROM projects p WHERE p.deleted_at IS NULL AND $w ORDER BY p.project_no DESC",
            $p
        );
    }

    private function canWriteProject(int $projectId): bool
    {
        [$w, $p] = $this->projectWriteScope();
        $p[':wp_id'] = $projectId;
        return (bool) Db::val("SELECT 1 FROM projects p WHERE p.id = :wp_id AND p.deleted_at IS NULL AND $w", $p);
    }

    private function projectWriteScope(): array
    {
        if (Rbac::can('worklog.view_all')) {
            return ['1=1', []];
        }
        $uid = Auth::id();
        return [
            "(p.site_manager_id = :ww_uid1 OR p.sales_user_id = :ww_uid2
              OR EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id = p.id AND pa.user_id = :ww_uid3))",
            [':ww_uid1' => $uid, ':ww_uid2' => $uid, ':ww_uid3' => $uid],
        ];
    }
}
