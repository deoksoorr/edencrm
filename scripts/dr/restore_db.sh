#!/bin/bash
# DR 테스트 T5 — DB 백업 실제 import.
#
# 이 단계가 DR 테스트의 핵심이다. "import 명령이 0 을 반환했다"는 성공 근거가 아니다.
# 오류를 무시하지 않고, 실패하면 라인 번호까지 남기고 멈춘다.
#
# 특히 이번 복구는 엔진이 교차한다: 백업 원본 MariaDB 10.6 → 복구 대상 MySQL 9.6.
# 실제 장애 시 복구 대상은 보통 같은 MariaDB 이므로 이건 더 엄격한 조건이며,
# 여기서 나오는 비호환은 "교차 엔진 복구 가능 여부"라는 별도 판정으로 기록한다.
#
# 사용: bash scripts/dr/restore_db.sh

source "$(dirname "${BASH_SOURCE[0]}")/dr_env.sh"
dr_guard

# 인자로 덤프 파일을 지정할 수 있다(복구변환본 재시도용). 미지정 시 원본 백업.
BACKUP_SQL="${1:-$BACKUP_SQL}"
LABEL="${2:-raw}"

EV="$RESTORE_ROOT/_dr/evidence"
LOG="$RESTORE_ROOT/_dr/logs/db_import_${LABEL}.log"
mkdir -p "$EV" "$(dirname "$LOG")"

echo "── T5 DB import (${LABEL}) ──"
[[ -f "$BACKUP_SQL" ]] || die "DB 백업 없음: $BACKUP_SQL"
echo "  덤프: $(basename "$BACKUP_SQL") ($(stat -f%z "$BACKUP_SQL") bytes)"

# ── 1. 복원 전 확인 ─────────────────────────────────────────────────────────
BEFORE=$(rdb_root -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RESTORE_DB'")
echo "  복원 전 테이블 수: $BEFORE"
if [[ "$BEFORE" != "0" ]]; then
    echo "  ⚠ 비어있지 않음 — 이전 시도의 잔재. 복구 DB 를 초기화한다(운영 아님, 가드 통과 확인됨)."
    rdb_root -e "DROP DATABASE \`$RESTORE_DB\`; CREATE DATABASE \`$RESTORE_DB\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    rdb_root -e "GRANT ALL PRIVILEGES ON \`$RESTORE_DB\`.* TO '$RESTORE_DB_USER'@'localhost'; FLUSH PRIVILEGES;"
    echo "  초기화 완료 (테이블 0)"
fi

# 복구 계정 권한 범위 — 이 DB 외에는 못 건드리는지 확인
echo "  복구 계정 권한:"
rdb_root -N -B -e "SHOW GRANTS FOR '$RESTORE_DB_USER'@'localhost'" | sed 's/^/    /'

# ── 2. import 실행 ──────────────────────────────────────────────────────────
# 핵심: --default-character-set=utf8mb4.
# 이 덤프에는 SET NAMES 선언이 없다. 클라이언트 기본 charset 이 utf8mb4 가 아니면
# 한글이 조용히 깨진 채로 import 가 "성공"한다. 옵션 하나가 복구 성패를 가른다.
# --force 는 쓰지 않는다 — 오류를 건너뛰면 결손을 못 본다.
echo "  import 시작 (--default-character-set=utf8mb4, --force 미사용)"
T_START=$(date +%s.%N)
set +e
"$MYSQL_BIN" --socket="$DEV_SOCK" --default-character-set=utf8mb4 \
    -u"$RESTORE_DB_USER" -p"$RESTORE_DB_PASS" \
    --show-warnings "$RESTORE_DB" < "$BACKUP_SQL" > "$LOG" 2>&1
IMPORT_RC=$?
set -e
T_END=$(date +%s.%N)
IMPORT_SEC=$(echo "$T_END - $T_START" | bc)

printf "  import 종료코드: %d · 소요 %.2f초\n" "$IMPORT_RC" "$IMPORT_SEC"

if [[ $IMPORT_RC -ne 0 ]]; then
    echo "  ❌ import 실패 — 오류 원문:"
    sed 's/^/    /' "$LOG" | head -20
    echo "  로그 전문: $LOG"
    echo "  (오류를 무시하고 진행하지 않는다. 원인 분류 후 재실행 필요.)"
    exit 1
fi

WARN_N=$(grep -ci "warning" "$LOG" 2>/dev/null || echo 0)
ERR_N=$(grep -ciE "^ERROR|ERROR [0-9]+" "$LOG" 2>/dev/null || echo 0)
echo "  로그: 경고 $WARN_N · 오류 $ERR_N ($LOG)"
[[ "$WARN_N" -gt 0 ]] && { echo "  경고 샘플:"; grep -i "warning" "$LOG" | head -5 | sed 's/^/    /'; }

# ── 3. 구조 검증 ────────────────────────────────────────────────────────────
echo "  구조 검증:"
Q() { rdb_root -N -B -e "$1"; }
TBL=$(Q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RESTORE_DB' AND TABLE_TYPE='BASE TABLE'")
VIEW=$(Q "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$RESTORE_DB'")
TRG=$(Q "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$RESTORE_DB'")
FK=$(Q "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$RESTORE_DB' AND REFERENCED_TABLE_NAME IS NOT NULL")
IDX=$(Q "SELECT COUNT(*) FROM (SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$RESTORE_DB') x")
AI=$(Q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RESTORE_DB' AND AUTO_INCREMENT IS NOT NULL")
DEC=$(Q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$RESTORE_DB' AND DATA_TYPE='decimal'")
COLL=$(Q "SELECT COUNT(DISTINCT TABLE_COLLATION) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RESTORE_DB' AND TABLE_TYPE='BASE TABLE'")
COLLNAME=$(Q "SELECT GROUP_CONCAT(DISTINCT TABLE_COLLATION) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RESTORE_DB' AND TABLE_TYPE='BASE TABLE'")
ROWS=$(Q "SELECT SUM(c) FROM (SELECT COUNT(*) c FROM \`${RESTORE_PREFIX}audit_logs\` UNION ALL SELECT 0) x" 2>/dev/null || echo "?")

printf "    테이블 %s · 뷰 %s · 트리거 %s · FK %s · 인덱스 %s\n" "$TBL" "$VIEW" "$TRG" "$FK" "$IDX"
printf "    AUTO_INCREMENT 보유 %s · DECIMAL 컬럼 %s\n" "$AI" "$DEC"
printf "    테이블 collation 종류 %s (%s)\n" "$COLL" "$COLLNAME"

# ── 4. 한글 왕복 검증 ───────────────────────────────────────────────────────
# 덤프에 SET NAMES 가 없어 charset 사고가 가장 나기 쉬운 지점. 바이트로 확인한다.
echo "  한글 무결성:"
rdb_root -e "SELECT id, name, HEX(name) AS hex, CHAR_LENGTH(name) AS chars, LENGTH(name) AS bytes
             FROM \`$RESTORE_DB\`.\`${RESTORE_PREFIX}customers\` ORDER BY id LIMIT 5" 2>&1 | sed 's/^/    /'

# ── 5. 결과 JSON ────────────────────────────────────────────────────────────
cat > "$EV/t5_db_restore_${LABEL}.json" <<JSON
{
  "task": "T5",
  "imported_at": "$(date '+%Y-%m-%dT%H:%M:%S%z')",
  "dump_file": "$(basename "$BACKUP_SQL")",
  "dump_bytes": $(stat -f%z "$BACKUP_SQL"),
  "import_rc": $IMPORT_RC,
  "import_seconds": $IMPORT_SEC,
  "log_warnings": $WARN_N,
  "log_errors": $ERR_N,
  "source_engine": "MariaDB 10.6.17",
  "target_engine": "MySQL $(Q 'SELECT VERSION()')",
  "tables": $TBL, "views": $VIEW, "triggers": $TRG,
  "foreign_keys": $FK, "indexes": $IDX,
  "auto_increment_tables": $AI, "decimal_columns": $DEC,
  "table_collations": "$COLLNAME"
}
JSON
echo "── T5 결과: $EV/t5_db_restore_${LABEL}.json ──"
