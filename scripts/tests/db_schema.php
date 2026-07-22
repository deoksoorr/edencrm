<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "스키마 백필 정합\n";

// 모든 계약: supply + vat = contract, NULL 없음
$badC = (int) Db::val("SELECT COUNT(*) FROM contracts WHERE deleted_at IS NULL AND (supply_amount IS NULL OR vat_amount IS NULL OR supply_amount+vat_amount<>contract_amount)");
t_int('계약 정합 위반 0', 0, $badC);
$badP = (int) Db::val("SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL AND (supply_amount IS NULL OR vat_amount IS NULL OR supply_amount+vat_amount<>contract_amount)");
t_int('프로젝트 정합 위반 0', 0, $badP);

// 시드 계약1: supply 34,000,000 / vat 3,462,250
$c1 = Db::one("SELECT supply_amount, vat_amount FROM contracts WHERE contract_no='C2026-0001'");
t_int('계약1 공급', 34000000, $c1['supply_amount']);
t_int('계약1 부가세', 3462250, $c1['vat_amount']);

// 시드 프로젝트2 이수아 방수(완료, 계약 22,000,000 → 공급 20,000,000 / 부가세 2,000,000)
$p2 = Db::one("SELECT supply_amount, vat_amount FROM projects WHERE project_no='P2026-0002'");
t_int('프로젝트2 공급', 20000000, $p2['supply_amount']);
t_int('프로젝트2 부가세', 2000000, $p2['vat_amount']);

exit(t_summary());
