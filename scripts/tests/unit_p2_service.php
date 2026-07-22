<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
echo "AccountingService P2 확장\n";

// computeSplit: 견적1 연결(총액 37,462,250 = 공급 34,000,000 + vat 3,462,250)
$s = AccountingService::computeSplit(37462250, 1);
t_int('split(견적1) 공급', 34000000, $s['supply']);
t_int('split(견적1) 부가세', 3462250, $s['vat']);
t_int('split 정합', 37462250, $s['supply'] + $s['vat']);
// 견적 없음 → ÷1.1
$s2 = AccountingService::computeSplit(18500000, null);
t_int('split(무견적) 공급', 16818182, $s2['supply']);
t_int('split(무견적) 정합', 18500000, $s2['supply'] + $s2['vat']);

// 현실 시드: 완료 P2(이수아)+P3(한빛) 준공 2026-07 → 확정원가 24,500,000(14,000,000+10,500,000)
t_int('확정원가(seed)=24,500,000', 24500000, AccountingService::confirmedCost());
// 직원2(차윤석) 귀속매출: 완료 P2 공급 20,000,000 × 60% = 12,000,000
t_int('직원2 귀속매출(seed)=12,000,000', 12000000, AccountingService::employeeConfirmedRevenue(2));
// weightedPipeline: 리드 8건(open 6건) 가중합 = 112,950,000
t_int('가중 파이프라인(seed)=112,950,000', 112950000, AccountingService::weightedPipeline());

// boss 대시보드: 이번 달 확정매출/수주액/미수금(현실 시드)
$mf = date('Y-m-01'); $mt = date('Y-m-t');
// 확정매출(준공 2026-07): P2 공급 20,000,000 + P3 공급 9,000,000 = 29,000,000
t_int('이번달 확정매출(seed)=29,000,000', 29000000, AccountingService::confirmedRevenue($mf, $mt));
// 수주액(계약일 2026-07·취소제외 공급): P1 34,000,000 + P4 14,000,000 + P5 8,000,000 = 56,000,000
t_int('이번달 수주액(seed)=56,000,000', 56000000, AccountingService::contractedAmount($mf, $mt));
// 미수금(취소·해지 제외, 건별 GREATEST(0,...)): C1 26,223,575 + C3 4,900,000 + C4 15,400,000 = 46,523,575
t_int('미수금(seed)=46,523,575', 46523575, AccountingService::receivable());
// contractedAmount uid 스코프: 이번달 계약 프로젝트 sales_user_id=5(이상훈) → 직원5=56,000,000, 직원2=0
t_int('이번달 수주액(영업 5)=56,000,000', 56000000, AccountingService::contractedAmount($mf, $mt, 5));
t_int('이번달 수주액(직원2)=0', 0, AccountingService::contractedAmount($mf, $mt, 2));

exit(t_summary());
