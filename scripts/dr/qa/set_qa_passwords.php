<?php
/**
 * DR 테스트 — 복구본 한정 QA 비밀번호 설정.
 *
 * 왜 필요한가: 운영의 관리자 비밀번호는 배포 당시 임시값에서 이미 변경됐고
 * (deploy/ADMIN_CREDENTIALS.local.txt 는 무효), 아무도 현재 값을 보관하고 있지 않다.
 * 로그인 흐름·권한을 실제로 태워보려면 알고 있는 비밀번호가 필요하다.
 *
 * 무결성 논증은 이미 끝나 있다: verify_auth_material.php 가 운영과 복구본의
 * password_hash 가 바이트 단위로 동일함을 증명했다. 즉 "복구된 해시가 온전한가"는
 * 여기서 비밀번호를 바꾸는 것과 무관하게 이미 참이다. 여기서 바꾸는 것은
 * **복구본 사본**의 값뿐이며 운영은 일절 건드리지 않는다.
 *
 * 사용: php scripts/dr/qa/set_qa_passwords.php
 */

if (PHP_SAPI !== 'cli') { exit(1); }

$conf = require '/Users/deoksookim/Desktop/코드/claude code/eden_crm_restore_test/_dr/config.restore.php';

// 가드 — 복구 DB 가 아니면 절대 실행하지 않는다.
if (!str_ends_with($conf['DB_NAME'], '_restore_test')) {
    fwrite(STDERR, "가드 위반: 대상이 복구 DB 가 아님 ({$conf['DB_NAME']}) — 중단\n");
    exit(9);
}
if (($conf['DB_HOST'] ?? '') !== '127.0.0.1') {
    fwrite(STDERR, "가드 위반: 대상 호스트가 로컬이 아님 — 중단\n");
    exit(9);
}

$pdo = new PDO(
    "mysql:unix_socket={$conf['DB_SOCKET']};dbname={$conf['DB_NAME']};charset=utf8mb4",
    $conf['DB_USER'], $conf['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$P = $conf['TBL_PREFIX'];

// QA 대상: 역할별로 하나씩. 이 세 계정으로 권한 경계를 검증한다.
$targets = [
    'admin' => 'QArestore!2026admin',   // super_admin — 전권
    'test1' => 'QArestore!2026sales',   // sales_manager
    'hghg'  => 'QArestore!2026staff',   // staff — 최소 권한
];

// 변경 전 지문을 남겨 둔다(원래 해시가 무엇이었는지 되돌아볼 수 있게, 값은 미노출).
$before = [];
foreach ($targets as $lid => $_) {
    $r = $pdo->prepare("SELECT id, login_id, role_key, SHA2(password_hash,256) AS fp, must_change_password FROM `{$P}users` WHERE login_id = ?");
    $r->execute([$lid]);
    $before[$lid] = $r->fetch();
}

$pdo->beginTransaction();
foreach ($targets as $lid => $pw) {
    $hash = password_hash($pw, PASSWORD_BCRYPT);
    // must_change_password 도 해제한다 — 비밀번호 변경 강제 화면에 갇히면
    // 정작 검증하려는 권한·업무 화면에 도달하지 못한다.
    $st = $pdo->prepare("UPDATE `{$P}users` SET password_hash = ?, must_change_password = 0,
                                failed_attempts = 0, locked_until = NULL
                          WHERE login_id = ?");
    $st->execute([$hash, $lid]);
}
$pdo->commit();

echo "복구본 한정 QA 비밀번호 설정 완료 (운영 무변경)\n";
foreach ($targets as $lid => $pw) {
    $b = $before[$lid];
    printf("  %-8s id=%-3s role=%-14s 변경전 해시지문 %s… → QA 비밀번호 적용\n",
        $lid, $b['id'], $b['role_key'], substr($b['fp'], 0, 12));
}
echo "주의: 이 변경은 " . $conf['DB_NAME'] . " 에만 적용된다. 운영 DB 는 접속조차 하지 않았다.\n";
