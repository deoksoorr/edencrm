#!/bin/bash
# EDEN CRM 백업 알림 — 단일 창구.
#
# 조용한 실패가 이 시스템의 가장 큰 위험이다. 백업 14개가 전부 복구 불가였는데
# 3개월간 아무도 몰랐던 게 그 증거다. 그래서 알림 경로를 3단으로 둔다.
#
#   1차  텔레그램 (telegram-control/telegram_notify.js 재사용)
#   2차  실패 시 macOS 알림센터 (osascript)
#   3차  항상 backup.log 에 기록 (둘 다 실패해도 흔적은 남는다)
#
# 텔레그램을 새로 만들지 않고 재사용하는 이유는 편의가 아니다. 그쪽
# lib/telegram.js 의 maskSensitive() 가 발송 직전 토큰·PASSWORD=·mysql -p…·DSN 을
# 자동으로 가린다. cafe24.env 값이 실수로 메시지에 섞여도 나가기 전에 마스킹된다 —
# 직접 구현으로는 얻을 수 없는 방어선이다.
#
# 사용:
#   bash deploy/notify.sh "메시지"                    # 억제 없이 즉시 발송
#   bash deploy/notify.sh --code FTP_FAIL "메시지"     # 원인코드별 억제 적용
#   bash deploy/notify.sh --code X --ttl 3600 "메시지" # 억제 간격 지정(초)

set -uo pipefail

TOOL_DIR="/Users/deoksookim/Desktop/코드/claude code/telegram-control"
LOGDIR="${EDEN_LOG_DIR:-$HOME/Library/Logs/eden_crm}"
STAMPDIR="${EDEN_STAMP_DIR:-$HOME/Library/Caches/eden_crm/notify}"
LOG="$LOGDIR/backup.log"
mkdir -p "$LOGDIR" "$STAMPDIR" 2>/dev/null

CODE=""
TTL=""
while [ $# -gt 0 ]; do
    case "$1" in
        --code) CODE="${2:-}"; shift 2 ;;
        --ttl)  TTL="${2:-}";  shift 2 ;;
        *) break ;;
    esac
done
MSG="$*"
[ -n "$MSG" ] || { echo "사용법: notify.sh [--code CODE] [--ttl SEC] <메시지>" >&2; exit 2; }

ts() { date '+%Y-%m-%dT%H:%M:%S%z'; }
log_line() { printf '%s %-7s %s\n' "$(ts)" "$1" "$2" >> "$LOG"; }

# ── 억제 ────────────────────────────────────────────────────────────────────
# 같은 원인으로 반복 알림이 오면 사람은 곧 모든 백업 알림을 무시하게 된다.
# 그러면 진짜 실패도 함께 묻힌다. 원인코드별로 최소 간격을 둔다.
if [ -n "$CODE" ]; then
    case "$CODE" in
        STALE*)     DEF_TTL=86400 ;;   # 신선도 경고는 하루 1회
        RECOVERED)  DEF_TTL=0 ;;       # 복구 알림은 드물고 중요 → 억제 안 함
        *)          DEF_TTL=21600 ;;   # 그 외 실패는 6시간
    esac
    TTL="${TTL:-$DEF_TTL}"
    STAMP="$STAMPDIR/$(echo "$CODE" | tr -c 'A-Za-z0-9_' '_').stamp"
    if [ "$TTL" -gt 0 ] && [ -f "$STAMP" ]; then
        LAST=$(stat -f %m "$STAMP" 2>/dev/null || echo 0)
        NOW=$(date +%s)
        if [ $((NOW - LAST)) -lt "$TTL" ]; then
            log_line "SUPPRESS" "code=$CODE (마지막 발송 $(( (NOW - LAST) / 60 ))분 전, 간격 $((TTL / 60))분) msg=${MSG:0:80}"
            exit 0
        fi
    fi
fi

# ── 1차: 텔레그램 ───────────────────────────────────────────────────────────
# node 경로는 nvm 절대경로가 깨질 수 있으므로 순차 탐색한다.
find_node() {
    command -v node 2>/dev/null && return 0
    for p in "$HOME"/.nvm/versions/node/*/bin/node /opt/homebrew/bin/node /usr/local/bin/node; do
        [ -x "$p" ] && { echo "$p"; return 0; }
    done
    return 1
}

SENT=0
NODE_BIN="$(find_node || true)"
if [ -n "$NODE_BIN" ] && [ -f "$TOOL_DIR/telegram_notify.js" ]; then
    if "$NODE_BIN" "$TOOL_DIR/telegram_notify.js" "$MSG" >/dev/null 2>&1; then
        SENT=1
    fi
else
    log_line "ALERT" "NOTIFY_NODE_MISSING — 텔레그램 발송 불가(node 또는 telegram_notify.js 없음)"
fi

# ── 2차: macOS 알림센터 ─────────────────────────────────────────────────────
if [ "$SENT" -eq 0 ] && command -v osascript >/dev/null 2>&1; then
    SAFE=$(printf '%s' "${MSG:0:200}" | tr '"' "'" | tr '\n' ' ')
    osascript -e "display notification \"$SAFE\" with title \"EDEN CRM 백업\"" >/dev/null 2>&1 && SENT=2
fi

# ── 3차: 로그 (항상) ────────────────────────────────────────────────────────
case "$SENT" in
    1) CH="telegram" ;;
    2) CH="osascript" ;;
    *) CH="log-only" ;;
esac
log_line "NOTIFY" "via=$CH code=${CODE:--} $MSG"

# 발송에 성공한 경우에만 억제 스탬프를 갱신한다.
# 실패했는데 스탬프를 찍으면 다음 알림까지 억제되어 침묵이 길어진다.
[ -n "$CODE" ] && [ "$SENT" -ne 0 ] && touch "$STAMP" 2>/dev/null

[ "$SENT" -eq 0 ] && exit 1
exit 0
