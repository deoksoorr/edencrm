#!/bin/bash
# R16 운영 실검수 — 권한 시스템이 실서버에서 실제로 동작하는지 확인한다.
# 비밀번호는 deploy/ADMIN_CREDENTIALS.local.txt 에서 읽고 어떤 출력에도 남기지 않는다.
# 사용: bash deploy/verify_r16.sh
set -uo pipefail
cd "$(dirname "$0")/.."

B="<SERVICE_URL>/index.php"
# 검수 전용 임시 계정(qa_r16_verify) 사용 — 사장 계정 비밀번호는 사장이 관리하므로 건드리지 않는다.
# 비밀번호는 /tmp 파일에서만 읽고 출력에 남기지 않으며, 검수 후 계정을 삭제한다.
ADMIN_ID="${R16_VERIFY_ID:-qa_r16_verify}"
ADMIN_PW=$(cat "${R16_VERIFY_PW_FILE:-/tmp/r16_verify_pw.txt}" 2>/dev/null)
[ -n "$ADMIN_PW" ] || { echo "검수 계정 비밀번호 파일 없음"; exit 1; }

PASS=0; FAIL=0; FAILS=""
ok(){ PASS=$((PASS+1)); printf '  ✅ %s\n' "$1"; }
bad(){ FAIL=$((FAIL+1)); FAILS+="  ✗ $1\n"; printf '  ❌ %s\n' "$1"; }
chk(){ if [ "$2" = "$3" ]; then ok "$1 ($3)"; else bad "$1 — 기대 $2, 실제 $3"; fi; }

J=$(mktemp); trap 'rm -f "$J" /tmp/r16_*.html' EXIT
curl -sk -c "$J" "$B?r=login" -o /tmp/r16_lp.html
TOK=$(grep -o 'name="_csrf" value="[^"]*"' /tmp/r16_lp.html | head -1 | sed 's/.*value="//;s/"//')
curl -sk -b "$J" -c "$J" -o /dev/null \
  --data-urlencode "_csrf=$TOK" --data-urlencode "login_id=$ADMIN_ID" --data-urlencode "password=$ADMIN_PW" \
  "$B?r=login.submit"
code(){ curl -sk -o /dev/null -w '%{http_code}' -b "$J" "$B?r=$1"; }

echo "════ 1) 최고운영자 로그인·기존 기능 회귀 ════"
chk "대시보드"        200 "$(code home)"
for r in customers.index pipeline.index quotes.index contracts.index projects.index \
         process.board schedule.index reports.index staff.index settings.index audit.index \
         halfyear.index bonus.index targets.index performance.index notifications.index; do
  chk "$r" 200 "$(code "$r")"
done

echo
echo "════ 2) 권한 매트릭스 UI ════"
curl -sk -b "$J" "$B?r=staff.index" -o /tmp/r16_si.html
TARGET=$(tr '\n' ' ' < /tmp/r16_si.html | grep -o 'data-staff-row="[0-9]*"' | sed 's/[^0-9]//g' | head -1)
if [ -n "$TARGET" ]; then
  curl -sk -b "$J" "$B?r=staff.form&id=$TARGET" -o /tmp/r16_sf.html
  N=$(tr '\n' ' ' < /tmp/r16_sf.html | grep -o 'name="perms\[' | wc -l | tr -d ' ')
  BLOCK=$(grep -c 'permBlock' /tmp/r16_sf.html)
  chk "직원 수정 화면 렌더" 200 "$(code "staff.form&id=$TARGET")"
  [ "$BLOCK" -ge 1 ] && ok "업무 권한 매트릭스 블록 존재" || bad "권한 매트릭스 블록 없음"
  echo "     (권한 체크박스 $N 개)"
else
  bad "직원 행을 찾지 못함"
fi

echo
echo "════ 3) 휴지통 — 최고운영자 접근 ════"
for r in quotes contracts projects customers pipeline; do
  chk "$r 휴지통" 200 "$(code "$r.index&trash=1")"
done

echo
echo "════ 4) 권한 데이터 반영 ════"
curl -sk -b "$J" "$B?r=staff.index" -o /dev/null
echo "     (권한 행 수는 DB 점검 스크립트로 확인)"

echo
echo "════ 5) 오류 문자열 점검 ════"
for r in home staff.index quotes.index projects.index reports.index; do
  curl -sk -b "$J" "$B?r=$r" -o /tmp/r16_p.html
  N=$(grep -oiE 'Fatal error|Parse error|Warning:|Notice:|Uncaught|SQLSTATE' /tmp/r16_p.html | wc -l | tr -d ' ')
  chk "$r PHP 오류 문자열 0" 0 "$N"
done

echo
echo "════════════════════════════════════════"
printf "결과: PASS %d · FAIL %d\n" "$PASS" "$FAIL"
[ "$FAIL" -gt 0 ] && printf "%b" "$FAILS"
exit $([ "$FAIL" -eq 0 ] && echo 0 || echo 1)
