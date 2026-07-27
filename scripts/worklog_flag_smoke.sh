#!/bin/bash
# 작업일지(work_logs) 기능 플래그(feature_worklog) OFF/ON 회귀 스모크.
#
# 검증 범위:
#   1) 로그인(admin) 성공
#   2) OFF(기본): 라우트 게이트(비활성화 안내) + 사이드바 메뉴 숨김
#   3) ON 토글(설정 저장) 후: 라우트 정상 노출 + 사이드바 메뉴 노출
#   4) OFF 원복: 다시 게이트/메뉴 숨김 확인
#   5) work_logs 행수가 토글 전/후 불변인지 DB 로 직접 확인
#
# 사용법: php -S 127.0.0.1:8080 -t public 를 먼저 백그라운드로 기동한 뒤
#         bash scripts/worklog_flag_smoke.sh
#
# 종료 코드: 전부 PASS 면 0, 하나라도 FAIL 이면 1.
# 주의: 스크립트는 반드시 feature_worklog='0' 상태로 원복하고 종료한다.

set -u
cd "$(dirname "$0")/.."

B="http://127.0.0.1:8080/index.php"
LOGIN_ID="admin"
LOGIN_PW="password123!"

MYSQL_SOCK=".devdb/mysql.sock"
MYSQL_USER="eden_crm_user"
MYSQL_PASS="EdenCrm!local2026"
MYSQL_DB="eden_crm"

JAR="$(mktemp)"
BODY="$(mktemp)"

PASS=0
FAIL=0

pass() { PASS=$((PASS + 1)); echo "[PASS] $1"; }
fail() { FAIL=$((FAIL + 1)); echo "[FAIL] $1"; }

cleanup() { rm -f "$JAR" "$BODY"; }
trap cleanup EXIT

mysql_q() {
  mysql --socket="$MYSQL_SOCK" -u"$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -N -B -e "$1" 2>/dev/null
}

extract_csrf() {
  grep -o 'name="_csrf" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'
}

# ── 사전 점검: 서버 가동 확인 ──
if ! curl -s -o /dev/null "$B?r=login"; then
  echo "[FAIL] 서버 연결 — http://127.0.0.1:8080 에 응답 없음. 먼저 'php -S 127.0.0.1:8080 -t public' 를 기동하세요." >&2
  exit 1
fi

echo "== 0) 사전 DB 상태 =="
INITIAL_FLAG="$(mysql_q "SELECT value FROM settings WHERE setting_key='feature_worklog'")"
INITIAL_COUNT="$(mysql_q "SELECT COUNT(*) FROM work_logs")"
echo "  feature_worklog(사전)=${INITIAL_FLAG:-?}  work_logs 행수(사전)=${INITIAL_COUNT:-?}"
if [ "$INITIAL_FLAG" != "0" ]; then
  echo "  [경고] 스모크 시작 시점에 feature_worklog 가 '0' 이 아닙니다(현재=$INITIAL_FLAG). 계속 진행하되 결과 해석에 주의하세요." >&2
fi

# ── 1) 로그인 ──
echo "== 1) 로그인 =="
curl -s -c "$JAR" "$B?r=login" -o "$BODY"
TOKEN="$(extract_csrf "$BODY")"
if [ -n "$TOKEN" ]; then
  pass "로그인 페이지에서 CSRF 토큰 추출"
else
  fail "로그인 페이지에서 CSRF 토큰 추출 실패 — 이후 단계를 진행할 수 없습니다"
  echo "PASS=$PASS  FAIL=$FAIL"
  exit 1
fi

curl -s -b "$JAR" -c "$JAR" -L -o "$BODY" \
  --data-urlencode "_csrf=$TOKEN" \
  --data-urlencode "login_id=$LOGIN_ID" \
  --data-urlencode "password=$LOGIN_PW" \
  "$B?r=login.submit"

if grep -q "대시보드" "$BODY"; then
  pass "로그인 성공(대시보드 진입 확인)"
else
  fail "로그인 성공(대시보드 진입 확인) — login_id/password/CSRF 필드명 변경 여부 확인 필요"
fi

# ── 2) OFF(기본) 확인 ──
echo "== 2) OFF(기본) 확인 =="
WL_CODE=$(curl -s -b "$JAR" -o "$BODY" -w "%{http_code}" "$B?r=worklogs.index")
if grep -q "비활성화된 기능" "$BODY" \
  && ! grep -q "접근 권한 없음" "$BODY" \
  && ! grep -q "페이지를 찾을 수 없음" "$BODY"; then
  pass "OFF: worklogs.index → 비활성화 안내 표시(권한오류/라우트없음 아님, http=$WL_CODE)"
else
  fail "OFF: worklogs.index → 비활성화 안내 미확인(http=$WL_CODE)"
fi

curl -s -b "$JAR" -o "$BODY" "$B?r=home"
if ! grep -q 'r=worklogs.index' "$BODY"; then
  pass "OFF: 대시보드 사이드바에 작업일지 메뉴 링크 없음"
else
  fail "OFF: 대시보드 사이드바에 작업일지 메뉴 링크가 존재함(숨김 실패)"
fi

# ── 3) 설정 ON 토글 ──
echo "== 3) 설정 ON 토글 =="
curl -s -b "$JAR" -o "$BODY" "$B?r=settings.index"
TOKEN2="$(extract_csrf "$BODY")"
ON_CODE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w "%{http_code}" \
  --data-urlencode "_csrf=$TOKEN2" \
  --data-urlencode "feature_worklog=1" \
  "$B?r=settings.save")
if [ "$ON_CODE" = "302" ]; then
  pass "ON 토글 POST settings.save → 302"
else
  fail "ON 토글 POST settings.save → http=$ON_CODE (302 기대)"
fi

WL_CODE2=$(curl -s -b "$JAR" -o "$BODY" -w "%{http_code}" "$B?r=worklogs.index")
if grep -q 'value="worklogs.index"' "$BODY" && ! grep -q "비활성화된 기능" "$BODY"; then
  pass "ON: worklogs.index → 작업일지 목록 정상 노출(http=$WL_CODE2)"
else
  fail "ON: worklogs.index → 작업일지 목록 노출 실패(http=$WL_CODE2)"
fi

curl -s -b "$JAR" -o "$BODY" "$B?r=home"
if grep -q 'r=worklogs.index' "$BODY"; then
  pass "ON: 대시보드 사이드바에 작업일지 메뉴 링크 노출"
else
  fail "ON: 대시보드 사이드바에 작업일지 메뉴 링크 노출 실패"
fi

# ── 4) OFF 원복 ──
echo "== 4) OFF 원복 =="
curl -s -b "$JAR" -o "$BODY" "$B?r=settings.index"
TOKEN3="$(extract_csrf "$BODY")"
OFF_CODE=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w "%{http_code}" \
  --data-urlencode "_csrf=$TOKEN3" \
  --data-urlencode "feature_worklog=0" \
  "$B?r=settings.save")
if [ "$OFF_CODE" = "302" ]; then
  pass "OFF 원복 POST settings.save → 302"
else
  fail "OFF 원복 POST settings.save → http=$OFF_CODE (302 기대)"
fi

WL_CODE3=$(curl -s -b "$JAR" -o "$BODY" -w "%{http_code}" "$B?r=worklogs.index")
if grep -q "비활성화된 기능" "$BODY"; then
  pass "원복 후 worklogs.index → 다시 비활성화 안내(http=$WL_CODE3)"
else
  fail "원복 후 worklogs.index → 비활성화 안내 미확인(http=$WL_CODE3)"
fi

curl -s -b "$JAR" -o "$BODY" "$B?r=home"
if ! grep -q 'r=worklogs.index' "$BODY"; then
  pass "원복 후 대시보드 사이드바에 작업일지 메뉴 링크 없음"
else
  fail "원복 후 대시보드 사이드바에 작업일지 메뉴 링크가 남아있음"
fi

# ── 5) 최종 플래그 / 데이터 보존 확인 ──
echo "== 5) 최종 DB 상태 =="
FINAL_FLAG="$(mysql_q "SELECT value FROM settings WHERE setting_key='feature_worklog'")"
FINAL_COUNT="$(mysql_q "SELECT COUNT(*) FROM work_logs")"
echo "  feature_worklog(최종)=${FINAL_FLAG:-?}  work_logs 행수(최종)=${FINAL_COUNT:-?}"

if [ "$FINAL_FLAG" = "0" ]; then
  pass "최종 feature_worklog = '0' (OFF 로 원복됨)"
else
  fail "최종 feature_worklog = '$FINAL_FLAG' (0 기대) — 수동으로 원복 필요"
fi

if [ -n "$INITIAL_COUNT" ] && [ "$FINAL_COUNT" = "$INITIAL_COUNT" ]; then
  pass "work_logs 행수 보존됨 (토글 전=$INITIAL_COUNT → 토글 후=$FINAL_COUNT)"
else
  fail "work_logs 행수 불일치 (토글 전=$INITIAL_COUNT → 토글 후=$FINAL_COUNT)"
fi

# R6 T2 빈 시드 재기준: seed_dev 는 작업일지를 시딩하지 않는다(기준값 0)
if [ "$INITIAL_COUNT" = "0" ]; then
  pass "work_logs 행수 = 빈 시드 기준값 0 과 일치"
else
  fail "work_logs 행수 = $INITIAL_COUNT (빈 시드 기준값 0 과 불일치)"
fi

echo
echo "=================================="
echo "PASS=$PASS  FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
