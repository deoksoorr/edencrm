<?php
/** 전체 회계 테스트 스위트 실행 + A~G 대사표. */
$suites = ['unit_money', 'unit_profit', 'unit_settings', 'db_schema', 'db_aggregations'];
$fail = 0;
foreach ($suites as $s) {
    echo "\n=== $s ===\n";
    $code = 0; passthru('php ' . escapeshellarg(__DIR__ . "/$s.php"), $code);
    $fail += $code;
}
echo "\n================ 대사 테스트 A~G 커버리지 ================\n";
$map = [
    'A 정상이익(공급100M·원가70M→순이익30M·30%)' => 'unit_profit',
    'B 적자(공급50M·원가60M→-10M·-20%)'          => 'unit_profit',
    'C 부분입금(총액100M·입금40M→미수60M)'        => 'db_aggregations',
    'D 2인 기여(순이익20M·70/30→14M/6M)'          => 'unit_profit',
    'E 목표 미설정(NULL→달성률 null)'             => 'unit_profit',
    'F 계약취소(확정매출 제외)'                   => 'db_aggregations',
    'G 중복 JOIN 방지(계약액 1회)'                => 'db_aggregations',
];
foreach ($map as $case => $suite) { printf("  %-45s → %s\n", $case, $suite); }
echo ($fail === 0 ? "\n✅ 전체 통과\n" : "\n❌ 실패 스위트 존재\n");
exit($fail === 0 ? 0 : 1);
