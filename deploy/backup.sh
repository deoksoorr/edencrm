#!/bin/bash
# EDEN CRM 운영 파일 백업 — 원격 FTP 를 로컬로 mirror.
#
# 계약: **실패하면 반드시 비정상 종료한다.** 자동 스케줄(backup_auto.sh)이 이 계약에
# 의존한다. 이전 버전은 `mirror ... || echo` 와 `lftp -f "$S" || true` 로 실패를 삼켜서
# 인증 실패·경로 오류·연결 끊김에도 exit 0 과 "백업 위치: ..." 를 출력했다.
# 파일 0개짜리 빈 디렉터리가 정상 백업으로 남는 경로였다.
#
# 종료코드: 0=성공 · 2=사전조건 실패 · 3=전송 실패 · 4=검증 실패
#
# 사용:
#   bash deploy/backup.sh                  # 단독 실행
#   BACKUP_TS=20260805-093012 bash ...     # DB 덤프와 타임스탬프를 맞출 때(backup_auto.sh)
#   EDEN_ENV_FILE=/tmp/test.env bash ...   # 대체 환경파일(장애 주입 테스트용)
#
# DB 백업은 deploy/db_dump.php 담당이다. 이 스크립트는 파일만 다룬다.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# ── 사전조건 ────────────────────────────────────────────────────────────────
# 환경파일 경로를 주입 가능하게 둔다. 이전 버전은 cafe24.env 를 무조건 source 해서
# 환경변수 주입이 덮어써졌고, 그 결과 **장애 주입 테스트가 원천적으로 불가능**했다.
# (app/config/config.php 의 EDEN_CONFIG_LOCAL 과 같은 패턴)
ENV_FILE="${EDEN_ENV_FILE:-$SCRIPT_DIR/cafe24.env}"
[ -f "$ENV_FILE" ] || { echo "환경파일 없음: $ENV_FILE" >&2; exit 2; }
set -a; source "$ENV_FILE"; set +a

for v in FTP_HOST FTP_PORT FTP_USER FTP_PASSWORD FTP_REMOTE_PATH; do
    [ -n "${!v:-}" ] || { echo "필수 환경값 누락: $v" >&2; exit 2; }
done
command -v lftp >/dev/null || { echo "lftp 미설치" >&2; exit 2; }

BK="$PROJECT_DIR/database/backups"
LOGDIR="${EDEN_LOG_DIR:-$HOME/Library/Logs/eden_crm}"
mkdir -p "$BK" "$LOGDIR/lftp" || { echo "디렉터리 생성 실패" >&2; exit 2; }

# ── 원자적 게시 ─────────────────────────────────────────────────────────────
# 받는 동안에는 점(.) prefix 로 둔다 → ftp_* 글롭에 걸리지 않는다.
# 검증을 통과한 것만 ftp_<TS> 로 rename 한다. 그래서 "목록에 보이면 검증을 통과한 것"이
# 불변식이 된다 — 장애 상황에서 불완전한 백업을 골라잡는 사고를 구조적으로 막는다.
TS="${BACKUP_TS:-$(date +%Y%m%d-%H%M%S)}"
[[ "$TS" =~ ^[0-9]{8}-[0-9]{6}$ ]] || { echo "BACKUP_TS 형식 오류: $TS" >&2; exit 2; }
TMP="$BK/.incomplete_$TS"
DEST="$BK/ftp_$TS"
FAILED="$BK/failed_$TS"
[ -e "$DEST" ] && { echo "이미 존재: $DEST" >&2; exit 2; }
rm -rf "$TMP" "$FAILED"; mkdir -p "$TMP" || exit 2

LFTP_LOG="$LOGDIR/lftp/$TS.log"
S="$(mktemp)"; chmod 600 "$S"
trap 'rm -f "$S"' EXIT

# 실패 시 받다 만 산출물을 남긴다 — 어디까지 받아졌는지가 원인 분석의 유일한 증거다
# (인증실패 0개 vs 중간끊김 80개를 구분하려면 산출물이 필요하다).
# 다만 ftp_* 이름공간 밖(failed_*)에 둔다.
bail() {   # bail <종료코드> <사유>
    local rc="$1" reason="$2"
    if [ -d "$TMP" ]; then
        if mv "$TMP" "$FAILED" 2>/dev/null; then
            printf '%s\n%s\n' "$reason" "$(date '+%Y-%m-%dT%H:%M:%S%z')" > "$FAILED/FAILED.txt"
        fi
    fi
    echo "FAIL: $reason" >&2
    echo "RESULT rc=$rc dest=- files=0 code_files=0 upload_files=0 bytes=0 warn=- reason=$reason"
    exit "$rc"
}

# ── 전송 ────────────────────────────────────────────────────────────────────
# lftp 기본값은 net:max-retries 1000 / net:timeout 5m 이라 서버가 죽으면 사실상
# 영구 대기한다. deploy.sh 가 이미 낮춰 쓰는 값을 그대로 가져온다.
# cmd:fail-exit yes 가 있어야 mirror 실패가 lftp 종료코드로 전파된다.
cat > "$S" <<LFTP
set cmd:fail-exit yes
set net:max-retries 2
set net:timeout 25
set net:reconnect-interval-base 5
set ftp:ssl-allow no
set ftp:passive-mode true
set mirror:parallel-transfer-count 3
open -p $FTP_PORT -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
mirror --verbose $FTP_REMOTE_PATH/ "$TMP/"
bye
LFTP

# macOS 에 timeout(1) 이 없다 → 백그라운드 + 폴링으로 데드라인을 건다.
DEADLINE="${BACKUP_DEADLINE_SEC:-600}"
lftp -f "$S" >"$LFTP_LOG" 2>&1 &
LP=$!
RC=""
for _ in $(seq 1 "$DEADLINE"); do
    kill -0 "$LP" 2>/dev/null || break
    sleep 1
done
if kill -0 "$LP" 2>/dev/null; then
    kill "$LP" 2>/dev/null; sleep 2; kill -9 "$LP" 2>/dev/null
    RC=124
fi
if [ -z "$RC" ]; then
    wait "$LP"; RC=$?
else
    wait "$LP" 2>/dev/null || true
fi

[ "$RC" = "124" ] && bail 3 "전송 데드라인 ${DEADLINE}초 초과"
[ "$RC" -ne 0 ] && bail 3 "lftp 종료코드 $RC (로그 $LFTP_LOG)"

# rc 가 0 이어도 로그를 본다 — lftp 는 일부 상황에서 실패하고도 0 을 반환한다.
if grep -qiE '^Fatal error|^Login failed|mirror: .*(No such file|Access failed|Login failed)' "$LFTP_LOG"; then
    DETAIL=$(grep -iE -m1 '^Fatal error|^Login failed|mirror: .*(No such file|Access failed|Login failed)' "$LFTP_LOG" | cut -c1-90)
    bail 3 "lftp 로그 치명 오류: $DETAIL"
fi

# ── 결과 검증 ───────────────────────────────────────────────────────────────
# "백업이 만들어졌다"와 "쓸 수 있는 백업이 만들어졌다"는 다르다.
cnt_files() { find "$1" -type f 2>/dev/null | wc -l | tr -d ' '; }
cnt_code()  { find "$1" -type f -not -path "$1/storage/*" 2>/dev/null | wc -l | tr -d ' '; }
cnt_up()    { find "$1/storage/uploads" -type f 2>/dev/null | wc -l | tr -d ' '; }
sum_bytes() { find "$1" -type f -exec stat -f%z {} + 2>/dev/null | awk '{s+=$1} END {print s+0}'; }

FILES=$(cnt_files "$TMP"); CODE=$(cnt_code "$TMP"); UP=$(cnt_up "$TMP"); BYTES=$(sum_bytes "$TMP")

# V2 — 파일 0개. 이전 버전이 정상으로 보고하던 바로 그 상태다.
[ "$FILES" -eq 0 ] && bail 4 "파일 0개"

# V3 — 이 넷이 없으면 복구가 성립하지 않는다.
for p in ".htaccess" "public/index.php" "app/config/config.php" "app/routes.php"; do
    [ -e "$TMP/$p" ] || bail 4 "필수 경로 누락: $p"
done

# V4 — 0바이트 파일(절단 전송의 직접 증거)
ZERO=$(find "$TMP" -type f -size 0 2>/dev/null | wc -l | tr -d ' ')
[ "$ZERO" -gt 0 ] && bail 4 "0바이트 파일 ${ZERO}개"

# V5/V6/V7 — 직전 성공 백업과 비교. 없으면(최초 실행) 건너뛴다.
WARN="-"
add_warn() { [ "$WARN" = "-" ] && WARN="$1" || WARN="$WARN; $1"; }
PREV=$(ls -1td "$BK"/ftp_* 2>/dev/null | head -1)
if [ -n "${PREV:-}" ] && [ -d "$PREV" ]; then
    PCODE=$(cnt_code "$PREV"); PUP=$(cnt_up "$PREV"); PBYTES=$(sum_bytes "$PREV")
    # V5: 코드 파일 감소.
    #
    # 처음에는 "deploy.sh 가 원격 삭제를 하지 않으므로 코드 파일은 단조 증가한다"는
    # 전제로 어떤 감소든 FAIL 로 잡았다. 그런데 2026-07-29 에 deploy.sh 에 --delete 를
    # 넣으면서 그 전제가 깨졌다 — 로컬에서 지운 파일이 운영에서도 지워지므로 감소는
    # 정상 동작이 됐다(실제로 dashboard 뷰 4개 삭제 직후 이 규칙이 오탐을 냈다).
    #
    # 그래도 규칙 자체는 살려둔다. 목적은 "전송 누락으로 파일이 대량 유실된 백업"을
    # 잡는 것이고, 그건 여전히 유효한 위험이다. 그래서 판정 기준만 바꾼다:
    #   대량 감소(10% 초과 또는 15개 초과) = FAIL — 배포 삭제로 보기엔 과하다
    #   소량 감소                          = WARN — 의도한 삭제일 가능성이 높다
    DROP=$((PCODE - CODE))
    if [ "$DROP" -gt 0 ]; then
        LIMIT=$((PCODE / 10))
        [ "$LIMIT" -gt 15 ] && LIMIT=15
        if [ "$DROP" -gt "$LIMIT" ]; then
            bail 4 "코드 파일 대량 감소 $PCODE → $CODE (${DROP}개, 허용 ${LIMIT}개 · 직전 $(basename "$PREV"))"
        fi
        add_warn "코드파일 ${DROP}개 감소 ${PCODE}→${CODE}(배포 삭제 여부 확인)"
    fi
    # V6: uploads 는 휴지통 정리로 정상 감소한다 → FAIL 로 잡으면 오탐이 쌓여
    #     알림 자체를 무시하게 된다. 경고로만 남긴다.
    # 변수명은 반드시 ${} 로 감싼다. `$PUP→$UP` 처럼 멀티바이트 문자가 공백 없이 붙으면
    # bash 가 `PUP→` 를 변수명으로 파싱해 unbound variable 로 죽는다(실측 확인).
    [ "$UP" -lt "$PUP" ] && add_warn "uploads 감소 ${PUP}→${UP}"
    # V7: 총 바이트 20% 이상 감소 — 절단 전송의 약한 신호
    if [ "$PBYTES" -gt 0 ] && [ "$BYTES" -lt $((PBYTES * 80 / 100)) ]; then
        add_warn "bytes 20%↓ ${PBYTES}→${BYTES}"
    fi
fi

# ── 게시 ────────────────────────────────────────────────────────────────────
mv "$TMP" "$DEST" || bail 4 "게시(rename) 실패"
chmod 700 "$DEST" 2>/dev/null || true

echo "파일 백업 완료: $DEST"
echo "RESULT rc=0 dest=$DEST files=$FILES code_files=$CODE upload_files=$UP bytes=$BYTES warn=$WARN"
exit 0
