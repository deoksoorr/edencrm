<?php
/**
 * 프로젝트(현장) 목록/상세/등록/파일. 데이터 범위는 Scope 헬퍼로 강제한다(IDOR 방지).
 */
class ProjectsController
{
    /** 프로젝트 상태 8종 — StatusService 단일 출처(브리프 §2 확정 enum). */
    private const STATUSES = StatusService::PROJECT_LABELS;
    /** 폼(등록/수정)에서 직접 선택 가능한 상태 — 취소/파기/완료/정산 등은 상태 전환 플로우(projects.transition)로만. */
    private const FORM_STATUSES = ['preparing', 'in_progress', 'paused'];
    private const CONTRIB_MODE = ['main' => '주담당 100%', 'ratio' => '비율 직접입력', 'role' => '역할별 기본배분'];

    public static function statuses(): array { return self::STATUSES; }
    public static function contribModes(): array { return self::CONTRIB_MODE; }

    /** 목록: 검색·필터·정렬·페이지네이션 + 데이터 범위 강제. */
    public function index(): void
    {
        $q         = Util::str('q', '');
        $status    = Util::str('status', '');
        $managerId = (int) Util::int('manager_id', 0);
        $workType  = Util::str('work_type', '');
        $delayed   = Util::str('delayed', '') === '1';
        // 등록 유형 필터: 전체 / 일반(계약 자동 생성) / 예외(수동 생성 — is_exception=1)
        $regType   = Util::str('reg_type', 'all');
        if (!in_array($regType, ['all', 'normal', 'exception'], true)) {
            $regType = 'all';
        }
        // R11 입금·정산 필터: 미입금/일부 입금/완납/미수금 있음/정산 완료/정산 보류
        $payFilter = Util::str('pay', '');
        if (!in_array($payFilter, ['none', 'partial', 'paid', 'outstanding', 'settled', 'hold'], true)) {
            $payFilter = '';
        }
        $page      = max(1, (int) Util::int('page', 1));

        // R14: 총액(예외=계약총액 우선·레거시 fallback, 일반=계약 총액)·순입금(계약 입금 + 프로젝트 직접 입금) SQL 식 — 목록·필터 공용
        $expectedExpr = "CASE WHEN p.is_exception = 1 AND p.contract_id IS NULL
                              THEN COALESCE(NULLIF(p.contract_amount, 0), p.expected_amount, 0) ELSE p.contract_amount END";
        $paidExpr = "(COALESCE((SELECT SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)
                        FROM payments pm WHERE pm.contract_id = p.contract_id AND pm.status='paid'),0)
                    + COALESCE((SELECT SUM(CASE WHEN pm2.kind='refund' THEN -pm2.amount ELSE pm2.amount END)
                        FROM payments pm2 WHERE pm2.project_id = p.id AND pm2.status='paid'),0))";

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

        // R16: 휴지통 목록은 최고운영자 전용 — trash=1 진입 자체를 403 으로 끊는다(일반 목록 폴백 금지).
        $trash = Util::int('trash', 0) === 1;
        if ($trash) {
            Perm::requireSuperAdmin('projects.trash');
        }
        [$scopeSql, $params] = Scope::projectWhere('p');
        $where = [$trash ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL', $scopeSql];

        if ($q !== '') {
            $like = '%' . $q . '%';
            // 예외 프로젝트(고객 미연결)는 스냅샷 고객명으로도 검색
            $where[] = '(p.name LIKE :q1 OR c.name LIKE :q2 OR p.site_address LIKE :q3 OR p.customer_name_snapshot LIKE :q4)';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
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
            // 대시보드 delayedCond 와 동일 기준(완료·정산·취소·파기 제외, 준공 처리 전) — KPI 건수와 목록 일치
            $where[] = "p.end_date IS NOT NULL AND p.end_date < CURDATE() AND p.actual_end_date IS NULL AND p.status NOT IN ('completed','settled','cancelled','terminated')";
        }
        if ($regType !== 'all') {
            $where[] = 'p.is_exception = ' . ($regType === 'exception' ? 1 : 0);
        }
        // R11 입금·정산 필터 — 목록 표시 컬럼과 동일 식(단일 출처)
        switch ($payFilter) {
            case 'none':        $where[] = "$paidExpr <= 0"; break;
            case 'partial':     $where[] = "$paidExpr > 0 AND $paidExpr < $expectedExpr"; break;
            case 'paid':        $where[] = "$expectedExpr > 0 AND $paidExpr >= $expectedExpr"; break;
            case 'outstanding': $where[] = "$expectedExpr - $paidExpr > 0"; break;
            case 'settled':     $where[] = "p.settlement_status = 'settled'"; break;
            case 'hold':        $where[] = "p.settlement_status = 'hold'"; break;
        }
        $whereSql = implode(' AND ', $where);

        // 예외 프로젝트는 customer_id NULL 가능 — LEFT JOIN(누락 방지)
        $total = (int) Db::val(
            "SELECT COUNT(*) FROM projects p LEFT JOIN customers c ON c.id = p.customer_id WHERE $whereSql",
            $params
        );
        $per = (int) setting('page_size', 20);
        $pg  = Util::paginate($total, $page, $per);

        $rows = Db::all(
            "SELECT p.*, COALESCE(c.name, p.customer_name_snapshot) AS customer_name, sm.name AS site_manager_name,
                    $expectedExpr AS pay_expected, $paidExpr AS pay_net
             FROM projects p
             LEFT JOIN customers c ON c.id = p.customer_id
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
            'regType'   => $regType,
            'payFilter' => $payFilter,
            'sort'      => $sortKey,
            'dir'       => strtolower($dir),
            'managers'  => $managers,
            'workTypes' => $workTypes,
            'statuses'  => self::STATUSES,
            'canFinance' => Rbac::can('finance.view') || Rbac::can('cost.manage'),
            'trash'     => $trash,
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
            "SELECT p.*, COALESCE(c.name, p.customer_name_snapshot) AS customer_name, c.phone AS customer_phone, c.site_address AS customer_site_address,
                    sales.name AS sales_user_name, sm.name AS site_manager_name,
                    ps.name AS process_stage_name, ps.color AS process_stage_color, ps.sort_order AS process_stage_sort
             FROM projects p
             LEFT JOIN customers c ON c.id = p.customer_id
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
        // 원가 총액 = 확정(confirmed) actual 비용 합 — projects.actual_cost 캐시와 동일 기준(CostService 단일 출처)
        $costSub    = CostService::subtotals($id);
        $actualCost = (float) $costSub['total'];

        $supply = AccountingService::supplyOf($project);
        $calc = [
            'contract_amount'       => $contractAmount,
            'supply_amount'         => $supply,
            'vat_amount'            => AccountingService::vatOf($project),
            'estimated_cost'        => $estimatedCost,
            'actual_cost'           => $actualCost,
            'estimated_profit'      => Calc::profit($supply, $estimatedCost),
            'estimated_profit_rate' => Calc::profitRate($supply, $estimatedCost),
            'actual_profit'         => Calc::profit($supply, $actualCost),
            'actual_profit_rate'    => Calc::profitRate($supply, $actualCost),
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

        // 상태 이력(취소·파기·중단·복구·완료·정산 전환 기록)
        $statusHistory = Db::all(
            "SELECT h.*, u.name AS changed_by_name FROM project_status_history h
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.project_id = :id ORDER BY h.changed_at DESC, h.id DESC",
            [':id' => $id]
        );

        // ── R3 탭 재설계 데이터 ──
        // 연결 계약(개요 탭 — contracts.show 왕복 링크 + 견적 전환 정보, contractflow 요청사항)
        $contract = null;
        if (!empty($project['contract_id'])) {
            $contract = Db::one(
                "SELECT c.id, c.contract_no, c.status, c.contract_amount, c.supply_amount, c.contract_date,
                        c.quote_id, c.quote_version_id, c.original_quote_amount, c.adjust_amount, c.converted_at,
                        q.quote_no, cb.name AS converted_by_name
                 FROM contracts c
                 LEFT JOIN quotes q ON q.id = c.quote_id
                 LEFT JOIN users cb ON cb.id = c.converted_by
                 WHERE c.id = :cid AND c.deleted_at IS NULL",
                [':cid' => (int) $project['contract_id']]
            );
        }
        // 다음 일정(개요 탭)
        $nextSchedule = Db::one(
            "SELECT id, title, start_datetime, end_datetime FROM schedules
             WHERE project_id = :id AND start_datetime >= NOW()
             ORDER BY start_datetime ASC LIMIT 1",
            [':id' => $id]
        );
        // 공정 탭: 진행 현황 단계 목록(현재 위치 표시용). R14: 수동 이동 select 는 폐지 —
        // 공정 진행은 공정 보드의 카드 게이지(process.progress.set, ProcessService::setStageProgress 경유)에서 조정한다.
        // R8-A: 프로젝트 공사 유형(미지정→painting) + 공통의 활성 단계만 — gaugeStages()와 동일 기준 집합(공통 포함).
        $projConstructionType = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        $processStages = Db::all(
            "SELECT id, stage_key, name, sort_order, color FROM process_stages
             WHERE (process_type = :t OR process_type = 'common') AND is_active = 1
             ORDER BY sort_order, id",
            [':t' => $projConstructionType]
        );
        // 공정 탭: 하자보수 목록(R4 T3 — warranty_repairs, 사진은 project_files entity_type='warranty_repair')
        $warrantyRepairs = Db::all(
            "SELECT w.*, ru.name AS requested_by_name, au.name AS assignee_name
             FROM warranty_repairs w
             LEFT JOIN users ru ON ru.id = w.requested_by
             LEFT JOIN users au ON au.id = w.assignee_id
             WHERE w.project_id = :id
             ORDER BY FIELD(w.status,'open','in_progress','done'), w.requested_at DESC, w.id DESC",
            [':id' => $id]
        );
        $warrantyPhotos = [];
        if ($warrantyRepairs) {
            $in = implode(',', array_fill(0, count($warrantyRepairs), '?'));
            $rows = Db::all(
                "SELECT id, entity_id, original_name FROM project_files
                 WHERE entity_type = 'warranty_repair' AND entity_id IN ($in) ORDER BY id",
                array_map(static fn($w) => (int) $w['id'], $warrantyRepairs)
            );
            foreach ($rows as $r) {
                $warrantyPhotos[(int) $r['entity_id']][] = $r;
            }
        }
        // ── R11: '입금·정산' 탭 데이터 — 요약(공통 산식) + 입금 행 + 변경 이력(감사 발췌) ──
        load_controller('ContractsController'); // pay_type 라벨 단일 출처 재사용(대시보드와 동일 패턴)
        $payTypeLabels = ContractsController::PAY_TYPE_LABELS;
        $paySummary = AccountingService::projectPaySummary($project);
        $isExceptionLedger = (int) $project['is_exception'] === 1 && empty($project['contract_id']);
        if ($isExceptionLedger) {
            $projectPayments = Db::all(
                "SELECT pm.*, u.name AS created_by_name FROM payments pm
                 LEFT JOIN users u ON u.id = pm.created_by
                 WHERE pm.project_id = :id
                 ORDER BY (pm.paid_date IS NULL), pm.paid_date DESC, pm.id DESC",
                [':id' => $id]
            );
        } elseif (!empty($project['contract_id'])) {
            // 일반 프로젝트: 연결 계약 입금 내역 연동 표시(관리는 계약 화면)
            $projectPayments = Db::all(
                "SELECT pm.*, u.name AS created_by_name FROM payments pm
                 LEFT JOIN users u ON u.id = pm.created_by
                 WHERE pm.contract_id = :cid
                 ORDER BY (pm.due_date IS NULL), pm.due_date ASC, pm.id ASC",
                [':cid' => (int) $project['contract_id']]
            );
        } else {
            $projectPayments = [];
        }
        // 변경 이력: 입금 생성·수정·취소 + 예정 금액·정산 상태 변경(이 화면에 이미 노출되는 범위의 감사 발췌)
        $payIds = array_map(static fn($r) => (int) $r['id'], $projectPayments);
        $auditCond = "(a.entity = 'project' AND a.entity_id = :pid
                       AND a.action IN ('project_expected_amount_change','project_settlement_change'))";
        $auditParams = [':pid' => $id];
        if ($payIds) {
            $auditCond .= ' OR (a.entity = \'payments\' AND a.entity_id IN (' . implode(',', $payIds) . '))';
        }
        $settleAudit = Db::all(
            "SELECT a.action, a.entity, a.entity_id, a.before_json, a.after_json, a.created_at, u.name AS user_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE $auditCond
             ORDER BY a.created_at DESC, a.id DESC LIMIT 30",
            $auditParams
        );

        // 이력 탭: 감사 로그 발췌(audit.view 권한자만, 최근 20건 — 전체는 감사 로그 화면)
        $auditRows = Rbac::can('audit.view') ? Db::all(
            "SELECT a.id, a.action, a.created_at, u.name AS user_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity = 'project' AND a.entity_id = :id
             ORDER BY a.created_at DESC, a.id DESC LIMIT 20",
            [':id' => $id]
        ) : null;

        // ── 원가 관리 목록: 비용 유형·작업자·기간 필터 + 서버 사이드 페이지네이션 ──
        $costFilters = CostService::listFilters();
        [$costWhere, $costParams] = CostService::filterWhere($id, $costFilters);
        $costTotalRows = (int) Db::val("SELECT COUNT(*) FROM costs c WHERE $costWhere", $costParams);
        $costPg = Util::paginate($costTotalRows, max(1, (int) Util::int('cost_page', 1)), 20);
        $costs = Db::all(
            "SELECT c.*, u.name AS worker_user_name FROM costs c
             LEFT JOIN users u ON u.id = c.worker_id
             WHERE $costWhere
             ORDER BY c.spent_date DESC, c.id DESC
             LIMIT {$costPg['per']} OFFSET {$costPg['offset']}",
            $costParams
        );
        // 작업자 필터 옵션(이 프로젝트 비용에 등장한 작업자: 직원=id, 외부=이름)
        $costWorkers = Db::all(
            "SELECT DISTINCT COALESCE(CAST(c.worker_id AS CHAR), c.worker_name) AS wkey,
                    COALESCE(u.name, c.worker_name) AS wname
             FROM costs c LEFT JOIN users u ON u.id = c.worker_id
             WHERE c.project_id = :id AND (c.worker_id IS NOT NULL OR c.worker_name IS NOT NULL)
             ORDER BY wname",
            [':id' => $id]
        );
        // 인건비 입력 폼 + 일정 인라인 폼(참여 직원, 개인색 dot)의 직원 선택 목록 (R4 T8: color 추가)
        $staffOptions = Db::all(
            "SELECT id, name, position, color FROM users WHERE deleted_at IS NULL AND status='active' ORDER BY name"
        );

        // 직원·일정 탭 초기 렌더용(R4 T8) — 슬롯(schedule_time_slots)·참여자명 포함.
        // 이후 갱신은 schedule.data(project_id 필터) AJAX 가 동일 테이블에서 다시 로드한다(캘린더와 동일 원천).
        $schedules = Db::all(
            "SELECT s.id, s.title, s.event_date, COALESCE(s.end_date, s.event_date) AS end_date, s.slot, s.type, s.status, s.memo,
                    GROUP_CONCAT(DISTINCT st.slot ORDER BY FIELD(st.slot,'morning','afternoon','night')) AS slot_keys,
                    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS participant_names
             FROM schedules s
             LEFT JOIN schedule_time_slots st ON st.schedule_id = s.id
             LEFT JOIN schedule_participants sp ON sp.schedule_id = s.id
             LEFT JOIN users u ON u.id = sp.user_id
             WHERE s.project_id = :id
             GROUP BY s.id
             ORDER BY s.event_date DESC, s.id DESC LIMIT 20",
            [':id' => $id]
        );

        $wl = Settings::enabled('feature_worklog');
        $workLogs = $wl ? Db::all(
            "SELECT w.*, u.name AS user_name FROM work_logs w JOIN users u ON u.id = w.user_id
             WHERE w.project_id = :id ORDER BY w.work_date DESC LIMIT 20",
            [':id' => $id]
        ) : [];

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
            'costSub'     => $costSub,
            'costPg'      => $costPg,
            'costFilters' => $costFilters,
            'costWorkers' => $costWorkers,
            'staffOptions' => $staffOptions,
            'schedules'   => $schedules,
            'workLogs'    => $workLogs,
            'photos'      => $photos,
            'docs'        => $docs,
            'statuses'    => self::STATUSES,
            'statusBadge' => StatusService::PROJECT_BADGE,
            'statusHistory' => $statusHistory,
            'allowedTransitions' => StatusService::PROJECT_TRANSITIONS[$project['status']] ?? [],
            'wl'          => $wl,
            'contract'    => $contract,
            'nextSchedule' => $nextSchedule,
            'processStages' => $processStages,
            'warrantyRepairs' => $warrantyRepairs,
            'warrantyPhotos'  => $warrantyPhotos,
            'auditRows'   => $auditRows,
            // R11: 입금·정산 탭
            'paySummary'        => $paySummary,
            'projectPayments'   => $projectPayments,
            'isExceptionLedger' => $isExceptionLedger,
            'settleAudit'       => $settleAudit,
            'payMethods'        => AccountingService::PAYMENT_METHODS,
            'payTypeLabels'     => $payTypeLabels,
            'canPayment'        => Rbac::can('payment.manage'),
        ]);
    }

    /**
     * 상태 전환(취소/파기/중단/재개·복구/완료/정산 완료) — 전이 규칙(StatusService)을 서버측에서 강제.
     * 파기·취소 시 부가정보(청구·환불 금액/정산 여부/후속 조치)를 이력 detail_json 에 보존,
     * 환불은 연결 계약의 payments(kind='refund') 행으로 기록한다. 물리 삭제 없음.
     */
    public function transition(): void
    {
        $id = (int) Util::postInt('id', 0);
        if (!$id || !Scope::canAccessProject($id)) {
            Response::error('이 프로젝트에 접근할 권한이 없습니다.', 403);
        }
        $project = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $id]);
        if (!$project) {
            Response::error('프로젝트를 찾을 수 없습니다.', 404);
        }

        $to = Util::postStr('to_status');
        if (!isset(self::STATUSES[$to])) {
            Response::error('알 수 없는 상태입니다.', 422);
        }
        $from = (string) $project['status'];
        if ($from === $to) {
            Response::error('이미 해당 상태입니다.', 422);
        }
        if (!StatusService::projectTransitionAllowed($from, $to)) {
            Response::error('허용되지 않는 상태 전환입니다: ' . self::STATUSES[$from] . ' → ' . self::STATUSES[$to], 422);
        }

        $date   = Util::dateOrNull(Util::postStr('effective_date')) ?? date('Y-m-d');
        $reason = Util::postStr('reason');
        if ($reason === '' && StatusService::reasonRequired($from, $to)) {
            Response::error('이 전환은 처리 사유가 필요합니다.', 422);
        }

        // R12: 프로젝트 상태 '정산 완료(settled)' 전환은 UI에서 제거(정산 완료는 '입금·정산' 탭으로 일원화).
        //      혹시 직접 POST 되어도 projectTransitionAllowed 가 거부하므로 여기서 별도 처리하지 않는다.

        // 파기·취소 부가정보(브리프: 처리일/사유/처리자/진행 공정·발생 원가(표시)/청구·환불/정산 여부/후속 조치/메모)
        $detail = null;
        $refund = 0;
        if (in_array($to, ['cancelled', 'terminated'], true)) {
            $refund = max(0, (int) round((float) Util::postFloat('refund_amount', 0)));
            $detail = array_filter([
                'effective_date' => $date,
                'billed_amount'  => max(0, (int) round((float) Util::postFloat('billed_amount', 0))),
                'refund_amount'  => $refund,
                'is_settled'     => Util::postStr('is_settled') === '1' ? 1 : 0,
                'followup'       => Util::nullIfEmpty(Util::postStr('followup')),
                'memo'           => Util::nullIfEmpty(Util::postStr('memo')),
                'process_stage'  => $project['process_stage_id'],
                'actual_cost'    => (int) $project['actual_cost'],
            ], static fn($v) => $v !== null);

            if ($refund > 0 && $project['contract_id']) {
                // 환불 상한 = 계약 순입금 — AccountingService 단일 출처
                $netPaid = AccountingService::contractNetPaid((int) $project['contract_id']);
                if ($refund > $netPaid) {
                    Response::error('환불 금액이 계약 순입금(' . number_format($netPaid) . '원)을 초과할 수 없습니다.', 422);
                }
            }
        }

        Db::transaction(function () use ($project, $to, $date, $reason, $detail, $refund) {
            StatusService::applyProjectStatus($project, $to, [
                'effective_date' => $date,
                'reason'         => $reason,
                'detail'         => $detail,
            ]);
            if ($refund > 0 && $project['contract_id']) {
                Db::insert('payments', [
                    'contract_id' => (int) $project['contract_id'],
                    'pay_type'    => 'etc',
                    'kind'        => 'refund',
                    'amount'      => $refund,
                    'due_date'    => null,
                    'paid_date'   => $date,
                    'status'      => 'paid',
                    'memo'        => '프로젝트 ' . ($to === 'cancelled' ? '취소' : '파기') . ' 환불(' . $project['project_no'] . ')',
                ]);
            }
        });
        if ($refund > 0 && $project['contract_id']) {
            StatusService::recalcContractPaymentStatus((int) $project['contract_id']);
        }

        if (Response::wantsJson()) {
            Response::json(['id' => $id, 'status' => $to]);
        }
        Response::redirect('projects.show', ['id' => $id], '상태가 \'' . self::STATUSES[$to] . '\'(으)로 변경되었습니다.');
    }

    /**
     * 등록/수정 폼 (perm project.manage 은 라우터가 강제).
     * R3: 프로젝트는 계약 '진행(active)' 전환 시 자동 생성된다 — 신규 등록은 최고 관리자(super_admin)의
     * '예외 프로젝트 생성'(계약 연결 없는 하자보수·내부 작업용, 생성 사유 필수)만 허용. CSS 숨김이 아닌 서버측 차단.
     */
    public function form(): void
    {
        $id = (int) Util::int('id', 0);
        $project = null;
        if ($id) {
            $project = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $id]);
            if (!$project) {
                Response::redirect('projects.index', [], '프로젝트를 찾을 수 없습니다.', 'error');
            }
        } elseif (!Rbac::isRole('super_admin')) {
            Audit::log('access_denied', 'project', null, null, ['action' => 'project_exception_create_form']);
            http_response_code(403);
            View::renderError(403, '접근 권한 없음',
                '프로젝트는 계약 \'진행\' 전환 시 자동 생성됩니다. 예외 프로젝트 생성(하자보수·내부 작업)은 최고 관리자만 가능합니다.');
            return;
        }

        $customers = Db::all(
            "SELECT id, name, company_name, type FROM customers WHERE deleted_at IS NULL ORDER BY name LIMIT 500"
        );
        $users = Db::all(
            "SELECT id, name, role_key FROM users WHERE status = 'active' AND deleted_at IS NULL ORDER BY name"
        );

        // 폼에서는 기본 상태(진행 예정/진행 중/일시 중단)만 선택 — 취소/파기/완료/정산은 상세의 상태 전환으로만
        $formStatuses = array_intersect_key(self::STATUSES, array_flip(self::FORM_STATUSES));
        if ($project && !isset($formStatuses[$project['status']])) {
            $formStatuses = [$project['status'] => (self::STATUSES[$project['status']] ?? $project['status']) . ' — 상세 화면 상태 전환으로만 변경'];
        }

        View::render('projects/form', [
            'title'         => $id ? '프로젝트 수정' : '예외 프로젝트 생성',
            'project'       => $project,
            'customers'     => $customers,
            'users'         => $users,
            'statuses'      => $formStatuses,
            'contribModes'  => self::CONTRIB_MODE,
        ]);
    }

    /**
     * 등록/수정 저장 (perm project.manage).
     * 신규 등록 = 예외 프로젝트 생성(최고 관리자 전용, 생성 사유 필수, 계약 연결 없음 — Audit 기록).
     * 공정 단계(process_stage_id)는 폼에서 직접 세팅하지 않는다 — ProcessService 경유만 허용(R3 커널).
     */
    public function save(): void
    {
        $id   = (int) Util::postInt('id', 0);
        $name = Util::postStr('name');
        $customerId = (int) Util::postInt('customer_id', 0);
        // 예외 프로젝트: 기존 고객 미연결 시 고객명 직접 입력(스냅샷) 허용
        $customerNameInput = Util::postStr('customer_name_snapshot');

        // 예외 프로젝트 생성: 최고 관리자 전용 + 생성 사유 필수
        $createReason = Util::postStr('create_reason');
        if (!$id) {
            if (!Rbac::isRole('super_admin')) {
                Audit::log('access_denied', 'project', null, null, ['action' => 'project_exception_create']);
                if (Response::wantsJson()) {
                    Response::error('예외 프로젝트 생성은 최고 관리자만 가능합니다. (프로젝트는 계약 \'진행\' 전환 시 자동 생성)', 403);
                }
                http_response_code(403);
                View::renderError(403, '접근 권한 없음',
                    '프로젝트는 계약 \'진행\' 전환 시 자동 생성됩니다. 예외 프로젝트 생성은 최고 관리자만 가능합니다.');
                return;
            }
            if ($createReason === '') {
                Response::redirect('projects.form', [], '예외 프로젝트 생성 사유를 입력하세요.', 'error');
            }
        }

        // 수정 시 기존 행 선로드 — 예외 여부(is_exception)에 따라 고객 검증이 달라진다
        $before = null;
        if ($id) {
            $before = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $id]);
            if (!$before) {
                Response::redirect('projects.index', [], '프로젝트를 찾을 수 없습니다.', 'error');
            }
        }
        // 신규 등록 경로 = 항상 예외 프로젝트(일반 프로젝트는 계약 '진행' 전환 시 자동 생성)
        $isException = $id ? ((int) $before['is_exception'] === 1) : true;

        // 예외→일반 전환(수정 폼 체크박스): 예외 생성과 동일 게이트(최고 관리자) + 기존 고객 연결 필수
        $convertToNormal = false;
        if ($id && $isException && Util::postStr('convert_to_normal') === '1') {
            if (!Rbac::isRole('super_admin')) {
                Audit::log('access_denied', 'project', $id, null, ['action' => 'project_exception_convert']);
                Response::error('예외 프로젝트의 일반 전환은 최고 관리자만 가능합니다.', 403);
            }
            $convertToNormal = true;
        }

        if ($name === '') {
            Response::redirect('projects.form', $id ? ['id' => $id] : [], '프로젝트명을 입력하세요.', 'error');
        }
        if ($isException && !$convertToNormal) {
            // 예외 프로젝트: 기존 고객 연결 또는 고객명 직접 입력 중 하나는 필수
            if ($customerId <= 0 && $customerNameInput === '') {
                Response::redirect('projects.form', $id ? ['id' => $id] : [], '고객을 선택하거나 고객명을 입력하세요.', 'error');
            }
        } elseif ($customerId <= 0) {
            // 일반 프로젝트(예외→일반 전환 포함): 기존 고객 연결 필수
            Response::redirect('projects.form', $id ? ['id' => $id] : [],
                $convertToNormal ? '일반 프로젝트로 전환하려면 기존 고객을 선택하세요.' : '프로젝트명과 고객을 입력하세요.', 'error');
        }

        // 고객 스냅샷: 고객 연결 시 저장 시점 고객명으로 항상 채움, 미연결 예외는 직접 입력값 사용
        $customerName = null;
        if ($customerId > 0) {
            $customerName = Db::val(
                "SELECT name FROM customers WHERE id = :cid AND deleted_at IS NULL",
                [':cid' => $customerId]
            );
            if (!$customerName) {
                Response::redirect('projects.form', $id ? ['id' => $id] : [], '선택한 고객을 찾을 수 없습니다.', 'error');
            }
        }
        $snapshot = $customerId > 0
            ? mb_substr((string) $customerName, 0, 150)
            : ($customerNameInput !== '' ? mb_substr($customerNameInput, 0, 150) : null);

        $status = Util::postStr('status', 'preparing');
        if (!in_array($status, self::FORM_STATUSES, true)) {
            $status = 'preparing';
        }
        // R10: 기본값을 스키마·계약 자동생성과 동일한 ratio 로 통일 — main 폴백이 기여도 100 강제의 유발 경로였음
        $contribMode = Util::postStr('contribution_mode', 'ratio');
        if (!isset(self::CONTRIB_MODE[$contribMode])) {
            $contribMode = 'ratio';
        }
        $salesUserId    = Util::postInt('sales_user_id', 0);
        $siteManagerId  = Util::postInt('site_manager_id', 0);
        $progress       = max(0, min(100, (int) Util::postInt('progress', 0)));
        // R8-A: 공사유형(구분) — 화이트리스트 밖(빈값 포함)은 null(수정 시 기존 값 유지, 신규는 미지정)
        $constructionType = Util::postStr('construction_type', '');
        $constructionType = array_key_exists($constructionType, Stages::constructionTypes()) ? $constructionType : null;

        $data = [
            'name'               => mb_substr($name, 0, 150),
            'customer_id'        => $customerId > 0 ? $customerId : null, // 예외 프로젝트는 미연결(NULL) 허용
            'is_exception'       => ($isException && !$convertToNormal) ? 1 : 0,
            'customer_name_snapshot' => $snapshot,
            'site_address'       => Util::nullIfEmpty(mb_substr(Util::postStr('site_address'), 0, 255)),
            'work_type'          => Util::nullIfEmpty(mb_substr(Util::postStr('work_type'), 0, 50)),
            'construction_type'  => $constructionType, // R8-A: 도장/인테리어(미지정 NULL 은 양쪽 보드 노출)
            // 금액 클램프(음수·비정상 초과값이 DECIMAL 오버플로/오입력으로 이어지는 것 방지)
            'contract_amount'    => min(999999999999, max(0, (int) round((float) Util::postFloat('contract_amount', 0)))),
            'estimated_cost'     => min(999999999999, max(0, (int) round((float) Util::postFloat('estimated_cost', 0)))),
            // process_stage_id 직접 세팅 금지(R3 커널) — 공정 이동은 공정 보드/ProcessService 로만
            'status'             => $status,
            // 날짜: 단일 출처 Util::dateOrNull — 잘못된 입력(예: 'abc')이 DATE 컬럼 SQL 오류(500)로 번지는 것 방지
            'contract_date'      => Util::dateOrNull(Util::postStr('contract_date')),
            'start_date'         => Util::dateOrNull(Util::postStr('start_date')),
            'end_date'           => Util::dateOrNull(Util::postStr('end_date')),
            'actual_start_date'  => Util::dateOrNull(Util::postStr('actual_start_date')),
            'actual_end_date'    => Util::dateOrNull(Util::postStr('actual_end_date')),
            'sales_user_id'      => $salesUserId > 0 ? $salesUserId : null,
            'site_manager_id'    => $siteManagerId > 0 ? $siteManagerId : null,
            'progress'           => $progress,
            'contribution_mode'  => $contribMode,
            'memo'               => Util::nullIfEmpty(Util::postStr('memo')),
        ];

        $split = AccountingService::computeSplit((int) $data['contract_amount'], null);
        $data['supply_amount'] = $split['supply'];
        $data['vat_amount']    = $split['vat'];

        // 저장 write 구간(공정 초기화·감사로그 포함) — 예기치 못한 예외로 500 대신 폼 플래시 복귀
        try {
            if ($id) {
                // R8-A: 공사유형 미전송·무효면 기존 값 유지(레거시 미지정 프로젝트의 다른 필드 수정 허용)
                if ($constructionType === null) {
                    $data['construction_type'] = $before['construction_type'];
                }
                $from = (string) $before['status'];
                if (!in_array($from, self::FORM_STATUSES, true)) {
                    // 종결·전환 전용 상태(완료/취소/파기/정산 등)는 폼에서 변경 불가 — 상태 전환 플로우로만
                    $data['status'] = $status = $from;
                } elseif ($from !== $status && !StatusService::projectTransitionAllowed($from, $status)) {
                    // 허용되지 않는 전환은 무시(기존 상태 유지)
                    $data['status'] = $status = $from;
                }
                // 담당 영업 잠금(R7 T2 확장): 관리자(super_admin) 외 변경 불가 — 성과·수주 귀속
                // (projects.sales_user_id) 조작 경로 차단. 계약 화면(contracts.save)과 동일 기준·감사로그.
                $beforeSales = $before['sales_user_id'] !== null ? (int) $before['sales_user_id'] : null;
                if (!Rbac::isRole('super_admin')) {
                    $data['sales_user_id'] = $beforeSales;
                } elseif ($beforeSales !== $data['sales_user_id']) {
                    Audit::log('project_sales_user_change', 'project', $id,
                        ['sales_user_id' => $beforeSales], ['sales_user_id' => $data['sales_user_id']]);
                }
                Db::update('projects', $data, 'id = :id', [':id' => $id]);
                if ($from !== $status) {
                    StatusService::logProjectStatus($id, $from, $status, '프로젝트 수정 화면에서 변경');
                }
                Audit::log('project_update', 'project', $id, $before, $data);
                // 예외→일반 전환: 별도 감사 로그(전환 전/후 고객 연결 상태 보존)
                if ($convertToNormal) {
                    Audit::log('project_exception_convert', 'project', $id,
                        [
                            'is_exception'           => 1,
                            'customer_id'            => $before['customer_id'] !== null ? (int) $before['customer_id'] : null,
                            'customer_name_snapshot' => $before['customer_name_snapshot'],
                        ],
                        [
                            'is_exception'           => 0,
                            'customer_id'            => $customerId,
                            'customer_name_snapshot' => $snapshot,
                            'converted_by'           => Auth::id(),
                            'at'                     => date('Y-m-d H:i:s'),
                        ]);
                }
                // R8-A: 공사 유형 변경 시 스테이지 정합 — 현재 공정이 다른 유형 전용 단계면 '대기중' 재배치
                //       (process.settype 과 동일한 ProcessService 공통 헬퍼, 이력 is_auto=1 기록)
                if (($before['construction_type'] ?? null) !== $data['construction_type']) {
                    ProcessService::ensureStageMatchesType($id, Auth::id() ?: null);
                }
            } else {
                $data['project_no']  = $this->generateProjectNo();
                $data['actual_cost'] = 0;
                $id = Db::insert('projects', $data);
                // 예외 생성 프로젝트가 '진행 중'이면 공정 '대기중' 배치(ProcessService 경유 — 이력·entered_at 일관)
                if ($status === 'in_progress') {
                    ProcessService::initWaiting($id, Auth::id() ?: null, false, '예외 프로젝트 생성');
                }
                StatusService::logProjectStatus($id, null, $status, '예외 프로젝트 생성: ' . $createReason);
                Audit::log('project_exception_create', 'project', $id, null, $data + [
                    'create_reason' => mb_substr($createReason, 0, 500),
                    'created_by'    => Auth::id(),
                    'at'            => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[ProjectsController::save] ' . $e->getMessage());
            Response::redirect('projects.form', $id ? ['id' => $id] : [], '저장에 실패했습니다. 입력값을 확인해주세요.', 'error');
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
        Audit::log('trash_move', 'projects', $id, $before, null);
        Response::redirect('projects.index', [], '프로젝트가 휴지통으로 이동되었습니다.');
    }

    /** 완전삭제 차단 사유(참조 존재 시) — RESTRICT 부모 나열. 없으면 null. */
    public static function purgeBlockReason(int $projectId): ?string
    {
        $refs = [];
        foreach ([
            ['payments', 'project_id', '입금'], ['costs', 'project_id', '비용'],
            ['site_bonuses', 'project_id', '보너스'], ['project_files', 'project_id', '파일'],
            ['schedules', 'project_id', '일정'], ['work_logs', 'project_id', '작업일지'],
            ['project_assignments', 'project_id', '직원 배정'],
        ] as [$t, $col, $label]) {
            $n = (int) Db::val("SELECT COUNT(*) FROM `$t` WHERE `$col` = :id", [':id' => $projectId]);
            if ($n > 0) { $refs[] = "{$label} {$n}건"; }
        }
        return $refs ? ('연결된 기록(' . implode(', ', $refs) . ')이 있어 완전삭제할 수 없습니다. 기록 보존을 위해 휴지통에 유지하세요.') : null;
    }

    /**
     * 복원 차단 사유 — 계약 연결(contract_id) 프로젝트인데 동일 계약에 이미 live 프로젝트가 있으면 차단.
     * projects.contract_id 는 UNIQUE(uq_projects_contract) 제약이라 정상 흐름에서는 이 상태가
     * 생기지 않지만(ContractProjectService 가 소프트삭제 점유 시 자동생성을 거부), 방어적으로 유지한다.
     * 없으면 null.
     */
    public static function restoreBlockReason(int $projectId): ?string
    {
        $row = Db::one("SELECT contract_id FROM projects WHERE id = :id", [':id' => $projectId]);
        if (!$row || $row['contract_id'] === null) {
            return null;
        }
        $other = Db::val(
            "SELECT id FROM projects WHERE contract_id = :cid AND deleted_at IS NULL AND id <> :id LIMIT 1",
            [':cid' => $row['contract_id'], ':id' => $projectId]
        );
        return $other !== null ? '동일 계약에 이미 진행 중인 프로젝트가 있어 복원할 수 없습니다. 관리자에게 문의하세요.' : null;
    }

    /** 완전삭제(super_admin 전용) — 휴지통(deleted_at IS NOT NULL)에 있는 프로젝트만. */
    public function purge(): void
    {
        Perm::requireSuperAdmin('projects.purge');   // R16: 라우터 trash.manage 와 이중 가드
        if (!Rbac::isRole('super_admin')) {
            Audit::log('access_denied', 'project', null, null, ['action' => 'project_purge']);
            http_response_code(403);
            View::renderError(403, '접근 권한 없음', '완전삭제는 최고 관리자만 가능합니다.');
            return;
        }
        $id = (int) Util::postInt('id', 0);
        $row = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NOT NULL", [':id' => $id]);
        if (!$row) {
            Response::redirect('projects.index', ['trash' => 1], '휴지통에 있는 프로젝트만 완전삭제할 수 있습니다.', 'error');
        }
        $reason = self::purgeBlockReason($id);
        if ($reason !== null) {
            Response::redirect('projects.index', ['trash' => 1], $reason, 'error');
        }
        Db::run("DELETE FROM projects WHERE id = :id", [':id' => $id]); // 이력·게이지·메모는 FK CASCADE
        Audit::log('trash_purge', 'projects', $id, $row, null);
        Response::redirect('projects.index', ['trash' => 1], '완전삭제되었습니다.');
    }

    /** 복원(휴지통 → 정상) — super_admin 전용. */
    public function restore(): void
    {
        Perm::requireSuperAdmin('projects.restore');  // R16: 라우터 trash.manage 와 이중 가드
        $id = (int) Util::postInt('id', 0);
        $row = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NOT NULL", [':id' => $id]);
        if (!$row) {
            Response::redirect('projects.index', ['trash' => 1], '휴지통에 있는 프로젝트만 복원할 수 있습니다.', 'error');
        }
        $reason = self::restoreBlockReason($id);
        if ($reason !== null) {
            Response::redirect('projects.index', ['trash' => 1], $reason, 'error');
        }
        Db::update('projects', ['deleted_at' => null], 'id = :id', [':id' => $id]);
        Audit::log('trash_restore', 'projects', $id, $row, null);
        Response::redirect('projects.index', ['trash' => 1], '프로젝트가 복원되었습니다.');
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
            // r4-refactor(T10): 사업자등록증은 전용 라우트(customers.license.download)만 허용 — 명시 게이트.
            // project_id NULL + project.view_all 경로로 우회 열람되던 것을 차단(실권한 우회는 없었음, 경로 단일화 목적).
            if (($f['entity_type'] ?? '') === 'customer_license') {
                return false;
            }
            if (empty($f['project_id'])) {
                // R16: project.view_all(=field.projects read) 은 이제 읽기 권한자 전원에게 참이므로
                //      범위 판정 전용 헬퍼를 쓴다(프로젝트 미연결 파일의 열람 범위 확대 방지).
                return Scope::canViewAllProjects();
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
