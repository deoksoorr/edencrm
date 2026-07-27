<?php
/**
 * 운영 DB 백업(읽기 전용) — cafe24.env 접속으로 edencrm_% 테이블만 SQL 덤프.
 * 공유 스키마(<DB_ACCOUNT>)의 타 프로젝트 테이블은 절대 건드리지 않는다.
 * 사용: php deploy/db_dump.php [라벨]   → database/backups/proddb_<라벨>_<ts>.sql
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
$label = preg_replace('/[^a-zA-Z0-9_-]/', '', $argv[1] ?? 'manual');
$prefix = $env['TBL_PREFIX'] ?: 'edencrm_';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_PORT'] ?: '3306', $env['DB_NAME']);
$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$tables = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($prefix . '%'))->fetchAll(PDO::FETCH_COLUMN);
if (!$tables) { fwrite(STDERR, "{$prefix}% 테이블 없음\n"); exit(1); }

$out = dirname(__DIR__) . '/database/backups/proddb_' . $label . '_' . date('Ymd-His') . '.sql';
$fh = fopen($out, 'w');
fwrite($fh, "-- eden_crm 운영 백업 " . date('c') . " · " . count($tables) . " tables (prefix {$prefix})\nSET FOREIGN_KEY_CHECKS=0;\n");
$rowsTotal = 0;
foreach ($tables as $t) {
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
    fwrite($fh, "\nDROP TABLE IF EXISTS `$t`;\n" . $create['Create Table'] . ";\n");
    $stmt = $pdo->query("SELECT * FROM `$t`");
    $n = 0;
    while ($row = $stmt->fetch()) {
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
        fwrite($fh, "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n");
        $n++;
    }
    $rowsTotal += $n;
    echo sprintf("%-40s %6d rows\n", $t, $n);
}
fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);
echo "백업 완료: $out (" . count($tables) . " tables, $rowsTotal rows, " . number_format(filesize($out)) . " bytes)\n";
