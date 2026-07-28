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
    // 주의: $col 은 SQL 문자열에 그대로 삽입(interpolate)되므로 반드시 하드코딩된 리터럴만 전달해야 하며, 호출자/사용자 입력값을 절대 전달해선 안 된다.
    private static function range(string $col, ?string $from, ?string $to, array &$p): string
    {
        $sql = '';
        if ($from !== null) { $sql .= " AND $col >= :from"; $p[':from'] = $from; }
        if ($to !== null)   { $sql .= " AND $col <= :to";   $p[':to'] = $to; }
        return $sql;
    }

    /** 입금 원장 공용 모집단(R11) — 계약 입금(유효 계약) + 예외 프로젝트 직접 입금(유효 프로젝트).
     *  별칭 고정: pm=payments, c=contracts(LEFT), pj=projects(LEFT). 행마다 contract_id/project_id 중 정확히 하나. */
    public const PAY_SOURCE_JOIN =
        " LEFT JOIN contracts c ON c.id = pm.contract_id
          LEFT JOIN projects pj ON pj.id = pm.project_id ";
    public const PAY_SOURCE_COND =
        "((pm.contract_id IS NOT NULL AND c.deleted_at IS NULL)
          OR (pm.project_id IS NOT NULL AND pj.deleted_at IS NULL))";

    /** 공급가 비율(VAT 제외 환산) = 1 ÷ (1 + 부가세율/100). 계약 supply/총액 비율이 없을 때의 폴백(예외 프로젝트 등). */
    public static function vatSupplyRatio(): float
    {
        return 1.0 / (1.0 + self::vatRate() / 100.0);
    }

    /** 입금 1건의 공급가액(VAT 제외) SQL 조각(R12) — 계약 연결분은 (순액 × supply/총액), 그 외(예외 등)는 순액 × :sr.
     *  곱셈을 먼저 수행해 DECIMAL 나눗셈 정밀도 손실(공급비율 반올림)을 피한다.
     *  별칭 pm=payments, c=contracts(LEFT). :sr 파라미터(vatSupplyRatio) 바인딩 필요. */
    private const PAY_SUPPLY_SQL =
        "(CASE WHEN pm.contract_id IS NOT NULL AND c.contract_amount > 0 AND c.supply_amount IS NOT NULL
               THEN (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END) * c.supply_amount / c.contract_amount
               ELSE (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END) * :sr END)";

    /** 확정 매출(공급가액·VAT 제외 — R12 사장 지시) = Σ순입금(paid, payment−refund)의 공급가 부분.
     *  입금 시점 인식(귀속 = paid_date), 계약 입금 + 예외 프로젝트 직접 입금 공통 산식.
     *  실제 입금된 금액만 반영·미입금 제외·환불/취소 차감. 부가세(예수금)는 매출이 아니므로 제외한다.
     *  현금 축(VAT 포함 입금 총액)은 paidTotal() — 두 값은 부가세만큼 다르다.
     *  대시보드·리포트·반기·목표 달성률이 전부 이 메서드 하나를 호출한다(중복 구현 금지). */
    public static function confirmedRevenue(?string $from = null, ?string $to = null): int
    {
        $p = [':sr' => self::vatSupplyRatio()];
        $r = self::range('pm.paid_date', $from, $to, $p);
        return (int) round((float) Db::val(
            "SELECT COALESCE(SUM(" . self::PAY_SUPPLY_SQL . "),0)
             FROM payments pm " . self::PAY_SOURCE_JOIN . "
             WHERE pm.status='paid' AND " . self::PAY_SOURCE_COND . " $r", $p));
    }

    /** 프로젝트 1건의 확정 매출(공급가액·VAT 제외, R12) = 순입금 × 공급가 비율.
     *  계약 연결은 계약 supply/총액 비율, 예외 프로젝트는 부가세율 환산(vatSupplyRatio).
     *  프로젝트 상세 손익·보너스 산정 대상 매출이 공유한다. */
    public static function projectConfirmedRevenue(array $p): int
    {
        if (!empty($p['contract_id'])) {
            $cid = (int) $p['contract_id'];
            $c = Db::one("SELECT contract_amount, supply_amount FROM contracts WHERE id = :id", [':id' => $cid]);
            $net = self::contractNetPaid($cid);
            $ratio = ($c && (int) $c['contract_amount'] > 0 && $c['supply_amount'] !== null)
                ? (float) $c['supply_amount'] / (float) $c['contract_amount']
                : self::vatSupplyRatio();
            return (int) round($net * $ratio);
        }
        // 예외 프로젝트(계약 미연결) — 직접 입금 순액을 부가세율로 공급가 환산
        return (int) round(self::projectNetPaid((int) ($p['id'] ?? 0)) * self::vatSupplyRatio());
    }

    /** 확정 순이익(R12) = 확정 매출(공급가액·VAT 제외) − 원가 총액(costs 확정 actual, spent_date 기준).
     *  완료 모집단(준공일 귀속) 산식 폐기 — 프로젝트 완료 여부만으로 손익을 확정하지 않는다. */
    public static function confirmedProfit(?string $from = null, ?string $to = null): int
    {
        return self::confirmedRevenue($from, $to) - self::costTotal($from, $to);
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

    /** 수주액 = 취소·파기 아닌 프로젝트 공급가액 합(계약일 기준). $uid 지정 시 담당 영업(sales_user_id) 범위로 축소. */
    public static function contractedAmount(?string $from = null, ?string $to = null, ?int $uid = null): int
    {
        $p = [];
        $r = self::range('contract_date', $from, $to, $p);
        $u = '';
        if ($uid !== null) { $u = ' AND sales_user_id = :u'; $p[':u'] = $uid; }
        return (int) Db::val("SELECT COALESCE(SUM(supply_amount),0) FROM projects
            WHERE deleted_at IS NULL AND status NOT IN ('cancelled','terminated') AND contract_date IS NOT NULL $r $u", $p);
    }

    /** 미수금 모집단 상태(현금 축) — 체결(active) 이후 계약만. (R3 acctverify)
     *  draft(작성중)는 체결 전 채권이 아니므로 제외(과대 계상 방지), terminated/cancelled 는 위약금·정산 별도 축.
     *  ReportsController::receivables 목록·화면 툴팁·알림 모집단(NotificationsController)이 이 정의를 따른다. */
    public const RECEIVABLE_STATUSES = ['active', 'on_hold', 'completed'];

    /** 미수금 대상 계약 공통 조건(현금 축) — RECEIVABLE_STATUSES 기반 SQL 조각(별칭 c 고정). */
    private const RECEIVABLE_CONTRACT_COND =
        "c.deleted_at IS NULL AND c.status IN ('active','on_hold','completed')";

    /** 계약별 순입금(payment−refund, status='paid') 상관 서브쿼리 SQL 조각(별칭 c 고정).
     *  컨트롤러 목록 쿼리도 이 조각을 재사용한다. 미수금 = 계약총액 − 정상입금 + 환불 = 계약총액 − 순입금 (브리프 §1). */
    public const PAID_SUM_SQL =
        "COALESCE((SELECT SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)
            FROM payments pm WHERE pm.contract_id=c.id AND pm.status='paid'),0)";

    /** 계약별 마지막 입금일 = 정상 입금(kind='payment', status='paid')의 paid_date 최댓값 —
     *  상관 서브쿼리 SQL 조각(별칭 c 고정, PAID_SUM_SQL 패턴 확장 · R4 T6).
     *  등록·수정일 사용 금지, 환불·pending·cancelled 제외. 입금이 없으면 NULL(화면 '입금 없음').
     *  계약 목록의 컬럼·정렬 4종·'최근 입금일' 기준 기간 필터가 이 조각 하나를 공유한다. */
    public const LAST_PAID_SQL =
        "(SELECT MAX(pm.paid_date) FROM payments pm
            WHERE pm.contract_id=c.id AND pm.status='paid' AND pm.kind='payment')";

    /** 계약 1건의 순입금(payment−refund, status='paid') — PAID_SUM_SQL 과 동일 산식의 단건 버전.
     *  결제상태 재계산·환불 상한 검증 등 계약 단위 로직이 공유한다. */
    public static function contractNetPaid(int $contractId): int
    {
        return (int) Db::val(
            "SELECT COALESCE(SUM(CASE WHEN kind='refund' THEN -amount ELSE amount END),0)
             FROM payments WHERE contract_id=:id AND status='paid'",
            [':id' => $contractId]
        );
    }

    /** 프로젝트별 직접 입금 순액 상관 서브쿼리 SQL 조각(별칭 p 고정, R11) — 예외 프로젝트 축. */
    public const PROJECT_PAID_SQL =
        "COALESCE((SELECT SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)
            FROM payments pm WHERE pm.project_id=p.id AND pm.status='paid'),0)";

    /** 프로젝트 1건의 직접 입금 순액(payment−refund, status='paid') — 예외 프로젝트 전용 축(R11).
     *  보너스 산정 base·정산 상태 재계산·환불 상한 검증이 공유한다. */
    public static function projectNetPaid(int $projectId): int
    {
        return (int) Db::val(
            "SELECT COALESCE(SUM(CASE WHEN kind='refund' THEN -amount ELSE amount END),0)
             FROM payments WHERE project_id=:id AND status='paid'",
            [':id' => $projectId]
        );
    }

    /** 입금 상태(파생, 저장 안 함 — R11) 라벨·배지. */
    public const PAY_STATUS_LABELS = ['none' => '미입금', 'partial' => '일부 입금', 'paid' => '완납', 'over' => '초과 입금'];
    public const PAY_STATUS_BADGE  = ['none' => 'badge-muted', 'partial' => 'badge-warn', 'paid' => 'badge-ok', 'over' => 'badge-info'];

    /** 입금 방식 화이트리스트(R11) — 계약 입금·프로젝트 직접 입금 폼 옵션·검증 공용(단일 출처). */
    public const PAYMENT_METHODS = ['transfer' => '계좌이체', 'cash' => '현금', 'card' => '카드', 'etc' => '기타'];

    /**
     * 프로젝트 입금 요약(R11) — 일반(연결 계약 축)/예외(프로젝트 직접 입금 축) 자동 분기.
     * 프로젝트 상세 '입금·정산' 탭·목록 표시·정산 완료 가드가 이 요약 하나를 공유한다.
     * @param array $p projects 행(id/contract_id/is_exception/expected_amount 필요)
     * @return array{expected:int, paid:int, refund:int, pendingCnt:int, outstanding:int, pay_status:string, expected_set:bool}
     *   expected=예정 금액(일반=계약 총액), paid=순입금, refund=환불 합, pendingCnt=대기(pending) 행 수,
     *   outstanding=미수금(max 0), pay_status=none/partial/paid/over(예정 미설정 시 paid>0 → partial).
     */
    public static function projectPaySummary(array $p): array
    {
        $pid = (int) ($p['id'] ?? 0);
        if (!empty($p['contract_id'])) {
            $cid = (int) $p['contract_id'];
            $expected = (int) Db::val("SELECT contract_amount FROM contracts WHERE id=:c AND deleted_at IS NULL", [':c' => $cid]);
            $paid = self::contractNetPaid($cid);
            $refund = (int) Db::val("SELECT COALESCE(SUM(amount),0) FROM payments
                WHERE contract_id=:c AND status='paid' AND kind='refund'", [':c' => $cid]);
            $pendingCnt = (int) Db::val("SELECT COUNT(*) FROM payments WHERE contract_id=:c AND status='pending'", [':c' => $cid]);
            $expectedSet = $expected > 0;
        } else {
            $expected = ((int) ($p['contract_amount'] ?? 0)) > 0
                ? (int) $p['contract_amount']
                : ($p['expected_amount'] !== null ? (int) $p['expected_amount'] : 0); // R14: 계약총액 우선, 레거시 fallback
            $paid = self::projectNetPaid($pid);
            $refund = (int) Db::val("SELECT COALESCE(SUM(amount),0) FROM payments
                WHERE project_id=:p AND status='paid' AND kind='refund'", [':p' => $pid]);
            $pendingCnt = (int) Db::val("SELECT COUNT(*) FROM payments WHERE project_id=:p AND status='pending'", [':p' => $pid]);
            $expectedSet = $expected > 0;
        }
        $outstanding = max(0, $expected - $paid);
        if ($expected > 0) {
            $payStatus = $paid <= 0 ? 'none' : ($paid < $expected ? 'partial' : ($paid === $expected ? 'paid' : 'over'));
        } else {
            $payStatus = $paid > 0 ? 'partial' : 'none';
        }
        return ['expected' => $expected, 'paid' => $paid, 'refund' => $refund, 'pendingCnt' => $pendingCnt,
            'outstanding' => $outstanding, 'pay_status' => $payStatus, 'expected_set' => $expectedSet];
    }

    /** 예외 프로젝트 미수금 모집단 조건(R11, 별칭 p 고정) — 계약총액(R14 우선)·레거시 예정 금액 입력된 유효 예외 프로젝트. */
    private const RECEIVABLE_EXCEPTION_COND =
        "p.deleted_at IS NULL AND p.is_exception = 1 AND COALESCE(NULLIF(p.contract_amount, 0), p.expected_amount, 0) > 0
         AND p.status NOT IN ('cancelled','terminated')";

    /** 미수금(현금 축) = Σ 계약별 max(0, 계약총액 − 순입금) + Σ 예외 프로젝트 max(0, 총액 − 직접 입금 순액)(R11, R14: 총액=계약총액 우선·레거시 fallback). */
    public static function receivable(): int
    {
        $contract = (int) Db::val("SELECT COALESCE(SUM(GREATEST(0,
                c.contract_amount - " . self::PAID_SUM_SQL . "
            )),0)
            FROM contracts c WHERE " . self::RECEIVABLE_CONTRACT_COND);
        $exception = (int) Db::val("SELECT COALESCE(SUM(GREATEST(0,
                COALESCE(NULLIF(p.contract_amount, 0), p.expected_amount, 0) - " . self::PROJECT_PAID_SQL . "
            )),0)
            FROM projects p WHERE " . self::RECEIVABLE_EXCEPTION_COND);
        return $contract + $exception;
    }

    /** 미수금 발생 건수(계약 + 예외 프로젝트) — receivable() 과 동일 모집단·동일 기준. */
    public static function receivableCount(): int
    {
        $contract = (int) Db::val("SELECT COUNT(*) FROM contracts c
            WHERE " . self::RECEIVABLE_CONTRACT_COND . "
              AND c.contract_amount > " . self::PAID_SUM_SQL);
        $exception = (int) Db::val("SELECT COUNT(*) FROM projects p
            WHERE " . self::RECEIVABLE_EXCEPTION_COND . "
              AND COALESCE(NULLIF(p.contract_amount, 0), p.expected_amount, 0) > " . self::PROJECT_PAID_SQL);
        return $contract + $exception;
    }

    /** 입금 총액(VAT 포함, 순입금·현금 축) = Σ payments(kind='payment') − Σ payments(kind='refund')
     *  — status='paid', 입금일(paid_date) 기준. 계약 + 예외 프로젝트 직접 입금 공통 모집단.
     *  R12: 확정 매출(공급가액·VAT 제외)과 분리 — 이 값은 실제 입금된 현금(부가세 포함) 그대로다. */
    public static function paidTotal(?string $from = null, ?string $to = null): int
    {
        $p = [];
        $r = self::range('pm.paid_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END),0)
            FROM payments pm " . self::PAY_SOURCE_JOIN . "
            WHERE pm.status='paid' AND " . self::PAY_SOURCE_COND . " $r", $p);
    }

    /** 환불 총액(VAT 포함, 별도 축) = Σ payments(kind='refund', status='paid') — 입금일(paid_date) 기준. R11 공통 모집단. */
    public static function refundTotal(?string $from = null, ?string $to = null): int
    {
        $p = [];
        $r = self::range('pm.paid_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(pm.amount),0) FROM payments pm " . self::PAY_SOURCE_JOIN . "
            WHERE pm.status='paid' AND pm.kind='refund' AND " . self::PAY_SOURCE_COND . " $r", $p);
    }

    /**
     * 계약(현금 축) 합계 — 계약관리 화면 요약용 배치 집계(전체 1쿼리, N+1 없음).
     * 기본 = 삭제 제외 전체 계약. $whereSql/$params 전달 시 해당 모집단으로 축소
     * (R4 T6: 계약 목록 합계 카드가 목록과 동일 WHERE 를 전달해 필터 연동 —
     *  R3 의 "합계 전체 기준 고정" 결정을 R4 사용자 지시가 대체).
     * 주의: $whereSql 은 하드코딩된 SQL 조각만 전달(사용자 입력 금지 — 값은 $params 바인딩).
     * 별칭: contracts c · customers cu (검색 필터의 cu.name 참조 지원).
     * 반환: count / contract(계약 총액 VAT 포함) / supply(공급가액) / vat(부가세) / paid(입금 총액 VAT 포함, 순입금)
     *      / refund(환불 총액 — 별도 축) / receivable(미수금 — 모집단 RECEIVABLE_STATUSES, Σ max(0, 계약총액−순입금)).
     */
    public static function contractTotals(string $whereSql = 'c.deleted_at IS NULL', array $params = []): array
    {
        $rows = Db::all("SELECT c.status, c.contract_amount, c.supply_amount, c.vat_amount,
                " . self::PAID_SUM_SQL . " AS paid,
                COALESCE((SELECT SUM(pm2.amount) FROM payments pm2
                    WHERE pm2.contract_id=c.id AND pm2.status='paid' AND pm2.kind='refund'),0) AS refund
            FROM contracts c JOIN customers cu ON cu.id = c.customer_id
            WHERE $whereSql", $params);
        $t = ['count' => 0, 'contract' => 0, 'supply' => 0, 'vat' => 0, 'paid' => 0, 'refund' => 0, 'receivable' => 0];
        foreach ($rows as $r) {
            $contract = (int) $r['contract_amount'];
            $supply = self::supplyOf($r);
            $t['count']++;
            $t['contract'] += $contract;
            $t['supply'] += $supply;
            $t['vat'] += $contract - $supply;
            $t['paid'] += (int) $r['paid'];
            $t['refund'] += (int) $r['refund'];
            if (in_array($r['status'], self::RECEIVABLE_STATUSES, true)) {
                $t['receivable'] += max(0, $contract - (int) $r['paid']);
            }
        }
        return $t;
    }

    /** 직원 확정 기여액(R11) = 직원 귀속 확정매출(입금×기여도) − 귀속 원가(확정 지출×기여도). */
    public static function employeeConfirmedContribution(int $uid, ?string $from = null, ?string $to = null): int
    {
        return self::employeeConfirmedRevenue($uid, $from, $to) - self::employeeCostShare($uid, $from, $to);
    }

    /** 직원 귀속 원가(R11) = Σ 확정 지출(costs actual·confirmed, spent_date 기준) × 기여도. */
    private static function employeeCostShare(int $uid, ?string $from = null, ?string $to = null): int
    {
        $p = [':u' => $uid];
        $r = self::range('cs.spent_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(cs.amount * pa.contribution_pct/100),0)
            FROM costs cs
            JOIN projects p ON p.id = cs.project_id AND p.deleted_at IS NULL
            JOIN project_assignments pa ON pa.project_id = p.id AND pa.user_id=:u AND pa.contribution_pct > 0
            WHERE cs.type='actual' AND cs.cost_status='confirmed' $r", $p);
    }

    /** 계약금액을 공급가/부가세로 분리. 견적 연결·total>0이면 견적 vat 비례, 아니면 ÷(1+rate). */
    public static function computeSplit(int $contractAmount, ?int $quoteId = null): array
    {
        $vat = null;
        if ($quoteId) {
            $row = Db::one("SELECT qv.vat, qv.total_amount FROM quotes q
                JOIN quote_versions qv ON qv.id = q.current_version_id WHERE q.id = :id", [':id' => $quoteId]);
            if ($row && (int) $row['total_amount'] > 0) {
                $vat = (int) round($contractAmount * (int) $row['vat'] / (int) $row['total_amount']);
            }
        }
        if ($vat === null) { $vat = self::deriveVat($contractAmount); }
        return ['supply' => $contractAmount - $vat, 'vat' => $vat];
    }

    /**
     * 분할 지급(계약금/중도금/잔금) 공통 산식 — 기준: 계약 총액(VAT 포함). (R3 브리프 §1)
     * 각 비율 0~100, 소수 2자리 반올림 후 합계가 정확히 100 이 아니면 InvalidArgumentException.
     * 계약금/중도금 = round(총액 × 비율 / 100), 잔금 = 총액 − 계약금 − 중도금
     * — 반올림 보정은 잔금 귀속(세 금액 합 = 계약 총액 정확히). JS(계약 폼)도 동일 규칙을 사용한다.
     * @return array{down:int, middle:int, balance:int}
     */
    public static function splitPayments(int $total, float $downPct, float $middlePct, float $balancePct): array
    {
        if ($total < 0) {
            throw new InvalidArgumentException('계약 총액(VAT 포함)은 0 이상이어야 합니다.');
        }
        $d = round($downPct, 2);
        $m = round($middlePct, 2);
        $b = round($balancePct, 2);
        foreach ([$d, $m, $b] as $p) {
            if ($p < 0 || $p > 100) {
                throw new InvalidArgumentException('분할 비율은 0~100 사이여야 합니다.');
            }
        }
        if (abs($d + $m + $b - 100.0) > 0.001) {
            throw new InvalidArgumentException('분할 비율 합계가 100%가 되어야 합니다. (현재 ' . rtrim(rtrim(number_format($d + $m + $b, 2), '0'), '.') . '%)');
        }
        $down   = (int) round($total * $d / 100);
        $middle = (int) round($total * $m / 100);
        return ['down' => $down, 'middle' => $middle, 'balance' => $total - $down - $middle];
    }

    /** 확정 원가(R11) = 원가 총액(costs 확정 actual, spent_date 기준) — costTotal 과 단일 산식.
     *  준공월 귀속(완료 프로젝트 actual_cost) 산식 폐기 — 순이익 = 확정 매출(입금) − 이 값. */
    public static function confirmedCost(?string $from = null, ?string $to = null): int
    {
        return self::costTotal($from, $to);
    }

    /** 프로젝트 귀속 입금 JOIN(R11, 별칭 고정: pm→pj2=귀속 프로젝트) — 계약 입금은 계약↔프로젝트(1:1),
     *  예외 입금은 project_id 직결. employeePaid/Confirmed 계열이 공유한다. */
    private const PAY_PROJECT_JOIN =
        " LEFT JOIN contracts c ON c.id = pm.contract_id AND c.deleted_at IS NULL
          JOIN projects pj2 ON pj2.deleted_at IS NULL
               AND (pj2.id = pm.project_id OR (pm.contract_id IS NOT NULL AND pj2.contract_id = pm.contract_id))
          ";

    /** 직원 귀속 확정매출(공급가액·VAT 제외, R12) = Σ 프로젝트 순입금의 공급가 부분 × 기여도 — 귀속 = paid_date. */
    public static function employeeConfirmedRevenue(int $uid, ?string $from = null, ?string $to = null): int
    {
        $p = [':u' => $uid, ':sr' => self::vatSupplyRatio()];
        $r = self::range('pm.paid_date', $from, $to, $p);
        return (int) round((float) Db::val(
            "SELECT COALESCE(SUM(" . self::PAY_SUPPLY_SQL . " * pa.contribution_pct/100),0)
            FROM payments pm " . self::PAY_PROJECT_JOIN . "
            JOIN project_assignments pa ON pa.project_id = pj2.id AND pa.user_id=:u AND pa.contribution_pct > 0
            WHERE pm.status='paid' AND (pm.contract_id IS NULL OR c.id IS NOT NULL) $r", $p));
    }

    /**
     * 직원별 확정 기여액·귀속매출 일괄 조회(R12) — employeeConfirmedContribution/Revenue 와
     * 동일 산식(공급가 매출×기여도 − 확정 지출×기여도)의 배치 버전(N+1 제거). 집계 0 인 직원은 키 부재(=0 취급).
     * revenue = Σ 프로젝트 순입금의 공급가 부분(VAT 제외) × 기여도(paid_date 귀속),
     * cost = Σ 확정 지출 × 기여도(spent_date 귀속), contrib = revenue − cost,
     * done = 기간 내 준공(완료·정산) 참여 프로젝트 수(참고 지표).
     * @return array<int, array{contrib:int, revenue:int, cost:int, done:int}>
     */
    public static function employeeConfirmedByUser(?string $from = null, ?string $to = null): array
    {
        $out = [];
        // 귀속 매출 = 공급가액(VAT 제외) × 기여도 — 현금(employeePaidByUser)과 분리(R12)
        $pr = [':sr' => self::vatSupplyRatio()];
        $rr = self::range('pm.paid_date', $from, $to, $pr);
        foreach (Db::all("SELECT pa.user_id AS uid,
                COALESCE(SUM(" . self::PAY_SUPPLY_SQL . " * pa.contribution_pct/100),0) AS revenue
            FROM payments pm " . self::PAY_PROJECT_JOIN . "
            JOIN project_assignments pa ON pa.project_id = pj2.id AND pa.contribution_pct > 0
            WHERE pm.status='paid' AND (pm.contract_id IS NULL OR c.id IS NOT NULL) $rr
            GROUP BY pa.user_id", $pr) as $row) {
            $uid = (int) $row['uid'];
            $out[$uid] = ['contrib' => (int) round((float) $row['revenue']), 'revenue' => (int) round((float) $row['revenue']), 'cost' => 0, 'done' => 0];
        }
        $p = [];
        $r = self::range('cs.spent_date', $from, $to, $p);
        foreach (Db::all("SELECT pa.user_id AS uid, COALESCE(SUM(cs.amount * pa.contribution_pct/100),0) AS cost
            FROM costs cs
            JOIN projects p ON p.id = cs.project_id AND p.deleted_at IS NULL
            JOIN project_assignments pa ON pa.project_id = p.id AND pa.contribution_pct > 0
            WHERE cs.type='actual' AND cs.cost_status='confirmed' $r
            GROUP BY pa.user_id", $p) as $row) {
            $uid = (int) $row['uid'];
            $out[$uid] = $out[$uid] ?? ['contrib' => 0, 'revenue' => 0, 'cost' => 0, 'done' => 0];
            $out[$uid]['cost'] = (int) $row['cost'];
            $out[$uid]['contrib'] = $out[$uid]['revenue'] - $out[$uid]['cost'];
        }
        $p2 = [];
        $r2 = self::range('p.actual_end_date', $from, $to, $p2);
        foreach (Db::all("SELECT pa.user_id AS uid, COUNT(DISTINCT p.id) AS done_cnt
            FROM project_assignments pa JOIN projects p ON p.id=pa.project_id
            WHERE p.deleted_at IS NULL AND p.status IN ('completed','settled') AND p.actual_end_date IS NOT NULL
              AND pa.contribution_pct > 0 $r2
            GROUP BY pa.user_id", $p2) as $row) {
            $uid = (int) $row['uid'];
            $out[$uid] = $out[$uid] ?? ['contrib' => 0, 'revenue' => 0, 'cost' => 0, 'done' => 0];
            $out[$uid]['done'] = (int) $row['done_cnt'];
        }
        return $out;
    }

    /**
     * 직원별 입금 기여(현금 축, VAT 포함) = Σ 프로젝트 순입금(paid, payment−refund) × 기여도.
     * R11: 계약 입금(계약→프로젝트 1:1)과 예외 프로젝트 직접 입금(project_id 직결)을 함께 귀속.
     * 기여율 0·미배정은 키 부재(=미반영, T9).
     * @return array<int, int> uid ⇒ 입금 기여액
     */
    public static function employeePaidByUser(?string $from = null, ?string $to = null): array
    {
        $p = [];
        $r = self::range('pm.paid_date', $from, $to, $p);
        $rows = Db::all("SELECT pa.user_id AS uid,
                COALESCE(SUM((CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)
                    * pa.contribution_pct/100),0) AS paid
            FROM payments pm " . self::PAY_PROJECT_JOIN . "
            JOIN project_assignments pa ON pa.project_id = pj2.id AND pa.contribution_pct > 0
            WHERE pm.status='paid' AND (pm.contract_id IS NULL OR c.id IS NOT NULL) $r
            GROUP BY pa.user_id", $p);
        $out = [];
        foreach ($rows as $row) { $out[(int) $row['uid']] = (int) $row['paid']; }
        return $out;
    }

    /**
     * 직원별 참여 프로젝트 수 = 기여율>0 배정 기준(취소·파기·삭제 제외) — T9 대시보드 직원 성과.
     * @return array<int, int> uid ⇒ 참여 프로젝트 수
     */
    public static function employeeProjectCountByUser(): array
    {
        $rows = Db::all("SELECT pa.user_id AS uid, COUNT(DISTINCT pa.project_id) AS c
            FROM project_assignments pa JOIN projects p ON p.id=pa.project_id
            WHERE p.deleted_at IS NULL AND p.status NOT IN ('cancelled','terminated')
              AND pa.contribution_pct > 0
            GROUP BY pa.user_id");
        $out = [];
        foreach ($rows as $row) { $out[(int) $row['uid']] = (int) $row['c']; }
        return $out;
    }

    /**
     * 담당 영업별 수주액 일괄 조회 — contractedAmount($from,$to,$uid) 와 동일 산식
     * (GROUP BY sales_user_id)의 배치 버전(T10 N+1 제거). 수주 0 인 직원은 키 부재(=0 취급).
     * @return array<int, int> uid ⇒ 수주 공급가액 합
     */
    public static function contractedAmountByUser(?string $from = null, ?string $to = null): array
    {
        $p = [];
        $r = self::range('contract_date', $from, $to, $p);
        $rows = Db::all("SELECT sales_user_id AS uid, COALESCE(SUM(supply_amount),0) AS v FROM projects
            WHERE deleted_at IS NULL AND status NOT IN ('cancelled','terminated') AND contract_date IS NOT NULL
              AND sales_user_id IS NOT NULL $r GROUP BY sales_user_id", $p);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['uid']] = (int) $row['v'];
        }
        return $out;
    }

    /**
     * 원가 총액(발생일 기준) = costs(type='actual', cost_status='confirmed') 의 지출일(spent_date) 기준 합.
     * 삭제 프로젝트 제외(실지출은 프로젝트 상태와 무관하게 집계 — 취소·파기 공사의 실비도 현금 유출).
     * 기간 지정 시 spent_date IS NULL 행은 제외된다(발생일 미상 — 기간 귀속 불가).
     * R3 대시보드 '이번 달 원가 총액' KPI 전용 신설 — 기존 confirmedCost(준공월 귀속)와 축이 다르다.
     */
    public static function costTotal(?string $from = null, ?string $to = null): int
    {
        $p = [];
        $r = self::range('cs.spent_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(cs.amount),0) FROM costs cs
            JOIN projects pj ON pj.id = cs.project_id AND pj.deleted_at IS NULL
            WHERE cs.type='actual' AND cs.cost_status='confirmed' $r", $p);
    }

    /** 계약 진행(status='active') 건수 — 삭제 제외. R3 대시보드 KPI. */
    public static function activeContractCount(): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM contracts c
            WHERE c.deleted_at IS NULL AND c.status='active'");
    }

    /**
     * 최근 입금 리스트(대시보드 T6) — paid 상태 정상 입금(kind='payment') 최근 $limit 건.
     * 환불(refund)·대기(pending)·취소(cancelled) 행 제외, 삭제 계약 제외.
     * 정렬: paid_date DESC → created_at DESC → id DESC (수정일 정렬 금지 — created_at 은 동률 보조키).
     * contract_refund = 해당 계약의 환불 합(paid refund) — 환불 발생 계약의 입금 행에 배지 병기용
     * (planner S3: 파기 환불은 costs 에 없어 '최근 출금(원가 지출)' 리스트에 안 나옴 — 여기 배지가 현금 유출을 드러낸다).
     * project_name = 계약 연결 프로젝트명(없으면 NULL — 화면은 계약번호 표시).
     */
    public static function recentPaidPayments(int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        // R11: 예외 프로젝트 직접 입금 포함 — 고객명은 계약 고객 → 프로젝트 고객 → 스냅샷 순 폴백
        return Db::all(
            "SELECT pm.id, pm.paid_date, pm.pay_type, pm.amount, pm.project_id,
                    c.id AS contract_id, c.contract_no, c.status AS contract_status,
                    COALESCE(cu.name, cu2.name, pj.customer_name_snapshot) AS customer_name,
                    COALESCE(u.name, u2.name) AS sales_user_name,
                    COALESCE(pj.name, (SELECT p2.name FROM projects p2
                        WHERE p2.contract_id=c.id AND p2.deleted_at IS NULL ORDER BY p2.id LIMIT 1)) AS project_name,
                    COALESCE((SELECT SUM(r.amount) FROM payments r
                        WHERE r.status='paid' AND r.kind='refund'
                          AND ((pm.contract_id IS NOT NULL AND r.contract_id = pm.contract_id)
                            OR (pm.project_id IS NOT NULL AND r.project_id = pm.project_id))),0) AS contract_refund
             FROM payments pm
             LEFT JOIN contracts c ON c.id = pm.contract_id
             LEFT JOIN projects pj ON pj.id = pm.project_id
             LEFT JOIN customers cu ON cu.id = c.customer_id
             LEFT JOIN customers cu2 ON cu2.id = pj.customer_id
             LEFT JOIN users u ON u.id = c.sales_user_id
             LEFT JOIN users u2 ON u2.id = pj.sales_user_id
             WHERE pm.status='paid' AND pm.kind='payment'
               AND ((pm.contract_id IS NOT NULL AND c.deleted_at IS NULL)
                 OR (pm.project_id IS NOT NULL AND pj.deleted_at IS NULL))
             ORDER BY pm.paid_date DESC, pm.created_at DESC, pm.id DESC
             LIMIT $limit"
        );
    }

    /** open 리드 가중 예상매출 합. $uid=null 전체. */
    public static function weightedPipeline(?int $uid = null): int
    {
        $scope = $uid !== null ? ' AND l.sales_user_id=:u' : '';
        $p = $uid !== null ? [':u' => $uid] : [];
        $sum = 0.0;
        foreach (Db::all("SELECT l.expected_amount, l.win_probability FROM leads l
            JOIN pipeline_stages ps ON ps.id=l.stage_id
            WHERE l.deleted_at IS NULL AND ps.is_won=0 AND ps.is_lost=0 $scope", $p) as $l) {
            $sum += Calc::weightedRevenue((float) ($l['expected_amount'] ?? 0), (float) ($l['win_probability'] ?? 0));
        }
        return (int) round($sum);
    }
}
