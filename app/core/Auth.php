<?php
/**
 * 세션 기반 인증. 로그인 실패 잠금, 세션 고정 방어, 유휴 자동 로그아웃 포함.
 */
class Auth
{
    private static ?array $cachedUser = null;

    /**
     * 계정 열거(타이밍) 방어용 더미 bcrypt 해시(cost 12).
     * 존재하지 않는 아이디로 로그인 시도해도 실제 계정과 동일하게 bcrypt 검증 1회를 수행해
     * 응답 시간 차이로 아이디 유효 여부가 노출되는 것을 막는다. 어떤 비밀번호와도 일치하지 않는다.
     */
    private const DUMMY_HASH = '$2y$12$/ltgJBlkJHeB4AnSeQEsquhqzzjsZjIMPZfz9w24tQiRUCuyK8uQS';

    /**
     * 로그인 시도. 성공 시 세션 설정 후 true.
     * 실패/잠금/비활성 사유는 $reason(참조)로 전달.
     */
    public static function attempt(string $loginId, string $password, ?string &$reason = null): bool
    {
        $loginId = trim($loginId);
        $ip = Util::clientIp();

        // ── IP 기준 스로틀 ──
        // 계정 잠금은 '한 계정을 노린 대입'만 막는다. 아이디를 바꿔가며 시도하는
        // 패스워드 스프레이와, 남의 계정을 일부러 잠가버리는 DoS 는 IP 기준으로 막아야 한다.
        if (self::ipThrottled($ip)) {
            $reason = '로그인 시도가 너무 많습니다. 잠시 후 다시 시도하세요.';
            self::recordAttempt($loginId, $ip, false);
            Audit::log('login_throttled', 'users', null, null, ['ip' => $ip]);
            return false;
        }

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

        // 계정 열거 타이밍 방어: 사용자 부재 시에도 동일하게 bcrypt 검증 1회를 수행한다.
        $verifyHash = ($user && !empty($user['password_hash'])) ? $user['password_hash'] : self::DUMMY_HASH;
        $passwordOk = password_verify($password, $verifyHash);

        $ok = $user
            && $user['status'] === 'active'
            && $passwordOk;

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

    /**
     * 같은 IP 의 최근 실패가 임계치를 넘었는지. login_attempts 는 이미 기록되고 있었으나
     * 아무 곳에서도 사용되지 않아 실질 방어가 없었다(감사에서 발견).
     * 성공한 로그인은 카운트하지 않으므로 정상 사용자는 영향을 받지 않는다.
     */
    private static function ipThrottled(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        $max    = (int) ($GLOBALS['config']['LOGIN_IP_MAX'] ?? 20);
        $window = (int) ($GLOBALS['config']['LOGIN_IP_WINDOW'] ?? 600);
        if ($max <= 0) {
            return false;                      // 0 이하면 비활성
        }
        try {
            $n = (int) Db::val(
                "SELECT COUNT(*) FROM login_attempts
                  WHERE ip = :ip AND success = 0
                    AND created_at > DATE_SUB(NOW(), INTERVAL :sec SECOND)",
                [':ip' => $ip, ':sec' => $window]
            );
            return $n >= $max;
        } catch (\Throwable $e) {
            // 스로틀 판정 실패가 로그인을 막지 않도록 한다(가용성 우선).
            error_log('[auth.throttle] ' . $e->getMessage());
            return false;
        }
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
