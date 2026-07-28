<?php
/**
 * 역할 기반 접근 제어. super_admin 은 항상 허용.
 *
 * R16 이후 판정 원장은 employee_permissions(직원별 리소스 × 읽기·쓰기·삭제) 하나다.
 * 호출부(라우터 perm 옵션·뷰 can() 헬퍼·컨트롤러)는 그대로 두고 판정 소스만 교체했다.
 *  - super_admin            → 항상 true
 *  - ADMIN_ONLY perm        → super_admin 외 항상 false (정산·분석·관리·휴지통)
 *  - 레지스트리 미등록 키    → false (기본 거부. perm_key 오타도 거부로 수렴)
 *  - 그 외                  → Perm::can(uid, resource, action)
 *
 * role_permissions/user_permissions 는 카탈로그·마이그레이션 소스로만 남기고
 * 판정에는 더 이상 사용하지 않는다(역할이 아니라 직원별 권한이 진실).
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
        if (Perm::isSuperAdmin($user)) {
            return true;
        }
        if (Perm::isAdminOnly($perm)) {
            return false;                        // 최고운영자 전용 — 부여 수단 없음
        }
        $reg = Perm::registry();
        if (!isset($reg[$perm])) {
            return false;                        // 기본 거부
        }
        [$resource, $action] = $reg[$perm];
        return Perm::can((int) $user['id'], $resource, $action);
    }

    /** 현재 사용자의 유효 권한 집합(perm_key 목록). */
    public static function perms(): array
    {
        if (self::$permCache !== null) {
            return self::$permCache;
        }
        $user = Auth::user();
        if (!$user) {
            return self::$permCache = [];
        }
        if (Perm::isSuperAdmin($user)) {
            return self::$permCache = Db::run("SELECT perm_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
        }
        $eff = [];
        foreach (Perm::registry() as $permKey => [$resource, $action]) {
            if (Perm::can((int) $user['id'], $resource, $action)) {
                $eff[] = $permKey;
            }
        }
        return self::$permCache = $eff;
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
        Perm::reset();
    }
}
