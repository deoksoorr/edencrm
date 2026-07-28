<?php
/**
 * 프로젝트 입금·정산(R11) — 예외 프로젝트 직접 입금 CRUD + 예정 금액 + 정산 상태 컨트롤.
 * - 입금 원장은 payments 공용(contract_id/project_id 택1) — 예외 프로젝트만 직접 입금 허용.
 * - 일반(계약 연결) 프로젝트의 입금 CRUD 는 기존 계약 화면(payments.save/delete)을 사용한다.
 * - 정산 상태(settlement_status)는 StatusService::recalcProjectSettlement 규칙(D5)을 따르고,
 *   '정산 완료'는 미수금 0 + 대기(pending) 건 0 일 때만 수동 처리 가능(종결≠정산 완료).
 * - 모든 응답 JSON + 비 AJAX 폴백 리다이렉트(R10-F 재발 방지).
 */
class SettlementController
{
    /** 입금 방식 화이트리스트 — AccountingService::PAYMENT_METHODS 단일 출처(컨트롤러 교차 로드 방지). */

    /** 접근 가드 — 프로젝트 존재 + Scope. 반환: projects 행. */
    private function guardProject(int $projectId): array
    {
        if (!$projectId || !Scope::canAccessProject($projectId)) {
            $this->fail('이 프로젝트에 접근할 권한이 없습니다.', 403, $projectId);
        }
        $project = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$project) {
            $this->fail('프로젝트를 찾을 수 없습니다.', 404, $projectId);
        }
        return $project;
    }

    /** 오류 응답 — AJAX 는 JSON, 네이티브 폼은 상세로 리다이렉트. */
    private function fail(string $msg, int $code, int $projectId = 0): void
    {
        if (Response::wantsJson()) {
            Response::error($msg, $code);
        }
        if ($projectId > 0) {
            Response::redirect('projects.show', ['id' => $projectId], $msg, 'error');
        }
        Response::redirect('projects.index', [], $msg, 'error');
    }

    /** 성공 응답 — AJAX 는 JSON, 네이티브 폼은 상세 '입금·정산' 탭으로 리다이렉트. */
    private function ok(int $projectId, array $payload, string $msg): void
    {
        if (Response::wantsJson()) {
            Response::json($payload + ['settlement_status' => Db::val(
                "SELECT settlement_status FROM projects WHERE id = :id", [':id' => $projectId])]);
        }
        Response::redirect('projects.show', ['id' => $projectId], $msg);
    }

    /**
     * 입금/환불 등록·수정 (perm payment.manage 는 라우터가 강제). id=0 이면 신규.
     * 예외 프로젝트(계약 미연결)만 프로젝트 직접 입금 허용 — 일반은 계약 화면으로 안내(422).
     */
    public function paymentSave(): void
    {
        $projectId = (int) Util::postInt('project_id', 0);
        $project = $this->guardProject($projectId);
        if ((int) $project['is_exception'] !== 1 || !empty($project['contract_id'])) {
            $this->fail('일반 프로젝트의 입금은 연결된 계약 화면에서 관리합니다.', 422, $projectId);
        }

        $id = (int) Util::postInt('id', 0);
        $before = null;
        if ($id > 0) {
            $before = Db::one("SELECT * FROM payments WHERE id = :id", [':id' => $id]);
            if (!$before || (int) ($before['project_id'] ?? 0) !== $projectId) {
                $this->fail('입금 내역을 찾을 수 없습니다.', 404, $projectId);
            }
            if ($before['status'] === 'cancelled') {
                $this->fail('취소된 입금 내역은 수정할 수 없습니다. 필요 시 새 입금을 등록하세요.', 422, $projectId);
            }
        }

        $kind = Util::postStr('kind', 'payment') === 'refund' ? 'refund' : 'payment';
        $amount = (int) round((float) str_replace([',', ' '], '', Util::postStr('amount', '0')));
        if ($amount <= 0) {
            $this->fail('금액을 올바르게 입력하세요.', 422, $projectId);
        }
        $method = Util::postStr('method', '');
        $method = array_key_exists($method, AccountingService::PAYMENT_METHODS) ? $method : null;
        $memo = Util::nullIfEmpty(mb_substr(Util::postStr('memo', ''), 0, 255));
        $dueDate = Util::dateOrNull(Util::postStr('due_date'));
        $paidDate = Util::dateOrNull(Util::postStr('paid_date'));
        $status = Util::postStr('status', 'paid');
        if (!in_array($status, ['pending', 'paid'], true)) {
            $status = 'paid';
        }

        // R13: 입금 유형(계약금/중도금/잔금). 환불은 'refund' 고정.
        $payType = Util::postStr('pay_type', '');
        if ($kind === 'refund') {
            $payType = 'refund';
        } elseif (!in_array($payType, ['down', 'middle', 'balance'], true)) {
            $this->fail('입금 유형(계약금/중도금/잔금)을 선택하세요.', 422, $projectId);
        }

        if ($kind === 'refund') {
            // 환불은 확정(paid) 행으로만 기록 — 순입금·확정 매출에서 즉시 차감(취소는 별도 기능)
            $status = 'paid';
            if (!$paidDate) {
                $paidDate = date('Y-m-d');
            }
            // 환불 상한 = 현재 순입금(수정 시 자기 자신 제외) — 계약 파기 환불과 동일 통제
            $netPaid = AccountingService::projectNetPaid($projectId);
            if ($before && $before['kind'] === 'refund' && $before['status'] === 'paid') {
                $netPaid += (int) $before['amount'];
            }
            if ($amount > $netPaid) {
                $this->fail('환불 금액이 누적 입금액(순입금 ' . number_format($netPaid) . '원)을 초과할 수 없습니다.', 422, $projectId);
            }
        } elseif ($status === 'paid' && !$paidDate) {
            $paidDate = date('Y-m-d');
        }

        $data = [
            'contract_id' => null,
            'project_id'  => $projectId,
            'pay_type'    => $payType,
            'method'      => $method,
            'kind'        => $kind,
            'amount'      => $amount,
            'due_date'    => $dueDate,
            'paid_date'   => $status === 'paid' ? $paidDate : null,
            'status'      => $status,
            'memo'        => $memo,
            'payer_name'  => null, // R13: 입금자명 미수집(유형으로 대체)
        ];

        if ($id > 0) {
            Db::update('payments', $data, 'id = :id', [':id' => $id]);
            $paymentId = $id;
        } else {
            $data['created_by'] = Auth::id() ?: null;
            $paymentId = Db::insert('payments', $data);
        }

        StatusService::recalcProjectSettlement($projectId);
        $after = Db::one("SELECT * FROM payments WHERE id = :id", [':id' => $paymentId]);
        Audit::log($id ? 'payment_update' : 'payment_create', 'payments', $paymentId, $before, $after);

        $this->ok($projectId, ['id' => $paymentId],
            $kind === 'refund' ? '환불이 등록되었습니다.' : ($id ? '입금 내역이 수정되었습니다.' : '입금이 등록되었습니다.'));
    }

    /** 입금 취소 — 물리 삭제 금지, status='cancelled' 전환(계약 입금 취소와 동일 통제). */
    public function paymentCancel(): void
    {
        $id = (int) Util::postInt('id', 0);
        $payment = $id ? Db::one("SELECT * FROM payments WHERE id = :id", [':id' => $id]) : null;
        if (!$payment || empty($payment['project_id'])) {
            $this->fail('입금 내역을 찾을 수 없습니다.', 404);
        }
        $projectId = (int) $payment['project_id'];
        $this->guardProject($projectId);
        if ($payment['status'] === 'cancelled') {
            $this->fail('이미 취소된 입금 내역입니다.', 422, $projectId);
        }
        Db::update('payments', ['status' => 'cancelled'], 'id = :id', [':id' => $id]);
        StatusService::recalcProjectSettlement($projectId);
        Audit::log('payment_cancel', 'payments', $id, $payment, ['status' => 'cancelled']);

        $this->ok($projectId, ['id' => $id, 'status' => 'cancelled'], '입금이 취소되었습니다.');
    }

    /** 정산 예정 금액 저장(예외 프로젝트 전용) — 수정 전·후 금액·수정자를 감사 이력으로 보존. */
    public function expectedSave(): void
    {
        $projectId = (int) Util::postInt('project_id', 0);
        $project = $this->guardProject($projectId);
        if ((int) $project['is_exception'] !== 1 || !empty($project['contract_id'])) {
            $this->fail('예정 금액 직접 입력은 예외 프로젝트 전용입니다. 일반 프로젝트는 계약 총액을 사용합니다.', 422, $projectId);
        }
        $raw = trim(Util::postStr('expected_amount', ''));
        $expected = $raw === '' ? null : max(0, (int) round((float) str_replace([',', ' '], '', $raw)));
        $beforeVal = $project['expected_amount'] !== null ? (int) $project['expected_amount'] : null;
        if ($expected === $beforeVal) {
            $this->ok($projectId, ['expected_amount' => $expected], '변경 사항이 없습니다.');
        }

        Db::update('projects', ['expected_amount' => $expected], 'id = :id', [':id' => $projectId]);
        Audit::log('project_expected_amount_change', 'project', $projectId,
            ['expected_amount' => $beforeVal],
            ['expected_amount' => $expected, 'changed_by' => Auth::id(), 'at' => date('Y-m-d H:i:s')]);
        StatusService::recalcProjectSettlement($projectId);

        $this->ok($projectId, ['expected_amount' => $expected], '정산 예정 금액이 저장되었습니다.');
    }

    /**
     * 정산 상태 컨트롤 — action: settle(정산 완료)/hold(보류)/refunding(환불 진행)/release(자동 재계산 복귀).
     * settle 가드: 미수금 0 + 대기(pending) 입금·환불 0 — 공정 '종결'만으로 정산 완료가 되지 않는다.
     */
    public function settlementUpdate(): void
    {
        $projectId = (int) Util::postInt('project_id', 0);
        $project = $this->guardProject($projectId);
        $action = Util::postStr('action', '');
        $reason = Util::nullIfEmpty(mb_substr(Util::postStr('reason', ''), 0, 255));
        $current = (string) $project['settlement_status'];

        switch ($action) {
            case 'settle':
                $sum = AccountingService::projectPaySummary($project);
                if ($sum['outstanding'] > 0) {
                    $this->fail('미수금 ' . number_format($sum['outstanding']) . '원이 남아 있어 정산 완료 처리할 수 없습니다.', 422, $projectId);
                }
                if ($sum['pendingCnt'] > 0) {
                    $this->fail('대기 중(pending) 입금 예정 ' . $sum['pendingCnt'] . '건을 정리(입금 확정 또는 취소)한 뒤 정산 완료 처리하세요.', 422, $projectId);
                }
                $next = 'settled';
                $msg = '정산 완료 처리되었습니다.';
                break;
            case 'hold':
                $next = 'hold';
                $msg = '정산 보류로 전환되었습니다.';
                break;
            case 'refunding':
                $next = 'refunding';
                $msg = '환불 진행으로 전환되었습니다.';
                break;
            case 'release':
                // 수동 상태 해제 → 자동 규칙(D5)으로 재계산
                Db::update('projects', ['settlement_status' => 'unsettled'], 'id = :id', [':id' => $projectId]);
                $next = StatusService::recalcProjectSettlement($projectId);
                $msg = '정산 상태가 자동 계산 상태(' . (StatusService::SETTLEMENT_LABELS[$next] ?? $next) . ')로 복귀했습니다.';
                break;
            default:
                $this->fail('알 수 없는 정산 처리입니다.', 422, $projectId);
                return;
        }

        if ($action !== 'release') {
            if ($next === $current) {
                $this->fail('이미 해당 정산 상태입니다.', 422, $projectId);
            }
            Db::update('projects', ['settlement_status' => $next], 'id = :id', [':id' => $projectId]);
        }
        Audit::log('project_settlement_change', 'project', $projectId,
            ['settlement_status' => $current],
            ['settlement_status' => $next, 'action' => $action, 'reason' => $reason, 'changed_by' => Auth::id()]);

        $this->ok($projectId, ['settlement_status_after' => $next], $msg);
    }
}
