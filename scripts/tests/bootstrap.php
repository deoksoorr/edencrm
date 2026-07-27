<?php
/** CLI 테스트 부트스트랩 — 세션/HTTP 없이 config 상수 + 코어 클래스만 로드. */
error_reporting(E_ALL);
ini_set('display_errors', '1');
$GLOBALS['config'] = require __DIR__ . '/../../app/config/config.php'; // APP_PATH 등 상수 define + 배열 반환
foreach (['Util', 'Db', 'Calc', 'Settings', 'AccountingService', 'CostService', 'AttendanceService'] as $c) {
    $f = APP_PATH . '/core/' . $c . '.php';
    if (is_file($f)) { require_once $f; }
}
try {
    $map = [];
    foreach (Db::all("SELECT setting_key, value FROM settings") as $r) { $map[$r['setting_key']] = $r['value']; }
    $GLOBALS['settings'] = $map;
} catch (\Throwable $e) { $GLOBALS['settings'] = []; }
