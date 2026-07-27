<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "Util::periodRange 기간 프리셋 경계 (R4 T1 — 견적·계약·파이프라인 공통)\n";

if (!function_exists('t_str')) {
    function t_str(string $label, ?string $expected, $actual): void {
        _t_record($actual === $expected, $label, var_export($expected, true), var_export($actual, true));
    }
}
/** periodRange 결과 from/to 를 한 번에 검증. */
function t_range(string $label, ?string $ef, ?string $et, array $r): void {
    t_str("$label · from", $ef, $r['from']);
    t_str("$label · to",   $et, $r['to']);
}

// ── today ──
t_range('today(2026-07-23)', '2026-07-23', '2026-07-23', Util::periodRange('today', null, null, '2026-07-23'));

// ── this_week (주 시작=월요일) ──
t_range('this_week 목요일 앵커(07-23)', '2026-07-20', '2026-07-26', Util::periodRange('this_week', null, null, '2026-07-23'));
t_range('this_week 월요일 경계(07-20)', '2026-07-20', '2026-07-26', Util::periodRange('this_week', null, null, '2026-07-20'));
t_range('this_week 일요일 경계(07-26)', '2026-07-20', '2026-07-26', Util::periodRange('this_week', null, null, '2026-07-26'));
t_range('this_week 월 경계 걸친 주(03-01 일요일)', '2026-02-23', '2026-03-01', Util::periodRange('this_week', null, null, '2026-03-01'));
t_range('this_week 연 경계 걸친 주(01-01 목요일)', '2025-12-29', '2026-01-04', Util::periodRange('this_week', null, null, '2026-01-01'));

// ── this_month (월초·월말·윤년) ──
t_range('this_month 월초(07-01)', '2026-07-01', '2026-07-31', Util::periodRange('this_month', null, null, '2026-07-01'));
t_range('this_month 월말(01-31)', '2026-01-01', '2026-01-31', Util::periodRange('this_month', null, null, '2026-01-31'));
t_range('this_month 윤년 2월(2024-02-15)', '2024-02-01', '2024-02-29', Util::periodRange('this_month', null, null, '2024-02-15'));

// ── last_month (연초 이월·31일 overflow 방지) ──
t_range('last_month 연초(01-15→전년 12월)', '2025-12-01', '2025-12-31', Util::periodRange('last_month', null, null, '2026-01-15'));
t_range('last_month 31일 앵커(07-31→6월)', '2026-06-01', '2026-06-30', Util::periodRange('last_month', null, null, '2026-07-31'));
t_range('last_month 3/31→2월(28일) overflow 방지', '2026-02-01', '2026-02-28', Util::periodRange('last_month', null, null, '2026-03-31'));

// ── last_3m (당월 포함 3개 캘린더 월) ──
t_range('last_3m(07-23→5/1~7/31)', '2026-05-01', '2026-07-31', Util::periodRange('last_3m', null, null, '2026-07-23'));
t_range('last_3m 연 경계(01-31→작년 11/1~1/31)', '2025-11-01', '2026-01-31', Util::periodRange('last_3m', null, null, '2026-01-31'));
t_range('last_3m 2월 앵커(02-15→12/1~2/28)', '2025-12-01', '2026-02-28', Util::periodRange('last_3m', null, null, '2026-02-15'));

// ── this_year (연초·연말) ──
t_range('this_year 연초(01-01)', '2026-01-01', '2026-12-31', Util::periodRange('this_year', null, null, '2026-01-01'));
t_range('this_year 연말(12-31)', '2026-01-01', '2026-12-31', Util::periodRange('this_year', null, null, '2026-12-31'));

// ── custom ──
t_range('custom 양쪽 지정', '2026-03-01', '2026-03-15', Util::periodRange('custom', '2026-03-01', '2026-03-15'));
t_range('custom from 만', '2026-03-01', null, Util::periodRange('custom', '2026-03-01', null));
t_range('custom to 만', null, '2026-03-15', Util::periodRange('custom', null, '2026-03-15'));
t_range('custom from>to 교환', '2026-03-01', '2026-03-15', Util::periodRange('custom', '2026-03-15', '2026-03-01'));
t_range('custom 잘못된 날짜(2026-02-30)→null', null, '2026-03-15', Util::periodRange('custom', '2026-02-30', '2026-03-15'));
t_range('custom 미입력→전체', null, null, Util::periodRange('custom', null, null));

// ── 미지정/알 수 없는 키 → 전체(필터 미적용) ──
t_range("period=''→전체", null, null, Util::periodRange('', null, null, '2026-07-23'));
t_range("period='foo'→전체", null, null, Util::periodRange('foo', null, null, '2026-07-23'));

// ── 프리셋(비 custom)은 date_from/to 무시 ──
t_range('프리셋 시 날짜 입력 무시', '2026-07-01', '2026-07-31', Util::periodRange('this_month', '2020-01-01', '2020-02-02', '2026-07-23'));

// ── 앵커 생략 시 오늘 기준 ──
$today = date('Y-m-d');
t_range('앵커 생략 today=오늘', $today, $today, Util::periodRange('today'));

// ── 프리셋 정의 7종 고정 ──
t_int('프리셋 7종', 7, count(Util::PERIOD_PRESETS));
t_true('프리셋 키 고정', array_keys(Util::PERIOD_PRESETS) === ['today', 'this_week', 'this_month', 'last_month', 'last_3m', 'this_year', 'custom']);

exit(t_summary());
