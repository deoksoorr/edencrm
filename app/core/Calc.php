<?php
/**
 * 금액·원가·수익률 계산 단일화 (ARCHITECTURE 8절). 0 나눗셈은 null 반환.
 */
class Calc
{
    /** 예상/실제 순이익 = 매출 - 총원가 (음수 = 적자, 그대로 반환). */
    public static function profit(float $revenue, float $cost): float
    {
        return round($revenue - $cost, 0);
    }

    /** 순이익률(%) = 순이익 ÷ 매출 × 100. 매출 0 이하 → null. */
    public static function profitRate(float $revenue, float $cost): ?float
    {
        if ($revenue <= 0) {
            return null;
        }
        return round(($revenue - $cost) / $revenue * 100, 2);
    }

    /** 임의 비율 = value ÷ base × 100. base 0 이하 → null. */
    public static function rate(float $value, float $base): ?float
    {
        if ($base <= 0) {
            return null;
        }
        return round($value / $base * 100, 2);
    }

    /** 가중 예상 매출 = 계약금액 × 성공확률(%) ÷ 100. */
    public static function weightedRevenue(float $amount, float $probPct): float
    {
        return round($amount * $probPct / 100, 0);
    }

    /** 가중 예상 순이익 = 순이익 × 성공확률(%) ÷ 100. */
    public static function weightedProfit(float $profit, float $probPct): float
    {
        return round($profit * $probPct / 100, 0);
    }

    /** 직원 수익 기여액 = 프로젝트 순이익 × 기여도(%) ÷ 100. */
    public static function contribution(float $projectProfit, float $pct): float
    {
        return round($projectProfit * $pct / 100, 0);
    }

    /** 달성률(%) = 실제 ÷ 목표 × 100. 목표 0 이하 → null. */
    public static function achievement(float $actual, float $target): ?float
    {
        return self::rate($actual, $target);
    }
}
