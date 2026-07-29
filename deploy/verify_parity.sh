#!/bin/bash
# EDEN CRM 로컬 ↔ 운영 파일 일치 검증.
#
# deploy/verify.sh 는 HTTP 응답만 본다("페이지가 뜬다"). 그것만으로는
# **배포가 실제로 반영됐는지** 알 수 없다. 2026-07-29 에 뷰 8개가 배포되지 않은 채
# 남아 있었는데도 서비스는 계속 200 을 반환했다 — HTTP 검사로는 잡히지 않는 종류의 사고다.
#
# 이 스크립트는 운영 파일을 실제로 내려받아 로컬과 바이트 단위로 대조한다.
# "올렸다"가 아니라 "올라갔다"를 확인하는 게 목적이다.
#
# 운영 DB 에는 접속하지 않는다. 파일만 다룬다.
#
# 사용:
#   bash deploy/verify_parity.sh            # 새로 내려받아 대조
#   bash deploy/verify_parity.sh --reuse    # 최근 백업(30분 이내)이 있으면 재사용
#
# 종료코드: 0=일치 · 1=불일치 · 2=사전조건 실패

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
BK="$PROJECT_DIR/database/backups"

REUSE=0
[ "${1:-}" = "--reuse" ] && REUSE=1

cd "$PROJECT_DIR" || exit 2

# ── 대조 기준 확보 ──────────────────────────────────────────────────────────
SNAP=""
if [ "$REUSE" -eq 1 ]; then
    CAND=$(ls -1td "$BK"/ftp_* 2>/dev/null | head -1)
    if [ -n "${CAND:-}" ] && [ -d "$CAND" ]; then
        AGE=$(( ($(date +%s) - $(stat -f %m "$CAND")) / 60 ))
        [ "$AGE" -le 30 ] && { SNAP="$CAND"; echo "최근 스냅샷 재사용: $(basename "$SNAP") (${AGE}분 전)"; }
    fi
fi

if [ -z "$SNAP" ]; then
    echo "== 운영 파일 수신 (DB 미접속) =="
    OUT=$(bash "$SCRIPT_DIR/backup.sh" 2>&1)
    RC=$?
    if [ "$RC" -ne 0 ]; then
        echo "$OUT" | grep -m1 'FAIL:' >&2
        echo "운영 파일 수신 실패(rc=$RC) — 대조 불가" >&2
        exit 2
    fi
    SNAP=$(echo "$OUT" | grep -m1 '^RESULT ' | sed -n 's/.*dest=\([^ ]*\).*/\1/p')
    [ -d "${SNAP:-}" ] || { echo "스냅샷 경로 확인 실패" >&2; exit 2; }
    echo "  수신: $(basename "$SNAP")"
fi

# ── 대조 ────────────────────────────────────────────────────────────────────
# 제외 대상:
#   config.local.php — 로컬(개발)과 운영(생성본)이 서로 다른 게 정상이다
#   .DS_Store        — macOS 부산물
echo
echo "== 로컬 ↔ 운영 대조 =="
DIFFS=0
report() {   # report <라벨> <로컬경로> <원격경로> [추가 exclude]
    local label="$1" a="$2" b="$3"; shift 3
    local out
    if [ -f "$a" ]; then
        if diff -q "$a" "$b" >/dev/null 2>&1; then
            printf "  %-12s ✅ 동일\n" "$label"
        else
            printf "  %-12s ❌ 다름\n" "$label"; DIFFS=$((DIFFS + 1))
        fi
        return
    fi
    out=$(diff -rq --exclude='config.local.php' --exclude='.DS_Store' "$@" "$a" "$b" 2>&1)
    local n
    n=$(printf '%s' "$out" | grep -c . || true)
    if [ "$n" -eq 0 ]; then
        printf "  %-12s ✅ 일치 (%s 파일)\n" "$label" "$(find "$a" -type f | wc -l | tr -d ' ')"
    else
        printf "  %-12s ❌ 차이 %s건\n" "$label" "$n"
        printf '%s\n' "$out" | sed 's|'"$PROJECT_DIR"'/||g; s|'"$BK"'/|백업:|g' | sed 's/^/       /'
        DIFFS=$((DIFFS + n))
    fi
}

report "app/"      "$PROJECT_DIR/app"      "$SNAP/app"
report "public/"   "$PROJECT_DIR/public"   "$SNAP/public"
report ".htaccess" "$PROJECT_DIR/.htaccess" "$SNAP/.htaccess"

# ── 업로드 파일은 운영에만 있어야 정상 ──────────────────────────────────────
# storage/uploads 는 배포 대상이 아니다(사용자가 올린 파일). 개수만 참고로 보고한다.
UP=$(find "$SNAP/storage/uploads" -type f 2>/dev/null | wc -l | tr -d ' ')
printf "  %-12s %s개 (배포 대상 아님 — 참고)\n" "업로드" "$UP"

echo
if [ "$DIFFS" -eq 0 ]; then
    echo "🎯 로컬 = 운영 (배포 대상 전 경로 일치)"
    exit 0
fi
echo "⚠ 불일치 ${DIFFS}건 — 미배포 변경이 있거나 운영이 직접 수정됐다."
echo "   배포하려면: CONFIRM=yes ./deploy/deploy.sh"
exit 1
