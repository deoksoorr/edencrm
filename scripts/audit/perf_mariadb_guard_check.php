<?php
/**
 * T8 — 마이그레이션 멱등 가드 패턴이 운영(MariaDB 10.6)에서 동작하는지 **읽기 전용**으로 검증한다.
 *
 * 배경: 로컬은 MySQL 9.6, 운영은 MariaDB 10.6.
 *   - MariaDB 는 `ALTER TABLE ... ADD KEY IF NOT EXISTS` 를 지원하지만 **MySQL 은 지원하지 않는다**.
 *   - 따라서 두 엔진 모두에서 동작하는 유일한 멱등 패턴은
 *       SET @sql = IF((information_schema 조회) > 0, 'DO 0', 'ALTER TABLE ... ADD KEY ...');
 *       PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
 *     이다. 이 스크립트는 그 구성요소가 MariaDB 에서 실제로 동작함을 확인한다.
 *
 * 안전장치 — 운영 DB 에 어떤 데이터/스키마 변경도 하지 않는다:
 *   - 세션을 SET SESSION TRANSACTION READ ONLY 로 고정
 *   - 실행하는 문장은 (1) 세션 사용자변수 SET (2) SELECT 의 PREPARE/EXECUTE (3) information_schema SELECT 뿐
 *   - ALTER/CREATE/DROP 은 문자열로만 만들고 **실행하지 않는다**(IF 분기가 'DO 0' 을 고르는지 확인)
 *
 * 사용: php scripts/audit/perf_mariadb_guard_check.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__, 2);
$env  = [];
foreach (file($root . '/deploy/cafe24.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) { continue; }
    [$k, $v] = explode('=', $l, 2);
    $env[trim($k)] = trim($v);
}
$P = $env['TBL_PREFIX'] ?? 'edencrm_';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_PORT'] ?? '3306', $env['DB_NAME']),
    $env['DB_USER'], $env['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');   // 쓰기 시도는 서버가 거부한다

echo '서버 버전: ' . $pdo->query('SELECT VERSION() v')->fetch()['v'] . "\n";

// ── 1) 가드의 판정부: information_schema.STATISTICS 조회가 MariaDB 에서 기대대로 동작하는가 ──
$q = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
$q->execute([$P . 'payments', 'idx_payments_status']);
$existing = (int) $q->fetch()['c'];
$q->execute([$P . 'payments', 'idx_payments_status_paid_date']);
$missing = (int) $q->fetch()['c'];
printf("판정부 — 존재하는 인덱스(idx_payments_status): %d행, 없는 인덱스(idx_payments_status_paid_date): %d행\n", $existing, $missing);
if ($existing < 1 || $missing !== 0) { fwrite(STDERR, "[실패] information_schema 판정부가 기대와 다름\n"); exit(3); }

// ── 2) 분기부: IF(...) 가 올바른 문자열을 고르는가 (실행하지 않고 문자열만 확인) ──
$branch = $pdo->query("SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$P}payments' AND INDEX_NAME = 'idx_payments_status') > 0,
    'DO 0', 'ALTER TABLE `{$P}payments` ADD KEY ...') AS chosen")->fetch()['chosen'];
echo "분기부 — 이미 존재할 때 선택되는 문장: '{$branch}'\n";
if ($branch !== 'DO 0') { fwrite(STDERR, "[실패] 멱등 분기가 'DO 0' 을 고르지 않음\n"); exit(3); }

// ── 3) 실행부: SET @var → PREPARE FROM @var → EXECUTE → DEALLOCATE 가 MariaDB 에서 되는가
//        (안전을 위해 SELECT 문만 준비·실행한다 — DDL 은 절대 실행하지 않는다)
$pdo->exec("SET @sql = 'SELECT 1 AS guard_ok'");
$pdo->exec('PREPARE guard_stmt FROM @sql');
$ok = $pdo->query('EXECUTE guard_stmt')->fetch();
$pdo->exec('DEALLOCATE PREPARE guard_stmt');
echo '실행부 — PREPARE FROM @sql / EXECUTE / DEALLOCATE: ' . (($ok['guard_ok'] ?? null) == 1 ? 'OK' : '실패') . "\n";

// ── 4) 'DO 0' 문장 자체가 MariaDB 에서 유효한 no-op 인가 (읽기 전용 세션에서도 안전) ──
$pdo->exec('DO 0');
echo "no-op — DO 0 실행 가능: OK\n";

echo "\n결론: information_schema 가드 + PREPARE/EXECUTE 멱등 패턴은 이 MariaDB 에서 동작한다.\n";
echo "      (운영 스키마는 이 스크립트로 전혀 변경되지 않았다 — READ ONLY 세션 + SELECT/DO 만 실행)\n";
