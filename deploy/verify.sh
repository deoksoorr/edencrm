#!/bin/bash
# EDEN CRM 배포 후 실측 검증(읽기 전용) — R6 T4 스켈레톤, 실행은 T12.
# 로그인 페이지 렌더 + 내부 경로·민감 파일 차단 + 정적 자원 서빙 확인.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
set -a; source "$SCRIPT_DIR/cafe24.env"; set +a
U="${SERVICE_URL%/}"
code(){ curl -s -o /dev/null -w '%{http_code}' -m 25 "$1"; }

echo "서비스 URL: $U"
echo "== 정상 서빙 =="
echo "  로그인 페이지(?r=login)     : $(code "$U/index.php?r=login")  (기대 200)"
echo "  루트(/ → public 위임)       : $(code "$U/")  (기대 200 또는 302)"
echo "  CSS(assets)                 : $(code "$U/assets/css/app.css")  (기대 200)"
echo "== 차단 확인 (403/404 기대) =="
for p in "app/config/config.php" "app/bootstrap.php" "storage/" "storage/uploads/" \
         "database/schema.sql" "scripts/qa_smoke.sh" "deploy/cafe24.env" ".htaccess" ".git/config"; do
  printf "  %-28s: %s\n" "$p" "$(code "$U/$p")"
done
echo "== 소스 노출 아님 확인 =="
BYTES=$(curl -s -m 25 "$U/app/config/config.local.php" | grep -c "DB_PASS" || true)
echo "  config.local.php DB_PASS 노출: $BYTES 회  (기대 0)"
echo "== 로그인 페이지에 오류 문자열 없음 =="
ERRS=$(curl -s -m 25 "$U/index.php?r=login" | grep -ciE "Fatal error|Warning:|Exception" || true)
echo "  오류 문자열: $ERRS 회  (기대 0)"
