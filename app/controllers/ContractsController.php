<?php
/**
 * 계약 관리: 목록/상세(+입금 관리)/등록·수정/계약→프로젝트 전환.
 * 저장 시 계약금/중도금/잔금으로 payments 예정행을 자동 생성하고,
 * 입금 등록·삭제 후 결제상태(unpaid/partial/paid)를 재계산한다.
 */
class ContractsController
{
    /** 계약 상태 라벨·뱃지 — StatusService 단일 출처(브리프 §2 확정 enum). */
    private const STATUS_LABELS = StatusService::CONTRACT_LABELS;
    private const STATUS_BADGE  = StatusService::CONTRACT_BADGE;
    /** 폼에서 직접 선택 가능한 상태 — 파기(terminated)는 전용 파기 플로우(contracts.terminate)로만 전환. */
    private const FORM_STATUSES = ['draft', 'active', 'on_hold', 'completed', 'cancelled'];
    private const PAY_STATUS_LABELS = ['unpaid' => '미수', 'partial' => '부분입금', 'paid' => '완납'];
    private const PAY_STATUS_BADGE  = ['unpaid' => 'badge-danger', 'partial' => 'badge-warn', 'paid' => 'badge-ok'];
    private const PAYMENT_ROW_LABELS = ['pending' => '대기', 'paid' => '입금완료', 'cancelled' => '취소'];
    /** 입금 구분 라벨 — 대시보드 최근 입금 리스트(DashboardController T6)도 재사용(단일 출처, 공개 상수). */
    public const PAY_TYPE_LABELS = ['down' => '계약금', 'middle' => '중도금', 'balance' => '잔금', 'etc' => '기타'];

    /** 목록 기간 필터의 기준 컬럼(basis) 선택지 — 공통 기간 필터 파셜의 basisOptions (R4 T6). */
    private const BASIS_OPTIONS = ['contract_date' => '계약일', 'created_at' => '등록일', 'last_paid' => '최근 입금일'];
    /** 목록 정렬(T6 4종 + 기본 등록순). NULL(입금 없음) 처리 명확화:
     *  paid_recent/paid_oldest = 입금일 순 정렬, 입금 없음(NULL)은 항상 마지막.
     *  no_paid_first/no_paid_last = 기본 등록순 유지, 입금 없음 그룹만 앞/뒤로 분리. */
    private const SORT_LABELS = [
        ''              => '최근 등록순',
        'paid_recent'   => '최근 입금일순',
        'paid_oldest'   => '오래된 입금일순',
        'no_paid_first' => '입금 없음 우선',
        'no_paid_last'  => '입금 없음 후순위',
    ];

    public function index(): void
    {
        $q       = Util::str('q');
        $status  = Util::str('status');
        $payStatus = Util::str('payment_status');
        $basis   = Util::str('basis');
        $sort    = Util::str('sort');
        $period  = Util::str('period');
        $fromIn  = Util::str('date_from');
        $toIn    = Util::str('date_to');
        $page    = max(1, Util::int('page', 1));
        $perPage = (int) ($GLOBALS['config']['PAGE_SIZE'] ?? 20);

        if (!isset(self::BASIS_OPTIONS[$basis])) { $basis = 'contract_date'; } // 기준 기본 = 계약일
        if (!isset(self::SORT_LABELS[$sort])) { $sort = ''; }

        // 기간 필터(R4 공통 규약) — 견적 탭과 동일: Util::periodRange 단일 계산(자체 날짜 계산 금지).
        // 하위호환: period 없이 date_from/to 직접 진입 시 custom 으로 동작.
        if ($period === '' && ($fromIn !== '' || $toIn !== '')) {
            $period = 'custom';
        }
        if ($period !== '' && !isset(Util::PERIOD_PRESETS[$period])) {
            $period = '';
        }
        $range = Util::periodRange($period, $fromIn !== '' ? $fromIn : null, $toIn !== '' ? $toIn : null);

        $lastPaid = AccountingService::LAST_PAID_SQL; // 마지막 입금일(정상 입금 paid_date 최대) — 컬럼·정렬·기간 기준 공유
        // 기준 컬럼(basis): 계약일(DATE)/등록일(DATETIME→DATE)/최근 입금일(입금 없는 계약은 자동 제외 — 화면 도움말 표기)
        $basisCol = [
            'contract_date' => 'c.contract_date',
            'created_at'    => 'DATE(c.created_at)',
            'last_paid'     => $lastPaid,
        ][$basis];

        $where  = ['c.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(c.contract_no LIKE :kw OR cu.name LIKE :kw2)';
            $params[':kw'] = "%$q%";
            $params[':kw2'] = "%$q%";
        }
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params[':status'] = $status;
        }
        if ($payStatus !== '') {
            $where[] = 'c.payment_status = :pstatus';
            $params[':pstatus'] = $payStatus;
        }
        if ($range['from'] !== null) {
            $where[] = "$basisCol >= :from";
            $params[':from'] = $range['from'];
        }
        if ($range['to'] !== null) {
            $where[] = "$basisCol <= :to";
            $params[':to'] = $range['to'];
        }
        $whereSql = implode(' AND ', $where);

        // 합계 카드 + 건수: 목록과 동일 WHERE 1쿼리 배치 집계(AccountingService — N+1 없음).
        // R4 사용자 지시로 합계가 필터 연동으로 변경(R3 "전체 기준 고정" 대체 — worklog [contracts]).
        $totals = AccountingService::contractTotals($whereSql, $params);
        $p = Util::paginate((int) $totals['count'], $page, $perPage);

        // 정렬 4종(T6) + 기본 등록순 — NULL(입금 없음) 처리는 SORT_LABELS 주석 참조
        $orderBy = match ($sort) {
            'paid_recent'   => "($lastPaid IS NULL), $lastPaid DESC, c.id DESC",
            'paid_oldest'   => "($lastPaid IS NULL), $lastPaid ASC, c.id ASC",
            'no_paid_first' => "($lastPaid IS NULL) DESC, c.created_at DESC, c.id DESC",
            'no_paid_last'  => "($lastPaid IS NULL), c.created_at DESC, c.id DESC",
            default         => 'c.created_at DESC, c.id DESC',
        };

        // 계약별 순입금/마지막 입금일/미수금 — AccountingService SQL 조각 재사용(단일 출처, 목록 1쿼리)
        $rows = Db::all(
            "SELECT c.id, c.contract_no, c.contract_amount, c.payment_status, c.status,
                    c.start_date, c.end_date, cu.name AS customer_name,
                    " . AccountingService::PAID_SUM_SQL . " AS net_paid,
                    $lastPaid AS last_paid,
                    GREATEST(0, c.contract_amount - " . AccountingService::PAID_SUM_SQL . ") AS receivable
             FROM contracts c
             JOIN customers cu ON cu.id = c.customer_id
             WHERE $whereSql
             ORDER BY $orderBy
             LIMIT :lim OFFSET :off",
            $params + [':lim' => $p['per'], ':off' => $p['offset']]
        );

        View::render('contracts/index', [
            'title'   => '계약 관리',
            'rows'    => $rows,
            'totals'  => $totals, // 필터 연동 현금 축 합계(계약 총액·순입금·미수금 등)
            'p'       => $p,
            'range'   => $range,
            'filters' => [
                'q'              => $q,
                'status'         => $status,
                'payment_status' => $payStatus,
                'basis'          => $basis,
                'sort'           => $sort,
                'period'         => $period,
                // custom 일 때만 URL 에 날짜 유지(프리셋은 period 만으로 재계산 — 견적 탭과 동일)
                'date_from'      => $period === 'custom' ? (string) ($range['from'] ?? '') : '',
                'date_to'        => $period === 'custom' ? (string) ($range['to'] ?? '') : '',
            ],
            'basisOptions' => self::BASIS_OPTIONS,
            'sortLabels'   => self::SORT_LABELS,
            'statusLabels' => self::STATUS_LABELS,
            'statusBadge'  => self::STATUS_BADGE,
            'payStatusLabels' => self::PAY_STATUS_LABELS,
            'payStatusBadge'  => self::PAY_STATUS_BADGE,
        ]);
    }

    public function show(): void
    {
        $id = Util::int('id', 0);
        $contract = Db::one(
            "SELECT c.*, cu.name AS customer_name, cu.phone AS customer_phone, cu.site_address AS customer_site_address,
                    u.name AS sales_user_name, q.quote_no,
                    qv.version_no AS quote_version_no, cb.name AS converted_by_name
             FROM contracts c
             JOIN customers cu ON cu.id = c.customer_id
             LEFT JOIN users u ON u.id = c.sales_user_id
             LEFT JOIN quotes q ON q.id = c.quote_id
             LEFT JOIN quote_versions qv ON qv.id = c.quote_version_id
             LEFT JOIN users cb ON cb.id = c.converted_by
             WHERE c.id = :id AND c.deleted_at IS NULL",
            [':id' => $id]
        );
        if (!$contract) {
            http_response_code(404);
            View::renderError(404, '계약을 찾을 수 없음', '요청하신 계약이 존재하지 않거나 삭제되었습니다.');
            return;
        }

        $payments = Db::all(
            "SELECT * FROM payments WHERE contract_id = :id ORDER BY (due_date IS NULL), due_date ASC, id ASC",
            [':id' => $id]
        );
        $paidSum = 0.0;   // 정상 입금 합(kind=payment)
        $refundSum = 0.0; // 환불 합(kind=refund, 별도 축)
        foreach ($payments as $pm) {
            if ($pm['status'] === 'paid') {
                if (($pm['kind'] ?? 'payment') === 'refund') {
                    $refundSum += (float) $pm['amount'];
                } else {
                    $paidSum += (float) $pm['amount'];
                }
            }
        }
        $netPaid = $paidSum - $refundSum; // 순입금 = 정상 입금 − 환불
        // 미수금 = max(0, 계약 총액 − 순입금) — AccountingService::receivable() 과 동일 기준(초과 입금은 0 처리)
        $receivable = max(0.0, (float) $contract['contract_amount'] - $netPaid);
        $supplyAmount = AccountingService::supplyOf($contract);
        $vatAmount    = AccountingService::vatOf($contract);

        $project = Db::one(
            "SELECT id, project_no, status FROM projects WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1",
            [':id' => $id]
        );

        // 파기 정보 + 첨부, 상태 이력
        $termination = Db::one(
            "SELECT t.*, u.name AS processed_by_name FROM contract_terminations t
             LEFT JOIN users u ON u.id = t.processed_by
             WHERE t.contract_id = :id ORDER BY t.id DESC LIMIT 1",
            [':id' => $id]
        );
        $terminationFiles = $termination ? Db::all(
            "SELECT id, original_name, size FROM project_files
             WHERE entity_type = 'contract_termination' AND entity_id = :tid ORDER BY id",
            [':tid' => $termination['id']]
        ) : [];
        $statusHistory = Db::all(
            "SELECT h.*, u.name AS changed_by_name FROM contract_status_history h
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.contract_id = :id ORDER BY h.changed_at DESC, h.id DESC",
            [':id' => $id]
        );

        View::render('contracts/show', [
            'title'     => '계약 상세 - ' . $contract['contract_no'],
            'contract'  => $contract,
            'payments'  => $payments,
            'paidSum'   => $paidSum,
            'refundSum' => $refundSum,
            'netPaid'   => $netPaid,
            'receivable'=> $receivable,
            'supplyAmount' => $supplyAmount,
            'vatAmount'    => $vatAmount,
            'project'   => $project,
            'termination' => $termination,
            'terminationFiles' => $terminationFiles,
            'statusHistory'    => $statusHistory,
            'projectStatuses'  => StatusService::PROJECT_LABELS,
            'statusLabels' => self::STATUS_LABELS,
            'statusBadge'  => self::STATUS_BADGE,
            'payStatusLabels' => self::PAY_STATUS_LABELS,
            'payStatusBadge'  => self::PAY_STATUS_BADGE,
            'payRowLabels' => self::PAYMENT_ROW_LABELS,
            'payTypeLabels'=> self::PAY_TYPE_LABELS,
        ]);
    }

    public function form(): void
    {
        $id = Util::int('id', 0);
        $contract = null;

        if ($id > 0) {
            $contract = Db::one("SELECT * FROM contracts WHERE id = :id AND deleted_at IS NULL", [':id' => $id]);
            if (!$contract) {
                http_response_code(404);
                View::renderError(404, '계약을 찾을 수 없음', '요청하신 계약이 존재하지 않거나 삭제되었습니다.');
                return;
            }
            if ($contract['status'] === 'terminated') {
                Response::redirect('contracts.show', ['id' => $id], '파기된 계약은 수정할 수 없습니다.', 'error');
            }
        } else {
            $quoteId = Util::int('quote_id', 0);
            $contract = [
                'id' => 0, 'quote_id' => $quoteId ?: null, 'customer_id' => null,
                'contract_date' => date('Y-m-d'), 'contract_amount' => 0,
                'down_payment' => 0, 'middle_payment' => 0, 'balance_payment' => 0,
                'down_pct' => null, 'middle_pct' => null, 'balance_pct' => null,
                'start_date' => '', 'end_date' => '', 'warranty_period' => '',
                'status' => 'draft', 'special_terms' => '', 'sales_user_id' => null,
                'work_name' => '', 'site_address' => '', 'work_type' => '', 'memo' => '',
                'construction_type' => 'painting', // R8-A: 신규 기본 도장
                'quote_version_id' => null, 'original_quote_amount' => null,
                'adjust_amount' => null, 'adjust_reason' => '',
            ];
            if ($quoteId) {
                $quote = Db::one(
                    "SELECT q.customer_id, qv.total_amount FROM quotes q
                     LEFT JOIN quote_versions qv ON qv.id = q.current_version_id
                     WHERE q.id = :id AND q.deleted_at IS NULL",
                    [':id' => $quoteId]
                );
                if ($quote) {
                    $contract['customer_id'] = $quote['customer_id'];
                    $contract['contract_amount'] = $quote['total_amount'] ?? 0;
                    // 담당 영업 자동 승계(리드 담당 우선, 없으면 고객 담당) — quoteData()·save() 와 동일 규칙(단일 출처)
                    $contract['sales_user_id'] = self::quoteSalesUserId($quoteId);
                }
            }
        }

        $customers = Db::all("SELECT id, name, phone FROM customers WHERE deleted_at IS NULL ORDER BY name ASC");
        $users = Db::all("SELECT id, name, role_key FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY name ASC");
        // 취소(cancelled) 행만 남은 유형은 '기존 항목 있음'으로 치지 않는다 — 동기화가 새 예정행을 만들므로 예정일 입력 허용
        $existingPayTypes = $id > 0
            ? Db::all("SELECT DISTINCT pay_type FROM payments WHERE contract_id = :id AND status <> 'cancelled'", [':id' => $id])
            : [];
        $existingPayTypes = array_column($existingPayTypes, 'pay_type');

        // 연결 가능한 견적 목록(검색 가능한 select) — 다른 계약에 이미 연결된 견적은 선택 불가 표시
        $quotes = Db::all(
            "SELECT q.id, q.quote_no, q.status, cu.name AS customer_name,
                    qv.version_no, qv.total_amount,
                    (SELECT c2.id FROM contracts c2 WHERE c2.quote_id = q.id AND c2.deleted_at IS NULL LIMIT 1) AS linked_contract_id
             FROM quotes q
             JOIN customers cu ON cu.id = q.customer_id
             LEFT JOIN quote_versions qv ON qv.id = q.current_version_id
             WHERE q.deleted_at IS NULL
             ORDER BY q.created_at DESC, q.id DESC LIMIT 300"
        );

        View::render('contracts/form', [
            'title'     => $id ? '계약 수정' : '계약 등록',
            'contract'  => $contract,
            'canEditSales' => Rbac::isRole('super_admin'), // 담당 영업 변경은 관리자 전용(save() 서버측 강제와 동일 기준)
            'customers' => $customers,
            'users'     => $users,
            'quotes'    => $quotes,
            'existingPayTypes' => $existingPayTypes,
            // 파기(terminated)는 전용 플로우로만 — 폼 선택지에서 제외
            'statusLabels' => array_intersect_key(self::STATUS_LABELS, array_flip(self::FORM_STATUSES)),
        ]);
    }

    /**
     * 견적 데이터 AJAX(GET) — 계약 폼 자동 입력용. 읽기 전용: 원본 견적은 절대 변경하지 않는다.
     * 불러온 값은 폼의 기본값일 뿐이며 사용자가 수정할 수 있다.
     */
    public function quoteData(): void
    {
        $id = Util::int('id', 0);
        $quote = Db::one(
            "SELECT q.id, q.quote_no, q.customer_id, q.memo, q.valid_until, q.current_version_id,
                    cu.name AS customer_name, cu.site_address AS customer_site_address,
                    cu.interest_type, cu.sales_user_id AS customer_sales_user_id,
                    l.work_type AS lead_work_type, l.site_address AS lead_site_address,
                    l.sales_user_id AS lead_sales_user_id,
                    qv.id AS version_id, qv.version_no, qv.subtotal, qv.vat, qv.discount,
                    qv.total_amount, qv.note AS version_note
             FROM quotes q
             JOIN customers cu ON cu.id = q.customer_id
             LEFT JOIN leads l ON l.id = q.lead_id
             LEFT JOIN quote_versions qv ON qv.id = q.current_version_id
             WHERE q.id = :id AND q.deleted_at IS NULL",
            [':id' => $id]
        );
        if (!$quote) {
            Response::error('견적을 찾을 수 없습니다.', 404);
        }

        $linked = Db::one(
            "SELECT id, contract_no FROM contracts WHERE quote_id = :id AND deleted_at IS NULL LIMIT 1",
            [':id' => $id]
        );
        $attachments = Db::all(
            "SELECT id, original_name, size FROM project_files
             WHERE entity_type = 'quote' AND entity_id = :id ORDER BY id",
            [':id' => $id]
        );

        $total = (int) ($quote['total_amount'] ?? 0);
        $vat   = (int) ($quote['vat'] ?? 0);
        $workType = $quote['lead_work_type'] ?: ($quote['interest_type'] ?? null) ?: '도장공사';

        Response::json([
            'quote_id'         => (int) $quote['id'],
            'quote_no'         => $quote['quote_no'],
            'customer_id'      => (int) $quote['customer_id'],
            'customer_name'    => $quote['customer_name'],
            'work_name'        => trim($quote['customer_name'] . ' ' . $workType),
            'site_address'     => $quote['lead_site_address'] ?: $quote['customer_site_address'],
            'work_type'        => $workType,
            'supply_amount'    => $total - $vat,
            'vat_amount'       => $vat,
            'total_amount'     => $total,
            'special_terms'    => $quote['memo'],
            'memo'             => $quote['version_note'] !== null && $quote['version_note'] !== ''
                                    ? '견적 버전 메모: ' . $quote['version_note'] : null,
            'sales_user_id'    => $quote['lead_sales_user_id'] ?: $quote['customer_sales_user_id'],
            'quote_version_id' => $quote['version_id'] !== null ? (int) $quote['version_id'] : null,
            'version_no'       => $quote['version_no'] !== null ? (int) $quote['version_no'] : null,
            'linked_contract'  => $linked,
            'attachments'      => $attachments,
        ]);
    }

    /** 견적의 승계 담당 영업(단일 출처) — 리드 담당 우선, 없으면 고객 담당(quoteData 응답과 동일 규칙). */
    private static function quoteSalesUserId(int $quoteId): ?int
    {
        $v = Db::val(
            "SELECT COALESCE(l.sales_user_id, cu.sales_user_id)
             FROM quotes q
             JOIN customers cu ON cu.id = q.customer_id
             LEFT JOIN leads l ON l.id = q.lead_id
             WHERE q.id = :id AND q.deleted_at IS NULL",
            [':id' => $quoteId]
        );
        return $v !== null ? (int) $v : null;
    }

    public function save(): void
    {
        $id = Util::postInt('id', 0);
        $customerId = Util::postInt('customer_id', 0);
        $quoteId = Util::postInt('quote_id', 0) ?: null;
        $contractDate = Util::nullIfEmpty(Util::postStr('contract_date'));
        $contractAmount = (float) str_replace(',', '', Util::postStr('contract_amount', '0'));
        $downPayment = (int) round((float) str_replace(',', '', Util::postStr('down_payment', '0')));
        $middlePayment = (int) round((float) str_replace(',', '', Util::postStr('middle_payment', '0')));
        $balancePayment = (int) round((float) str_replace(',', '', Util::postStr('balance_payment', '0')));
        $downPct = (float) str_replace(',', '', Util::postStr('down_pct', '0'));
        $middlePct = (float) str_replace(',', '', Util::postStr('middle_pct', '0'));
        $balancePct = (float) str_replace(',', '', Util::postStr('balance_pct', '0'));
        $startDate = Util::nullIfEmpty(Util::postStr('start_date'));
        $endDate = Util::nullIfEmpty(Util::postStr('end_date'));
        $warranty = Util::postStr('warranty_period');
        $status = Util::postStr('status', 'draft');
        $specialTerms = Util::postStr('special_terms');
        $salesUserId = Util::postInt('sales_user_id', 0) ?: null;
        $workName = Util::postStr('work_name');
        $siteAddress = Util::postStr('site_address');
        $workTypeIn = Util::postStr('work_type');
        $constructionType = Util::postStr('construction_type'); // R8-A: 공사유형(구분) 도장/인테리어
        $memo = Util::postStr('memo');
        $adjustReason = Util::postStr('adjust_reason');
        $downDue = Util::nullIfEmpty(Util::postStr('down_due_date'));
        $middleDue = Util::nullIfEmpty(Util::postStr('middle_due_date'));
        $balanceDue = Util::nullIfEmpty(Util::postStr('balance_due_date'));

        $backTo = function (string $msg) use ($id): void {
            Response::redirect('contracts.form', $id ? ['id' => $id] : [], $msg, 'error');
        };

        if (!$customerId || !Db::val("SELECT id FROM customers WHERE id=:id AND deleted_at IS NULL", [':id' => $customerId])) {
            $backTo('고객을 선택하세요.');
        }
        if ($contractAmount <= 0) {
            $backTo('계약금액을 올바르게 입력하세요.');
        }
        $contractAmount = (int) round($contractAmount); // 원 단위 정수 정규화 — 저장값과 split 을 동일 정수로
        if (!in_array($status, self::FORM_STATUSES, true)) {
            $status = 'draft';
        }

        // 분할 지급 검증 — 공통 산식(0~100·합계 100, 반올림 보정 잔금 귀속). 기준: 계약 총액(VAT 포함)
        try {
            $split = AccountingService::splitPayments($contractAmount, $downPct, $middlePct, $balancePct);
        } catch (\InvalidArgumentException $e) {
            $backTo($e->getMessage());
        }
        // 금액 직접 수정 후 비율과 불일치 → 저장 차단(클라이언트 JS 와 동일 규칙의 서버측 강제)
        if ($downPayment !== $split['down'] || $middlePayment !== $split['middle'] || $balancePayment !== $split['balance']) {
            $backTo('분할 금액이 비율 계산 결과와 일치하지 않습니다. 비율 또는 금액을 다시 확인하세요. (분할 지급 계산 기준: 계약 총액(VAT 포함))');
        }

        $before = $id ? Db::one("SELECT * FROM contracts WHERE id=:id", [':id' => $id]) : null;
        if ($id && !$before) {
            Response::redirect('contracts.index', [], '계약을 찾을 수 없습니다.', 'error');
        }
        if ($before && $before['status'] === 'terminated') {
            Response::redirect('contracts.show', ['id' => $id], '파기된 계약은 수정할 수 없습니다.', 'error');
        }

        // R8-A: 공사유형(구분) 화이트리스트 — 무효 값이면 기존 값 유지(수정), 신규는 도장 기본.
        //       진행(active) 전환 시 자동 생성되는 프로젝트로 승계된다(ContractProjectService).
        if (!array_key_exists($constructionType, Stages::constructionTypes())) {
            $constructionType = $before !== null ? ($before['construction_type'] ?? null) : 'painting';
        }

        // 이미 입금(paid, 순입금)된 금액이 새 계약 총액을 초과하게 되는 변경은 거부(422)
        if ($id) {
            $netPaid = AccountingService::contractNetPaid($id);
            if ($netPaid > $contractAmount) {
                $msg = '이미 입금된 금액(순입금 ' . number_format($netPaid) . '원)이 새 계약 총액(VAT 포함) '
                     . number_format($contractAmount) . '원을 초과합니다. 계약 총액을 확인하세요.';
                if (Response::wantsJson()) {
                    Response::error($msg, 422);
                }
                $backTo($msg);
            }
        }

        // 견적 연결 — 원본 견적은 절대 변경하지 않고, 전환 정보만 커널 컬럼에 보존한다.
        $conv = [
            'quote_version_id' => null, 'original_quote_amount' => null,
            'adjust_amount' => null, 'adjust_reason' => null,
            'converted_at' => null, 'converted_by' => null,
        ];
        if ($quoteId) {
            $qv = Db::one(
                "SELECT qv.id, qv.total_amount FROM quotes q
                 JOIN quote_versions qv ON qv.id = q.current_version_id
                 WHERE q.id = :id AND q.deleted_at IS NULL",
                [':id' => $quoteId]
            );
            if (!$qv) {
                $backTo('연결할 견적을 찾을 수 없습니다.');
            }
            $other = Db::val(
                "SELECT id FROM contracts WHERE quote_id = :q AND deleted_at IS NULL AND id <> :self LIMIT 1",
                [':q' => $quoteId, ':self' => $id ?: 0]
            );
            if ($other) {
                $backTo('이미 다른 계약에 연결된 견적입니다.');
            }
            if ($before && (int) $before['quote_id'] === $quoteId && $before['quote_version_id'] !== null) {
                // 최초 전환 정보 보존(원본 견적액·전환 일시·처리자 불변)
                $conv['quote_version_id']      = (int) $before['quote_version_id'];
                $conv['original_quote_amount'] = (int) $before['original_quote_amount'];
                $conv['converted_at']          = $before['converted_at'];
                $conv['converted_by']          = $before['converted_by'];
            } else {
                $conv['quote_version_id']      = (int) $qv['id'];
                $conv['original_quote_amount'] = (int) $qv['total_amount'];
                $conv['converted_at']          = date('Y-m-d H:i:s');
                $conv['converted_by']          = Auth::id() ?: null;
            }
            // 최종 계약액 − 원본 견적액 자동 계산(할인 음수/증액 양수)
            $conv['adjust_amount'] = $contractAmount - (int) $conv['original_quote_amount'];
            $conv['adjust_reason'] = $adjustReason !== '' ? mb_substr($adjustReason, 0, 255) : null;
        }

        // 담당 영업 서버측 강제 — 일반 사용자는 변경 불가(관리자 super_admin 만 자유 지정).
        //   수정: 기존 담당 유지 / 신규+견적: 견적 담당 승계(리드→고객) / 신규 직접 등록: 고객 담당(없으면 등록자 본인).
        //   비관리자 화면의 select 는 disabled(미전송)이므로 서버가 권위값을 확정한다(변조 POST 도 무시).
        $canEditSales = Rbac::isRole('super_admin');
        if (!$canEditSales) {
            if ($id) {
                $salesUserId = $before['sales_user_id'] !== null ? (int) $before['sales_user_id'] : null;
            } elseif ($quoteId) {
                $salesUserId = self::quoteSalesUserId($quoteId);
            } else {
                $salesUserId = ((int) Db::val("SELECT COALESCE(sales_user_id,0) FROM customers WHERE id=:id", [':id' => $customerId])) ?: (Auth::id() ?: null);
            }
        } elseif (!$id && !$salesUserId && $quoteId) {
            // 관리자도 미선택 시에는 견적 담당 승계
            $salesUserId = self::quoteSalesUserId($quoteId);
        }

        $data = [
            'quote_id'        => $quoteId,
            'customer_id'     => $customerId,
            'contract_date'   => $contractDate,
            'contract_amount' => $contractAmount,
            'down_payment'    => $split['down'],
            'down_pct'        => round($downPct, 2),
            'middle_payment'  => $split['middle'],
            'middle_pct'      => round($middlePct, 2),
            'balance_payment' => $split['balance'],
            'balance_pct'     => round($balancePct, 2),
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'warranty_period' => $warranty !== '' ? $warranty : null,
            'status'          => $status,
            'special_terms'   => $specialTerms !== '' ? $specialTerms : null,
            'work_name'       => $workName !== '' ? mb_substr($workName, 0, 150) : null,
            'site_address'    => $siteAddress !== '' ? mb_substr($siteAddress, 0, 255) : null,
            'work_type'       => $workTypeIn !== '' ? mb_substr($workTypeIn, 0, 50) : null,
            'construction_type' => $constructionType, // R8-A: 도장/인테리어(레거시 NULL 허용)
            'memo'            => $memo !== '' ? $memo : null,
            'sales_user_id'   => $salesUserId,
        ] + $conv;

        $vatSplit = AccountingService::computeSplit($contractAmount, $quoteId);
        $data['supply_amount'] = $vatSplit['supply'];
        $data['vat_amount']    = $vatSplit['vat'];

        $dues = ['down' => $downDue, 'middle' => $middleDue, 'balance' => $balanceDue];
        $projectResult = null;
        try {
            $contractId = Db::transaction(function () use ($id, $data, $status, $split, $dues, &$projectResult) {
                if ($id > 0) {
                    $existing = Db::one("SELECT id FROM contracts WHERE id=:id AND deleted_at IS NULL FOR UPDATE", [':id' => $id]);
                    if (!$existing) {
                        throw new RuntimeException('계약을 찾을 수 없습니다.');
                    }
                    Db::update('contracts', $data, 'id = :id', [':id' => $id]);
                    $cid = $id;
                } else {
                    $data['contract_no'] = $this->nextContractNo();
                    $data['payment_status'] = 'unpaid';
                    $cid = Db::insert('contracts', $data);
                }
                // 분할 계획 payments 동기화 — pending 예정행만 갱신, paid·환불 행 불변(같은 트랜잭션)
                self::syncPaymentPlan($cid, $split, $dues);
                // 계약 진행(active) → 프로젝트 자동 생성 — 실패 시 계약 저장까지 롤백(계약만 active 상태 금지)
                if ($status === 'active') {
                    $projectResult = ContractProjectService::ensureProject($cid, Auth::id() ?: null);
                }
                return $cid;
            });
        } catch (\Throwable $e) {
            Response::redirect('contracts.form', $id ? ['id' => $id] : [], '저장 실패: ' . $e->getMessage(), 'error');
        }

        // 계약서 파일 첨부(선택) — 계약 id 확정 후 저장
        if (!empty($_FILES['contract_file']) && ($_FILES['contract_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $saved = Upload::save($_FILES['contract_file'], 'contracts', Upload::docExts());
                $fileId = Db::insert('project_files', [
                    'project_id'    => null,
                    'entity_type'   => 'contract',
                    'entity_id'     => $contractId,
                    'original_name' => $saved['original_name'],
                    'stored_name'   => $saved['stored_name'],
                    'path'          => $saved['path'],
                    'size'          => $saved['size'],
                    'mime'          => $saved['mime'],
                    'uploaded_by'   => Auth::id(),
                ]);
                Db::update('contracts', ['contract_file_id' => $fileId], 'id = :id', [':id' => $contractId]);
            } catch (\RuntimeException $e) {
                Response::redirect('contracts.show', ['id' => $contractId], '계약은 저장되었으나 첨부파일 업로드에 실패했습니다: ' . $e->getMessage(), 'error');
            }
        }

        $this->recalcPaymentStatus($contractId);

        // 상태 이력: 신규 등록 시 초기 상태, 수정 시 상태가 바뀐 경우만 기록
        if (!$id) {
            StatusService::logContractStatus($contractId, null, $status, '계약 등록');
        } elseif ($before && $before['status'] !== $status) {
            StatusService::logContractStatus($contractId, $before['status'], $status, '계약 수정 화면에서 변경');
        }

        $after = Db::one("SELECT * FROM contracts WHERE id=:id", [':id' => $contractId]);
        Audit::log($id ? 'contract_update' : 'contract_create', 'contracts', $contractId, $before, $after);

        // 담당 영업 변경(관리자 전용 경로) — 전용 감사로그 + 연결 프로젝트 담당 동기화.
        // 성과 집계(PerformanceController·AccountingService)와 접근 범위(Scope)는 projects.sales_user_id 를
        // 사용하므로, 프로젝트가 아직 변경 전 계약 담당과 동일할 때만 함께 갱신한다
        // (프로젝트 화면에서 의도적으로 바꾼 값은 보존 — NULL 안전 비교 <=>).
        $beforeSales = ($before && $before['sales_user_id'] !== null) ? (int) $before['sales_user_id'] : null;
        if ($id && $beforeSales !== $salesUserId) {
            Audit::log('contract_sales_user_change', 'contracts', $contractId,
                ['sales_user_id' => $beforeSales], ['sales_user_id' => $salesUserId]);
            Db::run(
                "UPDATE projects SET sales_user_id = :new
                 WHERE contract_id = :cid AND deleted_at IS NULL AND (sales_user_id <=> :old)",
                [':new' => $salesUserId, ':cid' => $contractId, ':old' => $beforeSales]
            );
        }
        // 리드 단계 자동 전진(R7-F5): 계약 작성중→계약대기, 진행·보류·완료→계약완료
        // (대시보드 깔때기·전환율이 원본 stage 를 세므로 문서 흐름과 동기화 — 종결 리드는 불변)
        if ($quoteId) {
            $leadOfQuote = Db::val("SELECT lead_id FROM quotes WHERE id = :id", [':id' => $quoteId]);
            $stageTarget = $status === 'draft' ? 'contract_pending'
                : (in_array($status, ['active', 'on_hold', 'completed'], true) ? 'contract_won' : null);
            if ($leadOfQuote !== null && $stageTarget !== null) {
                PipelineStageService::advanceLead((int) $leadOfQuote, $stageTarget, '계약 ' . $status . ' 자동 전이');
            }
        }

        // 계약 금액 수정 → 연결 프로젝트 금액 동기화(T10 수식 일관성: 회사 확정매출은 계약,
        // 직원 기여매출은 프로젝트 금액을 원천으로 쓰므로 두 축이 어긋나지 않게 유지)
        if ($id && $before && ((float) $before['contract_amount'] !== (float) $data['contract_amount']
            || (float) $before['supply_amount'] !== (float) $data['supply_amount'])) {
            Db::run(
                "UPDATE projects SET contract_amount = :ca, supply_amount = :sa, vat_amount = :va
                 WHERE contract_id = :cid AND deleted_at IS NULL",
                [':ca' => $data['contract_amount'], ':sa' => $data['supply_amount'],
                 ':va' => $data['vat_amount'], ':cid' => $contractId]
            );
        }

        // active 전환 결과 안내 — 신규 생성/기존 재사용 모두 프로젝트 링크 제공
        if ($projectResult !== null
            && ($projectResult['created'] || ($before['status'] ?? null) !== 'active')) {
            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => $projectResult['created']
                    ? '계약이 저장되었습니다. 프로젝트가 자동 생성되었습니다.'
                    : '계약이 저장되었습니다. 연결된 프로젝트가 이미 존재합니다.',
                'link' => ['url' => url('projects.show', ['id' => $projectResult['project_id']]), 'label' => '프로젝트 보기 →'],
            ];
            Response::redirect('contracts.show', ['id' => $contractId]);
        }
        Response::redirect('contracts.show', ['id' => $contractId], '계약이 저장되었습니다.');
    }

    /**
     * 분할 지급 계획(payments) 동기화 — splitPayments 결과 저장과 같은 트랜잭션에서 호출.
     * 규칙(R3): 유형별 잔여 예정액 = 분할 금액 − 해당 유형 paid 합(kind='payment').
     *   - status='pending' 예정행만 갱신/정리하고, status='paid' 행과 kind='refund' 행은 절대 변경하지 않는다.
     *   - 잔여 0 이면 pending 행 삭제, pending 행이 없고 잔여>0 이면 새 예정행 생성.
     * @param array{down:int, middle:int, balance:int} $split
     * @param array{down?:?string, middle?:?string, balance?:?string} $dues 유형별 예정일(폼 입력 시에만 반영)
     */
    public static function syncPaymentPlan(int $contractId, array $split, array $dues = []): void
    {
        foreach (['down', 'middle', 'balance'] as $type) {
            $target = (int) ($split[$type] ?? 0);
            $paid = (int) Db::val(
                "SELECT COALESCE(SUM(amount),0) FROM payments
                 WHERE contract_id = :cid AND pay_type = :t AND status = 'paid' AND kind = 'payment'",
                [':cid' => $contractId, ':t' => $type]
            );
            $remain = max(0, $target - $paid);
            $pendings = Db::all(
                "SELECT id FROM payments
                 WHERE contract_id = :cid AND pay_type = :t AND status = 'pending' AND kind = 'payment'
                 ORDER BY id ASC",
                [':cid' => $contractId, ':t' => $type]
            );
            if ($remain <= 0) {
                foreach ($pendings as $p) {
                    Db::run("DELETE FROM payments WHERE id = :id AND status = 'pending'", [':id' => $p['id']]);
                }
                continue;
            }
            if ($pendings) {
                $upd = ['amount' => $remain];
                if (!empty($dues[$type])) {
                    $upd['due_date'] = $dues[$type];
                }
                Db::update('payments', $upd, "id = :id AND status = 'pending'", [':id' => $pendings[0]['id']]);
                for ($i = 1, $n = count($pendings); $i < $n; $i++) {
                    Db::run("DELETE FROM payments WHERE id = :id AND status = 'pending'", [':id' => $pendings[$i]['id']]);
                }
            } else {
                Db::insert('payments', [
                    'contract_id' => $contractId,
                    'pay_type'    => $type,
                    'amount'      => $remain,
                    'due_date'    => $dues[$type] ?? null,
                    'paid_date'   => null,
                    'status'      => 'pending',
                    'memo'        => null,
                ]);
            }
        }
    }

    /**
     * 계약 파기 — 단순 삭제 금지. 파기일/사유/처리자(자동)/환불·위약금·정산 금액/메모/첨부 기록,
     * 환불은 payments(kind='refund') 행으로 기록해 순입금·미수금 산식(브리프 §1)에 반영.
     * 프로젝트가 있으면 처리 방법(cancel/terminate/pause/keep)을 함께 적용한다.
     */
    public function terminate(): void
    {
        $id = Util::postInt('id', 0);
        $contract = Db::one("SELECT * FROM contracts WHERE id=:id AND deleted_at IS NULL", [':id' => $id]);
        if (!$contract) {
            Response::redirect('contracts.index', [], '계약을 찾을 수 없습니다.', 'error');
        }
        if (in_array($contract['status'], ['terminated', 'cancelled'], true)) {
            Response::redirect('contracts.show', ['id' => $id], '이미 파기/취소된 계약입니다.', 'error');
        }

        $date   = Util::dateOrNull(Util::postStr('terminated_date')) ?? date('Y-m-d');
        $reason = Util::postStr('reason');
        if ($reason === '') {
            Response::redirect('contracts.show', ['id' => $id], '파기 사유를 입력하세요.', 'error');
        }
        $refund     = max(0, (int) round((float) Util::postFloat('refund_amount', 0)));
        $penalty    = max(0, (int) round((float) Util::postFloat('penalty_amount', 0)));
        $settlement = max(0, (int) round((float) Util::postFloat('settlement_amount', 0)));
        $memo       = Util::nullIfEmpty(Util::postStr('memo'));

        // 환불 상한: 현재 순입금(정상 입금 − 기존 환불)을 초과할 수 없다 — AccountingService 단일 출처
        $netPaid = AccountingService::contractNetPaid($id);
        if ($refund > $netPaid) {
            Response::redirect('contracts.show', ['id' => $id], '환불 금액이 입금 총액(순입금 ' . number_format($netPaid) . '원)을 초과할 수 없습니다.', 'error');
        }

        $project = Db::one(
            "SELECT * FROM projects WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1", [':id' => $id]
        );
        $projectAction = Util::postStr('project_action');
        $projectTo = null; // 실제 적용할 프로젝트 상태(전이 규칙 검증 후)
        if ($project) {
            if (!in_array($projectAction, ['cancel', 'terminate', 'pause', 'keep'], true)) {
                Response::redirect('contracts.show', ['id' => $id], '연결된 프로젝트 처리 방법을 선택하세요.', 'error');
            }
            if ($projectAction !== 'keep') {
                if (in_array($project['status'], ['cancelled', 'terminated'], true)) {
                    $projectAction = 'keep'; // 이미 종결된 프로젝트 — 유지로 처리
                } else {
                    $map = ['cancel' => 'cancelled', 'terminate' => 'terminated', 'pause' => 'paused'];
                    $projectTo = $map[$projectAction];
                    if (!StatusService::projectTransitionAllowed((string) $project['status'], $projectTo)) {
                        Response::redirect('contracts.show', ['id' => $id],
                            '현재 프로젝트 상태에서는 선택한 처리가 허용되지 않습니다 (예: 착공 전은 취소, 진행 중은 파기/중단, 완료 후는 유지).', 'error');
                    }
                }
            }
        } else {
            $projectAction = null;
        }

        $terminationId = Db::transaction(function () use ($id, $contract, $date, $reason, $refund, $penalty, $settlement, $memo, $project, $projectAction, $projectTo) {
            $tid = Db::insert('contract_terminations', [
                'contract_id'       => $id,
                'terminated_date'   => $date,
                'reason'            => mb_substr($reason, 0, 500),
                'processed_by'      => Auth::id(),
                'refund_amount'     => $refund,
                'penalty_amount'    => $penalty,
                'settlement_amount' => $settlement,
                'project_action'    => $projectAction,
                'memo'              => $memo,
            ]);
            Db::update('contracts', ['status' => 'terminated'], 'id = :id', [':id' => $id]);
            StatusService::logContractStatus($id, $contract['status'], 'terminated', $reason);

            if ($refund > 0) {
                Db::insert('payments', [
                    'contract_id' => $id,
                    'pay_type'    => 'etc',
                    'kind'        => 'refund',
                    'amount'      => $refund,
                    'due_date'    => null,
                    'paid_date'   => $date,
                    'status'      => 'paid',
                    'memo'        => '계약 파기 환불',
                ]);
            }

            // 연결 프로젝트 처리(선택) — 전이 규칙 통과분만 상태 전환 + 이력(물리 삭제 없음, 원가·일정·파일 보존)
            if ($project && $projectTo !== null) {
                StatusService::applyProjectStatus($project, $projectTo, [
                    'effective_date' => $date,
                    'reason'         => '계약 파기(' . $contract['contract_no'] . '): ' . $reason,
                    'detail'         => ['contract_termination_id' => $tid, 'project_action' => $projectAction],
                ]);
            }
            return $tid;
        });

        $this->recalcPaymentStatus($id);

        // 첨부 파일(선택) — project_files 재사용(entity_type='contract_termination')
        if (!empty($_FILES['termination_file']) && ($_FILES['termination_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $saved = Upload::save($_FILES['termination_file'], 'contracts', Upload::docExts());
                Db::insert('project_files', [
                    'project_id'    => null,
                    'entity_type'   => 'contract_termination',
                    'entity_id'     => $terminationId,
                    'original_name' => $saved['original_name'],
                    'stored_name'   => $saved['stored_name'],
                    'path'          => $saved['path'],
                    'size'          => $saved['size'],
                    'mime'          => $saved['mime'],
                    'uploaded_by'   => Auth::id(),
                ]);
            } catch (\RuntimeException $e) {
                Response::redirect('contracts.show', ['id' => $id], '계약은 파기 처리되었으나 첨부파일 업로드에 실패했습니다: ' . $e->getMessage(), 'error');
            }
        }

        Audit::log('contract_terminate', 'contracts', $id, ['status' => $contract['status']], [
            'status' => 'terminated', 'terminated_date' => $date, 'reason' => $reason,
            'refund_amount' => $refund, 'penalty_amount' => $penalty, 'settlement_amount' => $settlement,
            'project_action' => $projectAction,
        ]);

        Response::redirect('contracts.show', ['id' => $id], '계약이 파기 처리되었습니다.');
    }

    /** 계약 → 프로젝트 전환(수동) — 자동 생성과 동일한 ContractProjectService 경유(멱등·공정 '대기중' 배치). */
    public function toProject(): void
    {
        $id = Util::postInt('id', 0);
        $contract = Db::one("SELECT * FROM contracts WHERE id=:id AND deleted_at IS NULL", [':id' => $id]);
        if (!$contract) {
            Response::error('계약을 찾을 수 없습니다.', 404);
        }
        if (in_array($contract['status'], ['terminated', 'cancelled'], true)) {
            Response::error('파기/취소된 계약은 프로젝트로 전환할 수 없습니다.', 400);
        }

        try {
            $result = ContractProjectService::ensureProject($id, Auth::id() ?: null);
        } catch (\Throwable $e) {
            Response::error('프로젝트 생성에 실패했습니다: ' . $e->getMessage(), 500);
        }
        // T10 정합: 프로젝트가 생긴 계약이 draft 로 남으면 KPI '계약 진행'·미수금 모집단에서
        // 누락된다 — 전환 시 계약을 active 로 승격하고 상태 이력을 남긴다(이력 없는 상태 변경 금지).
        if ($contract['status'] === 'draft') {
            Db::update('contracts', ['status' => 'active'], 'id = :id', [':id' => $id]);
            StatusService::logContractStatus($id, 'draft', 'active', '프로젝트 전환에 따른 자동 진행 전환');
        }
        // 리드 단계 자동 전진(R7-F5) — 프로젝트 전환 = 계약 진행 확정 → 계약완료 단계
        if (!empty($contract['quote_id'])) {
            $leadOfQuote = Db::val("SELECT lead_id FROM quotes WHERE id = :id", [':id' => (int) $contract['quote_id']]);
            if ($leadOfQuote !== null) {
                PipelineStageService::advanceLead((int) $leadOfQuote, 'contract_won', '프로젝트 전환 자동 전이');
            }
        }
        Audit::log('contract_to_project', 'contracts', $id, null, [
            'project_id' => $result['project_id'], 'created' => $result['created'],
        ]);

        if (Response::wantsJson()) {
            Response::json(['id' => $result['project_id'], 'created' => $result['created']]);
        }
        Response::redirect('projects.show', ['id' => $result['project_id']],
            $result['created'] ? '프로젝트로 전환되었습니다.' : '연결된 프로젝트가 이미 존재합니다.');
    }

    public function savePayment(): void
    {
        $id = Util::postInt('id', 0);
        $contractId = Util::postInt('contract_id', 0);
        $contract = Db::one("SELECT * FROM contracts WHERE id=:id AND deleted_at IS NULL", [':id' => $contractId]);
        if (!$contract) {
            Response::error('계약을 찾을 수 없습니다.', 404);
        }

        $payType = Util::postStr('pay_type', 'etc');
        if (!array_key_exists($payType, self::PAY_TYPE_LABELS)) {
            $payType = 'etc';
        }
        $amount = (float) str_replace(',', '', Util::postStr('amount', '0'));
        $dueDate = Util::nullIfEmpty(Util::postStr('due_date'));
        $paidDate = Util::nullIfEmpty(Util::postStr('paid_date'));
        $status = Util::postStr('status', 'pending');
        if (!in_array($status, ['pending', 'paid'], true)) {
            $status = 'pending';
        }
        $memo = Util::postStr('memo');
        if ($amount <= 0) {
            Response::error('입금액을 올바르게 입력하세요.', 400);
        }
        if ($status === 'paid' && !$paidDate) {
            $paidDate = date('Y-m-d');
        }

        // R11: 입금 방식·입금자명(선택) — 프로젝트 직접 입금과 동일 화이트리스트
        $method = Util::postStr('method', '');
        $method = array_key_exists($method, AccountingService::PAYMENT_METHODS) ? $method : null;
        $payerName = Util::nullIfEmpty(mb_substr(Util::postStr('payer_name', ''), 0, 100));

        $data = [
            'contract_id' => $contractId,
            'pay_type'    => $payType,
            'method'      => $method,
            'amount'      => $amount,
            'due_date'    => $dueDate,
            'paid_date'   => $status === 'paid' ? $paidDate : null,
            'status'      => $status,
            'memo'        => $memo !== '' ? $memo : null,
            'payer_name'  => $payerName,
        ];

        $before = $id ? Db::one("SELECT * FROM payments WHERE id=:id", [':id' => $id]) : null;
        if ($id > 0) {
            if (!$before || (int) $before['contract_id'] !== $contractId) {
                Response::error('입금 내역을 찾을 수 없습니다.', 404);
            }
            if ($before['status'] === 'cancelled') {
                Response::error('취소된 입금 내역은 수정할 수 없습니다. 필요 시 새 입금을 등록하세요.', 422);
            }
            Db::update('payments', $data, 'id = :id', [':id' => $id]);
            $paymentId = $id;
        } else {
            $data['created_by'] = Auth::id() ?: null;
            $paymentId = Db::insert('payments', $data);
        }

        $this->recalcPaymentStatus($contractId);
        $after = Db::one("SELECT * FROM payments WHERE id=:id", [':id' => $paymentId]);
        Audit::log($id ? 'payment_update' : 'payment_create', 'payments', $paymentId, $before, $after);

        Response::json(['id' => $paymentId]);
    }

    /**
     * 입금 취소(구 삭제) — 현금 데이터는 물리 삭제 금지(costs 취소 패턴과 통제 수준 일치, R3 acctverify).
     * status='cancelled' 전환으로 대체: 순입금·미수금·입금 총액 집계(status='paid')와
     * 예정행 동기화·알림(status='pending')에서 자동 제외되고, 목록에는 '취소' 상태로 남아 대사가 가능하다.
     */
    public function deletePayment(): void
    {
        $id = Util::postInt('id', 0);
        $payment = Db::one("SELECT * FROM payments WHERE id=:id", [':id' => $id]);
        if (!$payment) {
            Response::error('입금 내역을 찾을 수 없습니다.', 404);
        }
        if ($payment['status'] === 'cancelled') {
            Response::error('이미 취소된 입금 내역입니다.', 422);
        }
        Db::update('payments', ['status' => 'cancelled'], 'id = :id', [':id' => $id]);
        $this->recalcPaymentStatus((int) $payment['contract_id']);
        Audit::log('payment_cancel', 'payments', $id, $payment, ['status' => 'cancelled']);

        Response::json(['id' => $id, 'status' => 'cancelled']);
    }

    /** 결제상태 재계산 — 순입금(payment−refund) 기준(StatusService 단일 출처). */
    private function recalcPaymentStatus(int $contractId): void
    {
        StatusService::recalcContractPaymentStatus($contractId);
    }

    /** 계약번호: C-YYYYMMDD-nnn */
    private function nextContractNo(): string
    {
        $prefix = 'C-' . date('Ymd') . '-';
        $last = Db::val(
            "SELECT contract_no FROM contracts WHERE contract_no LIKE :p ORDER BY contract_no DESC LIMIT 1 FOR UPDATE",
            [':p' => $prefix . '%']
        );
        $seq = $last ? ((int) substr($last, -3) + 1) : 1;
        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

}
