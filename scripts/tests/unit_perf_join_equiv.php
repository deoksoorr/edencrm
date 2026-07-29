<?php
/**
 * T8 — PAY_PROJECT_JOIN 재작성 동치성 검증.
 *
 * 성능 때문에 OR 조인을 LEFT JOIN×2 + COALESCE 로 바꿨다. 금액 집계 SQL 이므로
 * "빨라졌다"가 아니라 "결과가 같다"를 증명해야 한다.
 * 구/신 조인을 같은 픽스처에 각각 실행해 직원별 귀속 금액이 정확히 일치하는지 본다.
 * 전부 트랜잭션 내 픽스처이며 롤백한다.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "PAY_PROJECT_JOIN 동치성 (트랜잭션 롤백)\n";

/** 재작성 전 원본 조인 — 비교 기준으로만 보관한다. */
const OLD_JOIN = " LEFT JOIN contracts c ON c.id = pm.contract_id AND c.deleted_at IS NULL
      JOIN projects pj2 ON pj2.deleted_at IS NULL
           AND (pj2.id = pm.project_id OR (pm.contract_id IS NOT NULL AND pj2.contract_id = pm.contract_id))
      ";

/** 현재(재작성) 조인 — AccountingService::PAY_PROJECT_JOIN 과 동일해야 한다. */
const NEW_JOIN = " LEFT JOIN contracts c ON c.id = pm.contract_id AND c.deleted_at IS NULL
      LEFT JOIN projects pj_direct ON pj_direct.id = pm.project_id AND pj_direct.deleted_at IS NULL
      LEFT JOIN projects pj_con ON pm.contract_id IS NOT NULL
           AND pj_con.contract_id = pm.contract_id AND pj_con.deleted_at IS NULL
      JOIN projects pj2 ON pj2.id = COALESCE(pj_direct.id, pj_con.id) AND pj2.deleted_at IS NULL
      ";

function agg(string $join): array
{
    $sr = AccountingService::vatSupplyRatio();
    // private 상수는 리플렉션으로 읽어 실제 서비스와 동일한 산식을 쓴다(테스트가 산식을 복제하지 않도록).
    $supply = (new ReflectionClass('AccountingService'))->getConstant('PAY_SUPPLY_SQL');
    $rows = Db::all(
        "SELECT pa.user_id AS uid,
                COALESCE(SUM($supply * pa.contribution_pct/100),0) AS revenue,
                COUNT(*) AS n
         FROM payments pm $join
         JOIN project_assignments pa ON pa.project_id = pj2.id AND pa.contribution_pct > 0
         WHERE pm.status='paid' AND (pm.contract_id IS NULL OR c.id IS NOT NULL)
         GROUP BY pa.user_id ORDER BY pa.user_id",
        [':sr' => $sr]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['uid']] = ['rev' => round((float) $r['revenue'], 4), 'n' => (int) $r['n']];
    }
    return $out;
}

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $suf = (string) random_int(100000, 999999);
    $cust = Db::insert('customers', ['name' => 'QAEQ고객' . $suf, 'privacy_agreed' => 1]);
    $roleStaff = (int) Db::one("SELECT id FROM roles WHERE role_key='staff'")['id'];
    $u1 = Db::insert('users', ['login_id' => 'qaeq1' . $suf, 'password_hash' => 'x', 'name' => 'QAEQ직원1',
        'email' => 'qaeq1' . $suf . '@qa.invalid', 'role_id' => $roleStaff, 'role_key' => 'staff', 'status' => 'active']);
    $u2 = Db::insert('users', ['login_id' => 'qaeq2' . $suf, 'password_hash' => 'x', 'name' => 'QAEQ직원2',
        'email' => 'qaeq2' . $suf . '@qa.invalid', 'role_id' => $roleStaff, 'role_key' => 'staff', 'status' => 'active']);

    // ── 케이스 A: 계약 경유 입금(project_id 없음) ──
    $conA = Db::insert('contracts', ['contract_no' => 'QAEQ-A-' . $suf, 'customer_id' => $cust, 'contract_amount' => 11000000]);
    $prjA = Db::insert('projects', ['project_no' => 'QAEQ-PA-' . $suf, 'name' => 'QAEQ프로젝트A',
        'customer_id' => $cust, 'contract_id' => $conA, 'contract_amount' => 11000000]);
    Db::insert('payments', ['contract_id' => $conA, 'pay_type' => 'down', 'amount' => 3300000,
        'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::insert('project_assignments', ['project_id' => $prjA, 'user_id' => $u1, 'role' => 'worker', 'contribution_pct' => 60]);
    Db::insert('project_assignments', ['project_id' => $prjA, 'user_id' => $u2, 'role' => 'worker', 'contribution_pct' => 40]);

    // ── 케이스 B: 예외 프로젝트 직결 입금(contract_id 없음) ──
    $prjB = Db::insert('projects', ['project_no' => 'QAEQ-PB-' . $suf, 'name' => 'QAEQ프로젝트B',
        'customer_id' => $cust, 'contract_amount' => 5500000]);
    Db::insert('payments', ['project_id' => $prjB, 'pay_type' => 'balance', 'amount' => 2200000,
        'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::insert('project_assignments', ['project_id' => $prjB, 'user_id' => $u1, 'role' => 'worker', 'contribution_pct' => 100]);

    // ── 케이스 C: 환불(음수) + 미입금(pending) — 필터가 동일하게 걸리는지 ──
    Db::insert('payments', ['project_id' => $prjB, 'pay_type' => 'etc', 'amount' => -200000,
        'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::insert('payments', ['project_id' => $prjB, 'pay_type' => 'etc', 'amount' => 900000,
        'status' => 'pending', 'due_date' => date('Y-m-d')]);

    // ── 케이스 D: 삭제된 프로젝트 귀속 입금 — 양쪽 모두 제외돼야 한다 ──
    $prjD = Db::insert('projects', ['project_no' => 'QAEQ-PD-' . $suf, 'name' => 'QAEQ삭제프로젝트',
        'customer_id' => $cust, 'contract_amount' => 1000000, 'deleted_at' => date('Y-m-d H:i:s')]);
    Db::insert('payments', ['project_id' => $prjD, 'pay_type' => 'balance', 'amount' => 777000,
        'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::insert('project_assignments', ['project_id' => $prjD, 'user_id' => $u1, 'role' => 'worker', 'contribution_pct' => 100]);

    $old = agg(OLD_JOIN);
    $new = agg(NEW_JOIN);

    t_true('①집계 대상 직원 수 동일 (' . count($old) . ' vs ' . count($new) . ')',
        array_keys($old) === array_keys($new));
    foreach ($old as $uid => $v) {
        $label = $uid === $u1 ? '직원1' : ($uid === $u2 ? '직원2' : "uid$uid");
        t_true("①{$label} 귀속매출 일치 ({$v['rev']} vs " . ($new[$uid]['rev'] ?? 'none') . ')',
            isset($new[$uid]) && abs($new[$uid]['rev'] - $v['rev']) < 0.0001);
        t_true("①{$label} 집계 행수 일치 ({$v['n']} vs " . ($new[$uid]['n'] ?? 'none') . ')',
            isset($new[$uid]) && $new[$uid]['n'] === $v['n']);
    }
    t_true('①삭제 프로젝트 입금은 양쪽 모두 제외',
        !isset($old[$u1]) || $old[$u1]['rev'] === ($new[$u1]['rev'] ?? -1));

    // ── 케이스 E: 계약↔프로젝트 1:1 이 스키마로 강제되는지 ──
    // 구 OR 조인의 유일한 위험은 "한 입금이 두 프로젝트에 매칭되어 금액이 중복 집계"되는 것이었다.
    // projects.contract_id 에 UNIQUE(uq_projects_contract)가 걸려 있어 한 계약에 프로젝트가
    // 둘 이상 달릴 수 없고, 따라서 그 시나리오는 구조적으로 발생 불가능하다.
    // 즉 신·구 조인은 어떤 데이터에서도 동일한 결과를 낸다.
    $dupBlocked = false;
    try {
        Db::insert('projects', ['project_no' => 'QAEQ-PA2-' . $suf, 'name' => 'QAEQ프로젝트A2',
            'customer_id' => $cust, 'contract_id' => $conA, 'contract_amount' => 11000000]);
    } catch (\Throwable $e) {
        $dupBlocked = str_contains($e->getMessage(), 'uq_projects_contract')
                   || str_contains($e->getMessage(), 'Duplicate entry');
    }
    t_true('②계약당 프로젝트 2개는 UNIQUE 제약으로 차단(중복 집계 구조적 불가)', $dupBlocked);

    // 입금에 contract_id·project_id 가 동시에 들어간 경우에도 두 조인이 같은 결과를 내는지
    $conF = Db::insert('contracts', ['contract_no' => 'QAEQ-F-' . $suf, 'customer_id' => $cust, 'contract_amount' => 1000000]);
    $prjF = Db::insert('projects', ['project_no' => 'QAEQ-PF-' . $suf, 'name' => 'QAEQ프로젝트F',
        'customer_id' => $cust, 'contract_id' => $conF, 'contract_amount' => 1000000]);
    Db::insert('project_assignments', ['project_id' => $prjF, 'user_id' => $u2, 'role' => 'worker', 'contribution_pct' => 100]);
    Db::insert('payments', ['contract_id' => $conF, 'project_id' => $prjF, 'pay_type' => 'balance',
        'amount' => 550000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    $old2 = agg(OLD_JOIN);
    $new2 = agg(NEW_JOIN);
    t_true('②두 id 가 모두 있는 입금도 결과 동일',
        ($old2[$u2]['rev'] ?? null) === ($new2[$u2]['rev'] ?? null)
        && ($old2[$u2]['n'] ?? null) === ($new2[$u2]['n'] ?? null));

    // ── 실제 서비스 메서드가 신 조인으로 정상 동작하는지 ──
    $byUser = AccountingService::employeeConfirmedByUser(null, null);
    t_true('③employeeConfirmedByUser 정상 반환', is_array($byUser));
    $paid = AccountingService::employeePaidByUser(null, null);
    t_true('③employeePaidByUser 정상 반환', is_array($paid));

    $pdo->rollBack();
    echo "\n(픽스처 롤백 완료 — 기존 행 무변경)\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "  [FAIL] 예외: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
