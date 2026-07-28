<?php
/**
 * CSRF 토큰. 세션당 1개 토큰을 발급하고 변경 요청마다 검증한다.
 */
class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** 폼에 넣을 hidden input. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }

    /**
     * POST 본문 _csrf 또는 X-CSRF-Token 헤더를 검증. 실패 시 403 종료.
     *
     * R16: 이전에는 419(Laravel 관례)를 썼으나 표준 상태코드가 아니라
     * 카페24 Apache/FastCGI 가 이를 500 으로 바꿔 내보냈다(운영 실측).
     * 요청 자체는 정상 차단되지만 사용자에게 서버 오류 페이지가 보였다.
     * 표준 403 으로 바꿔 안내 문구가 그대로 전달되게 한다(차단 동작은 동일).
     */
    public static function verify(): void
    {
        $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $real = $_SESSION['csrf_token'] ?? '';
        if ($real === '' || !is_string($sent) || !hash_equals($real, $sent)) {
            if (Response::wantsJson()) {
                Response::error('보안 토큰이 유효하지 않습니다. 새로고침 후 다시 시도하세요.', 403);
            }
            http_response_code(403);
            View::renderError(403, '보안 토큰 오류', '세션이 만료되었거나 보안 토큰이 유효하지 않습니다. 페이지를 새로고침한 뒤 다시 시도하세요.');
            exit;
        }
    }
}
