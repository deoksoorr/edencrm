<?php
/**
 * 회계 집계 단일 출처. 손익=공급가액(VAT 제외), 현금=계약총액(VAT 포함).
 * 정책은 이 클래스가 싣고, 원자 산술은 Calc 을 재사용한다.
 * 스펙: docs/superpowers/specs/2026-07-22-eden-crm-accounting-and-ui-audit-design.md
 */
class AccountingService
{
    /** 부가세율(%) — 설정 vat_rate, 기본 10. */
    public static function vatRate(): float
    {
        $v = $GLOBALS['settings']['vat_rate'] ?? ($GLOBALS['config']['VAT_RATE'] ?? 10);
        return (float) $v;
    }

    /** 계약총액에서 부가세 파생 = contract − round(contract / (1 + rate/100)). */
    public static function deriveVat(int $contract): int
    {
        $rate = self::vatRate();
        return $contract - (int) round($contract / (1 + $rate / 100));
    }

    /** 공급가액(VAT 제외). 저장 supply_amount>0 우선 → vat_amount 있으면 contract−vat → 없으면 파생. */
    public static function supplyOf(array $row): int
    {
        $contract = (int) ($row['contract_amount'] ?? 0);
        if (isset($row['supply_amount']) && (int) $row['supply_amount'] > 0) {
            return (int) $row['supply_amount'];
        }
        if (isset($row['vat_amount']) && $row['vat_amount'] !== null) {
            return $contract - (int) $row['vat_amount'];
        }
        return $contract - self::deriveVat($contract);
    }

    /** 부가세 = 계약총액 − 공급가액. */
    public static function vatOf(array $row): int
    {
        return (int) ($row['contract_amount'] ?? 0) - self::supplyOf($row);
    }

    /** 확정 순이익 = 공급가액 − 실제원가 (음수=적자 그대로). */
    public static function projectActualProfit(array $p): int
    {
        return (int) Calc::profit(self::supplyOf($p), (float) ($p['actual_cost'] ?? 0));
    }

    /** 확정 순이익률(%) = (공급가액 − 실제원가) ÷ 공급가액 × 100. 공급 ≤0 → null. */
    public static function projectActualProfitRate(array $p): ?float
    {
        return Calc::profitRate((float) self::supplyOf($p), (float) ($p['actual_cost'] ?? 0));
    }

    /** 직원 기여액 = 프로젝트 순이익 × 기여도(%). */
    public static function contribution(int $profit, float $pct): int
    {
        return (int) Calc::contribution((float) $profit, $pct);
    }

    /** 달성률(%) = 실제 ÷ 목표 × 100. 목표 null/≤0 → null('목표 미설정'). */
    public static function achievement(?float $actual, ?float $target): ?float
    {
        if ($target === null || $target <= 0 || $actual === null) { return null; }
        return Calc::achievement($actual, $target);
    }
}
