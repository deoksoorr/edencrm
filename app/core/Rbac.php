<?php
/**
 * 역할 기반 접근 제어. super_admin 은 항상 허용.
 * 권한은 role_permissions + user_permissions(개별 grant/deny) 를 합성한다.
 */
class Rbac
{
    private static ?array $permCache = null;

    /** 현재 사용자가 권한 보유 여부. */
    public static function can(string $perm): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        if ($user['role'] === 'super_admin') {
            return true;
        }
        return in_array($perm, self::perms(), true);
    }

    /** 현재 사용자의 유효 권한 집합. */
    public static function perms(): array
    {
        if (self::$permCache !== null) {
            return self::$permCache;
        }
        $user = Auth::user();
        if (!$user) {
            return self::$permCache = [];
        }
        if ($user['role'] === 'super_admin') {
            // 전체 권한
            return self::$permCache = Db::run("SELECT perm_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
        }
        // 역할 권한
        $rolePerms = Db::run(
            "SELECT p.perm_key FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :rid",
            [':rid' => $user['role_id']]
        )->fetchAll(PDO::FETCH_COLUMN);

        // 사용자별 추가/제외
        $grants = [];
        $denies = [];
        $rows = Db::all(
            "SELECT p.perm_key, up.is_grant FROM user_permissions up
             JOIN permissions p ON p.id = up.permission_id
             WHERE up.user_id = :uid",
            [':uid' => $user['id']]
        );
        foreach ($rows as $r) {
            if ((int) $r['is_grant'] === 1) {
                $grants[] = $r['perm_key'];
            } else {
                $denies[] = $r['perm_key'];
            }
        }
        $eff = array_diff(array_unique(array_merge($rolePerms, $grants)), $denies);
        return self::$permCache = array_values($eff);
    }

    /** 권한 없으면 403 (페이지 또는 JSON). */
    public static function require(string $perm): void
    {
        if (self::can($perm)) {
            return;
        }
        Audit::log('access_denied', 'permission', null, null, ['perm' => $perm, 'route' => $_GET['r'] ?? '']);
        if (Response::wantsJson()) {
            Response::error('이 작업을 수행할 권한이 없습니다.', 403);
        }
        http_response_code(403);
        View::renderError(403, '접근 권한 없음', '이 페이지 또는 기능에 접근할 권한이 없습니다.');
        exit;
    }

    /** 역할 중 하나에 해당하는지. */
    public static function isRole(string ...$roleKeys): bool
    {
        $user = Auth::user();
        return $user && in_array($user['role'], $roleKeys, true);
    }

    public static function reset(): void
    {
        self::$permCache = null;
    }
}
