<?php
/**
 * 공통 유틸 + 입력 검증 헬퍼.
 */
class Util
{
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 금액: 12,345,678 (소수 없음, 원 단위). null → '-' */
    public static function money(?float $n): string
    {
        if ($n === null) {
            return '-';
        }
        return number_format($n, 0);
    }

    /** 퍼센트: 12.3% (소수 1자리). null → '-' */
    public static function pct(?float $n): string
    {
        if ($n === null) {
            return '-';
        }
        return number_format($n, 1) . '%';
    }

    /** 날짜 표기. null/빈값 → '-' */
    public static function date(?string $d, string $fmt = 'Y-m-d'): string
    {
        if (empty($d) || $d === '0000-00-00' || str_starts_with($d, '0000')) {
            return '-';
        }
        $ts = strtotime($d);
        return $ts ? date($fmt, $ts) : '-';
    }

    /** 페이지네이션 계산. */
    public static function paginate(int $total, int $page, int $per = 20): array
    {
        $per = max(1, $per);
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, $page), $pages);
        return [
            'total'  => $total,
            'per'    => $per,
            'page'   => $page,
            'pages'  => $pages,
            'offset' => ($page - 1) * $per,
            'from'   => $total ? ($page - 1) * $per + 1 : 0,
            'to'     => min($total, $page * $per),
        ];
    }

    // ── 입력 헬퍼 (GET+POST 통합) ──
    public static function input(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_string($v) ? trim($v) : $default;
    }

    public static function int(string $key, ?int $default = 0): ?int
    {
        $v = self::input($key, null);
        if ($v === null || $v === '') {
            return $default;
        }
        return (int) $v;
    }

    public static function float(string $key, ?float $default = 0.0): ?float
    {
        $v = self::input($key, null);
        if ($v === null || $v === '') {
            return $default;
        }
        // 콤마 제거 후 숫자화
        return (float) str_replace([','], '', (string) $v);
    }

    /** POST 전용 (변경 요청에서 GET 오염 방지) */
    public static function postStr(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public static function postInt(string $key, ?int $default = null): ?int
    {
        $v = $_POST[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        return (int) $v;
    }

    public static function postFloat(string $key, ?float $default = null): ?float
    {
        $v = $_POST[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        return (float) str_replace([','], '', (string) $v);
    }

    /**
     * 유효한 날짜(YYYY-MM-DD)면 정규화해 반환, 아니면 null.
     * 잘못된 사용자 입력이 DATE 컬럼에 들어가 SQL 오류로 500 나는 것을 방지한다.
     */
    public static function dateOrNull($v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') {
            return null;
        }
        $d = DateTime::createFromFormat('Y-m-d', substr($v, 0, 10));
        $errors = DateTime::getLastErrors();
        if ($d === false || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }
        return $d->format('Y-m-d');
    }

    /** 유효한 일시(YYYY-MM-DD HH:MM[:SS], 'T' 구분자 허용)면 정규화해 반환, 아니면 null. */
    public static function datetimeOrNull($v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') {
            return null;
        }
        $v = str_replace('T', ' ', $v);
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
            $d = DateTime::createFromFormat($fmt, $v);
            $errors = DateTime::getLastErrors();
            if ($d !== false && !($errors && ($errors['warning_count'] || $errors['error_count']))) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    /** null 또는 빈 문자열이면 null 반환 (DATE/nullable 컬럼용) */
    public static function nullIfEmpty($v)
    {
        if ($v === null) {
            return null;
        }
        $v = is_string($v) ? trim($v) : $v;
        return ($v === '' ) ? null : $v;
    }

    /** 라우트 URL 생성. */
    public static function url(string $route, array $params = []): string
    {
        $base = $GLOBALS['config']['BASE_URL'] ?? '';
        $qs = http_build_query(array_merge(['r' => $route], $params));
        return rtrim($base, '/') . '/index.php?' . $qs;
    }

    /** 클라이언트 IP. */
    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** 랜덤 파일 안전 이름. */
    public static function randomName(int $len = 20): string
    {
        return bin2hex(random_bytes($len / 2));
    }

    /** 문자열 자르기(표시용). */
    public static function truncate(?string $s, int $len = 40): string
    {
        $s = (string) $s;
        if (mb_strlen($s) <= $len) {
            return $s;
        }
        return mb_substr($s, 0, $len) . '…';
    }
}

// ── 전역 단축 함수 (뷰에서 사용) ──
function e(?string $s): string { return Util::e($s); }
function money(?float $n): string { return Util::money($n); }
function pct(?float $n): string { return Util::pct($n); }
function url(string $route, array $params = []): string { return Util::url($route, $params); }
function fmtdate(?string $d, string $fmt = 'Y-m-d'): string { return Util::date($d, $fmt); }
