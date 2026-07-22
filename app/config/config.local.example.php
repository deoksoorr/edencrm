<?php
// 로컬/운영 환경별 설정 — config.local.php 로 복사 후 값 입력 (git 에 커밋 금지)
return [
    'APP_ENV'   => 'local',            // local | production
    'BASE_URL'  => 'http://127.0.0.1:8080',
    'DB_HOST'   => '127.0.0.1',
    'DB_PORT'   => 3306,               // 격리 개발 인스턴스 사용 시 3307
    'DB_SOCKET' => '',                 // 비우면 host:port(TCP), 값이 있으면 소켓 우선
    'DB_NAME'   => 'eden_crm',
    'DB_USER'   => 'eden_crm_user',
    'DB_PASS'   => '',
];
