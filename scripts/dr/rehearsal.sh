#!/bin/bash
# DR 테스트 T10 — 복구 전체 리허설(시간 측정).
#
# 앞선 T3~T8 은 단계를 하나씩 검증하며 진행했다. 여기서는 그 검증된 절차를
# **처음부터 끝까지 한 번에** 돌려 실제 복구 소요시간(RTO)을 잰다.
# 시간은 추정하지 않는다 — 매 단계 실측해서 기록한다.
#
# 사용: bash scripts/dr/rehearsal.sh

source "$(dirname "${BASH_SOURCE[0]}")/dr_env.sh"
dr_guard

EV="$RESTORE_ROOT/_dr/evidence"
mkdir -p "$EV"
OUT="$EV/t10_rto.json"

now() { python3 -c 'import time; print(f"{time.time():.3f}")'; }
el()  { python3 -c "print(f'{$2 - $1:.2f}')"; }

echo "════════ 복구 리허설 (실측) ════════"
T_ALL0=$(now)

# ── 1) 백업 파일 확인 ───────────────────────────────────────────────────────
T0=$(now)
[[ -d "$BACKUP_FILES" ]] || die "파일 백업 없음"
[[ -f "$BACKUP_SQL" ]] || die "DB 백업 없음"
FILE_N=$(find "$BACKUP_FILES" -type f | wc -l | tr -d ' ')
SQL_SZ=$(stat -f%z "$BACKUP_SQL")
T1=$(now); S1=$(el $T0 $T1)
printf "1) 백업 확인        %6ss  (파일 %s개 · 덤프 %s bytes)\n" "$S1" "$FILE_N" "$SQL_SZ"

# ── 2) 파일 복원 ────────────────────────────────────────────────────────────
T0=$(now)
find "$RESTORE_ROOT" -mindepth 1 -maxdepth 1 -not -name '_dr' -exec rm -rf {} +
rsync -a --exclude '_dr/' "$BACKUP_FILES/" "$RESTORE_ROOT/"
T1=$(now); S2=$(el $T0 $T1)
RESTORED_N=$(find "$RESTORE_ROOT" -type f -not -path "$RESTORE_ROOT/_dr/*" | wc -l | tr -d ' ')
printf "2) 파일 복원        %6ss  (%s개 복원)\n" "$S2" "$RESTORED_N"

# ── 3) 운영 설정 격리 ───────────────────────────────────────────────────────
T0=$(now)
mkdir -p "$RESTORE_ROOT/_dr/quarantine"
for f in "app/config/config.local.php" "app/config/config.production.php" "deploy/cafe24.env"; do
    [[ -f "$RESTORE_ROOT/$f" ]] && mv "$RESTORE_ROOT/$f" "$RESTORE_ROOT/_dr/quarantine/$(basename "$f")"
done
chmod -R u+w "$RESTORE_ROOT/storage" 2>/dev/null || true
T1=$(now); S3=$(el $T0 $T1)
printf "3) 설정 격리·권한   %6ss\n" "$S3"

# ── 4) 덤프 복구변환 ────────────────────────────────────────────────────────
# 현재 보유한 백업은 생성컬럼 결함으로 그대로는 import 되지 않는다.
# 이 단계는 db_dump.php 수정 이후 만들어진 백업에는 불필요하다.
T0=$(now)
mkdir -p "$RESTORE_ROOT/_dr/repaired"
REPAIRED="$RESTORE_ROOT/_dr/repaired/proddb_repaired.sql"
"$PHP_BIN" "$PROJECT_ROOT/scripts/dr/repair_dump.php" "$BACKUP_SQL" "$REPAIRED" >/dev/null
T1=$(now); S4=$(el $T0 $T1)
printf "4) 덤프 복구변환    %6ss  (생성컬럼 제외 + charset 선언)\n" "$S4"

# ── 5) DB 생성 ──────────────────────────────────────────────────────────────
T0=$(now)
rdb_root -e "DROP DATABASE IF EXISTS \`$RESTORE_DB\`;
             CREATE DATABASE \`$RESTORE_DB\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
             CREATE USER IF NOT EXISTS '$RESTORE_DB_USER'@'localhost' IDENTIFIED BY '$RESTORE_DB_PASS';
             GRANT ALL PRIVILEGES ON \`$RESTORE_DB\`.* TO '$RESTORE_DB_USER'@'localhost';
             FLUSH PRIVILEGES;"
T1=$(now); S5=$(el $T0 $T1)
printf "5) DB 생성          %6ss\n" "$S5"

# ── 6) DB import ────────────────────────────────────────────────────────────
T0=$(now)
"$MYSQL_BIN" --socket="$DEV_SOCK" --default-character-set=utf8mb4 \
    -u"$RESTORE_DB_USER" -p"$RESTORE_DB_PASS" "$RESTORE_DB" < "$REPAIRED" \
    > "$RESTORE_ROOT/_dr/logs/rehearsal_import.log" 2>&1
IMP_RC=$?
T1=$(now); S6=$(el $T0 $T1)
[[ $IMP_RC -eq 0 ]] || { echo "❌ import 실패(rc=$IMP_RC)"; exit 1; }
TBL=$(rdb_root -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RESTORE_DB' AND TABLE_TYPE='BASE TABLE'")
printf "6) DB import        %6ss  (테이블 %s개)\n" "$S6" "$TBL"

# ── 7) 애플리케이션 기동 ────────────────────────────────────────────────────
T0=$(now)
pkill -f "127.0.0.1:$RESTORE_PORT" 2>/dev/null || true
sleep 0.5
nohup bash "$RESTORE_ROOT/_dr/serve.sh" > "$RESTORE_ROOT/_dr/logs/server.log" 2>&1 &
for i in $(seq 1 60); do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "http://127.0.0.1:$RESTORE_PORT/index.php?r=login" || echo 000)
    [[ "$code" == "200" ]] && break
    sleep 0.25
done
T1=$(now); S7=$(el $T0 $T1)
printf "7) 앱 기동          %6ss  (로그인 화면 HTTP %s)\n" "$S7" "$code"

# ── 8) 핵심 기능 사용 가능 시점 ─────────────────────────────────────────────
# "화면이 뜬다"가 아니라 "로그인해서 업무 데이터를 조회할 수 있다"까지를 잰다.
T0=$(now)
"$PHP_BIN" "$PROJECT_ROOT/scripts/dr/qa/set_qa_passwords.php" >/dev/null 2>&1
CJ=$(mktemp)
TOKEN=$(curl -s -c "$CJ" "http://127.0.0.1:$RESTORE_PORT/index.php?r=login" \
        | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$CJ" -c "$CJ" -o /dev/null -X POST "http://127.0.0.1:$RESTORE_PORT/index.php?r=login.submit" \
     --data-urlencode "_csrf=$TOKEN" --data-urlencode "login_id=admin" --data-urlencode "password=QArestore!2026admin"
DASH=$(curl -s -b "$CJ" "http://127.0.0.1:$RESTORE_PORT/index.php?r=dashboard")
CTR=$(curl -s -b "$CJ" "http://127.0.0.1:$RESTORE_PORT/index.php?r=contracts.index")
rm -f "$CJ"
T1=$(now); S8=$(el $T0 $T1)
USABLE=$(echo "$DASH" | grep -qE "r=logout" && echo "$CTR" | grep -qE "r=logout" && echo yes || echo no)
printf "8) 핵심 기능 가능   %6ss  (로그인+대시보드+계약목록: %s)\n" "$S8" "$USABLE"

T_ALL1=$(now); TOTAL=$(el $T_ALL0 $T_ALL1)
echo "───────────────────────────────────"
printf "전체 복구 완료      %6ss\n" "$TOTAL"

cat > "$OUT" <<JSON
{
  "task": "T10",
  "measured_at": "$(date '+%Y-%m-%dT%H:%M:%S%z')",
  "note": "실측값(추정 아님). 로컬 격리 환경 기준 — 원격 복구 시 전송시간이 추가된다.",
  "steps": {
    "1_backup_check_sec": $S1,
    "2_file_restore_sec": $S2,
    "3_config_isolate_sec": $S3,
    "4_dump_repair_sec": $S4,
    "5_db_create_sec": $S5,
    "6_db_import_sec": $S6,
    "7_app_boot_sec": $S7,
    "8_core_usable_sec": $S8
  },
  "totals": {
    "file_restore_sec": $S2,
    "db_restore_sec": $(python3 -c "print(f'{$S4 + $S5 + $S6:.2f}')"),
    "app_boot_sec": $S7,
    "core_usable_sec": $(python3 -c "print(f'{$S1 + $S2 + $S3 + $S4 + $S5 + $S6 + $S7 + $S8:.2f}')"),
    "total_sec": $TOTAL
  },
  "verification": {
    "files_restored": $RESTORED_N,
    "tables_imported": $TBL,
    "login_http": "$code",
    "core_functions_usable": "$USABLE"
  }
}
JSON
echo "결과: $OUT"
