#!/bin/bash
# EDEN CRM 스모크 테스트 — 로그인·권한·주요 라우트 상태코드 점검.
# 사용: bash scripts/qa_smoke.sh   (php -S 가 127.0.0.1:8080 에 떠 있어야 함)
set -uo pipefail
cd "$(dirname "$0")/.."
B="http://127.0.0.1:8080/index.php"
PASS=0; FAIL=0; RESULTS=""

login(){ # $1=id $2=pw $3=jarfile
  local jar="$3"
  curl -s -c "$jar" "$B?r=login" -o /tmp/eden_lp.html
  local t; t=$(grep -o 'name="_csrf" value="[^"]*"' /tmp/eden_lp.html | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$jar" -c "$jar" -o /dev/null \
    --data-urlencode "_csrf=$t" --data-urlencode "login_id=$1" --data-urlencode "password=$2" \
    "$B?r=login.submit"
}
code(){ curl -s -o /dev/null -w "%{http_code}" "$@"; }
check(){ # $1=desc $2=expected $3=actual
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); RESULTS+="  ✅ $1 ($3)\n";
  else FAIL=$((FAIL+1)); RESULTS+="  ❌ $1 기대=$2 실제=$3\n"; fi
}

echo "== 로그인 =="
JADMIN=$(mktemp); login admin 'password123!' "$JADMIN"
JSTAFF=$(mktemp); login staff1 'password123!' "$JSTAFF"
JSALES=$(mktemp); login sales1 'password123!' "$JSALES"
JACCT=$(mktemp);  login acct1 'password123!' "$JACCT"

echo "== admin GET 라우트(200 기대) =="
for r in home customers.index pipeline.index quotes.index contracts.index projects.index \
         process.board schedule.index worklogs.index performance.index reports.index \
         notifications.index staff.index settings.index settings.stages audit.index; do
  check "admin GET $r" 200 "$(code -b "$JADMIN" "$B?r=$r")"
done

echo "== staff 권한 차단(403 기대) =="
check "staff staff.index"    403 "$(code -b "$JSTAFF" "$B?r=staff.index")"
check "staff audit.index"    403 "$(code -b "$JSTAFF" "$B?r=audit.index")"
check "staff settings.index" 403 "$(code -b "$JSTAFF" "$B?r=settings.index")"
check "staff customers.export" 403 "$(code -b "$JSTAFF" "$B?r=customers.export")"
check "staff reports.export"  403 "$(code -b "$JSTAFF" "$B?r=reports.export")"

echo "== staff 허용(200 기대) =="
check "staff home"          200 "$(code -b "$JSTAFF" "$B?r=home")"
check "staff worklogs.index" 200 "$(code -b "$JSTAFF" "$B?r=worklogs.index")"
check "staff schedule.index" 200 "$(code -b "$JSTAFF" "$B?r=schedule.index")"

echo "== 비로그인 차단(302 기대) =="
JNONE=$(mktemp)
check "guest home"          302 "$(code -c "$JNONE" "$B?r=home")"
check "guest customers.index" 302 "$(code -c "$JNONE" "$B?r=customers.index")"

echo "== CSRF 없는 POST(419 기대) =="
check "no-csrf customers.save" 419 "$(code -b "$JADMIN" -H 'X-Requested-With: XMLHttpRequest' -d 'x=1' "$B?r=customers.save")"
check "no-csrf process.move"   419 "$(code -b "$JADMIN" -H 'X-Requested-With: XMLHttpRequest' -d 'x=1' "$B?r=process.move")"

echo "== 잘못된 라우트(404 기대) =="
check "bad route" 404 "$(code -b "$JADMIN" "$B?r=nonexistent.route")"

echo
echo -e "$RESULTS"
echo "=================================="
echo "PASS=$PASS  FAIL=$FAIL"
rm -f "$JADMIN" "$JSTAFF" "$JSALES" "$JACCT" "$JNONE" /tmp/eden_lp.html
[ "$FAIL" -eq 0 ]
