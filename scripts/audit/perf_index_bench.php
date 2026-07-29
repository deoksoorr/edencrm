<?php
/**
 * T8 성능 감사 — 인덱스 후보 전/후 실측 벤치.
 *
 * 실제 앱이 실행하는 쿼리(perf_probe/perf_slowq 로 채집)를 대상으로
 *   ① 인덱스 없이 N회 실행한 중앙값
 *   ② 후보 인덱스 생성 후 같은 쿼리 N회 실행한 중앙값 + EXPLAIN key
 *   ③ 인덱스 삭제(원복)
 * 을 측정한다. "인덱스를 무조건 추가하지 않는다" 원칙의 근거 자료.
 *
 * 측정 전용 DB(`*_perf`)에서만 동작한다.
 *
 * 사용: php scripts/audit/perf_index_bench.php --db eden_crm_perf
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
error_reporting(E_ALL & ~E_DEPRECATED);

$opts   = getopt('', ['db:', 'socket:', 'runs:']);
$dbName = $opts['db'] ?? 'eden_crm_perf';
$runs   = max(3, (int) ($opts['runs'] ?? 5));
$root   = dirname(__DIR__, 2);
$socket = $opts['socket'] ?? ($root . '/.devdb/mysql.sock');

if (!str_ends_with($dbName, '_perf')) {
    fwrite(STDERR, "[거부] --db 는 '_perf' 로 끝나야 한다 (측정 전용).\n"); exit(2);
}

$pdo = new PDO("mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function timeIt(PDO $pdo, string $sql, int $runs): float
{
    $t = [];
    for ($i = 0; $i < $runs; $i++) {
        $s = microtime(true);
        $pdo->query($sql)->fetchAll();
        $t[] = (microtime(true) - $s) * 1000;
    }
    sort($t);
    return $t[(int) floor(count($t) / 2)];
}

function explainKey(PDO $pdo, string $sql): string
{
    $out = [];
    foreach ($pdo->query('EXPLAIN FORMAT=TRADITIONAL ' . $sql)->fetchAll() as $e) {
        $out[] = ($e['table'] ?? '?') . ':' . ($e['type'] ?? '?') . '/' . ($e['key'] ?? '-') . '/r' . ($e['rows'] ?? '?');
    }
    return implode(' ', $out);
}

$m0 = date('Y-m-01'); $m1 = date('Y-m-t');

/** [설명, 대상테이블, 인덱스명, 인덱스정의, 벤치 쿼리] */
$CASES = [
    [
        '입금 기간 집계 (확정매출·입금 총액 — 대시보드/리포트 전 구간)',
        'payments', 'idx_payments_status_paid_date', '(status, paid_date)',
        "SELECT COALESCE(SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END),0)
           FROM payments pm WHERE pm.status='paid' AND pm.paid_date >= '{$m0}' AND pm.paid_date <= '{$m1}'",
    ],
    [
        '원가 기간 집계 (원가 총액 — 대시보드/리포트/반기)',
        'costs', 'idx_costs_status_type_date', '(cost_status, type, spent_date)',
        "SELECT COALESCE(SUM(cs.amount),0) FROM costs cs
           JOIN projects pj ON pj.id = cs.project_id AND pj.deleted_at IS NULL
          WHERE cs.type='actual' AND cs.cost_status='confirmed'
            AND cs.spent_date >= '{$m0}' AND cs.spent_date <= '{$m1}'",
    ],
    [
        '읽지 않은 알림 수 (모든 페이지 레이아웃에서 1회)',
        'notifications', 'idx_notifications_user_read', '(user_id, is_read)',
        "SELECT COUNT(*) FROM notifications WHERE user_id=1 AND is_read=0",
    ],
    [
        '감사 로그 목록 1페이지 (created_at DESC)',
        'audit_logs', 'idx_audit_logs_entity_created', '(entity, created_at)',
        "SELECT a.id, a.action, a.entity, a.entity_id, a.created_at FROM audit_logs a
          WHERE a.entity='project' ORDER BY a.created_at DESC LIMIT 20",
    ],
    [
        '공정 보드 — 프로젝트별 최근 일정 (윈도우 함수)',
        'schedules', 'idx_schedules_project_date', '(project_id, event_date)',
        "SELECT project_id, title FROM (
            SELECT s.project_id, s.title, ROW_NUMBER() OVER (PARTITION BY s.project_id ORDER BY s.event_date ASC, s.id) rn
              FROM schedules s WHERE s.project_id BETWEEN 1 AND 100
         ) t WHERE rn = 1",
    ],
    [
        '게이지 보드 진행률 벌크 조회 (project_id IN)',
        'project_stage_progress', 'idx_psp_project_pct', '(project_id, pct)',
        "SELECT project_id, stage_id, pct FROM project_stage_progress WHERE project_id BETWEEN 1 AND 100",
    ],
    [
        '프로젝트 목록 기본 정렬 (미삭제 + 최신순)',
        'projects', 'idx_projects_deleted_id', '(deleted_at, id)',
        "SELECT p.id, p.project_no, p.name, p.status FROM projects p
          WHERE p.deleted_at IS NULL ORDER BY p.id DESC LIMIT 20",
    ],
    [
        '계약 목록 기본 정렬 (미삭제 + 계약일 최신순)',
        'contracts', 'idx_contracts_deleted_date', '(deleted_at, contract_date)',
        "SELECT c.id, c.contract_no, c.contract_amount FROM contracts c
          WHERE c.deleted_at IS NULL ORDER BY c.contract_date DESC, c.id DESC LIMIT 20",
    ],
];

printf("%-58s %10s %10s %8s\n", '케이스', '전(ms)', '후(ms)', '배율');
echo str_repeat('-', 92) . "\n";

$detail = [];
foreach ($CASES as [$desc, $tbl, $idxName, $idxDef, $sql]) {
    // 이미 존재하면 스킵(중복 생성 방지)
    $exists = (int) $pdo->query("SELECT COUNT(*) c FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$tbl}' AND INDEX_NAME='{$idxName}'")->fetch()['c'];
    if ($exists) { $pdo->exec("DROP INDEX `{$idxName}` ON `{$tbl}`"); }

    $pdo->query("ANALYZE TABLE `{$tbl}`")->fetchAll();
    $before   = timeIt($pdo, $sql, $runs);
    $exBefore = explainKey($pdo, $sql);

    $pdo->exec("CREATE INDEX `{$idxName}` ON `{$tbl}` {$idxDef}");
    $pdo->query("ANALYZE TABLE `{$tbl}`")->fetchAll();
    $after   = timeIt($pdo, $sql, $runs);
    $exAfter = explainKey($pdo, $sql);
    $idxKb   = (int) round(((int) $pdo->query("SELECT COALESCE(SUM(stat_value*@@innodb_page_size),0) s
        FROM mysql.innodb_index_stats WHERE database_name=DATABASE() AND table_name='{$tbl}'
          AND index_name='{$idxName}' AND stat_name='size'")->fetch()['s']) / 1024);

    $pdo->exec("DROP INDEX `{$idxName}` ON `{$tbl}`");   // 원복 — 벤치는 스키마를 남기지 않는다
    $pdo->query("ANALYZE TABLE `{$tbl}`")->fetchAll();

    printf("%-58s %9.2f %9.2f %7.1fx\n", mb_strimwidth($desc, 0, 56, ''), $before, $after, $before / max(0.001, $after));
    $detail[] = compact('desc', 'tbl', 'idxName', 'idxDef', 'before', 'after', 'exBefore', 'exAfter', 'idxKb');
}

echo "\n=== EXPLAIN 상세 ===\n";
foreach ($detail as $d) {
    echo "\n[{$d['tbl']} {$d['idxDef']}]  {$d['desc']}\n";
    printf("  전 %.2f ms  %s\n", $d['before'], $d['exBefore']);
    printf("  후 %.2f ms  %s  (인덱스 %d KB)\n", $d['after'], $d['exAfter'], $d['idxKb']);
}
