<?php
/**
 * R16 QA 테스트 계정 시딩·정리 — 권한 조합 A~H 검증용.
 * 모든 계정은 login_id 가 'qa_r16_' 로 시작한다(정리 시 이 prefix 만 삭제).
 * 기존 운영/개발 계정과 데이터는 절대 건드리지 않는다.
 *
 * 사용:
 *   php scripts/qa_r16_seed.php --seed              계정 생성·권한 부여
 *   php scripts/qa_r16_seed.php --list              현재 QA 계정·권한 출력
 *   php scripts/qa_r16_seed.php --cleanup           QA 계정 전량 삭제(권한은 FK CASCADE)
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$GLOBALS['config'] = require __DIR__ . '/../app/config/config.php';
foreach (['Util', 'Db', 'Perm'] as $c) { require_once APP_PATH . '/core/' . $c . '.php'; }

if (($GLOBALS['config']['APP_ENV'] ?? '') === 'production'
    && !in_array('--allow-prod', $argv, true)) {
    fwrite(STDERR, "운영 환경에서는 --allow-prod 를 명시해야 합니다.\n");
    exit(1);
}

const QA_PREFIX = 'qa_r16_';
const QA_PASSWORD = 'QaR16!verify2026';

/** 테스트 시나리오 A~H — 요청서 15절. */
$SCENARIOS = [
    'a' => ['name' => 'QA영업읽기',   'role' => 'staff', 'desc' => '영업 읽기만',
        'perms' => [
            'sales.customers' => [1, 0, 0], 'sales.leads' => [1, 0, 0],
            'sales.quotes'    => [1, 0, 0], 'sales.contracts' => [1, 0, 0],
        ]],
    'b' => ['name' => 'QA영업쓰기',   'role' => 'staff', 'desc' => '영업 읽기·쓰기, 삭제 차단',
        'perms' => [
            'sales.customers' => [1, 1, 0], 'sales.leads' => [1, 1, 0],
            'sales.quotes'    => [1, 1, 0], 'sales.contracts' => [1, 1, 0],
        ]],
    'c' => ['name' => 'QA영업삭제',   'role' => 'staff', 'desc' => '영업 읽기·쓰기·삭제',
        'perms' => [
            'sales.customers' => [1, 1, 1], 'sales.leads' => [1, 1, 1],
            'sales.quotes'    => [1, 1, 1], 'sales.contracts' => [1, 1, 1],
        ]],
    'd' => ['name' => 'QA현장쓰기',   'role' => 'staff', 'desc' => '현장 읽기·쓰기(프로젝트 삭제 없음)',
        'perms' => [
            'field.projects'      => [1, 1, 0], 'field.process_board' => [1, 1, 0],
            'field.schedules'     => [1, 1, 0], 'field.worklogs'      => [1, 1, 0],
        ]],
    'e' => ['name' => 'QA고객만',     'role' => 'staff', 'desc' => '고객 CRM만 — 견적·계약 차단',
        'perms' => ['sales.customers' => [1, 1, 0]]],
    'f' => ['name' => 'QA권한없음',   'role' => 'staff', 'desc' => '업무 권한 전무',
        'perms' => []],
    'h' => ['name' => 'QA즉시반영',   'role' => 'staff', 'desc' => '권한 변경 즉시 반영 검증용',
        'perms' => ['sales.quotes' => [1, 1, 1]]],
];
// 시나리오 G(최고운영자)는 기존 admin 계정을 그대로 사용한다(신규 생성 금지).

$mode = null;
foreach (['--seed', '--list', '--cleanup'] as $m) { if (in_array($m, $argv, true)) { $mode = $m; } }
if ($mode === null) { fwrite(STDERR, "--seed | --list | --cleanup 중 하나를 지정하세요.\n"); exit(1); }

$roleIds = [];
foreach (Db::all("SELECT id, role_key FROM roles") as $r) { $roleIds[$r['role_key']] = (int) $r['id']; }

// ─────────────────────────────────────── cleanup
if ($mode === '--cleanup') {
    $rows = Db::all("SELECT id, login_id FROM users WHERE login_id LIKE :p",
        [':p' => QA_PREFIX . '%']);
    if (!$rows) { echo "삭제할 QA 계정이 없습니다.\n"; exit(0); }
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $uid = (int) $r['id'];
            // 참조 정리 — 감사 로그는 FK CASCADE, 권한은 FK CASCADE
            Db::run("DELETE FROM employee_permissions WHERE user_id = :u", [':u' => $uid]);
            Db::run("DELETE FROM notifications WHERE user_id = :u", [':u' => $uid]);
            Db::run("DELETE FROM users WHERE id = :u", [':u' => $uid]);
            echo "  삭제: {$r['login_id']}\n";
        }
        $pdo->commit();
        echo "✅ QA 계정 " . count($rows) . "개 정리 완료\n";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "❌ 정리 실패(롤백): " . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

// ─────────────────────────────────────── list
if ($mode === '--list') {
    $rows = Db::all("SELECT id, login_id, name, status FROM users WHERE login_id LIKE :p ORDER BY login_id",
        [':p' => QA_PREFIX . '%']);
    if (!$rows) { echo "QA 계정이 없습니다.\n"; exit(0); }
    foreach ($rows as $r) {
        printf("\n%s (%s) — %s\n", $r['login_id'], $r['name'], $r['status']);
        $ps = Db::all("SELECT resource_key, can_read, can_write, can_delete
                         FROM employee_permissions WHERE user_id = :u ORDER BY resource_key",
            [':u' => (int) $r['id']]);
        if (!$ps) { echo "   (권한 없음)\n"; continue; }
        foreach ($ps as $p) {
            printf("   %-22s %s%s%s\n", $p['resource_key'],
                $p['can_read'] ? 'R' : '-', $p['can_write'] ? 'W' : '-', $p['can_delete'] ? 'D' : '-');
        }
    }
    exit(0);
}

// ─────────────────────────────────────── seed
$defs = Perm::resources();
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    foreach ($SCENARIOS as $tag => $s) {
        $loginId = QA_PREFIX . $tag;
        $existing = Db::one("SELECT id FROM users WHERE login_id = :l", [':l' => $loginId]);
        if ($existing) {
            $uid = (int) $existing['id'];
            Db::update('users', [
                'name'      => $s['name'],
                'status'    => 'active',
                'role_id'   => $roleIds[$s['role']],
                'role_key'  => $s['role'],
                'deleted_at'=> null,
            ], 'id = :id', [':id' => $uid]);
        } else {
            $uid = Db::insert('users', [
                'login_id'      => $loginId,
                'password_hash' => password_hash(QA_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]),
                'name'          => $s['name'],
                'email'         => $loginId . '@qa.invalid',
                'role_id'       => $roleIds[$s['role']],
                'role_key'      => $s['role'],
                'status'        => 'active',
                'must_change_password' => 0,
            ]);
        }

        Db::run("DELETE FROM employee_permissions WHERE user_id = :u", [':u' => $uid]);
        foreach ($s['perms'] as $key => [$r, $w, $d]) {
            $n = Perm::normalize(['can_read' => $r, 'can_write' => $w, 'can_delete' => $d], $key);
            Db::insert('employee_permissions', [
                'user_id'      => $uid,
                'section'      => $defs[$key]['section'],
                'resource_key' => $key,
                'can_read'     => $n['can_read'],
                'can_write'    => $n['can_write'],
                'can_delete'   => $n['can_delete'],
            ]);
        }
        printf("  %-14s %-12s %s\n", $loginId, $s['name'], $s['desc']);
    }
    $pdo->commit();
    echo "\n✅ QA 계정 " . count($SCENARIOS) . "개 시딩 완료 (비밀번호는 스크립트 상수)\n";
    echo "   시나리오 G(최고운영자)는 기존 admin 계정 사용 — 신규 생성하지 않음\n";
    echo "   검수 후 반드시: php scripts/qa_r16_seed.php --cleanup\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "❌ 시딩 실패(롤백): " . $e->getMessage() . "\n");
    exit(1);
}
