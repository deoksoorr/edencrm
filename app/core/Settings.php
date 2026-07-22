<?php
/**
 * 시스템 설정/기능 플래그 조회. bootstrap 이 settings 테이블을 $GLOBALS['settings'] 로 로드한다.
 * 값은 문자열. 플래그는 '1'=사용 / '0'=사용 안 함.
 */
class Settings
{
    public static function get(string $key, $default = null)
    {
        $v = $GLOBALS['settings'][$key] ?? null;
        return $v === null ? $default : $v;
    }

    public static function enabled(string $key): bool
    {
        return (string) self::get($key, '0') === '1';
    }
}
