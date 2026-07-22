<?php
/**
 * PDO 래퍼 (싱글턴). Prepared Statement 만 사용한다.
 */
class Db
{
    private static ?PDO $pdo = null;

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
        $stmt = self::pdo()->prepare($sql);
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
