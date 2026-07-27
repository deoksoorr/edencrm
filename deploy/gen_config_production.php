#!/usr/bin/env php
<?php
/**
 * deploy/cafe24.env → deploy/config.production.php 생성. (R6 T4)
 * - 배포 시 app/config/config.local.php 자리에 업로드되는 운영 설정을 만든다.
 * - 비밀값은 파일→파일로만 흐르며 화면·로그에 출력하지 않는다.
 * - 실행: php deploy/gen_config_production.php   (deploy.sh 가 자동 호출)
 */
$dir = __DIR__;
$envFile = $dir . '/cafe24.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "cafe24.env 없음: $envFile\n");
    exit(1);
}
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
    [$k, $v] = explode('=', $l, 2);
    $env[trim($k)] = trim($v);
}
foreach (['SERVICE_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'TBL_PREFIX'] as $k) {
    if (!isset($env[$k]) || $env[$k] === '') {
        fwrite(STDERR, "cafe24.env 필수 키 누락: $k\n");
        exit(1);
    }
}
$cfg = [
    'APP_ENV'    => 'production',   // config.php 가 display_errors=0·log_errors=1 강제
    'BASE_URL'   => rtrim($env['SERVICE_URL'], '/'),
    // 앱 런타임은 운영 서버 내부에서 접속하므로 localhost 사용(외부 DB_HOST 는 로컬 마이그레이션 전용).
    'DB_HOST'    => $env['APP_DB_HOST'] ?? 'localhost',
    'DB_PORT'    => (int) $env['DB_PORT'],
    'DB_SOCKET'  => '',
    'DB_NAME'    => $env['DB_NAME'],
    'DB_USER'    => $env['DB_USER'],
    'DB_PASS'    => $env['DB_PASSWORD'],
    'TBL_PREFIX' => $env['TBL_PREFIX'],
];
$out = "<?php\n"
    . "// EDEN CRM 운영(cafe24 <DB_ACCOUNT>) 설정 — 배포 시 app/config/config.local.php 로 업로드된다.\n"
    . "// 자동 생성(deploy/gen_config_production.php ← deploy/cafe24.env). 직접 수정·git 커밋 금지.\n"
    . "// APP_ENV=production → app/config/config.php 가 display_errors off·에러 로그 파일을 강제한다.\n"
    . "// 업로드 경로는 config.php 상수로 산출(ROOT_PATH/storage/uploads → /www/eden-crm/storage/uploads).\n"
    . "// storage/ 웹 접근은 루트 .htaccess + storage/.htaccess 가 차단, 다운로드는 PHP 스트리밍만 허용.\n"
    . 'return ' . var_export($cfg, true) . ";\n";
file_put_contents($dir . '/config.production.php', $out);
chmod($dir . '/config.production.php', 0600);
echo "config.production.php 생성 완료 (키: " . implode(', ', array_keys($cfg)) . ")\n";
