<?php
/**
 * PDO 래퍼 (싱글턴). Prepared Statement 만 사용한다.
 *
 * TBL_PREFIX(config): 운영 공유 DB(단일 DB 에 여러 프로젝트 공존)를 위해
 * 알려진 테이블명에 prefix 를 붙이는 SQL rewrite 레이어를 내장한다.
 * 기본값 ''(빈 문자열)이면 rewrite 경로가 전혀 실행되지 않아 로컬 동작 불변.
 */
class Db
{
    private static ?PDO $pdo = null;

    /**
     * database/schema.sql 의 전체 테이블 목록(46개) — prefix rewrite 대상.
     * 테이블을 신설하면 schema.sql 과 함께 반드시 여기에도 추가할 것.
     * (충돌 전수검사 2026-07-23: 컬럼명·별칭·SQL 내 문자열 리터럴·바인딩 플레이스홀더와
     *  겹치는 테이블명 없음 — r6-worklog ## [dbprefix] 참고)
     */
    private const TABLES = [
        'attendance_marks', 'audit_logs', 'company_targets', 'contract_status_history',
        'contract_terminations', 'contracts', 'costs', 'customer_activities',
        'customer_contacts', 'customers', 'departments', 'employee_permissions',
        'goal_history', 'goals',
        'holidays', 'leads',
        'login_attempts', 'notifications', 'payments', 'permissions', 'pipeline_stages',
        'process_stages', 'project_assignments', 'project_files', 'project_memos',
        'project_process_history', 'project_stage_progress',
        'project_status_history', 'projects', 'quote_items', 'quote_versions', 'quotes',
        'role_permissions', 'roles', 'schedule_participants', 'schedule_time_slots',
        'schedules', 'settings', 'site_bonus_history', 'site_bonuses', 'targets',
        'user_permissions', 'users', 'warranty_repairs', 'work_log_photos', 'work_logs',
    ];

    /** 해석된 prefix('' = rewrite 미작동). null 이면 아직 미해석. */
    private static ?string $tblPrefix = null;
    /** 프리컴파일 치환 정규식 — prefix 사용 시 최초 1회만 생성. */
    private static ?string $tblRegex = null;

    /**
     * TBL_PREFIX 가 설정된 경우 SQL 내 알려진 테이블명(단어 경계)에 prefix 를 붙인다.
     * - prefix 가 '' 이면 원문 그대로 반환(로컬 기본 경로 — 추가 비용 없음).
     * - SHOW 문·information_schema 참조 쿼리는 재작성하지 않는다.
     * - 문자열 리터럴('...' / "...") 내부는 치환하지 않는다(데이터 오염 방지).
     * - 단어 경계: 앞이 [0-9A-Za-z_$.:] 면 미치환 — 별칭 컬럼 접근(x.name),
     *   바인딩 플레이스홀더(:users), 이미 prefix 붙은 이름(edencrm_users) 재치환 방지.
     *   백틱 식별자(`users`)는 백틱이 경계 문자이므로 정상 치환된다.
     */
    private static function rewrite(string $sql): string
    {
        if (self::$tblPrefix === null) {
            self::$tblPrefix = (string) ($GLOBALS['config']['TBL_PREFIX'] ?? '');
            if (self::$tblPrefix !== '') {
                $names = self::TABLES;
                usort($names, fn($a, $b) => strlen($b) <=> strlen($a)); // 긴 이름 우선(안전 여유)
                self::$tblRegex = '/(?<![0-9A-Za-z_$.:])(' . implode('|', $names) . ')(?![0-9A-Za-z_$])/';
            }
        }
        if (self::$tblPrefix === '') {
            return $sql;
        }
        if (stripos($sql, 'information_schema') !== false || preg_match('/^\s*SHOW\b/i', $sql)) {
            return $sql;
        }
        // 문자열 리터럴 구간을 분리해 리터럴 밖에서만 치환한다.
        $parts = preg_split('/(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/s', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($part === '' || $part[0] === "'" || $part[0] === '"') {
                continue;
            }
            $parts[$i] = preg_replace(self::$tblRegex, self::$tblPrefix . '$1', $part);
        }
        return implode('', $parts);
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c = $GLOBALS['config'];
        if (!empty($c['DB_SOCKET']) && is_readable($c['DB_SOCKET'])) {
            $dsn = "mysql:unix_socket={$c['DB_SOCKET']};dbname={$c['DB_NAME']};charset=utf8mb4";
        } else {
            $dsn = "mysql:host={$c['DB_HOST']};port={$c['DB_PORT']};dbname={$c['DB_NAME']};charset=utf8mb4";
        }
        self::$pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASS'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$pdo;
    }

    /** 파라미터 바인딩 실행. $params 는 위치(?) 또는 이름(:x) 배열. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare(self::rewrite($sql));
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** 단일 스칼라 값 반환 (첫 행 첫 컬럼). */
    public static function val(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** 연관배열을 INSERT 하고 lastInsertId 반환. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $place = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $colSql = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $sql = "INSERT INTO `$table` ($colSql) VALUES ($place)";
        self::run($sql, self::prefixKeys($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** 연관배열로 UPDATE. $where 는 준비된 조건문(예: 'id = :id'), $params 는 그 바인딩. 영향 행 수 반환. */
    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(fn($c) => "`$c` = :set_$c", array_keys($data)));
        $bind = [];
        foreach ($data as $k => $v) {
            $bind['set_' . $k] = $v;
        }
        foreach ($params as $k => $v) {
            $bind[ltrim($k, ':')] = $v;
        }
        $sql = "UPDATE `$table` SET $set WHERE $where";
        return self::run($sql, $bind)->rowCount();
    }

    private static function prefixKeys(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[':' . $k] = $v;
        }
        return $out;
    }

    /** 트랜잭션 헬퍼. */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
