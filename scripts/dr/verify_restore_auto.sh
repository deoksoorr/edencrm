#!/bin/bash
# EDEN CRM 주간 자동 복구 검증 — 최신 auto 백업을 실제로 import 해 본다.
#
# 이번 사고의 본질은 "백업을 만들기만 하고 복구되는지 확인하지 않은 것"이다.
# 생성컬럼 결함으로 백업 14개 전부가 복구 불가였는데 3개월간 발각되지 않았다.
# 재발 방지는 **실제로 import 해 보는 것** 외에 없다.
#
# 핵심은 repair_dump.php 를 거치지 않고 바로 import 한다는 점이다.
# 이건 2026-07-29 db_dump.php 수정에 대한 회귀 테스트다 — 변환을 거쳐야만
# 성공한다면 그 자체가 결함 신호다.
#
# 사용:
#   bash scripts/dr/verify_restore_auto.sh          # 6일 가드 적용(launchd 기본)
#   bash scripts/dr/verify_restore_auto.sh --force  # 가드 무시

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
BK="$PROJECT_DIR/database/backups"
OUTDIR="$PROJECT_DIR/docs/audit/dr/auto_verify"
LOGDIR="${EDEN_LOG_DIR:-$HOME/Library/Logs/eden_crm}"
LOG="$LOGDIR/backup.log"
MIN_DAYS="${VERIFY_MIN_DAYS:-6}"

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

mkdir -p "$OUTDIR" "$LOGDIR"
ts()   { date '+%Y-%m-%dT%H:%M:%S%z'; }
logl() { printf '%s %-7s %s\n' "$(ts)" "$1" "$2" >> "$LOG"; echo "[$1] $2"; }
notify() { bash "$PROJECT_DIR/deploy/notify.sh" "$@" >/dev/null 2>&1 || true; }

# ── 신선도 가드 ─────────────────────────────────────────────────────────────
# launchd 캐치업으로 중복 실행되는 것을 막는다.
if [ "$FORCE" -eq 0 ]; then
    LATEST_OUT=$(ls -1t "$OUTDIR"/*.json 2>/dev/null | head -1)
    if [ -n "${LATEST_OUT:-}" ]; then
        AGE=$(( ( $(date +%s) - $(stat -f %m "$LATEST_OUT") ) / 86400 ))
        [ "$AGE" -lt "$MIN_DAYS" ] && { logl SKIP "복구검증 최근 실행 ${AGE}일 전 < ${MIN_DAYS}일"; exit 0; }
    fi
fi

# ── 격리 MySQL 기동 ─────────────────────────────────────────────────────────
# start_dev.sh 를 그대로 호출하면 안 된다 — 마지막 줄이 포그라운드 `php -S` 라
# launchd 잡이 영원히 끝나지 않는다. DB 기동 로직만 가져온다.
MYSQL_BIN="/opt/homebrew/bin/mysql"
MYSQLD="/opt/homebrew/bin/mysqld"
SOCK="$PROJECT_DIR/.devdb/mysql.sock"

if ! "$MYSQL_BIN" --socket="$SOCK" -uroot -e "SELECT 1" >/dev/null 2>&1; then
    [ -d "$PROJECT_DIR/.devdb/data" ] || {
        logl ALERT "격리 MySQL 데이터디렉토리 없음 — 검증 불가"
        notify --code VERIFY_NO_DB "❌ EDEN CRM 주간 복구검증 실패 — 격리 MySQL 이 초기화되지 않았습니다."
        exit 1
    }
    "$MYSQLD" --datadir="$PROJECT_DIR/.devdb/data" --port=3307 --socket="$SOCK" \
        --pid-file="$PROJECT_DIR/.devdb/mysqld.pid" --mysqlx=0 \
        --log-error="$PROJECT_DIR/.devdb/error.log" >/dev/null 2>&1 &
    for _ in $(seq 1 30); do
        "$MYSQL_BIN" --socket="$SOCK" -uroot -e "SELECT 1" >/dev/null 2>&1 && break
        sleep 1
    done
fi
# 기동 실패도 알림 대상이다. "검증이 조용히 안 도는 것"이 바로 원래 사고의 형태다.
"$MYSQL_BIN" --socket="$SOCK" -uroot -e "SELECT 1" >/dev/null 2>&1 || {
    logl ALERT "격리 MySQL 기동 실패 — 검증 불가"
    notify --code VERIFY_NO_DB "❌ EDEN CRM 주간 복구검증 실패 — 격리 MySQL 을 기동할 수 없습니다."
    exit 1
}

# ── 대상 선정 ───────────────────────────────────────────────────────────────
LATEST_SQL=$(ls -1t "$BK"/proddb_auto_*.sql 2>/dev/null | head -1)
[ -n "${LATEST_SQL:-}" ] || { logl SKIP "auto 덤프 없음 — 검증 대상 없음"; exit 0; }
RUN_TS=$(basename "$LATEST_SQL" | sed 's/proddb_auto_//; s/\.sql//')
DATESTAMP=$(date '+%Y%m%d')
OUT="$OUTDIR/${DATESTAMP}.json"

logl INFO "복구검증 시작 대상=$(basename "$LATEST_SQL")"
T0=$(date +%s)

# ── 무변환 import (핵심 회귀 테스트) ────────────────────────────────────────
# restore_db.sh 가 dr_env.sh 의 가드 8종을 통과해야만 실행된다 → 운영 오염 원천 차단.
IMPORT_OUT=$(bash "$SCRIPT_DIR/restore_db.sh" "$LATEST_SQL" auto_weekly 2>&1)
IMPORT_RC=$?
T1=$(date +%s)

if [ "$IMPORT_RC" -ne 0 ]; then
    DETAIL=$(echo "$IMPORT_OUT" | grep -iE 'ERROR|실패' | head -2 | tr '\n' ' ' | cut -c1-200)
    logl ALERT "복구검증 실패 — 무변환 import 불가: $DETAIL"
    cat > "$OUT" <<JSON
{"date":"$DATESTAMP","target":"$(basename "$LATEST_SQL")","verdict":"FAIL",
 "stage":"import","rc":$IMPORT_RC,"detail":$(printf '%s' "$DETAIL" | sed 's/\\/\\\\/g; s/"/\\"/g; s/^/"/; s/$/"/'),"at":"$(ts)"}
JSON
    notify --code RESTORE_VERIFY_FAIL "❌ EDEN CRM 주간 복구검증 실패
최신 백업($RUN_TS)이 변환 없이는 복구되지 않습니다.
$DETAIL"
    exit 1
fi

# ── 어서션 ──────────────────────────────────────────────────────────────────
RDB="eden_crm_restore_test"
Q() { "$MYSQL_BIN" --socket="$SOCK" -uroot -N -B -e "$1" 2>/dev/null; }
TABLES=$(Q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RDB' AND TABLE_TYPE='BASE TABLE'")
VIEWS=$(Q "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$RDB'")
TRIGGERS=$(Q "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$RDB'")
FKS=$(Q "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$RDB' AND REFERENCED_TABLE_NAME IS NOT NULL")

# 덤프의 INSERT 수와 복구 DB 실제 행수가 같아야 한다 — 부분 import 검출.
DUMP_INSERTS=$(grep -c '^INSERT INTO' "$LATEST_SQL")
DB_ROWS=$(Q "SELECT COALESCE(SUM(c),0) FROM (SELECT TABLE_NAME, (SELECT COUNT(*) FROM information_schema.TABLES t2 WHERE 1=0) AS c FROM information_schema.TABLES WHERE 1=0) x")
# information_schema 는 추정치라 못 쓴다. 테이블을 돌며 실제로 센다.
DB_ROWS=0
for t in $(Q "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RDB' AND TABLE_TYPE='BASE TABLE'"); do
    n=$(Q "SELECT COUNT(*) FROM \`$RDB\`.\`$t\`")
    DB_ROWS=$((DB_ROWS + ${n:-0}))
done

# 한글 왕복 (charset 사고 검출)
HANGUL=$(Q "SELECT HEX(name) FROM \`$RDB\`.edencrm_customers ORDER BY id LIMIT 1")

PROBLEMS=""
[ "${TABLES:-0}" -lt 40 ] && PROBLEMS="$PROBLEMS 테이블 ${TABLES}개(40 미만);"
[ "${DB_ROWS:-0}" -ne "${DUMP_INSERTS:-0}" ] && PROBLEMS="$PROBLEMS 행수 불일치(덤프 ${DUMP_INSERTS} vs DB ${DB_ROWS});"
# db_dump.php 는 뷰·트리거를 백업하지 않는다. 운영에 생기면 조용히 유실되므로
# 0 이라는 가정이 깨지는 순간을 여기서 잡는다.
[ "${VIEWS:-0}" -ne 0 ] && PROBLEMS="$PROBLEMS 복구본에 뷰 ${VIEWS}개(백업 대상 밖);"
[ "${TRIGGERS:-0}" -ne 0 ] && PROBLEMS="$PROBLEMS 복구본에 트리거 ${TRIGGERS}개(백업 대상 밖);"
[ -z "${HANGUL:-}" ] && PROBLEMS="$PROBLEMS 한글 시료 없음;"

T2=$(date +%s)
VERDICT=$([ -z "$PROBLEMS" ] && echo OK || echo FAIL)

cat > "$OUT" <<JSON
{
  "date": "$DATESTAMP",
  "target": "$(basename "$LATEST_SQL")",
  "verdict": "$VERDICT",
  "note": "repair_dump 를 거치지 않은 무변환 import — db_dump.php 수정에 대한 회귀 테스트",
  "tables": ${TABLES:-0}, "views": ${VIEWS:-0}, "triggers": ${TRIGGERS:-0}, "foreign_keys": ${FKS:-0},
  "dump_inserts": ${DUMP_INSERTS:-0}, "restored_rows": ${DB_ROWS:-0},
  "hangul_hex_sample": "${HANGUL:-}",
  "import_seconds": $((T1 - T0)), "total_seconds": $((T2 - T0)),
  "problems": "${PROBLEMS:-none}",
  "at": "$(ts)"
}
JSON

if [ "$VERDICT" = "OK" ]; then
    logl OK "복구검증 통과 대상=$RUN_TS 테이블=${TABLES} 행=${DB_ROWS} import=$((T1 - T0))s"
else
    logl ALERT "복구검증 이상: $PROBLEMS"
    notify --code RESTORE_VERIFY_FAIL "⚠️ EDEN CRM 주간 복구검증 이상 ($RUN_TS)
$PROBLEMS"
    exit 1
fi
exit 0
