#!/bin/bash
# EDEN CRM 로컬 개발환경 기동 — 격리 MySQL(:3307) + PHP 서버(:8080).
# 운영에서는 이 스크립트 대신 실제 호스팅 MySQL + 웹서버를 사용한다.
set -uo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
SOCK="$ROOT/.devdb/mysql.sock"
MYSQLD="/opt/homebrew/bin/mysqld"
MYSQL="/opt/homebrew/bin/mysql"
PORT_WEB=8080

# 1) 격리 MySQL 인스턴스 기동(미기동 시)
if [ ! -d "$ROOT/.devdb/data" ]; then
  echo "[DB] 데이터디렉토리 초기화..."
  "$MYSQLD" --initialize-insecure --datadir="$ROOT/.devdb/data" --basedir=/opt/homebrew
fi
if ! "$MYSQL" --socket="$SOCK" -uroot -e "SELECT 1" >/dev/null 2>&1; then
  echo "[DB] MySQL 기동(:3307)..."
  "$MYSQLD" --datadir="$ROOT/.devdb/data" --port=3307 --socket="$SOCK" \
    --pid-file="$ROOT/.devdb/mysqld.pid" --mysqlx=0 --log-error="$ROOT/.devdb/error.log" &
  for i in $(seq 1 30); do "$MYSQL" --socket="$SOCK" -uroot -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
fi

# 2) DB/계정/스키마/시드 (없을 때만)
if ! "$MYSQL" --socket="$SOCK" -uroot -e "USE eden_crm" >/dev/null 2>&1; then
  echo "[DB] eden_crm 생성 + 스키마·시드 적재..."
  "$MYSQL" --socket="$SOCK" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS eden_crm DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'eden_crm_user'@'localhost' IDENTIFIED BY 'EdenCrm!local2026';
GRANT ALL PRIVILEGES ON eden_crm.* TO 'eden_crm_user'@'localhost';
FLUSH PRIVILEGES;
SQL
  "$MYSQL" --socket="$SOCK" -ueden_crm_user -p'EdenCrm!local2026' eden_crm < database/schema.sql
  "$MYSQL" --socket="$SOCK" -ueden_crm_user -p'EdenCrm!local2026' eden_crm < database/seed_core.sql
  "$MYSQL" --socket="$SOCK" -ueden_crm_user -p'EdenCrm!local2026' eden_crm < database/seed_dev.sql
fi

# 3) PHP 서버 기동
pkill -f "php -S 127.0.0.1:$PORT_WEB" 2>/dev/null; sleep 1
echo "[WEB] PHP 서버 기동: http://127.0.0.1:$PORT_WEB/index.php?r=login"
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:$PORT_WEB -t public
