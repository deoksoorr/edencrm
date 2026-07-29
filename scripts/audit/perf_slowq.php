<?php
/**
 * T8 성능 감사 — 라우트 1회 요청이 실행한 개별 쿼리를 뽑아 재실행·EXPLAIN 한다.
 *
 * general_log 의 'Execute' 행은 바인딩이 치환된 완성 SQL 이므로 그대로 재실행할 수 있다.
 * 각 쿼리를 3회 실행한 중앙값(ms)과 EXPLAIN 요약(type/rows/key/Extra)을 함께 낸다.
 *
 * 사용:
 *   php scripts/audit/perf_slowq.php --route home
 *   php scripts/audit/perf_slowq.php --route dashboard.data --top 15
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
error_reporting(E_ALL & ~E_DEPRECATED);

$opts   = getopt('', ['route:', 'base:', 'top:', 'socket:', 'db:', 'user:', 'pass:', 'qs:']);
$route  = $opts['route'] ?? 'home';
$qs     = $opts['qs'] ?? ('r=' . $route);
$base   = rtrim($opts['base'] ?? 'http://127.0.0.1:8099', '/') . '/index.php';
$top    = (int) ($opts['top'] ?? 20);
$root   = dirname(__DIR__, 2);
$socket = $opts['socket'] ?? ($root . '/.devdb/mysql.sock');
$dbName = $opts['db'] ?? 'eden_crm_perf';

if (!str_ends_with($dbName, '_perf')) {
    fwrite(STDERR, "[거부] --db 는 '_perf' 로 끝나야 한다.\n"); exit(2);
}

$adm = new PDO("mysql:unix_socket={$socket};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$run = new PDO("mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$cookie = tempnam(sys_get_temp_dir(), 'slowq');
function req(string $url, ?array $post = null, string $cookie = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = curl_exec($ch);
    return ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => (string) $b];
}

$r = req($base . '?r=login', null, $cookie);
preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $r['body'], $m);
req($base . '?r=login.submit', ['_csrf' => $m[1] ?? '', 'login_id' => 'admin', 'password' => 'password123!'], $cookie);

$url = $base . '?' . $qs;
req($url, null, $cookie); // warmup

$adm->exec("SET GLOBAL log_output='TABLE'");
$adm->exec("SET GLOBAL general_log='OFF'");
$adm->exec('TRUNCATE TABLE mysql.general_log');
$adm->exec("SET GLOBAL general_log='ON'");
$since = (string) $adm->query('SELECT NOW(6) n')->fetch()['n'];
usleep(20000);
$resp = req($url, null, $cookie);
usleep(150000);
$adm->exec("SET GLOBAL general_log='OFF'");

$st = $adm->prepare("SELECT CONVERT(argument USING utf8mb4) s FROM mysql.general_log
    WHERE user_host LIKE 'eden_perf%' AND event_time > ? AND command_type IN ('Query','Execute') ORDER BY event_time");
$st->execute([$since]);
$rows = array_column($st->fetchAll(), 's');

echo "[perf_slowq] route={$route} HTTP {$resp['code']} 로그 {$rows[0]}...\n" ;
echo "[perf_slowq] 총 문장 " . count($rows) . "\n\n";

// 같은 SQL(리터럴 포함)은 1회만 측정, 실행 횟수는 센다
$uniq = [];
foreach ($rows as $s) {
    $s = trim($s);
    if ($s === '' || preg_match('/^(SET |SHOW |START TRANSACTION|COMMIT|ROLLBACK|SELECT NOW)/i', $s)) { continue; }
    $uniq[$s] = ($uniq[$s] ?? 0) + 1;
}

$out = [];
foreach ($uniq as $sql => $cnt) {
    $ts = [];
    for ($i = 0; $i < 3; $i++) {
        $t = microtime(true);
        try { $run->query($sql)->fetchAll(); } catch (\Throwable $e) { $ts[] = -1; break; }
        $ts[] = (microtime(true) - $t) * 1000;
    }
    sort($ts);
    $med = $ts[(int) floor(count($ts) / 2)];
    $ex = [];
    try {
        // MySQL 8.3+/9.x 는 EXPLAIN 기본 출력이 TREE 형식이라 컬럼 접근이 불가 — TRADITIONAL 강제
        foreach ($run->query('EXPLAIN FORMAT=TRADITIONAL ' . $sql)->fetchAll() as $e) {
            $ex[] = sprintf('%s[%s rows=%s key=%s%s]', $e['table'] ?? '?', $e['type'] ?? '?',
                $e['rows'] ?? '?', $e['key'] ?? '-',
                !empty($e['Extra']) && str_contains($e['Extra'], 'filesort') ? ' FILESORT' : '');
        }
    } catch (\Throwable $e) { $ex[] = 'EXPLAIN 실패'; }
    $out[] = ['sql' => $sql, 'n' => $cnt, 'ms' => $med, 'total' => $med * $cnt, 'explain' => implode(' ', $ex)];
}

usort($out, fn($a, $b) => $b['total'] <=> $a['total']);
$sumAll = array_sum(array_column($out, 'total'));
printf("=== 쿼리 시간 상위 %d (총 DB 시간 %.1f ms) ===\n\n", $top, $sumAll);
foreach (array_slice($out, 0, $top) as $i => $o) {
    printf("#%-2d  %7.1f ms × %d회 = %8.1f ms (%.1f%%)\n", $i + 1, $o['ms'], $o['n'], $o['total'], $o['total'] / max(0.01, $sumAll) * 100);
    echo '     ' . preg_replace('/\s+/', ' ', mb_substr($o['sql'], 0, 400)) . "\n";
    echo '     EXPLAIN: ' . mb_substr($o['explain'], 0, 400) . "\n\n";
}
@unlink($cookie);
