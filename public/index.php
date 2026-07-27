<?php
/**
 * 프론트 컨트롤러 — 유일한 진입점. 요청: index.php?r=<route.key>
 * 라우터가 로그인·권한·CSRF·메서드를 일괄 강제한 뒤 컨트롤러 액션을 호출한다.
 */
declare(strict_types=1);

define('APP_CONFIG_FILE', __DIR__ . '/../app/config/config.php');
$config = require APP_CONFIG_FILE;      // 상수(APP_PATH 등) 정의 + 설정 배열 반환
$GLOBALS['config'] = $config;
require APP_PATH . '/bootstrap.php';    // 코어 로드·세션·설정 override

// 기술 스택 노출 최소화 — X-Powered-By(PHP 버전) 제거(핑거프린팅 축소). 호스트 expose_php 설정과 이중.
header_remove('X-Powered-By');

$routes = require APP_PATH . '/routes.php';

$routeKey = $_GET['r'] ?? 'home';
if (!isset($routes[$routeKey])) {
    // 루트 접근(?r 없음)은 홈으로
    if ($routeKey === 'home' && !isset($routes['home'])) {
        Auth::requireLogin();
    }
    http_response_code(404);
    View::renderError(404, '페이지를 찾을 수 없음', '요청하신 페이지가 존재하지 않습니다.');
    exit;
}

[$controllerName, $action] = $routes[$routeKey];
$opts = $routes[$routeKey];

// ── 1) 메서드 강제 ──
$method = $opts['method'] ?? 'GET';
if ($method === 'POST' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    if (Response::wantsJson()) {
        Response::error('허용되지 않은 요청 방식입니다.', 405);
    }
    View::renderError(405, '허용되지 않은 요청', 'POST 로만 접근할 수 있는 기능입니다.');
    exit;
}

// ── 2) 인증 (public 라우트 제외) ──
$isPublic = !empty($opts['public']);
if (!$isPublic) {
    Auth::requireLogin();

    // 유휴 자동 로그아웃
    if (Auth::checkIdle()) {
        if (Response::wantsJson()) {
            Response::error('세션이 만료되었습니다. 다시 로그인하세요.', 401);
        }
        Response::redirect('login', [], '장시간 미사용으로 자동 로그아웃되었습니다.', 'warning');
    }

    // 최초 로그인 비밀번호 변경 강제
    $u = Auth::user();
    if ($u && (int) $u['must_change_password'] === 1
        && !in_array($routeKey, ['password.change', 'password.update', 'logout'], true)) {
        Response::redirect('password.change', [], '최초 로그인 시 비밀번호를 변경해야 합니다.', 'warning');
    }
}

// ── 3) CSRF (POST 전부) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
}

// ── 4) 권한 강제 ──
if (!empty($opts['perm'])) {
    Rbac::require($opts['perm']);
}

// ── 4.5) 기능 플래그 게이트 ──
if (!empty($opts['feature']) && !Settings::enabled('feature_' . $opts['feature'])) {
    if (Response::wantsJson()) {
        Response::error('현재 비활성화된 기능입니다.', 404);
    }
    http_response_code(404);
    View::renderError(404, '비활성화된 기능', '이 기능은 현재 사용하도록 설정되어 있지 않습니다. 관리자에게 문의하세요.');
    exit;
}

// ── 5) 컨트롤러 실행 ──
try {
    load_controller($controllerName);
    $controller = new $controllerName();
    if (!method_exists($controller, $action)) {
        throw new RuntimeException("Action not found: {$controllerName}::{$action}");
    }
    $controller->$action();
} catch (\Throwable $e) {
    $isProd = ($GLOBALS['config']['APP_ENV'] ?? 'local') === 'production';
    error_log('[eden_crm] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (Response::wantsJson()) {
        Response::error($isProd ? '서버 오류가 발생했습니다.' : $e->getMessage(), 500);
    }
    $msg = $isProd ? '처리 중 오류가 발생했습니다. 잠시 후 다시 시도하세요.'
                   : $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine();
    View::renderError(500, '서버 오류', $msg);
}
