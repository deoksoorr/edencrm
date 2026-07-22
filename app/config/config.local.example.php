<?php
// 로컬/운영 환경별 설정 — config.local.php 로 복사 후 값 입력 (git 에 커밋 금지)
return [
    'APP_ENV'  => 'local',            // local | production
    'BASE_URL' => 'http://127.0.0.1:8080',
    'DB_HOST'  => '127.0.0.1',
    'DB_NAME'  => 'eden_crm',
    'DB_USER'  => 'root',
    'DB_PASS'  => '',
];
