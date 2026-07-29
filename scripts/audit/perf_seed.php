<?php
/**
 * T8 성능 감사 — 합성 데이터 시더 (측정 전용).
 *
 * 목적: 로컬/운영 DB 모두 업무 데이터가 사실상 비어 있어(운영 최대 412행) 실제 부하를
 *       측정할 수 없다. 별도 DB(`*_perf`)에 두 가지 규모의 데이터를 생성해
 *         S1 = 현재 운영 규모(≈2026-07 실측)
 *         S2 = 성장 규모(payments/costs/audit_logs 10k+)
 *       를 동일 코드 경로로 비교 측정한다.
 *
 * 안전장치
 *  - DB 이름이 `_perf` 로 끝나지 않으면 즉시 종료 (운영/로컬 개발 DB 오염 방지)
 *  - 운영 접속정보(deploy/cafe24.env)는 읽지 않는다 — 로컬 소켓 전용
 *
 * 사용:
 *   php scripts/audit/perf_seed.php --db eden_crm_perf --scale s1
 *   php scripts/audit/perf_seed.php --db eden_crm_perf --scale s2
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

$opts  = getopt('', ['db:', 'scale:', 'socket:']);
$dbName = $opts['db']   ?? 'eden_crm_perf';
$scale  = $opts['scale'] ?? 's1';
$root   = dirname(__DIR__, 2);
$socket = $opts['socket'] ?? ($root . '/.devdb/mysql.sock');

if (!str_ends_with($dbName, '_perf')) {
    fwrite(STDERR, "[거부] --db 는 '_perf' 로 끝나야 한다 (측정 전용 DB). 받은 값: {$dbName}\n");
    exit(2);
}
if (!file_exists($socket)) {
    fwrite(STDERR, "[거부] 로컬 소켓 없음: {$socket}\n");
    exit(2);
}

$pdo = new PDO("mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

// ── 규모 정의 ───────────────────────────────────────────────────────────────
// S1: 2026-07-29 운영 실측(edencrm_*) 근사치.
// S2: 연 300건 계약 × 3년 + 감사로그 누적 을 가정한 성장 시나리오.
$SCALES = [
    's1' => [
        'customers' => 12,   'leads' => 6,     'quotes' => 8,    'contracts' => 6,
        'projects'  => 12,   'payments_per_c' => 3, 'costs_per_p' => 4,
        'audit'     => 500,  'schedules' => 20, 'notifications' => 40,
    ],
    's2' => [
        'customers' => 3000, 'leads' => 2000,  'quotes' => 4000, 'contracts' => 3000,
        'projects'  => 3000, 'payments_per_c' => 4, 'costs_per_p' => 10,
        'audit'     => 200000, 'schedules' => 20000, 'notifications' => 20000,
    ],
];
if (!isset($SCALES[$scale])) { fwrite(STDERR, "[거부] --scale 은 s1|s2\n"); exit(2); }
$N = $SCALES[$scale];

$t0 = microtime(true);
echo "[perf_seed] db={$dbName} scale={$scale}\n";

// ── 초기화: 업무 데이터만 비운다(users/roles/permissions/stages/settings 는 보존) ──
$wipe = [
    'goal_history', 'goals', 'site_bonus_history', 'site_bonuses',
    'work_log_photos', 'work_logs', 'attendance_marks',
    'schedule_participants', 'schedule_time_slots', 'schedules',
    'project_memos', 'project_stage_progress', 'project_process_history',
    'project_status_history', 'project_assignments', 'warranty_repairs',
    'project_files', 'costs', 'notifications', 'audit_logs',
    'payments', 'contract_terminations', 'contract_status_history', 'contracts',
    'quote_items', 'quote_versions', 'quotes', 'leads',
    'customer_activities', 'customer_contacts', 'projects', 'customers',
    'targets', 'company_targets',
];
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($wipe as $t) { $pdo->exec("TRUNCATE TABLE `{$t}`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
echo "  - 업무 테이블 초기화 완료\n";

$userIds  = array_column($pdo->query('SELECT id FROM users')->fetchAll(), 'id');
$stageIds = array_column($pdo->query('SELECT id FROM pipeline_stages ORDER BY sort_order')->fetchAll(), 'id');
$procAll  = $pdo->query("SELECT id, process_type, stage_key FROM process_stages WHERE is_active=1 ORDER BY sort_order")->fetchAll();
$procByType = ['painting' => [], 'interior' => []];
foreach ($procAll as $p) { $procByType[$p['process_type']][] = (int) $p['id']; }
if (!$procByType['painting']) { $procByType['painting'] = array_column($procAll, 'id'); }
if (!$procByType['interior']) { $procByType['interior'] = $procByType['painting']; }

mt_srand(20260729); // 재현 가능

/** 대량 INSERT 헬퍼 — 1000행 단위 배치 */
function bulk(PDO $pdo, string $table, array $cols, iterable $rowGen, int $chunk = 1000): int
{
    $colSql = '`' . implode('`,`', $cols) . '`';
    $ph     = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $buf = []; $n = 0;
    $flush = function () use (&$buf, $pdo, $table, $colSql, $ph, &$n) {
        if (!$buf) { return; }
        $sql = "INSERT INTO `{$table}` ({$colSql}) VALUES " . implode(',', array_fill(0, count($buf), $ph));
        $flat = [];
        foreach ($buf as $r) { foreach ($r as $v) { $flat[] = $v; } }
        $pdo->prepare($sql)->execute($flat);
        $n += count($buf);
        $buf = [];
    };
    foreach ($rowGen as $row) {
        $buf[] = $row;
        if (count($buf) >= $chunk) { $flush(); }
    }
    $flush();
    return $n;
}

function d(int $daysAgo): string { return date('Y-m-d', strtotime("-{$daysAgo} days")); }
function dt(int $daysAgo): string { return date('Y-m-d H:i:s', strtotime("-{$daysAgo} days")); }

// ── customers ───────────────────────────────────────────────────────────────
$src   = ['홈페이지', '블로그', '소개', '전화', '현수막', '재계약'];
$itype = ['아파트도장', '상가도장', '공장도장', '방수', '인테리어'];
$n = bulk($pdo, 'customers',
    ['type','is_business','name','company_name','phone','email','address','site_address','source','interest_type','expected_budget','sales_user_id','status','memo','created_at'],
    (function () use ($N, $userIds, $src, $itype) {
        for ($i = 1; $i <= $N['customers']; $i++) {
            $biz = $i % 3 === 0;
            yield [
                $biz ? 'company' : 'individual', $biz ? 1 : 0,
                "고객{$i}", $biz ? "㈜테스트{$i}" : null,
                sprintf('010-%04d-%04d', $i % 10000, ($i * 7) % 10000),
                "cust{$i}@example.com", "서울시 강남구 테헤란로 {$i}", "현장주소 {$i}",
                $src[$i % 6], $itype[$i % 5], (500000 + ($i % 50) * 100000),
                $userIds[$i % count($userIds)], 'active',
                str_repeat("메모 {$i}. ", 8), dt(($i % 700) + 1),
            ];
        }
    })());
echo "  - customers: {$n}\n";
$custIds = array_column($pdo->query('SELECT id FROM customers')->fetchAll(), 'id');

// ── leads ───────────────────────────────────────────────────────────────────
$n = bulk($pdo, 'leads',
    ['customer_id','sales_user_id','stage_id','work_type','site_address','expected_amount','expected_cost','win_probability','expected_profit','next_contact_date','stage_entered_at','memo','created_at'],
    (function () use ($N, $custIds, $userIds, $stageIds, $itype) {
        for ($i = 1; $i <= $N['leads']; $i++) {
            $amt = (2000000 + ($i % 200) * 350000);
            $cost = (int) ($amt * 0.62);
            yield [
                $custIds[$i % count($custIds)], $userIds[$i % count($userIds)],
                $stageIds[$i % count($stageIds)], $itype[$i % 5], "현장 {$i}",
                $amt, $cost, ($i % 100), $amt - $cost, d($i % 60),
                dt(($i % 300) + 1), "리드 메모 {$i}", dt(($i % 300) + 2),
            ];
        }
    })());
echo "  - leads: {$n}\n";
$leadIds = array_column($pdo->query('SELECT id FROM leads')->fetchAll(), 'id');

// ── quotes + versions + items ───────────────────────────────────────────────
$qstat = ['draft','sent','accepted','rejected','expired'];
$n = bulk($pdo, 'quotes',
    ['quote_no','lead_id','customer_id','status','valid_until','memo','created_at'],
    (function () use ($N, $custIds, $leadIds, $qstat) {
        for ($i = 1; $i <= $N['quotes']; $i++) {
            yield [
                sprintf('Q-2026-%06d', $i),
                $leadIds ? $leadIds[$i % count($leadIds)] : null,
                $custIds[$i % count($custIds)],
                $qstat[$i % 5], d(-30 + ($i % 30)), "견적 메모 {$i}", dt(($i % 400) + 1),
            ];
        }
    })());
echo "  - quotes: {$n}\n";
$quoteIds = array_column($pdo->query('SELECT id FROM quotes ORDER BY id')->fetchAll(), 'id');

$n = bulk($pdo, 'quote_versions',
    ['quote_id','version_no','subtotal','vat','discount','total_amount','note','created_by','created_at'],
    (function () use ($quoteIds) {
        $i = 0;
        foreach ($quoteIds as $qid) {
            $i++;
            $sub  = (3000000 + ($i % 300) * 420000);
            $vat  = (int) ($sub * 0.1);
            yield [$qid, 1, $sub, $vat, 0, $sub + $vat, "버전 비고 {$i}", 1, dt(($i % 400) + 1)];
        }
    })());
echo "  - quote_versions: {$n}\n";
$pdo->exec('UPDATE quotes q JOIN quote_versions v ON v.quote_id = q.id SET q.current_version_id = v.id');

$vRows = $pdo->query('SELECT id, quote_id FROM quote_versions')->fetchAll();
$n = bulk($pdo, 'quote_items',
    ['quote_version_id','sort_order','name','area','qty','unit_price','material_cost','labor_cost','equipment_cost','outsourcing_cost','etc_cost','amount'],
    (function () use ($vRows) {
        foreach ($vRows as $v) {
            for ($k = 1; $k <= 4; $k++) {
                $qty = 10 * $k; $up = 25000 * $k; $amt = $qty * $up;
                yield [$v['id'], $k, "품목 {$k}", 50 * $k, $qty, $up,
                       (int) ($amt * 0.4), (int) ($amt * 0.3), (int) ($amt * 0.1),
                       (int) ($amt * 0.1), (int) ($amt * 0.05), $amt];
            }
        }
    })());
echo "  - quote_items: {$n}\n";

// ── contracts ───────────────────────────────────────────────────────────────
$cstat = ['draft','active','active','completed','on_hold','cancelled'];
$pstat = ['unpaid','partial','paid'];
$n = bulk($pdo, 'contracts',
    ['contract_no','quote_id','customer_id','contract_date','contract_amount','supply_amount','vat_amount','down_payment','middle_payment','balance_payment','start_date','end_date','status','payment_status','work_name','site_address','work_type','construction_type','sales_user_id','memo','created_at'],
    (function () use ($N, $quoteIds, $custIds, $userIds, $cstat, $pstat, $itype) {
        for ($i = 1; $i <= $N['contracts']; $i++) {
            $supply = (4000000 + ($i % 400) * 380000);
            $vat    = (int) ($supply * 0.1);
            $total  = $supply + $vat;
            yield [
                sprintf('C-2026-%06d', $i),
                $quoteIds ? $quoteIds[$i % count($quoteIds)] : null,
                $custIds[$i % count($custIds)], d(($i % 900) + 1),
                $total, $supply, $vat,
                (int) ($total * 0.3), (int) ($total * 0.4), $total - (int) ($total * 0.3) - (int) ($total * 0.4),
                d(($i % 900) - 10), d(($i % 900) - 40),
                $cstat[$i % 6], $pstat[$i % 3],
                "공사 {$i}", "현장주소 {$i}", $itype[$i % 5],
                $i % 4 === 0 ? 'interior' : 'painting',
                $userIds[$i % count($userIds)], "계약 메모 {$i}", dt(($i % 900) + 1),
            ];
        }
    })());
echo "  - contracts: {$n}\n";
$conRows = $pdo->query('SELECT id, contract_amount, construction_type, customer_id, sales_user_id, contract_date FROM contracts ORDER BY id')->fetchAll();

// ── payments (계약 기준) ────────────────────────────────────────────────────
$n = bulk($pdo, 'payments',
    ['contract_id','pay_type','method','kind','amount','due_date','paid_date','status','memo','payer_name','created_by','created_at'],
    (function () use ($conRows, $N) {
        $types = ['down','middle','balance','etc'];
        $i = 0;
        foreach ($conRows as $c) {
            $i++;
            for ($k = 0; $k < $N['payments_per_c']; $k++) {
                $paid = ($i + $k) % 4 !== 0;
                yield [
                    $c['id'], $types[$k % 4], 'transfer', 'payment',
                    (int) ($c['contract_amount'] / max(1, $N['payments_per_c'])),
                    d((($i * 3 + $k) % 800)), $paid ? d((($i * 3 + $k) % 800)) : null,
                    $paid ? 'paid' : 'pending', "입금 {$i}-{$k}", '입금자', 1,
                    dt((($i * 3 + $k) % 800) + 1),
                ];
            }
        }
    })());
echo "  - payments: {$n}\n";

// ── projects ────────────────────────────────────────────────────────────────
$prjStat = ['preparing','in_progress','in_progress','completed','warranty','settled','paused'];
$setStat = ['unsettled','partial','settled'];
$n = bulk($pdo, 'projects',
    ['project_no','name','customer_id','is_exception','contract_id','site_address','work_type','construction_type','contract_amount','supply_amount','vat_amount','estimated_cost','actual_cost','process_stage_id','process_entered_at','status','settlement_status','contract_date','start_date','end_date','sales_user_id','site_manager_id','progress','memo','created_at'],
    (function () use ($N, $conRows, $custIds, $userIds, $procByType, $prjStat, $setStat, $itype) {
        for ($i = 1; $i <= $N['projects']; $i++) {
            $c    = $conRows ? $conRows[($i - 1) % count($conRows)] : null;
            // 계약 1:1 (uq_projects_contract) — 계약 수를 넘어가면 예외 프로젝트로 만든다
            $useC = ($c && $i <= count($conRows));
            $ctype = $useC ? ($c['construction_type'] ?: 'painting') : ($i % 4 === 0 ? 'interior' : 'painting');
            $pool  = $procByType[$ctype] ?: $procByType['painting'];
            $total = $useC ? (int) $c['contract_amount'] : (3000000 + ($i % 300) * 400000);
            $supply = (int) round($total / 1.1);
            yield [
                sprintf('P-2026-%06d', $i), "프로젝트 {$i}",
                $useC ? $c['customer_id'] : $custIds[$i % count($custIds)],
                $useC ? 0 : 1, $useC ? $c['id'] : null,
                "현장주소 {$i}", $itype[$i % 5], $ctype,
                $total, $supply, $total - $supply,
                (int) ($supply * 0.6), (int) ($supply * 0.55),
                $pool[$i % count($pool)], dt(($i % 400) + 1),
                $prjStat[$i % 7], $setStat[$i % 3],
                d(($i % 900) + 1), d(($i % 900) - 5), d(($i % 900) - 45),
                $userIds[$i % count($userIds)], $userIds[($i + 1) % count($userIds)],
                ($i * 13) % 101, "프로젝트 메모 {$i}", dt(($i % 900) + 1),
            ];
        }
    })());
echo "  - projects: {$n}\n";
$prjRows = $pdo->query('SELECT id, construction_type, sales_user_id, site_manager_id FROM projects ORDER BY id')->fetchAll();

// ── project_assignments (기여율) ────────────────────────────────────────────
$n = bulk($pdo, 'project_assignments',
    ['project_id','user_id','role','contribution_pct','status','created_at'],
    (function () use ($prjRows, $userIds) {
        $i = 0;
        foreach ($prjRows as $p) {
            $i++;
            $u1 = (int) $p['sales_user_id'];
            $u2 = (int) $p['site_manager_id'];
            yield [$p['id'], $u1, '영업', 60.00, 'active', dt($i % 500 + 1)];
            if ($u2 !== $u1) {
                yield [$p['id'], $u2, '현장책임자', 40.00, 'active', dt($i % 500 + 1)];
            }
        }
    })());
echo "  - project_assignments: {$n}\n";

// ── project_stage_progress (게이지 보드 — 프로젝트당 실공정 전부) ───────────
$n = bulk($pdo, 'project_stage_progress',
    ['project_id','stage_id','pct','updated_by','updated_at'],
    (function () use ($prjRows, $procByType) {
        $i = 0;
        foreach ($prjRows as $p) {
            $i++;
            $pool = $procByType[$p['construction_type']] ?: $procByType['painting'];
            foreach ($pool as $k => $sid) {
                yield [$p['id'], $sid, min(100, ($i * 7 + $k * 11) % 130), 1, dt(($i % 300) + 1)];
            }
        }
    })());
echo "  - project_stage_progress: {$n}\n";

// ── project_process_history ─────────────────────────────────────────────────
$n = bulk($pdo, 'project_process_history',
    ['project_id','from_stage_id','to_stage_id','changed_by','reason','is_auto','changed_at'],
    (function () use ($prjRows, $procByType) {
        $i = 0;
        foreach ($prjRows as $p) {
            $i++;
            $pool = $procByType[$p['construction_type']] ?: $procByType['painting'];
            $cnt  = min(6, count($pool) - 1);
            for ($k = 0; $k < $cnt; $k++) {
                yield [$p['id'], $pool[$k], $pool[$k + 1], 1, "공정 이동 {$i}-{$k}", 0, dt(($i % 300) + $cnt - $k)];
            }
        }
    })());
echo "  - project_process_history: {$n}\n";

// ── costs ───────────────────────────────────────────────────────────────────
$cats = ['material','labor','outsourcing','equipment','transport','meal','waste','etc'];
$n = bulk($pdo, 'costs',
    ['project_id','type','cost_status','category','item_name','spec','qty','unit','unit_price','amount','worker_id','worker_name','vendor','spent_date','memo','created_by','created_at'],
    (function () use ($prjRows, $N, $cats, $userIds) {
        $i = 0;
        foreach ($prjRows as $p) {
            $i++;
            for ($k = 0; $k < $N['costs_per_p']; $k++) {
                $qty = 5 + (($i + $k) % 20); $up = 20000 + (($i + $k) % 30) * 1500;
                yield [
                    $p['id'], ($i + $k) % 5 === 0 ? 'estimate' : 'actual',
                    ($i + $k) % 11 === 0 ? 'cancelled' : 'confirmed',
                    $cats[($i + $k) % 8], "비용항목 {$i}-{$k}", '규격', $qty, 'EA', $up, $qty * $up,
                    $userIds[($i + $k) % count($userIds)], null, "거래처 {$k}",
                    d((($i * 2 + $k) % 800)), "비용 메모 {$i}-{$k}", 1, dt((($i * 2 + $k) % 800) + 1),
                ];
            }
        }
    })());
echo "  - costs: {$n}\n";

// ── schedules (+ 참여자 · 슬롯) ─────────────────────────────────────────────
$n = bulk($pdo, 'schedules',
    ['project_id','user_id','title','event_date','end_date','slot','start_datetime','end_datetime','all_day','type','status','memo','created_at'],
    (function () use ($N, $prjRows, $userIds) {
        $slots = ['am','pm','night'];
        for ($i = 1; $i <= $N['schedules']; $i++) {
            $day  = ($i % 400) - 200; // 과거·미래 혼합
            $date = date('Y-m-d', strtotime(($day >= 0 ? "+{$day}" : $day) . ' days'));
            $sl   = $slots[$i % 3];
            $h    = $sl === 'am' ? '09' : ($sl === 'pm' ? '13' : '19');
            yield [
                $prjRows ? $prjRows[$i % count($prjRows)]['id'] : null,
                $userIds[$i % count($userIds)], "일정 {$i}", $date, $date, $sl,
                "{$date} {$h}:00:00", "{$date} {$h}:59:00", 0,
                $i % 5 === 0 ? 'meeting' : 'work', 'scheduled', "일정 메모 {$i}", dt(($i % 300) + 1),
            ];
        }
    })());
echo "  - schedules: {$n}\n";
$schIds = array_column($pdo->query('SELECT id FROM schedules')->fetchAll(), 'id');
$n = bulk($pdo, 'schedule_participants', ['schedule_id','user_id','created_at'],
    (function () use ($schIds, $userIds) {
        $i = 0;
        foreach ($schIds as $sid) {
            $i++;
            yield [$sid, $userIds[$i % count($userIds)], dt($i % 200 + 1)];
            yield [$sid, $userIds[($i + 1) % count($userIds)], dt($i % 200 + 1)];
        }
    })());
echo "  - schedule_participants: {$n}\n";

// ── audit_logs (가장 빠르게 증가하는 테이블) ────────────────────────────────
$acts = ['create','update','delete','login','export','restore','purge'];
$ents = ['customer','project','contract','quote','payment','cost','user','setting'];
$n = bulk($pdo, 'audit_logs',
    ['user_id','action','entity','entity_id','before_json','after_json','ip','user_agent','created_at'],
    (function () use ($N, $acts, $ents, $userIds) {
        for ($i = 1; $i <= $N['audit']; $i++) {
            yield [
                $userIds[$i % count($userIds)], $acts[$i % 7], $ents[$i % 8], ($i % 500) + 1,
                '{"a":' . $i . ',"b":"' . str_repeat('x', 60) . '"}',
                '{"a":' . ($i + 1) . ',"b":"' . str_repeat('y', 60) . '"}',
                '127.0.0.1', 'Mozilla/5.0 perf-seed', dt((int) ($i / 300) + 1),
            ];
        }
    })(), 2000);
echo "  - audit_logs: {$n}\n";

// ── notifications ───────────────────────────────────────────────────────────
$n = bulk($pdo, 'notifications',
    ['user_id','type','title','message','link_route','link_params','is_read','created_at'],
    (function () use ($N, $userIds) {
        for ($i = 1; $i <= $N['notifications']; $i++) {
            yield [$userIds[$i % count($userIds)], 'system', "알림 {$i}", "알림 내용 {$i}",
                   'home', '', $i % 3 === 0 ? 0 : 1, dt((int) ($i / 50) + 1)];
        }
    })(), 2000);
echo "  - notifications: {$n}\n";

// ── company_targets (반기/성과 화면 근거) ───────────────────────────────────
$n = bulk($pdo, 'company_targets', ['period_type','year','period_no','target_revenue','target_profit','created_at'],
    (function () {
        foreach ([2024, 2025, 2026] as $y) {
            foreach ([1, 2] as $h) { yield ['half', $y, $h, 2000000000, 500000000, dt(30)]; }
            for ($m = 1; $m <= 12; $m++) { yield ['month', $y, $m, 300000000, 80000000, dt(30)]; }
        }
    })());
echo "  - company_targets: {$n}\n";

// ANALYZE 는 결과 집합을 돌려주므로 반드시 소비한다(unbuffered query 오류 방지)
$pdo->query('ANALYZE TABLE customers, leads, quotes, quote_versions, quote_items, contracts, payments, projects, project_assignments, project_stage_progress, project_process_history, costs, schedules, schedule_participants, audit_logs, notifications')->fetchAll();

printf("[perf_seed] 완료 %.1fs\n", microtime(true) - $t0);

$rows = $pdo->query("SELECT TABLE_NAME t, TABLE_ROWS r FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_ROWS > 0 ORDER BY TABLE_ROWS DESC")->fetchAll();
foreach ($rows as $r) { printf("    %-32s %s\n", $r['t'], $r['r']); }
