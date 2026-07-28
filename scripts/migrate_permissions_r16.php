<?php
/**
 * R16 — 기존 직원 권한을 employee_permissions 매트릭스로 변환한다.
 *
 * 원칙
 *  - 기존 계정·데이터를 삭제하거나 초기화하지 않는다.
 *  - 현재 "유효 권한"(role_permissions ∪ user_permissions.grant − deny)을 스냅샷해
 *    (리소스, 액션)으로 사상한다. 즉 지금 쓸 수 있는 기능은 그대로 쓸 수 있다.
 *  - 삭제 라우트가 쓰기 perm(*.manage)을 재사용하던 기존 동작을 보존하기 위해
 *    LEGACY_DELETE 로 delete 를 승계한다(권한 축소로 인한 업무 중단 방지).
 *  - super_admin 은 행을 만들지 않는다(코드가 무조건 허용).
 *  - ADMIN_ONLY perm(정산·전직원성과·출근·보너스·직원·설정·감사)은 사상하지 않는다
 *    → 최고운영자 전용으로 이관된다. 상실 대상은 리포트에 명시한다.
 *  - 멱등: 재실행해도 결과가 같다(사용자별 전량 재계산 후 교체).
 *
 * 사용:
 *   php scripts/migrate_permissions_r16.php --dry      (변환 결과만 출력)
 *   php scripts/migrate_permissions_r16.php --apply    (실제 반영)
 *   php scripts/migrate_permissions_r16.php --apply --report docs/r16_perm_migration.md
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$GLOBALS['config'] = require __DIR__ . '/../app/config/config.php';
foreach (['Util', 'Db', 'Perm'] as $c) { require_once APP_PATH . '/core/' . $c . '.php'; }

$dry      = in_array('--dry', $argv, true);
$apply    = in_array('--apply', $argv, true);
$reportTo = null;
foreach ($argv as $i => $a) { if ($a === '--report') { $reportTo = $argv[$i + 1] ?? null; } }

if (!$dry && !$apply) {
    fwrite(STDERR, "--dry 또는 --apply 를 지정하세요.\n");
    exit(1);
}

/**
 * 쓰기 perm 이 곧 삭제 권한이던 기존 라우트 동작을 승계한다.
 * (예: quotes.delete 라우트가 quote.manage 를 요구했으므로 quote.manage 보유자는 견적을 지울 수 있었다)
 */
const LEGACY_DELETE = [
    'pipeline.manage' => 'sales.leads',
    'quote.manage'    => 'sales.quotes',
    'contract.manage' => 'sales.contracts',
    'project.manage'  => 'field.projects',
    'process.move'    => 'field.process_board',
    'schedule.manage' => 'field.schedules',
    'cost.manage'     => 'field.costs',
];

/** 기존에 read 키가 없던 리소스의 읽기 승계(공정 보드·비용은 perm 없이 열려 있었다). */
const LEGACY_READ = [
    'process.move' => 'field.process_board',
    'cost.manage'  => 'field.costs',
];

$registry  = Perm::registry();
$resources = Perm::resources();
$adminOnly = Perm::adminOnly();

$users = Db::all(
    "SELECT u.id, u.login_id, u.name, u.status, r.role_key
       FROM users u JOIN roles r ON r.id = u.role_id
      WHERE u.deleted_at IS NULL
      ORDER BY u.id"
);

$results = [];
foreach ($users as $u) {
    $uid  = (int) $u['id'];
    $role = $u['role_key'];

    if ($role === Perm::SUPER_ROLE) {
        $results[] = ['user' => $u, 'skip' => '최고운영자 — 코드상 전체 권한(행 미생성)', 'matrix' => [], 'lost' => []];
        continue;
    }

    // ── 현재 유효 perm 계산: 역할 권한 ∪ 개별 grant − 개별 deny ──
    $rolePerms = Db::run(
        "SELECT p.perm_key FROM role_permissions rp
           JOIN permissions p ON p.id = rp.permission_id
           JOIN users u ON u.role_id = rp.role_id
          WHERE u.id = :uid",
        [':uid' => $uid]
    )->fetchAll(PDO::FETCH_COLUMN);

    $grants = $denies = [];
    foreach (Db::all(
        "SELECT p.perm_key, up.is_grant FROM user_permissions up
           JOIN permissions p ON p.id = up.permission_id
          WHERE up.user_id = :uid",
        [':uid' => $uid]
    ) as $r) {
        if ((int) $r['is_grant'] === 1) { $grants[] = $r['perm_key']; } else { $denies[] = $r['perm_key']; }
    }
    $effective = array_values(array_diff(array_unique(array_merge($rolePerms, $grants)), $denies));

    // ── (리소스, 액션)으로 사상 ──
    $matrix = [];
    $touch = function (string $res, string $act) use (&$matrix, $resources) {
        if (!isset($resources[$res])) { return; }
        $matrix[$res] = $matrix[$res] ?? ['can_read' => 0, 'can_write' => 0, 'can_delete' => 0];
        $matrix[$res]['can_' . $act] = 1;
    };

    $lost = [];
    foreach ($effective as $perm) {
        if (isset($adminOnly[$perm])) {
            $lost[$perm] = $adminOnly[$perm];       // 최고운영자 전용으로 이관 → 상실
            continue;
        }
        if (isset($registry[$perm])) {
            [$res, $act] = $registry[$perm];
            $touch($res, $act);
        }
        if (isset(LEGACY_DELETE[$perm])) { $touch(LEGACY_DELETE[$perm], 'delete'); }
        if (isset(LEGACY_READ[$perm]))   { $touch(LEGACY_READ[$perm], 'read'); }
    }

    // 종속 규칙 정규화
    foreach ($matrix as $key => $vals) { $matrix[$key] = Perm::normalize($vals, $key); }
    ksort($matrix);

    $results[] = ['user' => $u, 'skip' => null, 'matrix' => $matrix, 'lost' => $lost, 'effective' => $effective];
}

// ── 출력 ──
$lines = [];
$lines[] = '# R16 직원 권한 마이그레이션 결과';
$lines[] = '';
$lines[] = '생성: ' . date('Y-m-d H:i:s') . ' · 모드: ' . ($apply ? 'APPLY' : 'DRY-RUN');
$lines[] = '';
$lines[] = '변환 규칙: 현재 유효 권한(역할 ∪ 개별부여 − 개별제외)을 리소스×액션으로 사상.';
$lines[] = '기존에 쓰기 perm 으로 삭제가 가능하던 라우트는 delete 를 승계해 업무 중단을 막았다.';
$lines[] = '최고운영자 전용으로 이관된 권한은 "상실" 열에 표시한다.';
$lines[] = '';
$lines[] = '| 직원 | 로그인ID | 역할 | 상태 | 부여된 리소스 (R/W/D) | 상실 권한 |';
$lines[] = '|---|---|---|---|---|---|';

foreach ($results as $r) {
    $u = $r['user'];
    if ($r['skip'] !== null) {
        $lines[] = sprintf('| %s | %s | %s | %s | %s | – |',
            $u['name'], $u['login_id'], $u['role_key'], $u['status'], $r['skip']);
        continue;
    }
    $cells = [];
    foreach ($r['matrix'] as $key => $v) {
        $cells[] = sprintf('%s(%s%s%s)', $key,
            $v['can_read'] ? 'R' : '-', $v['can_write'] ? 'W' : '-', $v['can_delete'] ? 'D' : '-');
    }
    $lines[] = sprintf('| %s | %s | %s | %s | %s | %s |',
        $u['name'], $u['login_id'], $u['role_key'], $u['status'],
        $cells ? implode('<br>', $cells) : '**없음(최소 권한)**',
        $r['lost'] ? implode(', ', $r['lost']) : '–');
}

$lines[] = '';
$lines[] = '## 요약';
$lines[] = '- 전체 계정: ' . count($results);
$lines[] = '- 최고운영자(행 미생성): ' . count(array_filter($results, fn($r) => $r['skip'] !== null));
$lines[] = '- 권한 행 생성 대상: ' . count(array_filter($results, fn($r) => $r['skip'] === null && $r['matrix']));
$lines[] = '- 권한 전무(최소 권한 적용): ' . count(array_filter($results, fn($r) => $r['skip'] === null && !$r['matrix']));

$out = implode("\n", $lines) . "\n";
echo $out;

// ── 반영 ──
if ($apply) {
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        $n = 0;
        foreach ($results as $r) {
            if ($r['skip'] !== null) {
                // 최고운영자에게 남아 있는 행이 있으면 정리(항상 전체 권한이므로 불필요)
                Db::run("DELETE FROM employee_permissions WHERE user_id = :u", [':u' => (int) $r['user']['id']]);
                continue;
            }
            $uid = (int) $r['user']['id'];
            Db::run("DELETE FROM employee_permissions WHERE user_id = :u", [':u' => $uid]);
            foreach ($r['matrix'] as $key => $v) {
                Db::insert('employee_permissions', [
                    'user_id'      => $uid,
                    'section'      => Perm::resources()[$key]['section'],
                    'resource_key' => $key,
                    'can_read'     => $v['can_read'],
                    'can_write'    => $v['can_write'],
                    'can_delete'   => $v['can_delete'],
                    'updated_by'   => null,
                ]);
                $n++;
            }
        }
        $pdo->commit();
        echo "\n✅ 반영 완료 — 권한 행 {$n}개\n";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "❌ 실패(롤백): " . $e->getMessage() . "\n");
        exit(1);
    }
}

if ($reportTo) {
    file_put_contents($reportTo, $out);
    echo "리포트 기록: {$reportTo}\n";
}
