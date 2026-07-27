<?php
/**
 * 운영 DB 마이그레이션 러너 (R6 배포 전용).
 * 로컬 mysql 클라이언트가 MariaDB native_password 를 못 여는 환경에서 PDO 로 적재한다.
 * cafe24.env 에서 접속값을 읽고, 인자로 받은 .sql 파일을 문장 단위로 실행한다.
 * 따옴표 안 세미콜론을 무시하는 안전 스플리터 사용. 실패 시 즉시 중단.
 * 사용: php deploy/run_migration.php database/cafe24/001_schema.sql [--dry]
 */
$envFile = __DIR__ . '/cafe24.env';
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
    [$k, $v] = explode('=', $l, 2);
    $env[trim($k)] = trim($v);
}
$sqlFile = $argv[1] ?? '';
$dry = in_array('--dry', $argv, true);
if (!is_file($sqlFile)) { fwrite(STDERR, "sql 파일 없음: $sqlFile\n"); exit(1); }

// 안전 스플리터: 문자열 리터럴('...' / "...") 과 라인주석(-- , #) 내부 세미콜론 무시.
function splitSql(string $sql): array {
    $stmts = []; $buf = ''; $n = strlen($sql);
    $inS = false; $inD = false; $inLine = false; $inBlock = false;
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i]; $c2 = $i + 1 < $n ? $sql[$i+1] : '';
        if ($inLine) { $buf .= $c; if ($c === "\n") $inLine = false; continue; }
        if ($inBlock) { $buf .= $c; if ($c === '*' && $c2 === '/') { $buf .= $c2; $i++; $inBlock = false; } continue; }
        if (!$inS && !$inD) {
            if ($c === '-' && $c2 === '-') { $inLine = true; $buf .= $c; continue; }
            if ($c === '#') { $inLine = true; $buf .= $c; continue; }
            if ($c === '/' && $c2 === '*') { $inBlock = true; $buf .= $c; continue; }
        }
        if ($c === "'" && !$inD) { $inS = !$inS; $buf .= $c; continue; }
        if ($c === '"' && !$inS) { $inD = !$inD; $buf .= $c; continue; }
        if ($c === ';' && !$inS && !$inD) { $t = trim($buf); if ($t !== '') $stmts[] = $t; $buf = ''; continue; }
        $buf .= $c;
    }
    $t = trim($buf); if ($t !== '') $stmts[] = $t;
    // 순수 주석/빈 문장 제거
    return array_values(array_filter($stmts, function ($s) {
        $lines = array_filter(array_map('trim', explode("\n", $s)), fn($x) => $x !== '' && !str_starts_with($x, '--') && !str_starts_with($x, '#'));
        return count($lines) > 0;
    }));
}

$raw = file_get_contents($sqlFile);
$stmts = splitSql($raw);
// 금지 작업 최종 게이트 (주석 제거 후 실제 DDL 만 검사)
function stripComments(string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', '', $s);
    $out = [];
    foreach (explode("\n", $s) as $line) {
        $t = ltrim($line);
        if (str_starts_with($t, '--') || str_starts_with($t, '#')) continue;
        $out[] = $line;
    }
    return implode("\n", $out);
}
foreach ($stmts as $s) {
    if (preg_match('/\b(CREATE\s+DATABASE|DROP\s+DATABASE|TRUNCATE)\b/i', stripComments($s))) {
        fwrite(STDERR, "금지 작업 감지, 중단: " . substr(stripComments($s), 0, 60) . "\n"); exit(2);
    }
}
echo basename($sqlFile) . ": 문장 " . count($stmts) . "개\n";
if ($dry) { echo "[dry-run] 실행하지 않음.\n"; exit(0); }

$dsn = "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4";
$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ok = 0;
foreach ($stmts as $idx => $s) {
    try { $pdo->exec($s); $ok++; }
    catch (Exception $e) {
        fwrite(STDERR, "문장 #" . ($idx+1) . " 실패, 중단.\n오류: " . $e->getMessage() . "\n대상: " . substr($s, 0, 80) . "\n");
        exit(3);
    }
}
echo "적용 완료: {$ok}/" . count($stmts) . " 문장\n";
