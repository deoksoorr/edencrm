<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "DB 집계 (대사 C·F·G) — 트랜잭션 롤백\n";

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    // 고객 1(픽스처)
    $cid = Db::insert('customers', ['type' => 'company', 'name' => 'TEST대사', 'status' => 'active']);

    // ── 대사 C: 미수금 = 계약총액 100,000,000 − 입금 40,000,000 = 60,000,000 ──
    $recvBefore = AccountingService::receivable();
    $conId = Db::insert('contracts', [
        'contract_no' => 'TC-RECV', 'customer_id' => $cid, 'contract_amount' => 100000000,
        'supply_amount' => 90909091, 'vat_amount' => 9090909, 'status' => 'active', 'payment_status' => 'partial',
    ]);
    Db::insert('payments', ['contract_id' => $conId, 'pay_type' => 'down', 'amount' => 40000000, 'status' => 'paid']);
    Db::insert('payments', ['contract_id' => $conId, 'pay_type' => 'middle', 'amount' => 60000000, 'status' => 'pending']);
    t_int('C 미수금 증분 60,000,000', 60000000, AccountingService::receivable() - $recvBefore);

    // ── 대사 F: 확정매출(R11 입금 기준) — 프로젝트 완료 여부 무관, 입금(순액)만 인식(VAT 포함 현금 축).
    //    취소 계약이라도 미환불 입금은 현금으로 남아 있으므로 확정 매출에 포함(환불 시 차감). ──
    $revBefore = AccountingService::confirmedRevenue();
    $profitBefore = AccountingService::confirmedProfit();
    Db::insert('projects', ['project_no' => 'TP-DONE', 'customer_id' => $cid, 'name' => '완료', 'contract_amount' => 55000000,
        'supply_amount' => 50000000, 'vat_amount' => 5000000, 'actual_cost' => 30000000, 'status' => 'completed', 'actual_end_date' => date('Y-m-d')]);
    Db::insert('projects', ['project_no' => 'TP-CANCEL', 'customer_id' => $cid, 'name' => '취소', 'contract_amount' => 22000000,
        'supply_amount' => 20000000, 'vat_amount' => 2000000, 'actual_cost' => 0, 'status' => 'cancelled', 'actual_end_date' => date('Y-m-d')]);
    t_int('F 프로젝트 완료만으로는 확정매출 증분 0 (입금 없음)', 0, AccountingService::confirmedRevenue() - $revBefore);
    $fCon = Db::insert('contracts', ['contract_no' => 'TC-FDONE', 'customer_id' => $cid, 'contract_amount' => 55000000,
        'supply_amount' => 50000000, 'vat_amount' => 5000000, 'status' => 'active', 'payment_status' => 'paid']);
    Db::insert('payments', ['contract_id' => $fCon, 'pay_type' => 'down', 'amount' => 55000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    $fCanCon = Db::insert('contracts', ['contract_no' => 'TC-FCANCEL', 'customer_id' => $cid, 'contract_amount' => 22000000,
        'supply_amount' => 20000000, 'vat_amount' => 2000000, 'status' => 'cancelled', 'payment_status' => 'paid']);
    Db::insert('payments', ['contract_id' => $fCanCon, 'pay_type' => 'down', 'amount' => 22000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_int('F 확정매출 증분=입금 합 77,000,000 (55M 유효 + 22M 취소계약 미환불 입금)', 77000000,
        AccountingService::confirmedRevenue() - $revBefore);
    t_int('F 확정순이익 = 확정매출(입금) − 원가 총액 = +77,000,000 (지출 0)', 77000000,
        AccountingService::confirmedProfit() - $profitBefore);

    // ── 대사 G: 계약1·입금3·비용5·직원2 → 계약액 중복 합산 안 됨 ──
    $revG = AccountingService::confirmedRevenue();
    $gPid = Db::insert('projects', ['project_no' => 'TP-JOIN', 'customer_id' => $cid, 'name' => 'JOIN', 'contract_amount' => 110000000,
        'supply_amount' => 100000000, 'vat_amount' => 10000000, 'actual_cost' => 70000000, 'status' => 'completed', 'actual_end_date' => date('Y-m-d')]);

    // 미수금(3입금 상관 서브쿼리 경로): 계약액 110,000,000 − 입금 3건×10,000,000=30,000,000
    $recvGBefore = AccountingService::receivable();
    $gCon = Db::insert('contracts', ['contract_no' => 'TC-JOIN', 'customer_id' => $cid, 'contract_amount' => 110000000,
        'supply_amount' => 100000000, 'vat_amount' => 10000000, 'status' => 'completed', 'payment_status' => 'paid']);
    Db::update('projects', ['contract_id' => $gCon], 'id = :id', [':id' => $gPid]); // 실제 흐름과 동일한 계약↔프로젝트 연결(직원 귀속 경로)
    for ($i = 0; $i < 3; $i++) { Db::insert('payments', ['contract_id' => $gCon, 'pay_type' => 'etc', 'amount' => 10000000, 'status' => 'paid']); }
    t_int('G 미수금 +80,000,000 (3입금 중복없음)', 80000000, AccountingService::receivable() - $recvGBefore);

    for ($i = 0; $i < 5; $i++) { Db::insert('costs', ['project_id' => $gPid, 'type' => 'actual', 'category' => 'material', 'amount' => 14000000]); }
    t_int('G 일부 입금(30/110M) → 확정매출 증분 30,000,000 (R11 입금 기준 즉시 인식)', 30000000,
        AccountingService::confirmedRevenue() - $revG);
    // 잔액 80,000,000 입금 → 입금 4건 합 110,000,000 그대로 1회 합산(원가5건·직원2 중복 없음)
    Db::insert('payments', ['contract_id' => $gCon, 'pay_type' => 'etc', 'amount' => 80000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_int('G 완납 → 확정매출 증분=입금 총액 110,000,000(1회·중복 없음)', 110000000, AccountingService::confirmedRevenue() - $revG);

    // G 기여액(R11): 직원2 = 입금 110M×70% − 원가 70M×70% = 77M − 49M = 28,000,000
    //               직원3 = 33M − 21M = 12,000,000 (정확 델타)
    $c2Before = AccountingService::employeeConfirmedContribution(2);
    $c3Before = AccountingService::employeeConfirmedContribution(3);
    Db::insert('project_assignments', ['project_id' => $gPid, 'user_id' => 2, 'role' => '현장책임자', 'contribution_pct' => 70]);
    Db::insert('project_assignments', ['project_id' => $gPid, 'user_id' => 3, 'role' => '도장작업자', 'contribution_pct' => 30]);
    t_int('G 직원2 확정기여 정확히 +28,000,000 (입금 77M − 원가 49M)', 28000000,
        AccountingService::employeeConfirmedContribution(2) - $c2Before);
    t_int('G 직원3 확정기여 정확히 +12,000,000 (입금 33M − 원가 21M)', 12000000,
        AccountingService::employeeConfirmedContribution(3) - $c3Before);

    // ── 예상매출: preparing/in_progress 프로젝트 공급가액 합 ──
    $expBefore = AccountingService::expectedRevenue();
    Db::insert('projects', ['project_no' => 'TP-EXP', 'customer_id' => $cid, 'name' => '예상매출', 'contract_amount' => 11000000,
        'supply_amount' => 10000000, 'vat_amount' => 1000000, 'actual_cost' => 0, 'status' => 'preparing']);
    t_int('예상매출 +10,000,000', 10000000, AccountingService::expectedRevenue() - $expBefore);

    // ── 수주액: 취소 아닌 프로젝트 공급가액 합(계약일 기준) — TP-CANCEL 은 contract_date 없어 미포함 ──
    $ctrBefore = AccountingService::contractedAmount();
    Db::insert('projects', ['project_no' => 'TP-CTR', 'customer_id' => $cid, 'name' => '수주액', 'contract_amount' => 7700000,
        'supply_amount' => 7000000, 'vat_amount' => 700000, 'status' => 'preparing', 'contract_date' => date('Y-m-d')]);
    t_int('수주액 +7,000,000 (계약일 기준)', 7000000, AccountingService::contractedAmount() - $ctrBefore);

    // ══ 사용자 시나리오 A: 계약 총액(VAT 포함) 11,000,000 전액 입금 ══
    //   기대: 미수금 증분 0 · 입금 총액(VAT 포함) +11,000,000 · 공급/부가세 분리 10,000,000/1,000,000
    $recvABefore = AccountingService::receivable();
    $paidABefore = AccountingService::paidTotal();
    $totABefore  = AccountingService::contractTotals();
    $aCon = Db::insert('contracts', ['contract_no' => 'TC-FULL', 'customer_id' => $cid, 'contract_amount' => 11000000,
        'supply_amount' => 10000000, 'vat_amount' => 1000000, 'status' => 'active', 'payment_status' => 'paid']);
    Db::insert('payments', ['contract_id' => $aCon, 'pay_type' => 'down', 'amount' => 4000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::insert('payments', ['contract_id' => $aCon, 'pay_type' => 'balance', 'amount' => 7000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_int('A 전액입금 → 미수금 증분 0', 0, AccountingService::receivable() - $recvABefore);
    t_int('A 입금 총액(VAT 포함) +11,000,000', 11000000, AccountingService::paidTotal() - $paidABefore);
    $totA = AccountingService::contractTotals();
    t_int('A 계약 총액(VAT 포함) +11,000,000', 11000000, $totA['contract'] - $totABefore['contract']);
    t_int('A 공급가액(VAT 제외) +10,000,000', 10000000, $totA['supply'] - $totABefore['supply']);
    t_int('A 부가세 +1,000,000', 1000000, $totA['vat'] - $totABefore['vat']);

    // ══ 사용자 시나리오 B: 일부 입금(부분입금) — VAT 분리·미수금 확인 ══
    //   계약 총액 22,000,000(공급 20,000,000·부가세 2,000,000), paid 6,600,000 → 미수금 15,400,000
    //   pending 입금은 어떤 합계에도 포함되지 않아야 한다.
    $recvBBefore = AccountingService::receivable();
    $paidBBefore = AccountingService::paidTotal();
    $bCon = Db::insert('contracts', ['contract_no' => 'TC-PART', 'customer_id' => $cid, 'contract_amount' => 22000000,
        'supply_amount' => 20000000, 'vat_amount' => 2000000, 'status' => 'active', 'payment_status' => 'partial']);
    Db::insert('payments', ['contract_id' => $bCon, 'pay_type' => 'down', 'amount' => 6600000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::insert('payments', ['contract_id' => $bCon, 'pay_type' => 'balance', 'amount' => 15400000, 'status' => 'pending']);
    t_int('B 부분입금 → 미수금 +15,400,000', 15400000, AccountingService::receivable() - $recvBBefore);
    t_int('B 입금 총액 +6,600,000 (pending 제외)', 6600000, AccountingService::paidTotal() - $paidBBefore);

    // ══ settled(정산 완료) — R11: 상태만으로는 어떤 확정 축도 움직이지 않는다(입금·지출 없음 = 증분 0) ══
    $revSBefore = AccountingService::confirmedRevenue();
    $profitSBefore = AccountingService::confirmedProfit();
    Db::insert('projects', ['project_no' => 'TP-SETTLED', 'customer_id' => $cid, 'name' => '정산완료', 'contract_amount' => 33000000,
        'supply_amount' => 30000000, 'vat_amount' => 3000000, 'actual_cost' => 18000000, 'status' => 'settled', 'actual_end_date' => date('Y-m-d')]);
    t_int('settled 프로젝트만으로는 확정매출 증분 0 (R11 입금 기준)', 0, AccountingService::confirmedRevenue() - $revSBefore);
    t_int('settled 프로젝트만으로는 확정순이익 증분 0 (입금·지출 없음)', 0, AccountingService::confirmedProfit() - $profitSBefore);

    // ══ CostService::recalcProject — 원가 총액 = 확정(confirmed)·actual 만 합산 (브리프 §3) ══
    //   confirmed 자재 850,000 + confirmed 인건 500,000 = 1,350,000.
    //   cancelled/draft/estimate 행은 제외되어야 한다.
    $rPid = Db::insert('projects', ['project_no' => 'TP-COST', 'customer_id' => $cid, 'name' => '원가재계산', 'contract_amount' => 11000000,
        'supply_amount' => 10000000, 'vat_amount' => 1000000, 'actual_cost' => 0, 'status' => 'in_progress']);
    Db::insert('costs', ['project_id' => $rPid, 'type' => 'actual', 'cost_status' => 'confirmed', 'category' => 'material', 'qty' => 10, 'unit_price' => 85000, 'amount' => 850000]);
    Db::insert('costs', ['project_id' => $rPid, 'type' => 'actual', 'cost_status' => 'confirmed', 'category' => 'labor', 'worker_name' => '테스트공', 'work_days' => 2, 'unit_price' => 250000, 'amount' => 500000]);
    Db::insert('costs', ['project_id' => $rPid, 'type' => 'actual', 'cost_status' => 'cancelled', 'category' => 'material', 'amount' => 999999]);
    Db::insert('costs', ['project_id' => $rPid, 'type' => 'actual', 'cost_status' => 'draft', 'category' => 'etc', 'amount' => 111111]);
    Db::insert('costs', ['project_id' => $rPid, 'type' => 'estimate', 'cost_status' => 'confirmed', 'category' => 'material', 'amount' => 555555]);
    t_int('recalcProject = 확정 actual 합 1,350,000 (취소·임시·예상 제외)', 1350000, CostService::recalcProject($rPid));
    t_int('projects.actual_cost 캐시 = 1,350,000', 1350000, (int) Db::val('SELECT actual_cost FROM projects WHERE id=:id', [':id' => $rPid]));
    $rSub = CostService::subtotals($rPid);
    t_int('소계: 자재 850,000', 850000, $rSub['material']);
    t_int('소계: 인건 500,000', 500000, $rSub['labor']);
    t_int('소계: 기타 0 (draft 제외)', 0, $rSub['other']);
    t_int('확정 행 수 2 (미입력 0건과 0원 구분용)', 2, $rSub['entry_count']);
    // autoAmount: 자재 = 수량×단가, 인건 = 일수×일당(일수 우선, 없으면 시간×시급)
    t_int('autoAmount 자재 10×85,000', 850000, CostService::autoAmount('material', 10.0, 85000.0));
    t_int('autoAmount 인건 일수 우선 2×250,000', 500000, CostService::autoAmount('labor', null, 250000.0, 2.0, 16.0));
    t_int('autoAmount 인건 시급 16h×25,000', 400000, CostService::autoAmount('labor', null, 25000.0, null, 16.0));
    t_null('autoAmount 단가 없으면 null(수동 금액 필요)', CostService::autoAmount('material', 10.0, null));

    // ══ 사용자 시나리오 C: 환불(kind='refund') — 계약 총액 11,000,000 전액 입금 후 3,300,000 환불 ══
    $recvCBefore = AccountingService::receivable();
    $paidCBefore = AccountingService::paidTotal();
    $revCBefore  = AccountingService::confirmedRevenue();
    $cCon = Db::insert('contracts', ['contract_no' => 'TC-REFUND', 'customer_id' => $cid, 'contract_amount' => 11000000,
        'supply_amount' => 10000000, 'vat_amount' => 1000000, 'status' => 'active', 'payment_status' => 'paid']);
    Db::insert('payments', ['contract_id' => $cCon, 'pay_type' => 'down', 'amount' => 11000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_int('C 전액 입금 → 미수금 증분 0', 0, AccountingService::receivable() - $recvCBefore);
    $refCBefore = AccountingService::refundTotal();
    Db::insert('payments', ['contract_id' => $cCon, 'pay_type' => 'etc', 'kind' => 'refund', 'amount' => 3300000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_int('C 순입금 = 11,000,000 − 3,300,000 = +7,700,000', 7700000, AccountingService::paidTotal() - $paidCBefore);
    t_int('C 환불 후 미수금 +3,300,000 (환불분 재미수)', 3300000, AccountingService::receivable() - $recvCBefore);
    t_int('C 환불 총액(별도 축) +3,300,000', 3300000, AccountingService::refundTotal() - $refCBefore);
    t_int('C 확정 매출 증분 +7,700,000 (R11 입금 기준 — 환불 차감 후 순입금)', 7700000,
        AccountingService::confirmedRevenue() - $revCBefore);

    // ══ 사용자 시나리오 D: 계약 파기(terminated) — 계약 22,000,000, 입금 6,600,000 후 파기 ══
    $recvDBefore = AccountingService::receivable();
    $ctrDBefore  = AccountingService::contractedAmount();
    $expDBefore  = AccountingService::expectedRevenue();
    $revDBefore  = AccountingService::confirmedRevenue();
    $dCon = Db::insert('contracts', ['contract_no' => 'TC-TERM', 'customer_id' => $cid, 'contract_amount' => 22000000,
        'supply_amount' => 20000000, 'vat_amount' => 2000000, 'status' => 'terminated', 'payment_status' => 'partial']);
    Db::insert('payments', ['contract_id' => $dCon, 'pay_type' => 'down', 'amount' => 6600000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_int('D 파기 계약 → 일반 미수금 증분 0 (제외)', 0, AccountingService::receivable() - $recvDBefore);
    $dPid = Db::insert('projects', ['project_no' => 'TP-TERM', 'customer_id' => $cid, 'name' => '파기', 'contract_amount' => 22000000,
        'supply_amount' => 20000000, 'vat_amount' => 2000000, 'actual_cost' => 5000000, 'status' => 'terminated',
        'contract_date' => date('Y-m-d')]);
    t_int('D 파기 프로젝트 → 수주액 증분 0', 0, AccountingService::contractedAmount() - $ctrDBefore);
    t_int('D 파기 프로젝트 → 예상 매출 증분 0', 0, AccountingService::expectedRevenue() - $expDBefore);
    t_int('D 파기 계약 미환불 입금 6,600,000 → 확정 매출 유지(R11 현금 기준 — 환불 시에만 차감)', 6600000,
        AccountingService::confirmedRevenue() - $revDBefore);
    // 성과 제외: 파기 프로젝트 배정은 확정 기여에 포함되지 않는다
    $dC4Before = AccountingService::employeeConfirmedContribution(4);
    Db::insert('project_assignments', ['project_id' => $dPid, 'user_id' => 4, 'role' => '도장작업자', 'contribution_pct' => 100]);
    t_int('D 파기 프로젝트 배정 → 확정 기여 증분 0', 0, AccountingService::employeeConfirmedContribution(4) - $dC4Before);
    // 위약금·정산은 별도 축(contract_terminations) — 기록해도 확정 매출 불변
    Db::insert('contract_terminations', ['contract_id' => $dCon, 'terminated_date' => date('Y-m-d'),
        'reason' => '테스트 파기', 'refund_amount' => 0, 'penalty_amount' => 1000000, 'settlement_amount' => 500000]);
    t_int('D 위약금·정산 기록 후에도 확정 매출 불변(별도 축)', 6600000, AccountingService::confirmedRevenue() - $revDBefore);

} finally {
    $pdo->rollBack();
}
exit(t_summary());
