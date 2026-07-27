#!/bin/bash
# EDEN CRM 롤백 — R6 T4 스켈레톤, 실행은 T12 코디네이터 전용. ⚠️ 파괴적 작업.
# (1) DB: database/cafe24/rollback.sql (edencrm_% 한정 DROP · 파일 내 안전핀을
#     직접 주석 처리해야 실행 가능) — phpMyAdmin 등으로 수동 실행.
# (2) 파일: CONFIRM=yes 일 때만 원격 배포 디렉토리 삭제. 기본은 목록 출력만.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
set -a; source "$SCRIPT_DIR/cafe24.env"; set +a
case "$FTP_REMOTE_PATH" in
  /www/eden-crm) ;;  # 기대 경로만 허용 — 오타로 타 프로젝트 삭제 방지
  *) echo "안전 가드: FTP_REMOTE_PATH 가 /www/eden-crm 이 아님($FTP_REMOTE_PATH) — 중단"; exit 1;;
esac
echo "DB 롤백 SQL: $PROJECT_DIR/database/cafe24/rollback.sql (edencrm_% 39개 한정 · 안전핀 참고)"
echo "삭제 예정 원격 경로: $FTP_REMOTE_PATH"
S="$(mktemp)"; chmod 600 "$S"; trap 'rm -f "$S"' EXIT
cat > "$S" <<LFTP
set ftp:ssl-allow no
set ftp:passive-mode true
open -p $FTP_PORT -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
find $FTP_REMOTE_PATH
bye
LFTP
echo "== 삭제 대상 파일 목록 =="; lftp -f "$S" || true
if [ "${CONFIRM:-no}" = "yes" ]; then
  R="$(mktemp)"; chmod 600 "$R"; trap 'rm -f "$R"' EXIT
  printf 'set ftp:ssl-allow no\nset ftp:passive-mode true\nopen -p %s -u %s,%s %s\nrm -r %s\nbye\n' \
    "$FTP_PORT" "$FTP_USER" "$FTP_PASSWORD" "$FTP_HOST" "$FTP_REMOTE_PATH" > "$R"
  lftp -f "$R"; echo "원격 삭제 완료: $FTP_REMOTE_PATH"
else
  echo "실제 삭제하려면: CONFIRM=yes $0  (그 전에 backup.sh 필수)"
fi
