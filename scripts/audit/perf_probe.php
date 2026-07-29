<?php
/**
 * T8 성능 감사 — 라우트별 쿼리 수·응답시간 실측 프로브.
 *
 * 측정 방식(추측 배제)
 *  1) 전용 DB(eden_crm_perf) + 전용 계정(eden_perf)으로 띄운 PHP 서버(:8099)에 curl 로그인
 *  2) 라우트마다: MySQL NOW(6) 로 시각 경계를 잡고 → 요청 → mysql.general_log 에서
 *     해당 경계 이후 `user_host LIKE 'eden_perf%'` 행만 읽어 **정확한 쿼리 수**를 센다.
 *     (GLOBAL STATUS 델타는 같은 인스턴스의 다른 개발 서버 트래픽에 오염되므로 쓰지 않는다)
 *  3) 응답시간은 warmup 후 N회 반복의 중앙값(ms). PHP 내장 서버 기준 상대 비교용.
 *  4) 반복 쿼리(N+1)는 SQL 을 정규화(리터럴/공백 제거)해 같은 형태가 2회 이상 나오면 보고.
 *
 * 전제: general_log=ON, log_output=TABLE (스크립트가 직접 켠다)
 *
 * 사용:
 *   php scripts/audit/perf_probe.php --base http://127.0.0.1:8099 --runs 5
 *   php scripts/audit/perf_probe.php --json out.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
error_reporting(E_ALL & ~E_DEPRECATED);

$opts = getopt('', ['base:', 'runs:', 'user:', 'pass:', 'json:', 'socket:', 'label:', 'routes:']);
$base   = rtrim($opts['base'] ?? 'http://127.0.0.1:8099', '/') . '/index.php';
$runs   = max(1, (int) ($opts['runs'] ?? 5));
$user   = $opts['user'] ?? 'admin';
$pass   = $opts['pass'] ?? 'password123!';
$label  = $opts['label'] ?? 'S?';
$root   = dirname(__DIR__, 2);
$socket = $opts['socket'] ?? ($root . '/.devdb/mysql.sock');

/** 관리(root) 커넥션 — general_log 읽기·시각 경계 전용. 대상 DB 에는 손대지 않는다. */
$adm = new PDO("mysql:unix_socket={$socket};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$adm->exec("SET GLOBAL log_output='TABLE'");
$adm->exec("SET GLOBAL general_log='ON'");

function logReset(PDO $adm): void
{
    $adm->exec("SET GLOBAL general_log='OFF'");
    $adm->exec('TRUNCATE TABLE mysql.general_log');
    $adm->exec("SET GLOBAL general_log='ON'");
}

function nowMicro(PDO $adm): string
{
    return (string) $adm->query('SELECT NOW(6) n')->fetch()['n'];
}

/** 앱 커넥션이 실행한 문장만 추출 (Prepare 는 제외, Execute/Query 만 = 실제 실행 횟수) */
function logSince(PDO $adm, string $since): array
{
    $st = $adm->prepare("
        SELECT command_type, CONVERT(argument USING utf8mb4) sql_text
          FROM mysql.general_log
         WHERE user_host LIKE 'eden_perf%'
           AND event_time > ?
           AND command_type IN ('Query','Execute')
         ORDER BY event_time");
    $st->execute([$since]);
    return $st->fetchAll();
}

/** SQL 정규화 — 리터럴/숫자/공백 제거해 '쿼리 형태'로 묶는다 */
function norm(string $sql): string
{
    $s = preg_replace('/\s+/', ' ', trim($sql));
    $s = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $s);
    $s = preg_replace('/\b\d+\b/', '?', $s);
    $s = preg_replace('/\bIN \([\?,\s]+\)/i', 'IN (?)', $s);
    return $s;
}

// ── curl 세션 (쿠키 유지) ────────────────────────────────────────────────────
$cookie = tempnam(sys_get_temp_dir(), 'perfjar');

function req(string $url, ?array $post = null, string $cookie = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookie,
        CURLOPT_COOKIEFILE     => $cookie,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 120,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $t = microtime(true);
    $body = curl_exec($ch);
    $ms   = (microtime(true) - $t) * 1000;
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['code' => (int) $code, 'ms' => $ms, 'body' => (string) $body, 'len' => strlen((string) $body)];
}

// ── 로그인 ──────────────────────────────────────────────────────────────────
$r = req($base . '?r=login', null, $cookie);
if (!preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $r['body'], $m)
    && !preg_match('/name="_csrf"\s+value="([a-f0-9]+)"/', $r['body'], $m)) {
    fwrite(STDERR, "[실패] CSRF 토큰 추출 불가 (HTTP {$r['code']})\n");
    exit(3);
}
$r = req($base . '?r=login.submit', ['_csrf' => $m[1], 'login_id' => $user, 'password' => $pass], $cookie);
if ($r['code'] !== 302 && $r['code'] !== 200) {
    fwrite(STDERR, "[실패] 로그인 HTTP {$r['code']}\n");
    exit(3);
}
$chk = req($base . '?r=home', null, $cookie);
if (str_contains($chk['body'], 'name="login_id"')) {
    fwrite(STDERR, "[실패] 로그인 후에도 로그인 폼 — 자격증명 확인\n");
    exit(3);
}
echo "[perf_probe] 로그인 OK ({$user}) base={$base} runs={$runs} label={$label}\n\n";

// ── 측정 대상 라우트 ────────────────────────────────────────────────────────
$today = date('Y-m-d');
$from  = date('Y-m-d', strtotime('-180 days'));
$ROUTES = [
    'home'                => 'r=home',
    'customers.index'     => 'r=customers.index',
    'pipeline.index'      => 'r=pipeline.index',
    'quotes.index'        => 'r=quotes.index',
    'contracts.index'     => 'r=contracts.index',
    'projects.index'      => 'r=projects.index',
    'process.board'       => 'r=process.board',
    'schedule.index'      => 'r=schedule.index',
    'reports.index'       => 'r=reports.index',
    'reports.data'        => "r=reports.data&from={$from}&to={$today}",
    'staff.index'         => 'r=staff.index',
    'halfyear.index'      => 'r=halfyear.index',
    'performance.index'   => 'r=performance.index',
    'dashboard.data'      => 'r=dashboard.data',
];
if (!empty($opts['routes'])) {
    $keep = array_flip(array_map('trim', explode(',', $opts['routes'])));
    $ROUTES = array_intersect_key($ROUTES, $keep);
}

$results = [];
foreach ($ROUTES as $key => $qs) {
    $url = $base . '?' . $qs;

    // warmup (opcache·세션·설정 캐시 워밍) — 측정에서 제외
    req($url, null, $cookie);

    // 1) 쿼리 수 측정 (로그 리셋 후 1회)
    logReset($adm);
    $since = nowMicro($adm);
    usleep(20000);
    $one = req($url, null, $cookie);
    usleep(120000); // general_log 플러시 여유
    $rows = logSince($adm, $since);

    $shapes = [];
    foreach ($rows as $lr) {
        $n = norm($lr['sql_text']);
        if ($n === '' || preg_match('/^(SET |SHOW |START TRANSACTION|COMMIT|ROLLBACK|SELECT NOW)/i', $n)) { continue; }
        $shapes[$n] = ($shapes[$n] ?? 0) + 1;
    }
    $qcount = array_sum($shapes);
    arsort($shapes);
    $repeats = array_filter($shapes, fn($c) => $c >= 2);

    // 2) 응답시간 측정 (로그 OFF 로 오버헤드 제거)
    $adm->exec("SET GLOBAL general_log='OFF'");
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $t = req($url, null, $cookie);
        $times[] = $t['ms'];
    }
    $adm->exec("SET GLOBAL general_log='ON'");
    sort($times);
    $median = $times[(int) floor(count($times) / 2)];

    $results[$key] = [
        'route'   => $key,
        'http'    => $one['code'],
        'bytes'   => $one['len'],
        'queries' => $qcount,
        'shapes'  => count($shapes),
        'ms_med'  => round($median, 1),
        'ms_min'  => round($times[0], 1),
        'ms_max'  => round($times[count($times) - 1], 1),
        'repeats' => array_slice($repeats, 0, 12, true),
    ];

    printf("%-20s HTTP %-3d  쿼리 %4d (형태 %3d)  %7.1f ms  (min %.1f / max %.1f)  %d bytes\n",
        $key, $one['code'], $qcount, count($shapes), $median, $times[0], $times[count($times) - 1], $one['len']);
}

// ── 반복 쿼리(N+1 후보) 요약 ────────────────────────────────────────────────
echo "\n=== 반복 실행 쿼리(같은 요청 안에서 2회 이상) ===\n";
foreach ($results as $k => $r) {
    if (!$r['repeats']) { continue; }
    echo "\n[{$k}] 총 {$r['queries']}회 / 고유 {$r['shapes']}형태\n";
    foreach ($r['repeats'] as $sql => $cnt) {
        printf("   x%-4d %s\n", $cnt, mb_substr($sql, 0, 150));
    }
}

$adm->exec("SET GLOBAL general_log='OFF'");

if (!empty($opts['json'])) {
    file_put_contents($opts['json'], json_encode(['label' => $label, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\n[perf_probe] JSON: {$opts['json']}\n";
}
@unlink($cookie);
