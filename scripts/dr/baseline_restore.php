<?php
/**
 * DR 테스트 T6 — 복구본 측정 + 운영 기준과 자동 대조.
 *
 * 운영에 던졌던 것과 **완전히 같은 프로브**(scripts/dr/probe.php)를 복구본에 던진다.
 * 측정 도구가 같으므로 차이가 나면 원인은 데이터뿐이다.
 *
 * 사용: php scripts/dr/baseline_restore.php
 * 산출: docs/audit/dr/baseline_restore.json · docs/audit/dr/comparison.json
 */

require __DIR__ . '/probe.php';

if (PHP_SAPI !== 'cli') { exit(1); }

$root   = dirname(__DIR__, 2);
$conf   = require '/Users/deoksookim/Desktop/코드/claude code/eden_crm_restore_test/_dr/config.restore.php';
$db     = $conf['DB_NAME'];
$prefix = $conf['TBL_PREFIX'];

// 안전장치: 복구 DB 가 아니면 실행하지 않는다.
if (!str_ends_with($db, '_restore_test')) {
    fwrite(STDERR, "가드: 복구 DB 가 아님({$db}) — 중단\n");
    exit(9);
}

$pdo = new PDO(
    "mysql:unix_socket={$conf['DB_SOCKET']};dbname={$db};charset=utf8mb4",
    $conf['DB_USER'], $conf['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
// AUTO_INCREMENT 등 통계를 캐시가 아닌 실시간 값으로 읽는다.
$pdo->exec('SET SESSION information_schema_stats_expiry = 0');

$q = function (string $sql, array $params = []) use ($pdo): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
};

$t0 = microtime(true);
$restore = dr_probe($q, $db, $prefix);
$restore['meta'] = [
    'task' => 'T6', 'target' => 'restore', 'measured_at' => date('c'),
    'elapsed_sec' => round(microtime(true) - $t0, 2), 'db' => $db,
];
file_put_contents($root . '/docs/audit/dr/baseline_restore.json',
    json_encode($restore, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// ── 운영 기준과 대조 ──────────────────────────────────────────────────────
$prod = json_decode(file_get_contents($root . '/docs/audit/dr/baseline_prod.json'), true);
if (!$prod) { fwrite(STDERR, "운영 기준 파일 없음 — T1 을 먼저 실행\n"); exit(1); }

$diffs = [];
/** 값 하나를 대조한다. 금액은 문자열 그대로 비교한다(float 비교는 정밀도 손실을 감춘다). */
$cmp = function (string $label, $p, $r, string $severity = '치명') use (&$diffs) {
    $same = (is_scalar($p) || $p === null) ? ((string) $p === (string) $r) : ($p == $r);
    if (!$same) {
        $diffs[] = ['항목' => $label, '운영' => $p, '복구본' => $r, '심각도' => $severity];
    }
    return $same;
};

// 1) 구조
$cmp('테이블 수', $prod['inventory']['owned_base_tables'], $restore['inventory']['owned_base_tables']);
$cmp('뷰 수', count($prod['views']), count($restore['views']));
$cmp('트리거 수', count($prod['triggers']), count($restore['triggers']));
$cmp('FK 수', count($prod['foreign_keys']), count($restore['foreign_keys']));
$cmp('인덱스 수', count($prod['indexes']), count($restore['indexes']));
$cmp('컬럼 수', count($prod['columns']), count($restore['columns']));
$cmp('DECIMAL 컬럼 수', count($prod['decimal_columns']), count($restore['decimal_columns']));

// 2) 테이블 집합 (이름 단위)
$pt = array_map(fn($t) => $t['TABLE_NAME'], $prod['inventory']['owned']);
$rt = array_map(fn($t) => $t['TABLE_NAME'], $restore['inventory']['owned']);
sort($pt); sort($rt);
foreach (array_diff($pt, $rt) as $miss) $diffs[] = ['항목' => "테이블 누락", '운영' => $miss, '복구본' => '없음', '심각도' => '치명'];
foreach (array_diff($rt, $pt) as $extra) $diffs[] = ['항목' => "복구본 잉여 테이블", '운영' => '없음', '복구본' => $extra, '심각도' => '높음'];

// 3) 컬럼 정의 (타입·NULL 허용·기본값·collation) — DECIMAL 정밀도 손실 검출의 핵심
$colKey = fn($c) => $c['TABLE_NAME'] . '.' . $c['COLUMN_NAME'];
$pc = []; foreach ($prod['columns'] as $c) $pc[$colKey($c)] = $c;
$rc = []; foreach ($restore['columns'] as $c) $rc[$colKey($c)] = $c;
$colTypeDiff = 0;
foreach ($pc as $k => $c) {
    if (!isset($rc[$k])) { $diffs[] = ['항목' => "컬럼 누락", '운영' => $k, '복구본' => '없음', '심각도' => '치명']; continue; }
    $r = $rc[$k];
    // 정수 표시폭(int(10) → int)은 MySQL 9 가 제거한다. 금액·의미에 영향이 없으므로
    // 표시폭만 다른 경우는 차이로 세지 않는다. DECIMAL·문자열 길이는 그대로 비교한다.
    $norm = fn($t) => preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i', '$1', strtolower($t));
    if ($norm($c['COLUMN_TYPE']) !== $norm($r['COLUMN_TYPE'])) {
        $diffs[] = ['항목' => "컬럼 타입 불일치 $k", '운영' => $c['COLUMN_TYPE'], '복구본' => $r['COLUMN_TYPE'], '심각도' => '치명'];
        $colTypeDiff++;
    }
    if ($c['IS_NULLABLE'] !== $r['IS_NULLABLE']) {
        $diffs[] = ['항목' => "NULL 허용 불일치 $k", '운영' => $c['IS_NULLABLE'], '복구본' => $r['IS_NULLABLE'], '심각도' => '높음'];
    }
    if (($c['COLLATION_NAME'] ?? '') !== ($r['COLLATION_NAME'] ?? '')) {
        $diffs[] = ['항목' => "collation 불일치 $k", '운영' => $c['COLLATION_NAME'], '복구본' => $r['COLLATION_NAME'], '심각도' => '높음'];
    }
}

// 4) 테이블별 건수
//
// QA(T7·T8)를 돌리고 나면 복구본에만 자연히 늘어나는 표가 있다: 감사 로그와 로그인 기록.
// 이건 복구 결함이 아니라 테스트를 수행한 흔적이고, 정책상 지우지 않는다(§17).
// 다만 "늘어났으니 괜찮다"로 뭉개면 진짜 결손을 놓치므로, 아래 5)에서 원본 구간이
// 그대로 보존됐는지를 id 범위로 따로 검증한다.
// 이 두 표는 양쪽에서 독립적으로 늘어난다: 운영은 백업 이후에도 계속 append 되고,
// 복구본은 QA(T7·T8)를 돌리면 늘어난다. 어느 방향의 차이도 복구 결함이 아니므로
// 총건수로 판정하지 않고, 아래 9)의 id 구간 대조로 "백업 시점까지의 이력 온전성"을 본다.
$appendOnly = ['audit_logs' => '감사 로그', 'login_attempts' => '로그인 시도'];
foreach ($prod['counts'] as $t => $n) {
    $r = $restore['counts'][$t] ?? '없음';
    if (isset($appendOnly[$t])) continue;
    $cmp("건수 $t", $n, $r);
}

// 5) 소프트 삭제(휴지통) 분포
foreach ($prod['soft_delete'] as $t => $v) {
    $r = $restore['soft_delete'][$t] ?? ['alive' => '없음', 'trashed' => '없음'];
    $cmp("활성 $t", $v['alive'], $r['alive']);
    $cmp("휴지통 $t", $v['trashed'], $r['trashed']);
}

// 6) 회계 — 금액은 전부 문자열 비교
$pa = $prod['accounting']; $ra = $restore['accounting'];
foreach (['contract_amount', 'supply_amount', 'vat_amount', 'down_payment', 'middle_payment', 'balance_payment', 'cnt'] as $k) {
    $cmp("계약 $k", $pa['contracts'][$k], $ra['contracts'][$k]);
}
$cmp('순입금(paid)', $pa['payments_net']['net'], $ra['payments_net']['net']);
$cmp('확정지출', $pa['costs_confirmed']['amt'], $ra['costs_confirmed']['amt']);
$cmp('견적항목 합계', $pa['quote_items']['amt'], $ra['quote_items']['amt']);
foreach (['receivable', 'confirmed_revenue_supply', 'fully_paid_contracts', 'valid_contracts', 'net_paid_on_contracts'] as $k) {
    $cmp("파생 $k", $pa['derived'][$k], $ra['derived'][$k]);
}
// 계약 단위 원장 — 합계가 같아도 계약별로 어긋날 수 있어 행 단위로 본다.
$pl = []; foreach ($pa['per_contract'] as $r) $pl[$r['id']] = $r;
$rl = []; foreach ($ra['per_contract'] as $r) $rl[$r['id']] = $r;
foreach ($pl as $id => $r) {
    if (!isset($rl[$id])) { $diffs[] = ['항목' => "계약 누락 id=$id", '운영' => $r['contract_no'], '복구본' => '없음', '심각도' => '치명']; continue; }
    foreach (['contract_amount', 'supply_amount', 'net_paid', 'status', 'payment_status'] as $f) {
        $cmp("계약 {$r['contract_no']} $f", $r[$f], $rl[$id][$f]);
    }
}

// 7) 계정·권한
$cmp('권한행 user_permissions', $prod['accounts']['perm_rows']['user_permissions'], $restore['accounts']['perm_rows']['user_permissions']);
$cmp('권한행 role_permissions', $prod['accounts']['perm_rows']['role_permissions'], $restore['accounts']['perm_rows']['role_permissions']);
$cmp('권한행 employee_permissions', $prod['accounts']['perm_rows']['employee_permissions'], $restore['accounts']['perm_rows']['employee_permissions']);
$cmp('삭제 계정 수', $prod['accounts']['deleted'], $restore['accounts']['deleted']);
$cmp('잠긴 계정 수', $prod['accounts']['locked'], $restore['accounts']['locked']);
$pr = json_encode($prod['accounts']['by_role'], JSON_UNESCAPED_UNICODE);
$rr = json_encode($restore['accounts']['by_role'], JSON_UNESCAPED_UNICODE);
$cmp('역할별 계정 분포', $pr, $rr);
$ph = json_encode($prod['accounts']['hash_algo'], JSON_UNESCAPED_UNICODE);
$rh = json_encode($restore['accounts']['hash_algo'], JSON_UNESCAPED_UNICODE);
$cmp('비밀번호 해시 분포', $ph, $rh);

// 8) 무결성
foreach ($prod['orphans'] as $k => $n) $cmp("orphan $k", $n, $restore['orphans'][$k] ?? '없음');
foreach ($prod['duplicates'] as $k => $n) $cmp("중복 $k", $n, $restore['duplicates'][$k] ?? '없음');

// 9) 감사 로그 — 회계 감사 추적성의 근거라 가장 엄격하게 본다.
//
// QA 를 돌리면 뒤에 새 로그가 붙으므로 총건수 비교는 의미가 없다. 확인해야 할 것은
// **백업 시점까지의 원본 구간이 한 건도 빠짐없이 그대로인가**다. 운영 max_id 이하
// 구간의 건수와 id 경계를 대조하면 그게 검증된다.
// 양쪽에 공통으로 존재하는 id 구간을 버킷 단위로 대조한다.
// 물리 삭제가 없는 한 같은 id 구간의 건수는 반드시 같다 — 시점 드리프트와 무관하게
// "백업 시점까지의 이력이 한 건도 빠지지 않았는가"를 판정할 수 있다.
$pb = []; foreach ($prod['audit']['id_buckets'] ?? [] as $b) $pb[(int) $b['bucket']] = (int) $b['c'];
$rb = []; foreach ($restore['audit']['id_buckets'] ?? [] as $b) $rb[(int) $b['bucket']] = (int) $b['c'];
$prodMaxId    = (int) $prod['audit']['range']['max_id'];
$restoreMaxId = (int) $restore['audit']['range']['max_id'];
// 마지막 버킷은 양쪽 다 진행 중일 수 있으므로 제외하고, 완결된 구간만 비교한다.
$safeBound = (int) (floor(min($prodMaxId, $restoreMaxId) / 100) * 100);
$bucketChecked = $bucketMismatch = 0;
foreach ($pb as $bucket => $n) {
    if ($bucket >= $safeBound) continue;
    $bucketChecked++;
    if (($rb[$bucket] ?? -1) !== $n) {
        $bucketMismatch++;
        $diffs[] = ['항목' => "감사로그 id {$bucket}~" . ($bucket + 99) . " 건수",
                    '운영' => $n, '복구본' => $rb[$bucket] ?? '없음', '심각도' => '치명'];
    }
}
$cmp('감사로그 min_id', $prod['audit']['range']['min_id'], $restore['audit']['range']['min_id']);
$origInRestore = (int) $restore['audit']['total'];
// 원본 구간의 내용까지 동일한가 — 액션별 분포를 지문처럼 비교한다.
//
// 주의: `ORDER BY c DESC` 는 동률에서 순서가 비결정적이라 MariaDB 와 MySQL 이 다르게
// 정렬한다(실제로 login/project_status_change 가 각 54건으로 동률이었다). 순서 차이를
// 데이터 차이로 오판하지 않도록 액션명 기준 맵으로 바꿔서 비교한다.
$toMap = function (array $rows): array {
    $m = [];
    foreach ($rows as $r) $m[$r['action']] = (int) $r['c'];
    ksort($m);
    return $m;
};
// 액션 분포도 공통 구간(safeBound 미만)으로 한정해야 드리프트에 흔들리지 않는다.
// 운영 쪽 분포는 JSON 에 전체 기준으로만 있으므로, 여기서는 복구본이 그 구간에서
// 운영에 없는 액션을 만들어내지 않았는지(부분집합인지)를 본다.
$restOrigActions = $toMap($q(
    "SELECT action, COUNT(*) AS c FROM `{$prefix}audit_logs` WHERE id < ?
      GROUP BY action", [$safeBound]));
$prodActionNames = array_keys($toMap($prod['audit']['by_action']));
$unknown = array_diff(array_keys($restOrigActions), $prodActionNames);
if ($unknown) {
    $diffs[] = ['항목' => '감사로그 원본구간에 운영에 없는 액션',
                '운영' => '없음', '복구본' => implode(',', $unknown), '심각도' => '높음'];
}

// QA 로 늘어난 부분은 정보로만 남긴다.


// 10) 한글 무결성 — HEX 바이트로 비교(화면상 같아 보여도 인코딩이 다를 수 있다)
$psam = []; foreach ($prod['charset_sample'] as $r) $psam[$r['id']] = $r;
foreach ($restore['charset_sample'] as $r) {
    if (!isset($psam[$r['id']])) continue;
    $cmp("한글바이트 customers#{$r['id']}", $psam[$r['id']]['name_hex'], $r['name_hex']);
}

// 11) 첨부파일 레코드
$cmp('첨부 레코드 수', $prod['files']['project_files_count'], $restore['files']['project_files_count']);
$cmp('사업자등록증 연결', $prod['files']['biz_license_linked'], $restore['files']['biz_license_linked']);

// 12) AUTO_INCREMENT — 값이 아니라 "기능적 안전성"으로 판정한다.
//
// 운영 카운터는 백업 이후에도 계속 전진한다(롤백·삭제로 소비된 값은 InnoDB 가 되돌리지
// 않는다). 따라서 운영 현재값과 복구본이 다른 것 자체는 복구 실패가 아니라 시점 차이다.
// 실제로 문제가 되는 조건은 딱 하나: 복구본의 카운터가 그 테이블 MAX(id) 이하이면
// 다음 INSERT 가 PK 충돌을 일으킨다. 그것만 치명으로 본다.
$pai = []; foreach ($prod['inventory']['owned'] as $t) $pai[$t['TABLE_NAME']] = $t['AUTO_INCREMENT'];
$rai = []; foreach ($restore['inventory']['owned'] as $t) $rai[$t['TABLE_NAME']] = $t['AUTO_INCREMENT'];
$aiMismatch = [];
foreach ($pai as $t => $v) {
    $rv = $rai[$t] ?? null;
    if ((string) $v === (string) $rv) continue;

    $short = substr($t, strlen($prefix));
    $rows  = $restore['counts'][$short] ?? 0;

    // 복구본에서 실제 다음 id 가 안전한지 확인한다.
    $maxId = 0;
    try {
        $r = $q("SELECT COALESCE(MAX(`id`),0) AS m FROM `$t`");
        $maxId = (int) $r[0]['m'];
    } catch (PDOException $e) {
        $maxId = -1;   // id 컬럼이 없는 테이블(복합 PK) — 카운터 무관
    }
    $effective = $rv === null ? 1 : (int) $rv;   // 절이 생략된 빈 테이블은 다음 id 가 1
    $collides  = ($maxId >= 0 && $effective <= $maxId);

    $sev = $collides ? '치명' : '정보';
    $aiMismatch[] = [
        '테이블' => $t, '운영' => $v, '복구본' => $rv,
        '복구본_다음id' => $effective, '복구본_MAXid' => $maxId,
        '행수' => $rows, '심각도' => $sev,
        '해석' => $collides ? 'PK 충돌 위험' : '백업 시점 차이(다음 id 안전)',
    ];
    if ($collides) {
        $diffs[] = ['항목' => "AUTO_INCREMENT 충돌 $t", '운영' => $v, '복구본' => $rv, '심각도' => '치명'];
    }
}

// ── 결과 ──────────────────────────────────────────────────────────────────
$bySev = [];
foreach ($diffs as $d) $bySev[$d['심각도']] = ($bySev[$d['심각도']] ?? 0) + 1;

$report = [
    'compared_at'      => date('c'),
    'prod_measured_at' => $prod['meta']['measured_at'],
    'restore_source'   => 'proddb_audit_pre_20260729-013710.sql (복구변환본)',
    'checks_total'     => 'cmp 호출 기준',
    'diff_count'       => count($diffs),
    'diff_by_severity' => $bySev,
    'diffs'            => $diffs,
    'auto_increment_detail' => $aiMismatch,
];
file_put_contents($root . '/docs/audit/dr/comparison.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// ── 콘솔 요약 ─────────────────────────────────────────────────────────────
echo "== T6 운영 ↔ 복구본 대조 ==\n";
printf("운영 측정 %s / 복구본 측정 %s\n", $prod['meta']['measured_at'], $restore['meta']['measured_at']);
printf("구조: 테이블 %d↔%d · FK %d↔%d · 인덱스 %d↔%d · 컬럼 %d↔%d · DECIMAL %d↔%d\n",
    $prod['inventory']['owned_base_tables'], $restore['inventory']['owned_base_tables'],
    count($prod['foreign_keys']), count($restore['foreign_keys']),
    count($prod['indexes']), count($restore['indexes']),
    count($prod['columns']), count($restore['columns']),
    count($prod['decimal_columns']), count($restore['decimal_columns']));
printf("데이터: 총행 %d↔%d · 감사로그 %d↔%d\n",
    array_sum($prod['counts']), array_sum($restore['counts']),
    $prod['audit']['total'], $restore['audit']['total']);
printf("회계: 계약총액 %s↔%s · 순입금 %s↔%s · 미수금 %s↔%s\n",
    $pa['contracts']['contract_amount'], $ra['contracts']['contract_amount'],
    $pa['payments_net']['net'], $ra['payments_net']['net'],
    $pa['derived']['receivable'], $ra['derived']['receivable']);
echo "\n";
printf("감사로그: 총 운영 %d / 복구본 %d — id 구간 %d개 대조, 불일치 %d건 (공통구간 id<%d)\n",
    $prod['audit']['total'], $restore['audit']['total'], $bucketChecked, $bucketMismatch, $safeBound);
printf("  ※ 총건수 차이는 판정 대상이 아니다 — 운영은 백업 이후 append, 복구본은 QA 로 증가한다\n\n");
if (!$diffs) {
    echo "✅ 불일치 0건 — 구조·데이터·회계·권한·무결성 전 항목 일치\n";
} else {
    printf("⚠ 불일치 %d건: %s\n", count($diffs), json_encode($bySev, JSON_UNESCAPED_UNICODE));
    foreach (array_slice($diffs, 0, 25) as $d) {
        printf("  [%s] %s — 운영 %s / 복구본 %s\n", $d['심각도'], $d['항목'],
            is_scalar($d['운영']) ? $d['운영'] : json_encode($d['운영'], JSON_UNESCAPED_UNICODE),
            is_scalar($d['복구본']) ? $d['복구본'] : json_encode($d['복구본'], JSON_UNESCAPED_UNICODE));
    }
    if (count($diffs) > 25) printf("  … 외 %d건 (comparison.json 참조)\n", count($diffs) - 25);
}
if ($aiMismatch) {
    $crit = count(array_filter($aiMismatch, fn($a) => $a['심각도'] === '치명'));
    printf("\nAUTO_INCREMENT 차이 %d건 (치명 %d · 시점차이 %d):\n",
        count($aiMismatch), $crit, count($aiMismatch) - $crit);
    foreach ($aiMismatch as $a) {
        printf("  [%s] %-34s 운영 %-4s → 복구본 %-4s · 다음id %-4s > MAXid %-4s · %s\n",
            $a['심각도'], $a['테이블'], $a['운영'] ?? '-', $a['복구본'] ?? 'NULL',
            $a['복구본_다음id'], $a['복구본_MAXid'], $a['해석']);
    }
}
