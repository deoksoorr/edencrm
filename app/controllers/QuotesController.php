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
        $period  = Util::str('period');
        $fromIn  = Util::str('date_from');
        $toIn    = Util::str('date_to');
        $page    = max(1, Util::int('page', 1));
        $perPage = (int) ($GLOBALS['config']['PAGE_SIZE'] ?? 20);

        // 기간 필터 (R4 공통 규약): period 프리셋 → Util::periodRange 단일 계산.
        // 하위호환 — period 없이 date_from/to 직접 진입 시 custom 으로 동작.
        if ($period === '' && ($fromIn !== '' || $toIn !== '')) {
            $period = 'custom';
        }
        if ($period !== '' && !isset(Util::PERIOD_PRESETS[$period])) {
            $period = '';
        }
        $range = Util::periodRange($period, $fromIn !== '' ? $fromIn : null, $toIn !== '' ? $toIn : null);

        // R15: 휴지통 모드 — manage 권한 없으면 강제로 일반 목록으로 폴백(trash=1 무시).
        $trash  = Util::int('trash', 0) === 1 && Rbac::can('quote.manage');
        $where  = [$trash ? 'q.deleted_at IS NOT NULL' : 'q.deleted_at IS NULL'];
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
        if ($range['from'] !== null) {
            $where[] = 'DATE(q.created_at) >= :from';
            $params[':from'] = $range['from'];
        }
        if ($range['to'] !== null) {
            $where[] = 'DATE(q.created_at) <= :to';
            $params[':to'] = $range['to'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::val(
            "SELECT COUNT(*) FROM quotes q JOIN customers c ON c.id = q.customer_id WHERE $whereSql",
            $params
        );
        // 합계 카드(필터 연동): 현재 버전 총액(VAT 포함) 합
        $sumTotal = (float) Db::val(
            "SELECT COALESCE(SUM(qv.total_amount), 0)
             FROM quotes q
             JOIN customers c ON c.id = q.customer_id
             LEFT JOIN quote_versions qv ON qv.id = q.current_version_id
             WHERE $whereSql",
            $params
        );
        $p = Util::paginate($total, $page, $perPage);

        $rows = Db::all(
            "SELECT q.id, q.quote_no, q.status, q.valid_until, q.created_at, q.deleted_at,
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
            'sumTotal'=> $sumTotal,
            'range'   => $range,
            'filters' => [
                'q'         => $q,
                'status'    => $status,
                'period'    => $period,
                // custom 일 때만 URL 에 날짜 유지(프리셋은 period 만으로 재계산)
                'date_from' => $period === 'custom' ? (string) ($range['from'] ?? '') : '',
                'date_to'   => $period === 'custom' ? (string) ($range['to'] ?? '') : '',
                'trash'     => $trash ? 1 : '',
            ],
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
        // 영업기회는 선택된 고객 것만 서버 쿼리로 노출(고객 미선택 시 빈 목록 — 고객 변경 시 quotes.leads AJAX 재조회)
        $selCustomerId = (int) ($quote['customer_id'] ?? 0);
        $leads = $selCustomerId > 0 ? $this->leadOptionsFor($selCustomerId, (int) ($quote['lead_id'] ?? 0)) : [];

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
        $validUntil = Util::dateOrNull(Util::postStr('valid_until'));
        $memo = Util::postStr('memo');
        $discount = (float) str_replace(',', '', Util::postStr('discount', '0'));
        $versionNote = Util::postStr('version_note');

        if (!$customerId || !Db::val("SELECT id FROM customers WHERE id=:id AND deleted_at IS NULL", [':id' => $customerId])) {
            Response::redirect('quotes.form', $id ? ['id' => $id] : [], '고객을 선택하세요.', 'error');
        }
        // 영업기회-고객 소속 검증(서버측 강제): 삭제되지 않은 리드이며 선택 고객의 리드여야 저장 가능
        if ($leadId !== null) {
            $leadCustomer = Db::val("SELECT customer_id FROM leads WHERE id=:id AND deleted_at IS NULL", [':id' => $leadId]);
            if ($leadCustomer === null || (int) $leadCustomer !== $customerId) {
                Response::redirect('quotes.form', $id ? ['id' => $id] : [], '선택한 영업기회가 해당 고객의 영업기회가 아니거나 삭제되었습니다.', 'error');
            }
        }
        if (!in_array($status, array_keys(self::STATUS_LABELS), true)) {
            $status = 'draft';
        }

        // items 가 배열이 아닌 값(스칼라 문자열 등)으로 전송돼도 TypeError(500) 대신 빈 목록으로 처리
        $items = $this->parseItems(is_array($_POST['items'] ?? null) ? $_POST['items'] : []);
        if (!$items) {
            Response::redirect('quotes.form', $id ? ['id' => $id] : [], '견적 항목을 최소 1개 이상 입력하세요.', 'error');
        }

        $vatRate = (float) setting('vat_rate', 10);
        [$subtotal, $vat, $total] = $this->computeTotals($items, $discount, $vatRate);

        $before = $id ? Db::one("SELECT * FROM quotes WHERE id=:id", [':id' => $id]) : null;

        try {
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
        } catch (\Throwable $e) {
            error_log('[QuotesController::save] ' . $e->getMessage());
            Response::redirect('quotes.form', $id ? ['id' => $id] : [], '저장에 실패했습니다. 입력값을 확인해주세요.', 'error');
        }

        $after = Db::one("SELECT * FROM quotes WHERE id=:id", [':id' => $newId]);
        Audit::log($id ? 'quote_update' : 'quote_create', 'quotes', $newId, $before, $after);

        // 리드 단계 자동 전진(R7-F5): 견적 문서 흐름 → 원본 파이프라인 단계 동기화
        // (대시보드 깔때기·전환율이 원본 stage 를 세므로 파생 단계와 어긋나지 않게 전진)
        if ($leadId) {
            $stageMap = ['draft' => 'quote_drafting', 'sent' => 'quote_sent', 'accepted' => 'contract_pending'];
            if (isset($stageMap[$status])) {
                PipelineStageService::advanceLead($leadId, $stageMap[$status],
                    '견적 ' . (self::STATUS_LABELS[$status] ?? $status) . ' 자동 전이');
            }
        }

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
        Response::redirect('quotes.index', [], '견적이 휴지통으로 이동되었습니다.');
    }

    /** 완전삭제 차단 사유(연결 계약 존재 시, 삭제분 포함 — FK RESTRICT 기준). 없으면 null. */
    public static function purgeBlockReason(int $quoteId): ?string
    {
        $n = (int) Db::val("SELECT COUNT(*) FROM contracts WHERE quote_id = :id", [':id' => $quoteId]);
        return $n > 0 ? "연결된 기록(계약 {$n}건)이 있어 완전삭제할 수 없습니다. 기록 보존을 위해 휴지통에 유지하세요." : null;
    }

    /**
     * 견적 완전삭제 실행(정적, 테스트/액션 공용) — quote_items → quote_versions → quotes 순 물리 삭제.
     * 이미 트랜잭션 안이면 그대로 참여(중첩 begin 금지 — ContractProjectService::withTransaction 패턴).
     */
    public static function purgeQuote(int $id): void
    {
        $run = function () use ($id) {
            Db::run(
                "DELETE FROM quote_items WHERE quote_version_id IN (SELECT id FROM quote_versions WHERE quote_id = :id)",
                [':id' => $id]
            );
            Db::run("DELETE FROM quote_versions WHERE quote_id = :id", [':id' => $id]);
            Db::run("DELETE FROM quotes WHERE id = :id", [':id' => $id]);
        };
        if (Db::pdo()->inTransaction()) {
            $run();
        } else {
            Db::transaction($run);
        }
    }

    /** 완전삭제(super_admin 전용) — 휴지통(deleted_at IS NOT NULL)에 있는 견적만. */
    public function purge(): void
    {
        if (!Rbac::isRole('super_admin')) {
            Audit::log('access_denied', 'quotes', null, null, ['action' => 'quote_purge']);
            http_response_code(403);
            View::renderError(403, '접근 권한 없음', '완전삭제는 최고 관리자만 가능합니다.');
            return;
        }
        $id = (int) Util::postInt('id', 0);
        $row = Db::one("SELECT * FROM quotes WHERE id = :id AND deleted_at IS NOT NULL", [':id' => $id]);
        if (!$row) {
            Response::redirect('quotes.index', ['trash' => 1], '휴지통에 있는 견적만 완전삭제할 수 있습니다.', 'error');
        }
        $reason = self::purgeBlockReason($id);
        if ($reason !== null) {
            Response::redirect('quotes.index', ['trash' => 1], $reason, 'error');
        }
        self::purgeQuote($id);
        Audit::log('quote_purge', 'quotes', $id, $row, null);
        Response::redirect('quotes.index', ['trash' => 1], '완전삭제되었습니다.');
    }

    /** 복원(휴지통 → 정상). */
    public function restore(): void
    {
        $id = (int) Util::postInt('id', 0);
        $row = Db::one("SELECT * FROM quotes WHERE id = :id AND deleted_at IS NOT NULL", [':id' => $id]);
        if (!$row) {
            Response::redirect('quotes.index', ['trash' => 1], '휴지통에 있는 견적만 복원할 수 있습니다.', 'error');
        }
        Db::update('quotes', ['deleted_at' => null], 'id = :id', [':id' => $id]);
        Audit::log('quote_restore', 'quotes', $id, $row, null);
        Response::redirect('quotes.index', ['trash' => 1], '견적이 복원되었습니다.');
    }

    /** 고객별 영업기회 목록 AJAX(GET) — 견적 폼의 고객 변경 시 서버 쿼리로 재조회(프론트 필터링 금지). */
    public function leadOptions(): void
    {
        $customerId = Util::int('customer_id', 0);
        if ($customerId <= 0 || !Db::val("SELECT id FROM customers WHERE id=:id AND deleted_at IS NULL", [':id' => $customerId])) {
            Response::error('고객을 찾을 수 없습니다.', 404);
        }
        Response::json(['leads' => $this->leadOptionsFor($customerId, Util::int('include_lead_id', 0))]);
    }

    /**
     * 고객별 영업기회 선택지 — 서버 쿼리 단일 출처(quotes.form 초기 렌더 + quotes.leads AJAX 공용).
     * 노출 정책: 소프트삭제 제외 + 실주·취소(pipeline_stages.is_lost=1) 단계 제외.
     * 보류·계약완료(is_won) 등 나머지 단계는 재견적 수요가 있어 노출하되 단계명을 라벨에 표기한다.
     * 수정 화면의 기존 연결 리드($includeLeadId)는 정책과 무관하게 항상 포함해 데이터 정합을 유지한다.
     */
    private function leadOptionsFor(int $customerId, int $includeLeadId = 0): array
    {
        return Db::all(
            "SELECT l.id, l.work_type, ps.name AS stage_name
             FROM leads l JOIN pipeline_stages ps ON ps.id = l.stage_id
             WHERE l.deleted_at IS NULL AND l.customer_id = :cid
               AND (ps.is_lost = 0 OR l.id = :keep)
             ORDER BY l.id DESC LIMIT 300",
            [':cid' => $customerId, ':keep' => $includeLeadId]
        );
    }

    /** POST items[idx][name/area/qty/unit_price/...] 를 정리·검증. */
    private function parseItems(array $raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 100);
            if ($name === '') {
                continue;
            }
            $num = fn($k, $def = 0) => (float) str_replace(',', '', (string) ($row[$k] ?? $def));
            // 금액 클램프(unit_price/각 비용/amount 는 DECIMAL(14,0) 컬럼 — 음수·비정상 초과값이
            // DECIMAL 오버플로(SQL 오류→500)로 이어지는 것 방지). qty/area 는 DECIMAL(10,2)라 범위가 달라 별도 클램프하지 않음(기존 동작 유지).
            $clampAmt = fn($v) => min(999999999999, max(0, $v));
            $qty = $num('qty', 1) ?: 1;
            $unitPrice = $clampAmt($num('unit_price', 0));
            $area = (isset($row['area']) && $row['area'] !== '') ? $num('area', 0) : null;
            // 기본금액 = 단가(원/㎡) × 면적 × 수량. 면적 미입력(개수 기준)이면 단가 × 수량.
            $areaFactor = ($area !== null && $area > 0) ? $area : 1;
            $material    = $clampAmt($num('material_cost', 0));
            $labor       = $clampAmt($num('labor_cost', 0));
            $equipment   = $clampAmt($num('equipment_cost', 0));
            $outsourcing = $clampAmt($num('outsourcing_cost', 0));
            $etc         = $clampAmt($num('etc_cost', 0));
            $out[] = [
                'name'             => $name,
                'area'             => $area,
                'qty'              => $qty,
                'unit_price'       => $unitPrice,
                'material_cost'    => $material,
                'labor_cost'       => $labor,
                'equipment_cost'   => $equipment,
                'outsourcing_cost' => $outsourcing,
                'etc_cost'         => $etc,
                // R5 확정 산식: 금액 = 기본금액 + 재료비+인건비+장비비+외주비+기타비 (서버가 최종 권위 — 클라이언트 값 신뢰 저장 금지)
                'amount'           => $clampAmt(round($areaFactor * $qty * $unitPrice + $material + $labor + $equipment + $outsourcing + $etc, 0)),
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
