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

    // ── 기간 WHERE 헬퍼 (기준일 컬럼 지정) ──
    private static function range(string $col, ?string $from, ?string $to, array &$p): string
    {
        $sql = '';
        if ($from !== null) { $sql .= " AND $col >= :from"; $p[':from'] = $from; }
        if ($to !== null)   { $sql .= " AND $col <= :to";   $p[':to'] = $to; }
        return $sql;
    }

    /** 확정 매출 = 완료 프로젝트 공급가액 합(준공일 기준). */
    public static function confirmedRevenue(?string $from = null, ?string $to = null): int
    {
        $p = [];
        $r = self::range('actual_end_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(supply_amount),0) FROM projects
            WHERE deleted_at IS NULL AND status='completed' AND actual_end_date IS NOT NULL $r", $p);
    }

    /** 확정 순이익 = 완료 프로젝트 (공급가액 − 실제원가) 합. */
    public static function confirmedProfit(?string $from = null, ?string $to = null): int
    {
        $p = [];
        $r = self::range('actual_end_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(supply_amount - actual_cost),0) FROM projects
            WHERE deleted_at IS NULL AND status='completed' AND actual_end_date IS NOT NULL $r", $p);
    }

    /** 회사 확정 순이익(기여율 분모) — confirmedProfit 별칭. */
    public static function companyConfirmedProfit(?string $from = null, ?string $to = null): int
    {
        return self::confirmedProfit($from, $to);
    }

    /** 예상 매출 = 미완료(preparing/in_progress) 프로젝트 공급가액 합. */
    public static function expectedRevenue(): int
    {
        return (int) Db::val("SELECT COALESCE(SUM(supply_amount),0) FROM projects
            WHERE deleted_at IS NULL AND status IN ('preparing','in_progress')");
    }

    /** 수주액 = 취소 아닌 프로젝트 공급가액 합(계약일 기준). */
    public static function contractedAmount(?string $from = null, ?string $to = null): int
    {
        $p = [];
        $r = self::range('contract_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(supply_amount),0) FROM projects
            WHERE deleted_at IS NULL AND status<>'cancelled' AND contract_date IS NOT NULL $r", $p);
    }

    /** 미수금(현금 축) = Σ 계약별 max(0, 계약총액 − 입금). terminated·삭제 제외. */
    public static function receivable(): int
    {
        return (int) Db::val("SELECT COALESCE(SUM(GREATEST(0,
                c.contract_amount - COALESCE((SELECT SUM(pm.amount) FROM payments pm WHERE pm.contract_id=c.id AND pm.status='paid'),0)
            )),0)
            FROM contracts c WHERE c.deleted_at IS NULL AND c.status<>'terminated'");
    }

    /** 직원 확정 기여액 = Σ 완료 프로젝트 (공급−실제원가) × 기여도. */
    public static function employeeConfirmedContribution(int $uid, ?string $from = null, ?string $to = null): int
    {
        $p = [':u' => $uid];
        $r = self::range('p.actual_end_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM((p.supply_amount - p.actual_cost) * pa.contribution_pct/100),0)
            FROM project_assignments pa JOIN projects p ON p.id=pa.project_id
            WHERE p.deleted_at IS NULL AND p.status='completed' AND p.actual_end_date IS NOT NULL
              AND pa.user_id=:u $r", $p);
    }
}
