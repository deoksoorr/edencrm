<?php
/**
 * R14 백필 — 로컬/운영 겸용(PDO 직결). 기본 dry-run 아님에 주의: --dry 로 미리보기.
 *  (1) 게이지 백필: 현 process_stage_id 위치 P 기준 — 위치<P pct=100, 위치=P pct=50,
 *      completed/settled/전체완료 카드는 전부 100, 대기중·미배치는 0(행 미생성).
 *  (2) 예외 계약총액 백필: is_exception=1 & contract_amount=0 & expected_amount>0
 *      → contract_amount=expected_amount + supply/vat 분해(VAT 10%).
 * 사용: php deploy/backfill_r14.php [--prod] [--dry]
 */
$prod = in_array('--prod', $argv, true);
$dry  = in_array('--dry', $argv, true);
if ($prod) {
    $env = [];
    foreach (file(__DIR__ . '/cafe24.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim($v);
    }
    $P = $env['TBL_PREFIX'] ?? 'edencrm_';
    $pdo = new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4",
        $env['DB_USER'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} else {
    $P = '';
    $pdo = new PDO('mysql:unix_socket=' . __DIR__ . '/../.devdb/mysql.sock;dbname=eden_crm;charset=utf8mb4',
        'eden_crm_user', 'EdenCrm!local2026', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
function q(PDO $pdo, string $sql, array $p = []): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }

// ── (1) 게이지 백필 ──
$stages = q($pdo, "SELECT id, process_type, sort_order, stage_key FROM {$P}process_stages
                   WHERE is_active = 1 AND process_type IN ('painting','interior') ORDER BY process_type, sort_order, id");
$byType = []; // type => [stage_id...] 위치순
foreach ($stages as $s) { $byType[$s['process_type']][] = (int) $s['id']; }
$projects = q($pdo, "SELECT p.id, p.status, p.construction_type, p.process_stage_id, ps.stage_key AS cur_key, ps.process_type AS cur_type
                     FROM {$P}projects p LEFT JOIN {$P}process_stages ps ON ps.id = p.process_stage_id
                     WHERE p.deleted_at IS NULL");
$ins = 0;
foreach ($projects as $pr) {
    $type = in_array($pr['construction_type'], ['painting', 'interior'], true) ? $pr['construction_type'] : 'painting';
    $ids = $byType[$type] ?? [];
    if (!$ids) continue;
    $rows = []; // stage_id => pct
    $doneAll = in_array($pr['status'], ['completed', 'settled'], true) || ($pr['cur_key'] ?? '') === 'full_complete';
    if ($doneAll) {
        foreach ($ids as $sid) { $rows[$sid] = 100; }
    } elseif ($pr['process_stage_id'] !== null && in_array((int) $pr['process_stage_id'], $ids, true)) {
        $pos = array_search((int) $pr['process_stage_id'], $ids, true); // 0-base
        foreach ($ids as $i => $sid) { $rows[$sid] = $i < $pos ? 100 : ($i === $pos ? 50 : 0); }
    } else {
        continue; // 대기중·하자보수·미배치 — 게이지 0(행 미생성)
    }
    foreach ($rows as $sid => $pct) {
        if ($pct === 0) continue;
        $ins++;
        if (!$dry) {
            $pdo->prepare("INSERT INTO {$P}project_stage_progress (project_id, stage_id, pct)
                           VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pct = VALUES(pct)")
                ->execute([(int) $pr['id'], $sid, $pct]);
        }
    }
}
echo "게이지 백필: " . count($projects) . "개 프로젝트 검사, pct행 {$ins}건" . ($dry ? " (dry)" : " 적용") . "\n";

// ── (2) 예외 계약총액 백필 ──
$targets = q($pdo, "SELECT id, expected_amount FROM {$P}projects
                    WHERE deleted_at IS NULL AND is_exception = 1 AND contract_id IS NULL
                      AND COALESCE(contract_amount,0) = 0 AND COALESCE(expected_amount,0) > 0");
foreach ($targets as $t) {
    $amt = (int) $t['expected_amount'];
    $supply = (int) round($amt / 1.1);   // VAT 10% — AccountingService::computeSplit 와 동일 산식
    $vat = $amt - $supply;
    echo "  예외 #{$t['id']}: contract_amount 0 → " . number_format($amt) . " (공급 " . number_format($supply) . ")\n";
    if (!$dry) {
        $pdo->prepare("UPDATE {$P}projects SET contract_amount = ?, supply_amount = ?, vat_amount = ? WHERE id = ?")
            ->execute([$amt, $supply, $vat, (int) $t['id']]);
    }
}
echo "계약총액 백필: " . count($targets) . "건" . ($dry ? " (dry)" : " 적용") . "\n";
