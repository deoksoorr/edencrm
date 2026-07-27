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

    /**
     * 금액 축약: 억/만 단위로 읽기 쉽게. 억은 소수1자리, 만은 정수(천단위 콤마).
     *   14,636,571,000 → "146.4억"  ·  60,500,000 → "6,050만"  ·  6,050,000 → "605만"  ·  8,900 → "8,900"
     * null → '-'. 음수는 부호 유지(적자 표시).
     */
    public static function moneyShort(?float $n): string
    {
        if ($n === null) {
            return '-';
        }
        $sign = $n < 0 ? '-' : '';
        $a = abs($n);
        if ($a >= 100000000) {                       // 억
            $v = $a / 100000000;
            // 정수 억이면 소수 제거, 아니면 1자리
            $str = ($v == floor($v)) ? number_format($v, 0) : number_format($v, 1);
            return $sign . $str . '억';
        }
        if ($a >= 10000) {                           // 만
            return $sign . number_format(round($a / 10000)) . '만';
        }
        return $sign . number_format($a);
    }

    /** 축약 금액 + 정확값 title 툴팁 HTML. 뷰에서 금액 셀에 사용. */
    public static function moneyCell(?float $n, string $suffix = '원'): string
    {
        if ($n === null) {
            return '<span class="mono">-</span>';
        }
        $full = number_format($n) . $suffix;
        return '<span class="mono" title="' . self::e($full) . '">' . self::e(self::moneyShort($n)) . $suffix . '</span>';
    }

    /** 부호 있는 퍼센트(증감 표기): +12.3% / -4.0% / 0%. null → '-' */
    public static function pctSigned(?float $n): string
    {
        if ($n === null) {
            return '-';
        }
        $s = $n > 0 ? '+' : '';
        return $s . number_format($n, 1) . '%';
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

    // ── 기간 필터 공통 (R4 T1 — 견적·계약·파이프라인 동일 프리셋=동일 날짜 경계) ──

    /** 기간 프리셋 정의(키 => 라벨). 파셜 partials/period_filter.php 와 컨트롤러가 공유. */
    public const PERIOD_PRESETS = [
        'today'      => '오늘',
        'this_week'  => '이번 주',
        'this_month' => '이번 달',
        'last_month' => '지난달',
        'last_3m'    => '최근 3개월',
        'this_year'  => '올해',
        'custom'     => '직접 지정',
    ];

    /**
     * 기간 프리셋 → 시작·종료일(YYYY-MM-DD) 계산 단일 출처.
     *  - 주 시작 = 월요일 (this_week = 해당 주 월~일).
     *  - last_3m = 당월 포함 최근 3개 캘린더 월 (전전월 1일 ~ 당월 말일).
     *  - custom  = $from/$to 검증 후 사용(잘못된 날짜는 null, from>to 이면 교환).
     *  - ''/알 수 없는 키 = ['from'=>null,'to'=>null] → 필터 미적용(전체).
     *  - 프리셋(비 custom) 선택 시 $from/$to 는 무시한다.
     * @param string|null $anchor 기준일(YYYY-MM-DD, 테스트용) — 생략 시 오늘.
     * @return array{from: ?string, to: ?string}
     */
    public static function periodRange(string $period, ?string $from = null, ?string $to = null, ?string $anchor = null): array
    {
        if ($period === 'custom') {
            $f = self::dateOrNull($from);
            $t = self::dateOrNull($to);
            if ($f !== null && $t !== null && $f > $t) {
                [$f, $t] = [$t, $f];
            }
            return ['from' => $f, 'to' => $t];
        }
        $base = self::dateOrNull($anchor) ?? date('Y-m-d');
        $d = new DateTimeImmutable($base);
        switch ($period) {
            case 'today':
                return ['from' => $base, 'to' => $base];
            case 'this_week': // 주 시작=월요일
                $mon = $d->modify('-' . ((int) $d->format('N') - 1) . ' days');
                return ['from' => $mon->format('Y-m-d'), 'to' => $mon->modify('+6 days')->format('Y-m-d')];
            case 'this_month':
                return ['from' => $d->format('Y-m-01'), 'to' => $d->format('Y-m-t')];
            case 'last_month':
                $p = $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1)->modify('-1 month');
                return ['from' => $p->format('Y-m-01'), 'to' => $p->format('Y-m-t')];
            case 'last_3m': // 당월 포함 3개 캘린더 월
                $s = $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1)->modify('-2 months');
                return ['from' => $s->format('Y-m-01'), 'to' => $d->format('Y-m-t')];
            case 'this_year':
                return ['from' => $d->format('Y-01-01'), 'to' => $d->format('Y-12-31')];
        }
        return ['from' => null, 'to' => null];
    }

    /**
     * 반기 범위(R8): 상반기 1/1-6/30, 하반기 7/1-12/31.
     * @return array{from: string, to: string}
     */
    public static function halfRange(int $year, int $half): array
    {
        return $half === 1
            ? ['from' => sprintf('%04d-01-01', $year), 'to' => sprintf('%04d-06-30', $year)]
            : ['from' => sprintf('%04d-07-01', $year), 'to' => sprintf('%04d-12-31', $year)];
    }

    /** 현재 날짜가 속한 반기. @return array{year: int, half: int} */
    public static function currentHalf(?string $anchor = null): array
    {
        $base = self::dateOrNull($anchor) ?? date('Y-m-d');
        return ['year' => (int) substr($base, 0, 4), 'half' => (int) substr($base, 5, 2) <= 6 ? 1 : 2];
    }

    /** 반기 라벨(예: 2026년 하반기). */
    public static function halfLabel(int $year, int $half): string
    {
        return $year . '년 ' . ($half === 1 ? '상반기' : '하반기');
    }

    /** 해당 반기가 이미 종료(마감)됐는지 — 마감 반기 데이터 수정 시 사유 필수 게이트. */
    public static function isHalfClosed(int $year, int $half, ?string $anchor = null): bool
    {
        $end = self::halfRange($year, $half)['to'];
        return (self::dateOrNull($anchor) ?? date('Y-m-d')) > $end;
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
function moneyShort(?float $n): string { return Util::moneyShort($n); }
function moneyCell(?float $n, string $suffix = '원'): string { return Util::moneyCell($n, $suffix); }
function pct(?float $n): string { return Util::pct($n); }
function pctSigned(?float $n): string { return Util::pctSigned($n); }
function url(string $route, array $params = []): string { return Util::url($route, $params); }
function fmtdate(?string $d, string $fmt = 'Y-m-d'): string { return Util::date($d, $fmt); }
