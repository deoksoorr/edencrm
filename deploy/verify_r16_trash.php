<?php
/**
 * R16 운영 실검수 — 휴지통 전체 수명주기(생성 → 휴지통 이동 → 복원 → 완전삭제).
 * QA prefix 레코드만 사용하고 종료 시 흔적을 남기지 않는다. 운영 실데이터는 건드리지 않는다.
 * 사용: php deploy/verify_r16_trash.php
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$ID = 'qa_r16_verify';
$PW = trim(@file_get_contents('/tmp/r16_verify_pw.txt') ?: '');
if ($PW === '') { fwrite(STDERR, "검수 계정 비밀번호 없음\n"); exit(1); }

$env = [];
foreach (file(__DIR__ . '/cafe24.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) { continue; }
    [$k, $v] = explode('=', $l, 2);
    $env[trim($k)] = trim($v);
}

// 서비스 URL 은 env 에서 읽는다. 예전에는 하드코딩돼 있어 저장소에 운영 도메인과
// 계정명이 그대로 남았다(카페24는 계정명 = FTP 계정명이라 자격증명의 절반이다).
if (empty($env['SERVICE_URL'])) { fwrite(STDERR, "SERVICE_URL 누락\n"); exit(1); }
$BASE = rtrim($env['SERVICE_URL'], '/') . '/index.php';
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'], $env['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$P = $env['TBL_PREFIX'];

$pass = 0; $fail = 0; $fails = [];
function ok(string $m) { global $pass; $pass++; echo "  ✅ $m\n"; }
function bad(string $m) { global $fail, $fails; $fail++; $fails[] = $m; echo "  ❌ $m\n"; }
function chk(string $m, bool $c) { $c ? ok($m) : bad($m); }

$jar = tempnam(sys_get_temp_dir(), 'r16jar');
function req(string $url, ?array $post = null, bool $json = true): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $json ? ['X-Requested-With: XMLHttpRequest'] : [],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) { curl_close($ch); }
    return ['code' => $code, 'body' => (string) $body];
}
function row(int $id): ?array {
    global $pdo, $P;
    $st = $pdo->prepare("SELECT id, name, deleted_at FROM {$P}customers WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// ── 로그인 ──
$lp = req("$BASE?r=login", null, false);
preg_match('/name="_csrf" value="([^"]*)"/', $lp['body'], $m);
req("$BASE?r=login.submit", ['_csrf' => $m[1] ?? '', 'login_id' => $ID, 'password' => $PW], false);
$home = req("$BASE?r=home", null, false);
preg_match('/name="csrf-token" content="([^"]*)"/', $home['body'], $m2);
$tok = $m2[1] ?? '';
chk('검수 계정 로그인', $home['code'] === 200 && $tok !== '');
if ($tok === '') { echo "토큰 없음 — 중단\n"; exit(1); }

echo "\n════ 휴지통 수명주기 (QA 레코드 전용) ════\n";

// 1) 생성
$r = req("$BASE?r=customers.save", ['_csrf' => $tok, 'name' => 'QA_R16_휴지통검수', 'customer_type' => 'individual', 'privacy_agreed' => 1]);
$d = json_decode($r['body'], true);
$cid = (int) ($d['data']['id'] ?? 0);
chk("QA 고객 생성 (id=$cid)", $cid > 0);
if ($cid <= 0) { echo "   응답: " . substr($r['body'], 0, 300) . "\n"; exit(1); }

// 2) 소프트 삭제 → 휴지통
$r = req("$BASE?r=customers.delete", ['_csrf' => $tok, 'id' => $cid]);
$after = row($cid);
chk('삭제 요청 성공', $r['code'] === 200);
chk('휴지통 이동(행 보존 + deleted_at 설정)', $after !== null && $after['deleted_at'] !== null);

// 3) 휴지통 목록에 보이는가
$t = req("$BASE?r=customers.index&trash=1", null, false);
chk('휴지통 목록에 노출', $t['code'] === 200 && str_contains($t['body'], 'QA_R16_휴지통검수'));

// 4) 일반 목록에서는 사라졌는가
$n = req("$BASE?r=customers.index", null, false);
chk('일반 목록에서 제외', !str_contains($n['body'], 'QA_R16_휴지통검수'));

// 5) 복원
$r = req("$BASE?r=customers.restore", ['_csrf' => $tok, 'id' => $cid]);
$after = row($cid);
// 복원·완전삭제는 폼 POST 라 성공 시 휴지통 목록으로 302 리다이렉트한다(JSON 아님).
chk('복원 요청 성공(200 또는 302)', in_array($r['code'], [200, 302], true));
chk('복원 후 deleted_at 해제', $after !== null && $after['deleted_at'] === null);
$n = req("$BASE?r=customers.index", null, false);
chk('복원 후 일반 목록에 재등장', str_contains($n['body'], 'QA_R16_휴지통검수'));

// 6) 재삭제 후 완전삭제
req("$BASE?r=customers.delete", ['_csrf' => $tok, 'id' => $cid]);
$r = req("$BASE?r=customers.purge", ['_csrf' => $tok, 'id' => $cid]);
chk('완전삭제 요청 성공(200 또는 302)', in_array($r['code'], [200, 302], true));
chk('완전삭제 후 행 물리 제거', row($cid) === null);

// 7) 감사 로그
$st = $pdo->prepare("SELECT action, entity, entity_id FROM {$P}audit_logs
                      WHERE entity_id = ? AND action IN ('trash_purge','trash_restore')
                      ORDER BY id DESC");
$st->execute([$cid]);
$logs = $st->fetchAll();
$acts = array_column($logs, 'action');
chk('복원 감사 로그 기록', in_array('trash_restore', $acts, true));
chk('완전삭제 감사 로그 기록(대상 삭제 후에도 잔존)', in_array('trash_purge', $acts, true));

// 8) 중복 완전삭제 안전 처리
$r = req("$BASE?r=customers.purge", ['_csrf' => $tok, 'id' => $cid]);
chk('이미 삭제된 대상 재요청 안전 거부', $r['code'] !== 500);

@unlink($jar);
echo "\n════════════════════════════════════════\n";
printf("결과: PASS %d · FAIL %d\n", $pass, $fail);
foreach ($fails as $f) { echo "  ✗ $f\n"; }
exit($fail === 0 ? 0 : 1);
