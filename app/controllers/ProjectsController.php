<?php
/**
 * 프로젝트(현장) 목록/상세/등록/파일. 데이터 범위는 Scope 헬퍼로 강제한다(IDOR 방지).
 */
class ProjectsController
{
    private const STATUSES = [
        'preparing'   => '준비중',
        'in_progress' => '진행중',
        'paused'      => '중단',
        'completed'   => '완료',
    ];
    private const IMPORTANCE = ['low' => '낮음', 'mid' => '보통', 'high' => '높음'];
    private const CONTRIB_MODE = ['main' => '주담당 100%', 'ratio' => '비율 직접입력', 'role' => '역할별 기본배분'];

    public static function statuses(): array { return self::STATUSES; }
    public static function importanceOptions(): array { return self::IMPORTANCE; }
    public static function contribModes(): array { return self::CONTRIB_MODE; }

    /** 목록: 검색·필터·정렬·페이지네이션 + 데이터 범위 강제. */
    public function index(): void
    {
        $q         = Util::str('q', '');
        $status    = Util::str('status', '');
        $managerId = (int) Util::int('manager_id', 0);
        $workType  = Util::str('work_type', '');
        $delayed   = Util::str('delayed', '') === '1';
        $page      = max(1, (int) Util::int('page', 1));

        $sortMap = [
            'project_no'      => 'p.project_no',
            'name'            => 'p.name',
            'start_date'      => 'p.start_date',
            'end_date'        => 'p.end_date',
            'contract_amount' => 'p.contract_amount',
            'progress'        => 'p.progress',
        ];
        $sortKey = Util::str('sort', 'end_date');
        if (!isset($sortMap[$sortKey])) {
            $sortKey = 'end_date';
        }
        $dir = strtolower(Util::str('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';

        [$scopeSql, $params] = Scope::projectWhere('p');
        $where = ['p.deleted_at IS NULL', $scopeSql];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(p.name LIKE :q1 OR c.name LIKE :q2 OR p.site_address LIKE :q3)';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }
        if ($status !== '' && isset(self::STATUSES[$status])) {
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        }
        if ($managerId > 0) {
            $where[] = 'p.site_manager_id = :mgr';
            $params[':mgr'] = $managerId;
        }
        if ($workType !== '') {
            $where[] = 'p.work_type = :wt';
            $params[':wt'] = $workType;
        }
        if ($delayed) {
            $where[] = "p.end_date IS NOT NULL AND p.end_date < CURDATE() AND p.status <> 'completed'";
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::val(
            "SELECT COUNT(*) FROM projects p JOIN customers c ON c.id = p.customer_id WHERE $whereSql",
            $params
        );
        $per = (int) setting('page_size', 20);
        $pg  = Util::paginate($total, $page, $per);

        $rows = Db::all(
            "SELECT p.*, c.name AS customer_name, sm.name AS site_manager_name
             FROM projects p
             JOIN customers c ON c.id = p.customer_id
             LEFT JOIN users sm ON sm.id = p.site_manager_id
             WHERE $whereSql
             ORDER BY {$sortMap[$sortKey]} $dir
             LIMIT " . (int) $pg['per'] . ' OFFSET ' . (int) $pg['offset'],
            $params
        );

        $managers = Db::all(
            "SELECT DISTINCT u.id, u.name FROM users u
             JOIN projects p2 ON p2.site_manager_id = u.id AND p2.deleted_at IS NULL
             ORDER BY u.name"
        );
        $workTypes = Db::run(
            "SELECT DISTINCT work_type FROM projects WHERE work_type IS NOT NULL AND work_type <> '' ORDER BY work_type"
        )->fetchAll(PDO::FETCH_COLUMN);

        View::render('projects/index', [
            'title'     => '프로젝트',
            'rows'      => $rows,
            'pg'        => $pg,
            'q'         => $q,
            'status'    => $status,
            'managerId' => $managerId,
            'workType'  => $workType,
            'delayed'   => $delayed,
            'sort'      => $sortKey,
            'dir'       => strtolower($dir),
            'managers'  => $managers,
            'workTypes' => $workTypes,
            'statuses'  => self::STATUSES,
        ]);
    }

    /** 상세: Scope::canAccessProject 가드. */
    public function show(): void
    {
        $id = (int) Util::int('id', 0);
        if (!$id || !Scope::canAccessProject($id)) {
            http_response_code(403);
            View::renderError(403, '접근 권한 없음', '이 프로젝트에 접근할 권한이 없습니다.');
            return;
        }

        $project = Db::one(
            "SELECT p.*, c.name AS customer_name, c.phone AS customer_phone, c.site_address AS customer_site_address,
                    sales.name AS sales_user_name, sm.name AS site_manager_name,
                    ps.name AS process_stage_name
             FROM projects p
             JOIN customers c ON c.id = p.customer_id
             LEFT JOIN users sales ON sales.id = p.sales_user_id
             LEFT JOIN users sm ON sm.id = p.site_manager_id
             LEFT JOIN process_stages ps ON ps.id = p.process_stage_id
             WHERE p.id = :id AND p.deleted_at IS NULL",
            [':id' => $id]
        );
        if (!$project) {
            http_response_code(403);
            View::renderError(403, '접근 권한 없음', '이 프로젝트에 접근할 권한이 없습니다.');
            return;
        }

        $contractAmount = (float) $project['contract_amount'];
        $estimatedCost  = (float) $project['estimated_cost'];
        $actualCost     = (float) Db::val(
            "SELECT COALESCE(SUM(amount),0) FROM costs WHERE project_id = :id AND type = 'actual'",
            [':id' => $id]
        );

        $calc = [
            'contract_amount'       => $contractAmount,
            'estimated_cost'        => $estimatedCost,
            'actual_cost'           => $actualCost,
            'estimated_profit'      => Calc::profit($contractAmount, $estimatedCost),
            'estimated_profit_rate' => Calc::profitRate($contractAmount, $estimatedCost),
            'actual_profit'         => Calc::profit($contractAmount, $actualCost),
            'actual_profit_rate'    => Calc::profitRate($contractAmount, $actualCost),
        ];

        $assignments = Db::all(
            "SELECT pa.*, u.name AS user_name, u.role_key
             FROM project_assignments pa JOIN users u ON u.id = pa.user_id
             WHERE pa.project_id = :id ORDER BY pa.created_at DESC",
            [':id' => $id]
        );

        $history = Db::all(
            "SELECT h.*, fs.name AS from_name, ts.name AS to_name, u.name AS changed_by_name
             FROM project_process_history h
             LEFT JOIN process_stages fs ON fs.id = h.from_stage_id
             JOIN process_stages ts ON ts.id = h.to_stage_id
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.project_id = :id ORDER BY h.changed_at DESC, h.id DESC",
            [':id' => $id]
        );

        $costs = Db::all(
            "SELECT * FROM costs WHERE project_id = :id ORDER BY spent_date DESC, created_at DESC",
            [':id' => $id]
        );

        $schedules = Db::all(
            "SELECT * FROM schedules WHERE project_id = :id ORDER BY start_datetime DESC LIMIT 20",
            [':id' => $id]
        );

        $workLogs = Db::all(
            "SELECT w.*, u.name AS user_name FROM work_logs w JOIN users u ON u.id = w.user_id
             WHERE w.project_id = :id ORDER BY w.work_date DESC LIMIT 20",
            [':id' => $id]
        );

        $files = Db::all(
            "SELECT f.*, u.name AS uploaded_by_name FROM project_files f
             LEFT JOIN users u ON u.id = f.uploaded_by
             WHERE f.project_id = :id AND f.entity_type = 'project'
             ORDER BY f.created_at DESC",
            [':id' => $id]
        );
        $photos = array_values(array_filter($files, fn($f) => str_starts_with((string) $f['mime'], 'image/')));
        $docs   = array_values(array_filter($files, fn($f) => !str_starts_with((string) $f['mime'], 'image/')));

        View::render('projects/show', [
            'title'       => $project['name'],
            'project'     => $project,
            'calc'        => $calc,
            'assignments' => $assignments,
            'history'     => $history,
            'costs'       => $costs,
            'schedules'   => $schedules,
            'workLogs'    => $workLogs,
            'photos'      => $photos,
            'docs'        => $docs,
            'statuses'    => self::STATUSES,
        ]);
    }

    /** 등록/수정 폼 (perm project.manage 은 라우터가 강제). */
    public function form(): void
    {
        $id = (int) Util::int('id', 0);
        $project = null;
        if ($id) {
            $project = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $id]);
            if (!$project) {
                Response::redirect('projects.index', [], '프로젝트를 찾을 수 없습니다.', 'error');
            }
        }

        $customers = Db::all(
            "SELECT id, name, company_name, type FROM customers WHERE deleted_at IS NULL ORDER BY name LIMIT 500"
        );
        $processStages = Db::all("SELECT id, name, sort_order FROM process_stages ORDER BY sort_order");
        $users = Db::all(
            "SELECT id, name, role_key FROM users WHERE status = 'active' AND deleted_at IS NULL ORDER BY name"
        );

        View::render('projects/form', [
            'title'         => $id ? '프로젝트 수정' : '프로젝트 등록',
            'project'       => $project,
            'customers'     => $customers,
            'processStages' => $processStages,
            'users'         => $users,
            'statuses'      => self::STATUSES,
            'importance'    => self::IMPORTANCE,
            'contribModes'  => self::CONTRIB_MODE,
        ]);
    }

    /** 등록/수정 저장 (perm project.manage). */
    public function save(): void
    {
        $id   = (int) Util::postInt('id', 0);
        $name = Util::postStr('name');
        $customerId = (int) Util::postInt('customer_id', 0);

        if ($name === '' || $customerId <= 0) {
            Response::redirect('projects.form', $id ? ['id' => $id] : [], '프로젝트명과 고객을 입력하세요.', 'error');
        }

        $status = Util::postStr('status', 'preparing');
        if (!isset(self::STATUSES[$status])) {
            $status = 'preparing';
        }
        $contribMode = Util::postStr('contribution_mode', 'main');
        if (!isset(self::CONTRIB_MODE[$contribMode])) {
            $contribMode = 'main';
        }
        $importance = Util::postStr('importance', 'mid');
        if (!isset(self::IMPORTANCE[$importance])) {
            $importance = 'mid';
        }
        $processStageId = Util::postInt('process_stage_id', 0);
        $salesUserId    = Util::postInt('sales_user_id', 0);
        $siteManagerId  = Util::postInt('site_manager_id', 0);
        $progress       = max(0, min(100, (int) Util::postInt('progress', 0)));

        $data = [
            'name'               => $name,
            'customer_id'        => $customerId,
            'site_address'       => Util::nullIfEmpty(Util::postStr('site_address')),
            'work_type'          => Util::nullIfEmpty(Util::postStr('work_type')),
            'contract_amount'    => (float) Util::postFloat('contract_amount', 0),
            'estimated_cost'     => (float) Util::postFloat('estimated_cost', 0),
            'process_stage_id'   => $processStageId > 0 ? $processStageId : null,
            'status'             => $status,
            'contract_date'      => Util::nullIfEmpty(Util::postStr('contract_date')),
            'start_date'         => Util::nullIfEmpty(Util::postStr('start_date')),
            'end_date'           => Util::nullIfEmpty(Util::postStr('end_date')),
            'actual_start_date'  => Util::nullIfEmpty(Util::postStr('actual_start_date')),
            'actual_end_date'    => Util::nullIfEmpty(Util::postStr('actual_end_date')),
            'sales_user_id'      => $salesUserId > 0 ? $salesUserId : null,
            'site_manager_id'    => $siteManagerId > 0 ? $siteManagerId : null,
            'progress'           => $progress,
            'importance'         => $importance,
            'contribution_mode'  => $contribMode,
            'memo'               => Util::nullIfEmpty(Util::postStr('memo')),
        ];

        if ($id) {
            $before = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $id]);
            if (!$before) {
                Response::redirect('projects.index', [], '프로젝트를 찾을 수 없습니다.', 'error');
            }
            Db::update('projects', $data, 'id = :id', [':id' => $id]);
            Audit::log('project_update', 'project', $id, $before, $data);
        } else {
            $data['project_no']  = $this->generateProjectNo();
            $data['actual_cost'] = 0;
            $id = Db::insert('projects', $data);
            Audit::log('project_create', 'project', $id, null, $data);
        }

        Response::redirect('projects.show', ['id' => $id], '저장되었습니다.');
    }

    /** soft delete (perm project.manage). */
    public function delete(): void
    {
        $id = (int) Util::postInt('id', 0);
        $before = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $id]);
        if (!$before) {
            Response::redirect('projects.index', [], '프로젝트를 찾을 수 없습니다.', 'error');
        }
        Db::update('projects', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
        Audit::log('project_delete', 'project', $id, $before, null);
        Response::redirect('projects.index', [], '삭제되었습니다.');
    }

    /** 파일 업로드(문서/현장사진) — 로그인만 필요, IDOR 은 Scope 로 직접 가드. */
    public function upload(): void
    {
        $projectId = (int) Util::postInt('project_id', 0);
        if (!$projectId || !Scope::canAccessProject($projectId)) {
            Response::error('이 프로젝트에 접근할 권한이 없습니다.', 403);
        }

        $category = Util::postStr('category', 'doc');
        if (!in_array($category, ['photo', 'doc'], true)) {
            $category = 'doc';
        }
        $allowed = $category === 'photo' ? Upload::imageExts() : Upload::docExts();

        $file = $_FILES['file'] ?? null;
        if (!$file) {
            Response::redirect('projects.show', ['id' => $projectId], '업로드할 파일을 선택하세요.', 'error');
        }

        try {
            $info = Upload::save($file, 'projects/' . $projectId, $allowed);
        } catch (\RuntimeException $e) {
            Response::redirect('projects.show', ['id' => $projectId], $e->getMessage(), 'error');
        }

        $fileId = Db::insert('project_files', [
            'project_id'    => $projectId,
            'entity_type'   => 'project',
            'entity_id'     => $projectId,
            'original_name' => $info['original_name'],
            'stored_name'   => $info['stored_name'],
            'path'          => $info['path'],
            'size'          => $info['size'],
            'mime'          => $info['mime'],
            'uploaded_by'   => Auth::id(),
        ]);
        Audit::log('file_upload', 'project_files', $fileId, null, [
            'project_id'    => $projectId,
            'original_name' => $info['original_name'],
            'category'      => $category,
        ]);

        Response::redirect('projects.show', ['id' => $projectId], '파일이 업로드되었습니다.');
    }

    /** 파일 다운로드 — Upload::send + Scope::canAccessProject 권한 콜백. */
    public function download(): void
    {
        $fileId = (int) Util::int('id', 0);
        if (!$fileId) {
            http_response_code(404);
            exit('파일을 찾을 수 없습니다.');
        }
        Upload::send($fileId, function (array $f): bool {
            if (empty($f['project_id'])) {
                return Rbac::can('project.view_all');
            }
            return Scope::canAccessProject((int) $f['project_id']);
        });
    }

    private function generateProjectNo(): string
    {
        $year = date('Y');
        for ($i = 0; $i < 5; $i++) {
            $count = (int) Db::val(
                "SELECT COUNT(*) FROM projects WHERE project_no LIKE :p",
                [':p' => "P{$year}-%"]
            );
            $no = sprintf('P%s-%04d', $year, $count + 1 + $i);
            $exists = Db::val("SELECT 1 FROM projects WHERE project_no = :no", [':no' => $no]);
            if (!$exists) {
                return $no;
            }
        }
        return 'P' . $year . '-' . substr((string) uniqid(), -6);
    }
}
