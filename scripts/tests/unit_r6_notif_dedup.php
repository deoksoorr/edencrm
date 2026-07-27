<?php
/**
 * R6 T10 — 알림 dedup 오탐 수정 재현·회귀 테스트 (perf HOLD §5-5 기능결함).
 *
 * 결함: 원본 중복확인은 `link_params LIKE '%"_eid":<id>%'`(=부분문자열 매칭)라, 같은 user+type 에서
 *       eid 5 가 같은 날 먼저 생성된 51 의 부분문자열("_eid":5 ⊂ "_eid":51)로 억제되어 정당한 알림이 누락됐다.
 * 수정: NotificationsController::notifiedToday 가 `_eid` 정수를 정확히 비교하도록 교정(오탐만 제거).
 *
 * DB 없이 순수 로직만 검증한다 — private static `$todayEid` 를 리플렉션으로 주입해
 * loadTodayDedup(DB 조회)을 우회하고, notifiedToday/extractEid 를 직접 호출한다.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/controllers/NotificationsController.php';

// PHP 8.1+ 에서 private 접근에 setAccessible 불필요(8.5 는 deprecated) — <8.1 만 호출.
$open = function ($r) { if (PHP_VERSION_ID < 80100) { $r->setAccessible(true); } return $r; };

$ref      = new ReflectionClass('NotificationsController');
$eidProp  = $open($ref->getProperty('todayEid'));
$notified = $open($ref->getMethod('notifiedToday'));
$extract  = $open($ref->getMethod('extractEid'));

$setState = function (array $state) use ($eidProp) { $eidProp->setValue(null, $state); };
$isNotified = fn(int $u, string $t, int $e) => (bool) $notified->invoke(null, $u, $t, $e);

echo "── 재현: 원본 부분문자열 방식의 오탐(문서화) ──\n";
$lp51 = json_encode(['id' => 51, '_eid' => 51], JSON_UNESCAPED_UNICODE); // 실제 저장 형식
t_true('[재현] 원본 방식(strpos)은 eid=5 를 eid=51 안에서 오탐', strpos($lp51, '"_eid":5') !== false);

echo "\n── 수정: 정확 eid 비교(오탐 제거) ──\n";
$setState(['7|lead_contact_due' => [51 => true]]);
t_true('오탐 제거: eid=5 는 eid=51 존재로 억제되지 않음(정당 알림 생성)', $isNotified(7, 'lead_contact_due', 5) === false);

$setState(['7|lead_contact_due' => [51 => true]]);
t_true('정확 매칭: eid=51 은 이미 생성됨 → 억제', $isNotified(7, 'lead_contact_due', 51) === true);

$setState(['7|payment_overdue' => [5 => true]]);
t_true('오탐 제거(역방향): eid=51 은 eid=5 존재로 억제되지 않음', $isNotified(7, 'payment_overdue', 51) === false);

echo "\n── user+type 격리 ──\n";
$setState(['7|lead_contact_due' => [5 => true]]);
t_true('다른 type 은 억제 안 됨(payment_due)', $isNotified(7, 'payment_due', 5) === false);
$setState(['7|lead_contact_due' => [5 => true]]);
t_true('다른 user 는 억제 안 됨(uid 8)', $isNotified(8, 'lead_contact_due', 5) === false);

echo "\n── 실행 중 즉시 반영(push 순서 의존 보존) ──\n";
$setState([]);
t_true('신규 eid=5 최초 호출 → 미억제(생성)', $isNotified(7, 'lead_contact_due', 5) === false);
t_true('동일 eid=5 재호출 → 억제(즉시 반영)', $isNotified(7, 'lead_contact_due', 5) === true);
t_true('다른 eid=51 은 여전히 미억제(오탐 없음)', $isNotified(7, 'lead_contact_due', 51) === false);
t_true('직전 생성된 eid=51 재호출 → 억제', $isNotified(7, 'lead_contact_due', 51) === true);

echo "\n── extractEid(JSON _eid 추출) ──\n";
t_int('정상 파싱(_eid=51)', 51, (int) $extract->invoke(null, json_encode(['id' => 7, '_eid' => 51])));
t_int('id≠_eid 케이스 — _eid 우선', 42, (int) $extract->invoke(null, json_encode(['id' => 7, '_eid' => 42])));
t_true('_eid 키 없음 → null', $extract->invoke(null, json_encode(['id' => 7])) === null);
t_true('빈 문자열 → null', $extract->invoke(null, '') === null);
t_true('null 입력 → null', $extract->invoke(null, null) === null);
t_true('비정수 _eid → null(주입 방지)', $extract->invoke(null, json_encode(['_eid' => 'x'])) === null);

exit(t_summary());
