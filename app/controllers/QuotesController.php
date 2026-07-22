<?php
/**
 * 견적 관리: 목록/상세/등록·수정(+버전 이력)/인쇄/삭제.
 * quotes(헤더) — quote_versions(버전, 금액 요약) — quote_items(버전별 항목) 3단 구조.
 * 수정 시 기존 버전을 덮지 않고 새 quote_versions+quote_items 를 추가한다.
 */
class QuotesController
{
    private const STATUS_LABELS = [
        'draft'    => '임시저장',
        'sent'     => '발송됨',
        'accepted' => '수락됨',
        'rejected' => '거절됨',
        'expired'  => '만료됨',
    ];
    private const STATUS_BADGE = [
        'draft'    => 'badge-muted',
        'sent'     => 'badge-info',
        'accepted' => 'badge-ok',
        'rejected' => 'badge-danger',
        'expired'  => 'badge-warn',
    ];

    public function index(): void
    {
        $q       = Util::str('q');
        $status  = Util::str('status');
        $from    = Util::str('date_from');
        $to      = Util::str('date_to');
        $page    = max(1, Util::int('page', 1));
        $perPage = (int) ($GLOBALS['config']['PAGE_SIZE'] ?? 20);

        $where  = ['q.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(q.quote_no LIKE :kw OR c.name LIKE :kw2)';
            $params[':kw'] = "%$q%";
            $params[':kw2'] = "%$q%";
        }
        if ($status !== '') {
            $where[] = 'q.status = :status';
            $params[':status'] = $status;
        }
        if ($from !== '') {
            $where[] = 'DATE(q.created_at) >= :from';
            $params[':from'] = $from;
        }
        if ($to !== '') {
            $where[] = 'DATE(q.created_at) <= :to';
            $params[':to'] = $to;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::val(
            "SELECT COUNT(*) FROM quotes q JOIN customers c ON c.id = q.customer_id WHERE $whereSql",
            $params
        );
        $p = Util::paginate($total, $page, $perPage);

        $rows = Db::all(
            "SELECT q.id, q.quote_no, q.status, q.valid_until, q.created_at,
                    c.name AS customer_name,
                    qv.total_amount
             FROM quotes q
             JOIN customers c ON c.id = q.customer_id
             LEFT JOIN quote_versions qv ON qv.id = q.current_version_id
             WHERE $whereSql
             ORDER BY q.created_at DESC, q.id DESC
             LIMIT :lim OFFSET :off",
            $params + [':lim' => $p['per'], ':off' => $p['offset']]
        );

        View::render('quotes/index', [
            'title'   => '견적 관리',
            'rows'    => $rows,
            'p'       => $p,
            'filters' => ['q' => $q, 'status' => $status, 'date_from' => $from, 'date_to' => $to],
            'statusLabels' => self::STATUS_LABELS,
            'statusBadge'  => self::STATUS_BADGE,
        ]);
    }

    public function show(): void
    {
        $id = Util::int('id', 0);
        $quote = Db::one(
            "SELECT q.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
                    l.work_type AS lead_work_type
             FROM quotes q
             JOIN customers c ON c.id = q.customer_id
             LEFT JOIN leads l ON l.id = q.lead_id
             WHERE q.id = :id AND q.deleted_at IS NULL",
            [':id' => $id]
        );
        if (!$quote) {
            http_response_code(404);
            View::renderError(404, '견적을 찾을 수 없음', '요청하신 견적이 존재하지 않거나 삭제되었습니다.');
            return;
        }

        $items = [];
        if ($quote['current_version_id']) {
            $items = Db::all(
                "SELECT * FROM quote_items WHERE quote_version_id = :vid ORDER BY sort_order ASC, id ASC",
                [':vid' => $quote['current_version_id']]
            );
        }
        $currentVersion = $quote['current_version_id']
            ? Db::one("SELECT * FROM quote_versions WHERE id = :vid", [':vid' => $quote['current_version_id']])
            : null;

        $versions = Db::all(
            "SELECT qv.*, u.name AS created_by_name
             FROM quote_versions qv
             LEFT JOIN users u ON u.id = qv.created_by
             WHERE qv.quote_id = :qid ORDER BY qv.version_no DESC",
            [':qid' => $id]
        );

        $contract = Db::one(
            "SELECT id, contract_no FROM contracts WHERE quote_id = :qid AND deleted_at IS NULL LIMIT 1",
            [':qid' => $id]
        );

        $attachments = Db::all(
            "SELECT * FROM project_files WHERE entity_type = 'quote' AND entity_id = :id ORDER BY created_at DESC",
            [':id' => $id]
        );

        View::render('quotes/show', [
            'title'      => '견적 상세 - ' . $quote['quote_no'],
            'quote'      => $quote,
            'items'      => $items,
            'version'    => $currentVersion,
            'versions'   => $versions,
            'contract'   => $contract,
            'attachments'=> $attachments,
            'statusLabels' => self::STATUS_LABELS,
            'statusBadge'  => self::STATUS_BADGE,
        ]);
    }

    public function form(): void
    {
        $id = Util::int('id', 0);
        $quote = null;
        $items = [];
        $version = null;

        if ($id > 0) {
            $quote = Db::one("SELECT * FROM quotes WHERE id = :id AND deleted_at IS NULL", [':id' => $id]);
            if (!$quote) {
                http_response_code(404);
                View::renderError(404, '견적을 찾을 수 없음', '요청하신 견적이 존재하지 않거나 삭제되었습니다.');
                return;
            }
            if ($quote['current_version_id']) {
                $version = Db::one("SELECT * FROM quote_versions WHERE id = :vid", [':vid' => $quote['current_version_id']]);
                $items = Db::all(
                    "SELECT * FROM quote_items WHERE quote_version_id = :vid ORDER BY sort_order ASC, id ASC",
                    [':vid' => $quote['current_version_id']]
                );
            }
        } else {
            $quote = [
                'id' => 0, 'customer_id' => Util::int('customer_id', 0) ?: null,
                'lead_id' => Util::int('lead_id', 0) ?: null,
                'status' => 'draft', 'valid_until' => date('Y-m-d', strtotime('+30 days')), 'memo' => '',
            ];
        }

        $customers = Db::all("SELECT id, name, phone FROM customers WHERE deleted_at IS NULL ORDER BY name ASC");
        $leads = Db::all(
            "SELECT l.id, l.work_type, c.name AS customer_name
             FROM leads l JOIN customers c ON c.id = l.customer_id
             WHERE l.deleted_at IS NULL ORDER BY l.id DESC LIMIT 300"
        );

        View::render('quotes/form', [
            'title'     => $id ? '견적 수정' : '견적 등록',
            'quote'     => $quote,
            'items'     => $items,
            'version'   => $version,
            'customers' => $customers,
            'leads'     => $leads,
            'vatRate'   => (float) setting('vat_rate', 10),
            'scripts'   => ['js/quotes.js'],
        ]);
    }

    public function save(): void
    {
        $id = Util::postInt('id', 0);
        $customerId = Util::postInt('customer_id', 0);
        $leadId = Util::postInt('lead_id', 0) ?: null;
        $status = Util::postStr('status', 'draft');
        $validUntil = Util::nullIfEmpty(Util::postStr('valid_until'));
        $memo = Util::postStr('memo');
        $discount = (float) str_replace(',', '', Util::postStr('discount', '0'));
        $versionNote = Util::postStr('version_note');

        if (!$customerId || !Db::val("SELECT id FROM customers WHERE id=:id AND deleted_at IS NULL", [':id' => $customerId])) {
            Response::redirect('quotes.form', $id ? ['id' => $id] : [], '고객을 선택하세요.', 'error');
        }
        if (!in_array($status, array_keys(self::STATUS_LABELS), true)) {
            $status = 'draft';
        }

        $items = $this->parseItems($_POST['items'] ?? []);
        if (!$items) {
            Response::redirect('quotes.form', $id ? ['id' => $id] : [], '견적 항목을 최소 1개 이상 입력하세요.', 'error');
        }

        $vatRate = (float) setting('vat_rate', 10);
        [$subtotal, $vat, $total] = $this->computeTotals($items, $discount, $vatRate);

        $before = $id ? Db::one("SELECT * FROM quotes WHERE id=:id", [':id' => $id]) : null;

        $newId = Db::transaction(function () use ($id, $customerId, $leadId, $status, $validUntil, $memo, $items, $subtotal, $vat, $discount, $total, $versionNote) {
            if ($id > 0) {
                $quote = Db::one("SELECT * FROM quotes WHERE id=:id AND deleted_at IS NULL FOR UPDATE", [':id' => $id]);
                if (!$quote) {
                    throw new RuntimeException('견적을 찾을 수 없습니다.');
                }
                $nextNo = (int) Db::val("SELECT COALESCE(MAX(version_no),0) FROM quote_versions WHERE quote_id=:qid FOR UPDATE", [':qid' => $id]) + 1;
                $quoteId = $id;
            } else {
                $quoteNo = $this->nextQuoteNo();
                $quoteId = Db::insert('quotes', [
                    'quote_no'    => $quoteNo,
                    'lead_id'     => $leadId,
                    'customer_id' => $customerId,
                    'status'      => $status,
                    'valid_until' => $validUntil,
                    'memo'        => $memo,
                ]);
                $nextNo = 1;
            }

            $versionId = Db::insert('quote_versions', [
                'quote_id'     => $quoteId,
                'version_no'   => $nextNo,
                'subtotal'     => $subtotal,
                'vat'          => $vat,
                'discount'     => $discount,
                'total_amount' => $total,
                'note'         => $versionNote !== '' ? $versionNote : null,
                'created_by'   => Auth::id(),
            ]);

            $sort = 1;
            foreach ($items as $it) {
                Db::insert('quote_items', [
                    'quote_version_id'  => $versionId,
                    'name'              => $it['name'],
                    'area'              => $it['area'],
                    'qty'               => $it['qty'],
                    'unit_price'        => $it['unit_price'],
                    'material_cost'     => $it['material_cost'],
                    'labor_cost'        => $it['labor_cost'],
                    'equipment_cost'    => $it['equipment_cost'],
                    'outsourcing_cost'  => $it['outsourcing_cost'],
                    'etc_cost'          => $it['etc_cost'],
                    'amount'            => $it['amount'],
                    'sort_order'        => $sort++,
                ]);
            }

            Db::update('quotes', [
                'customer_id'         => $customerId,
                'lead_id'             => $leadId,
                'status'              => $status,
                'valid_until'         => $validUntil,
                'memo'                => $memo,
                'current_version_id'  => $versionId,
            ], 'id = :id', [':id' => $quoteId]);

            return $quoteId;
        });

        $after = Db::one("SELECT * FROM quotes WHERE id=:id", [':id' => $newId]);
        Audit::log($id ? 'quote_update' : 'quote_create', 'quotes', $newId, $before, $after);

        Response::redirect('quotes.show', ['id' => $newId], '견적이 저장되었습니다.');
    }

    public function printView(): void
    {
        $id = Util::int('id', 0);
        $quote = Db::one(
            "SELECT q.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
                    c.site_address AS customer_site_address
             FROM quotes q JOIN customers c ON c.id = q.customer_id
             WHERE q.id = :id AND q.deleted_at IS NULL",
            [':id' => $id]
        );
        if (!$quote) {
            http_response_code(404);
            View::renderError(404, '견적을 찾을 수 없음', '요청하신 견적이 존재하지 않거나 삭제되었습니다.');
            return;
        }
        $items = $quote['current_version_id']
            ? Db::all("SELECT * FROM quote_items WHERE quote_version_id=:vid ORDER BY sort_order ASC, id ASC", [':vid' => $quote['current_version_id']])
            : [];
        $version = $quote['current_version_id']
            ? Db::one("SELECT * FROM quote_versions WHERE id=:vid", [':vid' => $quote['current_version_id']])
            : null;

        View::render('quotes/print', [
            'title'   => '견적서 인쇄 - ' . $quote['quote_no'],
            'quote'   => $quote,
            'items'   => $items,
            'version' => $version,
            'companyName' => setting('company_name', 'EDEN'),
        ], 'blank');
    }

    public function delete(): void
    {
        $id = Util::postInt('id', 0);
        $quote = Db::one("SELECT * FROM quotes WHERE id=:id AND deleted_at IS NULL", [':id' => $id]);
        if (!$quote) {
            Response::error('견적을 찾을 수 없습니다.', 404);
        }
        $hasContract = Db::val("SELECT id FROM contracts WHERE quote_id=:id AND deleted_at IS NULL", [':id' => $id]);
        if ($hasContract) {
            Response::error('이미 계약으로 전환된 견적은 삭제할 수 없습니다.', 400);
        }
        Db::update('quotes', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
        Audit::log('quote_delete', 'quotes', $id, $quote, null);
        if (Response::wantsJson()) {
            Response::json(['id' => $id]);
        }
        Response::redirect('quotes.index', [], '견적이 삭제되었습니다.');
    }

    /** POST items[idx][name/area/qty/unit_price/...] 를 정리·검증. */
    private function parseItems(array $raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $num = fn($k, $def = 0) => (float) str_replace(',', '', (string) ($row[$k] ?? $def));
            $qty = $num('qty', 1) ?: 1;
            $unitPrice = $num('unit_price', 0);
            $area = (isset($row['area']) && $row['area'] !== '') ? $num('area', 0) : null;
            // 금액 = 단가(원/㎡) × 면적 × 수량. 면적 미입력(개수 기준)이면 단가 × 수량.
            $areaFactor = ($area !== null && $area > 0) ? $area : 1;
            $out[] = [
                'name'             => $name,
                'area'             => $area,
                'qty'              => $qty,
                'unit_price'       => $unitPrice,
                'material_cost'    => $num('material_cost', 0),
                'labor_cost'       => $num('labor_cost', 0),
                'equipment_cost'   => $num('equipment_cost', 0),
                'outsourcing_cost' => $num('outsourcing_cost', 0),
                'etc_cost'         => $num('etc_cost', 0),
                'amount'           => round($areaFactor * $qty * $unitPrice, 0),
            ];
        }
        return $out;
    }

    private function computeTotals(array $items, float $discount, float $vatRate): array
    {
        $subtotal = 0.0;
        foreach ($items as $it) {
            $subtotal += $it['amount'];
        }
        $subtotal = round($subtotal, 0);
        $vat = round($subtotal * $vatRate / 100, 0);
        $total = $subtotal + $vat - $discount;
        return [$subtotal, $vat, $total];
    }

    /** 견적번호: Q + YYYYMMDD + '-' + 일련(3자리). 예: Q20260722-001 */
    private function nextQuoteNo(): string
    {
        $prefix = 'Q' . date('Ymd') . '-';
        $last = Db::val(
            "SELECT quote_no FROM quotes WHERE quote_no LIKE :p ORDER BY quote_no DESC LIMIT 1 FOR UPDATE",
            [':p' => $prefix . '%']
        );
        $seq = $last ? ((int) substr($last, -3) + 1) : 1;
        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
