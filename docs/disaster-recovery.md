# EDEN CRM 재해복구(DR) 절차서

**최종 검증**: 2026-07-29 · 격리 환경에서 전 과정 실제 수행 후 작성
**실측 복구시간**: 2.78초 (로컬 격리 기준 · [측정 내역](#9-실측-복구시간))
**검증 결과**: [DR 테스트 보고서](audit/dr/DR_TEST_REPORT.md)

이 문서는 "이렇게 하면 될 것이다"가 아니라 **실제로 실행해서 통과한 절차**만 담는다.
각 명령은 2026-07-29 복구 테스트에서 그대로 실행되어 검증됐다.

---

## ⚠️ 시작 전 반드시 읽을 것

### 경고 1 — 덤프에 `DROP TABLE IF EXISTS` 46개가 들어 있다

`database/backups/proddb_*.sql` 은 각 테이블마다 `DROP TABLE IF EXISTS` 로 시작한다.
**이 파일을 운영 DB 에 실행하면 eden 테이블 46개가 즉시 삭제된다.**
복구 시도가 2차 재해가 되는 경로이므로, import 대상 DB 이름을 두 번 확인한다.

```bash
# 반드시 이렇게: 비어 있는 전용 스키마에만
mysql --default-character-set=utf8mb4 -u <user> -p <복구전용DB> < dump.sql
#                                                  ↑ 운영 DB 이름이 아닌지 확인
```

### 경고 2 — 2026-07-29 이전 백업은 그대로 복구되지 않는다 (라벨 `auto` 는 해당 없음)

`deploy/db_dump.php` 가 생성 컬럼(`edencrm_project_assignments.active_pair`)에 값을
INSERT 하는 결함이 있었다. 그 시점 이전에 만들어진 백업 14개는 전부 import 중
**ERROR 3105 로 23/46 테이블에서 멈춘다**. 해당 백업을 써야 한다면 §4 의 복구변환을
먼저 거쳐야 한다. 2026-07-29 수정 이후 백업은 변환 없이 바로 import 된다.

### 경고 3 — `--default-character-set=utf8mb4` 를 빠뜨리면 한글이 깨진다

덤프에 `SET NAMES` 선언이 없던 시기가 있다(2026-07-29 수정으로 추가됨).
옵션 없이 import 하면 오류 없이 **성공한 것처럼 보이면서** 한글이 전부 깨진다.
복구 후 §7 의 한글 검증을 반드시 수행한다.

### 경고 4 — `--force` 를 쓰지 않는다

오류를 건너뛰면 절반만 복원된 DB 가 "성공"으로 남는다. 실제로 이 결함을 발견했을 때
`--force` 를 썼다면 계정 테이블이 통째로 빠진 채 복구 완료로 오판했을 것이다.

---

## 1. 백업 위치

| 종류 | 경로 | 생성 도구 | 주기 |
|---|---|---|---|
| 파일 | `database/backups/ftp_<YYYYMMDD-HHMMSS>/` | `deploy/backup.sh` (lftp mirror) | 자동 — 하루 1회 (수동 릴리스 백업 병행) |
| DB | `database/backups/proddb_<label>_<YYYYMMDD-HHMMSS>.sql` | `deploy/db_dump.php` | 자동 — 하루 1회 (수동 릴리스 백업 병행) |
| 매니페스트 | `database/backups/manifests/run_<TS>.json` | `deploy/backup_auto.sh` | 자동 백업마다 |

### 자동 백업 (2026-07-29 도입)

`deploy/backup_auto.sh` 가 파일·DB 백업을 **한 묶음으로** 실행한다.
launchd(`com.edencrm.backup`)가 매시 정각 호출하지만, 신선도 가드(`MIN_INTERVAL_MIN`,
기본 20시간) 때문에 실제 백업은 하루 1회다. 라벨은 `auto`.

```bash
bash deploy/launchd/install.sh            # 설치 · 상태 확인 포함
bash deploy/launchd/install.sh --status   # 등록 상태 + 최근 로그
bash deploy/backup_auto.sh --once         # 가드 무시하고 즉시 1회
tail -20 ~/Library/Logs/eden_crm/backup.log
```

**RPO 조절 손잡이는 `MIN_INTERVAL_MIN` 하나뿐이다.** 8시간으로 낮추려면
`~/Library/LaunchAgents/com.edencrm.backup.plist` 의 값을 `420` 으로 바꾸면 된다
(저장은 약 3배).

> **⚠️ 전체 디스크 접근 권한이 필요하다.** 이 프로젝트가 `~/Desktop` 아래에 있어
> macOS 가 launchd 프로세스의 접근을 차단한다(TCC). 시스템 설정 → 개인정보 보호 및
> 보안 → 전체 디스크 접근 권한에 `/bin/bash` 를 추가해야 자동 백업이 돈다.
> 확인: `bash deploy/launchd/install.sh --check-tcc`

### 짝 맞추기

**자동 백업(`auto` 라벨)은 파일명 타임스탬프가 완전히 일치한다.** `backup_auto.sh` 가
하나의 `RUN_TS` 를 양쪽에 주입하기 때문이다. 짝을 고르는 판단 자체가 필요 없다.

```bash
ls -t database/backups/proddb_auto_*.sql | head -1   # → proddb_auto_20260805-093012.sql
ls -td database/backups/ftp_*            | head -1   # → ftp_20260805-093012
jq . database/backups/manifests/run_20260805-093012.json   # 두 백업의 실제 소요·건수
```

**수동 백업(릴리스 직전 `*pre*`·`final` 등)** 은 두 도구를 따로 돌리므로 시각이 다를 수
있다. 파일명 시각으로 가장 가까운 쌍을 고르되, 덤프 첫 줄 주석의 ISO 시각을 신뢰한다.

> 2026-07-29 이전 백업은 두 도구의 타임존이 달라(셸 KST vs PHP UTC) **파일명이 최대
> 9시간, 날짜까지 어긋난다**. 예: `proddb_r16_pre_20260728-232641.sql` 의 실제 생성은
> 2026-07-29 08:26. 파일명만 보고 짝을 고르면 안 된다. (수정 이후 백업은 둘 다 KST)

### 보존 정책

`deploy/backup_prune.sh` 가 자동 백업 실행 시 함께 돈다. **`auto` 라벨만 삭제 대상**이며
릴리스 직전 수동 백업은 라벨과 무관하게 전부 보호된다.

| 구간 | 보존 |
|---|---|
| 최근 14일 | 전부 |
| 15~90일 | 주 1쌍 |
| 91일~12개월 | 월 1쌍 |
| 12개월 초과 | 삭제 |

최신 3쌍은 규칙과 무관하게 무조건 남는다. 실제 삭제는 `--apply` 를 명시해야만 한다
(기본은 `--dry-run`).

### 자동 복구 검증

`scripts/dr/verify_restore_auto.sh` 가 **일요일마다 최신 자동 백업을 실제로 import** 한다
(`com.edencrm.backup-verify`). `repair_dump.php` 를 거치지 않는 무변환 import 이므로,
§경고 2 의 결함이 재발하면 최대 7일 안에 잡힌다. 결과는 `docs/audit/dr/auto_verify/`.

```bash
bash scripts/dr/verify_restore_auto.sh --force   # 즉시 1회
```

---

## 2. 필요한 환경

| 항목 | 운영 실측값 | 복구 대상 요건 |
|---|---|---|
| DB | MariaDB 10.6.17 | MariaDB 10.6+ 권장. MySQL 9.6 에서도 복구 검증 완료(교차 엔진 가능) |
| DB charset | 스키마 기본 utf8mb3 / **테이블 46개 전부 utf8mb4_unicode_ci** | 복구 DB 는 `utf8mb4 / utf8mb4_unicode_ci` 로 생성 |
| PHP | 8.x (CLI 8.5.4 로 검증) | PDO_MySQL, mbstring, gd(이미지 업로드), json |
| 웹서버 | Apache + `.htaccess` | `.htaccess` 의 `RewriteRule ^(app\|storage\|...)` 차단이 **반드시 동작해야 함** |
| 테이블 prefix | `edencrm_` | 공유 스키마이므로 prefix 유지 필수 |

**중요**: 운영 DB는 다른 프로젝트와 **공유되는 스키마**다(전체 162 객체 중 eden 은 46개).
복구 시에도 **`edencrm_` prefix 를 유지**해야 애플리케이션의 SQL rewrite 가 동작한다.

> 스키마명·계정명은 이 문서에 적지 않는다. 카페24는 **DB명 = DB 계정명 = FTP 계정명**이 동일해서,
> 스키마명을 적는 순간 자격증명의 절반이 노출된다. 실제 값은 `deploy/cafe24.env` 에서 읽는다.

---

## 3. 파일 복원

```bash
BACKUP=database/backups/ftp_20260729-103659      # 사용할 파일 백업
TARGET=/path/to/restore_root                      # 복구 대상 (운영 경로 아님!)

mkdir -p "$TARGET"
rsync -a "$BACKUP/" "$TARGET/"

# 검증: 파일 수·체크섬
find "$BACKUP" -type f | wc -l
find "$TARGET" -type f | wc -l                    # 같아야 함 (실측 134개)
( cd "$BACKUP" && find . -type f -print0 | sort -z | xargs -0 shasum -a 256 ) > /tmp/a.txt
( cd "$TARGET" && find . -type f -print0 | sort -z | xargs -0 shasum -a 256 ) > /tmp/b.txt
diff /tmp/a.txt /tmp/b.txt && echo "체크섬 전건 일치"
```

### 3-1. 운영 접속정보 격리 (필수)

백업에는 **운영 DB 접속정보가 평문으로 들어 있다** (`app/config/config.local.php`, 권한 644).
복구본이 실수로 운영 DB 를 물지 않도록 반드시 치운다.

```bash
mkdir -p "$TARGET/_dr/quarantine"
mv "$TARGET/app/config/config.local.php" "$TARGET/_dr/quarantine/" 2>/dev/null
chmod 600 "$TARGET/_dr/quarantine/"* 2>/dev/null
```

### 3-2. 쓰기 권한

```bash
chmod -R u+w "$TARGET/storage"
# storage/uploads, storage/logs 가 쓰기 가능해야 업로드·로그가 동작한다
[ -w "$TARGET/storage/uploads" ] && [ -w "$TARGET/storage/logs" ] && echo "권한 OK"
```

> 백업 디렉터리의 mode(755)는 백업을 내려받은 로컬 umask 값이라 **원격 실제 권한이 아니다**.
> 복구 후 반드시 수동으로 설정한다.

---

## 4. 덤프 복구변환 (2026-07-29 이전 백업만)

```bash
php scripts/dr/repair_dump.php \
    database/backups/proddb_audit_pre_20260729-013710.sql \
    /tmp/proddb_repaired.sql
```

수행 내용 두 가지 — 원본 백업 파일은 수정하지 않는다:
1. 생성 컬럼(`GENERATED ALWAYS`)을 제외한 **명시적 컬럼 목록** INSERT 로 재작성
2. 선두에 `SET NAMES utf8mb4;` 추가

실측: 0.07초 · INSERT 1,102건 중 11건 재작성 · 46개 테이블 파싱

---

## 5. DB 생성

```bash
RESTORE_DB=eden_crm_restore          # 운영 DB 이름과 절대 같으면 안 됨
mysql -u root -p <<SQL
CREATE DATABASE \`$RESTORE_DB\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'eden_restore'@'localhost' IDENTIFIED BY '<비밀번호>';
GRANT ALL PRIVILEGES ON \`$RESTORE_DB\`.* TO 'eden_restore'@'localhost';
FLUSH PRIVILEGES;
SQL

# import 전 비어 있는지 확인 — 비어 있지 않으면 중단하고 원인부터 확인
mysql -u root -p -N -B -e \
  "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RESTORE_DB'"
# → 0 이어야 정상
```

권한은 **복구 DB 하나로만 한정**한다. 전역 권한을 주면 사고 시 피해 범위가 커진다.

---

## 6. DB import

```bash
mysql --default-character-set=utf8mb4 \
      -u eden_restore -p "$RESTORE_DB" \
      < /tmp/proddb_repaired.sql \
      2>&1 | tee /tmp/import.log

echo "종료코드: ${PIPESTATUS[0]}"        # 0 이 아니면 즉시 중단하고 로그 확인
grep -E "^ERROR" /tmp/import.log        # 비어 있어야 정상
```

`Warning (Code 1681): Integer display width is deprecated` 는 **정상**이다
(MariaDB 의 `int(10)` 표기를 MySQL 이 경고하는 것 — 데이터에 영향 없음).

---

## 7. 복구 검증 (이 단계를 건너뛰면 복구했다고 말할 수 없다)

### 7-1. 구조

```bash
mysql -u root -p -e "
SELECT
 (SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA='$RESTORE_DB' AND TABLE_TYPE='BASE TABLE') AS 테이블,
 (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
   WHERE TABLE_SCHEMA='$RESTORE_DB' AND REFERENCED_TABLE_NAME IS NOT NULL) AS FK,
 (SELECT COUNT(*) FROM (SELECT DISTINCT TABLE_NAME, INDEX_NAME
   FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$RESTORE_DB') x) AS 인덱스,
 (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='$RESTORE_DB' AND DATA_TYPE='decimal') AS DECIMAL컬럼;"
```

**기대값(2026-07-29 기준)**: 테이블 46 · FK 85 · 인덱스 207 · DECIMAL 61

### 7-2. 한글 (charset 사고 검출 — 가장 놓치기 쉬운 지점)

```bash
mysql -u root -p -e "
SELECT id, name, HEX(name), CHAR_LENGTH(name) AS 글자, LENGTH(name) AS 바이트
  FROM $RESTORE_DB.edencrm_customers ORDER BY id LIMIT 5;"
```

한글 1글자 = 3바이트여야 한다. `고객1` → `CHAR_LENGTH 3 / LENGTH 7`, HEX `EAB3A0EAB09D31`.
`?` 나 `ìê°` 같은 문자가 보이면 charset 이 깨진 것이므로 **DB 를 지우고 §6 부터 다시** 한다.

### 7-3. 데이터·회계 정합성 (자동)

```bash
php scripts/dr/baseline_prod.php       # 운영 기준 기록 (읽기 전용 — 운영 무영향)
php scripts/dr/baseline_restore.php    # 복구본 측정 + 자동 대조
```

`✅ 불일치 0건` 이 나와야 한다. 확인 항목: 구조·테이블별 건수·소프트삭제 분포·
회계 금액(계약총액/공급가/VAT/순입금/확정지출/미수금/확정매출)·계약별 원장·권한 행·
비밀번호 해시 분포·orphan·중복·감사로그 원본구간·한글 HEX·첨부 레코드.

### 7-4. 인증 자격증명 무결성

```bash
php scripts/dr/verify_auth_material.php
```

운영과 복구본의 `password_hash` 를 **SHA-256 지문으로 비교**한다(해시 원문 미노출).
일치하면 "운영에서 통하는 비밀번호가 복구본에서도 통한다"가 보장된다.

### 7-5. AUTO_INCREMENT 충돌 검사

```bash
mysql -u root -p -N -B -e "
SELECT t.TABLE_NAME FROM information_schema.TABLES t
 WHERE t.TABLE_SCHEMA='$RESTORE_DB' AND t.AUTO_INCREMENT IS NOT NULL" \
| while read tbl; do
    mysql -u root -p -N -B -e "
      SELECT IF(t.AUTO_INCREMENT > COALESCE(MAX(x.id),0), 'OK',
                CONCAT('충돌위험 $tbl'))
        FROM information_schema.TABLES t, $RESTORE_DB.\`$tbl\` x
       WHERE t.TABLE_SCHEMA='$RESTORE_DB' AND t.TABLE_NAME='$tbl'" 2>/dev/null
  done | grep -v OK
```

운영보다 카운터가 작은 것 자체는 정상(백업 이후 운영에서 전진). **`AUTO_INCREMENT > MAX(id)`**
이기만 하면 다음 INSERT 가 안전하다.

### 7-6. 첨부파일

```bash
# DB 레코드와 실제 파일 대조
mysql -u root -p -N -B -e "SELECT id, path, size FROM $RESTORE_DB.edencrm_project_files" \
| while read id p sz; do
    f="$TARGET/storage/uploads/$p"
    [ -f "$f" ] && [ "$(stat -f%z "$f")" = "$sz" ] && echo "OK #$id" || echo "❌ #$id 누락/불일치"
  done
```

---

## 8. 애플리케이션 기동

### 8-1. 설정 파일 작성

`app/config/config.local.php` 를 복구 대상에 맞게 **새로 만든다**(백업의 운영 설정 재사용 금지).

```php
<?php
return [
    'APP_ENV'      => 'production',      // 검증 중에는 'local' 로 두면 오류가 화면에 보인다
    'BASE_URL'     => 'https://<복구 도메인>',
    'SESSION_NAME' => 'eden_crm_sid',
    'TBL_PREFIX'   => 'edencrm_',        // 공유 스키마 — 반드시 유지
    'DB_HOST'      => '<복구 DB 호스트>',
    'DB_PORT'      => 3306,
    'DB_NAME'      => '<복구 DB>',
    'DB_USER'      => '<복구 DB 계정>',
    'DB_PASS'      => '<비밀번호>',
];
```

> 비밀번호·키의 실제 값은 이 문서에 적지 않는다. 별도 비밀 보관소에서 가져온다.

### 8-2. 기동 확인

```bash
curl -s -o /dev/null -w "%{http_code}\n" "$BASE_URL/index.php?r=login"   # 200
curl -s "$BASE_URL/index.php?r=login" | grep -c "Fatal error\|SQLSTATE"  # 0
```

### 8-3. 최고운영자 로그인 확인

로그인 → 대시보드 → 계약 목록까지 도달하면 "핵심 기능 사용 가능" 상태다.

**주의 — 실제 장애 시 가장 먼저 막히는 지점**: `deploy/ADMIN_CREDENTIALS.local.txt` 의
비밀번호는 **이미 무효다**(운영에서 변경됨). 현재 관리자 비밀번호를 아는 사람이 없으면
복구본에 로그인할 수 없다. §11 의 개선 항목 참조.

임시 대응 — 복구본에서만 비밀번호를 재설정한다(운영 금지):

```bash
php -r 'echo password_hash("새비밀번호", PASSWORD_BCRYPT), "\n";'
# 출력된 해시를 복구 DB 에만 UPDATE
mysql -u eden_restore -p "$RESTORE_DB" -e \
  "UPDATE edencrm_users SET password_hash='<해시>', must_change_password=0,
          failed_attempts=0, locked_until=NULL WHERE login_id='admin';"
```

---

## 9. 실측 복구시간

2026-07-29 리허설 실측 (로컬 격리 환경 · `scripts/dr/rehearsal.sh`):

| 단계 | 실측 | 자동화 |
|---|---:|---|
| 1. 백업 파일 확인 | 0.03s | 자동 |
| 2. 파일 복원 (134개) | 0.09s | 자동 |
| 3. 설정 격리·권한 | 0.02s | 자동 |
| 4. 덤프 복구변환 | 0.07s | 자동 |
| 5. DB 생성 | 0.16s | 자동 |
| 6. DB import (46테이블) | 0.34s | 자동 |
| 7. 앱 기동 | 0.86s | 자동 |
| 8. 핵심 기능 사용 가능 | 0.88s | 자동 |
| **전체** | **2.78s** | |

**이 수치가 실제 장애에 그대로 적용되지 않는 부분**:
- 백업 파일이 이미 로컬에 있는 상태를 전제한다. 원격 스토리지에서 받아오면 **전송 시간이 추가**된다.
- 신규 호스팅 계정 발급·DNS 전환은 포함되지 않는다(§10).
- **수동 단계**: 복구 대상 서버 확보, `config.local.php` 작성, 관리자 비밀번호 확보, DNS 전환.
- **자동화된 단계**: 위 1~8 전부 (`scripts/dr/rehearsal.sh` 한 번으로 재현).

현실적 RTO 추정: 호스팅이 준비된 상태라면 **10~30분**(파일 업로드 + 설정 + 검증),
신규 계정부터 시작하면 **수 시간**(계정 발급·DNS 전파가 지배적).

---

## 10. 실제 장애 시 도메인 전환

1. 복구본을 **운영과 다른 경로/DB** 에 올려 §7 검증을 전부 통과시킨다.
2. 검증 통과 후에만 도메인을 전환한다. **검증되지 않은 복구본을 운영으로 올리지 않는다.**
3. Cafe24 관리자에서 도메인 연결 경로를 복구본으로 변경.
4. DNS TTL 만큼 전파 지연이 있다(레코드 확인: `dig +short <도메인>`).
5. 전환 후 §8-2, §8-3 을 운영 도메인 기준으로 재확인.

---

## 11. 롤백

복구본에 문제가 발견되면:

1. **복구 환경을 삭제하지 않는다** — 원인 분석 자료다.
2. 도메인을 전환했다면 원래 경로로 되돌린다.
3. 복구 DB 는 유지하고, 새 복구 DB 를 만들어 §5 부터 다시 수행한다.
4. import 로그(`/tmp/import.log`)와 `_dr/evidence/` 를 보존한다.

---

## 12. 외부 연동

**현재 이 애플리케이션에는 외부 연동이 없다** (실측: `curl_init`·`mail()`·`fsockopen`·
webhook·SMTP 전부 0건). 알림은 DB 기반 인앱 알림뿐이다.
→ 복구 환경을 띄워도 실제 고객·직원에게 발송되는 경로가 코드상 존재하지 않는다.

향후 외부 연동이 추가되면 복구 환경에서 반드시 차단하고 이 절에 기록한다.

---

## 13. 백업 자체의 개선 필요 사항

2026-07-29 DR 테스트에서 확인된, **아직 해결되지 않은** 항목:

| # | 문제 | 위험 | 상태 |
|---|---|---|---|
| 1 | 백업이 맥북 1대에만 존재 (git 원격 0개, 오프사이트 사본 0, `.gitignore` 로 git 제외) | 디스크 고장·분실 시 **코드와 백업 동시 전손 → 복구 불가** | ❗ **미해결 — 최우선** |
| 2 | 관리자 비밀번호 보관 체계 없음 (`ADMIN_CREDENTIALS.local.txt` 무효) | 복구해도 **로그인 불가 → RTO 무한** | ❗ 미해결 |
| 3 | `rollback.sql` 이 39/46 테이블만 커버 (7개 누락) | 롤백 후 잔여 테이블로 충돌 | ❗ 미해결 |
| 4 | 백업에 운영 DB 자격증명 평문 포함(mode 644) | 백업 사본 유출 = 자격증명 유출. **자동화로 사본이 매일 누적된다** | ⚠ 복구 시 §3-1 로 대응 |
| 5 | `deploy.sh` 가 원격 삭제 미동기화 | 운영에 죽은 파일 누적(현재 4개, 웹 접근은 차단됨) | ⚠ 낮음 |
| ~~6~~ | ~~백업 100% 수동·배포 직전에만 실행. 실측 최대 간격 94.9시간~~ | ~~RPO 무제한~~ | ✅ 2026-07-29 자동화(하루 1회) |
| ~~7~~ | ~~`backup.sh` 가 실패를 성공으로 보고~~ | ~~빈 백업이 정상으로 남음~~ | ✅ 2026-07-29 수정 |
| ~~8~~ | ~~생성 컬럼 INSERT 로 전 백업 복구 불가~~ | ~~치명~~ | ✅ 2026-07-29 수정 |
| ~~9~~ | ~~charset 선언 없음~~ | ~~한글 파손~~ | ✅ 2026-07-29 수정 |
| ~~10~~ | ~~비원자적 덤프~~ | ~~정합성 깨진 백업~~ | ✅ 2026-07-29 수정 |
| ~~11~~ | ~~타임존 불일치로 파일명 9시간 어긋남~~ | ~~잘못된 백업쌍 선택~~ | ✅ 2026-07-29 수정 |
| ~~12~~ | ~~복구 가능성 미확인(결함이 3개월간 미발각)~~ | ~~복구 불가를 장애 때 알게 됨~~ | ✅ 2026-07-29 주간 자동 검증 |

### 자동화가 해결하지 못하는 것 — 반드시 인지할 것

**RPO 는 개선됐지만 "백업이 살아남는가"는 그대로다.** 자동 백업이 매일 돌면
"백업은 챙겨졌다"는 안심이 생기는데, `database/backups/` 는 여전히 노트북 SSD
한 곳에만 있다. 디스크 고장·도난·랜섬웨어 시 코드와 백업이 동시에 사라진다.
**두 문제는 독립적이며 후자가 더 치명적이다.**

또 자동 백업은 **맥북이 켜져 있고 네트워크에 연결돼 있을 때만** 돈다. 휴가로 5일간
노트북을 열지 않으면 백업도 5일 공백이다. 26시간 신선도 알림이 이를 알리지만,
그 알림 역시 같은 맥북에서 나가므로 **맥북이 꺼져 있으면 알림도 침묵한다** —
가장 위험한 상태에서 감지도 함께 멈춘다는 뜻이다.

최소 완화(권고): 외장 디스크 `rsync` 또는 클라우드 동기 폴더 사본. 현재 백업 총량이
24MB 라 무료 용량으로 수년치가 들어간다. 비용이 사실상 0인데 위험이 가장 크다.

---

## 14. 복구 테스트 재실행

이 절차 전체를 격리 환경에서 다시 검증하려면:

```bash
bash scripts/dr/setup_restore_env.sh     # 격리 환경 생성 (가드 8종 통과 필요)
bash scripts/dr/restore_files.sh         # 파일 복원 + 체크섬 검증
bash scripts/dr/restore_db.sh <덤프> <라벨>  # DB import + 구조 검증
php  scripts/dr/baseline_prod.php        # 운영 기준 (읽기 전용)
php  scripts/dr/baseline_restore.php     # 대조
php  scripts/dr/verify_auth_material.php # 인증 자격증명 무결성
node scripts/dr/qa/t7_auth.js            # 기동·인증·권한
node scripts/dr/qa/t8_business.js        # 업무 흐름 + 휴지통 수명주기
node scripts/dr/qa/t8b_files_browser.js  # 첨부파일
node scripts/dr/qa/t8c_browser.js        # 브라우저 PC·모바일
php  scripts/dr/qa/cleanup_qa.php        # QA 데이터 정리
bash scripts/dr/rehearsal.sh             # 전체 리허설 + RTO 실측
```

모든 스크립트에 하드 가드가 걸려 있어 **운영 DB·운영 경로를 대상으로는 실행되지 않는다**
(대상 DB 이름에 `_restore_test` 가 없거나, 경로가 프로젝트 하위이거나, 소켓이 격리
인스턴스가 아니면 즉시 중단).
