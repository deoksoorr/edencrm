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
