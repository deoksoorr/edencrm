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

// seed: 완료 프로젝트 0 → 확정원가 0
t_int('확정원가(seed)=0', 0, AccountingService::confirmedCost());
// seed: 직원2 귀속매출(완료 없음)=0
t_int('직원2 귀속매출(seed)=0', 0, AccountingService::employeeConfirmedRevenue(2));
// weightedPipeline: seed 리드 없음 → 0 (leads 미시딩)
t_int('가중 파이프라인(seed)=0', 0, AccountingService::weightedPipeline());

// boss 대시보드: 이번 달 확정매출/수주액/미수금(seed)
$mf = date('Y-m-01'); $mt = date('Y-m-t');
t_int('이번달 확정매출(seed)=0', 0, AccountingService::confirmedRevenue($mf, $mt));
t_int('이번달 수주액(seed)=34,000,000', 34000000, AccountingService::contractedAmount($mf, $mt));
t_int('미수금(seed)=26,223,575', 26223575, AccountingService::receivable());
// contractedAmount uid 스코프: seed 프로젝트 sales_user_id=1(계약1 이번달) → 직원1=34,000,000, 직원2=0
t_int('이번달 수주액(직원1)=34,000,000', 34000000, AccountingService::contractedAmount($mf, $mt, 1));
t_int('이번달 수주액(직원2)=0', 0, AccountingService::contractedAmount($mf, $mt, 2));

exit(t_summary());
