<?php
/**
 * 세션 기반 인증. 로그인 실패 잠금, 세션 고정 방어, 유휴 자동 로그아웃 포함.
 */
class Auth
{
    private static ?array $cachedUser = null;

    /**
     * 로그인 시도. 성공 시 세션 설정 후 true.
     * 실패/잠금/비활성 사유는 $reason(참조)로 전달.
     */
    public static function attempt(string $loginId, string $password, ?string &$reason = null): bool
    {
        $loginId = trim($loginId);
        $ip = Util::clientIp();

        // 잠금 여부 확인 (계정 기준)
        $user = Db::one(
            "SELECT * FROM users WHERE login_id = :lid AND deleted_at IS NULL LIMIT 1",
            [':lid' => $loginId]
        );

        // 잠금 상태
        if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $reason = '로그인 시도가 많아 계정이 잠겼습니다. 잠시 후 다시 시도하세요.';
            self::recordAttempt($loginId, $ip, false);
            return false;
        }

        $ok = $user
            && $user['status'] === 'active'
            && password_verify($password, $user['password_hash']);

        if (!$ok) {
            self::recordAttempt($loginId, $ip, false);
            // 실패 횟수 증가 및 잠금
            if ($user) {
                $fails = (int) $user['failed_attempts'] + 1;
                $max = (int) ($GLOBALS['config']['LOGIN_MAX'] ?? 5);
                $data = ['failed_attempts' => $fails];
                if ($fails >= $max) {
                    $lockMin = (int) ($GLOBALS['config']['LOCK_MINUTES'] ?? 15);
                    $data['locked_until'] = date('Y-m-d H:i:s', time() + $lockMin * 60);
                    $data['failed_attempts'] = 0;
                    $reason = "로그인 {$max}회 실패로 계정이 {$lockMin}분간 잠깁니다.";
                }
                Db::update('users', $data, 'id = :id', [':id' => $user['id']]);
            }
            if ($user && $user['status'] !== 'active') {
                $reason = '비활성화된 계정입니다. 관리자에게 문의하세요.';
            }
            $reason = $reason ?? '아이디 또는 비밀번호가 올바르지 않습니다.';
            Audit::log('login_failed', 'users', $user['id'] ?? null, null, ['login_id' => $loginId]);
            return false;
        }

        // 성공 — 세션 고정 방어
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['role_key'] = $user['role_key'];
        $_SESSION['last_activity'] = time();
        $_SESSION['login_time'] = time();

        Db::update('users', [
            'last_login_at'   => date('Y-m-d H:i:s'),
            'last_login_ip'   => $ip,
            'failed_attempts' => 0,
            'locked_until'    => null,
        ], 'id = :id', [':id' => $user['id']]);

        self::recordAttempt($loginId, $ip, true);
        self::$cachedUser = null;
        Audit::log('login', 'users', (int) $user['id'], null, null);
        return true;
    }

    private static function recordAttempt(string $loginId, string $ip, bool $success): void
    {
        try {
            Db::insert('login_attempts', [
                'login_id' => $loginId,
                'ip'       => $ip,
                'success'  => $success ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            // 로그 기록 실패는 인증을 막지 않음
        }
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    public static function id(): int
    {
        return (int) ($_SESSION['uid'] ?? 0);
    }

    /** 현재 사용자 행(users + role_key). 매 요청 상태/soft-delete 재검증. */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        $u = Db::one(
            "SELECT u.*, r.role_key AS role FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1",
            [':id' => self::id()]
        );
        // 비활성/삭제 계정은 즉시 로그아웃
        if (!$u || $u['status'] !== 'active') {
            self::logout();
            return null;
        }
        self::$cachedUser = $u;
        return $u;
    }

    public static function roleKey(): ?string
    {
        return $_SESSION['role_key'] ?? null;
    }

    public static function logout(): void
    {
        $uid = self::id();
        if ($uid) {
            Audit::log('logout', 'users', $uid, null, null);
        }
        self::$cachedUser = null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /**
     * 유휴 시간 초과 검사. 초과 시 로그아웃하고 true 반환.
     */
    public static function checkIdle(): bool
    {
        if (!self::check()) {
            return false;
        }
        $idle = (int) ($GLOBALS['config']['SESSION_IDLE'] ?? 3600);
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        if ($last && (time() - $last) > $idle) {
            self::logout();
            return true;
        }
        $_SESSION['last_activity'] = time();
        return false;
    }

    /** 미로그인 시 로그인 페이지(또는 401 JSON)로. */
    public static function requireLogin(): void
    {
        if (self::check() && self::user()) {
            return;
        }
        if (Response::wantsJson()) {
            Response::error('로그인이 필요합니다.', 401);
        }
        Response::redirect('login');
    }
}
