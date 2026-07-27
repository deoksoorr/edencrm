<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
foreach (['Auth', 'Audit', 'StatusService', 'ProcessService', 'ContractProjectService'] as $c) {
    require_once APP_PATH . '/core/' . $c . '.php';
}
require_once APP_PATH . '/controllers/ContractsController.php';

echo "R3 contractflow — splitPayments · ensureProject 멱등 · 견적 전환 보존 · payments 동기화\n";

// ── 1) splitPayments: 기준 = 계약 총액(VAT 포함), 반올림 보정은 잔금 귀속 ──
$s = AccountingService::splitPayments(33000000, 20, 50, 30);
t_int('33,000,000 × 20% 계약금 = 6,600,000', 6600000, $s['down']);
t_int('33,000,000 × 50% 중도금 = 16,500,000', 16500000, $s['middle']);
t_int('33,000,000 × 30% 잔금 = 9,900,000', 9900000, $s['balance']);
t_int('세 금액 합 = 총액', 33000000, $s['down'] + $s['middle'] + $s['balance']);

$s = AccountingService::splitPayments(10000001, 33.33, 33.33, 33.34);
t_int('10,000,001(나머지 케이스) 합 = 총액', 10000001, $s['down'] + $s['middle'] + $s['balance']);
t_int('잔금 = 총액 − 계약금 − 중도금(보정 귀속)', 10000001 - $s['down'] - $s['middle'], $s['balance']);

$s = AccountingService::splitPayments(37462250, 30, 40.04, 29.96);
t_int('C1 형태(30/40.04/29.96) 합 = 총액', 37462250, $s['down'] + $s['middle'] + $s['balance']);

foreach ([[30, 30, 30, '합 90%'], [40, 40, 30, '합 110%'], [-10, 60, 50, '음수 비율'], [101, 0, -1, '100 초과']] as [$d, $m, $b, $label]) {
    $threw = false;
    try {
        AccountingService::splitPayments(1000000, (float) $d, (float) $m, (float) $b);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    t_true("splitPayments 거부: $label", $threw);
}

// ── 2) ensureProject 멱등 + 커널 계약(waiting·entered_at·is_auto·실착공일 NULL) ──
$cid = 0;
$pid = 0;
$custId = 0;
try {
    $custId = (int) Db::insert('customers', ['type' => 'company', 'name' => 'TEST-R3-CF', 'status' => 'active']);
    $cid = (int) Db::insert('contracts', [
        'contract_no'     => 'TEST-R3-' . substr(uniqid(), -8),
        'customer_id'     => $custId,
        'contract_date'   => date('Y-m-d'),
        'contract_amount' => 11000000, 'supply_amount' => 10000000, 'vat_amount' => 1000000,
        'down_payment'    => 2200000, 'down_pct' => 20,
        'middle_payment'  => 0, 'middle_pct' => 0,
        'balance_payment' => 8800000, 'balance_pct' => 80,
        'status'          => 'active', 'payment_status' => 'unpaid',
        'work_name'       => 'R3 테스트 공사', 'work_type' => '테스트도장', 'site_address' => '테스트 현장',
    ]);
    $r1 = ContractProjectService::ensureProject($cid, null);
    $r2 = ContractProjectService::ensureProject($cid, null);
    $pid = (int) $r1['project_id'];
    t_true('1차 호출 created=true', $r1['created'] === true);
    t_true('2차 호출 created=false(멱등)', $r2['created'] === false);
    t_int('project_id 동일', $pid, (int) $r2['project_id']);
    t_int('계약당 프로젝트 1건', 1, (int) Db::val("SELECT COUNT(*) FROM projects WHERE contract_id = :c", [':c' => $cid]));

    $p = Db::one("SELECT * FROM projects WHERE id = :id", [':id' => $pid]);
    t_int('공정 = 대기중(waiting)', ProcessService::waitingStageId(), (int) $p['process_stage_id']);
    t_true('process_entered_at 세팅', !empty($p['process_entered_at']));
    t_true('status = in_progress', $p['status'] === 'in_progress');
    t_null('actual_start_date NULL(계약일이 실착공일 오염 금지)', $p['actual_start_date']);
    t_true('공사명 복사(work_name → name)', $p['name'] === 'R3 테스트 공사');
    t_int('계약 총액(VAT 포함) 복사', 11000000, (int) $p['contract_amount']);
    t_int('공급가액 복사', 10000000, (int) $p['supply_amount']);
    t_int('공정 이력 is_auto=1 기록', 1, (int) Db::val(
        "SELECT COUNT(*) FROM project_process_history WHERE project_id = :p AND to_stage_id = :s AND is_auto = 1",
        [':p' => $pid, ':s' => ProcessService::waitingStageId()]
    ));
    t_int('상태 이력(in_progress) 기록', 1, (int) Db::val(
        "SELECT COUNT(*) FROM project_status_history WHERE project_id = :p AND to_status = 'in_progress'",
        [':p' => $pid]
    ));

    // ── 3) 분할 계획 payments 동기화: pending 만 갱신, paid 불변 ──
    Db::insert('payments', ['contract_id' => $cid, 'pay_type' => 'down', 'amount' => 2200000,
        'due_date' => null, 'paid_date' => date('Y-m-d'), 'status' => 'paid', 'memo' => null]);
    Db::insert('payments', ['contract_id' => $cid, 'pay_type' => 'balance', 'amount' => 8800000,
        'due_date' => null, 'paid_date' => null, 'status' => 'pending', 'memo' => null]);
    // 비율 변경 20/30/50 → 2,200,000 / 3,300,000 / 5,500,000
    $split = AccountingService::splitPayments(11000000, 20, 30, 50);
    ContractsController::syncPaymentPlan($cid, $split, []);
    t_int('paid 행 불변(계약금 2,200,000)', 2200000, (int) Db::val(
        "SELECT amount FROM payments WHERE contract_id = :c AND pay_type = 'down' AND status = 'paid'", [':c' => $cid]));
    t_int('down pending 미생성(전액 입금 완료)', 0, (int) Db::val(
        "SELECT COUNT(*) FROM payments WHERE contract_id = :c AND pay_type = 'down' AND status = 'pending'", [':c' => $cid]));
    t_int('middle pending 신규 생성 = 3,300,000', 3300000, (int) Db::val(
        "SELECT COALESCE(SUM(amount),0) FROM payments WHERE contract_id = :c AND pay_type = 'middle' AND status = 'pending'", [':c' => $cid]));
    t_int('balance pending 갱신 = 5,500,000', 5500000, (int) Db::val(
        "SELECT COALESCE(SUM(amount),0) FROM payments WHERE contract_id = :c AND pay_type = 'balance' AND status = 'pending'", [':c' => $cid]));
    t_int('balance pending 행 1건(중복 없음)', 1, (int) Db::val(
        "SELECT COUNT(*) FROM payments WHERE contract_id = :c AND pay_type = 'balance' AND status = 'pending'", [':c' => $cid]));
} finally {
    // 정리 — 시드 대사값(reconcile_qa)에 영향 없도록 테스트 데이터 완전 삭제
    if ($pid) {
        Db::run("DELETE FROM audit_logs WHERE entity = 'project' AND entity_id = :p", [':p' => $pid]);
        Db::run("DELETE FROM project_process_history WHERE project_id = :p", [':p' => $pid]);
        Db::run("DELETE FROM project_status_history WHERE project_id = :p", [':p' => $pid]);
        Db::run("DELETE FROM projects WHERE id = :p", [':p' => $pid]);
    }
    if ($cid) {
        Db::run("DELETE FROM payments WHERE contract_id = :c", [':c' => $cid]);
        Db::run("DELETE FROM contracts WHERE id = :c", [':c' => $cid]);
    }
    if ($custId) {
        Db::run("DELETE FROM customers WHERE id = :c", [':c' => $custId]);
    }
}

// ── 4) 견적 전환 보존 필드 — R6 T2 빈 시드 재기준(트랜잭션 롤백 픽스처) ──
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $qCust = Db::insert('customers', ['type' => 'company', 'name' => 'TEST-R3-QCONV', 'status' => 'active']);
    $qid = Db::insert('quotes', ['quote_no' => 'TQ-R3-CONV', 'customer_id' => $qCust, 'status' => 'accepted']);
    $qvid = Db::insert('quote_versions', ['quote_id' => $qid, 'version_no' => 1, 'subtotal' => 34622500,
        'vat' => 3462250, 'discount' => 622500, 'total_amount' => 37462250, 'created_by' => 1]);
    Db::update('quotes', ['current_version_id' => $qvid], 'id = :id', [':id' => $qid]);
    $convCid = Db::insert('contracts', ['contract_no' => 'TC-R3-CONV', 'quote_id' => $qid, 'customer_id' => $qCust,
        'contract_amount' => 37462250, 'supply_amount' => 34000000, 'vat_amount' => 3462250,
        'status' => 'active', 'payment_status' => 'unpaid', 'contract_date' => date('Y-m-d')]);
    // seed_dev 의 구 백필 UPDATE 와 동일 규칙(견적 연결 계약의 전환 보존 필드 세팅)
    Db::run(
        "UPDATE contracts c JOIN quotes q ON q.id=c.quote_id JOIN quote_versions qv ON qv.id=q.current_version_id
         SET c.quote_version_id=qv.id, c.original_quote_amount=qv.total_amount, c.adjust_amount=c.contract_amount-qv.total_amount,
             c.converted_at=c.created_at, c.converted_by=c.sales_user_id
         WHERE c.id=:id AND c.quote_version_id IS NULL", [':id' => $convCid]
    );
    $c1 = Db::one("SELECT * FROM contracts WHERE id = :id", [':id' => $convCid]);
    t_int('전환 계약 quote_version_id = 견적 버전', $qvid, (int) $c1['quote_version_id']);
    t_int('원본 견적액 = 37,462,250', 37462250, (int) $c1['original_quote_amount']);
    t_int('조정액(계약액−견적액) = 0', 0, (int) $c1['adjust_amount']);
    t_true('전환 일시 보존', !empty($c1['converted_at']));
} finally {
    $pdo->rollBack();
}

// 백필 비율: 분할 금액 있는 계약 전건 — 비율 존재 + 합계 100(잔금 귀속 보정) — 빈 시드에선 0건이 정상
t_int('백필 비율 위반(NULL 또는 합≠100) 0건', 0, (int) Db::val(
    "SELECT COUNT(*) FROM contracts
     WHERE deleted_at IS NULL AND (down_payment + middle_payment + balance_payment) > 0
       AND (down_pct IS NULL OR ABS(down_pct + middle_pct + balance_pct - 100) > 0.001)"
));

exit(t_summary());
