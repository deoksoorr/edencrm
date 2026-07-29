# DR 테스트 상태 로그 (세션 중단 대비)

**마지막 갱신**: 2026-07-29 · 상태: **전 단계 완료**

다음 세션에서 이어서 작업할 경우 **운영 환경을 다시 건드리지 말고** 이 문서와
복구 환경 상태를 먼저 확인한다.

---

## 완료된 복구 단계

| 태스크 | 상태 | 결과 |
|---|---|---|
| T1 운영 기준상태·백업 커버리지 갭 | ✅ | MariaDB 10.6.17 · 46테이블 · 1,102행 · 커버리지 갭 0 |
| T2 백업본 자체 검증 | ✅ | 치명 2 · 높음 5 발견 (§아래) |
| T3 격리 복구환경 구축 | ✅ | 가드 8/8 차단 확인 |
| T4 파일 백업 실제 복원 | ✅ | 134/134 SHA-256 일치 |
| T5 DB import | ⚠→✅ | 원본 실패(ERROR 3105) → 복구변환 후 성공 → 생성기 수정 후 무변환 성공 |
| T6 데이터·회계 정합성 | ✅ | 불일치 0건 |
| T7 기동·인증·권한 | ✅ | PASS 47 · FAIL 0 |
| T8 기능 QA·휴지통·첨부·브라우저 | ✅ | PASS 151 · FAIL 0 |
| T9 QA 데이터 정리 | ✅ | 잔존 0건 · orphan 0건 |
| T10 절차서·RPO/RTO·최종판정 | ✅ | RTO 2.78초 실측 · 절차서 작성 |

---

## 생성한 복구 경로·DB

| 항목 | 값 |
|---|---|
| 복구 디렉터리 | `/Users/deoksookim/Desktop/코드/claude code/eden_crm_restore_test/` |
| 복구 DB | `eden_crm_restore_test` @ 로컬 격리 MySQL 9.6 (소켓 `eden_crm/.devdb/mysql.sock`, 포트 3307) |
| 복구 DB 계정 | `eden_restore_user` (해당 DB 에만 권한) |
| 웹 서버 | `http://127.0.0.1:8091` — 기동: `bash <복구경로>/_dr/serve.sh` |
| 설정 파일 | `<복구경로>/_dr/config.restore.php` |
| 격리 보관 | `<복구경로>/_dr/quarantine/config.local.php` (백업에 있던 운영 자격증명) |
| 증거 | `<복구경로>/_dr/evidence/` |

---

## 현재 상태

- **파일 복원**: 완료 (134개, 백업과 SHA-256 전수 일치)
- **DB import**: 완료 — **원본 백업의 복구변환본**(`_dr/repaired/proddb_repaired.sql`)이 적재된
  **원본 그대로의 상태**. QA 흔적 없음(감사로그 394건 = 운영과 동일).
- **QA 데이터**: 전량 정리 완료, 잔존 0건
- **QA 비밀번호**: 적용 안 된 상태 (재적재로 원복). 로그인 테스트가 필요하면
  `php scripts/dr/qa/set_qa_passwords.php` 를 다시 실행할 것 — **복구 DB 에만 적용된다**.
- **운영 환경**: 변경 없음. 읽기 전용 접속만 수행했고 서버 강제 READ ONLY 트랜잭션 사용.

---

## 외부 연동 차단 상태

- 앱 코드에 외부 연동 **실측 0건** (`curl_init`·`mail()`·`fsockopen`·webhook·SMTP 전무)
- 알림은 DB 기반 인앱 전용 → 복구 환경에서 실제 발송 경로가 코드상 부재
- `cafe24.env` 복구 환경 **미복사** · `config.local.php` 격리 보관
- 웹서버 루프백(127.0.0.1) 바인딩 · `X-Robots-Tag: noindex, nofollow, noarchive`

---

## 발견한 오류

### 치명 (미해결)

1. **백업 단일 장애점** — git 원격 0개, `.gitignore` 로 백업 git 제외, 오프사이트 사본 0,
   Time Machine 미설정. 맥북 1대 디스크에 코드와 백업 동시 존재.
2. **RPO 무제한** — 백업 100% 수동, 배포 직전에만 실행. 실측 최대 공백 94.9시간(3.95일).
   7/26 업로드 파일이 백업 공백 구간에 존재 → 3일치 유실 가능 실증.
3. **관리자 자격증명 부재** — `deploy/ADMIN_CREDENTIALS.local.txt` 의 비밀번호 무효 확인.
   복구본에 로그인할 수단이 없다.

### 치명 (이번 세션에서 수정 완료)

4. **생성 컬럼 INSERT** — `db_dump.php` 가 `active_pair`(VIRTUAL)에 값을 INSERT →
   ERROR 3105 로 23/46 테이블에서 중단. **DB 백업 14개 전량 해당**.
   → 명시적 컬럼 목록 + 생성 컬럼 제외로 수정, 신규 백업 무변환 import 실증.

### 높음 (수정 완료)

5. charset 선언 없음 → `SET NAMES utf8mb4` 추가
6. 비원자적 덤프 → `START TRANSACTION WITH CONSISTENT SNAPSHOT` 추가
7. 타임존 불일치(파일명 최대 9시간·날짜 어긋남) → `Asia/Seoul` 명시
8. `SHOW TABLES` 가 뷰 포함 → `information_schema` BASE TABLE 한정

### 높음 (미해결)

9. 백업에 운영 자격증명 평문 포함 (`config.local.php`, mode 644)
10. `rollback.sql` 커버리지 39/46 (7개 테이블 누락)

### 낮음

11. `deploy.sh` 원격 삭제 미동기화 → 운영 잔존 파일 4개 (`.htaccess` 403 차단·라우트 미참조로 노출 없음)

---

## 다음 실행 명령

### 복구 환경을 다시 검증하려면

```bash
cd "/Users/deoksookim/Desktop/코드/claude code/eden_crm"

# 서버가 내려가 있으면 기동
bash "/Users/deoksookim/Desktop/코드/claude code/eden_crm_restore_test/_dr/serve.sh" &

# 상태 확인 (운영 접속 없음)
php scripts/dr/baseline_restore.php        # 복구본 ↔ 운영 기준 대조
```

> `baseline_restore.php` 는 `docs/audit/dr/baseline_prod.json` 을 기준으로 비교한다.
> 운영을 다시 조회할 필요가 없다면 `baseline_prod.php` 는 **실행하지 않아도 된다**.

### 전체를 처음부터 다시 돌리려면

```bash
bash scripts/dr/rehearsal.sh               # 파일+DB 전체 복구 + RTO 실측 (2.78초)
node scripts/dr/qa/t7_auth.js              # 인증·권한 (QA 비밀번호 필요)
node scripts/dr/qa/t8_business.js          # 업무 흐름 + 휴지통
node scripts/dr/qa/t8b_files_browser.js    # 첨부파일
node scripts/dr/qa/t8c_browser.js          # 브라우저 PC·모바일
php  scripts/dr/qa/cleanup_qa.php          # QA 정리
```

### 복구 환경을 제거하려면

```bash
# DB
mysql --socket="$PWD/.devdb/mysql.sock" -uroot \
  -e "DROP DATABASE IF EXISTS eden_crm_restore_test; DROP USER IF EXISTS 'eden_restore_user'@'localhost';"
# 파일 (증거·절차서는 프로젝트 안에 이미 보존되어 있음)
rm -rf "/Users/deoksookim/Desktop/코드/claude code/eden_crm_restore_test"
```

---

## 절대 하지 말 것

- 운영 DB 에 `database/backups/proddb_*.sql` 실행 (**DROP TABLE 46개로 시작한다**)
- 복구본을 검증 없이 운영으로 재배포
- 운영 계정 비밀번호 변경 (복구 DB 에만 적용할 것)
- `scripts/dr/*` 를 가드 우회해서 실행 (대상 DB 에 `_restore_test` 가 없으면 중단되도록 설계됨)
