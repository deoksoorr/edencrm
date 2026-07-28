#!/bin/bash
# R11 예외 프로젝트 종료·정산 17단계 시나리오 QA (스펙 원문 순서).
# 사용: bash scripts/qa_r11_scenario.sh [BASE] [LOGIN_ID] [PASSWORD]
#   기본 = 로컬 dev(http://localhost:8080, admin). 운영 검증 시 인자로 교체.
# 생성물: 프로젝트 1건(R11SC 접두 이름)·입금 4행·배정 2행·지출 1행 — 종료 후 정리는 별도(운영은 하드삭제 원복 필수).
set -u  # pipefail 금지 — grep -q 조기 종료 시 echo SIGPIPE(141)가 매치 성공을 실패로 뒤집는다
B="${1:-http://localhost:8080}/index.php"
LID="${2:-admin}"
LPW="${3:-password123!}"
J=$(mktemp)
PASS=0; FAIL=0
ok(){ echo "  ✅ $1"; PASS=$((PASS+1)); }
ng(){ echo "  ❌ $1"; FAIL=$((FAIL+1)); }
chk(){ [ "$2" = "$3" ] && ok "$1 ($2)" || ng "$1 — 기대 $3, 실제 $2"; }

# 로그인 + CSRF
T0=$(curl -s -c "$J" "$B?r=login" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$J" -c "$J" --data-urlencode "_csrf=$T0" --data-urlencode "login_id=$LID" --data-urlencode "password=$LPW" "$B?r=login.submit" -o /dev/null
CSRF=$(curl -s -b "$J" "$B?r=projects.index" | grep -o 'name="csrf-token" content="[^"]*"' | sed 's/.*content="//;s/"//')
[ -n "$CSRF" ] && ok "로그인·CSRF 확보" || { ng "로그인 실패"; exit 1; }

api(){ # $1=route $2...=data pairs
  local route="$1"; shift
  curl -s -b "$J" -H "X-Requested-With: XMLHttpRequest" -d "_csrf=$CSRF" "$@" "$B?r=$route"
}
jval(){ python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)" 2>/dev/null; }

NAME="R11SC-$(date +%H%M%S)"

echo "== 1~3) 예외 프로젝트 생성(고객명 직접 입력·정산 예정 금액 5,000,000) =="
curl -s -b "$J" -o /dev/null -d "_csrf=$CSRF" \
  --data-urlencode "name=$NAME" --data-urlencode "create_reason=R11 시나리오 QA" \
  --data-urlencode "customer_name_snapshot=시나리오 고객(직접입력)" \
  --data-urlencode "expected_amount=5000000" --data-urlencode "status=in_progress" \
  --data-urlencode "construction_type=painting" "$B?r=projects.save"
PID=$(curl -s -b "$J" "$B?r=projects.index&q=$NAME" | grep -o 'projects.show&amp;id=[0-9]*' | head -1 | grep -o '[0-9]*$')
[ -n "$PID" ] || PID=$(curl -s -b "$J" "$B?r=projects.index&q=$NAME" | grep -o 'projects.show&id=[0-9]*' | head -1 | grep -o '[0-9]*$')
[ -n "$PID" ] && ok "프로젝트 생성 id=$PID" || { ng "생성 실패"; exit 1; }
SHOW=$(curl -s -b "$J" "$B?r=projects.show&id=$PID")
echo "$SHOW" | grep -q "시나리오 고객(직접입력)" && ok "고객명 스냅샷 표시" || ng "고객명 스냅샷 미표시"
echo "$SHOW" | grep -q "예외" && ok "예외 배지 표시" || ng "예외 배지 없음"

echo "== 4) 직원 배정·기여도(60/40) =="
api "assignments.save" -d "project_id=$PID&user_id=2&role=현장책임자&contribution_pct=60" >/dev/null
R=$(api "assignments.save" -d "project_id=$PID&user_id=3&role=보조작업자&contribution_pct=40")
echo "$R" | grep -q '"ok":true' && ok "배정 2명(합 100%)" || ng "배정 실패: $R"

echo "== 5~7) 일부 입금 2,000,000 → 확정 매출 반영·미수금 3,000,000 =="
R=$(api "projects.payment.save" -d "project_id=$PID&kind=payment&amount=2000000&status=paid&method=transfer" --data-urlencode "payer_name=시나리오입금자")
PAY1=$(echo "$R" | jval "['data']['id']")
[ -n "$PAY1" ] && ok "입금 등록 id=$PAY1" || ng "입금 등록 실패: $R"
SHOW=$(curl -s -b "$J" "$B?r=projects.show&id=$PID")
echo "$SHOW" | grep -q "2,000,000" && ok "누적 입금 2,000,000 표시" || ng "누적 입금 표시 오류"
echo "$SHOW" | grep -q "3,000,000" && ok "미수금 3,000,000 표시" || ng "미수금 표시 오류"
echo "$SHOW" | grep -q "일부 입금" && ok "입금 상태=일부 입금" || ng "입금 상태 오류"

echo "== 8) 공정 종결(전체완료) 처리 =="
# R14: 드래그 이동(process.move) 폐지 — 게이지 보드는 단계별 process.progress.set(pct)로 진행하고,
# 전 단계 100% 확인 후 process.complete.confirm 으로 완료를 확정한다(서버 재검증).
SOCK=".devdb/mysql.sock"
STAGE_IDS=$(/opt/homebrew/bin/mysql --socket="$SOCK" -ueden_crm_user -p'EdenCrm!local2026' eden_crm -N -e \
  "SELECT id FROM process_stages WHERE process_type='painting' AND is_active=1 ORDER BY sort_order, id" 2>/dev/null)
[ -n "$STAGE_IDS" ] && ok "도장 공정 게이지 단계 조회($(echo "$STAGE_IDS" | wc -l | tr -d ' ')건)" || ng "게이지 단계 조회 실패"
for SID in $STAGE_IDS; do
  api "process.progress.set" -d "project_id=$PID&stage_id=$SID&pct=100" >/dev/null
done
R=$(api "process.complete.confirm" -d "project_id=$PID")
chk "종결 확정 → 상태 completed" "$(echo "$R" | jval "['data']['status']")" "completed"

echo "== 9) 미수금 잔존 → 정산 완료 거부(422) =="
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$J" -H "X-Requested-With: XMLHttpRequest" -d "_csrf=$CSRF&project_id=$PID&action=settle" "$B?r=projects.settlement.update")
chk "정산 완료 거부" "$CODE" "422"

echo "== 10~11) 잔금 3,000,000 입금 → 미수금 0 =="
api "projects.payment.save" -d "project_id=$PID&kind=payment&amount=3000000&status=paid&method=cash" >/dev/null
SHOW=$(curl -s -b "$J" "$B?r=projects.show&id=$PID")
echo "$SHOW" | grep -q ">완납<" && ok "입금 상태=완납" || ng "완납 미표시"

echo "== 12) 정산 완료 처리 =="
R=$(api "projects.settlement.update" -d "project_id=$PID&action=settle")
chk "정산 완료 전환" "$(echo "$R" | jval "['data']['settlement_status_after']")" "settled"

echo "== 13~15) 반기 매출·순이익·보너스 산정 반영 =="
api "costs.save" -d "project_id=$PID&type=actual&cost_status=confirmed&category=material&amount=1000000" --data-urlencode "spent_date=$(date +%Y-%m-%d)" >/dev/null
HY=$(curl -s -b "$J" "$B?r=halfyear.index")
echo "$HY" | grep -q "확정매출(공급가액)" && ok "반기 화면 R12 라벨(공급가액)" || ng "반기 라벨 미반영"
BC=$(curl -s -b "$J" "$B?r=bonus.calc&project_id=$PID")
# R12: 보너스 총매출 = 확정 매출(공급가·VAT 제외) = 입금 5,000,000 ÷ 1.1 = 4,545,455
chk "보너스 base=확정매출 공급가 4,545,455" "$(echo "$BC" | jval "['data']['base']")" "4545455"
chk "보너스 배정 직원 2명 반환" "$(echo "$BC" | jval "['data']['assignees'].__len__()")" "2"

echo "== 16) 환불 500,000 → 확정 매출 차감·정산 강등 =="
R=$(api "projects.payment.save" -d "project_id=$PID&kind=refund&amount=500000")
echo "$R" | grep -q '"ok":true' && ok "환불 등록" || ng "환불 실패: $R"
BC=$(curl -s -b "$J" "$B?r=bonus.calc&project_id=$PID")
# R12: 환불 500k 차감 후 순입금 4,500,000 ÷ 1.1 = 4,090,909 (공급가)
chk "환불 차감 후 base=공급가 4,090,909" "$(echo "$BC" | jval "['data']['base']")" "4090909"
SHOW=$(curl -s -b "$J" "$B?r=projects.show&id=$PID")
echo "$SHOW" | grep -q "환불 발생" && ok "환불 발생 배지" || ng "환불 배지 없음"
echo "$SHOW" | grep -q "일부 정산" && ok "정산 강등(일부 정산)" || ng "정산 강등 미동작"

echo "== 17) 입금 수정·취소 이력 =="
api "projects.payment.save" -d "project_id=$PID&id=$PAY1&kind=payment&amount=2100000&status=paid" >/dev/null
R=$(api "projects.payment.cancel" -d "id=$PAY1")
echo "$R" | grep -q "cancelled" && ok "입금 취소 처리" || ng "취소 실패: $R"
SHOW=$(curl -s -b "$J" "$B?r=projects.show&id=$PID")
echo "$SHOW" | grep -q "입금 수정: 2,000,000원 → 2,100,000원" && ok "수정 이력 표시" || ng "수정 이력 없음"
echo "$SHOW" | grep -q "입금 취소: 2,100,000원" && ok "취소 이력 표시" || ng "취소 이력 없음"
echo "$SHOW" | grep -q "정산 상태 변경" && ok "정산 상태 변경 이력" || ng "정산 이력 없음"

echo "=================================="
echo "PASS=$PASS FAIL=$FAIL (생성 프로젝트 id=$PID — 원복 시 하드삭제 필요)"
[ "$FAIL" = "0" ]
