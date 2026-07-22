<?php
/**
 * 화면=DB 대사 QA (P5). 현실 시드 기준으로 AccountingService 산식이
 * 대시보드·성과·리포트에 표시될 값과 DB 원천이 일치함을 검증한다.
 * 실행: php scripts/reconcile_qa.php   (개발 MySQL 가동 필요)
 */
require __DIR__ . '/tests/bootstrap.php';
require __DIR__ . '/tests/lib.php';

$mf = date('Y-m-01');
$mt = date('Y-m-t');

echo "화면=DB 대사 (현실 시드 · " . date('Y-m') . ")\n";

// ── 재무/대시보드 핵심 ──
t_int('이번달 확정매출(준공·공급) = 29,000,000', 29000000, AccountingService::confirmedRevenue($mf, $mt));
t_int('이번달 확정순이익(공급−실제원가) = 4,500,000', 4500000, AccountingService::confirmedProfit($mf, $mt));
t_int('이번달 확정원가 = 24,500,000', 24500000, AccountingService::confirmedCost($mf, $mt));
t_int('이번달 수주액(계약일·취소제외 공급) = 56,000,000', 56000000, AccountingService::contractedAmount($mf, $mt));
t_int('예상매출(진행+미착공 공급) = 56,000,000', 56000000, AccountingService::expectedRevenue());
t_int('미수금(건별 GREATEST) = 46,523,575', 46523575, AccountingService::receivable());
t_int('가중 예상매출(파이프라인) = 112,950,000', 112950000, AccountingService::weightedPipeline());

// ── 직원 확정 기여(완료 프로젝트만·공급가) ──
$c2 = AccountingService::employeeConfirmedContribution(2);
$c3 = AccountingService::employeeConfirmedContribution(3);
$c4 = AccountingService::employeeConfirmedContribution(4);
t_int('차윤석(2) 확정기여 = 3,600,000', 3600000, $c2);
t_int('맹기현(3) 확정기여 = 900,000 (P2 +2,400,000 · P3 적자 −1,500,000)', 900000, $c3);
t_int('차우석(4) 확정기여 = 0 (완료 배정 없음)', 0, $c4);

// ── 무결성 항등식: 직원 기여 합 = 회사 확정순이익 (화면 합계 = 원천) ──
$company = AccountingService::companyConfirmedProfit();
t_int('회사 확정순이익 = 4,500,000', 4500000, $company);
t_int('직원 기여 합 = 회사 확정순이익', $company, $c2 + $c3 + $c4);

// ── 회사 순이익 기여율(합 100%) ──
t_float('차윤석 회사기여율 = 80%', 80.0, Calc::rate((float) $c2, (float) $company));
t_float('맹기현 회사기여율 = 20%', 20.0, Calc::rate((float) $c3, (float) $company));

// ── 목표 달성률(이상훈 5, 이번달 수주 56,000,000 / 목표 50,000,000 = 112%) ──
$rev5 = AccountingService::contractedAmount($mf, $mt, 5);
$tgt5 = (float) Db::val("SELECT COALESCE(target_revenue,0) FROM targets WHERE user_id=5 AND year=YEAR(CURDATE()) AND month=MONTH(CURDATE())");
t_float('이상훈 매출목표 달성률 = 112%', 112.0, AccountingService::achievement((float) $rev5, $tgt5));

// ── 미수금: 리스트 합계 = KPI(항등) ──
$listSum = (int) Db::val(
    "SELECT COALESCE(SUM(GREATEST(0, c.contract_amount - COALESCE((SELECT SUM(pm.amount) FROM payments pm WHERE pm.contract_id=c.id AND pm.status='paid'),0))),0)
     FROM contracts c WHERE c.deleted_at IS NULL AND c.status<>'terminated'"
);
t_int('미수금 리스트 합계 = KPI', AccountingService::receivable(), $listSum);

// ── 적자 프로젝트(한빛 P3) 음수 순이익 정상 표시 ──
$p3 = Db::one("SELECT supply_amount, actual_cost, status FROM projects WHERE project_no='P2026-0003'");
t_int('한빛(P3) 확정순이익 = −1,500,000 (적자)', -1500000, AccountingService::projectActualProfit($p3));

// ── 취소 프로젝트(P6) 확정매출/수주 제외 ──
$cancelledInRev = (int) Db::val("SELECT COUNT(*) FROM projects WHERE project_no='P2026-0006' AND status='cancelled'");
t_int('취소 프로젝트 존재(집계 제외 대상)', 1, $cancelledInRev);

exit(t_summary());
