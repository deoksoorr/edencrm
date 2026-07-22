<?php
/**
 * 고객 CRM. 목록/상세/등록수정/중복검사/병합/삭제/CSV.
 */
class CustomersController
{
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

        View::render('customers/show', [
            'title' => '고객 상세 - ' . $customer['name'],
            'customer' => $customer,
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

        View::render('customers/form', [
            'title' => $id ? '고객 정보 수정' : '신규 고객 등록',
            'customer' => $customer,
            'salesUsers' => $this->salesUserOptions((int) ($customer['sales_user_id'] ?? 0)),
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

        $data = [
            'type' => $type,
            'name' => $name,
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

        if (Response::wantsJson()) {
            Response::json(['id' => $id]);
        }
        Response::redirect('customers.show', ['id' => $id], '저장되었습니다.');
    }

    /** 전화번호/이메일 중복 후보 (등록/수정 폼에서 AJAX 호출). */
    public function dupCheck(): void
    {
        $phone = trim((string) Util::input('phone', ''));
        $email = trim((string) Util::input('email', ''));
        $excludeId = (int) Util::input('id', 0);

        if ($phone === '' && $email === '') {
            Response::json(['candidates' => []]);
        }

        $conds = [];
        $params = [':exclude' => $excludeId];
        if ($phone !== '') {
            $conds[] = 'phone = :phone';
            $params[':phone'] = $phone;
        }
        if ($email !== '') {
            $conds[] = 'email = :email';
            $params[':email'] = $email;
        }
        $rows = Db::all(
            "SELECT id, name, company_name, phone, email FROM customers
             WHERE deleted_at IS NULL AND id <> :exclude AND (" . implode(' OR ', $conds) . ") LIMIT 5",
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

        $keep = Db::one('SELECT * FROM customers WHERE id=:id AND deleted_at IS NULL', [':id' => $keepId]);
        $merge = Db::one('SELECT * FROM customers WHERE id=:id AND deleted_at IS NULL', [':id' => $mergeId]);
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
