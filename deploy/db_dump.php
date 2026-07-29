<?php
/**
 * 운영 DB 백업(읽기 전용) — cafe24.env 접속으로 edencrm_% 테이블만 SQL 덤프.
 * 운영 스키마는 다른 프로젝트와 공유된다. 타 프로젝트 테이블은 절대 건드리지 않는다.
 * (스키마명은 곧 DB 계정명·FTP 계정명이기도 하므로 소스·문서에 적지 않는다 — cafe24.env 참조)
 * 사용: php deploy/db_dump.php [라벨]   → database/backups/proddb_<라벨>_<ts>.sql
 */
if (PHP_SAPI !== 'cli') { exit(1); }

// 파일명 타임스탬프는 backup.sh(셸 date, 로컬 KST)와 짝을 이뤄야 한다.
// PHP CLI 기본 타임존이 UTC 라 이걸 지정하지 않으면 파일명이 9시간 어긋나고,
// 장애 상황에서 이름만 보고 짝이 안 맞는 파일·DB 백업을 고르게 된다(날짜까지 달라짐).
date_default_timezone_set('Asia/Seoul');

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
// ── 덤프 대상 = prefix 일치 BASE TABLE ──────────────────────────────────────
// SHOW TABLES 는 뷰도 반환한다. 뷰를 SHOW CREATE TABLE 로 뽑으면 복구 불가능한
// 덤프가 만들어지므로 BASE TABLE 로 한정한다(현재 뷰는 없지만 생기면 조용히 깨진다).
$stmt = $pdo->prepare(
    "SELECT TABLE_NAME FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ?
      ORDER BY TABLE_NAME"
);
$stmt->execute([$env['DB_NAME'], $prefix . '%']);
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (!$tables) { fwrite(STDERR, "{$prefix}% 테이블 없음\n"); exit(1); }

// ── 컬럼 메타 수집 ─────────────────────────────────────────────────────────
// 생성 컬럼(GENERATED ALWAYS)에는 값을 INSERT 할 수 없다(MySQL 3105 / MariaDB 1906).
// SELECT * + 위치기반 INSERT 로 덤프하면 import 가 그 테이블에서 멈춘다 —
// 2026-07-29 DR 테스트에서 백업 14개 전부가 이 결함으로 복구 불가로 확인됐다.
// 그래서 생성 컬럼을 제외한 **명시적 컬럼 목록**으로 덤프한다.
$colStmt = $pdo->prepare(
    "SELECT TABLE_NAME, COLUMN_NAME, EXTRA FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?
      ORDER BY TABLE_NAME, ORDINAL_POSITION"
);
$colStmt->execute([$env['DB_NAME'], $prefix . '%']);
$cols = $skippedGen = [];
foreach ($colStmt->fetchAll() as $c) {
    if (stripos($c['EXTRA'], 'GENERATED') !== false) {
        $skippedGen[] = $c['TABLE_NAME'] . '.' . $c['COLUMN_NAME'];
        continue;
    }
    $cols[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
}

// ── 일관 스냅샷 ────────────────────────────────────────────────────────────
// 트랜잭션 없이 46개 테이블을 순차 조회하면 그 사이의 운영 쓰기가 섞여 들어가
// "계약은 담겼는데 그 입금은 빠진" 정합성 깨진 백업이 만들어질 수 있다.
// CONSISTENT SNAPSHOT 으로 덤프 전체를 한 시점으로 고정한다(읽기 전용, 운영 무영향).
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

$out = dirname(__DIR__) . '/database/backups/proddb_' . $label . '_' . date('Ymd-His') . '.sql';
$fh = fopen($out, 'w');
fwrite($fh, "-- eden_crm 운영 백업 " . date('c') . " · " . count($tables) . " tables (prefix {$prefix})\n");
fwrite($fh, "-- 일관 스냅샷(REPEATABLE READ) · 생성컬럼 제외 명시적 INSERT\n");
// charset 선언이 없으면 클라이언트 기본값에 따라 한글이 깨진 채 import 가 "성공"한다.
fwrite($fh, "SET NAMES utf8mb4;\n");
fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");

$rowsTotal = 0;
foreach ($tables as $t) {
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
    fwrite($fh, "\nDROP TABLE IF EXISTS `$t`;\n" . $create['Create Table'] . ";\n");

    $names   = $cols[$t] ?? [];
    if (!$names) { echo sprintf("%-40s (컬럼 없음 — 건너뜀)\n", $t); continue; }
    $colList = '`' . implode('`,`', $names) . '`';

    $stmt = $pdo->query("SELECT $colList FROM `$t`");
    $n = 0;
    while ($row = $stmt->fetch()) {
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
        fwrite($fh, "INSERT INTO `$t` ($colList) VALUES (" . implode(',', $vals) . ");\n");
        $n++;
    }
    $rowsTotal += $n;
    echo sprintf("%-40s %6d rows\n", $t, $n);
}
fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);
$pdo->commit();

echo "백업 완료: $out (" . count($tables) . " tables, $rowsTotal rows, " . number_format(filesize($out)) . " bytes)\n";
if ($skippedGen) {
    echo "생성컬럼 제외(정상): " . implode(', ', $skippedGen) . "\n";
}
echo "복구: mysql --default-character-set=utf8mb4 -u<user> -p <db> < " . basename($out) . "\n";
