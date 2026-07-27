<?php
/**
 * 공정 보드 데이터 보정 (R3 procboard — 브리프 §3)
 *
 * 대상: status IN ('preparing','in_progress','paused','warranty') AND (
 *   ① process_stage_id IS NULL(공정 미배치)
 *   ② 삭제·존재하지 않는 공정 참조
 *   ③ 공정은 있으나 project_process_history 첫 기록 없음
 * )
 * 보정:
 *   ①② ProcessService::initWaiting/moveStage 로 '대기중' 배치(is_auto=1, reason '데이터 보정')
 *       → process_entered_at 을 상태변경일(최신 project_status_history) 또는 생성일로 재조정
 *   ③   현재 공정 기준 첫 이력 INSERT(is_auto=1, changed_at = process_entered_at 또는 생성일)
 * 취소·파기·완료·정산 프로젝트는 건드리지 않는다.
 *
 * 실행: php scripts/backfill_process_board.php            (dry-run, 대상 목록만 출력)
 *       php scripts/backfill_process_board.php --apply    (실제 반영)
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
$statusIn = "'preparing','in_progress','paused','warranty'";

echo "── 공정 보드 데이터 보정 " . ($apply ? '[APPLY 실행]' : '[dry-run — 반영하려면 --apply]') . " ──\n\n";

// ── 대상 수집 ──
$targets = Db::all(
    "SELECT p.id, p.project_no, p.name, p.status, p.process_stage_id, p.process_entered_at, p.created_at,
            (SELECT ps.name FROM process_stages ps WHERE ps.id = p.process_stage_id) AS stage_name,
            (SELECT MAX(h.changed_at) FROM project_status_history h WHERE h.project_id = p.id) AS last_status_at,
            (SELECT COUNT(*) FROM project_process_history ph WHERE ph.project_id = p.id) AS hist_count
     FROM projects p
     WHERE p.deleted_at IS NULL AND p.status IN ($statusIn)
       AND (p.process_stage_id IS NULL
            OR NOT EXISTS (SELECT 1 FROM process_stages ps WHERE ps.id = p.process_stage_id)
            OR NOT EXISTS (SELECT 1 FROM project_process_history ph WHERE ph.project_id = p.id))
     ORDER BY p.id"
);

if (!$targets) {
    echo "보정 대상이 없습니다. (진행 상태 프로젝트 모두 공정·이력 정상)\n";
    exit(0);
}

echo "보정 대상 " . count($targets) . "건:\n";
printf("%-4s %-12s %-28s %-12s %-10s %-19s %s\n", 'ID', '프로젝트NO', '이름', '상태', '공정', '진입일', '사유');
foreach ($targets as $t) {
    $valid = $t['process_stage_id'] !== null && $t['stage_name'] !== null;
    if ($t['process_stage_id'] === null) {
        $why = '공정 미배치(NULL) → 대기중 배치';
    } elseif (!$valid) {
        $why = '유효하지 않은 공정 참조(#' . $t['process_stage_id'] . ') → 대기중 배치';
    } else {
        $why = '공정 이력 첫 기록 없음 → 이력 생성';
    }
    printf("%-4d %-12s %-28s %-12s %-10s %-19s %s\n",
        $t['id'], $t['project_no'], mb_strimwidth($t['name'], 0, 28, '…'),
        $t['status'], $t['stage_name'] ?? 'NULL', $t['process_entered_at'] ?? '-', $why);
}

// ── 백업(롤백용) 출력 ──
echo "\n[백업] 롤백 시 사용할 원상복구 SQL:\n";
foreach ($targets as $t) {
    printf("UPDATE projects SET process_stage_id = %s, process_entered_at = %s WHERE id = %d;\n",
        $t['process_stage_id'] === null ? 'NULL' : (int) $t['process_stage_id'],
        $t['process_entered_at'] === null ? 'NULL' : "'" . $t['process_entered_at'] . "'",
        $t['id']);
}
echo "-- 보정으로 생성된 이력 제거: DELETE FROM project_process_history WHERE reason LIKE '데이터 보정%' AND is_auto = 1;\n";

if (!$apply) {
    echo "\ndry-run 종료 — 반영하려면 --apply 를 붙여 다시 실행하세요.\n";
    exit(0);
}

// ── 실제 반영 ──
echo "\n반영 시작…\n";
$fixed = 0;
foreach ($targets as $t) {
    $id = (int) $t['id'];
    $valid = $t['process_stage_id'] !== null && $t['stage_name'] !== null;
    // entered_at 기준: 최신 상태변경일 → 없으면 생성일 (브리프 §3)
    $enteredAt = $t['last_status_at'] ?: $t['created_at'];

    if ($t['process_stage_id'] === null) {
        ProcessService::initWaiting($id, null, true, '데이터 보정');
        Db::run("UPDATE projects SET process_entered_at = :d WHERE id = :id", [':d' => $enteredAt, ':id' => $id]);
        Db::run("UPDATE project_process_history SET changed_at = :d
                 WHERE project_id = :id AND reason = '데이터 보정' AND is_auto = 1
                 ORDER BY id DESC LIMIT 1", [':d' => $enteredAt, ':id' => $id]);
        echo "  #$id {$t['name']} → 대기중 배치 (진입일 $enteredAt)\n";
        $fixed++;
    } elseif (!$valid) {
        ProcessService::moveStage($id, ProcessService::waitingStageId(), null, '데이터 보정(유효하지 않은 공정 참조)', true);
        Db::run("UPDATE projects SET process_entered_at = :d WHERE id = :id", [':d' => $enteredAt, ':id' => $id]);
        Db::run("UPDATE project_process_history SET changed_at = :d
                 WHERE project_id = :id AND reason = '데이터 보정(유효하지 않은 공정 참조)' AND is_auto = 1
                 ORDER BY id DESC LIMIT 1", [':d' => $enteredAt, ':id' => $id]);
        echo "  #$id {$t['name']} → 잘못된 참조(#{$t['process_stage_id']}) 대기중 재배치 (진입일 $enteredAt)\n";
        $fixed++;
    } else {
        // 공정은 유효하나 첫 이력 없음 — 공정 변경 없이 이력만 생성(직접 UPDATE 아님)
        $histAt = $t['process_entered_at'] ?: $enteredAt;
        Db::run("INSERT INTO project_process_history (project_id, from_stage_id, to_stage_id, changed_by, reason, is_auto, changed_at)
                 VALUES (:p, NULL, :t, NULL, '데이터 보정(이력 생성)', 1, :d)",
            [':p' => $id, ':t' => (int) $t['process_stage_id'], ':d' => $histAt]);
        if ($t['process_entered_at'] === null) {
            Db::run("UPDATE projects SET process_entered_at = :d WHERE id = :id", [':d' => $histAt, ':id' => $id]);
        }
        echo "  #$id {$t['name']} → 현재 공정({$t['stage_name']}) 첫 이력 생성 ($histAt)\n";
        $fixed++;
    }
}

// ── 사후 검증 ──
$remain = (int) Db::val(
    "SELECT COUNT(*) FROM projects p
     WHERE p.deleted_at IS NULL AND p.status IN ($statusIn)
       AND (p.process_stage_id IS NULL
            OR NOT EXISTS (SELECT 1 FROM process_stages ps WHERE ps.id = p.process_stage_id)
            OR NOT EXISTS (SELECT 1 FROM project_process_history ph WHERE ph.project_id = p.id))"
);
echo "\n완료: {$fixed}건 보정, 잔여 대상 {$remain}건" . ($remain === 0 ? ' — 정상' : ' — 확인 필요!') . "\n";

$rows = Db::all(
    "SELECT p.id, p.name, p.status, ps.name AS stage, p.process_entered_at
     FROM projects p LEFT JOIN process_stages ps ON ps.id = p.process_stage_id
     WHERE p.deleted_at IS NULL AND p.status IN ($statusIn) ORDER BY p.id"
);
echo "\n보정 후 보드 대상 상태:\n";
foreach ($rows as $r) {
    printf("  #%-3d %-28s %-12s %-10s %s\n", $r['id'], mb_strimwidth($r['name'], 0, 28, '…'), $r['status'], $r['stage'] ?? 'NULL', $r['process_entered_at'] ?? '-');
}
exit($remain === 0 ? 0 : 1);
