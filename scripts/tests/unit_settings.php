<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "Settings 헬퍼 / feature_worklog\n";

t_true('feature_worklog 기본 OFF', Settings::enabled('feature_worklog') === false);
t_true('get 기본값', Settings::get('__none__', 'x') === 'x');
t_true('feature_worklog 값 로드됨(=0, not-loaded와 구분)', Settings::get('feature_worklog') === '0');

exit(t_summary());
