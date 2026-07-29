<?php
/**
 * DR 테스트 — 운영 DB 읽기 전용 접속 가드.
 *
 * 재해복구 테스트는 운영을 절대 변경하면 안 된다. 그런데 "조심해서 짜겠다"는
 * 약속만으로는 부족하다 — 실수 한 번이 운영 사고다. 그래서 방어를 2중으로 건다.
 *
 *  1) 애플리케이션 가드: 문장 선두 토큰이 SELECT/SHOW/DESCRIBE/EXPLAIN 이 아니면 차단.
 *     다중 문(;) 도 차단해 "SELECT 1; DROP TABLE x" 같은 우회를 막는다.
 *  2) 서버 가드: START TRANSACTION READ ONLY 로 세션을 연다. 가드를 뚫더라도
 *     MySQL 이 쓰기를 거부한다. 애플리케이션이 아니라 DB 가 강제하는 경계다.
 *
 * 부수 효과가 하나 더 있는데 이게 중요하다. READ ONLY 트랜잭션은 REPEATABLE READ
 * 스냅샷이라 46개 테이블을 순차 조회하는 동안에도 시점이 고정된다. 즉 이 스크립트가
 * 뜨는 기준값은 원자적이다 — deploy/db_dump.php 가 트랜잭션 없이 덤프를 뜨는 것과
 * 대조되는 지점이고, DR 판정에서 이 차이가 그대로 근거가 된다.
 */

if (PHP_SAPI !== 'cli') { exit(1); }

/** cafe24.env 파싱 — 값은 절대 출력하지 않는다. */
function dr_env(string $envFile): array
{
    if (!is_file($envFile)) {
        fwrite(STDERR, "환경파일 없음: $envFile\n");
        exit(1);
    }
    $env = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim($v);
    }
    return $env;
}

/** 운영 DB 에 읽기 전용으로 접속한다. 실패하면 즉시 종료. */
function dr_connect_readonly(array $env): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'], $env['DB_PORT'] ?: '3306', $env['DB_NAME']
    );
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    // 다중 문 비활성화 — PHP 8.5 에서 상수가 Pdo\Mysql 로 이동했다(구버전 호환 유지).
    $multi = class_exists('Pdo\\Mysql') ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS : PDO::MYSQL_ATTR_MULTI_STATEMENTS;
    $opts[$multi] = false;
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASSWORD'], $opts);
    // 서버 강제 읽기 전용 — 여기부터 이 세션은 쓰기가 불가능하다.
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
}

/** 조회문만 통과시킨다. 위반 시 예외로 즉시 중단(무시하고 진행하지 않는다). */
function ro(PDO $pdo, string $sql, array $params = []): array
{
    $head = strtoupper(strtok(ltrim($sql), " \t\n\r("));
    if (!in_array($head, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
        throw new RuntimeException("RO-GUARD 차단(비조회문): {$head}");
    }
    if (str_contains(rtrim($sql, "; \t\n\r"), ';')) {
        throw new RuntimeException('RO-GUARD 차단(다중 문)');
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** 단일 스칼라 조회 헬퍼. */
function ro_one(PDO $pdo, string $sql, array $params = [])
{
    $rows = ro($pdo, $sql, $params);
    if (!$rows) return null;
    return array_values($rows[0])[0];
}

/** 가드가 실제로 동작하는지 자체 점검 — 통과하지 못하면 스크립트를 쓰지 않는다. */
function ro_selftest(PDO $pdo): array
{
    $results = [];
    foreach ([
        'UPDATE t SET a=1'      => '비조회문',
        'DELETE FROM t'         => '비조회문',
        'DROP TABLE t'          => '비조회문',
        'SELECT 1; DROP TABLE t' => '다중 문',
    ] as $sql => $why) {
        try {
            ro($pdo, $sql);
            $results[] = ['sql' => substr($sql, 0, 24), 'blocked' => false, 'expect' => $why];
        } catch (RuntimeException $e) {
            $results[] = ['sql' => substr($sql, 0, 24), 'blocked' => true, 'expect' => $why];
        }
    }
    return $results;
}
