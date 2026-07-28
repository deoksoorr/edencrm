<?php
/**
 * 공정 상태 단일 출처 — 프로젝트 상태(StatusService)와 공정 상태(process_stages)를 분리 관리한다.
 * 규약(R3 커널): 공정 보드·API·프로젝트 상세는 모두 projects.process_stage_id 를 원천으로 하며
 * 별도 카드 테이블을 만들지 않는다. 공정 이동은 반드시 이 서비스를 경유한다(직접 UPDATE 금지).
 * '대기중'(stage_key=waiting, sort_order 0)이 항상 첫 단계이고, 신규 진행 프로젝트가 여기 배치된다.
 * 보드 내 정렬은 process_entered_at DESC → created_at DESC → id DESC (updated_at 정렬 금지).
 */
class ProcessService
{
    public const WAITING_KEY = 'waiting';

    /** '대기중' 단계 ID. 시드 누락 시 예외. */
    public static function waitingStageId(): int
    {
        $id = Db::val("SELECT id FROM process_stages WHERE stage_key = :k", [':k' => self::WAITING_KEY]);
        if (!$id) {
            throw new RuntimeException("process_stages 에 'waiting' 단계가 없습니다 (r3_kernel 마이그레이션 필요)");
        }
        return (int) $id;
    }

    /** stage_key 로 공정 단계 ID 조회(없으면 null). */
    public static function stageIdByKey(string $key): ?int
    {
        $id = Db::val("SELECT id FROM process_stages WHERE stage_key = :k", [':k' => $key]);
        return $id !== null ? (int) $id : null;
    }

    /**
     * 공정 미배치 프로젝트를 '대기중'으로 초기 배치한다(멱등 — 이미 공정이 있으면 no-op).
     * 계약 자동 생성·데이터 보정 등 자동 흐름은 $auto=true 로 호출한다.
     * @return bool 배치 수행 여부
     */
    public static function initWaiting(int $projectId, ?int $userId = null, bool $auto = true, string $reason = '공정 초기 배치'): bool
    {
        $cur = Db::one("SELECT process_stage_id FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$cur || $cur['process_stage_id'] !== null) {
            return false;
        }
        self::applyStage($projectId, null, self::waitingStageId(), $userId, $reason, $auto);
        return true;
    }

    /**
     * 공정 이동. 동일 단계면 no-op. 대기중 재진입 포함 항상 process_entered_at 을 갱신해
     * 재진입 카드가 해당 컬럼 최상단에 오도록 한다.
     * @return bool 이동 수행 여부
     */
    public static function moveStage(int $projectId, int $toStageId, ?int $userId, ?string $reason = null, bool $auto = false): bool
    {
        $cur = Db::one("SELECT process_stage_id FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$cur || (int) $cur['process_stage_id'] === $toStageId) {
            return false;
        }
        if (!Db::val("SELECT id FROM process_stages WHERE id = :id", [':id' => $toStageId])) {
            return false;
        }
        $from = $cur['process_stage_id'] !== null ? (int) $cur['process_stage_id'] : null;
        self::applyStage($projectId, $from, $toStageId, $userId, $reason, $auto);
        return true;
    }

    /**
     * R8-A: 프로젝트의 현재 공정 단계가 공사 유형(construction_type, NULL→painting 기준)과 맞는지 검사하고,
     * 반대 유형 전용 단계(또는 비활성 단계)에 있으면 '대기중'으로 재배치한다(이력 is_auto=1 기록).
     * 공사 유형 지정(process.settype)·프로젝트 수정 화면의 유형 변경 시 공통 사용.
     * @return bool 재배치 수행 여부(정합 상태·공정 미배치면 false)
     */
    public static function ensureStageMatchesType(int $projectId, ?int $userId = null): bool
    {
        $row = Db::one(
            "SELECT process_stage_id, construction_type FROM projects WHERE id = :id AND deleted_at IS NULL",
            [':id' => $projectId]
        );
        if (!$row || $row['process_stage_id'] === null) {
            return false;
        }
        $type = Stages::normalizeConstructionType($row['construction_type'] ?? null);
        $ok = Db::val(
            "SELECT 1 FROM process_stages
             WHERE id = :sid AND (process_type = :t OR process_type = 'common') AND is_active = 1",
            [':sid' => (int) $row['process_stage_id'], ':t' => $type]
        );
        if ($ok !== null) {
            return false; // 현재 단계가 유형 집합(유형+공통, 활성)에 속함 — 재배치 불필요
        }
        return self::moveStage($projectId, self::waitingStageId(), $userId, '공사 유형 지정에 따른 보드 재배치', true);
    }

    /**
     * R14-4/6: 게이지 값으로 현재 공정·진행률 재파생(그룹 드래그 재개 등) — pct>0 최후방, 없으면 대기중.
     * 완료 정책이 progress=100 을 강제하므로, 재개 시 반드시 게이지 평균으로 복원한다(100%/0% 불일치 방지).
     */
    public static function syncStageFromGauges(int $projectId, ?int $userId): void
    {
        $project = Db::one("SELECT id, construction_type FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$project) {
            return;
        }
        $type = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        $pctMap = [];
        foreach (Db::all("SELECT stage_id, pct FROM project_stage_progress WHERE project_id = :p", [':p' => $projectId]) as $r) {
            $pctMap[(int) $r['stage_id']] = (int) $r['pct'];
        }
        $target = null;
        foreach (self::gaugeStages($type) as $st) {
            if (($pctMap[(int) $st['id']] ?? 0) > 0) {
                $target = (int) $st['id'];
            }
        }
        self::moveStage($projectId, $target ?? self::waitingStageId(), $userId, '게이지 파생 공정 재동기', true);
        self::recalcProgressFromGauges($projectId);
    }

    /** R14-6: projects.progress = 게이지 평균 재계산·저장(완료의 100 강제 해제 복원 등). @return int 평균 */
    public static function recalcProgressFromGauges(int $projectId): int
    {
        $project = Db::one("SELECT id, construction_type FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$project) {
            return 0;
        }
        $type = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        $ids = array_map(static fn($s) => (int) $s['id'], self::gaugeStages($type));
        $pctMap = [];
        foreach (Db::all("SELECT stage_id, pct FROM project_stage_progress WHERE project_id = :p", [':p' => $projectId]) as $r) {
            $pctMap[(int) $r['stage_id']] = (int) $r['pct'];
        }
        $sum = 0;
        foreach ($ids as $sid) {
            $sum += $pctMap[$sid] ?? 0;
        }
        $progress = count($ids) ? (int) round($sum / count($ids)) : 0;
        Db::update('projects', ['progress' => $progress], 'id = :id', [':id' => $projectId]);
        return $progress;
    }

    /** R14: 게이지 대상 실공정(유형·활성, 공통 예약 자동 제외 — common 은 process_type 불일치) 위치순. */
    public static function gaugeStages(string $constructionType): array
    {
        return Db::all(
            "SELECT id, stage_key, name, sort_order, color FROM process_stages
             WHERE process_type = :t AND is_active = 1
             ORDER BY sort_order, id",
            [':t' => $constructionType]
        );
    }

    /**
     * R14: 공정 게이지 저장 + 파생 — 보드 게이지의 단일 진입점.
     * 파생: progress=pct 평균, 현재 공정=pct>0 최후방(없으면 대기중), 상태 자동 전환
     *  (preparing/paused+시작→in_progress, completed/settled+미완→재개). 전부 100 은 all_done
     *  플래그만 반환(완료 확정은 클라 확인 후 별도 호출 — 서버 재검증).
     * warranty 상태는 보드 위치(warranty_repair) 유지 — 게이지만 기록.
     */
    public static function setStageProgress(int $projectId, int $stageId, int $pct, ?int $userId): array
    {
        $pct = max(0, min(100, $pct));
        $project = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$project) {
            throw new RuntimeException('프로젝트를 찾을 수 없습니다.');
        }
        if (in_array($project['status'], ['cancelled', 'terminated'], true)) {
            throw new RuntimeException('취소·파기 프로젝트는 공정 게이지를 수정할 수 없습니다.');
        }
        $type = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        $stages = self::gaugeStages($type);
        $ids = array_map(static fn($s) => (int) $s['id'], $stages);
        if (!in_array($stageId, $ids, true)) {
            throw new RuntimeException('이 프로젝트 유형의 공정이 아닙니다.');
        }

        $run = function () use ($project, $projectId, $stageId, $pct, $userId, $ids) {
            Db::run("INSERT INTO project_stage_progress (project_id, stage_id, pct, updated_by)
                     VALUES (:p, :s, :v, :u)
                     ON DUPLICATE KEY UPDATE pct = VALUES(pct), updated_by = COALESCE(VALUES(updated_by), updated_by)",
                [':p' => $projectId, ':s' => $stageId, ':v' => $pct, ':u' => $userId]);

            // ── 파생 ──
            $pctMap = [];
            foreach (Db::all("SELECT stage_id, pct FROM project_stage_progress WHERE project_id = :p", [':p' => $projectId]) as $r) {
                $pctMap[(int) $r['stage_id']] = (int) $r['pct'];
            }
            $sum = 0; $currentStageId = null; $allDone = count($ids) > 0;
            foreach ($ids as $sid) {
                $v = $pctMap[$sid] ?? 0;
                $sum += $v;
                if ($v > 0) { $currentStageId = $sid; }   // 위치순 순회 — pct>0 최후방
                if ($v < 100) { $allDone = false; }
            }
            $progress = count($ids) ? (int) round($sum / count($ids)) : 0;
            Db::update('projects', ['progress' => $progress], 'id = :id', [':id' => $projectId]);

            $status = (string) $project['status'];
            if (!$allDone && in_array($status, ['completed', 'settled'], true)) {
                StatusService::applyProjectStatus($project, 'in_progress', ['reason' => '공정 게이지 수정 재개(종결 해제)']);
                $status = 'in_progress';
            } elseif ($currentStageId !== null && in_array($status, ['preparing', 'paused'], true)) {
                StatusService::applyProjectStatus($project, 'in_progress', ['reason' => '공정 게이지 시작 자동 전환']);
                $status = 'in_progress';
            }
            // 보드 위치 동기 — 종결(전체완료 유지)·하자보수(warranty_repair 유지) 제외
            $targetStage = $currentStageId ?? self::waitingStageId();
            if (!in_array($status, ['completed', 'settled', 'warranty'], true)) {
                self::moveStage($projectId, $targetStage, $userId, '공정 게이지 파생 이동', true);
            }
            $cur = (int) Db::val("SELECT process_stage_id FROM projects WHERE id = :id", [':id' => $projectId]);
            return ['pct' => $pct, 'progress' => $progress, 'status' => $status,
                'current_stage_id' => $cur, 'all_done' => $allDone];
        };
        // R14-fix: 원자성 — 이미 열린 트랜잭션(테스트·상위 호출) 안이면 그대로, 아니면 트랜잭션 래핑
        return Db::pdo()->inTransaction() ? $run() : Db::transaction(static fn() => $run());
    }

    private static function applyStage(int $projectId, ?int $fromStageId, int $toStageId, ?int $userId, ?string $reason, bool $auto): void
    {
        Db::run("UPDATE projects SET process_stage_id = :s, process_entered_at = NOW() WHERE id = :id",
            [':s' => $toStageId, ':id' => $projectId]);
        Db::run("INSERT INTO project_process_history (project_id, from_stage_id, to_stage_id, changed_by, reason, is_auto)
                 VALUES (:p, :f, :t, :u, :r, :a)",
            [':p' => $projectId, ':f' => $fromStageId, ':t' => $toStageId, ':u' => $userId, ':r' => $reason, ':a' => $auto ? 1 : 0]);
    }
}
