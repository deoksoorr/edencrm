<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "AccountingService 손익 원자 (대사 A·B·D·E)\n";

// 테스트 A: 공급 100,000,000 · 실제원가 70,000,000 → 순이익 30,000,000 · 률 30%
$A = ['contract_amount' => 110000000, 'supply_amount' => 100000000, 'vat_amount' => 10000000, 'actual_cost' => 70000000];
t_int('A 확정 순이익', 30000000, AccountingService::projectActualProfit($A));
t_float('A 순이익률 30%', 30.0, AccountingService::projectActualProfitRate($A));

// 테스트 B: 공급 50,000,000 · 실제원가 60,000,000 → -10,000,000 · -20%
$B = ['contract_amount' => 55000000, 'supply_amount' => 50000000, 'actual_cost' => 60000000];
t_int('B 적자 순이익', -10000000, AccountingService::projectActualProfit($B));
t_float('B 순이익률 -20%', -20.0, AccountingService::projectActualProfitRate($B));

// 순이익률 분모 0 → null
$Z = ['contract_amount' => 0, 'supply_amount' => 0, 'actual_cost' => 0];
t_null('공급 0 → 률 null', AccountingService::projectActualProfitRate($Z));

// 테스트 D: 프로젝트 확정순이익 20,000,000, A70%/B30%
t_int('D A 기여 70%', 14000000, AccountingService::contribution(20000000, 70.0));
t_int('D B 기여 30%', 6000000, AccountingService::contribution(20000000, 30.0));
t_int('D 합=20,000,000', 20000000,
    AccountingService::contribution(20000000, 70.0) + AccountingService::contribution(20000000, 30.0));

// 테스트 E: 목표 미설정 → 달성률 null
t_null('E 목표 0 → null', AccountingService::achievement(5000000, 0.0));
t_null('E 목표 null → null', AccountingService::achievement(5000000, null));
t_float('달성률 정상', 50.0, AccountingService::achievement(5000000, 10000000));

exit(t_summary());
