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

    private static function applyStage(int $projectId, ?int $fromStageId, int $toStageId, ?int $userId, ?string $reason, bool $auto): void
    {
        Db::run("UPDATE projects SET process_stage_id = :s, process_entered_at = NOW() WHERE id = :id",
            [':s' => $toStageId, ':id' => $projectId]);
        Db::run("INSERT INTO project_process_history (project_id, from_stage_id, to_stage_id, changed_by, reason, is_auto)
                 VALUES (:p, :f, :t, :u, :r, :a)",
            [':p' => $projectId, ':f' => $fromStageId, ':t' => $toStageId, ':u' => $userId, ':r' => $reason, ':a' => $auto ? 1 : 0]);
    }
}
