#!/bin/bash
# DR 테스트 T3 — 격리 복구 환경 생성.
#
# 만드는 것: 복구 디렉터리 골격 / 복구 전용 DB·계정 / 복구 전용 설정 / 배너 라우터.
# 만들지 않는 것: 운영 접속정보. cafe24.env 는 복구 환경에 복사하지 않는다 —
# 복구본이 운영에 닿을 수 있는 경로를 애초에 두지 않는 게 가장 확실한 차단이다.
#
# 사용: bash scripts/dr/setup_restore_env.sh

source "$(dirname "${BASH_SOURCE[0]}")/dr_env.sh"
dr_guard

T0=$(date +%s)
echo "── T3 격리 복구환경 생성 ──"

# ── 1. 디렉터리 골격 ────────────────────────────────────────────────────────
# _dr/ 은 복구 테스트용 부속물이다. 백업에서 복원되는 파일과 절대 섞이지 않도록
# 복원 대상 경로(app/public/storage) 밖에 따로 둔다.
mkdir -p "$RESTORE_ROOT/_dr/sessions" "$RESTORE_ROOT/_dr/logs" "$RESTORE_ROOT/_dr/evidence"
chmod 700 "$RESTORE_ROOT/_dr/sessions"
echo "  디렉터리: $RESTORE_ROOT"

# ── 2. 복구 전용 DB·계정 ────────────────────────────────────────────────────
# 개발 DB(eden_crm)와 다른 DB, 다른 계정. 권한도 이 DB 하나로 한정한다.
rdb_root <<SQL
CREATE DATABASE IF NOT EXISTS \`$RESTORE_DB\`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$RESTORE_DB_USER'@'localhost' IDENTIFIED BY '$RESTORE_DB_PASS';
GRANT ALL PRIVILEGES ON \`$RESTORE_DB\`.* TO '$RESTORE_DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "  복구 DB : $RESTORE_DB (계정 $RESTORE_DB_USER — 이 DB 에만 권한)"

# import 전 비어 있는지 확인. 비어 있지 않으면 이전 시도의 잔재이므로 알린다.
EXISTING=$(rdb_root -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RESTORE_DB'")
echo "  import 전 테이블 수: $EXISTING (0 이어야 정상)"

# ── 3. 복구 전용 설정 파일 ──────────────────────────────────────────────────
# 운영 값은 하나도 쓰지 않는다. TBL_PREFIX 만 운영과 같게 두는데, 이는 백업 덤프가
# edencrm_ prefix 테이블을 담고 있고 운영과 동일한 SQL rewrite 경로를 타야
# "운영이 실제로 복구되는가"를 검증하는 의미가 있기 때문이다.
cat > "$RESTORE_ROOT/_dr/config.restore.php" <<PHPCONF
<?php
/**
 * DR 복구 테스트 전용 설정 — 운영 값 없음.
 * EDEN_CONFIG_LOCAL 환경변수로 주입되며, 복원된 app/config/config.local.php 를
 * 대체한다(복원본 코드는 한 줄도 수정하지 않는다).
 */
return [
    'APP_ENV'      => 'local',                       // 오류를 화면에 노출 → QA 에서 즉시 검출
    'BASE_URL'     => 'http://127.0.0.1:$RESTORE_PORT',
    'SESSION_NAME' => 'eden_restore_sid',            // 운영·개발 쿠키와 분리
    'TBL_PREFIX'   => '$RESTORE_PREFIX',             // 운영과 동일 경로 검증
    'DB_HOST'      => '127.0.0.1',
    'DB_PORT'      => 3307,
    'DB_SOCKET'    => '$DEV_SOCK',
    'DB_NAME'      => '$RESTORE_DB',
    'DB_USER'      => '$RESTORE_DB_USER',
    'DB_PASS'      => '$RESTORE_DB_PASS',
    'DR_RESTORE_TEST' => true,                       // 배너·차단 로직의 판별 플래그
];
PHPCONF
chmod 600 "$RESTORE_ROOT/_dr/config.restore.php"
echo "  설정    : _dr/config.restore.php (운영 접속정보 미포함)"

# ── 4. 배너 라우터 ──────────────────────────────────────────────────────────
# 복원된 파일을 고치지 않고 "복구 테스트 환경" 표시를 넣기 위해 php -S 라우터를 쓴다.
# 복원본을 수정하면 그건 더 이상 "백업본 그대로의 복구"가 아니게 된다.
cat > "$RESTORE_ROOT/_dr/router.php" <<'PHPROUTER'
<?php
/**
 * DR 복구 테스트 라우터 (php -S 전용).
 *  - 검색엔진 차단 헤더
 *  - HTML 응답에만 "복구 테스트 환경" 배너 주입 (JSON·파일 다운로드는 건드리지 않음)
 *  - 복원된 애플리케이션 코드는 일절 수정하지 않는다
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$docRoot = $_SERVER['DOCUMENT_ROOT'];

// 정적 파일(css/js/이미지)만 내장 서버에 넘긴다.
// .php 까지 넘기면 index.php 요청이 라우터를 우회해 배너·헤더가 적용되지 않는다.
if ($uri !== '/' && is_file($docRoot . $uri) && !str_ends_with(strtolower($uri), '.php')) {
    return false;
}

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-DR-Restore-Test: 1');

$banner = '<div id="dr-restore-banner" style="position:fixed;top:0;left:0;right:0;z-index:2147483647;'
        . 'background:#b91c1c;color:#fff;font:600 13px/1.5 system-ui,-apple-system,sans-serif;'
        . 'padding:6px 12px;text-align:center;letter-spacing:.02em;box-shadow:0 1px 4px rgba(0,0,0,.3)">'
        . '⚠ 재해복구 테스트 환경 — 운영 데이터 아님 · 복구본(RESTORE TEST) · 외부 발송 없음'
        . '</div><div style="height:29px"></div>';

ob_start(function ($out) use ($banner) {
    foreach (headers_list() as $h) {
        // HTML 이 아니면(JSON·이미지·첨부 다운로드) 절대 손대지 않는다.
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
            return $out;
        }
    }
    if (stripos($out, '<body') === false) return $out;
    return preg_replace('/(<body\b[^>]*>)/i', '$1' . $banner, $out, 1);
});

require $docRoot . '/index.php';
PHPROUTER
echo "  라우터  : _dr/router.php (배너·noindex — 복원본 코드 무수정)"

# ── 5. 기동 스크립트 ────────────────────────────────────────────────────────
cat > "$RESTORE_ROOT/_dr/serve.sh" <<SERVE
#!/bin/bash
# 복구 환경 웹서버 기동 (127.0.0.1 바인딩 — 외부 접근 불가)
export EDEN_CONFIG_LOCAL="$RESTORE_ROOT/_dr/config.restore.php"
exec "$PHP_BIN" \\
  -d session.save_path="$RESTORE_ROOT/_dr/sessions" \\
  -d session.name=eden_restore_sid \\
  -d log_errors=1 \\
  -d error_log="$RESTORE_ROOT/_dr/logs/php-error.log" \\
  -S 127.0.0.1:$RESTORE_PORT \\
  -t "$RESTORE_ROOT/public" \\
  "$RESTORE_ROOT/_dr/router.php"
SERVE
chmod +x "$RESTORE_ROOT/_dr/serve.sh"
echo "  기동    : _dr/serve.sh (127.0.0.1:$RESTORE_PORT · 세션·로그 경로 분리)"

# ── 6. 격리 확인 기록 ───────────────────────────────────────────────────────
cat > "$RESTORE_ROOT/_dr/ISOLATION.md" <<ISO
# 복구 테스트 환경 격리 상태

| 축 | 운영 | 복구 테스트 |
|---|---|---|
| 파일 경로 | Cafe24 FTP 원격 | \`$RESTORE_ROOT\` |
| DB | Cafe24 MariaDB 10.6 (공유 스키마) | \`$RESTORE_DB\` @ 로컬 격리 MySQL 9.6 (:3307, 프로젝트 내부 datadir) |
| DB 계정 | 운영 계정 | \`$RESTORE_DB_USER\` — 복구 DB 에만 권한 |
| 웹 | https 운영 도메인 | http://127.0.0.1:$RESTORE_PORT (루프백 바인딩) |
| 세션 쿠키 | eden_crm_sid | eden_restore_sid |
| 세션 저장소 | 서버 기본 | \`_dr/sessions\` (0700) |
| 로그 | storage/logs | \`_dr/logs\` + 복원본 storage/logs |
| 업로드 | 운영 storage/uploads | 복원본 storage/uploads |
| 운영 접속정보 | — | **복구 환경에 미배치** (cafe24.env 미복사) |
| 검색엔진 | 허용 | X-Robots-Tag: noindex,nofollow |
| 외부 발송 | — | 앱 코드에 외부 연동 없음(curl/mail/webhook 0건) |

생성: $(date '+%Y-%m-%d %H:%M:%S %Z')
ISO

T1=$(date +%s)
echo "── T3 완료 ($((T1 - T0))초) ──"
