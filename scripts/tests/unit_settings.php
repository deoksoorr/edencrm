<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "Settings 헬퍼 / feature_worklog\n";

t_true('feature_worklog 기본 OFF', Settings::enabled('feature_worklog') === false);
t_true('get 기본값', Settings::get('__none__', 'x') === 'x');

exit(t_summary());
