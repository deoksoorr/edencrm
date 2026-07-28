<?php
/**
 * 계약·프로젝트 상태 단일 출처 (R2 status).
 * - 확정 enum 라벨·뱃지 (브리프 §2)
 * - 프로젝트 상태 전이 규칙(서버측 강제) + 사유 필수 전환
 * - 상태 이력(contract_status_history / project_status_history) 기록
 * - 계약 결제상태(payment_status) 재계산 — 순입금(payment−refund) 기준
 *
 * 상태 의미(브리프 확정):
 *   프로젝트 취소(cancelled)   = 착공 전 철회
 *   프로젝트 파기(terminated)  = 진행 중 계약관계 종료
 *   일시 중단(paused)          = 재개 가능 일시 정지
 *   정산 완료(settled)         = 완료 후 대금 정산까지 종료 (completed 에서만 진입)
 */
class StatusService
{
    // ── 계약 상태 (draft/active/on_hold/completed/cancelled/terminated) ──
    public const CONTRACT_LABELS = [
        'draft'      => '작성중',
        'active'     => '계약 진행',
        'on_hold'    => '계약 보류',
        'completed'  => '계약 완료',
        'cancelled'  => '계약 취소',
        'terminated' => '계약 파기',
    ];
    public const CONTRACT_BADGE = [
        'draft'      => 'badge-muted',
        'active'     => 'badge-info',
        'on_hold'    => 'badge-warn',
        'completed'  => 'badge-ok',
        'cancelled'  => 'badge-muted',
        'terminated' => 'badge-danger',
    ];

    // ── 프로젝트 상태 (8종) ──
    public const PROJECT_LABELS = [
        'preparing'   => '진행 예정',
        'in_progress' => '진행 중',
        'paused'      => '일시 중단',
        'cancelled'   => '취소',
        'terminated'  => '파기',
        'completed'   => '완료',
        'warranty'    => '하자보수',
        'settled'     => '정산 완료',
    ];
    public const PROJECT_BADGE = [
        'preparing'   => 'badge-muted',
        'in_progress' => 'badge-info',
        'paused'      => 'badge-warn',
        'cancelled'   => 'badge-muted',
        'terminated'  => 'badge-danger',
        'completed'   => 'badge-ok',
        'warranty'    => 'badge-warn',
        'settled'     => 'badge-ok',
    ];

    /**
     * 프로젝트 상태 전이 규칙(단순 유지 — worklog 기록).
     *   preparing   → in_progress(착공) / paused / cancelled
     *   in_progress → paused / completed / terminated
     *   paused      → in_progress(재개) / cancelled / terminated
     *   completed   → warranty / settled / in_progress(재개 — 사유 필수)
     *   warranty    → completed
     *   settled     → (없음 — 최종)
     *   cancelled   → preparing(복구 — 사유 필수)
     *   terminated  → in_progress(복구 — 사유 필수)
     */
    public const PROJECT_TRANSITIONS = [
        'preparing'   => ['in_progress', 'paused', 'cancelled'],
        'in_progress' => ['paused', 'completed', 'terminated'],
        'paused'      => ['in_progress', 'cancelled', 'terminated'],
        // R12: 프로젝트 상태 '정산 완료(settled)' 전환 제거 — 정산 완료는 '입금·정산' 탭의 정산 상태로 일원화(버튼 중복 해소).
        //      공정/진행 축은 완료·하자보수까지만. 레거시 settled 프로젝트는 재개(in_progress)만 허용.
        'completed'   => ['warranty', 'in_progress'],
        'warranty'    => ['completed'],
        'settled'     => ['in_progress'], // (레거시 데이터) 정산 완료 상태 프로젝트의 재개 — 신규 진입 경로 없음
        'cancelled'   => ['preparing'],
        'terminated'  => ['in_progress'],
    ];

    /** 사유가 반드시 필요한 전환(취소·파기·재개/복구). 'from>to' 키. */
    private const REASON_REQUIRED = [
        'preparing>cancelled', 'paused>cancelled',
        'in_progress>terminated', 'paused>terminated',
        'completed>in_progress', 'cancelled>preparing', 'terminated>in_progress',
        'settled>in_progress',
    ];

    // ── R11: 정산 상태(projects.settlement_status) — 공정 상태와 분리된 축 ──
    //    unsettled/partial 은 입금 이벤트 시 자동 재계산, settled 는 수동 전용(가드),
    //    hold(정산 보류)/refunding(환불 진행)은 수동 토글 — 자동 재계산이 덮어쓰지 않는다.
    public const SETTLEMENT_LABELS = [
        'unsettled' => '미정산',
        'partial'   => '일부 정산',
        'settled'   => '전액 입금 완료',
        'refunding' => '환불 진행',
        'hold'      => '정산 보류',
    ];
    public const SETTLEMENT_BADGE = [
        'unsettled' => 'badge-muted',
        'partial'   => 'badge-warn',
        'settled'   => 'badge-ok',
        'refunding' => 'badge-danger',
        'hold'      => 'badge-warn',
    ];

    /** 집계 제외 상태(예상 매출·수주·진행 수·업무량·성과 제외 — 브리프 §2). */
    public const EXCLUDED_STATUSES = ['cancelled', 'terminated'];

    // ── T8: 3단 단순 상태(대기/공정/완료) — 공정보드·대시보드·분석 공용(조회시 매핑, DB 원본 8종 유지) ──
    /** 레거시 8종 → 3단 그룹. cancelled/terminated 는 집계 제외(EXCLUDED_STATUSES — 매핑 없음). */
    public const SIMPLE_GROUPS = [
        'preparing'   => 'waiting',
        'in_progress' => 'working',
        'paused'      => 'working',
        'warranty'    => 'working',
        'completed'   => 'done',
        'settled'     => 'done',
    ];
    public const SIMPLE_LABELS = ['waiting' => '대기', 'working' => '공정', 'done' => '완료'];

    /** 상태의 3단 그룹 키(제외·미지 상태는 null). */
    public static function simpleOf(string $status): ?string
    {
        return self::SIMPLE_GROUPS[$status] ?? null;
    }

    /** 3단 그룹에 속한 DB 상태 목록 — 집계 IN 절 공용(하드코딩 금지). */
    public static function simpleStatuses(string $group): array
    {
        return array_keys(self::SIMPLE_GROUPS, $group, true);
    }

    public static function projectTransitionAllowed(string $from, string $to): bool
    {
        return in_array($to, self::PROJECT_TRANSITIONS[$from] ?? [], true);
    }

    /**
     * R13: 공정 보드 이동 시 프로젝트 상태 목표 결정. null=상태 전환 없음.
     * 규칙: 전체완료→completed / 하자보수→warranty / (대기중·전체완료 외 실공정)+preparing|paused→in_progress.
     * 완료·정산에서 실공정으로 되돌리는 '재개'는 호출부(ProcessController)에서 별도 처리.
     */
    public static function boardStatusFor(string $toStageKey, string $currentStatus): ?string
    {
        if ($toStageKey === 'full_complete') {
            return in_array($currentStatus, ['completed', 'settled'], true) ? null : 'completed';
        }
        if ($toStageKey === 'warranty_repair') {
            return $currentStatus === 'warranty' ? null : 'warranty';
        }
        if ($toStageKey !== ProcessService::WAITING_KEY
            && in_array($currentStatus, ['preparing', 'paused'], true)) {
            return 'in_progress';
        }
        return null;
    }

    public static function reasonRequired(string $from, string $to): bool
    {
        return in_array($from . '>' . $to, self::REASON_REQUIRED, true);
    }

    /** 계약 상태 이력 기록 + 감사로그. */
    public static function logContractStatus(int $contractId, ?string $from, string $to, ?string $reason = null): void
    {
        Db::insert('contract_status_history', [
            'contract_id' => $contractId,
            'from_status' => $from,
            'to_status'   => $to,
            'changed_by'  => Auth::check() ? Auth::id() : null,
            'reason'      => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 500) : null,
        ]);
        Audit::log('contract_status_change', 'contracts', $contractId, ['status' => $from], ['status' => $to, 'reason' => $reason]);
    }

    /** 프로젝트 상태 이력 기록 + 감사로그. $detail 은 파기·취소 부가정보 배열(JSON 저장). */
    public static function logProjectStatus(int $projectId, ?string $from, string $to, ?string $reason = null, ?array $detail = null): void
    {
        Db::insert('project_status_history', [
            'project_id'  => $projectId,
            'from_status' => $from,
            'to_status'   => $to,
            'changed_by'  => Auth::check() ? Auth::id() : null,
            'reason'      => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 500) : null,
            'detail_json' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
        Audit::log('project_status_change', 'project', $projectId, ['status' => $from], ['status' => $to, 'reason' => $reason] + ($detail ?? []));
    }

    /**
     * 계약 결제상태 재계산 — 순입금(payment−refund, status='paid') 기준.
     * 전액입금=paid, 일부=partial, 0 이하=unpaid.
     */
    public static function recalcContractPaymentStatus(int $contractId): void
    {
        $contract = Db::one("SELECT contract_amount FROM contracts WHERE id=:id", [':id' => $contractId]);
        if (!$contract) {
            return;
        }
        $netPaid = (float) AccountingService::contractNetPaid($contractId);
        $amount = (float) $contract['contract_amount'];
        if ($netPaid <= 0) {
            $status = 'unpaid';
        } elseif ($netPaid >= $amount && $amount > 0) {
            $status = 'paid';
        } else {
            $status = 'partial';
        }
        Db::update('contracts', ['payment_status' => $status], 'id = :id', [':id' => $contractId]);

        // R11: 연결 프로젝트 정산 상태도 동기 재계산(입금 이벤트 단일 훅)
        $projectId = Db::val("SELECT id FROM projects WHERE contract_id = :c AND deleted_at IS NULL LIMIT 1", [':c' => $contractId]);
        if ($projectId !== null) {
            self::recalcProjectSettlement((int) $projectId);
        }
    }

    /**
     * R11: 프로젝트 정산 상태 자동 재계산 — 입금 등록/수정/취소/환불 이벤트 훅.
     * 규칙(D5): hold(보류)·refunding(환불 진행)은 수동 상태 — 자동 재계산이 건드리지 않는다.
     *   settled(정산 완료)는 수동 전용이나, 미수금이 재발생하면 partial 로 자동 강등(감사 기록).
     *   그 외에는 순입금>0 → partial, 아니면 unsettled.
     * 반환: 적용된 정산 상태.
     */
    public static function recalcProjectSettlement(int $projectId): string
    {
        $project = Db::one("SELECT id, contract_id, is_exception, expected_amount, settlement_status
            FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$project) {
            return 'unsettled';
        }
        BonusService::recalcForProject($projectId); // R13: 모든 입금/환불 이벤트에서 보너스·손실 재계산(정산 상태 hold/refunding 무관)
        $current = (string) $project['settlement_status'];
        if (in_array($current, ['hold', 'refunding'], true)) {
            return $current; // 수동 상태 유지(해제는 정산 상태 컨트롤에서만)
        }
        $sum = AccountingService::projectPaySummary($project);
        // R13: 전액 입금(계약총액 설정·미수금 0·대기 0·입금>0) → '전액 입금 완료'(settled) 자동 승격.
        if ($sum['expected_set'] && $sum['outstanding'] <= 0 && $sum['pendingCnt'] === 0 && $sum['paid'] > 0) {
            if ($current !== 'settled') {
                Db::update('projects', ['settlement_status' => 'settled'], 'id = :id', [':id' => $projectId]);
                Audit::log('project_settlement_change', 'project', $projectId,
                    ['settlement_status' => $current],
                    ['settlement_status' => 'settled', 'reason' => '전액 입금 자동 완료']);
            }
            return 'settled';
        }
        // 전액이 아닌데 settled 였으면 → 미수금 재발생/대기 발생으로 partial 자동 강등(감사 기록).
        if ($current === 'settled') {
            Db::update('projects', ['settlement_status' => 'partial'], 'id = :id', [':id' => $projectId]);
            Audit::log('project_settlement_change', 'project', $projectId,
                ['settlement_status' => 'settled'],
                ['settlement_status' => 'partial', 'reason' => '미수금 재발생 자동 강등', 'outstanding' => $sum['outstanding']]);
            return 'partial';
        }
        $next = $sum['paid'] > 0 ? 'partial' : 'unsettled';
        if ($next !== $current) {
            Db::update('projects', ['settlement_status' => $next], 'id = :id', [':id' => $projectId]);
        }
        return $next;
    }

    /**
     * 프로젝트 상태 변경 적용(트랜잭션 내부 호출 전제): projects.status 갱신 + 부수 날짜 처리 + 이력.
     * $opts: effective_date(처리일), reason, detail(부가정보 배열).
     * 반환: 실제 갱신된 컬럼 배열.
     */
    public static function applyProjectStatus(array $project, string $to, array $opts = []): array
    {
        $from = (string) $project['status'];
        $date = $opts['effective_date'] ?? date('Y-m-d');
        $data = ['status' => $to];
        if ($to === 'in_progress' && empty($project['actual_start_date'])) {
            $data['actual_start_date'] = $date;
        }
        if ($to === 'completed') {
            if (empty($project['actual_end_date'])) {
                $data['actual_end_date'] = $date;
            }
            $data['progress'] = 100;
            // R13: 완료 처리 시 공정 보드 카드를 '전체완료'로 자동 이동(상태→보드 동기화). 같은 단계면 no-op.
            $fcId = ProcessService::stageIdByKey('full_complete');
            if ($fcId !== null) {
                ProcessService::moveStage((int) $project['id'], $fcId, Auth::check() ? Auth::id() : ($opts['actor_id'] ?? null), '완료 처리 자동 종결', true);
            }
            // 준공 훅(R3 acctverify): 연결 계약의 잔금 pending 예정행에 수금 예정일이 없으면 준공일로 자동 세팅
            // — due_date NULL 이면 입금 예정·미수금 독촉 알림이 영원히 침묵하는 문제 방지. paid·환불 행 불변.
            if (!empty($project['contract_id'])) {
                $dueBase = $data['actual_end_date'] ?? $project['actual_end_date'];
                Db::run(
                    "UPDATE payments SET due_date = :d
                     WHERE contract_id = :cid AND pay_type = 'balance' AND status = 'pending'
                       AND kind = 'payment' AND due_date IS NULL",
                    [':d' => $dueBase, ':cid' => (int) $project['contract_id']]
                );
            }
        }
        // 완료·정산 → 재개: 준공일 기준 집계(완료 건수 등)에서 빠지도록 준공일 해제 (R11: settled 재개 포함)
        if (in_array($from, ['completed', 'settled'], true) && $to === 'in_progress') {
            $data['actual_end_date'] = null;
        }
        Db::update('projects', $data, 'id = :id', [':id' => (int) $project['id']]);
        self::logProjectStatus((int) $project['id'], $from, $to, $opts['reason'] ?? null, $opts['detail'] ?? null);
        return $data;
    }
}
