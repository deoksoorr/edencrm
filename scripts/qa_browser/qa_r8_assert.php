<?php
/** R8 QA — 시드 기대값 대사(반기 계산·직원 실적·보너스 합계). dev DB 전용. */
require '/Users/deoksookim/Desktop/코드/claude code/eden_crm/scripts/tests/bootstrap.php';

$pass = 0; $fail = 0;
function t(string $name, $exp, $act): void {
    global $pass, $fail;
    if ((string) $exp === (string) $act) { $pass++; echo "  [PASS] $name = " . number_format((float) $act) . "\n"; }
    else { $fail++; echo "  [FAIL] $name 기대=" . number_format((float) $exp) . " 실제=" . number_format((float) $act) . "\n"; }
}

[$h1f, $h1t] = array_values(Util::halfRange(2026, 1));
[$h2f, $h2t] = array_values(Util::halfRange(2026, 2));

echo "── 반기 경계 ──\n";
t('2026 상반기 from', '2026-01-01', $h1f); t('2026 상반기 to', '2026-06-30', $h1t);
t('2026 하반기 from', '2026-07-01', $h2f); t('2026 하반기 to', '2026-12-31', $h2t);
t('현재(7/27) 반기', 2, Util::currentHalf()['half']);
t('상반기 마감 여부', 1, (int) Util::isHalfClosed(2026, 1));
t('하반기 마감 여부', 0, (int) Util::isHalfClosed(2026, 2));

echo "── 매출·입금·수주(공통 서비스) ──\n";
t('H1 입금액', 64900000, AccountingService::paidTotal($h1f, $h1t));
t('H1 확정매출(완납·공급가)', 59000000, AccountingService::confirmedRevenue($h1f, $h1t));
t('H1 확정순이익', 7000000, AccountingService::confirmedProfit($h1f, $h1t));
t('H1 수주액(I1 계약일 6/20 포함)', 74000000, AccountingService::contractedAmount($h1f, $h1t));
t('H1 등록지출', 52000000, AccountingService::costTotal($h1f, $h1t));
t('H2 입금액', 34500000, AccountingService::paidTotal($h2f, $h2t));
t('H2 확정매출', 15000000, AccountingService::confirmedRevenue($h2f, $h2t));
t('H2 확정순이익', 6000000, AccountingService::confirmedProfit($h2f, $h2t));
t('H2 수주액', 62000000, AccountingService::contractedAmount($h2f, $h2t));
t('H2 등록지출', 20000000, AccountingService::costTotal($h2f, $h2t));
t('현재 미수금', 50200000, AccountingService::receivable());

echo "── 직원별(기여율 가중) ──\n";
$h1u = AccountingService::employeeConfirmedByUser($h1f, $h1t);
t('H1 QA직원A 현장매출', 38000000, (int) ($h1u[5]['revenue'] ?? 0));
t('H1 QA직원A 순이익기여', 1000000, (int) ($h1u[5]['contrib'] ?? 0));
t('H1 QA직원B 현장매출', 21000000, (int) ($h1u[6]['revenue'] ?? 0));
t('H1 QA직원B 순이익기여', 6000000, (int) ($h1u[6]['contrib'] ?? 0));
$h2u = AccountingService::employeeConfirmedByUser($h2f, $h2t);
t('H2 QA직원B 현장매출', 15000000, (int) ($h2u[6]['revenue'] ?? 0));
t('H2 QA직원B 순이익기여', 6000000, (int) ($h2u[6]['contrib'] ?? 0));

echo "── 보너스 원장 ──\n";
t('H1 지급 합계', 1200000, (int) Db::val(
    "SELECT COALESCE(SUM(paid_amount),0) FROM site_bonuses WHERE year=2026 AND half=1 AND pay_status<>'cancelled' AND deleted_at IS NULL"));
t('H2 미지급(산정-지급, 취소 제외)', 500000, (int) Db::val(
    "SELECT COALESCE(SUM(calc_amount-paid_amount),0) FROM site_bonuses WHERE year=2026 AND half=2 AND pay_status<>'cancelled' AND deleted_at IS NULL"));
t('이력 행수(시드)', 6, (int) Db::val("SELECT COUNT(*) FROM site_bonus_history"));

echo "── 유형 분리 ──\n";
t('도장 프로젝트 수', 5, (int) Db::val("SELECT COUNT(*) FROM projects WHERE construction_type='painting' AND deleted_at IS NULL"));
t('인테리어 프로젝트 수', 5, (int) Db::val("SELECT COUNT(*) FROM projects WHERE construction_type='interior' AND deleted_at IS NULL"));
t('미지정 프로젝트 수', 1, (int) Db::val("SELECT COUNT(*) FROM projects WHERE construction_type IS NULL AND deleted_at IS NULL"));

echo "\n──────── 결과: PASS $pass · FAIL $fail ────────\n";
exit($fail ? 1 : 0);
