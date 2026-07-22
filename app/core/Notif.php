<?php
/**
 * 인앱 알림 생성 헬퍼. 어떤 모듈이든 Notif::push() 로 알림을 만든다.
 */
class Notif
{
    /** 단일 사용자 알림. */
    public static function push(int $userId, string $type, string $title, string $message = '', string $linkRoute = '', array $linkParams = []): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            Db::insert('notifications', [
                'user_id'     => $userId,
                'type'        => $type,
                'title'       => $title,
                'message'     => $message,
                'link_route'  => $linkRoute ?: null,
                'link_params' => $linkParams ? json_encode($linkParams, JSON_UNESCAPED_UNICODE) : null,
                'is_read'     => 0,
            ]);
        } catch (\Throwable $e) {
            error_log('[notif] ' . $e->getMessage());
        }
    }

    /** 여러 사용자에게 동일 알림. */
    public static function pushMany(array $userIds, string $type, string $title, string $message = '', string $linkRoute = '', array $linkParams = []): void
    {
        foreach (array_unique(array_filter($userIds)) as $uid) {
            self::push((int) $uid, $type, $title, $message, $linkRoute, $linkParams);
        }
    }

    /** 특정 역할 전원에게. */
    public static function pushRole(string $roleKey, string $type, string $title, string $message = '', string $linkRoute = '', array $linkParams = []): void
    {
        $ids = Db::run(
            "SELECT id FROM users WHERE role_key = :rk AND status='active' AND deleted_at IS NULL",
            [':rk' => $roleKey]
        )->fetchAll(PDO::FETCH_COLUMN);
        self::pushMany($ids, $type, $title, $message, $linkRoute, $linkParams);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0", [':u' => $userId]);
    }
}
