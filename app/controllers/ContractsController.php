<?php
/**
 * 계약 관리: 목록/상세(+입금 관리)/등록·수정/계약→프로젝트 전환.
 * 저장 시 계약금/중도금/잔금으로 payments 예정행을 자동 생성하고,
 * 입금 등록·삭제 후 결제상태(unpaid/partial/paid)를 재계산한다.
 */
class ContractsController
{
    private const STATUS_LABELS = [
        'draft'      => '임시저장',
        'active'     => '진행중',
        'completed'  => '완료',
        'terminated' => '해지',
    ];
    private const STATUS_BADGE = [
        'draft'      => 'badge-muted',
        'active'     => 'badge-info',
        'completed'  => 'badge-ok',
        'terminated' => 'badge-danger',
    ];
    private const PAY_STATUS_LABELS = ['unpaid' => '미수', 'partial' => '부분입금', 'paid' => '완납'];
    private const PAY_STATUS_BADGE  = ['unpaid' => 'badge-danger', 'partial' => 'badge-warn', 'paid' => 'badge-ok'];
    private const PAYMENT_ROW_LABELS = ['pending' => '대기', 'paid' => '입금완료'];
    private const PAY_TYPE_LABELS = ['down' => '계약금', 'middle' => '중도금', 'balance' => '잔금', 'etc' => '기타'];

    public function index(): void
    {
        $q       = Util::str('q');
        $status  = Util::str('status');
        $payStatus = Util::str('payment_status');
        $page    = max(1, Util::int('page', 1));
        $perPage = (int) ($GLOBALS['config']['PAGE_SIZE'] ?? 20);

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
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::val(
            "SELECT COUNT(*) FROM contracts c JOIN customers cu ON cu.id = c.customer_id WHERE $whereSql",
            $params
        );
        $p = Util::paginate($total, $page, $perPage);

        $rows = Db::all(
            "SELECT c.id, c.contract_no, c.contract_amount, c.payment_status, c.status,
                    c.start_date, c.end_date, cu.name AS customer_name,
                    c.contract_amount - COALESCE((SELECT SUM(pm.amount) FROM payments pm WHERE pm.contract_id = c.id AND pm.status='paid'),0) AS receivable
             FROM contracts c
             JOIN customers cu ON cu.id = c.customer_id
             WHERE $whereSql
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT :lim OFFSET :off",
            $params + [':lim' => $p['per'], ':off' => $p['offset']]
        );

        View::render('contracts/index', [
            'title'   => '계약 관리',
            'rows'    => $rows,
            'p'       => $p,
            'filters' => ['q' => $q, 'status' => $status, 'payment_status' => $payStatus],
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
                    u.name AS sales_user_name, q.quote_no
             FROM contracts c
             JOIN customers cu ON cu.id = c.customer_id
             LEFT JOIN users u ON u.id = c.sales_user_id
             LEFT JOIN quotes q ON q.id = c.quote_id
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
        $paidSum = 0.0;
        foreach ($payments as $pm) {
            if ($pm['status'] === 'paid') {
                $paidSum += (float) $pm['amount'];
            }
        }
        $receivable = (float) $contract['contract_amount'] - $paidSum;

        $project = Db::one(
            "SELECT id, project_no, status FROM projects WHERE contract_id = :id AND deleted_at IS NULL LIMIT 1",
            [':id' => $id]
        );

        View::render('contracts/show', [
            'title'     => '계약 상세 - ' . $contract['contract_no'],
            'contract'  => $contract,
            'payments'  => $payments,
            'paidSum'   => $paidSum,
            'receivable'=> $receivable,
            'project'   => $project,
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
        } else {
            $quoteId = Util::int('quote_id', 0);
            $contract = [
                'id' => 0, 'quote_id' => $quoteId ?: null, 'customer_id' => null,
                'contract_date' => date('Y-m-d'), 'contract_amount' => 0,
                'down_payment' => 0, 'middle_payment' => 0, 'balance_payment' => 0,
                'start_date' => '', 'end_date' => '', 'warranty_period' => '',
                'status' => 'draft', 'special_terms' => '', 'sales_user_id' => null,
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
                }
            }
        }

        $customers = Db::all("SELECT id, name, phone FROM customers WHERE deleted_at IS NULL ORDER BY name ASC");
        $users = Db::all("SELECT id, name, role_key FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY name ASC");
        $existingPayTypes = $id > 0
            ? Db::all("SELECT DISTINCT pay_type FROM payments WHERE contract_id = :id", [':id' => $id])
            : [];
        $existingPayTypes = array_column($existingPayTypes, 'pay_type');

        View::render('contracts/form', [
            'title'     => $id ? '계약 수정' : '계약 등록',
            'contract'  => $contract,
            'customers' => $customers,
            'users'     => $users,
            'existingPayTypes' => $existingPayTypes,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function save(): void
    {
        $id = Util::postInt('id', 0);
        $customerId = Util::postInt('customer_id', 0);
        $quoteId = Util::postInt('quote_id', 0) ?: null;
        $contractDate = Util::nullIfEmpty(Util::postStr('contract_date'));
        $contractAmount = (float) str_replace(',', '', Util::postStr('contract_amount', '0'));
        $downPayment = (float) str_replace(',', '', Util::postStr('down_payment', '0'));
        $middlePayment = (float) str_replace(',', '', Util::postStr('middle_payment', '0'));
        $balancePayment = (float) str_replace(',', '', Util::postStr('balance_payment', '0'));
        $startDate = Util::nullIfEmpty(Util::postStr('start_date'));
        $endDate = Util::nullIfEmpty(Util::postStr('end_date'));
        $warranty = Util::postStr('warranty_period');
        $status = Util::postStr('status', 'draft');
        $specialTerms = Util::postStr('special_terms');
        $salesUserId = Util::postInt('sales_user_id', 0) ?: null;
        $downDue = Util::nullIfEmpty(Util::postStr('down_due_date'));
        $middleDue = Util::nullIfEmpty(Util::postStr('middle_due_date'));
        $balanceDue = Util::nullIfEmpty(Util::postStr('balance_due_date'));

        if (!$customerId || !Db::val("SELECT id FROM customers WHERE id=:id AND deleted_at IS NULL", [':id' => $customerId])) {
            Response::redirect('contracts.form', $id ? ['id' => $id] : [], '고객을 선택하세요.', 'error');
        }
        if ($contractAmount <= 0) {
            Response::redirect('contracts.form', $id ? ['id' => $id] : [], '계약금액을 올바르게 입력하세요.', 'error');
        }
        if (!array_key_exists($status, self::STATUS_LABELS)) {
            $status = 'draft';
        }

        $before = $id ? Db::one("SELECT * FROM contracts WHERE id=:id", [':id' => $id]) : null;

        $data = [
            'quote_id'        => $quoteId,
            'customer_id'     => $customerId,
            'contract_date'   => $contractDate,
            'contract_amount' => $contractAmount,
            'down_payment'    => $downPayment,
            'middle_payment'  => $middlePayment,
            'balance_payment' => $balancePayment,
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'warranty_period' => $warranty !== '' ? $warranty : null,
            'status'          => $status,
            'special_terms'   => $specialTerms !== '' ? $specialTerms : null,
            'sales_user_id'   => $salesUserId,
        ];

        $contractId = Db::transaction(function () use ($id, $data) {
            if ($id > 0) {
                $existing = Db::one("SELECT id FROM contracts WHERE id=:id AND deleted_at IS NULL FOR UPDATE", [':id' => $id]);
                if (!$existing) {
                    throw new RuntimeException('계약을 찾을 수 없습니다.');
                }
                Db::update('contracts', $data, 'id = :id', [':id' => $id]);
                return $id;
            }
            $data['contract_no'] = $this->nextContractNo();
            $data['payment_status'] = 'unpaid';
            return Db::insert('contracts', $data);
        });

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

        // 계약금/중도금/잔금 payments 예정행 자동 생성 — 이미 있으면 중복 생성 금지
        $plan = [
            'down'    => [$downPayment, $downDue],
            'middle'  => [$middlePayment, $middleDue],
            'balance' => [$balancePayment, $balanceDue],
        ];
        foreach ($plan as $type => [$amount, $due]) {
            if ($amount <= 0) {
                continue;
            }
            $exists = Db::val(
                "SELECT id FROM payments WHERE contract_id=:cid AND pay_type=:t LIMIT 1",
                [':cid' => $contractId, ':t' => $type]
            );
            if ($exists) {
                continue;
            }
            Db::insert('payments', [
                'contract_id' => $contractId,
                'pay_type'    => $type,
                'amount'      => $amount,
                'due_date'    => $due,
                'paid_date'   => null,
                'status'      => 'pending',
                'memo'        => null,
            ]);
        }

        $this->recalcPaymentStatus($contractId);

        $after = Db::one("SELECT * FROM contracts WHERE id=:id", [':id' => $contractId]);
        Audit::log($id ? 'contract_update' : 'contract_create', 'contracts', $contractId, $before, $after);

        Response::redirect('contracts.show', ['id' => $contractId], '계약이 저장되었습니다.');
    }

    /** 계약 → 프로젝트 전환. */
    public function toProject(): void
    {
        $id = Util::postInt('id', 0);
        $contract = Db::one("SELECT * FROM contracts WHERE id=:id AND deleted_at IS NULL", [':id' => $id]);
        if (!$contract) {
            Response::error('계약을 찾을 수 없습니다.', 404);
        }
        $already = Db::val("SELECT id FROM projects WHERE contract_id=:id AND deleted_at IS NULL", [':id' => $id]);
        if ($already) {
            Response::error('이미 프로젝트로 전환된 계약입니다.', 400);
        }

        $customer = Db::one("SELECT name, site_address, interest_type FROM customers WHERE id=:id", [':id' => $contract['customer_id']]);
        $workType = null;
        if ($contract['quote_id']) {
            $workType = Db::val(
                "SELECT l.work_type FROM quotes q JOIN leads l ON l.id = q.lead_id WHERE q.id = :qid",
                [':qid' => $contract['quote_id']]
            );
        }
        $workType = $workType ?: ($customer['interest_type'] ?? null) ?: '도장공사';

        $firstStageId = Db::val("SELECT id FROM process_stages ORDER BY sort_order ASC LIMIT 1");

        $projectId = Db::transaction(function () use ($contract, $customer, $workType, $firstStageId) {
            $projectNo = $this->nextProjectNo();
            $name = trim(($customer['name'] ?? '고객') . ' ' . $workType);
            return Db::insert('projects', [
                'project_no'       => $projectNo,
                'name'             => $name,
                'customer_id'      => $contract['customer_id'],
                'contract_id'      => $contract['id'],
                'site_address'     => $customer['site_address'] ?? null,
                'work_type'        => $workType,
                'contract_amount'  => $contract['contract_amount'],
                'estimated_cost'   => 0,
                'actual_cost'      => 0,
                'process_stage_id' => $firstStageId,
                'status'           => 'preparing',
                'contract_date'    => $contract['contract_date'],
                'start_date'       => $contract['start_date'],
                'end_date'         => $contract['end_date'],
                'sales_user_id'    => $contract['sales_user_id'],
                'progress'         => 0,
                'contribution_mode'=> 'ratio',
            ]);
        });

        Audit::log('contract_to_project', 'contracts', $contract['id'], null, ['project_id' => $projectId]);

        if (Response::wantsJson()) {
            Response::json(['id' => $projectId]);
        }
        Response::redirect('projects.show', ['id' => $projectId], '프로젝트로 전환되었습니다.');
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

        $data = [
            'contract_id' => $contractId,
            'pay_type'    => $payType,
            'amount'      => $amount,
            'due_date'    => $dueDate,
            'paid_date'   => $status === 'paid' ? $paidDate : null,
            'status'      => $status,
            'memo'        => $memo !== '' ? $memo : null,
        ];

        $before = $id ? Db::one("SELECT * FROM payments WHERE id=:id", [':id' => $id]) : null;
        if ($id > 0) {
            if (!$before || (int) $before['contract_id'] !== $contractId) {
                Response::error('입금 내역을 찾을 수 없습니다.', 404);
            }
            Db::update('payments', $data, 'id = :id', [':id' => $id]);
            $paymentId = $id;
        } else {
            $paymentId = Db::insert('payments', $data);
        }

        $this->recalcPaymentStatus($contractId);
        $after = Db::one("SELECT * FROM payments WHERE id=:id", [':id' => $paymentId]);
        Audit::log($id ? 'payment_update' : 'payment_create', 'payments', $paymentId, $before, $after);

        Response::json(['id' => $paymentId]);
    }

    public function deletePayment(): void
    {
        $id = Util::postInt('id', 0);
        $payment = Db::one("SELECT * FROM payments WHERE id=:id", [':id' => $id]);
        if (!$payment) {
            Response::error('입금 내역을 찾을 수 없습니다.', 404);
        }
        Db::run("DELETE FROM payments WHERE id = :id", [':id' => $id]);
        $this->recalcPaymentStatus((int) $payment['contract_id']);
        Audit::log('payment_delete', 'payments', $id, $payment, null);

        Response::json(['id' => $id]);
    }

    /** 결제상태 재계산: 미수금 = 계약금액 - Σ(입금완료). 전액입금=paid, 일부=partial, 0=unpaid. */
    private function recalcPaymentStatus(int $contractId): void
    {
        $contract = Db::one("SELECT contract_amount FROM contracts WHERE id=:id", [':id' => $contractId]);
        if (!$contract) {
            return;
        }
        $paidSum = (float) Db::val(
            "SELECT COALESCE(SUM(amount),0) FROM payments WHERE contract_id=:id AND status='paid'",
            [':id' => $contractId]
        );
        $amount = (float) $contract['contract_amount'];
        if ($paidSum <= 0) {
            $status = 'unpaid';
        } elseif ($paidSum >= $amount && $amount > 0) {
            $status = 'paid';
        } else {
            $status = 'partial';
        }
        Db::update('contracts', ['payment_status' => $status], 'id = :id', [':id' => $contractId]);
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

    /** 프로젝트번호: P-YYYYMMDD-nnn */
    private function nextProjectNo(): string
    {
        $prefix = 'P-' . date('Ymd') . '-';
        $last = Db::val(
            "SELECT project_no FROM projects WHERE project_no LIKE :p ORDER BY project_no DESC LIMIT 1 FOR UPDATE",
            [':p' => $prefix . '%']
        );
        $seq = $last ? ((int) substr($last, -3) + 1) : 1;
        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
