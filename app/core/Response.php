<?php
/**
 * 응답 헬퍼. JSON API 는 {ok:bool, ...} 형식으로 통일.
 */
class Response
{
    public static function json($data = null, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $msg, int $status = 400, array $extra = []): never
    {
        // AJAX(X-Requested-With/Accept: json)는 JSON, 브라우저 폼 제출은 HTML 오류 페이지로 응답한다.
        // (네이티브 CRUD 폼의 서버측 검증 실패 시 원시 JSON 이 사용자에게 노출되던 문제 방지)
        if (!self::wantsJson()) {
            http_response_code($status);
            View::renderError($status, '요청을 처리할 수 없습니다', $msg);
            exit;
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** 라우트로 리다이렉트. $flash 는 세션 플래시 메시지. */
    public static function redirect(string $route, array $params = [], ?string $flash = null, string $flashType = 'success'): never
    {
        if ($flash !== null) {
            $_SESSION['flash'] = ['type' => $flashType, 'msg' => $flash];
        }
        header('Location: ' . Util::url($route, $params));
        exit;
    }

    /** 절대/상대 URL 로 직접 리다이렉트. */
    public static function to(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    /** 요청이 AJAX/JSON 기대인지. */
    public static function wantsJson(): bool
    {
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strcasecmp($xrw, 'XMLHttpRequest') === 0 || str_contains($accept, 'application/json');
    }
}
