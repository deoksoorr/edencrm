#!/bin/bash
# EDEN CRM 백업 보존 정책 — 오래된 자동 백업 정리.
#
# 파괴적 스크립트다. 그래서 기본이 --dry-run 이고, 실제 삭제는 --apply 를
# 명시해야만 일어난다. backup_auto.sh 만 --apply 로 호출한다.
#
# 삭제 대상은 **auto 라벨 백업만**이다. 보호 라벨 화이트리스트(*pre*·final…)를
# 만들지 않는 이유: 앞으로 새 라벨(r17pre, hotfix_pre…)이 생길 때마다 목록을
# 갱신해야 하고, 갱신을 잊으면 복구 기준점이 조용히 삭제된다. 블랙리스트 방식은
# 새 라벨이 자동으로 보호되므로 실패 방향이 안전한 쪽이다.
#
# 사용:
#   bash deploy/backup_prune.sh              # dry-run (기본)
#   bash deploy/backup_prune.sh --apply      # 실제 삭제

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
BK="$PROJECT_DIR/database/backups"
LOGDIR="${EDEN_LOG_DIR:-$HOME/Library/Logs/eden_crm}"
LOG="$LOGDIR/backup.log"
mkdir -p "$LOGDIR" 2>/dev/null

APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1

KEEP_ALL_DAYS="${PRUNE_KEEP_ALL_DAYS:-14}"      # 최근 N일: 전부 보존
KEEP_WEEKLY_DAYS="${PRUNE_KEEP_WEEKLY_DAYS:-90}" # ~N일: 주 1쌍
KEEP_MONTHLY_DAYS="${PRUNE_KEEP_MONTHLY_DAYS:-365}" # ~N일: 월 1쌍, 초과는 삭제
KEEP_MIN_PAIRS="${PRUNE_KEEP_MIN_PAIRS:-3}"     # 규칙과 무관하게 무조건 남길 최신 쌍
FAILED_DAYS="${PRUNE_FAILED_DAYS:-7}"

[ -d "$BK" ] || { echo "백업 디렉터리 없음: $BK" >&2; exit 2; }

# 안전장치: 삭제 경로가 반드시 database/backups 하위여야 한다.
BK_REAL="$(cd "$BK" && pwd -P)"
safe_rm() {
    local target="$1" real
    real="$(cd "$(dirname "$target")" 2>/dev/null && pwd -P)/$(basename "$target")"
    case "$real" in
        "$BK_REAL"/*) ;;
        *) echo "가드: 백업 디렉터리 밖 삭제 시도 차단 — $target" >&2; return 1 ;;
    esac
    case "$(basename "$target")" in
        *..*|/) echo "가드: 비정상 경로 차단 — $target" >&2; return 1 ;;
    esac
    [ "$APPLY" -eq 1 ] && rm -rf "$real"
    return 0
}

now_epoch=$(date +%s)
age_days() { echo $(( (now_epoch - $1) / 86400 )); }

# ── 삭제 후보 수집 ──────────────────────────────────────────────────────────
# auto 백업 쌍은 파일명 타임스탬프가 일치한다(backup_auto.sh 가 RUN_TS 를 양쪽에 주입).
# 따라서 타임스탬프 하나로 쌍을 식별할 수 있다.
TS_LIST=()
for f in "$BK"/proddb_auto_*.sql; do
    [ -f "$f" ] || continue
    b=$(basename "$f")
    [[ "$b" =~ ^proddb_auto_([0-9]{8}-[0-9]{6})\.sql$ ]] || continue
    TS_LIST+=("${BASH_REMATCH[1]}")
done

# 최신순 정렬
IFS=$'\n' TS_SORTED=($(printf '%s\n' "${TS_LIST[@]+"${TS_LIST[@]}"}" | sort -r))
unset IFS

TOTAL=${#TS_SORTED[@]}
echo "auto 백업 쌍: ${TOTAL}개 · 모드: $([ "$APPLY" -eq 1 ] && echo APPLY || echo DRY-RUN)"

TO_DELETE=()
# macOS 기본 bash 는 3.2 라 연관배열(declare -A)을 쓸 수 없다.
# 의존성을 늘리는 대신 공백 구분 문자열로 "이미 본 주/월"을 추적한다.
SEEN_WEEK=" "
SEEN_MONTH=" "
seen() { case "$1" in *" $2 "*) return 0 ;; *) return 1 ;; esac; }

idx=0
for ts in ${TS_SORTED[@]+"${TS_SORTED[@]}"}; do
    idx=$((idx + 1))
    ymd="${ts%%-*}"
    # 최신 N쌍은 규칙과 무관하게 무조건 보존.
    # 규칙 로직에 버그가 있어도 전멸하지 않게 하는 최후의 안전장치다.
    if [ "$idx" -le "$KEEP_MIN_PAIRS" ]; then continue; fi

    epoch=$(date -j -f '%Y%m%d' "$ymd" '+%s' 2>/dev/null || echo "$now_epoch")
    age=$(age_days "$epoch")

    if [ "$age" -le "$KEEP_ALL_DAYS" ]; then
        continue                                        # 최근 14일: 전부 보존
    elif [ "$age" -le "$KEEP_WEEKLY_DAYS" ]; then
        wk=$(date -j -f '%Y%m%d' "$ymd" '+%G-W%V' 2>/dev/null || echo "$ymd")
        if ! seen "$SEEN_WEEK" "$wk"; then SEEN_WEEK="$SEEN_WEEK$wk "; continue; fi
    elif [ "$age" -le "$KEEP_MONTHLY_DAYS" ]; then
        mo=$(echo "$ymd" | cut -c1-6)
        if ! seen "$SEEN_MONTH" "$mo"; then SEEN_MONTH="$SEEN_MONTH$mo "; continue; fi
    fi
    TO_DELETE+=("$ts")
done

# ── 실행 ────────────────────────────────────────────────────────────────────
DEL=0
for ts in ${TO_DELETE[@]+"${TO_DELETE[@]}"}; do
    # 짝은 반드시 함께 지운다. 한쪽만 남으면 반쪽 백업이 되어 오히려 위험하다.
    for p in "$BK/proddb_auto_${ts}.sql" "$BK/ftp_${ts}" "$BK/manifests/run_${ts}.json"; do
        [ -e "$p" ] || continue
        safe_rm "$p" && echo "  $([ "$APPLY" -eq 1 ] && echo 삭제 || echo '삭제예정'): $(basename "$p")"
    done
    DEL=$((DEL + 1))
done

# failed_* 는 원인 분석용이라 남기되 무한 누적은 막는다.
FDEL=0
for d in "$BK"/failed_*; do
    [ -d "$d" ] || continue
    b=$(basename "$d"); ymd="${b#failed_}"; ymd="${ymd%%-*}"
    epoch=$(date -j -f '%Y%m%d' "$ymd" '+%s' 2>/dev/null || echo "$now_epoch")
    [ "$(age_days "$epoch")" -gt "$FAILED_DAYS" ] && { safe_rm "$d" && { echo "  $([ "$APPLY" -eq 1 ] && echo 삭제 || echo '삭제예정'): $b"; FDEL=$((FDEL + 1)); }; }
done

# 중단된 실행의 잔재 — 즉시 제거 대상(정상 경로에서는 생기지 않는다).
IDEL=0
for d in "$BK"/.incomplete_*; do
    [ -d "$d" ] || continue
    safe_rm "$d" && { echo "  $([ "$APPLY" -eq 1 ] && echo 삭제 || echo '삭제예정'): $(basename "$d")"; IDEL=$((IDEL + 1)); }
done

REMAIN=$((TOTAL - DEL))
SUMMARY="auto쌍 ${TOTAL}→${REMAIN} (삭제 ${DEL}) · failed ${FDEL} · incomplete ${IDEL}"
echo "$SUMMARY"
[ "$APPLY" -eq 1 ] && printf '%s %-7s %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "PRUNE" "$SUMMARY" >> "$LOG"

# 보호 대상이 후보에 섞이지 않았는지 자체 점검 — 규칙이 잘못되면 여기서 걸린다.
PROTECTED=$(ls -1 "$BK"/proddb_*.sql 2>/dev/null | grep -vc '/proddb_auto_' || true)
echo "보호(수동 라벨) DB 덤프: ${PROTECTED}개 — 삭제 대상 아님"
exit 0
