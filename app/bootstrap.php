<?php
/**
 * 앱 부트스트랩: 설정 → 세션 → 코어 로드 → 헬퍼 준비.
 * 모든 진입점(public/index.php)은 이 파일을 가장 먼저 require 한다.
 */

// $config, $GLOBALS['config'] 는 진입점(index.php)에서 이미 설정됨
$config = $GLOBALS['config'];

// ── 코어 클래스 로드 ──
foreach ([
    'Util', 'Db', 'Response', 'Csrf', 'Audit', 'Auth', 'Rbac', 'View', 'Calc', 'Upload', 'Nav', 'Notif', 'Scope', 'Stages', 'AccountingService',
] as $cls) {
    require APP_PATH . '/core/' . $cls . '.php';
}

// ── 세션 시작 ──
session_name($config['SESSION_NAME']);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => ($config['APP_ENV'] ?? 'local') === 'production',
]);
session_start();

// ── DB 설정값을 config 에 반영(설정 테이블 override) ──
try {
    $rows = Db::all("SELECT setting_key, value FROM settings");
    $map = [];
    foreach ($rows as $r) {
        $map[$r['setting_key']] = $r['value'];
    }
    if (isset($map['session_idle_min'])) $GLOBALS['config']['SESSION_IDLE'] = (int) $map['session_idle_min'] * 60;
    if (isset($map['login_max_attempts'])) $GLOBALS['config']['LOGIN_MAX'] = (int) $map['login_max_attempts'];
    if (isset($map['lock_minutes'])) $GLOBALS['config']['LOCK_MINUTES'] = (int) $map['lock_minutes'];
    if (isset($map['page_size'])) $GLOBALS['config']['PAGE_SIZE'] = (int) $map['page_size'];
    if (isset($map['app_name'])) $GLOBALS['config']['APP_NAME'] = $map['app_name'];
    if (isset($map['upload_max_size_mb'])) $GLOBALS['config']['UPLOAD_MAX'] = (int) $map['upload_max_size_mb'] * 1048576;
    if (isset($map['vat_rate'])) $GLOBALS['config']['VAT_RATE'] = (float) $map['vat_rate'];
    $GLOBALS['settings'] = $map;
} catch (\Throwable $e) {
    // 설치 전이면 settings 테이블이 없을 수 있음 — 무시하고 기본값 사용
    $GLOBALS['settings'] = [];
}

/** 컨트롤러 로더. */
function load_controller(string $name): string
{
    $file = APP_PATH . '/controllers/' . $name . '.php';
    if (!is_file($file)) {
        throw new RuntimeException("Controller not found: $name");
    }
    require_once $file;
    return $name;
}

/** 모델 로더(요청 시). */
function model(string $name): void
{
    $file = APP_PATH . '/models/' . $name . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}

/** 현재 사용자 권한 헬퍼(뷰에서 사용). */
function can(string $perm): bool { return Rbac::can($perm); }
function is_role(string ...$roles): bool { return Rbac::isRole(...$roles); }
function auth_user(): ?array { return Auth::user(); }
function csrf_field(): string { return Csrf::field(); }
function setting(string $key, $default = null) { return $GLOBALS['settings'][$key] ?? $default; }
