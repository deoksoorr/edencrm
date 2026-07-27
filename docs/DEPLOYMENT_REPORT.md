# 운영 배포 보고서 (R6 · 카페24 <DB_ACCOUNT>)

> **상태: 배포 완료 · 운영 실측 검수 통과 (2026-07-23).**
> **비밀값(DB/FTP 비밀번호·운영 접속정보)은 이 문서에 기록하지 않습니다** — 운영 접속값은 git 제외 `deploy/cafe24.env`, 운영 관리자 임시 자격은 git 제외 `deploy/ADMIN_CREDENTIALS.local.txt` 에만 존재합니다.

관련 문서: 스키마·prefix [MIGRATION_REPORT.md](MIGRATION_REPORT.md) · 되돌리기 [ROLLBACK_GUIDE.md](ROLLBACK_GUIDE.md) · RC 검수 [QA_REPORT.md](QA_REPORT.md).

---

## 0. 배포 개요

| 항목 | 값 |
|---|---|
| 배포 일시 | 2026-07-23 12:29 KST (파일 업로드) · DB 마이그레이션 선행 |
| 대상 호스트 | 카페24 <DB_ACCOUNT> (FTP) — 접속값은 `deploy/cafe24.env` |
| 대상 경로 | `/www/eden-crm/` (신규 — 기존 형제 프로젝트와 분리) |
| 서비스 URL | `<SERVICE_URL>/` |
| 운영 DB | 공용 `<DB_ACCOUNT>` (MariaDB 10.6.17) · prefix `edencrm_` 공존 · CREATE DATABASE 미실행 |
| 배포 담당 | 코디네이터(T12) — 검수 에이전트는 운영 미접속 |

## 1. 사전 진단

- ✅ 배포 전 `edencrm_%` 테이블 **0건**(신규 배포, 잔재 없음) — PDO 실측.
- ✅ 타 프로젝트 공존 확인(무접촉): `/www` 하위 eden·gnu-landing·land·land-landing·ondo-bakery·opening·raum / DB 116테이블. 무프리픽스 일반명 충돌 **0**.
- ✅ `deploy/cafe24.env` 필수 키 충족(FTP·DB·SERVICE_URL·TBL_PREFIX=edencrm_·APP_DB_HOST=localhost).
- ✅ `php -l` 전 PHP 파일 문법 이상 0 (deploy.sh 선행 검사).

## 2. 백업

- ✅ 배포 전 DB 베이스라인 스냅샷: `_deploy/backups/eden-crm/pre_deploy_baseline_<ts>.txt` (웹루트 밖, 202B) — "edencrm_ 0개, 롤백=edencrm_ DROP" 기록. 신규 배포라 잃을 우리 데이터 없음.
- ✅ 롤백 경로 확보: `database/cafe24/rollback.sql` (edencrm_ 39테이블 한정 DROP, 안전핀 주석). 상세 [ROLLBACK_GUIDE.md](ROLLBACK_GUIDE.md).

## 3. DB 마이그레이션 (PDO 러너 — 로컬 mysql 9.6 이 native_password 미지원이라 `deploy/run_migration.php` 사용)

- ✅ `001_schema.sql` 적재 — **39테이블** `edencrm_`, CREATE TABLE IF NOT EXISTS. 적재 후 실측 39개.
- ✅ `002_core.sql` 적재 — 역할 5·권한 31(attendance.manage 포함)·공정단계 20(대기중+19)·영업단계 12·설정 15·공휴일 20. INSERT IGNORE idempotent.
- ✅ 멱등 재실행 검증: 001·002 재적재 후 테이블/시드 수 불변.
- ✅ MariaDB 10.6.17 실적재 오류 0 (운영 실 DB).
- ✅ `seed_dev.sql`(개발 더미)·테스트 계정 **미적재** — 업무 더미(고객·계약·프로젝트) 0 확인.
- ✅ 관리자 계정: 002 는 계정 미포함 → 운영 super_admin(admin/김덕수) 별도 생성, **강력 임시 비밀번호 + `must_change_password=1`**(첫 로그인 변경 강제). 자격은 `deploy/ADMIN_CREDENTIALS.local.txt`(git 제외).

## 4. 파일 업로드 (`deploy/deploy.sh`, mirror --reverse · `--delete` 미사용)

- ✅ dry-run 전송 목록 검토 → 실제 업로드(`CONFIRM=yes`). 로그 `deploy/deploy_20260723-122922.log`.

| 구분 | 건수 |
|---|---|
| 전송 항목 | 116 (소스 ~114 파일 + 루트/`storage` `.htaccess` + 운영 config) |
| 생성 디렉토리 | 31 |
| 원격 총 항목(파일+디렉토리) | 152 |
| 제외(격리) | `database/`·`scripts/`·`docs/`·`deploy/`·`.superpowers/`·`.git/`·`.devdb/`·`app/config/config.local.php`·`storage/uploads`·`storage/logs`·`*.md`/`*.sql`/`*.log` 등 |

- ✅ `storage/uploads`·`storage/logs` 생성 + `storage/.htaccess` 업로드.
- ✅ 운영 config: `config.production.php`(APP_ENV=production·display_errors off·**DB_HOST=localhost**·prefix edencrm_) → 원격 `app/config/config.local.php` 배치. 로컬 devdb 값 유출 0.
- ✅ `--delete` 미사용 — 기존/사용자 업로드 파일 무삭제. 형제 프로젝트 디렉토리 무접촉.

## 5. 운영 Smoke (실측 · `<SERVICE_URL>`)

- ✅ 루트 `/` → 302 `?r=login` · 로그인 페이지 200(로그인 폼·CSRF, 오류 문자열 0).
- ✅ 내부 경로 차단: `app/config/config.local.php`·`database/schema.sql`·`.superpowers/…`·`deploy/cafe24.env` 전부 **403**.
- ✅ 운영 config 소스 노출 0.

## 6. 전체 검수 (운영 실측)

- ✅ 관리자 로그인(admin) → 비밀번호 변경(must_change 해소) → 재로그인 정상. `last_login_at` DB 기록(쓰기 정상).
- ✅ 주요 10화면(대시보드·고객·계약·견적·프로젝트·공정보드·일정·근태분석·설정·감사) **200·PHP 경고 0**.
- ✅ 미인증 dashboard → 302(로그인). 파이프라인 쓰기 라우트(move/patch/board) **404**(조회 전용 유지).
- ✅ 빈 데이터 무오류(NaN/Infinity/Deprecated 0), 공정 20단계·대기중 존재, 업무 더미 0(운영 초기 청정).
- ✅ 운영에 업무 테스트 데이터 미생성 — 원복 대상 없음(관리자 계정은 실계정).

## 7. 배포 중 발견·수정 (2건)

1. **운영 로그인 500 (SQLSTATE 1130)** — 앱이 DB에 외부 주소(<FTP_HOST>)로 접속해 공인 IP 미허용. `gen_config_production.php` 가 `APP_DB_HOST=localhost` 를 쓰도록 교정 + `cafe24.env` 에 키 추가 → config 재생성·재업로드 후 로그인 302 정상. (외부 `DB_HOST` 는 로컬 마이그레이션 전용으로 분리.)
2. **운영 로그인 계정 부재** — seed_dev 미적재 원칙상 계정 0 → super_admin 운영 계정 생성(강제 변경·자격 git 제외 파일).

## 8. 잔여·후속

- 정책/후속(코드 아님): 계정 열거 메시지 UX · `session_write_close` 조기 해제 · 대시보드 당월 집계 메모이제이션 — [QA_REPORT.md](QA_REPORT.md) R6 절.
- 운영 관리자 최초 로그인 시 비밀번호 변경 필수(임시값은 `deploy/ADMIN_CREDENTIALS.local.txt`).
- 추가 직원 계정은 운영 설정 화면에서 생성(개발 더미 미이관).
