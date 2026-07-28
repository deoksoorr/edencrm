<?php
/** R15 — 폼 검증 개편: dateOrNull 안전망(컨트롤러 nullIfEmpty→dateOrNull 치환의 스모크) (트랜잭션 롤백). */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "R15 회귀 (트랜잭션 롤백)\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    // ── Step 1: Util::dateOrNull 안전망 — 잘못된/빈 날짜가 500 대신 NULL 로 흡수되는지 ──
    t_null('잘못된 날짜(월/일 범위 초과) → null', Util::dateOrNull('2026-13-99'));
    t_null('빈 문자열 → null', Util::dateOrNull(''));
    $ok = Util::dateOrNull('2026-07-28');
    t_true('정상 날짜는 정규화되어 통과', $ok === '2026-07-28');

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "  [FAIL] 예외: " . $e->getMessage() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
