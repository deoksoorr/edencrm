#!/bin/bash
# DR 테스트 T4 — 파일 백업 실제 복원 + 검증.
#
# "압축 목록만 보고 OK" 는 복구 테스트가 아니다. 실제로 실행 가능한 구조로 펼치고,
# 원본 백업과 바이트 단위로 대조하고, 운영 접속정보가 섞여 들어왔는지까지 확인한다.
#
# 사용: bash scripts/dr/restore_files.sh

source "$(dirname "${BASH_SOURCE[0]}")/dr_env.sh"
dr_guard

EV="$RESTORE_ROOT/_dr/evidence"
mkdir -p "$EV"

echo "── T4 파일 복원 ──"
[[ -d "$BACKUP_FILES" ]] || die "파일 백업 없음: $BACKUP_FILES"

# ── 1. 복원 실행 (백업은 읽기 전용으로만 접근) ──────────────────────────────
# rsync 로 백업 → 복구 경로. _dr/ 은 우리가 만든 부속물이라 절대 지우지 않는다.
T_START=$(date +%s.%N)
rsync -a --exclude '_dr/' "$BACKUP_FILES/" "$RESTORE_ROOT/"
T_END=$(date +%s.%N)
RESTORE_SEC=$(echo "$T_END - $T_START" | bc)
printf "  복원 소요: %.2f초\n" "$RESTORE_SEC"

# ── 2. 파일 수·크기 대조 ────────────────────────────────────────────────────
SRC_FILES=$(find "$BACKUP_FILES" -type f | wc -l | tr -d ' ')
DST_FILES=$(find "$RESTORE_ROOT" -type f -not -path "$RESTORE_ROOT/_dr/*" | wc -l | tr -d ' ')
SRC_BYTES=$(find "$BACKUP_FILES" -type f -exec stat -f%z {} + | awk '{s+=$1} END {print s+0}')
DST_BYTES=$(find "$RESTORE_ROOT" -type f -not -path "$RESTORE_ROOT/_dr/*" -exec stat -f%z {} + | awk '{s+=$1} END {print s+0}')
echo "  파일 수  : 백업 $SRC_FILES → 복구 $DST_FILES"
echo "  총 바이트: 백업 $SRC_BYTES → 복구 $DST_BYTES"

# ── 3. 체크섬 전수 비교 ─────────────────────────────────────────────────────
# 개수·크기가 같아도 내용이 다를 수 있다. 전 파일 SHA-256 을 뜬다(134개면 즉시 끝난다).
( cd "$BACKUP_FILES" && find . -type f -print0 | sort -z | xargs -0 shasum -a 256 ) > "$EV/checksums_backup.txt"
( cd "$RESTORE_ROOT" && find . -type f -not -path './_dr/*' -print0 | sort -z | xargs -0 shasum -a 256 ) > "$EV/checksums_restored.txt"
if diff -q "$EV/checksums_backup.txt" "$EV/checksums_restored.txt" >/dev/null; then
    echo "  체크섬  : 전 파일 일치 ($(wc -l < "$EV/checksums_backup.txt" | tr -d ' ')개 SHA-256)"
    CHECKSUM_OK=1
else
    echo "  체크섬  : ❌ 불일치 — $EV/checksums_diff.txt 참조"
    diff "$EV/checksums_backup.txt" "$EV/checksums_restored.txt" > "$EV/checksums_diff.txt" || true
    CHECKSUM_OK=0
fi

# ── 4. 애플리케이션 필수 구조 검증 ──────────────────────────────────────────
# 파일이 다 있어도 "실행 가능한 구조"가 아니면 복구가 아니다.
echo "  필수 구조:"
MISSING=0
for p in \
    "public/index.php" "app/bootstrap.php" "app/routes.php" \
    "app/config/config.php" "app/core/Db.php" "app/core/Auth.php" \
    "app/core/Perm.php" "app/core/Rbac.php" "app/core/View.php" \
    "public/assets" "app/controllers" "app/views" "storage/uploads" ".htaccess"
do
    if [[ -e "$RESTORE_ROOT/$p" ]]; then printf "    ✅ %s\n" "$p"
    else printf "    ❌ %s (누락)\n" "$p"; MISSING=$((MISSING+1)); fi
done

# 디렉터리별 파일 수
echo "  영역별 파일 수:"
for d in app/controllers app/core app/views app/config public/assets storage/uploads; do
    n=$(find "$RESTORE_ROOT/$d" -type f 2>/dev/null | wc -l | tr -d ' ')
    printf "    %-20s %s\n" "$d" "$n"
done

# ── 5. 운영 접속정보 격리 ───────────────────────────────────────────────────
# 백업에 운영 config.local.php 가 들어 있으면 복구본이 운영 DB 를 물 수 있다.
# 파일을 지우지 않고 격리 보관한다 — 백업에 무엇이 있었는지는 증거로 남겨야 한다.
echo "  운영 설정 격리:"
QUARANTINE="$RESTORE_ROOT/_dr/quarantine"
mkdir -p "$QUARANTINE"
FOUND_PROD_CONF=0
for f in "app/config/config.local.php" "app/config/config.production.php" "deploy/cafe24.env"; do
    if [[ -f "$RESTORE_ROOT/$f" ]]; then
        FOUND_PROD_CONF=$((FOUND_PROD_CONF+1))
        mv "$RESTORE_ROOT/$f" "$QUARANTINE/$(basename "$f")"
        chmod 600 "$QUARANTINE/$(basename "$f")"
        echo "    ⚠ $f → _dr/quarantine/ 로 격리 (복구본이 운영 DB 를 물지 못하게)"
    fi
done
[[ $FOUND_PROD_CONF -eq 0 ]] && echo "    (백업에 운영 접속정보 파일 없음)"

# ── 6. 권한 확인 ────────────────────────────────────────────────────────────
echo "  권한 상태:"
printf "    %-22s %s\n" "storage/uploads" "$(stat -f '%Sp' "$RESTORE_ROOT/storage/uploads" 2>/dev/null || echo '없음')"
printf "    %-22s %s\n" "storage/logs" "$(stat -f '%Sp' "$RESTORE_ROOT/storage/logs" 2>/dev/null || echo '없음')"
printf "    %-22s %s\n" "public/index.php" "$(stat -f '%Sp' "$RESTORE_ROOT/public/index.php" 2>/dev/null || echo '없음')"
# 쓰기 가능해야 하는 디렉터리
for d in storage/uploads storage/logs; do
    if [[ -w "$RESTORE_ROOT/$d" ]]; then echo "    ✅ $d 쓰기 가능"
    else echo "    ❌ $d 쓰기 불가 — 업로드·로그 실패함"; fi
done

# 심볼릭 링크
SYMLINKS=$(find "$RESTORE_ROOT" -type l -not -path "$RESTORE_ROOT/_dr/*" | wc -l | tr -d ' ')
echo "  심볼릭 링크: $SYMLINKS 개"

# ── 7. 업로드 파일 목록(경로만, 내용 미노출) ────────────────────────────────
find "$RESTORE_ROOT/storage/uploads" -type f -exec shasum -a 256 {} + > "$EV/uploads_restored.txt" 2>/dev/null || true
UPLOAD_N=$(wc -l < "$EV/uploads_restored.txt" 2>/dev/null | tr -d ' ' || echo 0)
echo "  업로드 파일: $UPLOAD_N 개 (체크섬 $EV/uploads_restored.txt)"

# ── 8. 결과 JSON ────────────────────────────────────────────────────────────
cat > "$EV/t4_file_restore.json" <<JSON
{
  "task": "T4",
  "restored_at": "$(date '+%Y-%m-%dT%H:%M:%S%z')",
  "restore_seconds": $RESTORE_SEC,
  "backup_source": "$(basename "$BACKUP_FILES")",
  "files_backup": $SRC_FILES,
  "files_restored": $DST_FILES,
  "bytes_backup": $SRC_BYTES,
  "bytes_restored": $DST_BYTES,
  "checksum_all_match": $CHECKSUM_OK,
  "missing_required": $MISSING,
  "symlinks": $SYMLINKS,
  "uploads_restored": $UPLOAD_N,
  "prod_config_quarantined": $FOUND_PROD_CONF
}
JSON
echo "── T4 결과: $EV/t4_file_restore.json ──"
