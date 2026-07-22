<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
echo "기능 플래그\n";
// 기본 OFF
t_true('feature_worklog 기본 OFF', Settings::enabled('feature_worklog') === false);
// routes.php 의 worklog 라우트에 feature 메타가 있는지(정적 검사)
$routes = require dirname(__DIR__,2).'/app/routes.php';
$missing = [];
foreach (['worklogs.index','worklogs.form','worklogs.save','worklogs.show','worklogs.confirm','worklogs.photo'] as $rk) {
    if (!isset($routes[$rk]) || ($routes[$rk]['feature'] ?? null) !== 'worklog') { $missing[] = $rk; }
}
t_int('worklog 라우트 feature 메타 누락 수', 0, count($missing));
exit(t_summary());
