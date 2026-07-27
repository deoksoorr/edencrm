<?php
/**
 * 비용(원가) 등록/수정/취소/CSV — perm cost.manage (CSV 는 finance.view 도 허용).
 * costs 테이블이 원가의 단일 소스. 저장·취소 후 항상 CostService::recalcProject 로
 * projects.actual_cost(원가 총액 = 확정 actual 합)를 재계산한다 (트리거 금지, 브리프 §3).
 *  - 자재비 = 수량×단가, 인건비 = (일수 또는 시간)×단가(일당/시급) 자동계산.
 *    자동값과 다른 수동 금액은 adjust_reason(조정 사유) 필수 — 서버에서 검증.
 *  - 물리 삭제 금지: 취소는 cost_status='cancelled' 상태 전환(cancel 액션).
 */
class CostsController
{
    private const TYPES = ['estimate', 'actual'];
    /** 저장 폼에서 선택 가능한 상태 — cancelled 는 cancel 액션으로만 전환. */
    private const SAVABLE_STATUSES = ['draft', 'pending', 'confirmed'];

    /** 검증 실패 응답 — AJAX 는 JSON, 일반 폼 제출은 플래시와 함께 상세로 복귀. */
    private function fail(int $projectId, string $msg, int $status = 422): never
    {
        if (Response::wantsJson() || $projectId <= 0) {
            Response::error($msg, $status);
        }
        Response::redirect('projects.show', ['id' => $projectId], $msg, 'error');
    }

    /**
     * {id?, project_id, type?, category, cost_status?, item_name, spec, qty, unit, unit_price,
     *  amount?, worker_id|worker_name, work_days|work_hours, vendor, adjust_reason, spent_date, memo,
     *  receipt(파일, 선택)} — id 있으면 수정. 금액 미입력 시 자동계산값 사용.
     */
    public function save(): void
    {
        $id           = Util::postInt('id', 0) ?: 0;
        $projectId    = Util::postInt('project_id', 0) ?: 0;
        $type         = Util::postStr('type', 'actual') ?: 'actual';
        $category     = Util::postStr('category');
        $costStatus   = Util::postStr('cost_status', 'confirmed') ?: 'confirmed';
        $itemName     = Util::nullIfEmpty(Util::postStr('item_name'));
        $spec         = Util::nullIfEmpty(Util::postStr('spec'));
        $qty          = Util::postFloat('qty');
        $unit         = Util::nullIfEmpty(Util::postStr('unit'));
        $unitPrice    = Util::postFloat('unit_price');
        $amount       = Util::postFloat('amount');
        $workerId     = Util::postInt('worker_id', 0) ?: 0;
        $workerName   = Util::nullIfEmpty(Util::postStr('worker_name'));
        $workDays     = Util::postFloat('work_days');
        $workHours    = Util::postFloat('work_hours');
        $vendor       = Util::nullIfEmpty(Util::postStr('vendor'));
        $adjustReason = Util::nullIfEmpty(Util::postStr('adjust_reason'));
        $spentDate    = Util::dateOrNull(Util::postStr('spent_date'));
        $memo         = Util::nullIfEmpty(Util::postStr('memo'));

        if ($projectId <= 0) {
            Response::error('project_id 가 필요합니다.', 422);
        }
        $project = Db::one('SELECT id FROM projects WHERE id=:id AND deleted_at IS NULL', [':id' => $projectId]);
        if (!$project) {
            $this->fail(0, '프로젝트를 찾을 수 없습니다.', 404);
        }
        if (!Scope::canAccessProject($projectId)) {
            $this->fail(0, '이 프로젝트의 비용을 관리할 권한이 없습니다.', 403);
        }
        if (!in_array($type, self::TYPES, true)) {
            $this->fail($projectId, 'type 값은 estimate 또는 actual 이어야 합니다.');
        }
        if (!isset(CostService::CATEGORIES[$category])) {
            $this->fail($projectId, '비용 구분을 선택하세요 (자재비/인건비/외주비/장비비/운송비/식비/폐기물 처리비/기타).');
        }
        if (!in_array($costStatus, self::SAVABLE_STATUSES, true)) {
            $this->fail($projectId, '비용 상태는 임시 저장/확인 대기/확정 중 하나여야 합니다 (취소는 취소 버튼 사용).');
        }
        foreach ([['수량', $qty], ['단가', $unitPrice], ['금액', $amount], ['작업 일수', $workDays], ['작업 시간', $workHours]] as [$label, $v]) {
            if ($v !== null && $v < 0) {
                $this->fail($projectId, $label . '은(는) 0 이상이어야 합니다.');
            }
        }
        if ($workerId > 0) {
            $worker = Db::one('SELECT id, name FROM users WHERE id=:id AND deleted_at IS NULL', [':id' => $workerId]);
            if (!$worker) {
                $this->fail($projectId, '작업자(직원)를 찾을 수 없습니다.');
            }
        }

        // 금액 자동계산: 자재비 = 수량×단가, 인건비 = (일수 또는 시간)×단가. 수동 금액이 자동값과 다르면 조정 사유 필수.
        $auto = CostService::autoAmount($category, $qty, $unitPrice, $workDays, $workHours);
        if ($amount === null || $amount <= 0) {
            if ($auto === null) {
                $this->fail($projectId, '금액을 입력하거나, 수량×단가(인건비는 일수×일당)를 입력해 자동계산 하세요.');
            }
            $amount = (float) $auto;
        }
        $amountInt = (int) round($amount);
        if ($auto !== null && $amountInt !== $auto) {
            if ($adjustReason === null) {
                $this->fail($projectId, '자동계산 금액(' . number_format($auto) . '원)과 다른 금액을 입력하려면 조정 사유를 입력하세요.');
            }
        } else {
            $adjustReason = null; // 자동값과 동일하면 사유 불필요
        }

        $data = [
            'project_id'    => $projectId,
            'type'          => $type,
            'cost_status'   => $costStatus,
            'category'      => $category,
            'item_name'     => $itemName,
            'spec'          => $spec,
            'qty'           => $qty,
            'unit'          => $unit,
            'unit_price'    => $unitPrice,
            'amount'        => $amountInt,
            'worker_id'     => $workerId > 0 ? $workerId : null,
            'worker_name'   => $workerId > 0 ? null : $workerName,
            'work_days'     => $workDays,
            'work_hours'    => $workHours,
            'vendor'        => $vendor,
            'adjust_reason' => $adjustReason,
            'spent_date'    => $spentDate,
            'memo'          => $memo,
        ];

        if ($id > 0) {
            $before = Db::one('SELECT * FROM costs WHERE id=:id', [':id' => $id]);
            if (!$before || (int) $before['project_id'] !== $projectId) {
                $this->fail($projectId, '수정할 비용 항목을 찾을 수 없습니다.', 404);
            }
            Db::update('costs', $data, 'id=:id', [':id' => $id]);
            Audit::log('update', 'costs', $id, $before, $data);
            $flash = '비용이 수정되었습니다.';
        } else {
            $data['created_by'] = Auth::id();
            $id = Db::insert('costs', $data);
            Audit::log('create', 'costs', $id, null, $data);
            $flash = '비용이 등록되었습니다.';
        }

        // 증빙 첨부(선택) — 기존 Upload/project_files 패턴 재사용 (entity_type='cost_receipt')
        $receipt = $_FILES['receipt'] ?? null;
        if ($receipt && (int) ($receipt['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $info = Upload::save($receipt, 'projects/' . $projectId, Upload::docExts());
                $fileId = Db::insert('project_files', [
                    'project_id'    => $projectId,
                    'entity_type'   => 'cost_receipt',
                    'entity_id'     => $id,
                    'original_name' => $info['original_name'],
                    'stored_name'   => $info['stored_name'],
                    'path'          => $info['path'],
                    'size'          => $info['size'],
                    'mime'          => $info['mime'],
                    'uploaded_by'   => Auth::id(),
                ]);
                Db::update('costs', ['receipt_file_id' => $fileId], 'id=:id', [':id' => $id]);
            } catch (\RuntimeException $e) {
                CostService::recalcProject($projectId); // 금액 저장은 이미 반영 — 재계산 후 증빙 오류 안내
                $this->fail($projectId, '비용은 저장되었으나 증빙 업로드 실패: ' . $e->getMessage());
            }
        }

        CostService::recalcProject($projectId);
        if (Response::wantsJson()) {
            $data['id'] = $id;
            Response::json($data);
        }
        Response::redirect('projects.show', ['id' => $projectId], $flash);
    }

    /** {id} — 취소(cost_status='cancelled'). 물리 삭제 금지 — 원가 총액에서만 제외된다. */
    public function cancel(): void
    {
        $id = Util::postInt('id', 0) ?: 0;
        if ($id <= 0) {
            Response::error('id 가 필요합니다.', 422);
        }
        $row = Db::one('SELECT * FROM costs WHERE id=:id', [':id' => $id]);
        if (!$row) {
            Response::error('비용 항목을 찾을 수 없습니다.', 404);
        }
        $projectId = (int) $row['project_id'];
        if (!Scope::canAccessProject($projectId)) {
            $this->fail(0, '이 프로젝트의 비용을 관리할 권한이 없습니다.', 403);
        }
        if ($row['cost_status'] === 'cancelled') {
            $this->fail($projectId, '이미 취소된 비용입니다.');
        }
        Db::update('costs', ['cost_status' => 'cancelled'], 'id=:id', [':id' => $id]);
        Audit::log('cancel', 'costs', $id, $row, ['cost_status' => 'cancelled']);
        CostService::recalcProject($projectId);
        if (Response::wantsJson()) {
            Response::json(['id' => $id, 'cost_status' => 'cancelled']);
        }
        Response::redirect('projects.show', ['id' => $projectId], '비용이 취소되었습니다. 지출 총액에서 제외됩니다.');
    }

    /** 프로젝트 비용 CSV(UTF-8 BOM). 필터(cost_cat/cost_worker/cost_from/cost_to) 반영. finance.view 또는 cost.manage. */
    public function export(): void
    {
        $projectId = (int) Util::int('project_id', 0);
        if (!$projectId || !Scope::canAccessProject($projectId)) {
            http_response_code(403);
            exit('접근 권한이 없습니다.');
        }
        if (!Rbac::can('finance.view') && !Rbac::can('cost.manage')) {
            http_response_code(403);
            exit('지출 정보를 열람할 권한이 없습니다.');
        }
        $f = CostService::listFilters();
        [$where, $params] = CostService::filterWhere($projectId, $f);
        $rows = Db::all(
            "SELECT c.*, u.name AS worker_user_name FROM costs c
             LEFT JOIN users u ON u.id = c.worker_id
             WHERE $where
             ORDER BY c.spent_date ASC, c.id ASC",
            $params
        );

        // r5-uifix: 파일명 '지출'(expenses) 표기 — 라우트 키(costs.export)·DB 는 불변
        $filename = 'expenses_project' . $projectId . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM(엑셀 호환)
        $out = fopen('php://output', 'w');
        fputcsv($out, ['발생일', '비용 구분', '상태', '내용/자재명', '규격', '수량', '단위', '단가', '금액(원)', '작업자', '작업 일수', '작업 시간', '공급처', '조정 사유', '메모'], ',', '"', '\\');
        foreach ($rows as $c) {
            fputcsv($out, [
                $c['spent_date'] ?? '',
                CostService::categoryLabel((string) $c['category']),
                CostService::statusLabel((string) $c['cost_status']),
                $c['item_name'] ?? '',
                $c['spec'] ?? '',
                $c['qty'] !== null ? (float) $c['qty'] : '',
                $c['unit'] ?? '',
                $c['unit_price'] !== null ? (int) $c['unit_price'] : '',
                (int) $c['amount'],
                $c['worker_user_name'] ?? ($c['worker_name'] ?? ''),
                $c['work_days'] !== null ? (float) $c['work_days'] : '',
                $c['work_hours'] !== null ? (float) $c['work_hours'] : '',
                $c['vendor'] ?? '',
                $c['adjust_reason'] ?? '',
                $c['memo'] ?? '',
            ], ',', '"', '\\');
        }
        // 하단 합계(프로젝트 전체 확정 기준 — 필터와 무관)
        $sub = CostService::subtotals($projectId);
        fputcsv($out, [], ',', '"', '\\');
        fputcsv($out, ['자재비 합계(확정)', '', '', '', '', '', '', '', $sub['material']], ',', '"', '\\');
        fputcsv($out, ['인건비 합계(확정)', '', '', '', '', '', '', '', $sub['labor']], ',', '"', '\\');
        fputcsv($out, ['기타 지출 합계(확정)', '', '', '', '', '', '', '', $sub['other']], ',', '"', '\\');
        fputcsv($out, ['지출 총액(확정)', '', '', '', '', '', '', '', $sub['total']], ',', '"', '\\');
        fclose($out);
        exit;
    }
}
