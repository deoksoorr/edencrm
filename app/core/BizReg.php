<?php
/**
 * 사업자등록번호 검증 헬퍼 (R4 T2).
 * 형식(숫자 10자리) + 국세청 체크섬 검증. 표시 형식은 000-00-00000.
 *
 * 체크섬: d1..d10, 가중치 [1,3,7,1,3,7,1,3,5]
 *   sum = Σ(d_i × w_i) (i=1..9) + floor(d9×5 ÷ 10)
 *   check = (10 − sum%10) % 10 == d10
 */
class BizReg
{
    private const WEIGHTS = [1, 3, 7, 1, 3, 7, 1, 3, 5];

    /** 숫자만 추출 (하이픈·공백 제거). */
    public static function normalize(string $raw): string
    {
        return preg_replace('/\D/', '', $raw) ?? '';
    }

    /** 형식(10자리) + 국세청 체크섬 검증. 입력은 하이픈 포함 가능. */
    public static function isValid(string $raw): bool
    {
        $d = self::normalize($raw);
        if (strlen($d) !== 10 || !ctype_digit($d)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $d[$i]) * self::WEIGHTS[$i];
        }
        $sum += intdiv(((int) $d[8]) * 5, 10);
        return ((10 - ($sum % 10)) % 10) === (int) $d[9];
    }

    /** 000-00-00000 표시 형식. 10자리가 아니면 원문 반환. */
    public static function format(string $raw): string
    {
        $d = self::normalize($raw);
        if (strlen($d) !== 10) {
            return $raw;
        }
        return substr($d, 0, 3) . '-' . substr($d, 3, 2) . '-' . substr($d, 5);
    }
}
