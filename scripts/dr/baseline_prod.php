<?php
/**
 * DR 테스트 T1 — 운영 기준 상태 기록 (읽기 전용).
 *
 * 운영에는 어떤 변경도 가하지 않는다. RO 가드 자체 점검을 먼저 통과해야 본 측정이
 * 시작되며, 측정 전체가 READ ONLY 트랜잭션(=서버 강제 + 일관 스냅샷) 안에서 돈다.
 *
 * 사용: php scripts/dr/baseline_prod.php
 * 산출: docs/audit/dr/baseline_prod.json  (비밀값·해시원문·개인정보 미포함)
 */

require __DIR__ . '/ro_guard.php';
require __DIR__ . '/probe.php';

$root = dirname(__DIR__, 2);
$env  = dr_env($root . '/deploy/cafe24.env');
$db     = $env['DB_NAME'];
$prefix = $env['TBL_PREFIX'] ?: 'edencrm_';

fwrite(STDERR, "[T1] 운영 접속(읽기 전용) 시도…\n");
$t0  = microtime(true);
$pdo = dr_connect_readonly($env);

// ── 가드 자체 점검 ────────────────────────────────────────────────────────
// 가드가 동작하지 않으면 측정을 시작하지 않는다.
$selftest = ro_selftest($pdo);
foreach ($selftest as $s) {
    if (!$s['blocked']) {
        fwrite(STDERR, "치명: RO 가드가 '{$s['sql']}' 를 차단하지 못함 — 중단\n");
        exit(2);
    }
}
fwrite(STDERR, "[T1] RO 가드 자체점검 " . count($selftest) . "/" . count($selftest) . " 차단 확인\n");

// 서버 측 강제도 실제로 걸렸는지 확인한다(가드를 우회해 직접 쓰기 시도).
$serverEnforced = false;
try {
    $pdo->exec("CREATE TEMPORARY TABLE dr_ro_probe_check (i INT)");
} catch (PDOException $e) {
    $serverEnforced = true;   // READ ONLY 트랜잭션이 DDL/쓰기를 거부함
}
fwrite(STDERR, "[T1] 서버 강제 읽기전용: " . ($serverEnforced ? '확인' : '미확인(주의)') . "\n");

// ── 본 측정 ───────────────────────────────────────────────────────────────
$q = fn(string $sql, array $params = []) => ro($pdo, $sql, $params);

$result = dr_probe($q, $db, $prefix);
$elapsed = round(microtime(true) - $t0, 2);

// ── 백업 커버리지 갭 판정 ─────────────────────────────────────────────────
// 백업은 SHOW TABLES LIKE 'edencrm_%' 만 대상으로 한다. 따라서
//  (a) prefix 밖에 eden 테이블이 있으면 → 영원히 백업되지 않는다
//  (b) prefix 안에 뷰가 있으면 → SHOW CREATE TABLE 이 깨져 덤프가 실패한다
//  (c) 트리거·프로시저는 애초에 덤프 대상이 아니다 → 존재하면 무조건 유실
$result['coverage_gap'] = [
    'views_in_prefix'    => count($result['views']),
    'triggers_in_prefix' => count($result['triggers']),
    'routines_in_schema' => count($result['routines']),
    'backup_covers'      => $result['inventory']['owned_base_tables'],
    'note' => 'db_dump.php 는 prefix 일치 BASE TABLE 만 덤프한다. 뷰·트리거·루틴은 백업 대상 밖.',
];

$result['meta'] = [
    'task'            => 'T1',
    'target'          => 'production',
    'measured_at'     => date('c'),
    'elapsed_sec'     => $elapsed,
    'ro_guard'        => ['app_blocked' => count($selftest), 'server_enforced' => $serverEnforced],
    'atomic_snapshot' => true,   // READ ONLY 트랜잭션 — 측정 중 시점 고정
];

// 트랜잭션은 커밋하지 않고 되돌린다(읽기 전용이라 변경분은 없지만 명시적으로 정리).
$pdo->rollBack();

// 출력 경로를 인자로 받는다. 주간 자동 복구검증이 이 스크립트를 돌릴 때
// 2026-07-29 DR 테스트의 증거 파일을 덮어쓰지 않게 하기 위함이다.
$outFile = $argv[1] ?? ($root . '/docs/audit/dr/baseline_prod.json');
file_put_contents($outFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// ── 콘솔 요약 ─────────────────────────────────────────────────────────────
printf("\n== 운영 기준 상태 (%s) ==\n", $result['meta']['measured_at']);
printf("서버        : %s (%s)\n", $result['env']['version'], $result['env']['comment']);
printf("스키마 charset: %s / %s\n", $result['env']['cs'], $result['env']['coll']);
printf("시간대       : %s · 서버시각 %s\n", $result['env']['tz'], $result['env']['now_at']);
printf("스키마 총 객체: %d (eden 소유 %d = 테이블 %d + 뷰 %d, 타 프로젝트 %d)\n",
    $result['inventory']['objects_total'], $result['inventory']['owned_total'],
    $result['inventory']['owned_base_tables'], $result['inventory']['owned_views'],
    $result['inventory']['foreign_object_count']);
printf("트리거 %d · 루틴 %d · FK %d · 인덱스 %d · DECIMAL 컬럼 %d\n",
    count($result['triggers']), count($result['routines']), count($result['foreign_keys']),
    count($result['indexes']), count($result['decimal_columns']));
printf("총 데이터 행  : %d\n", array_sum($result['counts']));
printf("감사로그      : %d 건 (id %s~%s)\n", $result['audit']['total'],
    $result['audit']['range']['min_id'], $result['audit']['range']['max_id']);
$d = $result['accounting']['derived'];
printf("계약총액 %s · 공급가 %s · 순입금(paid) %s · 확정지출 %s\n",
    number_format((float) $result['accounting']['contracts']['contract_amount']),
    number_format((float) $result['accounting']['contracts']['supply_amount']),
    number_format((float) $result['accounting']['payments_net']['net']),
    number_format((float) $result['accounting']['costs_confirmed']['amt']));
printf("미수금 %s · 확정매출(공급가) %s · 완납 %d/%d 계약\n",
    number_format((float) $d['receivable']),
    number_format((float) $d['confirmed_revenue_supply']),
    $d['fully_paid_contracts'], $d['valid_contracts']);
printf("커버리지 갭  : prefix밖 동명테이블 %d · 뷰 %d · 트리거 %d · 루틴 %d\n",
    count($result['inventory']['unprefixed_twins']), count($result['views']),
    count($result['triggers']), count($result['routines']));
printf("첨부파일 레코드: %d (사업자등록증 연결 %d)\n",
    $result['files']['project_files_count'], $result['files']['biz_license_linked']);
printf("잠긴 계정 %d · 실패시도>0 %d\n",
    $result['accounts']['locked'], $result['accounts']['failed_gt0']);
$orphanSum = array_sum($result['orphans']);
printf("orphan 합계  : %d · 중복 합계: %d\n", $orphanSum, array_sum($result['duplicates']));
printf("소요 %.2fs → %s\n", $elapsed, $outFile);
