<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "AccountingService 금액 원자\n";

// vat_rate 기본 10 → 배수 1.1
t_float('vatRate 기본 10', 10.0, AccountingService::vatRate());

// 견적 연결(계약1): vat_amount 저장값 사용
$p1 = ['contract_amount' => 37462250, 'supply_amount' => 34000000, 'vat_amount' => 3462250];
t_int('공급가액(저장값)', 34000000, AccountingService::supplyOf($p1));
t_int('부가세(저장값)', 3462250, AccountingService::vatOf($p1));

// 미저장 → ÷1.1 파생 (프로젝트2)
$p2 = ['contract_amount' => 18500000];
t_int('부가세 파생(P2)', 1681818, AccountingService::vatOf($p2));
t_int('공급가액 파생(P2)', 16818182, AccountingService::supplyOf($p2));

// 파생 정합: supply + vat == contract
$s = AccountingService::supplyOf($p2); $v = AccountingService::vatOf($p2);
t_int('정합 supply+vat=contract', 18500000, $s + $v);

// vat_amount 만 저장(공급 미저장) → contract - vat
$p3 = ['contract_amount' => 9800000, 'vat_amount' => 890909];
t_int('공급가액(vat만 저장)', 8909091, AccountingService::supplyOf($p3));

exit(t_summary());
