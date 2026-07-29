#!/bin/bash
# DR 테스트 공통 상수 + 하드 가드. 모든 복구 스크립트가 이 파일을 source 한다.
#
# 가드의 목적은 하나다: "실수로 운영을 건드리는 경로"를 코드로 막는 것.
# 주석으로 "조심하자"고 적는 대신, 조건이 어긋나면 스크립트가 죽게 만든다.

set -euo pipefail

PROJECT_ROOT="/Users/deoksookim/Desktop/코드/claude code/eden_crm"

# ── 복구 테스트 대상 (운영과 절대 겹치지 않는 이름) ──────────────────────────
RESTORE_ROOT="/Users/deoksookim/Desktop/코드/claude code/eden_crm_restore_test"
RESTORE_DB="eden_crm_restore_test"
RESTORE_DB_USER="eden_restore_user"
RESTORE_DB_PASS="EdenRestore!test2026"     # 로컬 격리 인스턴스 전용 · 운영 무관
RESTORE_PORT=8091
RESTORE_PREFIX="edencrm_"                  # 운영과 동일 — 같은 코드 경로로 검증하기 위함

# ── 복구 대상 백업본 ────────────────────────────────────────────────────────
BACKUP_FILES="$PROJECT_ROOT/database/backups/ftp_20260729-103659"
BACKUP_SQL="$PROJECT_ROOT/database/backups/proddb_audit_pre_20260729-013710.sql"

# ── 로컬 격리 MySQL (프로젝트 내부 datadir, 포트 3307) ──────────────────────
MYSQL_BIN="/opt/homebrew/bin/mysql"
DEV_SOCK="$PROJECT_ROOT/.devdb/mysql.sock"
PHP_BIN="$(command -v php)"

# 복구 DB 접속 단축 — 항상 소켓 경유(로컬 인스턴스 외에는 닿을 수 없다)
rdb() { "$MYSQL_BIN" --socket="$DEV_SOCK" --default-character-set=utf8mb4 "$@"; }
rdb_root() { rdb -uroot "$@"; }
rdb_user() { rdb -u"$RESTORE_DB_USER" -p"$RESTORE_DB_PASS" "$@"; }

die() { echo "❌ 가드 위반: $*" >&2; exit 9; }

# ── 하드 가드 ───────────────────────────────────────────────────────────────
dr_guard() {
    # 1) 복구 DB 이름에 _restore_test 가 없으면 중단.
    #    이 한 줄이 "운영 DB명을 실수로 넣는" 사고를 막는다.
    [[ "$RESTORE_DB" == *_restore_test ]] || die "복구 DB명에 _restore_test 없음: $RESTORE_DB"

    # 2) 복구 경로가 프로젝트 루트와 같거나 그 하위면 중단(로컬 소스 오염 방지).
    [[ "$RESTORE_ROOT" == *_restore_test ]] || die "복구 경로에 _restore_test 없음: $RESTORE_ROOT"
    [[ "$RESTORE_ROOT" != "$PROJECT_ROOT" ]] || die "복구 경로가 프로젝트 루트와 동일"
    [[ "$RESTORE_ROOT" != "$PROJECT_ROOT"/* ]] || die "복구 경로가 프로젝트 하위: $RESTORE_ROOT"

    # 3) 운영 DB명과 충돌하면 중단. cafe24.env 에서 읽되 값은 출력하지 않는다.
    local prod_db
    prod_db="$(grep -E '^DB_NAME=' "$PROJECT_ROOT/deploy/cafe24.env" | head -1 | cut -d= -f2- | tr -d ' \r')"
    [[ -n "$prod_db" ]] || die "운영 DB명을 읽지 못함 — 충돌 검사 불가"
    [[ "$RESTORE_DB" != "$prod_db" ]] || die "복구 DB명이 운영 DB명과 동일"

    # 4) 접속은 반드시 로컬 소켓. 소켓 파일이 프로젝트 .devdb 안에 있어야 한다.
    [[ -S "$DEV_SOCK" ]] || die "로컬 격리 MySQL 소켓 없음: $DEV_SOCK (scripts/start_dev.sh 로 기동)"
    [[ "$DEV_SOCK" == "$PROJECT_ROOT/.devdb/"* ]] || die "소켓이 격리 인스턴스 밖: $DEV_SOCK"

    # 5) 그 소켓이 정말 격리 인스턴스인지 서버에 직접 물어본다(포트·datadir 확인).
    local port datadir
    port="$(rdb_root -N -B -e 'SELECT @@port' 2>/dev/null || true)"
    datadir="$(rdb_root -N -B -e 'SELECT @@datadir' 2>/dev/null || true)"
    [[ "$port" == "3307" ]] || die "격리 인스턴스 포트가 3307 이 아님: '$port'"
    [[ "$datadir" == "$PROJECT_ROOT/.devdb/data/" ]] || die "datadir 이 격리 경로가 아님: '$datadir'"

    # 6) 개발 DB(eden_crm)를 복구 대상으로 삼지 않는다 — 기존 개발 데이터 보호.
    [[ "$RESTORE_DB" != "eden_crm" ]] || die "복구 대상이 개발 DB(eden_crm)"

    echo "✅ 가드 통과 — 복구DB=$RESTORE_DB · 경로=$RESTORE_ROOT · 인스턴스=127.0.0.1:$port(격리)"
}
