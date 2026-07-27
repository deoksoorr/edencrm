<?php
/**
 * 공정 19단계 확장 데이터 보정 (R4 T3 — r4-shared-brief §1 '공정 19단계')
 *
 * 대상: status IN ('completed','settled') 프로젝트 중 현재 공정이
 *       final_inspection(준공검사, 17) 또는 warranty_repair(하자보수, 18)로 남은 건
 * 보정: ProcessService::moveStage 로 full_complete(전체완료, 19) 이동
 *       (is_auto=1, reason '19단계 확장 보정') + progress=100
 *       + process_entered_at 을 완료 전환 시각(project_status_history)·실제 준공일로 재조정.
 * 하자보수(warranty_repair)로 되돌리는 케이스 없음 — 전방(full_complete) 이동만 수행.
 * 취소·파기·진행 중 프로젝트는 건드리지 않는다.
 *
 * 실행: php scripts/backfill_process19.php            (dry-run, 대상 목록만 출력)
 *       php scripts/backfill_process19.php --apply    (실제 반영)
 * 출력의 [백업] SQL 을 저장해 두면 수동 롤백이 가능하다.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI 전용 스크립트입니다.\n");
    exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
$GLOBALS['config'] = require __DIR__ . '/../app/config/config.php';
foreach (['Util', 'Db', 'ProcessService'] as $c) {
    require_once APP_PATH . '/core/' . $c . '.php';
}

$apply = in_array('--apply', $argv, true);

echo "── 공정 19단계 확장 보정 " . ($apply ? '[APPLY 실행]' : '[dry-run — 반영하려면 --apply]') . " ──\n\n";

$fullId = (int) Db::val("SELECT id FROM process_stages WHERE stage_key = 'full_complete'");
if (!$fullId) {
    fwrite(STDERR, "process_stages 에 'full_complete' 단계가 없습니다 — 2026-07-23_r4_process19.sql 먼저 적용하세요.\n");
    exit(1);
}

// ── 대상 수집: 완료·정산인데 최종 공정(준공검사·하자보수)에 남은 건 ──
$targets = Db::all(
    "SELECT p.id, p.project_no, p.name, p.status, p.process_stage_id, p.process_entered_at,
            p.progress, p.actual_end_date, p.created_at,
            ps.stage_key, ps.name AS stage_name,
            (SELECT MAX(h.changed_at) FROM project_status_history h
              WHERE h.project_id = p.id AND h.to_status IN ('completed','settled')) AS done_at
     FROM projects p
     JOIN process_stages ps ON ps.id = p.process_stage_id
     WHERE p.deleted_at IS NULL
       AND p.status IN ('completed','settled')
       AND ps.stage_key IN ('final_inspection','warranty_repair')
     ORDER BY p.id"
);

if (!$targets) {
    echo "보정 대상이 없습니다. (완료·정산 프로젝트 중 준공검사·하자보수 공정에 남은 건 없음)\n";
    exit(0);
}

echo "보정 대상 " . count($targets) . "건 → 전체완료(full_complete, #$fullId) 이동:\n";
printf("%-4s %-12s %-28s %-10s %-14s %-19s %s\n", 'ID', '프로젝트NO', '이름', '상태', '현재 공정', '공정 진입일', '진행률');
foreach ($targets as $t) {
    printf("%-4d %-12s %-28s %-10s %-14s %-19s %d%%\n",
        $t['id'], $t['project_no'], mb_strimwidth($t['name'], 0, 28, '…'),
        $t['status'], $t['stage_name'], $t['process_entered_at'] ?? '-', (int) $t['progress']);
}

// ── 백업(롤백용) 출력 ──
echo "\n[백업] 롤백 시 사용할 원상복구 SQL:\n";
foreach ($targets as $t) {
    printf("UPDATE projects SET process_stage_id = %d, process_entered_at = %s, progress = %d WHERE id = %d;\n",
        (int) $t['process_stage_id'],
        $t['process_entered_at'] === null ? 'NULL' : "'" . $t['process_entered_at'] . "'",
        (int) $t['progress'],
        (int) $t['id']);
}
echo "DELETE FROM project_process_history WHERE reason = '19단계 확장 보정' AND is_auto = 1;\n";

if (!$apply) {
    echo "\ndry-run 종료 — 반영하려면 --apply 를 붙여 다시 실행하세요.\n";
    exit(0);
}

// ── 실제 반영 — 모든 이동은 ProcessService 경유(직접 UPDATE 금지) ──
echo "\n반영 시작…\n";
$fixed = 0;
foreach ($targets as $t) {
    $id = (int) $t['id'];
    $moved = ProcessService::moveStage($id, $fullId, null, '19단계 확장 보정', true);
    if (!$moved) {
        echo "  #$id {$t['name']} → 이동 실패(이미 전체완료이거나 삭제됨) — 확인 필요\n";
        continue;
    }
    // 진입일: 완료 전환 시각 > 실제 준공일 > 기존 공정 진입일 순으로 의미 있는 시점 유지
    $enteredAt = $t['done_at']
        ?: ($t['actual_end_date'] ? $t['actual_end_date'] . ' 00:00:00' : null)
        ?: $t['process_entered_at'];
    if ($enteredAt !== null) {
        Db::run("UPDATE projects SET process_entered_at = :d WHERE id = :id", [':d' => $enteredAt, ':id' => $id]);
    }
    // 전체완료 = 최종 단계 → 진행률 100%(공정 이동 시 자동 산정 로직과 동일 기준)
    Db::run("UPDATE projects SET progress = 100 WHERE id = :id", [':id' => $id]);
    echo "  #$id {$t['name']}: {$t['stage_name']} → 전체완료 (진입일 " . ($enteredAt ?? '유지') . ", 진행률 100%)\n";
    $fixed++;
}

// ── 사후 검증 ──
$remain = (int) Db::val(
    "SELECT COUNT(*) FROM projects p
     JOIN process_stages ps ON ps.id = p.process_stage_id
     WHERE p.deleted_at IS NULL AND p.status IN ('completed','settled')
       AND ps.stage_key IN ('final_inspection','warranty_repair')"
);
echo "\n완료: {$fixed}건 보정, 잔여 대상 {$remain}건" . ($remain === 0 ? ' — 정상' : ' — 확인 필요!') . "\n";

$rows = Db::all(
    "SELECT p.id, p.name, p.status, ps.name AS stage, p.process_entered_at, p.progress
     FROM projects p LEFT JOIN process_stages ps ON ps.id = p.process_stage_id
     WHERE p.deleted_at IS NULL AND p.status IN ('completed','settled') ORDER BY p.id"
);
echo "\n보정 후 완료·정산 프로젝트 공정 상태:\n";
foreach ($rows as $r) {
    printf("  #%-3d %-28s %-10s %-10s %-19s %d%%\n", $r['id'], mb_strimwidth($r['name'], 0, 28, '…'),
        $r['status'], $r['stage'] ?? 'NULL', $r['process_entered_at'] ?? '-', (int) $r['progress']);
}
exit($remain === 0 ? 0 : 1);
