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
}
