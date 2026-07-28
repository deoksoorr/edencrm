<?php
/**
 * 운영 DB 읽기 전용 점검 — R16 권한 마이그레이션 영향 산정용.
 * 쓰기 구문을 일절 실행하지 않는다(SELECT/SHOW 만).
 * 사용: php deploy/inspect_prod.php
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$envFile = __DIR__ . '/cafe24.env';
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) { continue; }
    [$k, $v] = explode('=', $l, 2);
    $env[trim($k)] = trim($v);
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $env['DB_HOST'], $env['DB_PORT'] ?? '3306', $env['DB_NAME']);
$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$P = $env['TBL_PREFIX'] ?? 'edencrm_';

echo "=== 운영 DB 점검 (읽기 전용) ===\n";
echo "서버 버전: " . $pdo->query("SELECT VERSION() v")->fetch()['v'] . "\n\n";

// 1) 역할별 계정 분포
echo "── 역할별 활성 계정 ──\n";
foreach ($pdo->query(
    "SELECT r.role_key, r.name, COUNT(*) c,
            SUM(u.status='active') act, SUM(u.deleted_at IS NOT NULL) del
       FROM {$P}users u JOIN {$P}roles r ON r.id = u.role_id
      GROUP BY r.role_key, r.name ORDER BY r.id"
) as $r) {
    printf("  %-15s %-10s 전체 %2d · 활성 %2d · 삭제 %2d\n",
        $r['role_key'], $r['name'], $r['c'], $r['act'], $r['del']);
}

// 2) 개별 권한 부여/제외 행
echo "\n── user_permissions(개별 부여·제외) ──\n";
$up = $pdo->query("SELECT COUNT(*) c FROM {$P}user_permissions")->fetch()['c'];
echo "  총 {$up}행\n";
if ($up > 0) {
    foreach ($pdo->query(
        "SELECT u.login_id, u.name, p.perm_key, up.is_grant
           FROM {$P}user_permissions up
           JOIN {$P}users u ON u.id = up.user_id
           JOIN {$P}permissions p ON p.id = up.permission_id
          ORDER BY u.id"
    ) as $r) {
        printf("  %-12s %-8s %-22s %s\n", $r['login_id'], $r['name'], $r['perm_key'],
            (int) $r['is_grant'] === 1 ? '부여' : '제외');
    }
}

// 3) 기존 데이터 건수 (배포 전후 비교 기준선)
echo "\n── 데이터 건수 기준선 ──\n";
$tables = ['users', 'customers', 'leads', 'quotes', 'quote_versions', 'quote_items',
           'contracts', 'payments', 'projects', 'costs', 'schedules', 'work_logs',
           'site_bonuses', 'goals', 'audit_logs', 'project_assignments'];
$baseline = [];
foreach ($tables as $t) {
    try {
        $c = (int) $pdo->query("SELECT COUNT(*) c FROM {$P}{$t}")->fetch()['c'];
        $baseline[$t] = $c;
        printf("  %-22s %6d\n", $t, $c);
    } catch (\Throwable $e) {
        printf("  %-22s   (없음)\n", $t);
    }
}

// 4) 소프트 삭제 현황
echo "\n── 소프트 삭제(휴지통) 현황 ──\n";
foreach (['customers', 'leads', 'quotes', 'contracts', 'projects', 'site_bonuses', 'goals'] as $t) {
    try {
        $c = (int) $pdo->query("SELECT COUNT(*) c FROM {$P}{$t} WHERE deleted_at IS NOT NULL")->fetch()['c'];
        printf("  %-22s %6d건 삭제됨\n", $t, $c);
    } catch (\Throwable $e) { /* 컬럼 없음 */ }
}

// 5) R16 대상 테이블 존재 여부
echo "\n── R16 마이그레이션 선행 확인 ──\n";
$has = $pdo->query("SHOW TABLES LIKE '{$P}employee_permissions'")->fetch();
echo "  {$P}employee_permissions: " . ($has ? '이미 존재(재실행 안전)' : '없음 — 신규 생성 예정') . "\n";
$newKeys = ['pipeline.delete','quote.delete','contract.delete','project.delete','process.view',
            'process.delete','schedule.delete','worklog.delete','cost.view','cost.delete','trash.manage'];
$in = implode(',', array_fill(0, count($newKeys), '?'));
$st = $pdo->prepare("SELECT perm_key FROM {$P}permissions WHERE perm_key IN ($in)");
$st->execute($newKeys);
$have = $st->fetchAll(PDO::FETCH_COLUMN);
echo "  신설 perm_key: " . count($have) . "/" . count($newKeys) . " 존재\n";

file_put_contents(__DIR__ . '/prod_baseline.json',
    json_encode(['at' => date('c'), 'counts' => $baseline], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n기준선 기록: deploy/prod_baseline.json\n";
