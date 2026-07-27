<?php
/**
 * 스키마 백필 정합 — R6 T2 빈 시드 재기준.
 * 라이브 데이터 전건 정합(공급+부가세=총액) + 픽스처(트랜잭션 롤백)로
 * 정합 위반 감지 쿼리가 실제로 위반을 잡아내는지 확인한다.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "스키마 백필 정합 — 빈 시드 재기준\n";

$violC = "SELECT COUNT(*) FROM contracts WHERE deleted_at IS NULL AND (supply_amount IS NULL OR vat_amount IS NULL OR supply_amount+vat_amount<>contract_amount)";
$violP = "SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL AND (supply_amount IS NULL OR vat_amount IS NULL OR supply_amount+vat_amount<>contract_amount)";

// 라이브 데이터(빈 시드 포함) 전건 정합
t_int('계약 정합 위반 0', 0, (int) Db::val($violC));
t_int('프로젝트 정합 위반 0', 0, (int) Db::val($violP));

// 픽스처: 정합 행은 위반 미검출, 불일치 행은 검출되는지(감지 쿼리 자체 검증)
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $cid = Db::insert('customers', ['type' => 'company', 'name' => 'TEST-SCHEMA', 'status' => 'active']);
    Db::insert('contracts', ['contract_no' => 'TSC-OK', 'customer_id' => $cid, 'contract_amount' => 11000000,
        'supply_amount' => 10000000, 'vat_amount' => 1000000, 'status' => 'active', 'payment_status' => 'unpaid']);
    Db::insert('projects', ['project_no' => 'TSP-OK', 'customer_id' => $cid, 'name' => '정합', 'contract_amount' => 22000000,
        'supply_amount' => 20000000, 'vat_amount' => 2000000, 'actual_cost' => 0, 'status' => 'preparing']);
    t_int('정합 픽스처 → 계약 위반 여전히 0', 0, (int) Db::val($violC));
    t_int('정합 픽스처 → 프로젝트 위반 여전히 0', 0, (int) Db::val($violP));

    Db::insert('contracts', ['contract_no' => 'TSC-BAD', 'customer_id' => $cid, 'contract_amount' => 11000000,
        'supply_amount' => 10000000, 'vat_amount' => 999999, 'status' => 'active', 'payment_status' => 'unpaid']);
    t_int('불일치 픽스처(공급+부가세≠총액) → 위반 1건 검출', 1, (int) Db::val($violC));
} finally {
    $pdo->rollBack();
}

exit(t_summary());
