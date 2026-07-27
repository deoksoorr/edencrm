#!/bin/bash
# EDEN CRM 원격 파일 백업(내려받기) — R6 T4 스켈레톤, 실행은 T12.
# 최초 배포 전에는 원격이 비어 있을 수 있다(정상).
# DB 백업: 카페24 MySQL 은 외부 직접 접속이 제한되므로 phpMyAdmin 에서
#   edencrm_% 테이블만 선택해 export 한다 (rollback.sql 실행 전 필수).
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
set -a; source "$SCRIPT_DIR/cafe24.env"; set +a
DEST="$PROJECT_DIR/database/backups/ftp_$(date +%Y%m%d-%H%M%S)"; mkdir -p "$DEST"
S="$(mktemp)"; chmod 600 "$S"; trap 'rm -f "$S"' EXIT
cat > "$S" <<LFTP
set ftp:ssl-allow no
set ftp:passive-mode true
open -p $FTP_PORT -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
mirror --verbose $FTP_REMOTE_PATH/ "$DEST/" || echo "(원격 비어있음/신규)"
bye
LFTP
lftp -f "$S" || true
echo "원격 파일 백업 위치: $DEST"
echo "⚠ DB 백업 별도 필요: phpMyAdmin 에서 ${TBL_PREFIX:-edencrm_}% 테이블만 export (타 프로젝트 테이블 미포함 확인)"
