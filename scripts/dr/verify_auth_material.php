<?php
/**
 * DR 테스트 T7 — 인증 자격증명 무결성 검증.
 *
 * "복구본으로 로그인이 되는가"를 증명하려면 원래 비밀번호를 알아야 할 것 같지만,
 * 사실 그럴 필요가 없다. 운영의 password_hash 와 복구본의 password_hash 가
 * 바이트 단위로 같다면, 운영에서 통하는 비밀번호는 복구본에서도 반드시 통한다
 * (bcrypt 검증은 해시와 입력만의 함수다). 그래서 해시 값을 화면에 노출하지 않고
 * SHA-256 지문만 비교한다 — 검증은 하되 비밀은 남기지 않는다.
 *
 * 사용: php scripts/dr/verify_auth_material.php
 */

require __DIR__ . '/ro_guard.php';

if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 2);

// ── 운영(읽기 전용) ────────────────────────────────────────────────────────
$env    = dr_env($root . '/deploy/cafe24.env');
$prefix = $env['TBL_PREFIX'] ?: 'edencrm_';
$pdoP   = dr_connect_readonly($env);
foreach (ro_selftest($pdoP) as $s) {
    if (!$s['blocked']) { fwrite(STDERR, "RO 가드 실패 — 중단\n"); exit(2); }
}
// 해시 원문은 PHP 밖으로 내보내지 않는다. 서버에서 바로 지문으로 바꿔 받는다.
$prod = [];
foreach (ro($pdoP, "SELECT id, login_id, role_key, status,
                           SHA2(password_hash, 256) AS fp, LENGTH(password_hash) AS len,
                           LEFT(password_hash, 7) AS algo, locked_until, failed_attempts,
                           must_change_password, deleted_at
                      FROM `{$prefix}users` ORDER BY id") as $r) {
    $prod[$r['id']] = $r;
}
$prodPerm = [];
foreach (ro($pdoP, "SELECT user_id, section, resource_key, can_read, can_write, can_delete
                      FROM `{$prefix}employee_permissions` ORDER BY user_id, section, resource_key") as $r) {
    $prodPerm[$r['user_id'] . '|' . $r['section'] . '|' . $r['resource_key']] = $r;
}
$prodRolePerm = [];
foreach (ro($pdoP, "SELECT rp.role_id, p.perm_key FROM `{$prefix}role_permissions` rp
                      JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
                     ORDER BY rp.role_id, p.perm_key") as $r) {
    $prodRolePerm[] = $r['role_id'] . ':' . $r['perm_key'];
}
$pdoP->rollBack();

// ── 복구본 ────────────────────────────────────────────────────────────────
$conf = require '/Users/deoksookim/Desktop/코드/claude code/eden_crm_restore_test/_dr/config.restore.php';
if (!str_ends_with($conf['DB_NAME'], '_restore_test')) { fwrite(STDERR, "가드: 복구 DB 아님\n"); exit(9); }
$pdoR = new PDO(
    "mysql:unix_socket={$conf['DB_SOCKET']};dbname={$conf['DB_NAME']};charset=utf8mb4",
    $conf['DB_USER'], $conf['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$rest = [];
foreach ($pdoR->query("SELECT id, login_id, role_key, status,
                              SHA2(password_hash, 256) AS fp, LENGTH(password_hash) AS len,
                              LEFT(password_hash, 7) AS algo, locked_until, failed_attempts,
                              must_change_password, deleted_at
                         FROM `{$prefix}users` ORDER BY id") as $r) {
    $rest[$r['id']] = $r;
}
$restPerm = [];
foreach ($pdoR->query("SELECT user_id, section, resource_key, can_read, can_write, can_delete
                         FROM `{$prefix}employee_permissions` ORDER BY user_id, section, resource_key") as $r) {
    $restPerm[$r['user_id'] . '|' . $r['section'] . '|' . $r['resource_key']] = $r;
}
$restRolePerm = [];
foreach ($pdoR->query("SELECT rp.role_id, p.perm_key FROM `{$prefix}role_permissions` rp
                         JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
                        ORDER BY rp.role_id, p.perm_key") as $r) {
    $restRolePerm[] = $r['role_id'] . ':' . $r['perm_key'];
}

// ── 대조 ──────────────────────────────────────────────────────────────────
$issues = [];
printf("== T7 인증 자격증명 무결성 ==\n");
printf("계정 수: 운영 %d / 복구본 %d\n\n", count($prod), count($rest));
printf("%-4s %-14s %-14s %-8s %-10s %-8s %s\n", 'id', 'login_id', 'role_key', 'status', '해시일치', '길이', '잠금');
foreach ($prod as $id => $p) {
    $r = $rest[$id] ?? null;
    if (!$r) { $issues[] = "계정 누락 id=$id"; printf("%-4s %-14s ❌ 복구본에 없음\n", $id, $p['login_id']); continue; }
    $hashSame = ($p['fp'] === $r['fp']);
    $ok = $hashSame && $p['login_id'] === $r['login_id'] && $p['role_key'] === $r['role_key']
        && $p['status'] === $r['status'] && (string) $p['deleted_at'] === (string) $r['deleted_at'];
    if (!$hashSame) $issues[] = "해시 불일치 id=$id ({$p['login_id']})";
    if ($p['role_key'] !== $r['role_key']) $issues[] = "역할 불일치 id=$id";
    if ($p['status'] !== $r['status']) $issues[] = "상태 불일치 id=$id";
    printf("%-4s %-14s %-14s %-8s %-10s %-8s %s\n",
        $id, $r['login_id'], $r['role_key'], $r['status'],
        $hashSame ? '✅ 동일' : '❌ 다름', $r['len'],
        $r['locked_until'] ? "⚠ {$r['locked_until']}" : '없음');
}

// 복구 직후 잠긴 계정이 있으면 로그인 자체가 막힌다 — 실제 복구 사고 시나리오.
$lockedR = array_filter($rest, fn($r) => $r['locked_until'] !== null);
$mustChg = array_filter($rest, fn($r) => (int) $r['must_change_password'] === 1);
printf("\n잠긴 계정: %d · 비밀번호 변경 강제 계정: %d · 실패시도>0: %d\n",
    count($lockedR), count($mustChg),
    count(array_filter($rest, fn($r) => (int) $r['failed_attempts'] > 0)));

// 권한 데이터
printf("\n권한 데이터:\n");
printf("  employee_permissions: 운영 %d / 복구본 %d\n", count($prodPerm), count($restPerm));
$permDiff = 0;
foreach ($prodPerm as $k => $p) {
    $r = $restPerm[$k] ?? null;
    if (!$r) { $permDiff++; $issues[] = "권한 누락 $k"; continue; }
    foreach (['can_read', 'can_write', 'can_delete'] as $f) {
        if ((string) $p[$f] !== (string) $r[$f]) { $permDiff++; $issues[] = "권한값 불일치 $k.$f"; }
    }
}
printf("  권한 불일치: %d건\n", $permDiff);
printf("  role_permissions: 운영 %d / 복구본 %d · 조합일치 %s\n",
    count($prodRolePerm), count($restRolePerm),
    ($prodRolePerm === $restRolePerm) ? '✅' : '❌');
if ($prodRolePerm !== $restRolePerm) $issues[] = 'role_permissions 조합 불일치';

printf("\n%s\n", $issues ? '❌ 문제 ' . count($issues) . '건: ' . implode(' / ', array_slice($issues, 0, 10))
                        : '✅ 인증·권한 자격증명 전 항목 무결 — 운영 비밀번호가 복구본에서 그대로 동작함이 보장됨');
exit($issues ? 1 : 0);
