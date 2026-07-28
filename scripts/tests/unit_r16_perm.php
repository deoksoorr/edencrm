<?php
/**
 * R16 — 직원별 세부 권한 엔진(Perm) 회귀. 전부 트랜잭션 내 픽스처로 만들고 롤백한다.
 * 기존 운영/개발 행은 절대 수정하지 않는다.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/Perm.php';

echo "R16 권한 엔진 (트랜잭션 롤백)\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $suf = (string) random_int(100000, 999999);

    $roleStaff = (int) Db::one("SELECT id FROM roles WHERE role_key='staff'")['id'];
    $roleSuper = (int) Db::one("SELECT id FROM roles WHERE role_key='super_admin'")['id'];

    $mkUser = function (string $tag, int $roleId, string $roleKey) use ($suf): int {
        return Db::insert('users', [
            'login_id' => 'qa_r16_' . $tag . $suf,
            'password_hash' => password_hash('x', PASSWORD_BCRYPT),
            'name' => 'R16' . $tag,
            'email' => 'qa_r16_' . $tag . $suf . '@example.invalid',
            'role_id' => $roleId,
            'role_key' => $roleKey,
            'status' => 'active',
        ]);
    };
    $staffId = $mkUser('staff', $roleStaff, 'staff');
    $superId = $mkUser('super', $roleSuper, 'super_admin');

    // ══════════════ 1) 기본 거부 ══════════════
    Perm::reset();
    t_true('①권한 행이 없으면 읽기 거부', Perm::can($staffId, 'sales.customers', 'read') === false);
    t_true('①권한 행이 없으면 쓰기 거부', Perm::can($staffId, 'sales.customers', 'write') === false);
    t_true('①권한 행이 없으면 삭제 거부', Perm::can($staffId, 'sales.customers', 'delete') === false);
    t_true('①미등록 리소스 키는 거부', Perm::can($staffId, 'sales.nonexistent', 'read') === false);
    t_true('①미등록 액션은 거부', Perm::can($staffId, 'sales.customers', 'purge') === false);

    // ══════════════ 2) 부여된 권한만 통과 ══════════════
    Db::insert('employee_permissions', [
        'user_id' => $staffId, 'section' => 'sales', 'resource_key' => 'sales.customers',
        'can_read' => 1, 'can_write' => 1, 'can_delete' => 0,
    ]);
    Perm::reset();
    t_true('②읽기 부여 → 통과',      Perm::can($staffId, 'sales.customers', 'read') === true);
    t_true('②쓰기 부여 → 통과',      Perm::can($staffId, 'sales.customers', 'write') === true);
    t_true('②삭제 미부여 → 차단',    Perm::can($staffId, 'sales.customers', 'delete') === false);
    t_true('②다른 리소스는 여전히 차단', Perm::can($staffId, 'sales.quotes', 'read') === false);

    // ══════════════ 3) 삭제는 쓰기와 독립 ══════════════
    Db::update('employee_permissions', ['can_delete' => 1], 'user_id = :u AND resource_key = :r',
        [':u' => $staffId, ':r' => 'sales.customers']);
    Perm::reset();
    t_true('③삭제 부여 → 통과', Perm::can($staffId, 'sales.customers', 'delete') === true);
    Db::update('employee_permissions', ['can_write' => 0, 'can_delete' => 1], 'user_id = :u AND resource_key = :r',
        [':u' => $staffId, ':r' => 'sales.customers']);
    Perm::reset();
    t_true('③쓰기 없이 삭제만 있어도 쓰기는 차단', Perm::can($staffId, 'sales.customers', 'write') === false);

    // ══════════════ 4) 최고운영자 ══════════════
    t_true('④super_admin 판정', Perm::isSuperAdmin(['role' => 'super_admin']) === true);
    t_true('④staff 는 super_admin 아님', Perm::isSuperAdmin(['role' => 'staff']) === false);
    t_true('④role 키 없으면 아님', Perm::isSuperAdmin([]) === false);
    t_true('④null 이면 아님', Perm::isSuperAdmin(null) === false);
    // 권한 행이 전혀 없어도 super_admin 은 전부 허용
    t_true('④super_admin 은 행 없이도 읽기 허용',  Perm::can($superId, 'sales.contracts', 'read') === true);
    t_true('④super_admin 은 행 없이도 삭제 허용',  Perm::can($superId, 'field.projects', 'delete') === true);
    t_true('④super_admin 은 미등록 키도 허용',     Perm::can($superId, 'anything.at.all', 'read') === true);

    // ══════════════ 5) 종속 규칙 정규화 ══════════════
    $n = Perm::normalize(['can_read' => 0, 'can_write' => 1, 'can_delete' => 0]);
    t_int('⑤쓰기 부여 시 읽기 자동 ON', 1, $n['can_read']);
    $n = Perm::normalize(['can_read' => 0, 'can_write' => 0, 'can_delete' => 1]);
    t_int('⑤삭제 부여 시 읽기 자동 ON', 1, $n['can_read']);
    $n = Perm::normalize(['can_read' => 0, 'can_write' => 1, 'can_delete' => 1]);
    t_int('⑤읽기 없이 쓰기+삭제 → 읽기 ON', 1, $n['can_read']);
    $n = Perm::normalize(['can_read' => 1, 'can_write' => 1, 'can_delete' => 1]);
    t_int('⑤전체 부여는 그대로', 1, $n['can_delete']);
    // analytics 는 읽기 전용 — 쓰기·삭제 강제 0
    $n = Perm::normalize(['can_read' => 1, 'can_write' => 1, 'can_delete' => 1], 'analytics.reports');
    t_int('⑤분석 리소스는 쓰기 강제 0', 0, $n['can_write']);
    t_int('⑤분석 리소스는 삭제 강제 0', 0, $n['can_delete']);
    t_int('⑤분석 리소스 읽기는 유지',   1, $n['can_read']);

    // ══════════════ 6) 레지스트리 — perm_key ↔ (리소스, 액션) ══════════════
    $reg = Perm::registry();
    t_true('⑥quote.view → sales.quotes read',
        ($reg['quote.view'] ?? null) === ['sales.quotes', 'read']);
    t_true('⑥quote.manage → sales.quotes write',
        ($reg['quote.manage'] ?? null) === ['sales.quotes', 'write']);
    t_true('⑥quote.delete → sales.quotes delete',
        ($reg['quote.delete'] ?? null) === ['sales.quotes', 'delete']);
    t_true('⑥report.view → analytics.reports read',
        ($reg['report.view'] ?? null) === ['analytics.reports', 'read']);
    // ADMIN_ONLY 는 레지스트리에 없어야 한다(일반 직원에게 매핑 불가)
    foreach (['payment.manage', 'staff.manage', 'settings.manage', 'audit.view',
              'bonus.manage', 'performance.view_all', 'attendance.manage', 'trash.manage'] as $adminKey) {
        t_true("⑥ADMIN_ONLY 미매핑: {$adminKey}", !isset($reg[$adminKey]));
        t_true("⑥ADMIN_ONLY 판정: {$adminKey}", Perm::isAdminOnly($adminKey) === true);
    }
    t_true('⑥일반 perm 은 ADMIN_ONLY 아님', Perm::isAdminOnly('quote.view') === false);

    // ══════════════ 7) 매트릭스 정의 ══════════════
    $res = Perm::resources();
    t_true('⑦영업 4종 존재', count(array_filter($res, fn($r) => $r['section'] === 'sales')) === 4);
    t_true('⑦현장 5종 존재', count(array_filter($res, fn($r) => $r['section'] === 'field')) === 5);
    t_true('⑦분석 1종 존재', count(array_filter($res, fn($r) => $r['section'] === 'analytics')) === 1);
    t_true('⑦모든 리소스에 표시명 존재',
        count(array_filter($res, fn($r) => !empty($r['label']))) === count($res));
    // 레지스트리의 모든 리소스 키가 매트릭스 정의에 존재해야 한다(오타 방지)
    $known = array_keys($res);
    $orphans = [];
    foreach ($reg as $pk => [$rk, $act]) {
        if (!in_array($rk, $known, true)) { $orphans[] = "$pk→$rk"; }
    }
    t_true('⑦레지스트리 리소스 키가 전부 정의됨 (' . implode(',', $orphans) . ')', $orphans === []);

    // ══════════════ 8) 저장 — 종속 규칙 + 미등록 키 거부 ══════════════
    Perm::save($staffId, [
        'sales.quotes'   => ['can_read' => 0, 'can_write' => 1, 'can_delete' => 0], // read 자동 ON
        'field.projects' => ['can_read' => 1, 'can_write' => 0, 'can_delete' => 0],
        'bogus.key'      => ['can_read' => 1, 'can_write' => 1, 'can_delete' => 1], // 무시돼야 함
    ], $superId);
    Perm::reset();
    t_true('⑧저장 시 쓰기→읽기 자동 ON', Perm::can($staffId, 'sales.quotes', 'read') === true);
    t_true('⑧저장된 쓰기 반영',          Perm::can($staffId, 'sales.quotes', 'write') === true);
    t_true('⑧저장된 읽기 전용 반영',      Perm::can($staffId, 'field.projects', 'read') === true);
    t_true('⑧읽기 전용은 쓰기 차단',      Perm::can($staffId, 'field.projects', 'write') === false);
    t_true('⑧미등록 키는 저장되지 않음',
        Db::one("SELECT id FROM employee_permissions WHERE user_id=:u AND resource_key='bogus.key'",
            [':u' => $staffId]) === null);
    // 저장은 전량 교체 — 이전 sales.customers 는 사라져야 한다
    t_true('⑧미포함 리소스는 회수됨', Perm::can($staffId, 'sales.customers', 'read') === false);
    t_int('⑧UNIQUE 조합 유지(리소스당 1행)', 2,
        (int) Db::one("SELECT COUNT(*) c FROM employee_permissions WHERE user_id=:u", [':u' => $staffId])['c']);

    // ══════════════ 9) super_admin 권한 저장 거부 ══════════════
    $threw = false;
    try { Perm::save($superId, ['sales.quotes' => ['can_read' => 1]], $superId); }
    catch (\Throwable $e) { $threw = true; }
    t_true('⑨super_admin 대상 권한 저장은 거부', $threw === true);

    // ══════════════ 10) 캐시 무효화 ══════════════
    Perm::reset();
    Perm::can($staffId, 'sales.quotes', 'read');            // 캐시 적재
    Db::run("DELETE FROM employee_permissions WHERE user_id = :u", [':u' => $staffId]);
    t_true('⑩reset 전에는 캐시 값 유지', Perm::can($staffId, 'sales.quotes', 'read') === true);
    Perm::reset();
    t_true('⑩reset 후에는 즉시 반영',   Perm::can($staffId, 'sales.quotes', 'read') === false);

    // ══════════════ 11) R16-1 회귀 — 쓰기 라우트가 읽기 perm 을 쓰지 않는다 ══════════════
    // 고객 읽기만 가진 직원이 활동 추가(쓰기)를 할 수 있던 결함의 재발 방지.
    $routes = require APP_PATH . '/routes.php';
    $readPerms = ['customer.view', 'pipeline.view', 'quote.view', 'contract.view',
                  'project.view_all', 'project.view_assigned', 'process.view',
                  'schedule.view_all', 'worklog.view_all', 'cost.view',
                  'report.view', 'finance.view'];
    $violations = [];
    foreach ($routes as $key => $def) {
        if (($def['method'] ?? 'GET') !== 'POST') { continue; }
        $perm = $def['perm'] ?? null;
        if ($perm !== null && in_array($perm, $readPerms, true)) {
            $violations[] = "$key→$perm";
        }
    }
    t_true('⑪POST 라우트가 읽기 perm 을 쓰지 않음 (' . implode(', ', $violations) . ')', $violations === []);
    t_true('⑪activities.save 는 쓰기 perm', ($routes['activities.save']['perm'] ?? '') === 'customer.manage');
    t_true('⑪projects.upload 은 쓰기 perm', ($routes['projects.upload']['perm'] ?? '') === 'project.manage');

    // ══════════════ 12) R16-1 회귀 — 열람 범위는 해당 리소스의 읽기 권한이 정한다 ══════════════
    // 프로젝트 읽기를 부여했는데 목록이 비던 결함(현장관리자 0건)의 재발 방지.
    $src = file_get_contents(APP_PATH . '/core/Scope.php');
    t_true('⑫프로젝트 범위가 분석 권한에 결합되지 않음',
        !preg_match('/canViewAllProjects\(\).*?analytics\.reports/s', $src));
    t_true('⑫프로젝트 범위는 project.view_all 로 판정',
        str_contains($src, "Rbac::can('project.view_all')"));
    t_true('⑫고객 범위는 customer.view 로 판정',
        str_contains($src, "Rbac::can('customer.view')"));
    // 주석의 단어가 아니라 실제 호출(Rbac::isRole)이 남아 있는지로 판정한다.
    t_true('⑫Scope 에서 역할 기반 판정(Rbac::isRole) 호출 제거',
        !str_contains($src, 'Rbac::isRole'));

    $pdo->rollBack();
    echo "\n(픽스처 롤백 완료 — 기존 행 무변경)\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "  [FAIL] 예외: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
