<?php
/**
 * 화면=DB 대사 QA (R6 T2 — 빈 데이터 재기준). 최소 시드(직원 4명·일정 3건, 업무 더미 0건)에서
 * AccountingService 산식이 전부 0을 반환하고(0 나눗셈 없이 null 처리), 시드 구성이
 * 기준선(김덕수·차윤석·맹기현·차우석 + 프로젝트 미연결 일정 3건)과 일치함을 검증한다.
 * 실행: php scripts/reconcile_qa.php   (개발 MySQL 가동 필요)
 */
require __DIR__ . '/tests/bootstrap.php';
require __DIR__ . '/tests/lib.php';

$mf = date('Y-m-01');
$mt = date('Y-m-t');

echo "화면=DB 대사 (빈 데이터 기준선 · " . date('Y-m') . ")\n";

// ── 재무/대시보드 핵심: 업무 데이터 0건 → 전 지표 0 ──
t_int('이번달 확정매출 = 0', 0, AccountingService::confirmedRevenue($mf, $mt));
t_int('이번달 확정순이익 = 0', 0, AccountingService::confirmedProfit($mf, $mt));
t_int('이번달 확정원가 = 0', 0, AccountingService::confirmedCost($mf, $mt));
t_int('이번달 수주액 = 0', 0, AccountingService::contractedAmount($mf, $mt));
t_int('예상매출 = 0', 0, AccountingService::expectedRevenue());
t_int('미수금 = 0', 0, AccountingService::receivable());
t_int('가중 예상매출(파이프라인) = 0', 0, AccountingService::weightedPipeline());
t_int('이번달 원가 총액 = 0', 0, AccountingService::costTotal($mf, $mt));
t_int('계약 진행(active) 건수 = 0', 0, AccountingService::activeContractCount());
t_int('입금 총액(순입금) = 0', 0, AccountingService::paidTotal());
t_int('환불 총액 = 0', 0, AccountingService::refundTotal());

// ── 직원 확정 기여: 완료 프로젝트 없음 → 전원 0, 합 = 회사 확정순이익(0) 항등 유지 ──
$c2 = AccountingService::employeeConfirmedContribution(2);
$c3 = AccountingService::employeeConfirmedContribution(3);
$c4 = AccountingService::employeeConfirmedContribution(4);
t_int('차윤석(2) 확정기여 = 0', 0, $c2);
t_int('맹기현(3) 확정기여 = 0', 0, $c3);
t_int('차우석(4) 확정기여 = 0', 0, $c4);
$company = AccountingService::companyConfirmedProfit();
t_int('회사 확정순이익 = 0', 0, $company);
t_int('직원 기여 합 = 회사 확정순이익(항등)', $company, $c2 + $c3 + $c4);

// ── 0 나눗셈 안전: 분모 0 → null(0% 아님) ──
t_null('회사기여율 분모 0 → null', Calc::rate((float) $c2, (float) $company));
t_null('목표 미설정(0) → 달성률 null', AccountingService::achievement(0.0, 0.0));
t_null('목표 NULL → 달성률 null', AccountingService::achievement(1000000.0, null));

// ── 미수금: 리스트 합계 = KPI(항등, 둘 다 0) + draft 0건 전제 ──
$recvStatusIn = "'" . implode("','", AccountingService::RECEIVABLE_STATUSES) . "'";
$listSum = (int) Db::val(
    "SELECT COALESCE(SUM(GREATEST(0, c.contract_amount - COALESCE((SELECT SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END) FROM payments pm WHERE pm.contract_id=c.id AND pm.status='paid'),0))),0)
     FROM contracts c WHERE c.deleted_at IS NULL AND c.status IN ($recvStatusIn)"
);
t_int('미수금 리스트 합계 = KPI', AccountingService::receivable(), $listSum);
t_int('draft 계약 0건', 0,
    (int) Db::val("SELECT COUNT(*) FROM contracts WHERE deleted_at IS NULL AND status='draft'"));

// ── 시드 구성: 직원 4명(김덕수·차윤석·맹기현·차우석), leesh 없음 ──
t_int('직원 4명', 4, (int) Db::val("SELECT COUNT(*) FROM users"));
t_true('admin = 김덕수(super_admin)',
    Db::one("SELECT name, role_key FROM users WHERE login_id='admin'") === ['name' => '김덕수', 'role_key' => 'super_admin']);
t_true('chays = 차윤석(site_manager)',
    Db::one("SELECT name, role_key FROM users WHERE login_id='chays'") === ['name' => '차윤석', 'role_key' => 'site_manager']);
t_true('maeng = 맹기현(staff)',
    Db::one("SELECT name, role_key FROM users WHERE login_id='maeng'") === ['name' => '맹기현', 'role_key' => 'staff']);
t_true('chaws = 차우석(staff)',
    Db::one("SELECT name, role_key FROM users WHERE login_id='chaws'") === ['name' => '차우석', 'role_key' => 'staff']);
t_int('leesh 등 제거된 계정 없음', 0,
    (int) Db::val("SELECT COUNT(*) FROM users WHERE login_id NOT IN ('admin','chays','maeng','chaws')"));

// ── 시드 구성: 일정 3건(전부 프로젝트 미연결·vacation 아님·슬롯 보유) ──
t_int('일정 3건', 3, (int) Db::val("SELECT COUNT(*) FROM schedules"));
t_int('프로젝트 연결 일정 0건', 0, (int) Db::val("SELECT COUNT(*) FROM schedules WHERE project_id IS NOT NULL"));
t_int('vacation 일정 0건(R6 비노출 정책)', 0, (int) Db::val("SELECT COUNT(*) FROM schedules WHERE type='vacation'"));
t_int('슬롯 없는 일정 0건(schedule_time_slots 원본 보유)', 0,
    (int) Db::val("SELECT COUNT(*) FROM schedules s WHERE NOT EXISTS(SELECT 1 FROM schedule_time_slots t WHERE t.schedule_id=s.id)"));

// ── 업무 더미 0건 전수 확인 ──
foreach (['customers', 'customer_contacts', 'customer_activities', 'leads', 'quotes', 'quote_versions',
    'quote_items', 'contracts', 'contract_status_history', 'contract_terminations', 'payments',
    'projects', 'project_assignments', 'project_status_history', 'project_process_history',
    'project_files', 'work_logs', 'work_log_photos', 'costs', 'warranty_repairs',
    'targets', 'company_targets', 'notifications', 'audit_logs', 'attendance_marks'] as $tbl) {
    t_int("빈 테이블: $tbl = 0건", 0, (int) Db::val("SELECT COUNT(*) FROM `$tbl`"));
}

exit(t_summary());
