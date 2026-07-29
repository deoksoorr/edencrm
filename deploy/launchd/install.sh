#!/bin/bash
# EDEN CRM 백업 자동화 launchd 설치.
#
# plist 를 저장소에 고정된 파일로 두지 않고 여기서 생성한다. 프로젝트 경로·node 경로가
# 기기마다 다르고, 하드코딩된 plist 는 경로가 바뀌는 순간 조용히 동작을 멈추기 때문이다.
#
# **plist 에 비밀값을 넣지 않는다.** cafe24.env 는 스크립트가 직접 읽는다.
#
# 사용:
#   bash deploy/launchd/install.sh            # 설치(또는 갱신)
#   bash deploy/launchd/install.sh --uninstall
#   bash deploy/launchd/install.sh --status

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PROJECT_DIR="$(cd "$DEPLOY_DIR/.." && pwd)"
AGENTS="$HOME/Library/LaunchAgents"
LOGDIR="$HOME/Library/Logs/eden_crm"
UID_NUM="$(id -u)"

BACKUP_LABEL="com.edencrm.backup"
VERIFY_LABEL="com.edencrm.backup-verify"
BACKUP_PLIST="$AGENTS/$BACKUP_LABEL.plist"
VERIFY_PLIST="$AGENTS/$VERIFY_LABEL.plist"

# node 경로는 nvm 업그레이드로 깨지기 쉬우므로 설치 시점에 탐색해 고정한다.
find_node() {
    command -v node 2>/dev/null && return 0
    for p in "$HOME"/.nvm/versions/node/*/bin/node /opt/homebrew/bin/node /usr/local/bin/node; do
        [ -x "$p" ] && { echo "$p"; return 0; }
    done
    return 1
}
NODE_BIN="$(find_node || true)"
NODE_DIR="${NODE_BIN:+$(dirname "$NODE_BIN")}"
PATH_VALUE="/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin${NODE_DIR:+:$NODE_DIR}"

usage() { echo "사용: install.sh [--uninstall|--status|--check-tcc]"; exit 2; }

# ── TCC(전체 디스크 접근) 검사 ──────────────────────────────────────────────
# 이 프로젝트는 ~/Desktop 아래에 있는데, macOS 는 launchd 로 뜬 프로세스의
# Desktop 접근을 기본 차단한다(TCC). 차단되면 백업 잡이 아예 시작조차 못 하고
# "Operation not permitted" 만 남긴다 — 자동화가 통째로 무력화되는 지점이다.
#
# "설정했으니 되겠지"로 넘기면 안 된다. 실제 launchd 컨텍스트에서 프로젝트를
# 읽어 보는 일회성 잡을 띄워 확인한다.
check_tcc() {
    local probe_label="com.edencrm.tccprobe"
    local probe_sh="$LOGDIR/.tcc_probe.sh"
    local probe_out="$LOGDIR/.tcc_probe.out"
    local probe_plist="$AGENTS/$probe_label.plist"

    cat > "$probe_sh" <<PROBE
#!/bin/bash
if head -1 "$PROJECT_DIR/.gitignore" >/dev/null 2>&1; then echo OK > "$probe_out"; else echo BLOCKED > "$probe_out"; fi
PROBE
    chmod +x "$probe_sh"
    rm -f "$probe_out"
    cat > "$probe_plist" <<PROBEPLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>$probe_label</string>
  <key>ProgramArguments</key><array><string>/bin/bash</string><string>$probe_sh</string></array>
  <key>RunAtLoad</key><true/>
</dict></plist>
PROBEPLIST
    launchctl bootout "gui/$UID_NUM/$probe_label" 2>/dev/null || true
    launchctl bootstrap "gui/$UID_NUM" "$probe_plist" 2>/dev/null || true
    for _ in $(seq 1 20); do [ -f "$probe_out" ] && break; sleep 0.5; done
    local r; r="$(cat "$probe_out" 2>/dev/null || echo BLOCKED)"
    launchctl bootout "gui/$UID_NUM/$probe_label" 2>/dev/null || true
    rm -f "$probe_plist" "$probe_sh" "$probe_out"
    [ "$r" = "OK" ]
}

tcc_instructions() {
    cat <<'MSG'

  ⚠️  전체 디스크 접근 권한이 필요합니다 — 지금 상태로는 자동 백업이 실행되지 않습니다.

  이 프로젝트가 ~/Desktop 아래에 있어서, macOS 가 launchd 로 실행되는 프로세스의
  접근을 차단합니다(TCC). 아래를 한 번만 설정하면 됩니다.

    1. 시스템 설정 → 개인정보 보호 및 보안 → 전체 디스크 접근 권한
    2. 좌하단 '+' 클릭
    3. Command+Shift+G 를 눌러 경로 입력창을 열고  /bin/bash  입력 후 이동
    4. bash 를 선택해 추가하고, 목록에서 스위치를 켠 상태로 둡니다
    5. 설정 후 아래로 확인:

       bash deploy/launchd/install.sh --check-tcc

  참고: /bin/bash 에 부여하는 권한이라 앞으로 실행되는 모든 bash 스크립트가
  전체 디스크를 읽을 수 있게 됩니다. 범위가 넓다는 점은 인지해 두시는 게 좋습니다.
MSG
}

status() {
    echo "═══ launchd 상태 ═══"
    for L in "$BACKUP_LABEL" "$VERIFY_LABEL"; do
        if launchctl print "gui/$UID_NUM/$L" >/dev/null 2>&1; then
            printf "  %-28s 등록됨\n" "$L"
            launchctl print "gui/$UID_NUM/$L" 2>/dev/null \
                | grep -E 'state|last exit code|runs' | sed 's/^/      /'
        else
            printf "  %-28s 미등록\n" "$L"
        fi
    done
    echo
    echo "  최근 백업 로그:"
    tail -5 "$LOGDIR/backup.log" 2>/dev/null | sed 's/^/    /' || echo "    (없음)"
}

uninstall() {
    for L in "$BACKUP_LABEL" "$VERIFY_LABEL"; do
        launchctl bootout "gui/$UID_NUM/$L" 2>/dev/null && echo "  해제: $L"
    done
    rm -f "$BACKUP_PLIST" "$VERIFY_PLIST"
    echo "  plist 제거 완료 (백업 파일·로그는 그대로 둔다)"
}

case "${1:-}" in
    --status) status; exit 0 ;;
    --uninstall) uninstall; exit 0 ;;
    --check-tcc)
        if check_tcc; then
            echo "✅ 전체 디스크 접근 권한 확인 — launchd 에서 프로젝트를 읽을 수 있습니다."
            echo "   이제 자동 백업이 정상 동작합니다. 즉시 1회 실행:"
            echo "     launchctl kickstart -p gui/$UID_NUM/$BACKUP_LABEL"
            exit 0
        else
            echo "❌ 아직 차단 상태입니다 — launchd 에서 프로젝트를 읽지 못합니다."
            tcc_instructions
            exit 1
        fi ;;
    "") ;;
    *) usage ;;
esac

mkdir -p "$AGENTS" "$LOGDIR"

# ── 백업 잡 ─────────────────────────────────────────────────────────────────
# StartCalendarInterval 에 Minute 만 지정 = 매시 정각(시는 와일드카드).
#
# StartInterval 3600 을 쓰지 않는 이유가 중요하다. man launchd.plist 는
# StartInterval 에 대해 "that interval will be missed due to shortcomings in
# kqueue(3)" 라고 명시한다 — 잠자기 중 놓친 실행은 사라진다. 반면
# StartCalendarInterval 은 wake 시 놓친 실행을 합쳐 1회 실행한다.
# 노트북에서는 이 차이가 곧 "백업이 되느냐 마느냐"다.
#
# 매시 호출이지만 실제 백업은 MIN_INTERVAL_MIN(20시간) 가드로 하루 1회다.
cat > "$BACKUP_PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$BACKUP_LABEL</string>
  <key>ProgramArguments</key>
  <array>
    <string>/usr/bin/caffeinate</string>
    <string>-i</string>
    <string>/bin/bash</string>
    <string>$DEPLOY_DIR/backup_auto.sh</string>
  </array>
  <key>WorkingDirectory</key><string>$PROJECT_DIR</string>
  <key>StartCalendarInterval</key>
  <array>
    <dict><key>Minute</key><integer>0</integer></dict>
  </array>
  <key>RunAtLoad</key><true/>
  <key>EnvironmentVariables</key>
  <dict>
    <key>PATH</key><string>$PATH_VALUE</string>
    <key>MIN_INTERVAL_MIN</key><string>1200</string>
    <key>STALE_WARN_H</key><string>26</string>
  </dict>
  <key>StandardOutPath</key><string>$LOGDIR/launchd.backup.out.log</string>
  <key>StandardErrorPath</key><string>$LOGDIR/launchd.backup.err.log</string>
  <key>Nice</key><integer>5</integer>
  <key>LowPriorityIO</key><true/>
  <key>ProcessType</key><string>Background</string>
  <key>ThrottleInterval</key><integer>60</integer>
</dict>
</plist>
PLIST

# ── 주간 복구 검증 잡 ───────────────────────────────────────────────────────
# 일요일 11:00. 운영 DB 를 읽지 않고 로컬 백업만 격리 인스턴스에 import 하므로
# 업무 영향은 0 이지만 로컬 자원을 쓰므로 업무시간을 피한다.
cat > "$VERIFY_PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$VERIFY_LABEL</string>
  <key>ProgramArguments</key>
  <array>
    <string>/bin/bash</string>
    <string>$PROJECT_DIR/scripts/dr/verify_restore_auto.sh</string>
  </array>
  <key>WorkingDirectory</key><string>$PROJECT_DIR</string>
  <key>StartCalendarInterval</key>
  <array>
    <dict><key>Weekday</key><integer>0</integer><key>Hour</key><integer>11</integer><key>Minute</key><integer>0</integer></dict>
  </array>
  <key>RunAtLoad</key><false/>
  <key>EnvironmentVariables</key>
  <dict>
    <key>PATH</key><string>$PATH_VALUE</string>
  </dict>
  <key>StandardOutPath</key><string>$LOGDIR/launchd.verify.out.log</string>
  <key>StandardErrorPath</key><string>$LOGDIR/launchd.verify.err.log</string>
  <key>Nice</key><integer>10</integer>
  <key>LowPriorityIO</key><true/>
  <key>ProcessType</key><string>Background</string>
</dict>
</plist>
PLIST

# ── 등록 ────────────────────────────────────────────────────────────────────
for P in "$BACKUP_PLIST" "$VERIFY_PLIST"; do
    plutil -lint "$P" >/dev/null || { echo "plist 형식 오류: $P" >&2; exit 1; }
done

for L in "$BACKUP_LABEL" "$VERIFY_LABEL"; do
    launchctl bootout "gui/$UID_NUM/$L" 2>/dev/null || true
done
launchctl bootstrap "gui/$UID_NUM" "$BACKUP_PLIST" || { echo "등록 실패: $BACKUP_LABEL" >&2; exit 1; }
if [ -f "$PROJECT_DIR/scripts/dr/verify_restore_auto.sh" ]; then
    launchctl bootstrap "gui/$UID_NUM" "$VERIFY_PLIST" || echo "경고: $VERIFY_LABEL 등록 실패" >&2
else
    echo "  (verify_restore_auto.sh 없음 — 검증 잡은 건너뜀)"
    rm -f "$VERIFY_PLIST"
fi

echo "설치 완료"
echo "  백업     : 매시 정각 호출 · 실제 백업은 20시간 가드로 하루 1회"
echo "  복구검증 : 일요일 11:00 · 최신 auto 덤프를 실제 import"
echo "  node     : ${NODE_BIN:-(못 찾음 — 텔레그램 알림은 osascript 로 폴백)}"
echo "  로그     : $LOGDIR/backup.log"
echo

# 설치가 끝났다고 동작하는 게 아니다. 실제 launchd 컨텍스트에서 읽히는지 확인한다.
echo "═══ 전체 디스크 접근 권한 확인 ═══"
if check_tcc; then
    echo "  ✅ launchd 에서 프로젝트 읽기 가능 — 자동 백업이 동작합니다."
else
    echo "  ❌ launchd 에서 프로젝트 읽기 차단됨"
    tcc_instructions
    # 조용히 방치되지 않도록 알림도 보낸다.
    bash "$DEPLOY_DIR/notify.sh" --code TCC_BLOCKED \
        "⚠️ EDEN CRM 자동 백업이 아직 동작하지 않습니다 — /bin/bash 에 전체 디스크 접근 권한이 필요합니다(시스템 설정 → 개인정보 보호 및 보안). 설정 후 'install.sh --check-tcc' 로 확인하세요." >/dev/null 2>&1 || true
fi
echo
status
