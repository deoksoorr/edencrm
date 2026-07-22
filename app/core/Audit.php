<?php
/**
 * 감사 로그. 중요 행동의 변경 전/후 값을 기록한다.
 */
class Audit
{
    public static function log(string $action, string $entity, ?int $entityId, ?array $before, ?array $after): void
    {
        try {
            Db::insert('audit_logs', [
                'user_id'     => Auth::check() ? Auth::id() : null,
                'action'      => $action,
                'entity'      => $entity,
                'entity_id'   => $entityId,
                'before_json' => $before !== null ? json_encode(self::mask($before), JSON_UNESCAPED_UNICODE) : null,
                'after_json'  => $after !== null ? json_encode(self::mask($after), JSON_UNESCAPED_UNICODE) : null,
                'ip'          => Util::clientIp(),
                'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (\Throwable $e) {
            // 감사 로그 실패가 기능을 막지 않도록 조용히 무시(운영에서는 파일 로그 권장)
            error_log('[audit] ' . $e->getMessage());
        }
    }

    /** 민감 필드는 로그에 남기지 않음. */
    private static function mask(array $data): array
    {
        foreach (['password', 'password_hash', 'new_password', '_csrf'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = '***';
            }
        }
        return $data;
    }
}
