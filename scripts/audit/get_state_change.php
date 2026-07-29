<?php
// GET 라우트 액션 본문에서 DB 쓰기 호출을 찾는다 (감사 전용, 앱 코드 미변경).
$routes = require __DIR__ . '/../../app/routes.php';
$writePat = '/Db::insert\(|Db::update\(|Db::run\(\s*["\']\s*(UPDATE|DELETE|INSERT|REPLACE)|Audit::log\(|->exec\(/i';
foreach ($routes as $key => $o) {
    if (($o['method'] ?? 'GET') === 'POST') continue;
    [$ctrl, $action] = $o;
    $file = __DIR__ . '/../../app/controllers/' . $ctrl . '.php';
    if (!is_file($file)) { echo "MISSING CONTROLLER $ctrl ($key)\n"; continue; }
    $src = file_get_contents($file);
    // 메서드 본문 추출 (중괄호 균형)
    if (!preg_match('/function\s+' . preg_quote($action, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        echo "NO METHOD $ctrl::$action ($key)\n"; continue;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1; $i = $start; $len = strlen($src);
    while ($i < $len && $depth > 0) { if ($src[$i]==='{') $depth++; elseif ($src[$i]==='}') $depth--; $i++; }
    $body = substr($src, $start, $i - $start - 1);
    $hits = [];
    if (preg_match_all('/(Db::insert\(|Db::update\(|Db::run\(\s*["\'](?:\s*)(?:UPDATE|DELETE|INSERT|REPLACE)[^"\']{0,60})/i', $body, $mm)) {
        $hits = array_unique($mm[1]);
    }
    // 서비스 호출로 상태를 바꿀 수 있는 후보
    if (preg_match_all('/(StatusService::|ProcessService::|BonusService::|GoalService::|ContractProjectService::|PipelineStageService::)\w+/', $body, $ms)) {
        $hits = array_merge($hits, array_unique($ms[0]));
    }
    if ($hits) {
        echo str_pad($key, 30) . " $ctrl::$action  =>  " . implode(' | ', array_map(fn($h)=>trim(preg_replace('/\s+/',' ',$h)), $hits)) . "\n";
    }
}
