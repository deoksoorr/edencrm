<?php
/**
 * 로컬 dev DB 마이그레이션 러너 — app config(config.local.php) 접속으로 .sql 을 문장 단위 실행.
 * 운영은 deploy/run_migration.php(cafe24.env) 를 사용한다 — 이 스크립트는 로컬 전용.
 * 사용: php scripts/apply_local_migration.php database/migrations/2026-07-27_r11_settlement.sql [--dry]
 */
if (PHP_SAPI !== 'cli') { exit(1); }
$GLOBALS['config'] = require __DIR__ . '/../app/config/config.php';
require_once APP_PATH . '/core/Db.php';

$sqlFile = $argv[1] ?? '';
$dry = in_array('--dry', $argv, true);
if (!is_file($sqlFile)) { fwrite(STDERR, "sql 파일 없음: $sqlFile\n"); exit(1); }
if (($GLOBALS['config']['APP_ENV'] ?? '') === 'production') {
    fwrite(STDERR, "로컬 전용 러너입니다(APP_ENV=production 차단).\n"); exit(1);
}

// 문장 스플리터 — deploy/run_migration.php 와 동일 규칙(문자열·주석 내 세미콜론 무시)
function splitSql(string $sql): array {
    $stmts = []; $buf = ''; $n = strlen($sql);
    $inS = false; $inD = false; $inLine = false; $inBlock = false;
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i]; $c2 = $i + 1 < $n ? $sql[$i + 1] : '';
        if ($inLine) { $buf .= $c; if ($c === "\n") { $inLine = false; } continue; }
        if ($inBlock) { $buf .= $c; if ($c === '*' && $c2 === '/') { $buf .= $c2; $i++; $inBlock = false; } continue; }
        if (!$inS && !$inD) {
            if ($c === '-' && $c2 === '-') { $inLine = true; $buf .= $c; continue; }
            if ($c === '#') { $inLine = true; $buf .= $c; continue; }
            if ($c === '/' && $c2 === '*') { $inBlock = true; $buf .= $c; continue; }
        }
        if ($c === "'" && !$inD) { $inS = !$inS; $buf .= $c; continue; }
        if ($c === '"' && !$inS) { $inD = !$inD; $buf .= $c; continue; }
        if ($c === ';' && !$inS && !$inD) { $t = trim($buf); if ($t !== '') { $stmts[] = $t; } $buf = ''; continue; }
        $buf .= $c;
    }
    $t = trim($buf); if ($t !== '') { $stmts[] = $t; }
    return array_values(array_filter($stmts, function ($s) {
        $lines = array_filter(array_map('trim', explode("\n", $s)), fn($x) => $x !== '' && !str_starts_with($x, '--') && !str_starts_with($x, '#'));
        return count($lines) > 0;
    }));
}

$stmts = splitSql((string) file_get_contents($sqlFile));
echo ($dry ? '[dry] ' : '') . basename($sqlFile) . ' — ' . count($stmts) . "개 문장\n";
foreach ($stmts as $i => $s) {
    $head = preg_replace('/\s+/', ' ', mb_substr(trim($s), 0, 90));
    echo sprintf("%2d) %s…\n", $i + 1, $head);
    if ($dry) { continue; }
    try {
        Db::pdo()->exec($s);
    } catch (Throwable $e) {
        fwrite(STDERR, "실패: {$e->getMessage()}\n");
        exit(1);
    }
}
echo $dry ? "dry-run 종료(실행 없음)\n" : "적용 완료\n";
