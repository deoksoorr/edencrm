<?php
/**
 * 계약 '진행(active)' 전환 → 프로젝트 자동 생성 (R3 커널 확정 흐름).
 *   계약 active → 프로젝트 자동 생성(계약 1:1, uq_projects_contract UNIQUE)
 *   → status='in_progress' → ProcessService::initWaiting() 으로 공정 '대기중' 배치
 * 멱등: 기존 프로젝트(소프트삭제 제외)가 있으면 재사용(created=false) —
 * 진행→보류→재진행 반복에도 중복 생성되지 않는다. UNIQUE 충돌 캐치 시에도 기존 프로젝트 반환.
 * 원자성: 호출측 트랜잭션이 있으면 그대로 참여(중첩 begin 금지), 없으면 자체 Db::transaction.
 * 실패 시 예외 전파 → 호출측(계약 저장 트랜잭션)이 롤백해 '계약만 active, 프로젝트 누락' 상태를 금지한다.
 */
class ContractProjectService
{
    /**
     * 계약의 연결 프로젝트를 보장한다(멱등).
     * @return array{project_id:int, created:bool}
     */
    public static function ensureProject(int $contractId, ?int $userId): array
    {
        // 서버 측 선조회(소프트삭제 제외) — 있으면 재사용
        $existing = Db::one(
            "SELECT id FROM projects WHERE contract_id = :cid AND deleted_at IS NULL LIMIT 1",
            [':cid' => $contractId]
        );
        if ($existing) {
            return ['project_id' => (int) $existing['id'], 'created' => false];
        }

        try {
            return self::withTransaction(fn() => self::createFromContract($contractId, $userId));
        } catch (\Throwable $e) {
            // 실패 감사로그(처리자·일시) — 트랜잭션 롤백과 무관하게 남긴다
            Audit::log('project_auto_create_failed', 'project', null, null, [
                'contract_id' => $contractId,
                'user_id'     => $userId,
                'error'       => mb_substr($e->getMessage(), 0, 300),
                'at'          => date('Y-m-d H:i:s'),
            ]);
            throw $e;
        }
    }

    /** 이미 트랜잭션 안이면 그대로 실행(계약 저장과 원자 결합), 아니면 새 트랜잭션 래핑. */
    private static function withTransaction(callable $fn)
    {
        if (Db::pdo()->inTransaction()) {
            return $fn();
        }
        return Db::transaction($fn);
    }

    /** @return array{project_id:int, created:bool} */
    private static function createFromContract(int $contractId, ?int $userId): array
    {
        $contract = Db::one(
            "SELECT * FROM contracts WHERE id = :id AND deleted_at IS NULL FOR UPDATE",
            [':id' => $contractId]
        );
        if (!$contract) {
            throw new RuntimeException('계약을 찾을 수 없습니다.');
        }

        // 잠금 후 재확인 — 동시 요청이 이미 만들었으면 재사용
        $existing = Db::one(
            "SELECT id FROM projects WHERE contract_id = :cid AND deleted_at IS NULL LIMIT 1",
            [':cid' => $contractId]
        );
        if ($existing) {
            return ['project_id' => (int) $existing['id'], 'created' => false];
        }

        $customer = Db::one(
            "SELECT name, site_address, interest_type FROM customers WHERE id = :id",
            [':id' => $contract['customer_id']]
        );

        // 공사 유형: 계약 → 견적의 리드 → 고객 관심공종 → 기본값
        $workType = trim((string) ($contract['work_type'] ?? ''));
        if ($workType === '' && !empty($contract['quote_id'])) {
            $workType = (string) (Db::val(
                "SELECT l.work_type FROM quotes q JOIN leads l ON l.id = q.lead_id WHERE q.id = :qid",
                [':qid' => $contract['quote_id']]
            ) ?? '');
        }
        if ($workType === '') {
            $workType = (string) (($customer['interest_type'] ?? null) ?: '도장공사');
        }
        $name = trim((string) ($contract['work_name'] ?? ''));
        if ($name === '') {
            $name = trim(($customer['name'] ?? '고객') . ' ' . $workType);
        }

        $contractAmount = (int) $contract['contract_amount'];
        $vatAmount = $contract['vat_amount'] !== null
            ? (int) $contract['vat_amount']
            : AccountingService::deriveVat($contractAmount);

        $data = [
            'project_no'        => self::nextProjectNo(),
            'name'              => $name,
            'customer_id'       => (int) $contract['customer_id'],
            'contract_id'       => $contractId,
            'site_address'      => $contract['site_address'] ?: ($customer['site_address'] ?? null),
            'work_type'         => $workType,
            // R8-A: 공사유형(구분) 승계 — 계약의 도장/인테리어를 프로젝트로(무효·NULL 은 미지정 유지 → 양쪽 보드 노출)
            'construction_type' => in_array((string) ($contract['construction_type'] ?? ''), ['painting', 'interior'], true)
                                     ? $contract['construction_type'] : null,
            'contract_amount'   => $contractAmount,
            'supply_amount'     => $contract['supply_amount'] !== null ? (int) $contract['supply_amount'] : $contractAmount - $vatAmount,
            'vat_amount'        => $vatAmount,
            'estimated_cost'    => 0,
            'actual_cost'       => 0,
            'process_stage_id'  => null, // 공정은 ProcessService::initWaiting 이 배치(직접 지정 금지)
            'status'            => 'in_progress',
            'contract_date'     => $contract['contract_date'],
            'start_date'        => $contract['start_date'],
            'end_date'          => $contract['end_date'],
            'sales_user_id'     => $contract['sales_user_id'],
            'progress'          => 0,
            'contribution_mode' => 'ratio',
            'memo'              => $contract['memo'] ?? null,
        ];

        try {
            $projectId = Db::insert('projects', $data);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            // uq_projects_contract UNIQUE 충돌 — 동시 생성 경합이면 기존 프로젝트 반환
            $dup = Db::one(
                "SELECT id FROM projects WHERE contract_id = :cid AND deleted_at IS NULL LIMIT 1",
                [':cid' => $contractId]
            );
            if ($dup) {
                return ['project_id' => (int) $dup['id'], 'created' => false];
            }
            // 소프트삭제된 프로젝트가 UNIQUE 를 점유 — 자동 복구 대신 관리자 확인 유도(계약 전환도 롤백)
            throw new RuntimeException('이 계약에는 삭제된 프로젝트가 연결되어 있어 자동 생성할 수 없습니다. 관리자 확인이 필요합니다.');
        }

        // 공정 '대기중' 자동 배치(커널 ProcessService — process_entered_at 세팅 + 이력 is_auto=1)
        ProcessService::initWaiting($projectId, $userId, true, '계약 진행 자동 생성');

        // 프로젝트 상태 이력(StatusService 패턴) + 성공 감사로그(처리자·일시)
        StatusService::logProjectStatus($projectId, null, 'in_progress',
            '계약 진행(active) 전환 자동 생성(' . $contract['contract_no'] . ')');
        Audit::log('project_auto_create', 'project', $projectId, null, [
            'contract_id' => $contractId,
            'contract_no' => $contract['contract_no'],
            'user_id'     => $userId,
            'at'          => date('Y-m-d H:i:s'),
        ]);

        return ['project_id' => (int) $projectId, 'created' => true];
    }

    /** 프로젝트번호: PYYYY-nnnn (ProjectsController 와 동일 포맷). */
    private static function nextProjectNo(): string
    {
        $year = date('Y');
        for ($i = 0; $i < 5; $i++) {
            $count = (int) Db::val(
                "SELECT COUNT(*) FROM projects WHERE project_no LIKE :p",
                [':p' => "P{$year}-%"]
            );
            $no = sprintf('P%s-%04d', $year, $count + 1 + $i);
            if (!Db::val("SELECT 1 FROM projects WHERE project_no = :no", [':no' => $no])) {
                return $no;
            }
        }
        return 'P' . $year . '-' . substr((string) uniqid(), -6);
    }
}
