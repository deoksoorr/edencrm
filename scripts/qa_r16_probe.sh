#!/bin/bash
# R16 권한 시스템 보안 프로브 — URL 직접 접근 · API 직접 호출 · 폼 위조 · 휴지통 우회.
# 차단(deny) 검증은 POST 까지 실제로 쏘고(403 이면 아무것도 기록되지 않음),
# 허용(allow) 검증은 페이지 GET 200 으로만 확인해 QA 데이터가 남지 않게 한다.
#
# 선행: php scripts/qa_r16_seed.php --seed
# 사용: bash scripts/qa_r16_probe.sh
set -uo pipefail
cd "$(dirname "$0")/.."

B="${QA_BASE:-http://127.0.0.1:8080/index.php}"
QAPW='QaR16!verify2026'
ADMINPW="${QA_ADMIN_PW:-password123!}"

PASS=0; FAIL=0; FAILS=""
ok(){   PASS=$((PASS+1)); printf '  ✅ %s\n' "$1"; }
bad(){  FAIL=$((FAIL+1)); FAILS+="  ✗ $1\n"; printf '  ❌ %s\n' "$1"; }
chk(){ # chk "<label>" "<expected>" "<actual>"
  if [ "$2" = "$3" ]; then ok "$1 ($3)"; else bad "$1 — 기대 $2, 실제 $3"; fi; }
chkin(){ # chkin "<label>" "<expected csv>" "<actual>"
  case ",$2," in *",$3,"*) ok "$1 ($3)";; *) bad "$1 — 기대 [$2], 실제 $3";; esac; }

login(){ # login <id> <pw> <jar>  → echo csrf token of a logged-in page
  local id="$1" pw="$2" jar="$3" t
  : > "$jar"
  curl -s -c "$jar" "$B?r=login" -o /tmp/qa_lp.html
  t=$(grep -o 'name="_csrf" value="[^"]*"' /tmp/qa_lp.html | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b "$jar" -c "$jar" -o /dev/null \
    --data-urlencode "_csrf=$t" --data-urlencode "login_id=$id" --data-urlencode "password=$pw" \
    "$B?r=login.submit"
  # 로그인 후 페이지의 meta csrf-token
  curl -s -b "$jar" "$B?r=home" -o /tmp/qa_home.html
  grep -o 'name="csrf-token" content="[^"]*"' /tmp/qa_home.html | head -1 | sed 's/.*content="//;s/"//'
}
gcode(){ curl -s -o /dev/null -w "%{http_code}" -b "$1" "$B?r=$2"; }
jcode(){ curl -s -o /dev/null -w "%{http_code}" -b "$1" -H 'X-Requested-With: XMLHttpRequest' "$B?r=$2"; }
pcode(){ # pcode <jar> <token> <route> [extra data...]
  local jar="$1" tok="$2" r="$3"; shift 3
  local args=(); for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -o /dev/null -w "%{http_code}" -b "$jar" -H 'X-Requested-With: XMLHttpRequest' \
    --data-urlencode "_csrf=$tok" "${args[@]}" "$B?r=$r"
}

JA=$(mktemp); JB=$(mktemp); JC=$(mktemp); JD=$(mktemp); JE=$(mktemp); JF=$(mktemp); JG=$(mktemp)
TA=$(login qa_r16_a "$QAPW" "$JA"); TB=$(login qa_r16_b "$QAPW" "$JB")
TC=$(login qa_r16_c "$QAPW" "$JC"); TD=$(login qa_r16_d "$QAPW" "$JD")
TE=$(login qa_r16_e "$QAPW" "$JE"); TF=$(login qa_r16_f "$QAPW" "$JF")
TG=$(login admin     "$ADMINPW" "$JG")

echo "════════ 로그인 확인 ════════"
chk "A 로그인 성공"  200 "$(gcode "$JA" home)"
chk "F(권한없음) 로그인 가능" 200 "$(gcode "$JF" home)"
chk "G(최고운영자) 로그인 성공" 200 "$(gcode "$JG" home)"
[ -n "$TG" ] && ok "관리자 CSRF 토큰 확보" || bad "관리자 CSRF 토큰 없음 — 이후 POST 검증 신뢰 불가"

echo
echo "════════ 테스트 A — 영업 읽기 전용 ════════"
chk "A 고객 목록 열람"        200 "$(gcode "$JA" customers.index)"
chk "A 견적 목록 열람"        200 "$(gcode "$JA" quotes.index)"
chk "A 고객 등록 폼 차단"     403 "$(gcode "$JA" customers.form)"
chk "A 견적 등록 폼 차단"     403 "$(gcode "$JA" quotes.form)"
chk "A 고객 저장 POST 차단"   403 "$(pcode "$JA" "$TA" customers.save "name=QA_R16_침입")"
chk "A 고객 삭제 POST 차단"   403 "$(pcode "$JA" "$TA" customers.delete "id=1")"
chk "A 견적 삭제 POST 차단"   403 "$(pcode "$JA" "$TA" quotes.delete "id=1")"
chk "A 현장(프로젝트) 차단"   403 "$(gcode "$JA" projects.index)"
chk "A 공정 보드 차단"        403 "$(gcode "$JA" process.board)"
chk "A 리포트 차단"           403 "$(gcode "$JA" reports.index)"
chk "A 리포트 API 차단"       403 "$(jcode "$JA" reports.data)"
chk "A 직원 관리 차단"        403 "$(gcode "$JA" staff.index)"
chk "A 시스템 설정 차단"      403 "$(gcode "$JA" settings.index)"
chk "A 감사 로그 차단"        403 "$(gcode "$JA" audit.index)"

echo
echo "════════ 테스트 B — 영업 읽기·쓰기(삭제 차단) ════════"
chk "B 고객 목록 열람"        200 "$(gcode "$JB" customers.index)"
chk "B 고객 등록 폼 허용"     200 "$(gcode "$JB" customers.form)"
chk "B 견적 등록 폼 허용"     200 "$(gcode "$JB" quotes.form)"
chk "B 고객 삭제 POST 차단"   403 "$(pcode "$JB" "$TB" customers.delete "id=1")"
chk "B 견적 삭제 POST 차단"   403 "$(pcode "$JB" "$TB" quotes.delete "id=1")"
chk "B 계약 삭제 POST 차단"   403 "$(pcode "$JB" "$TB" contracts.delete "id=1")"
chk "B 정산(입금) 저장 차단"  403 "$(pcode "$JB" "$TB" payments.save "contract_id=1&amount=1000")"

echo
echo "════════ 테스트 C — 영업 읽기·쓰기·삭제 ════════"
chk "C 휴지통 목록 차단"          403 "$(gcode "$JC" "quotes.index&trash=1")"
chk "C 계약 휴지통 차단"          403 "$(gcode "$JC" "contracts.index&trash=1")"
chk "C 프로젝트 휴지통 차단"      403 "$(gcode "$JC" "projects.index&trash=1")"
chk "C 견적 복원 POST 차단"       403 "$(pcode "$JC" "$TC" quotes.restore "id=1")"
chk "C 견적 완전삭제 POST 차단"   403 "$(pcode "$JC" "$TC" quotes.purge "id=1")"
chk "C 계약 복원 POST 차단"       403 "$(pcode "$JC" "$TC" contracts.restore "id=1")"
chk "C 계약 완전삭제 POST 차단"   403 "$(pcode "$JC" "$TC" contracts.purge "id=1")"
chk "C 프로젝트 복원 POST 차단"   403 "$(pcode "$JC" "$TC" projects.restore "id=1")"
chk "C 프로젝트 완전삭제 차단"    403 "$(pcode "$JC" "$TC" projects.purge "id=1")"

echo
echo "════════ 테스트 D — 현장 읽기·쓰기 ════════"
chk "D 프로젝트 목록 열람"    200 "$(gcode "$JD" projects.index)"
chk "D 공정 보드 열람"        200 "$(gcode "$JD" process.board)"
chk "D 일정 열람"             200 "$(gcode "$JD" schedule.index)"
chk "D 프로젝트 삭제 차단"    403 "$(pcode "$JD" "$TD" projects.delete "id=1")"
chk "D 영업(견적) 차단"       403 "$(gcode "$JD" quotes.index)"
chk "D 계약 차단"             403 "$(gcode "$JD" contracts.index)"
chk "D 휴지통 차단"           403 "$(gcode "$JD" "projects.index&trash=1")"
chk "D 프로젝트 복원 차단"    403 "$(pcode "$JD" "$TD" projects.restore "id=1")"

echo
echo "════════ 테스트 E — 고객 CRM 전용 ════════"
chk "E 고객 목록 열람"        200 "$(gcode "$JE" customers.index)"
chk "E 견적 목록 차단"        403 "$(gcode "$JE" quotes.index)"
chk "E 계약 목록 차단"        403 "$(gcode "$JE" contracts.index)"
chk "E 견적 상세 직접접근 차단" 403 "$(gcode "$JE" "quotes.show&id=1")"
chk "E 계약 상세 직접접근 차단" 403 "$(gcode "$JE" "contracts.show&id=1")"
chk "E 견적 리드 API 차단"    403 "$(jcode "$JE" "quotes.leads&customer_id=1")"
chk "E 계약 견적데이터 API 차단" 403 "$(jcode "$JE" "contracts.quotedata&quote_id=1")"
chk "E 프로젝트 차단"         403 "$(gcode "$JE" projects.index)"

echo
echo "════════ 테스트 F — 업무 권한 전무 ════════"
chk "F 대시보드 접근 가능"    200 "$(gcode "$JF" home)"
chk "F 고객 차단"             403 "$(gcode "$JF" customers.index)"
chk "F 영업기회 차단"         403 "$(gcode "$JF" pipeline.index)"
chk "F 견적 차단"             403 "$(gcode "$JF" quotes.index)"
chk "F 계약 차단"             403 "$(gcode "$JF" contracts.index)"
chk "F 프로젝트 차단"         403 "$(gcode "$JF" projects.index)"
chk "F 공정 보드 차단"        403 "$(gcode "$JF" process.board)"
chk "F 일정 차단"             403 "$(gcode "$JF" schedule.index)"
chk "F 리포트 차단"           403 "$(gcode "$JF" reports.index)"
chk "F 대시보드 API 민감정보"  200 "$(jcode "$JF" dashboard.data)"

echo
echo "════════ 분석·관리 차단(전 비관리자 공통) ════════"
for pair in "A:$JA" "B:$JB" "C:$JC" "D:$JD" "E:$JE" "F:$JF"; do
  tag="${pair%%:*}"; jar="${pair##*:}"
  chk "$tag 리포트 차단"       403 "$(gcode "$jar" reports.index)"
  chk "$tag 출근 통계 차단"    403 "$(gcode "$jar" reports.attendance)"
  chk "$tag 직원 관리 차단"    403 "$(gcode "$jar" staff.index)"
  chk "$tag 설정 차단"         403 "$(gcode "$jar" settings.index)"
  chk "$tag 감사 로그 차단"    403 "$(gcode "$jar" audit.index)"
done

echo
echo "════════ 권한 상승 차단 ════════"
chk "일반직원의 직원 저장 차단"   403 "$(pcode "$JC" "$TC" staff.save "id=1&name=hack")"
chk "일반직원의 권한 변경 차단"   403 "$(pcode "$JC" "$TC" staff.save "id=2&perms[sales.quotes][read]=1")"
chk "일반직원의 설정 저장 차단"   403 "$(pcode "$JC" "$TC" settings.save "k=v")"
chk "일반직원의 근태 마킹 차단"   403 "$(pcode "$JC" "$TC" attendance.mark "user_id=1&date=2026-07-29&kind=late")"
chk "일반직원의 보너스 저장 차단" 403 "$(pcode "$JC" "$TC" bonus.save "user_id=1&amount=1")"

echo
echo "════════ CSRF · 세션 ════════"
chkin "CSRF 토큰 없는 POST 거부" "403,419,400" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b "$JC" -H 'X-Requested-With: XMLHttpRequest' \
     --data-urlencode "id=1" "$B?r=quotes.delete")"
chkin "잘못된 CSRF 토큰 거부" "403,419,400" \
  "$(pcode "$JC" "INVALID_TOKEN_VALUE" quotes.delete "id=1")"
JX=$(mktemp)
chkin "비로그인 휴지통 접근 차단" "302,403" "$(gcode "$JX" "quotes.index&trash=1")"
chkin "비로그인 완전삭제 차단"    "302,403" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b "$JX" --data-urlencode "id=1" "$B?r=quotes.purge")"

echo
echo "════════ 테스트 G — 최고운영자 회귀 ════════"
for r in home customers.index pipeline.index quotes.index contracts.index projects.index \
         process.board schedule.index reports.index staff.index settings.index audit.index \
         halfyear.index bonus.index targets.index performance.index notifications.index; do
  chk "G $r" 200 "$(gcode "$JG" "$r")"
done
chk "G 견적 휴지통 접근"      200 "$(gcode "$JG" "quotes.index&trash=1")"
chk "G 계약 휴지통 접근"      200 "$(gcode "$JG" "contracts.index&trash=1")"
chk "G 프로젝트 휴지통 접근"  200 "$(gcode "$JG" "projects.index&trash=1")"

rm -f "$JA" "$JB" "$JC" "$JD" "$JE" "$JF" "$JG" "$JX" 2>/dev/null
echo
echo "════════════════════════════════════════"
printf "결과: PASS %d · FAIL %d\n" "$PASS" "$FAIL"
[ "$FAIL" -gt 0 ] && printf "%b" "$FAILS"
exit $([ "$FAIL" -eq 0 ] && echo 0 || echo 1)
