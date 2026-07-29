<?php
/**
 * DR 테스트 — 기존 백업 덤프 복구 가능화 변환.
 *
 * 배경: deploy/db_dump.php 가 `SELECT *` 결과를 그대로 `INSERT INTO t VALUES (...)` 로
 * 쓴다. 생성 컬럼(GENERATED ALWAYS ... VIRTUAL)까지 값 목록에 들어가는데, 생성 컬럼에는
 * 값을 INSERT 할 수 없다(MySQL 3105 / MariaDB 1906). 그래서 기존 백업 14개 전부가
 * 중간에 멈춘다. 이 스크립트는 **원본 백업을 수정하지 않고** 복구 가능한 사본을 만든다.
 *
 * 하는 일 두 가지:
 *  1) 생성 컬럼을 제외한 명시적 컬럼 목록을 붙인 INSERT 로 재작성
 *  2) 덤프 선두에 SET NAMES utf8mb4 추가 (원본에 charset 선언이 없어 한글 파손 위험)
 *
 * 사용: php scripts/dr/repair_dump.php <원본.sql> <출력.sql>
 */

if (PHP_SAPI !== 'cli') { exit(1); }
$src = $argv[1] ?? null;
$dst = $argv[2] ?? null;
if (!$src || !$dst) { fwrite(STDERR, "사용: php repair_dump.php <원본.sql> <출력.sql>\n"); exit(1); }
if (!is_file($src)) { fwrite(STDERR, "원본 없음: $src\n"); exit(1); }
if (realpath($src) === realpath($dst ?: '')) { fwrite(STDERR, "원본을 덮어쓸 수 없다\n"); exit(1); }

/**
 * INSERT 의 VALUES(...) 안을 최상위 콤마 기준으로 쪼갠다.
 * 값은 PDO::quote 로 만들어졌으므로 작은따옴표 문자열 + 백슬래시 이스케이프를 고려해야
 * 한다. 단순 explode(',') 는 '서울시, 강남구' 같은 값에서 바로 깨진다.
 */
function split_values(string $s): array
{
    $out = [];
    $buf = '';
    $inStr = false;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($inStr) {
            if ($ch === '\\' && $i + 1 < $len) { $buf .= $ch . $s[$i + 1]; $i++; continue; }
            if ($ch === "'") { $inStr = false; }
            $buf .= $ch;
            continue;
        }
        if ($ch === "'") { $inStr = true; $buf .= $ch; continue; }
        if ($ch === ',') { $out[] = $buf; $buf = ''; continue; }
        $buf .= $ch;
    }
    $out[] = $buf;
    return $out;
}

$in  = fopen($src, 'r');
$out = fopen($dst, 'w');

$tables      = [];      // table => ['cols' => [...], 'generated' => [idx => true]]
$curTable    = null;    // CREATE TABLE 파싱 중인 테이블
$headerDone  = false;
$stats       = ['insert_total' => 0, 'insert_rewritten' => 0, 'generated_cols' => 0, 'tables_with_generated' => []];
$lineNo      = 0;

while (($line = fgets($in)) !== false) {
    $lineNo++;

    // 덤프 선두(첫 주석 직후)에 charset 선언을 넣는다.
    if (!$headerDone && str_starts_with($line, 'SET FOREIGN_KEY_CHECKS=0;')) {
        fwrite($out, "-- [DR 복구변환] 원본: " . basename($src) . " · 변환 " . date('c') . "\n");
        fwrite($out, "-- 변환 내용: (1) 생성컬럼 제외 명시적 INSERT (2) charset 선언 추가\n");
        fwrite($out, "SET NAMES utf8mb4;\n");
        fwrite($out, "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION';\n");
        fwrite($out, $line);
        $headerDone = true;
        continue;
    }

    // CREATE TABLE 시작
    if (preg_match('/^CREATE TABLE `([^`]+)` \($/', $line, $m)) {
        $curTable = $m[1];
        $tables[$curTable] = ['cols' => [], 'generated' => []];
        fwrite($out, $line);
        continue;
    }

    // CREATE TABLE 본문 — 컬럼 정의 수집
    if ($curTable !== null) {
        if (preg_match('/^\s*`([^`]+)`\s+(.*)$/', $line, $m)) {
            $col = $m[1];
            $rest = $m[2];
            // PRIMARY KEY / UNIQUE KEY / KEY / CONSTRAINT 행은 컬럼이 아니다.
            // 이 행들은 백틱이 선두에 오지 않으므로 위 정규식에 걸리지 않지만,
            // 방어적으로 한 번 더 거른다.
            if (!preg_match('/^\s*(PRIMARY|UNIQUE|KEY|CONSTRAINT|FULLTEXT|SPATIAL|INDEX)\b/i', $line)) {
                $idx = count($tables[$curTable]['cols']);
                $tables[$curTable]['cols'][] = $col;
                if (stripos($rest, 'GENERATED ALWAYS') !== false) {
                    $tables[$curTable]['generated'][$idx] = true;
                    $stats['generated_cols']++;
                    $stats['tables_with_generated'][$curTable] = $col;
                }
            }
        }
        if (preg_match('/^\)\s*ENGINE=/i', $line)) { $curTable = null; }
        fwrite($out, $line);
        continue;
    }

    // INSERT 재작성
    if (preg_match('/^INSERT INTO `([^`]+)` VALUES \((.*)\);\s*$/s', $line, $m)) {
        $stats['insert_total']++;
        $t = $m[1];
        $gen = $tables[$t]['generated'] ?? [];
        if (!$gen) { fwrite($out, $line); continue; }

        $cols = $tables[$t]['cols'];
        $vals = split_values($m[2]);
        if (count($vals) !== count($cols)) {
            fwrite(STDERR, "경고: {$t} {$lineNo}행 — 값 " . count($vals) . "개 ≠ 컬럼 " . count($cols) . "개, 원본 유지\n");
            fwrite($out, $line);
            continue;
        }
        $keepCols = $keepVals = [];
        foreach ($cols as $i => $c) {
            if (isset($gen[$i])) continue;             // 생성 컬럼은 통째로 제외
            $keepCols[] = '`' . $c . '`';
            $keepVals[] = $vals[$i];
        }
        fwrite($out, "INSERT INTO `$t` (" . implode(',', $keepCols) . ") VALUES (" . implode(',', $keepVals) . ");\n");
        $stats['insert_rewritten']++;
        continue;
    }

    fwrite($out, $line);
}
fclose($in);
fclose($out);

echo "복구변환 완료: $dst\n";
echo "  테이블 파싱      : " . count($tables) . "\n";
echo "  생성컬럼 발견    : {$stats['generated_cols']}개\n";
foreach ($stats['tables_with_generated'] as $t => $c) echo "     - $t.$c\n";
echo "  INSERT 전체      : {$stats['insert_total']}\n";
echo "  INSERT 재작성    : {$stats['insert_rewritten']}\n";
echo "  원본 크기        : " . number_format(filesize($src)) . " bytes\n";
echo "  변환본 크기      : " . number_format(filesize($dst)) . " bytes\n";
