<?php
/** 경량 테스트 러너 — Composer/PHPUnit 없이 사용. */
$GLOBALS['__T'] = ['pass' => 0, 'fail' => 0, 'fails' => []];

function t_int(string $label, int $expected, $actual): void {
    $a = (int) $actual;
    _t_record($a === $expected, $label, $expected, $a);
}
function t_float(string $label, ?float $expected, $actual, float $eps = 0.01): void {
    if ($expected === null) { _t_record($actual === null, $label, 'null', var_export($actual, true)); return; }
    if ($actual === null)   { _t_record(false, $label, (string) $expected, 'null'); return; }
    _t_record(abs((float) $actual - $expected) <= $eps, $label, (string) $expected, (string) $actual);
}
function t_null(string $label, $actual): void {
    _t_record($actual === null, $label, 'null', var_export($actual, true));
}
function t_true(string $label, bool $cond): void {
    _t_record($cond, $label, 'true', $cond ? 'true' : 'false');
}
function _t_record(bool $ok, string $label, $exp, $act): void {
    if ($ok) { $GLOBALS['__T']['pass']++; }
    else { $GLOBALS['__T']['fail']++; $GLOBALS['__T']['fails'][] = "$label — 기대 $exp, 실제 $act"; }
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
}
function t_summary(): int {
    $p = $GLOBALS['__T']['pass']; $f = $GLOBALS['__T']['fail'];
    echo "\n──────── 결과: PASS $p · FAIL $f ────────\n";
    foreach ($GLOBALS['__T']['fails'] as $m) { echo "  ✗ $m\n"; }
    return $f > 0 ? 1 : 0;
}
