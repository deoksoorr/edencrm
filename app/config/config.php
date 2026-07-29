<?php
/**
 * 공통 설정. 환경별 비밀값(DB 접속 등)은 config.local.php 에서 로드한다.
 * config.local.php 는 git 에 커밋하지 않는다 (config.local.example.php 참고).
 */

// ── 경로 상수 ──
define('APP_PATH', dirname(__DIR__));                 // .../app
define('ROOT_PATH', dirname(APP_PATH));               // 프로젝트 루트
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');
define('VIEW_PATH', APP_PATH . '/views');

// ── 로컬/운영 환경값 로드 ──
// EDEN_CONFIG_LOCAL 환경변수로 대체 로컬 설정 파일을 지정할 수 있다
// (스크래치 DB 등가 검증·병렬 QA 용 — 미지정 시 기존 경로 그대로).
$localFile = getenv('EDEN_CONFIG_LOCAL') ?: (__DIR__ . '/config.local.php');
if (!is_file($localFile)) {
    http_response_code(500);
    exit('설정 파일(config.local.php)이 없습니다. config.local.example.php 를 복사해 생성하세요.');
}
$local = require $localFile;

$config = array_merge([
    'APP_NAME'        => 'EDEN CRM',
    'APP_ENV'         => 'local',
    'BASE_URL'        => 'http://127.0.0.1:8080',
    'TIMEZONE'        => 'Asia/Seoul',
    'SESSION_NAME'    => 'eden_crm_sid',
    'SESSION_IDLE'    => 3600,        // 유휴 자동 로그아웃(초) — 설정값으로 덮임
    'LOGIN_MAX'       => 5,           // 로그인 연속 실패 허용(계정 기준)
    'LOCK_MINUTES'    => 15,          // 잠금 시간(분)
    // IP 기준 스로틀 — 계정 잠금만으로는 패스워드 스프레이(아이디를 바꿔가며 시도)와
    // 타 계정 잠금 유발(관리자 계정 DoS)을 막지 못한다.
    'LOGIN_IP_MAX'    => 20,          // 같은 IP 의 실패 허용 횟수
    'LOGIN_IP_WINDOW' => 600,         // 관측 구간(초)
    'LOGIN_IP_BLOCK'  => 600,         // 초과 시 차단 시간(초)
    'UPLOAD_MAX'      => 10 * 1024 * 1024,  // 업로드 최대 크기(바이트)
    'PAGE_SIZE'       => 20,
    // 운영 공유 DB 테이블 prefix(Db 레이어 SQL rewrite). '' 이면 rewrite 미작동(로컬 기본).
    'TBL_PREFIX'      => '',
    // DB 기본값(로컬이 덮음)
    'DB_HOST'   => '127.0.0.1',
    'DB_PORT'   => 3306,
    'DB_SOCKET' => '',
    'DB_NAME'   => 'eden_crm',
    'DB_USER'   => 'root',
    'DB_PASS'   => '',
], $local);

date_default_timezone_set($config['TIMEZONE']);

// ── 오류 표시: 로컬은 화면 표시, 운영은 로그 파일 ──
if (($config['APP_ENV'] ?? 'local') === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

return $config;
