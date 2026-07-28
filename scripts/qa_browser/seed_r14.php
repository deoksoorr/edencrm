<?php
/** R14 QA 시드 — 예외 프로젝트 A(도장)/B(인테리어) + 입금 + 게이지 + 메모. 실제 서비스 클래스를 통해 삽입해
 * projects.progress/process_stage_id 파생이 프로덕션과 동일하게 일관되도록 한다. dev DB 전용. */
declare(strict_types=1);
define('APP_CONFIG_FILE', '/Users/deoksookim/Desktop/코드/claude code/eden_crm/app/config/config.php');
$GLOBALS['config'] = require APP_CONFIG_FILE;
require APP_PATH . '/bootstrap.php';

function out($k, $v) { echo "$k=$v\n"; }

$adminId = (int) Db::val("SELECT id FROM users WHERE login_id='admin'");
if (!$adminId) { fwrite(STDERR, "admin user not found\n"); exit(1); }
$waitingId = ProcessService::waitingStageId();

// ── 프로젝트 A: QA도장 (painting, in_progress, 계약총액 33,000,000) ──
$splitA = AccountingService::computeSplit(33000000);
$noA = 'QAR14-A-' . substr((string) time(), -6);
$dataA = [
    'project_no'        => $noA,
    'name'              => 'QA도장',
    'is_exception'      => 1,
    'customer_name_snapshot' => 'QA고객A',
    'site_address'      => 'QA현장 A',
    'construction_type' => 'painting',
    'contract_amount'   => 33000000,
    'supply_amount'     => $splitA['supply'],
    'vat_amount'        => $splitA['vat'],
    'estimated_cost'    => 0,
    'actual_cost'       => 0,
    'process_stage_id'  => $waitingId,
    'process_entered_at' => date('Y-m-d H:i:s'),
    'status'            => 'in_progress',
    'contract_date'     => date('Y-m-d'),
    'sales_user_id'     => $adminId,
    'progress'          => 0,
];
$idA = Db::insert('projects', $dataA);
out('project_a_id', $idA);
out('project_a_no', $noA);

// ── 프로젝트 B: QA인테리어 (interior, preparing, 계약총액 0 / 예상금액 5,500,000 레거시 폴백) ──
$noB = 'QAR14-B-' . substr((string) time(), -6);
$dataB = [
    'project_no'        => $noB,
    'name'              => 'QA인테리어',
    'is_exception'      => 1,
    'customer_name_snapshot' => 'QA고객B',
    'site_address'      => 'QA현장 B',
    'construction_type' => 'interior',
    'contract_amount'   => 0,
    'expected_amount'   => 5500000,
    'estimated_cost'    => 0,
    'actual_cost'       => 0,
    'process_stage_id'  => $waitingId,
    'process_entered_at' => date('Y-m-d H:i:s'),
    'status'            => 'preparing',
    'sales_user_id'     => $adminId,
    'progress'          => 0,
];
$idB = Db::insert('projects', $dataB);
out('project_b_id', $idB);
out('project_b_no', $noB);

// ── 입금: A에 계약금 11,000,000 (paid, 오늘) ──
$paymentId = Db::insert('payments', [
    'project_id' => $idA,
    'pay_type'   => 'down',
    'method'     => 'transfer',
    'kind'       => 'payment',
    'amount'     => 11000000,
    'due_date'   => date('Y-m-d'),
    'paid_date'  => date('Y-m-d'),
    'status'     => 'paid',
    'memo'       => 'QA 시드 입금',
    'created_by' => $adminId,
]);
out('payment_id', $paymentId);

// ── 게이지: A 첫 두 도장 공정 100/50 — ProcessService 경유(파생 progress·process_stage_id 일관) ──
$gauge = ProcessService::gaugeStages('painting');
$stage1 = $gauge[0]['id'];
$stage2 = $gauge[1]['id'];
$stage3 = $gauge[2]['id'];
out('stage1_id', $stage1);
out('stage1_name', $gauge[0]['name']);
out('stage2_id', $stage2);
out('stage2_name', $gauge[1]['name']);
out('stage3_id', $stage3);
out('stage3_name', $gauge[2]['name']);
ProcessService::setStageProgress($idA, (int) $stage1, 100, $adminId);
$r2 = ProcessService::setStageProgress($idA, (int) $stage2, 50, $adminId);
out('after_seed_progress', $r2['progress']);
out('after_seed_status', $r2['status']);
out('after_seed_current_stage_id', $r2['current_stage_id']);

// ── 메모: A에 1건 ──
$memoId = Db::insert('project_memos', [
    'project_id' => $idA,
    'memo_date'  => date('Y-m-d'),
    'content'    => 'QA 메모 테스트',
    'created_by' => $adminId,
]);
out('memo_id', $memoId);

// ── 현장 보너스 1건: 반기 원장 신규 용어(총매출/기여도 반영 매출/기여도 반영 순이익) 헤더 렌더 확인용 ──
$cur = Util::currentHalf();
$bonusId = Db::insert('site_bonuses', [
    'user_id'         => $adminId,
    'project_id'      => $idA,
    'year'            => $cur['year'],
    'half'            => $cur['half'],
    'base_amount'     => 12000000,
    'calc_basis'      => 'QA 시드 산정',
    'contrib_revenue' => 6000000,
    'contrib_profit'  => 3000000,
    'bonus_rate'      => 10.00,
    'calc_amount'     => 600000,
    'confirmed_bonus' => 600000,
    'pay_status'      => 'unpaid',
    'created_by'      => $adminId,
]);
out('bonus_id', $bonusId);
out('bonus_year', $cur['year']);
out('bonus_half', $cur['half']);

echo "SEED_OK\n";
