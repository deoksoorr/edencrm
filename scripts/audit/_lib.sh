#!/bin/bash
# 보안 감사 공용 헬퍼 (audit-only, 앱 코드 변경 없음)
B="${QA_BASE:-http://127.0.0.1:8080/index.php}"
QAPW='QaR16!verify2026'
ADMINPW="${QA_ADMIN_PW:-password123!}"

login(){ # login <id> <pw> <jar> → echo csrf token
  local id="$1" pw="$2" jar="$3" t tmp
  tmp=$(mktemp)
  : > "$jar"
  curl -s -c "$jar" "$B?r=login" -o "$tmp"
  t=$(grep -o 'name="_csrf" value="[^"]*"' "$tmp" | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$jar" -c "$jar" -o /dev/null \
    --data-urlencode "_csrf=$t" --data-urlencode "login_id=$id" --data-urlencode "password=$pw" \
    "$B?r=login.submit"
  curl -s -b "$jar" "$B?r=home" -o "$tmp"
  grep -o 'name="csrf-token" content="[^"]*"' "$tmp" | head -1 | sed 's/.*content="//;s/"//'
  rm -f "$tmp"
}
gcode(){ curl -s -o /dev/null -w "%{http_code}" -b "$1" "$B?r=$2"; }
gbody(){ curl -s -b "$1" "$B?r=$2"; }
jcode(){ curl -s -o /dev/null -w "%{http_code}" -b "$1" -H 'X-Requested-With: XMLHttpRequest' "$B?r=$2"; }
jbody(){ curl -s -b "$1" -H 'X-Requested-With: XMLHttpRequest' "$B?r=$2"; }
pcode(){ local jar="$1" tok="$2" r="$3"; shift 3
  local args=(); for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -o /dev/null -w "%{http_code}" -b "$jar" -H 'X-Requested-With: XMLHttpRequest' \
    --data-urlencode "_csrf=$tok" "${args[@]}" "$B?r=$r"; }
pbody(){ local jar="$1" tok="$2" r="$3"; shift 3
  local args=(); for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -b "$jar" -H 'X-Requested-With: XMLHttpRequest' \
    --data-urlencode "_csrf=$tok" "${args[@]}" "$B?r=$r"; }
# CSRF 토큰 없이 POST
pnotok(){ local jar="$1" r="$2"; shift 2
  local args=(); for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -o /dev/null -w "%{http_code}" -b "$jar" -H 'X-Requested-With: XMLHttpRequest' \
    "${args[@]}" "$B?r=$r"; }
DBQ(){ /opt/homebrew/bin/mysql --socket=.devdb/mysql.sock -ueden_crm_user -p'EdenCrm!local2026' eden_crm -N -B -e "$1" 2>/dev/null; }
