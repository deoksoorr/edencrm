<?php
/**
 * R11 운영 실측 QA 헬퍼 (prod_r10_verify 패턴) — cafe24.env 접속.
 *   setup   : 임시 super_admin QA 계정 생성(qa_r11 / 임의 비밀번호 출력 — 기록 금지, 종료 시 삭제)
 *   verify  : 읽기 검증 — 공정 마스터 vs 위치 매핑 표(도장/인테리어), 예외 프로젝트·정산 백필 상태
 *   cleanup : QA 생성물 완전 원복 — R11QA 프로젝트(입금·지출·배정·이력·일정) 하드삭제 + QA 계정·login_attempts 삭제
 *             (감사 로그는 잔존 — 통제 증적)
 * 사용: php deploy/prod_r11_qa.php setup|verify|cleanup
 */
if (PHP_SAPI !== 'cli') { exit(1); }
$envFile = __DIR__ . '/cafe24.env';
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
    [$k, $v] = explode('=', $l, 2);
    $env[trim($k)] = trim($v);
}
$p = $env['TBL_PREFIX'] ?: 'edencrm_';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_PORT'] ?: '3306', $env['DB_NAME']);
$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$mode = $argv[1] ?? '';

if ($mode === 'setup') {
    $exists = $pdo->query("SELECT id FROM {$p}users WHERE login_id='qa_r11'")->fetchColumn();
    if ($exists) { echo "이미 존재: qa_r11 (id=$exists)\n"; exit(0); }
    $pw = bin2hex(random_bytes(6)) . 'Qa!1';
    $roleId = (int) $pdo->query("SELECT id FROM {$p}roles WHERE role_key='super_admin'")->fetchColumn();
    $st = $pdo->prepare("INSERT INTO {$p}users (login_id, email, password_hash, name, role_id, role_key, status, must_change_password)
        VALUES ('qa_r11', 'qa_r11@example.invalid', :h, 'R11 QA(삭제 예정)', :r, 'super_admin', 'active', 0)");
    $st->execute([':h' => password_hash($pw, PASSWORD_DEFAULT), ':r' => $roleId]);
    echo "QA 계정 생성: qa_r11 / $pw\n";
    exit(0);
}

if ($mode === 'verify') {
    echo "== 공정 마스터 → 위치 매핑(활성, sort·id 순) ==\n";
    foreach (['painting', 'interior'] as $type) {
        $rows = $pdo->prepare("SELECT id, stage_key, name, stage_group, sort_order, is_active FROM {$p}process_stages
            WHERE (process_type = :t OR process_type = 'common') AND is_active = 1 ORDER BY sort_order, id");
        $rows->execute([':t' => $type]);
        $n = 0;
        echo "-- $type --\n";
        foreach ($rows as $r) {
            $pos = $r['stage_key'] === 'waiting' ? 0 : ++$n;
            printf("  %2d %-22s %-14s group=%-8s sort=%s\n", $pos, $r['stage_key'], $r['name'], $r['stage_group'], $r['sort_order']);
        }
        echo "  실공정 수 N=$n\n";
    }
    echo "\n== 비활성 단계 ==\n";
    foreach ($pdo->query("SELECT stage_key, name, process_type, stage_group FROM {$p}process_stages WHERE is_active=0") as $r) {
        echo "  (비활성) {$r['process_type']} {$r['stage_key']} {$r['name']} group={$r['stage_group']}\n";
    }
    echo "\n== 정산 상태 백필 분포 ==\n";
    foreach ($pdo->query("SELECT settlement_status, COUNT(*) c FROM {$p}projects WHERE deleted_at IS NULL GROUP BY 1") as $r) {
        echo "  {$r['settlement_status']}: {$r['c']}건\n";
    }
    echo "\n== 예외 프로젝트 현황 ==\n";
    foreach ($pdo->query("SELECT id, name, status, settlement_status, expected_amount, contract_id FROM {$p}projects
        WHERE deleted_at IS NULL AND is_exception=1") as $r) {
        echo "  #{$r['id']} {$r['name']} status={$r['status']} 정산={$r['settlement_status']} 예정=" . ($r['expected_amount'] ?? 'NULL') . "\n";
    }
    echo "\n== payments 신규 컬럼 ==\n";
    foreach ($pdo->query("DESCRIBE {$p}payments") as $c) {
        if (in_array($c['Field'], ['contract_id', 'project_id', 'method', 'payer_name', 'created_by'], true)) {
            echo "  {$c['Field']} {$c['Type']} null={$c['Null']}\n";
        }
    }
    exit(0);
}

if ($mode === 'cleanup') {
    $ids = $pdo->query("SELECT id FROM {$p}projects WHERE name LIKE 'R11SC-%' OR name LIKE 'R11QA%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $pid) {
        $pid = (int) $pid;
        foreach (['payments', 'costs', 'project_assignments', 'project_status_history', 'project_process_history', 'schedules', 'site_bonuses'] as $t) {
            $pdo->prepare("DELETE FROM {$p}{$t} WHERE project_id = :p")->execute([':p' => $pid]);
        }
        $pdo->prepare("DELETE FROM {$p}projects WHERE id = :p")->execute([':p' => $pid]);
        echo "프로젝트 #$pid 하드삭제(입금·지출·배정·이력·일정·보너스 포함)\n";
    }
    $uid = $pdo->query("SELECT id FROM {$p}users WHERE login_id='qa_r11'")->fetchColumn();
    if ($uid) {
        $pdo->prepare("DELETE FROM {$p}notifications WHERE user_id = :u")->execute([':u' => (int) $uid]);
        $pdo->prepare("DELETE FROM {$p}users WHERE id = :u")->execute([':u' => (int) $uid]);
        echo "QA 계정 삭제(id=$uid)\n";
    }
    $pdo->exec("DELETE FROM {$p}login_attempts WHERE login_id IN ('qa_r11')");
    echo "원복 완료 — 감사 로그만 잔존(통제 증적)\n";
    exit(0);
}

fwrite(STDERR, "사용법: php deploy/prod_r11_qa.php setup|verify|cleanup\n");
exit(1);
