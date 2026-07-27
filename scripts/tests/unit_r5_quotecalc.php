<?php
/**
 * R5 T2 — 견적 항목 합산 산식 단위 테스트.
 * 확정 산식: 항목 금액(amount) = 기본금액(면적>0 이면 면적×수량×단가, 아니면 수량×단가)
 *                                + 재료비+인건비+장비비+외주비+기타비.
 * 공급가액(subtotal) = Σamount, VAT = round(subtotal×율), 총액 = subtotal+VAT−할인 (기존 파생 규칙 유지).
 * QuotesController::parseItems / computeTotals 를 Reflection 으로 직접 검증(서버가 최종 권위).
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/controllers/QuotesController.php';

$qc = new ReflectionClass('QuotesController');
$ctrl = $qc->newInstanceWithoutConstructor();
$mParse = $qc->getMethod('parseItems');
$mTotals = $qc->getMethod('computeTotals');

$parse = fn(array $raw) => $mParse->invoke($ctrl, $raw);
$totals = fn(array $items, float $disc, float $rate) => $mTotals->invoke($ctrl, $items, $disc, $rate);

echo "R5 견적 합산 산식 (parseItems / computeTotals)\n";

// ── 1. 단가만 (cost 전부 0): amount = qty×unit_price ──
$r = $parse([[ 'name' => '외벽 도장', 'qty' => '2', 'unit_price' => '1000' ]]);
t_int('단가만: 2×1,000 = 2,000', 2000, (int) $r[0]['amount']);

// ── 2. cost 만 (단가 0): amount = 재료+인건+장비+외주+기타 ──
$r = $parse([[
    'name' => '자재 일괄', 'qty' => '1', 'unit_price' => '0',
    'material_cost' => '100', 'labor_cost' => '200', 'equipment_cost' => '300',
    'outsourcing_cost' => '400', 'etc_cost' => '500',
]]);
t_int('cost만(단가0): 100+200+300+400+500 = 1,500', 1500, (int) $r[0]['amount']);

// ── 3. 혼합 + 면적: amount = 면적×수량×단가 + cost 합 ──
$r = $parse([[
    'name' => '옥상 방수', 'area' => '10', 'qty' => '1', 'unit_price' => '1,000',
    'material_cost' => '500',
]]);
t_int('혼합(면적10×1×1,000 + 재료500) = 10,500', 10500, (int) $r[0]['amount']);

// ── 4. 콤마 입력 정규화 + 면적 미입력(개수 기준) ──
$r = $parse([[
    'name' => '난간', 'qty' => '2', 'unit_price' => '18,000',
    'labor_cost' => '1,000', 'etc_cost' => '2,000',
]]);
t_int('면적無: 2×18,000 + 1,000+2,000 = 39,000', 39000, (int) $r[0]['amount']);

// ── 5. 이름 빈 행 skip ──
$r = $parse([
    ['name' => '', 'qty' => '1', 'unit_price' => '999'],
    ['name' => '유효', 'qty' => '1', 'unit_price' => '100'],
]);
t_int('빈 이름 행 제외 → 1건', 1, count($r));

// ── 6. computeTotals: 공급가액=Σamount, VAT=round(subtotal×10%), 총액=subtotal+VAT−할인 ──
$items = $parse([
    ['name' => 'A', 'qty' => '2', 'unit_price' => '1000'],                                                  // 2,000
    ['name' => 'B', 'qty' => '1', 'unit_price' => '0',
     'material_cost' => '100', 'labor_cost' => '200', 'equipment_cost' => '300',
     'outsourcing_cost' => '400', 'etc_cost' => '500'],                                                     // 1,500
    ['name' => 'C', 'area' => '10', 'qty' => '1', 'unit_price' => '1000', 'material_cost' => '500'],        // 10,500
]);
[$sub, $vat, $tot] = $totals($items, 500.0, 10.0);
t_int('공급가액 = 2,000+1,500+10,500 = 14,000', 14000, (int) $sub);
t_int('VAT 10% = 1,400', 1400, (int) $vat);
t_int('총액 = 14,000+1,400−500 = 14,900', 14900, (int) $tot);

exit(t_summary());
