#!/bin/bash
# ============================================================================
# EDEN CRM 카페24(<DB_ACCOUNT>) FTP 배포 — R6 T4 스켈레톤. 실행은 T12 코디네이터 전용.
# 사용:
#   ./deploy/deploy.sh                → 검사 + dry-run(전송 목록만, 업로드 없음)
#   CONFIRM=yes ./deploy/deploy.sh    → 실제 업로드
# 전제: deploy/cafe24.env (git 제외), lftp 설치, 001/002 마이그레이션은 별도 선행(T12).
#
# 이 스크립트는 **파일만** 다룬다. DB 에는 접속하지 않으며 어떤 쿼리도 실행하지 않는다.
# (DB 백업은 deploy/db_dump.php, 마이그레이션은 별도 절차)
#
# 비밀번호 취급: 이전 버전은 "비밀번호는 어떤 출력에도 나타나지 않는다"고 적어 두었으나
# 실제로는 lftp 가 자체 출력에 접속 URL(ftp://user:pass@host)을 그대로 찍어 로그에
# 평문으로 남았다(2026-07-29 실측, 로그 14개에 잔존). 아래 mask_secrets 로 차단한다.
# ============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$SCRIPT_DIR/cafe24.env"
[ -f "$ENV_FILE" ] || { echo "환경파일 없음: $ENV_FILE"; exit 1; }
set -a; source "$ENV_FILE"; set +a
for v in FTP_HOST FTP_PORT FTP_USER FTP_PASSWORD FTP_REMOTE_PATH SERVICE_URL TBL_PREFIX; do
  [ -n "${!v:-}" ] || { echo "필수 환경값 누락: $v"; exit 1; }
done
command -v lftp >/dev/null || { echo "lftp 미설치. 'brew install lftp'"; exit 1; }

MODE="dry-run(전송 없음)"; DRY_FLAG="--dry-run"
if [ "${CONFIRM:-no}" = "yes" ]; then MODE="실제 업로드"; DRY_FLAG=""; fi
echo "배포 모드   : $MODE"
echo "배포 호스트 : $FTP_HOST"
echo "원격 경로   : $FTP_REMOTE_PATH"
echo "FTP 사용자  : $FTP_USER"
echo "FTP 비밀번호: ********"
echo "TBL_PREFIX  : $TBL_PREFIX"

echo "== 운영 config 생성 (cafe24.env → config.production.php) =="
php "$SCRIPT_DIR/gen_config_production.php"

echo "== PHP 문법 검사 =="
find "$PROJECT_DIR" -name '*.php' \
  -not -path '*/.devdb/*' -not -path '*/deploy/*' -not -path '*/.git/*' -print0 \
  | while IFS= read -r -d '' f; do php -l "$f" >/dev/null || { echo "문법 오류: $f"; exit 1; }; done
echo "  문법 이상 없음"

# lftp 출력에서 접속 URL의 비밀값을 가린다. lftp 는 mkdir/open 등을 자체 출력할 때
# ftp://user:pass@host 형태를 그대로 찍는다 — 이게 로그에 평문으로 남던 원인이다.
mask_secrets() {
    sed -e "s|ftp://[^@ ]*@|ftp://***:***@|g" \
        -e "s|${FTP_PASSWORD}|***|g" \
        -e "s|-u ${FTP_USER},[^ ]*|-u ***,***|g"
}

echo "== 업로드 (mirror --reverse --delete$( [ -n "$DRY_FLAG" ] && echo ' · dry-run')) =="
LOG="$SCRIPT_DIR/deploy_$(date +%Y%m%d-%H%M%S).log"
LFTP_SCRIPT="$(mktemp)"; chmod 600 "$LFTP_SCRIPT"
TMP_RM="$(mktemp)"; TMP_TX="$(mktemp)"; TMP_DEL="$(mktemp)"   # 전송 요약 계산용
trap 'rm -f "$LFTP_SCRIPT" "$TMP_RM" "$TMP_TX" "$TMP_DEL"' EXIT
# 제외 목록: 개발 전용·비밀·DB 산출물 전부. app/config/config.local.php(로컬 비밀) 제외 필수.
#
# --ignore-time 을 뺀 이유 (2026-07-29):
#   이 옵션은 lftp 가 **크기만** 비교하게 만든다. 그래서 바이트 수가 같은 변경은
#   영원히 배포되지 않았다. 실제로 커밋 3d35593(버튼 문구 통일)의 뷰 8개가
#   `작성`→`등록`, `조회`→`검색` 처럼 UTF-8 길이가 같아 전부 누락된 채 남아 있었다.
#   앞으로도 `>`→`>=`, 상태 문자열 교체 같은 동일 길이 수정이 조용히 빠질 수 있다.
#   제거하면 시각까지 비교하므로 매번 전량에 가깝게 재전송되지만, 전체가 2.3MB 라
#   수 초면 끝난다. "빠뜨리지 않는 것"이 "덜 보내는 것"보다 중요하다.
#
# --delete 를 넣은 이유:
#   로컬에서 삭제된 파일이 운영에 계속 남아 있었다(예: app/views/dashboard/boss.php 등
#   커밋 28deb85 에서 지운 4개). 로컬과 운영을 동일하게 유지하려면 삭제도 반영해야 한다.
#   위 --exclude 대상(storage/uploads/, storage/logs/, app/config/config.local.php,
#   database/, deploy/ …)은 mirror 범위 밖이라 --delete 의 삭제 대상이 되지 않는다.
#   업로드 파일·운영 설정·로그는 안전하다. 반드시 dry-run 으로 삭제 목록을 먼저 확인할 것.
cat > "$LFTP_SCRIPT" <<LFTP
set ftp:ssl-allow no
set ftp:passive-mode true
set net:max-retries 2
set net:timeout 25
set mirror:parallel-transfer-count 3
open -p $FTP_PORT -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
mkdir -f -p $FTP_REMOTE_PATH
mirror --reverse --verbose --no-perms --delete $DRY_FLAG \
  --exclude-glob .git/ --exclude .gitignore \
  --exclude deploy/ --exclude database/ --exclude scripts/ --exclude docs/ \
  --exclude .superpowers/ --exclude .devdb/ --exclude .claude/ --exclude .vscode/ \
  --exclude app/config/config.local.php \
  --exclude storage/uploads/ --exclude storage/logs/ \
  --exclude-glob *.md --exclude-glob *.log --exclude-glob *.sql \
  --exclude-glob .DS_Store --exclude-glob .env --exclude-glob .env.* \
  "$PROJECT_DIR/" "$FTP_REMOTE_PATH/"
mkdir -f -p $FTP_REMOTE_PATH/storage/uploads
mkdir -f -p $FTP_REMOTE_PATH/storage/logs
put "$PROJECT_DIR/storage/.htaccess" -o "$FTP_REMOTE_PATH/storage/.htaccess"
put "$SCRIPT_DIR/config.production.php" -o "$FTP_REMOTE_PATH/app/config/config.local.php"
bye
LFTP
if [ -n "$DRY_FLAG" ]; then
  # dry-run 에서는 mirror 목록만 의미 있음 — put/mkdir 는 실제 수행되므로 제거
  sed -i '' -e '/^mkdir -f -p .*storage/d' -e '/^put /d' "$LFTP_SCRIPT"
fi
# 마스킹은 tee 앞에 둔다 — 로그 파일에도 평문이 남지 않아야 한다.
lftp -f "$LFTP_SCRIPT" 2>&1 | mask_secrets | tee "$LOG" | grep -viE 'Transferring|already' || true
chmod 600 "$LOG" 2>/dev/null || true

echo "== 전송 요약 =="
# lftp 는 **덮어쓰기** 때도 "Removing old file" 을 먼저 찍는다(기존 파일을 지우고 올림).
# 그래서 그 줄을 그대로 세면 이번처럼 3개 파일을 갱신했을 뿐인데 "삭제 3건"이 된다.
# 삭제 목록은 배포 전 사람이 마지막으로 눈으로 확인하는 관문이라, 매번 덮어쓰기가 섞여
# 나오면 곧 안 읽게 되고 진짜 삭제 1건이 그 속에 묻힌다 — 관문 자체가 무력해진다.
# 따라서 "지웠는데 다시 올리지 않은 경로"만 진짜 삭제로 본다.
lpath() { sed -nE "s/^[^\`]*\`(.*)'.*$/\1/p"; }
grep -iE '^Removing old file' "$LOG" | lpath | sort -u > "$TMP_RM"
grep -iE '^Transferring file|^(get|put) '  "$LOG" | lpath | sort -u > "$TMP_TX"
comm -23 "$TMP_RM" "$TMP_TX" > "$TMP_DEL"
NTX=$(grep -ci '^Transferring file' "$LOG" || true)
NDEL=$(grep -c . "$TMP_DEL" || true)
NOVR=$(comm -12 "$TMP_RM" "$TMP_TX" | grep -c . || true)
printf "  업로드 %s건(덮어쓰기 %s) · 삭제 %s건\n" "$NTX" "$NOVR" "$NDEL"
if [ "$NDEL" -gt 0 ]; then
    echo "  삭제 대상(운영에서 사라짐):"
    sed 's/^/    /' "$TMP_DEL"
fi
echo "== 완료($MODE). 로그: $LOG =="
if [ -z "$DRY_FLAG" ]; then
  echo "운영 config(app/config/config.local.php) 업로드 완료. 서비스: $SERVICE_URL"
  echo "다음 단계: ./deploy/verify.sh 로 실측 검증"
else
  echo "실제 업로드: CONFIRM=yes $0"
fi
