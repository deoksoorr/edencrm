#!/bin/bash
# EDEN CRM 자동 백업 — 단일 진입점 (launchd 가 매시 호출).
#
# 왜 필요한가: 2026-07-29 DR 테스트 실측으로 백업이 100% 수동이고 배포 직전에만
# 실행됨을 확인했다. 실측 최대 공백 94.9시간(3.95일). 그 공백에 실제 업무가 있었다는
# 증거도 있다 — 고객 첨부 파일 하나의 mtime 이 7/26 인데 이를 감싸는 백업은
# 7/23 과 7/27 뿐이었다. 7/26 장애였다면 3일치 유실이었다.
#
# 매시 호출되지만 실제 백업은 하루 1회다. MIN_INTERVAL_MIN(기본 20시간) 가드가
# 나머지를 SKIP 시킨다. 특정 시각 슬롯 방식은 노트북에서 취약하다 — 그 시각에
# 닫혀 있으면 놓친다. 매시 + 신선도 가드는 노트북이 열려 있는 아무 시각에나
# 그날 백업을 성사시킨다.
#
# 사용:
#   bash deploy/backup_auto.sh            # 신선도 가드 적용(launchd 기본)
#   bash deploy/backup_auto.sh --once     # 가드 무시하고 강제 실행
#
# 종료코드: 0=성공 또는 정상 SKIP/DEFER · 1=실패(알림 발송됨)

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
BK="$PROJECT_DIR/database/backups"
MANIFEST_DIR="$BK/manifests"
LOGDIR="${EDEN_LOG_DIR:-$HOME/Library/Logs/eden_crm}"
LOG="$LOGDIR/backup.log"
LOCK="${EDEN_LOCK_FILE:-/tmp/eden_crm_backup.lock}"

MIN_INTERVAL_MIN="${MIN_INTERVAL_MIN:-1200}"   # 20시간
STALE_WARN_H="${STALE_WARN_H:-26}"
LABEL="${BACKUP_LABEL:-auto}"

FORCE=0
[ "${1:-}" = "--once" ] && FORCE=1

mkdir -p "$BK" "$MANIFEST_DIR" "$LOGDIR" 2>/dev/null

ts()   { date '+%Y-%m-%dT%H:%M:%S%z'; }
logl() { printf '%s %-7s %s\n' "$(ts)" "$1" "$2" >> "$LOG"; echo "[$1] $2"; }
notify() { bash "$SCRIPT_DIR/notify.sh" "$@" >/dev/null 2>&1 || true; }

# ── 단독 실행 보장 ──────────────────────────────────────────────────────────
# lockf -t 0 = 이미 실행 중이면 즉시 실패. 중복 실행은 조용히 넘긴다(정상 상황).
if command -v lockf >/dev/null 2>&1 && [ "${EDEN_LOCKED:-0}" != "1" ]; then
    EDEN_LOCKED=1 exec lockf -t 0 "$LOCK" "$0" "$@"
fi

# ── 필수 바이너리 ───────────────────────────────────────────────────────────
for b in lftp php; do
    command -v "$b" >/dev/null || {
        logl ALERT "필수 바이너리 없음: $b"
        notify --code MISSING_BIN "❌ EDEN CRM 백업 실패 — $b 를 찾을 수 없습니다(PATH 문제일 수 있음)"
        exit 1
    }
done

# ── 마지막 성공 백업 조회 ───────────────────────────────────────────────────
# 별도 상태 파일을 두지 않는다. 상태 파일과 현실이 어긋나는 종류의 버그를 원천 차단하려면
# 산출물 자체(verdict=OK 매니페스트)에서 유도하는 편이 안전하다.
last_ok_epoch() {
    local newest=0 e
    for m in "$MANIFEST_DIR"/run_*.json; do
        [ -f "$m" ] || continue
        grep -q '"verdict"[[:space:]]*:[[:space:]]*"OK"' "$m" || continue
        e=$(stat -f %m "$m" 2>/dev/null || echo 0)
        [ "$e" -gt "$newest" ] && newest="$e"
    done
    echo "$newest"
}
LAST_OK=$(last_ok_epoch)
NOW=$(date +%s)
AGE_MIN=$(( LAST_OK > 0 ? (NOW - LAST_OK) / 60 : 999999 ))

# ── 신선도 판단 ─────────────────────────────────────────────────────────────
if [ "$FORCE" -eq 0 ] && [ "$AGE_MIN" -lt "$MIN_INTERVAL_MIN" ]; then
    logl SKIP "신선함 (마지막 성공 ${AGE_MIN}분 전 < 기준 ${MIN_INTERVAL_MIN}분)"
    exit 0
fi

# 오래 방치되면 알린다. 오프라인이 지속되는 상황을 사람이 알아채는 유일한 신호다.
if [ "$LAST_OK" -gt 0 ] && [ "$AGE_MIN" -gt $((STALE_WARN_H * 60)) ]; then
    notify --code STALE_BACKUP "⚠️ EDEN CRM 백업이 $((AGE_MIN / 60))시간째 없습니다. 맥이 네트워크에 연결된 상태로 켜져 있는지 확인해 주세요."
fi

# ── 네트워크 확인 ───────────────────────────────────────────────────────────
# 노트북에서 오프라인은 흔한 정상 상태다. 여기에 알림을 붙이면 하루에도 여러 번 울리고
# 사용자는 곧 모든 백업 알림을 무시하게 된다 — 그러면 진짜 실패도 묻힌다.
# 오프라인은 조용히 연기(DEFER)하고, 지속 여부는 위 STALE 알림이 전담한다.
ENV_FILE="${EDEN_ENV_FILE:-$SCRIPT_DIR/cafe24.env}"
[ -f "$ENV_FILE" ] || { logl ALERT "환경파일 없음"; notify --code NO_ENV "❌ EDEN CRM 백업 실패 — 환경파일 없음"; exit 1; }
set -a; source "$ENV_FILE"; set +a

online() { /sbin/route -n get default >/dev/null 2>&1; }
reachable() { nc -z -G 5 -w 5 "$FTP_HOST" "${FTP_PORT:-21}" >/dev/null 2>&1; }

if ! online; then
    for _ in 1 2 3; do
        sleep 300
        online && break
    done
fi
if ! online; then
    logl DEFER "오프라인 (15분 재시도 후에도 미연결)"
    exit 0
fi
if ! reachable; then
    sleep 60
    if ! reachable; then
        # 인터넷은 되는데 서버에 못 닿는다 = 진짜 이상 신호 → 알린다.
        logl ALERT "FTP 서버 도달 불가"
        notify --code FTP_UNREACHABLE "❌ EDEN CRM 백업 실패 — FTP 서버에 연결할 수 없습니다(인터넷은 정상)."
        exit 1
    fi
fi

# ── 실행 ────────────────────────────────────────────────────────────────────
RUN_TS=$(date +%Y%m%d-%H%M%S)
T_START=$(date +%s)
MANIFEST="$MANIFEST_DIR/run_${RUN_TS}.json"

fail_out() {   # fail_out <code> <사유>
    logl ALERT "run=$RUN_TS code=$1 $2"
    notify --code "$1" "❌ EDEN CRM 백업 실패 ($RUN_TS)
$2"
    cat > "$MANIFEST" <<JSON
{"run_ts":"$RUN_TS","label":"$LABEL","verdict":"FAIL","code":"$1","reason":$(printf '%s' "$2" | sed 's/\\/\\\\/g; s/"/\\"/g; s/^/"/; s/$/"/'),"at":"$(ts)"}
JSON
    exit 1
}

# 1) DB 먼저.
#
# 앱은 파일을 디스크에 먼저 쓰고 project_files 행을 나중에 넣는다
# (ContractsController.php:601-602 등). 그래서 DB 를 먼저 뜨면 그 시점 DB 가 가진
# 모든 파일 행은 이미 디스크에 있고 뒤이은 미러에도 담긴다 → dangling reference 0.
# 순서를 뒤집으면 그 사이 업로드가 "DB엔 있는데 파일 없음" = 복구본에서 첨부 404 가 된다.
DB_OUT=$(DUMP_TS="$RUN_TS" php "$SCRIPT_DIR/db_dump.php" "$LABEL" 2>&1)
DB_RC=$?
[ "$DB_RC" -ne 0 ] && fail_out DB_DUMP_RC "DB 덤프 실패(rc=$DB_RC): $(echo "$DB_OUT" | tail -2 | tr '\n' ' ' | cut -c1-160)"
DB_FILE="$BK/proddb_${LABEL}_${RUN_TS}.sql"
[ -f "$DB_FILE" ] || fail_out DB_DUMP_MISSING "DB 덤프 파일이 생성되지 않음"
DB_TABLES=$(echo "$DB_OUT" | grep -oE '\(([0-9]+) tables' | grep -oE '[0-9]+' | head -1)
DB_ROWS=$(echo "$DB_OUT" | grep -oE '[0-9]+ rows,' | grep -oE '[0-9]+' | head -1)
DB_BYTES=$(stat -f%z "$DB_FILE" 2>/dev/null || echo 0)
T_DB=$(date +%s)

# 2) 파일. 같은 RUN_TS 를 주입해 파일명 타임스탬프를 일치시킨다.
FILE_OUT=$(BACKUP_TS="$RUN_TS" bash "$SCRIPT_DIR/backup.sh" 2>&1)
FILE_RC=$?
if [ "$FILE_RC" -ne 0 ]; then
    # 파일 백업이 실패하면 짝이 없는 DB 덤프만 남는다 — 반쪽 백업은 오해를 부르므로 치운다.
    rm -f "$DB_FILE"
    REASON=$(echo "$FILE_OUT" | grep -m1 'FAIL:' | cut -c1-160)
    fail_out FILE_BACKUP_RC "파일 백업 실패(rc=$FILE_RC): ${REASON:-원인 미상} · 짝 없는 DB 덤프는 제거함"
fi
RESULT_LINE=$(echo "$FILE_OUT" | grep -m1 '^RESULT ')
# 앞에 공백을 요구해야 한다. `files=` 만 찾으면 `code_files=`·`upload_files=` 안에서도
# 매치돼 값이 여러 줄로 나오고, 그대로 JSON 에 들어가면 매니페스트가 깨진다(실측 확인).
kv() { echo "$RESULT_LINE" | grep -oE "(^|[[:space:]])$1=[^[:space:]]*" | head -1 | cut -d= -f2-; }
F_FILES=$(kv files); F_CODE=$(kv code_files); F_UP=$(kv upload_files); F_BYTES=$(kv bytes)
F_WARN=$(echo "$RESULT_LINE" | sed -n 's/.*warn=\(.*\)$/\1/p')
T_END=$(date +%s)

# ── 매니페스트 ──────────────────────────────────────────────────────────────
# ftp_*/ 안이 아니라 형제 디렉터리에 둔다. rehearsal.sh 가 백업 디렉터리 전체를
# 복구본에 rsync 하므로 안에 넣으면 복구본을 오염시킨다.
cat > "$MANIFEST" <<JSON
{
  "run_ts": "$RUN_TS",
  "label": "$LABEL",
  "verdict": "OK",
  "at": "$(ts)",
  "order": "db_first",
  "db":    {"file":"proddb_${LABEL}_${RUN_TS}.sql","tables":${DB_TABLES:-0},"rows":${DB_ROWS:-0},"bytes":$DB_BYTES,"seconds":$((T_DB - T_START))},
  "files": {"dir":"ftp_${RUN_TS}","count":${F_FILES:-0},"code_files":${F_CODE:-0},"upload_files":${F_UP:-0},"bytes":${F_BYTES:-0},"seconds":$((T_END - T_DB))},
  "content_skew_seconds": $((T_END - T_DB)),
  "warn": "${F_WARN:--}",
  "elapsed_seconds": $((T_END - T_START))
}
JSON

logl OK "run=$RUN_TS files=${F_FILES}/$(( ${F_BYTES:-0} / 1024 ))KB code=${F_CODE} up=${F_UP} db=${DB_TABLES}t/${DB_ROWS}r/$((DB_BYTES / 1024))KB skew=$((T_END - T_DB))s elapsed=$((T_END - T_START))s warn=${F_WARN:--}"

[ "${F_WARN:--}" != "-" ] && notify --code BACKUP_WARN "⚠️ EDEN CRM 백업 경고 ($RUN_TS): ${F_WARN}"

# 직전에 실패가 있었다면 복구됐음을 한 번 알린다.
PREV_MANIFEST=$(ls -1t "$MANIFEST_DIR"/run_*.json 2>/dev/null | sed -n '2p')
if [ -n "${PREV_MANIFEST:-}" ] && grep -q '"verdict"[[:space:]]*:[[:space:]]*"FAIL"' "$PREV_MANIFEST" 2>/dev/null; then
    notify --code RECOVERED "✅ EDEN CRM 백업 정상 복구 ($RUN_TS) — 직전 실행은 실패했었습니다."
fi

# ── 보존 정책 ───────────────────────────────────────────────────────────────
if [ -x "$SCRIPT_DIR/backup_prune.sh" ]; then
    bash "$SCRIPT_DIR/backup_prune.sh" --apply >>"$LOG" 2>&1 || logl WARN "정리(prune) 실패 — 백업 자체는 성공"
fi

exit 0
