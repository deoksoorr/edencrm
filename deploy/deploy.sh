#!/bin/bash
# ============================================================================
# EDEN CRM 카페24(<DB_ACCOUNT>) FTP 배포 — R6 T4 스켈레톤. 실행은 T12 코디네이터 전용.
# 사용:
#   ./deploy/deploy.sh                → 검사 + dry-run(전송 목록만, 업로드 없음)
#   CONFIRM=yes ./deploy/deploy.sh    → 실제 업로드
# 전제: deploy/cafe24.env (git 제외), lftp 설치, 001/002 마이그레이션은 별도 선행(T12).
# 비밀번호는 어떤 출력에도 나타나지 않는다.
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

echo "== 업로드 (mirror --reverse, --delete 미사용$( [ -n "$DRY_FLAG" ] && echo ' · dry-run')) =="
LOG="$SCRIPT_DIR/deploy_$(date +%Y%m%d-%H%M%S).log"
LFTP_SCRIPT="$(mktemp)"; chmod 600 "$LFTP_SCRIPT"
trap 'rm -f "$LFTP_SCRIPT"' EXIT
# 제외 목록: 개발 전용·비밀·DB 산출물 전부. app/config/config.local.php(로컬 비밀) 제외 필수.
cat > "$LFTP_SCRIPT" <<LFTP
set ftp:ssl-allow no
set ftp:passive-mode true
set net:max-retries 2
set net:timeout 25
set mirror:parallel-transfer-count 3
open -p $FTP_PORT -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
mkdir -f -p $FTP_REMOTE_PATH
mirror --reverse --verbose --no-perms --ignore-time $DRY_FLAG \
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
lftp -f "$LFTP_SCRIPT" 2>&1 | tee "$LOG" | grep -viE 'Transferring|already' || true
echo "== 완료($MODE). 로그: $LOG =="
if [ -z "$DRY_FLAG" ]; then
  echo "운영 config(app/config/config.local.php) 업로드 완료. 서비스: $SERVICE_URL"
  echo "다음 단계: ./deploy/verify.sh 로 실측 검증"
else
  echo "실제 업로드: CONFIRM=yes $0"
fi
