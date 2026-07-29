<?php
/**
 * 운영 DB 읽기 전용 쿼리 러너 (T4 데이터 무결성 감사 전용).
 *
 * 안전장치
 *  - SELECT / SHOW / EXPLAIN / DESCRIBE / WITH 로 시작하는 문장만 허용
 *  - INSERT/UPDATE/DELETE/DROP/ALTER/CREATE/TRUNCATE/REPLACE/GRANT/SET 등은 즉시 거부
 *  - PDO::ATTR_EMULATE_PREPARES=false, 다중 문장 금지(세미콜론 분리 실행 안 함)
 *
 * 사용:
 *   php scripts/audit/prod_query.php --sql "SELECT 1"
 *   php scripts/audit/prod_query.php --file queries.sql   (--- 로 구분된 여러 쿼리)
 *   php scripts/audit/prod_query.php --json --sql "..."
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$opts = getopt('', ['sql:', 'file:', 'json', 'limit:']);
$root = dirname(__DIR__, 2);

$env = [];
foreach (file($root . '/deploy/cafe24.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) { continue; }
    [$k, $v] = explode('=', $l, 2);
    $env[trim($k)] = trim($v);
}

$P = $env['TBL_PREFIX'] ?? 'edencrm_';

/** 읽기 전용 검증 */
function assertReadOnly(string $sql): void
{
    $s = trim($sql);
    // 주석 제거(선두)
    $s = preg_replace('/^\s*(--[^\n]*\n|\/\*.*?\*\/|#[^\n]*\n)+/s', '', $s);
    $s = trim($s);
    if (!preg_match('/^(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC|WITH)\b/i', $s)) {
        fwrite(STDERR, "[거부] 읽기 전용 문장이 아님: " . substr($s, 0, 80) . "\n");
        exit(2);
    }
    if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE|GRANT|REVOKE|RENAME|LOAD\s+DATA|INTO\s+OUTFILE|CREATE)\b/i', $s)) {
        fwrite(STDERR, "[거부] 쓰기 키워드 포함: " . substr($s, 0, 80) . "\n");
        exit(2);
    }
    if (substr_count(rtrim($s, "; \n"), ';') > 0) {
        fwrite(STDERR, "[거부] 다중 문장 금지\n");
        exit(2);
    }
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $env['DB_HOST'], $env['DB_PORT'] ?? '3306', $env['DB_NAME']);
$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASSWORD'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
// 세션 자체를 읽기 전용으로 (MariaDB 지원)
try { $pdo->exec('SET SESSION TRANSACTION READ ONLY'); } catch (\Throwable $e) { /* 무시 */ }

$queries = [];
if (isset($opts['sql'])) {
    $queries[] = ['', $opts['sql']];
} elseif (isset($opts['file'])) {
    $raw = file_get_contents($opts['file']);
    foreach (preg_split('/^---+\s*$/m', $raw) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') { continue; }
        $label = '';
        if (preg_match('/^--\s*@(.+)$/m', $chunk, $m)) { $label = trim($m[1]); }
        $sql = trim(preg_replace('/^--\s*@.+$/m', '', $chunk));
        if ($sql === '') { continue; }
        $queries[] = [$label, $sql];
    }
} else {
    fwrite(STDERR, "--sql 또는 --file 필요\n");
    exit(1);
}

$limit = (int) ($opts['limit'] ?? 200);
$asJson = isset($opts['json']);
$out = [];

foreach ($queries as [$label, $sql]) {
    $sql = str_replace('{P}', $P, $sql);
    assertReadOnly($sql);
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (\Throwable $e) {
        $rows = [['__error' => $e->getMessage()]];
    }
    if ($asJson) {
        $out[] = ['label' => $label, 'rows' => array_slice($rows, 0, $limit), 'count' => count($rows)];
        continue;
    }
    echo "\n=== " . ($label !== '' ? $label : substr(preg_replace('/\s+/', ' ', $sql), 0, 70)) . " ===\n";
    if (!$rows) { echo "  (0행)\n"; continue; }
    $cols = array_keys($rows[0]);
    echo '  ' . implode(' | ', $cols) . "\n";
    echo '  ' . str_repeat('-', min(100, max(20, strlen(implode(' | ', $cols))))) . "\n";
    foreach (array_slice($rows, 0, $limit) as $r) {
        echo '  ' . implode(' | ', array_map(static fn($v) => $v === null ? 'NULL' : (string) $v, $r)) . "\n";
    }
    if (count($rows) > $limit) { echo '  ... (총 ' . count($rows) . "행)\n"; }
}

if ($asJson) { echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"; }
