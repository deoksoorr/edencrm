<?php
/**
 * 고객 CRM. 목록/상세/등록수정/중복검사/병합/삭제/CSV + 사업자등록증 관리(R4 T2).
 */
require_once APP_PATH . '/core/BizReg.php';

class CustomersController
{
    /** 사업자등록증 허용 확장자 — PDF/JPG/JPEG/PNG만 (브리프 §1 규약). */
    private const LICENSE_EXTS = ['pdf', 'jpg', 'jpeg', 'png'];

    private const SORT_MAP = [
        'created_at' => 'c.created_at DESC',
        'last_consult' => 'c.last_consult_date DESC',
        'name' => 'c.name ASC',
    ];

    /** 목록 필터 WHERE 조합 (index/export 공용). */
    private function buildFilter(): array
    {
        [$scopeSql, $scopeParams] = Scope::customerWhere('c');
        $where = ['c.deleted_at IS NULL', $scopeSql];
        $params = $scopeParams;

        $q = Util::str('q');
        if ($q !== '') {
            $where[] = '(c.name LIKE :q1 OR c.company_name LIKE :q2 OR c.contact_name LIKE :q3 OR c.phone LIKE :q4 OR c.site_address LIKE :q5)';
            $like = '%' . $q . '%';
            $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like; $params[':q4'] = $like; $params[':q5'] = $like;
        }
        $status = Util::str('status');
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params[':status'] = $status;
        }
        $source = Util::str('source');
        if ($source !== '') {
            $where[] = 'c.source = :source';
            $params[':source'] = $source;
        }
        $salesUserId = Util::int('sales_user_id', null);
        if ($salesUserId) {
            $where[] = 'c.sales_user_id = :su';
            $params[':su'] = $salesUserId;
        }

        return [implode(' AND ', $where), $params];
    }

    public function index(): void
    {
        [$whereSql, $params] = $this->buildFilter();
        $sort = Util::str('sort', 'created_at');
        $orderBy = self::SORT_MAP[$sort] ?? self::SORT_MAP['created_at'];

        $total = (int) Db::val("SELECT COUNT(*) FROM customers c WHERE $whereSql", $params);
        $per = (int) ($GLOBALS['config']['PAGE_SIZE'] ?? 20);
        $page = max(1, (int) Util::int('page', 1));
        $pg = Util::paginate($total, $page, $per);

        $rows = Db::all(
            "SELECT c.*, u.name AS sales_user_name
             FROM customers c LEFT JOIN users u ON u.id = c.sales_user_id
             WHERE $whereSql ORDER BY $orderBy LIMIT :lim OFFSET :off",
            $params + [':lim' => $pg['per'], ':off' => $pg['offset']]
        );

        $salesUsers = $this->salesUserOptions();
        $sources = Db::run("SELECT DISTINCT source FROM customers WHERE source IS NOT NULL AND source <> '' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);

        View::render('customers/index', [
            'title' => '고객 CRM',
            'rows' => $rows,
            'pg' => $pg,
            'salesUsers' => $salesUsers,
            'sources' => $sources,
            'q' => Util::str('q'),
            'status' => Util::str('status'),
            'source' => Util::str('source'),
            'salesUserId' => Util::int('sales_user_id', null),
            'sort' => $sort,
        ]);
    }

    public function show(): void
    {
        $id = Util::int('id', 0);
        [$scopeSql, $scopeParams] = Scope::customerWhere('c');
        $customer = Db::one(
            "SELECT c.*, u.name AS sales_user_name
             FROM customers c LEFT JOIN users u ON u.id = c.sales_user_id
             WHERE c.id = :id AND c.deleted_at IS NULL AND $scopeSql",
            [':id' => $id] + $scopeParams
        );
        if (!$customer) {
            http_response_code(404);
            View::renderError(404, '고객을 찾을 수 없음', '해당 고객이 존재하지 않거나 접근 권한이 없습니다.');
            return;
        }

        $activities = Db::all(
            "SELECT a.*, u.name AS user_name FROM customer_activities a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.customer_id = :id ORDER BY a.activity_at DESC LIMIT 100",
            [':id' => $id]
        );
        $contacts = Db::all("SELECT * FROM customer_contacts WHERE customer_id = :id ORDER BY is_primary DESC, id ASC", [':id' => $id]);
        $quotes = Db::all("SELECT * FROM quotes WHERE customer_id = :id AND deleted_at IS NULL ORDER BY created_at DESC", [':id' => $id]);
        $contracts = Db::all("SELECT * FROM contracts WHERE customer_id = :id AND deleted_at IS NULL ORDER BY created_at DESC", [':id' => $id]);
        $projects = Db::all("SELECT * FROM projects WHERE customer_id = :id AND deleted_at IS NULL ORDER BY created_at DESC", [':id' => $id]);
        $leads = Db::all(
            "SELECT l.*, ps.name AS stage_name, ps.color AS stage_color FROM leads l
             JOIN pipeline_stages ps ON ps.id = l.stage_id
             WHERE l.customer_id = :id AND l.deleted_at IS NULL ORDER BY l.created_at DESC",
            [':id' => $id]
        );

        $licenseFile = null;
        if (!empty($customer['biz_license_file_id'])) {
            $licenseFile = Db::one(
                "SELECT f.*, u.name AS uploader_name FROM project_files f
                 LEFT JOIN users u ON u.id = f.uploaded_by
                 WHERE f.id = :fid AND f.entity_type = 'customer_license' AND f.entity_id = :cid",
                [':fid' => (int) $customer['biz_license_file_id'], ':cid' => $id]
            );
        }

        View::render('customers/show', [
            'title' => '고객 상세 - ' . $customer['name'],
            'customer' => $customer,
            'licenseFile' => $licenseFile,
            'activities' => $activities,
            'contacts' => $contacts,
            'quotes' => $quotes,
            'contracts' => $contracts,
            'projects' => $projects,
            'leads' => $leads,
        ]);
    }

    public function form(): void
    {
        $id = Util::int('id', 0);
        $customer = null;
        if ($id) {
            [$scopeSql, $scopeParams] = Scope::customerWhere('c');
            $customer = Db::one("SELECT c.* FROM customers c WHERE c.id = :id AND c.deleted_at IS NULL AND $scopeSql", [':id' => $id] + $scopeParams);
            if (!$customer) {
                http_response_code(404);
                View::renderError(404, '고객을 찾을 수 없음', '해당 고객이 존재하지 않거나 접근 권한이 없습니다.');
                return;
            }
        }

        $licenseFile = null;
        if (!empty($customer['biz_license_file_id'])) {
            $licenseFile = Db::one(
                "SELECT id, original_name, created_at FROM project_files
                 WHERE id = :fid AND entity_type = 'customer_license' AND entity_id = :cid",
                [':fid' => (int) $customer['biz_license_file_id'], ':cid' => $id]
            );
        }

        View::render('customers/form', [
            'title' => $id ? '고객 정보 수정' : '신규 고객 등록',
            'customer' => $customer,
            'salesUsers' => $this->salesUserOptions((int) ($customer['sales_user_id'] ?? 0)),
            'licenseFile' => $licenseFile,
        ]);
    }

    public function save(): void
    {
        $id = Util::postInt('id', 0);
        $type = Util::postStr('type') === 'company' ? 'company' : 'individual';
        $name = trim(Util::postStr('name'));
        if ($name === '') {
            Response::error('고객명은 필수입니다.', 422);
        }
        if ((int) Util::postInt('privacy_agreed', 0) !== 1) {
            Response::error('개인정보 수집·이용 동의가 필요합니다.', 422);
        }

        // ── 사업자 정보 (R4 T2) — 번호는 형식(10자리)+국세청 체크섬 서버 검증(중복은 경고만, 차단 아님) ──
        $isBusiness = Util::postInt('is_business', 0) === 1 ? 1 : 0;
        $bizRegNo = Util::nullIfEmpty(Util::postStr('biz_reg_no'));
        if ($bizRegNo !== null) {
            if (!BizReg::isValid($bizRegNo)) {
                Response::error('사업자등록번호가 올바르지 않습니다. 숫자 10자리와 검증번호를 확인하세요.', 422);
            }
            $bizRegNo = BizReg::format($bizRegNo);
        }

        $data = [
            'type' => $type,
            'name' => $name,
            'is_business' => $isBusiness,
            'biz_reg_no' => $bizRegNo,
            'biz_name' => Util::nullIfEmpty(Util::postStr('biz_name')),
            'biz_ceo' => Util::nullIfEmpty(Util::postStr('biz_ceo')),
            'biz_address' => Util::nullIfEmpty(Util::postStr('biz_address')),
            'biz_type' => Util::nullIfEmpty(Util::postStr('biz_type')),
            'biz_item' => Util::nullIfEmpty(Util::postStr('biz_item')),
            'company_name' => Util::nullIfEmpty(Util::postStr('company_name')),
            'contact_name' => Util::nullIfEmpty(Util::postStr('contact_name')),
            'phone' => Util::nullIfEmpty(Util::postStr('phone')),
            'email' => Util::nullIfEmpty(Util::postStr('email')),
            'address' => Util::nullIfEmpty(Util::postStr('address')),
            'site_address' => Util::nullIfEmpty(Util::postStr('site_address')),
            'source' => Util::nullIfEmpty(Util::postStr('source')),
            'interest_type' => Util::nullIfEmpty(Util::postStr('interest_type')),
            'expected_scale' => Util::nullIfEmpty(Util::postStr('expected_scale')),
            'expected_budget' => Util::postFloat('expected_budget', null),
            'desired_consult_date' => Util::nullIfEmpty(Util::postStr('desired_consult_date')),
            'sales_user_id' => Util::postInt('sales_user_id', null) ?: null,
            'status' => in_array(Util::postStr('status'), ['active', 'inactive', 'blacklist'], true) ? Util::postStr('status') : 'active',
            'tags' => Util::nullIfEmpty(Util::postStr('tags')),
            'privacy_agreed' => 1,
            'memo' => Util::nullIfEmpty(Util::postStr('memo')),
            'next_contact_date' => Util::nullIfEmpty(Util::postStr('next_contact_date')),
        ];

        if ($id) {
            [$scopeSql, $scopeParams] = Scope::customerWhere('c');
            $before = Db::one("SELECT c.* FROM customers c WHERE c.id=:id AND c.deleted_at IS NULL AND $scopeSql", [':id' => $id] + $scopeParams);
            if (!$before) {
                Response::error('수정할 고객을 찾을 수 없습니다.', 404);
            }
            Db::update('customers', $data, 'id = :id', [':id' => $id]);
            Audit::log('customer.update', 'customers', $id, $before, $data);
        } else {
            $id = Db::insert('customers', $data);
            Audit::log('customer.create', 'customers', $id, null, $data);
        }

        // 폼에 첨부된 사업자등록증 파일이 있으면 저장(교체 포함) — 실패해도 고객 저장은 유지
        if (!empty($_FILES['biz_license']) && ($_FILES['biz_license']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $this->storeLicenseFile($id, $_FILES['biz_license']);
            } catch (\RuntimeException $e) {
                if (Response::wantsJson()) {
                    Response::error('고객은 저장되었으나 사업자등록증 업로드 실패: ' . $e->getMessage(), 422, ['id' => $id]);
                }
                Response::redirect('customers.show', ['id' => $id], '고객은 저장되었으나 사업자등록증 업로드에 실패했습니다: ' . $e->getMessage(), 'error');
            }
        }

        if (Response::wantsJson()) {
            Response::json(['id' => $id]);
        }
        Response::redirect('customers.show', ['id' => $id], '저장되었습니다.');
    }

    /* ───────────────────── 사업자등록증 파일 (R4 T2) ─────────────────────
     * 업로드/교체/삭제 = customer.manage(라우터 강제), 열람 = customer.view + Scope.
     * 파일 보안은 기존 Upload 패턴 재사용: MIME+확장자 이중 검증, 실행 파일 금지,
     * 난수 파일명+원본명 보존, DocumentRoot 밖(storage/uploads) 저장, 다운로드는 스트리밍. */

    /** 등록증 업로드/교체 (POST customer_id + license_file). */
    public function licenseUpload(): void
    {
        $customerId = (int) Util::postInt('customer_id', 0);
        $customer = $this->findScopedCustomer($customerId);
        if (!$customer) {
            Response::error('고객을 찾을 수 없거나 접근 권한이 없습니다.', 404);
        }

        $file = $_FILES['license_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Response::error('업로드할 파일을 선택하세요.', 422);
        }

        try {
            $fileId = $this->storeLicenseFile($customerId, $file);
        } catch (\RuntimeException $e) {
            if (Response::wantsJson()) {
                Response::error($e->getMessage(), 422);
            }
            Response::redirect('customers.show', ['id' => $customerId], $e->getMessage(), 'error');
        }

        if (Response::wantsJson()) {
            Response::json(['file_id' => $fileId]);
        }
        Response::redirect('customers.show', ['id' => $customerId], '사업자등록증이 업로드되었습니다.');
    }

    /** 등록증 다운로드/미리보기 — Upload::send + customer_license 전용 권한 콜백(Scope 강제). */
    public function licenseDownload(): void
    {
        $fileId = (int) Util::int('id', 0);
        if (!$fileId) {
            http_response_code(404);
            exit('파일을 찾을 수 없습니다.');
        }
        $inline = Util::int('preview', 0) === 1;
        Upload::send($fileId, function (array $f): bool {
            if (($f['entity_type'] ?? '') !== 'customer_license') {
                return false; // 이 라우트는 사업자등록증 전용 — 타 엔티티 파일 접근 차단
            }
            return $this->findScopedCustomer((int) $f['entity_id']) !== null;
        }, $inline);
    }

    /** 등록증 삭제 — Audit 기록 후 DB 행·물리 파일 제거. */
    public function licenseDelete(): void
    {
        $customerId = (int) Util::postInt('customer_id', 0);
        $customer = $this->findScopedCustomer($customerId);
        if (!$customer) {
            Response::error('고객을 찾을 수 없거나 접근 권한이 없습니다.', 404);
        }
        if (empty($customer['biz_license_file_id'])) {
            Response::error('삭제할 사업자등록증이 없습니다.', 404);
        }

        $this->removeLicenseFile($customerId, (int) $customer['biz_license_file_id'], 'customer_license.delete');

        if (Response::wantsJson()) {
            Response::json(['customer_id' => $customerId]);
        }
        Response::redirect('customers.show', ['id' => $customerId], '사업자등록증이 삭제되었습니다.');
    }

    /** Scope 를 강제한 단일 고객 조회 (라이선스 계열 공용 가드). */
    private function findScopedCustomer(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        [$scopeSql, $scopeParams] = Scope::customerWhere('c');
        return Db::one(
            "SELECT c.* FROM customers c WHERE c.id = :id AND c.deleted_at IS NULL AND $scopeSql",
            [':id' => $customerId] + $scopeParams
        );
    }

    /**
     * 등록증 저장(기존 파일 있으면 교체). Upload::save 재사용 — PDF/JPG/JPEG/PNG만.
     * @return int 새 project_files.id
     * @throws RuntimeException 검증 실패 시
     */
    private function storeLicenseFile(int $customerId, array $file): int
    {
        $info = Upload::save($file, 'customers/' . $customerId, self::LICENSE_EXTS);

        $customer = Db::one('SELECT id, biz_license_file_id FROM customers WHERE id = :id', [':id' => $customerId]);
        $oldFileId = (int) ($customer['biz_license_file_id'] ?? 0);

        $fileId = Db::insert('project_files', [
            'project_id'    => null,
            'entity_type'   => 'customer_license',
            'entity_id'     => $customerId,
            'original_name' => $info['original_name'],
            'stored_name'   => $info['stored_name'],
            'path'          => $info['path'],
            'size'          => $info['size'],
            'mime'          => $info['mime'],
            'uploaded_by'   => Auth::id(),
        ]);
        Db::update('customers', ['biz_license_file_id' => $fileId], 'id = :id', [':id' => $customerId]);
        Audit::log($oldFileId ? 'customer_license.replace' : 'customer_license.upload', 'project_files', $fileId, null, [
            'customer_id'   => $customerId,
            'original_name' => $info['original_name'],
            'replaced_file_id' => $oldFileId ?: null,
        ]);

        if ($oldFileId) {
            $this->removeLicenseFile($customerId, $oldFileId, 'customer_license.delete_replaced', false);
        }
        return $fileId;
    }

    /** 등록증 파일 제거: Audit → 포인터 해제(유지 시 생략) → project_files 행·물리 파일 삭제. */
    private function removeLicenseFile(int $customerId, int $fileId, string $auditAction, bool $clearPointer = true): void
    {
        $f = Db::one(
            "SELECT * FROM project_files WHERE id = :id AND entity_type = 'customer_license' AND entity_id = :cid",
            [':id' => $fileId, ':cid' => $customerId]
        );
        if (!$f) {
            return;
        }
        Audit::log($auditAction, 'project_files', $fileId, $f, null);
        if ($clearPointer) {
            Db::update('customers', ['biz_license_file_id' => null], 'id = :id', [':id' => $customerId]);
        }
        Db::run('DELETE FROM project_files WHERE id = :id', [':id' => $fileId]);
        $full = UPLOAD_PATH . '/' . $f['path'];
        if (is_file($full)) {
            @unlink($full);
        }
    }

    /** 전화번호/이메일/사업자등록번호 중복 후보 (등록/수정 폼에서 AJAX 호출 — 경고용, 차단 아님). */
    public function dupCheck(): void
    {
        $phone = trim((string) Util::input('phone', ''));
        $email = trim((string) Util::input('email', ''));
        $bizRegNo = BizReg::normalize((string) Util::input('biz_reg_no', ''));
        $excludeId = (int) Util::input('id', 0);

        if ($phone === '' && $email === '' && $bizRegNo === '') {
            Response::json(['candidates' => []]);
        }

        // R16-V2: 담당 범위 밖 고객이 이름·전화·이메일·사업자번호로 검색되던 문제 — Scope 적용.
        [$scopeSql, $scopeParams] = Scope::customerWhere('c');
        $conds = [];
        $params = [':exclude' => $excludeId] + $scopeParams;
        if ($phone !== '') {
            $conds[] = 'c.phone = :phone';
            $params[':phone'] = $phone;
        }
        if ($email !== '') {
            $conds[] = 'c.email = :email';
            $params[':email'] = $email;
        }
        if ($bizRegNo !== '') {
            $conds[] = "REPLACE(c.biz_reg_no, '-', '') = :bizno";
            $params[':bizno'] = $bizRegNo;
        }
        $rows = Db::all(
            "SELECT c.id, c.name, c.company_name, c.phone, c.email, c.biz_reg_no FROM customers c
             WHERE c.deleted_at IS NULL AND c.id <> :exclude AND $scopeSql
               AND (" . implode(' OR ', $conds) . ") LIMIT 5",
            $params
        );
        Response::json(['candidates' => $rows]);
    }

    /** 두 고객 병합: merge_id 의 연관 데이터를 keep_id 로 이전 후 merge_id soft delete. */
    public function merge(): void
    {
        $keepId = Util::postInt('keep_id', 0);
        $mergeId = Util::postInt('merge_id', 0);
        if (!$keepId || !$mergeId || $keepId === $mergeId) {
            Response::error('병합할 두 고객을 올바르게 지정하세요.', 422);
        }

        // R16-V10: 병합은 파괴적 작업 — 유지·병합 대상 '양쪽 모두' 담당 범위 안이어야 한다.
        [$scopeSql, $scopeParams] = Scope::customerWhere('c');
        $sql = "SELECT c.* FROM customers c WHERE c.id=:id AND c.deleted_at IS NULL AND $scopeSql";
        $keep = Db::one($sql, [':id' => $keepId] + $scopeParams);
        $merge = Db::one($sql, [':id' => $mergeId] + $scopeParams);
        if (!$keep || !$merge) {
            Response::error('고객을 찾을 수 없습니다.', 404);
        }

        Db::transaction(function () use ($keepId, $mergeId) {
            foreach (['customer_activities', 'customer_contacts', 'leads', 'quotes', 'contracts', 'projects'] as $table) {
                Db::run("UPDATE `$table` SET customer_id = :keep WHERE customer_id = :merge", [':keep' => $keepId, ':merge' => $mergeId]);
            }
            Db::update('customers', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $mergeId]);
        });

        Audit::log('customer.merge', 'customers', $keepId, ['merged_from' => $mergeId], ['kept' => $keepId]);

        if (Response::wantsJson()) {
            Response::json(['keep_id' => $keepId]);
        }
        Response::redirect('customers.show', ['id' => $keepId], '고객이 병합되었습니다.');
    }

    public function delete(): void
    {
        $id = Util::postInt('id', 0);
        [$scopeSql, $scopeParams] = Scope::customerWhere('c');
        $before = Db::one("SELECT c.* FROM customers c WHERE c.id=:id AND c.deleted_at IS NULL AND $scopeSql", [':id' => $id] + $scopeParams);
        if (!$before) {
            Response::error('삭제할 고객을 찾을 수 없습니다.', 404);
        }
        Db::update('customers', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
        Audit::log('customer.delete', 'customers', $id, $before, null);

        if (Response::wantsJson()) {
            Response::json(['id' => $id]);
        }
        Response::redirect('customers.index', [], '고객이 삭제되었습니다.');
    }

    public function export(): void
    {
        [$whereSql, $params] = $this->buildFilter();
        $rows = Db::all(
            "SELECT c.*, u.name AS sales_user_name
             FROM customers c LEFT JOIN users u ON u.id = c.sales_user_id
             WHERE $whereSql ORDER BY c.created_at DESC",
            $params
        );

        $typeLabel = ['individual' => '개인', 'company' => '법인'];
        $statusLabel = ['active' => '활성', 'inactive' => '비활성', 'blacklist' => '블랙리스트'];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="customers_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM (엑셀 호환)

        $out = fopen('php://output', 'w');
        fputcsv($out, ['고객명', '구분', '업체명', '담당자', '연락처', '이메일', '주소', '현장주소', '유입경로', '관심공사', '예상예산', '담당영업', '상태', '다음연락예정일', '최근상담일', '등록일'], ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['name'],
                $typeLabel[$r['type']] ?? $r['type'],
                $r['company_name'],
                $r['contact_name'],
                $r['phone'],
                $r['email'],
                $r['address'],
                $r['site_address'],
                $r['source'],
                $r['interest_type'],
                $r['expected_budget'],
                $r['sales_user_name'],
                $statusLabel[$r['status']] ?? $r['status'],
                $r['next_contact_date'],
                $r['last_consult_date'],
                $r['created_at'],
            ], ',', '"', '\\');
        }
        fclose($out);
        Audit::log('customer.export', 'customers', null, null, ['count' => count($rows)]);
        exit;
    }

    /** 담당영업 select 옵션(role_key=sales_manager, 활성). 편집 중인 값이 목록에 없으면 포함. */
    private function salesUserOptions(int $includeId = 0): array
    {
        $rows = Db::all("SELECT id, name FROM users WHERE role_key = 'sales_manager' AND status = 'active' AND deleted_at IS NULL ORDER BY name");
        if ($includeId && !in_array($includeId, array_column($rows, 'id'), true)) {
            $extra = Db::one('SELECT id, name FROM users WHERE id = :id', [':id' => $includeId]);
            if ($extra) {
                $extra['name'] .= ' (비활성)';
                $rows[] = $extra;
            }
        }
        return $rows;
    }
}
