<?php
/**
 * DR 테스트 T9 — QARESTORE- QA 데이터 정리.
 *
 * 원칙: **복구된 운영 데이터는 한 행도 건드리지 않는다.**
 * 그래서 "QARESTORE- 로 시작하는 이름"만 지우는 게 아니라, 그 QA 고객에
 * 연결된 하위 데이터만 관계를 타고 정확히 지운다. 계약번호·프로젝트명처럼
 * 서버가 채번·승계하는 값에는 접두어가 없으므로 이름으로만 지우면 누락된다.
 *
 * 앱의 참조가드는 기록 보존이 목적이라 QA 잔재를 남기는데, 여기서는 테스트
 * 환경을 원상복구하는 게 목적이므로 DB 레벨에서 의존성 역순으로 제거한다.
 *
 * 사용: php scripts/dr/qa/cleanup_qa.php [--dry-run]
 */

if (PHP_SAPI !== 'cli') { exit(1); }
$dryRun = in_array('--dry-run', $argv, true);

$conf = require '/Users/deoksookim/Desktop/코드/claude code/eden_crm_restore_test/_dr/config.restore.php';
if (!str_ends_with($conf['DB_NAME'], '_restore_test')) {
    fwrite(STDERR, "가드 위반: 복구 DB 가 아님 ({$conf['DB_NAME']}) — 중단\n");
    exit(9);
}
$pdo = new PDO(
    "mysql:unix_socket={$conf['DB_SOCKET']};dbname={$conf['DB_NAME']};charset=utf8mb4",
    $conf['DB_USER'], $conf['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$P = $conf['TBL_PREFIX'];
$QA = 'QARESTORE-';

/** QA 고객 id 목록 — 여기서부터 관계를 타고 내려간다. */
$custIds = $pdo->query("SELECT id FROM `{$P}customers` WHERE name LIKE '{$QA}%'")->fetchAll(PDO::FETCH_COLUMN);
$custIn  = $custIds ? implode(',', array_map('intval', $custIds)) : '0';

$quoteIds = $pdo->query("SELECT id FROM `{$P}quotes` WHERE customer_id IN ($custIn)")->fetchAll(PDO::FETCH_COLUMN);
$quoteIn  = $quoteIds ? implode(',', array_map('intval', $quoteIds)) : '0';

$contractIds = $pdo->query("SELECT id FROM `{$P}contracts` WHERE customer_id IN ($custIn)")->fetchAll(PDO::FETCH_COLUMN);
$contractIn  = $contractIds ? implode(',', array_map('intval', $contractIds)) : '0';

$projectIds = $pdo->query("SELECT id FROM `{$P}projects`
                            WHERE contract_id IN ($contractIn) OR customer_id IN ($custIn)
                               OR name LIKE '{$QA}%'")->fetchAll(PDO::FETCH_COLUMN);
$projectIn  = $projectIds ? implode(',', array_map('intval', $projectIds)) : '0';

$scheduleIds = $pdo->query("SELECT id FROM `{$P}schedules`
                             WHERE title LIKE '{$QA}%' OR project_id IN ($projectIn)")->fetchAll(PDO::FETCH_COLUMN);
$scheduleIn  = $scheduleIds ? implode(',', array_map('intval', $scheduleIds)) : '0';

echo "정리 대상 (관계 추적 결과)\n";
printf("  고객 %d · 견적 %d · 계약 %d · 프로젝트 %d · 일정 %d\n",
    count($custIds), count($quoteIds), count($contractIds), count($projectIds), count($scheduleIds));

// 의존성 역순 — 자식부터 지운다. FK CASCADE 에 의존하지 않고 명시적으로 처리한다.
$steps = [
    '일정 참여자'   => "DELETE FROM `{$P}schedule_participants` WHERE schedule_id IN ($scheduleIn)",
    '일정 시간대'   => "DELETE FROM `{$P}schedule_time_slots` WHERE schedule_id IN ($scheduleIn)",
    '일정'          => "DELETE FROM `{$P}schedules` WHERE id IN ($scheduleIn)",
    '공정 이력'     => "DELETE FROM `{$P}project_process_history` WHERE project_id IN ($projectIn)",
    '공정 진행률'   => "DELETE FROM `{$P}project_stage_progress` WHERE project_id IN ($projectIn)",
    '프로젝트 이력' => "DELETE FROM `{$P}project_status_history` WHERE project_id IN ($projectIn)",
    '프로젝트 배정' => "DELETE FROM `{$P}project_assignments` WHERE project_id IN ($projectIn)",
    '프로젝트 메모' => "DELETE FROM `{$P}project_memos` WHERE project_id IN ($projectIn)",
    '프로젝트 파일' => "DELETE FROM `{$P}project_files` WHERE project_id IN ($projectIn)",
    '지출'          => "DELETE FROM `{$P}costs` WHERE project_id IN ($projectIn)",
    '입금'          => "DELETE FROM `{$P}payments` WHERE contract_id IN ($contractIn) OR project_id IN ($projectIn)",
    '보너스'        => "DELETE FROM `{$P}site_bonuses` WHERE project_id IN ($projectIn)",
    '프로젝트'      => "DELETE FROM `{$P}projects` WHERE id IN ($projectIn)",
    '계약 상태이력' => "DELETE FROM `{$P}contract_status_history` WHERE contract_id IN ($contractIn)",
    '계약 파기'     => "DELETE FROM `{$P}contract_terminations` WHERE contract_id IN ($contractIn)",
    '계약'          => "DELETE FROM `{$P}contracts` WHERE id IN ($contractIn)",
    '견적 항목'     => "DELETE qi FROM `{$P}quote_items` qi JOIN `{$P}quote_versions` qv ON qv.id=qi.quote_version_id WHERE qv.quote_id IN ($quoteIn)",
    '견적 버전'     => "DELETE FROM `{$P}quote_versions` WHERE quote_id IN ($quoteIn)",
    '견적'          => "DELETE FROM `{$P}quotes` WHERE id IN ($quoteIn)",
    '영업기회'      => "DELETE FROM `{$P}leads` WHERE customer_id IN ($custIn)",
    '고객 활동'     => "DELETE FROM `{$P}customer_activities` WHERE customer_id IN ($custIn)",
    '고객 연락처'   => "DELETE FROM `{$P}customer_contacts` WHERE customer_id IN ($custIn)",
    '고객'          => "DELETE FROM `{$P}customers` WHERE id IN ($custIn)",
];

if ($dryRun) {
    echo "\n[dry-run] 실행하지 않음\n";
    exit(0);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$total = 0;
foreach ($steps as $label => $sql) {
    $n = $pdo->exec($sql);
    $total += $n;
    if ($n > 0) printf("  %-14s %d행 삭제\n", $label, $n);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
echo "총 {$total}행 삭제\n";

// ── 잔존 확인 ──────────────────────────────────────────────────────────────
// "지웠다"가 아니라 "0건인가"로 확인한다.
echo "\n잔존 확인:\n";
$checks = [
    'customers'  => "SELECT COUNT(*) FROM `{$P}customers` WHERE name LIKE '{$QA}%'",
    'leads'      => "SELECT COUNT(*) FROM `{$P}leads` WHERE memo LIKE '{$QA}%'",
    'quotes'     => "SELECT COUNT(*) FROM `{$P}quotes` WHERE memo LIKE '{$QA}%'",
    'quote_items'=> "SELECT COUNT(*) FROM `{$P}quote_items` WHERE name LIKE '{$QA}%'",
    'contracts'  => "SELECT COUNT(*) FROM `{$P}contracts` WHERE work_name LIKE '{$QA}%' OR memo LIKE '{$QA}%'",
    'projects'   => "SELECT COUNT(*) FROM `{$P}projects` WHERE name LIKE '{$QA}%'",
    'payments'   => "SELECT COUNT(*) FROM `{$P}payments` WHERE memo LIKE '{$QA}%' OR payer_name LIKE '{$QA}%'",
    'costs'      => "SELECT COUNT(*) FROM `{$P}costs` WHERE item_name LIKE '{$QA}%'",
    'schedules'  => "SELECT COUNT(*) FROM `{$P}schedules` WHERE title LIKE '{$QA}%'",
];
$left = 0;
foreach ($checks as $k => $q) {
    $n = (int) $pdo->query($q)->fetchColumn();
    $left += $n;
    printf("  %-12s %s\n", $k, $n === 0 ? '0건 ✅' : "{$n}건 ❌");
}

// 감사 로그는 정책상 보존한다(완전삭제 이력은 남아야 한다). 다만 이 환경 안에만 존재한다.
$auditQa = (int) $pdo->query("SELECT COUNT(*) FROM `{$P}audit_logs` WHERE created_at >= CURDATE()")->fetchColumn();
printf("  %-12s %d건 (정책상 보존 — 복구 테스트 환경 내부에만 존재)\n", 'audit_logs', $auditQa);

// orphan 재검증 — 정리가 참조 무결성을 깨지 않았는지
echo "\n정리 후 무결성:\n";
$orphans = [
    'quote→customer'    => "SELECT COUNT(*) FROM `{$P}quotes` a LEFT JOIN `{$P}customers` b ON a.customer_id=b.id WHERE a.customer_id IS NOT NULL AND b.id IS NULL",
    'contract→customer' => "SELECT COUNT(*) FROM `{$P}contracts` a LEFT JOIN `{$P}customers` b ON a.customer_id=b.id WHERE b.id IS NULL",
    'project→contract'  => "SELECT COUNT(*) FROM `{$P}projects` a LEFT JOIN `{$P}contracts` b ON a.contract_id=b.id WHERE a.contract_id IS NOT NULL AND b.id IS NULL",
    'payment→parent'    => "SELECT COUNT(*) FROM `{$P}payments` a LEFT JOIN `{$P}contracts` c ON a.contract_id=c.id LEFT JOIN `{$P}projects` p ON a.project_id=p.id WHERE (a.contract_id IS NOT NULL AND c.id IS NULL) OR (a.project_id IS NOT NULL AND p.id IS NULL)",
    'cost→project'      => "SELECT COUNT(*) FROM `{$P}costs` a LEFT JOIN `{$P}projects` b ON a.project_id=b.id WHERE b.id IS NULL",
    'quote_item→version'=> "SELECT COUNT(*) FROM `{$P}quote_items` a LEFT JOIN `{$P}quote_versions` b ON a.quote_version_id=b.id WHERE b.id IS NULL",
];
$orphanTotal = 0;
foreach ($orphans as $k => $q) {
    $n = (int) $pdo->query($q)->fetchColumn();
    $orphanTotal += $n;
    printf("  %-20s %s\n", $k, $n === 0 ? '0건 ✅' : "{$n}건 ❌");
}

echo "\n" . ($left === 0 && $orphanTotal === 0
    ? "✅ QA 데이터 정리 완료 — 잔존 0건 · orphan 0건"
    : "❌ 잔존 {$left}건 · orphan {$orphanTotal}건 — 확인 필요") . "\n";
exit(($left === 0 && $orphanTotal === 0) ? 0 : 1);
