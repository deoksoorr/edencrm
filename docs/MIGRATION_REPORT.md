# DB 마이그레이션·데이터 보정 이력 (R2~R6)

`database/migrations/` 의 마이그레이션과 `scripts/` 의 데이터 보정 스크립트가 각각 무엇을 바꿨는지,
몇 건을 보정했고 어떻게 되돌리는지를 정리한 문서입니다. **R6 운영 배포용 스키마·롤백은 아래 [R6 절](#0-r6-운영-배포-준비-2026-07-23)** 참조.

공통 규약:

- 마이그레이션은 **파일명(날짜) 순으로 1회 적용**합니다: `mysql <DB> < database/migrations/<파일>.sql`
- 적용 후 `database/schema.sql`(신규 설치용)·`seed_core.sql` 에 같은 내용이 동기 반영되어 있으므로, **신규 설치는 schema.sql 만으로 충분**하고 마이그레이션은 기존 DB 업그레이드용입니다.
- 보정 스크립트는 **dry-run 이 기본**(대상 목록만 출력)이고 `--apply` 를 붙여야 실제 반영됩니다.
- **비밀값(DB/FTP 비밀번호·운영 DB 접속정보)은 이 문서에 기록하지 않습니다** — 운영 접속값은 git 제외 파일 `deploy/cafe24.env` 에만 존재합니다.

---

## 0. R6 운영 배포 준비 (2026-07-23)

R6 은 **로컬 스키마 변경(마이그레이션 1본)** 과 **카페24 공용 호스팅에 얹기 위한 운영 DDL(prefix 적용)** 두 갈래입니다.

### 0-1. R6 로컬 마이그레이션 — 1본

| 파일 | 대상 | 변경 내용 | 데이터 보정 | 롤백 방법 |
|---|---|---|---|---|
| `2026-07-23_r6_attendance_marks.sql` | attendance_marks(신규), permissions, role_permissions | ① **신규 테이블 `attendance_marks`**(수동 지각·무단결근 마킹 — id, user_id FK, mark_date, mark_type ENUM('late','absent'), memo, created_by FK, created_at, updated_at, **UNIQUE(user_id, mark_date)** = 같은 날 1상태만·동시 등록 원천 차단, 인덱스 idx_..._date·idx_..._created_by) ② 권한 `attendance.manage` 신설(idempotent) + super_admin(role 1) 매핑 | 없음(신규 테이블·권한 1행 — 멱등 삽입) | `DROP TABLE IF EXISTS attendance_marks;` + `DELETE FROM permissions WHERE perm_key='attendance.manage';`(role_permissions 는 FK/CASCADE 정리) |

- `schema.sql`·`seed_core.sql`(permissions 31종 — attendance.manage 포함)에 동기 완료 → **신규 설치는 schema.sql 만으로 충분**.
- 근태 R6 개편은 대부분 **코드 변경**입니다(휴가 비노출·자동 판정 폐지). 기존 `vacation` 일정 행·`attendance_work_start/end` 설정 행은 **DB 에 보존**하되 화면·통계에서 참조를 제거했습니다(데이터 삭제 없음).
- **R6 시드 리셋(T2)**: `database/seed_dev.sql` 을 직원 4명 + 일정 3건만 남기는 빈 데이터 기준선으로 재작성(개발 더미 전량 제거·AUTO_INCREMENT 리셋). **마이그레이션이 아니라 개발 시드 재작성**이며 운영에는 적재하지 않습니다. 적용 전 백업 `.superpowers/sdd/backup_pre_r6seed.sql.gz` 보존. 근거: `r6-seed-report.md`.

### 0-2. 운영 배포용 DDL — `database/cafe24/` (Prefix 적용본)

카페24 <DB_ACCOUNT> 호스팅은 **단일 공용 DB 에 여러 프로젝트가 테이블 prefix 로 공존**하는 구조라, eden_crm 은 운영 prefix **`edencrm_`** 를 씁니다.
운영 DB 이름·접속정보는 민감정보이므로 기록하지 않습니다(git 제외 `deploy/cafe24.env`). `CREATE DATABASE` 금지, 타 prefix 테이블 미접근, 일반명 테이블 신규 생성 금지.

| 파일 | 내용 |
|---|---|
| `database/cafe24/001_schema.sql` | schema.sql 의 **39개 테이블 전부에 `edencrm_` prefix** 적용. `CREATE TABLE IF NOT EXISTS`(DROP 없음), utf8mb4_unicode_ci 각 테이블 명시, **FK 제약명 65건을 `fk_edencrm_*` 로 개명**(FK 명은 DB 전역 유일이라 공용 DB 충돌 방지). **UNIQUE/KEY 인덱스명은 원본 유지**(테이블 스코프라 충돌 불가 + 앱이 1062 메시지의 `uq_assign_active_pair` 문자열을 매칭하므로 개명 시 회귀 — 등가검증에서 검출·결정). MariaDB 10.6 호환 문법만 사용. |
| `database/cafe24/002_core.sql` | seed_core 상당(역할·권한·단계·설정·공휴일). **전 구문 idempotent**(INSERT IGNORE + UNIQUE 가드, role_permissions 는 role_key·perm_key 조인으로 id 의존 제거), 개발 더미 0. 재실행 시 운영 조정 설정값 보존. |
| `database/cafe24/rollback.sql` | `edencrm_` **39개 테이블 한정 DROP** + **안전핀**(파일 내 강제 오류 1행을 직접 주석 처리해야만 실행 가능) + 실행 전 절차 주석(백업 확인·`SHOW TABLES LIKE 'edencrm\_%'` 39개 대사). 타 prefix 테이블 DROP 문 추가 금지. |

- **애플리케이션 prefix 레이어**: `config` 키 `TBL_PREFIX`(기본 `''`) + `Db::run()` 단일 진입점 SQL rewrite(알려진 39개 테이블명 단어 경계 치환, 문자열 리터럴·SHOW·information_schema 예외). `TBL_PREFIX=''` 이면 원문 즉시 반환(로컬 무비용). 테이블 신설 시 `Db::TABLES` 상수와 schema.sql 을 **반드시 함께 갱신**.
- **등가 증명(완료)**: 무프리픽스 `eden_crm_plain`(schema+seed_core+seed_dev) vs `edencrm_` 프리픽스 `eden_crm_prefixed`(001+002+seed_dev 변환) 2면에서 39테이블 행수·CHECKSUM 일치, 회계 스위트·대사(56/0)·스모크(33/0)·주요 5화면 **출력 diff 0**(세션 CSRF 토큰 제외). 근거: `r6-dbprefix-report.md`.

### 0-3. 자동 보정 대상·제외 (R6)

R6 은 **금액·기여율·근태를 자동 보정하지 않습니다**(임의 데이터 조작 금지 — 사람 판단·정책 확정 필요).

| 항목 | 처리 |
|---|---|
| 리드-계약 연결 누락 | **자동 보정 안 함** — `scripts/lead_link_candidates.php` 후보 리포트(읽기 전용)까지만(P-2 정책 확인). |
| 직원 기여율(`contribution_pct`) | **자동 보정 안 함** — 배정 시 입력값을 그대로 존중(중복 합산 방지 규칙만 코드로 강제). |
| 근태(vacation·자동 지각/조퇴) | **데이터 삭제·변환 없음** — 기존 vacation 일정·설정 행 보존, 화면 참조만 제거. |
| 기존 견적 Q2026-0001 | R6 시드 리셋으로 소멸(보류 자연 종결) — 별도 UPDATE 보정 없음. |

**R6 신규 보정 스크립트 실행: 없음**(로컬 마이그레이션은 신규 테이블·권한 추가뿐, 데이터 보정 0건). R4 이전 보정 이력은 아래 절 참조.

### 0-4. 운영 롤백 방법 (요약 — 상세는 [ROLLBACK_GUIDE.md](ROLLBACK_GUIDE.md))

1. **DB 백업 확인** → `database/cafe24/rollback.sql` 안전핀(강제 오류 1행) 주석 처리 → phpMyAdmin 등으로 실행(`edencrm_` 39개만 DROP).
2. **파일 복원** → `deploy/rollback.sh`(CONFIRM=yes 가드·`FTP_REMOTE_PATH=/www/eden-crm` 검증 — 타 프로젝트 오삭제 방지). 실행 전 `deploy/backup.sh` 로 원격 파일·DB export 선행 필수.

---

## 1. R4 마이그레이션 (2026-07-23) — 3본

| 파일 | 대상 | 변경 내용 | 데이터 보정 | 롤백 방법 |
|---|---|---|---|---|
| `2026-07-23_r4_customer_biz.sql` | customers | 사업자 컬럼 8개 추가(is_business, biz_reg_no, biz_name, biz_ceo, biz_address, biz_type, biz_item, biz_license_file_id) + 번호 인덱스 + 등록증 파일 FK(project_files, ON DELETE SET NULL) | 없음(컬럼 추가만 — 기존 행 기본값) | FK·인덱스·컬럼 8개 DROP (`ALTER TABLE customers DROP FOREIGN KEY fk_customers_biz_license_file, DROP KEY idx_customers_biz_reg_no, DROP COLUMN …`) |
| `2026-07-23_r4_process19.sql` | process_stages, warranty_repairs | ① 공정 단계 `full_complete`(전체완료, id 19, sort 19, 확인 필요) 1행 추가 — 이미 있으면 no-op(멱등) ② 하자보수 상세 테이블 `warranty_repairs` 신설(사진은 project_files 재사용) | 없음(단계 1행 삽입) | 파일 머리 주석에 명시 — `DELETE FROM process_stages WHERE stage_key='full_complete';`(참조 프로젝트 없을 때만) + `DROP TABLE IF EXISTS warranty_repairs;` |
| `2026-07-23_r4_attendance.sql` | settings | 운영 기능 토글 `feature_attendance`(근태 표시) 기본 '1' 행 1건 — `INSERT … WHERE NOT EXISTS` 로 **운영자가 바꾼 값은 보존** | 없음(설정 행 1건) | `DELETE FROM settings WHERE setting_key='feature_attendance';` |

### R4 보정 스크립트

**`scripts/backfill_process19.php`** — 공정 19단계 확장 보정 (T3)

- 실행: `php scripts/backfill_process19.php` (dry-run — 대상 목록만) → `--apply` 로 반영. 선행 조건: `r4_process19.sql` 적용.
- 대상: 상태가 완료(completed)·정산 완료(settled)인 프로젝트 중 공정이 준공검사(17) 또는 하자보수(18)에 남은 건.
- 보정: `ProcessService::moveStage` 경유 전체완료(19) 이동 — 이력에 is_auto=1, 사유 `19단계 확장 보정` 기록 + 진행률 100 +
  공정 진입일을 완료 전환 시각→실제 준공일 순으로 재조정. **하자보수로 되돌리는 케이스 없음**(전방 이동만).
- 제외: 취소·파기·진행 중 프로젝트 미접촉. 과거 공정 이력 백필 없음(이력 조작 금지 — planner S7).
- **실행 결과(로컬 DB): 2건 보정** — P2026-0002 · P2026-0003 (둘 다 준공검사→전체완료), 잔여 대상 0건.
- 롤백: 실행 시 출력되는 `[백업]` SQL 을 저장해 두면 수동 롤백 가능(보정 이력 행 삭제 + 원 공정·진입일·진행률 복원).

**`scripts/lead_link_candidates.php`** — 리드-계약/견적 연결 후보 리포트 (T7)

- 실행: `php scripts/lead_link_candidates.php` — **읽기 전용, DB 를 절대 변경하지 않음**(--apply 없음).
- 출력(현 시드 기준): 리드↔계약 연결 후보 3건 + 견적 lead_id 백필 후보 1건.
- 확정 연결(UPDATE)은 정책 확인(P-2) 후 별도 작업 — 금액 불일치로 동일 딜을 데이터만으로 단정할 수 없기 때문. [SALES_PIPELINE_RULES §5](SALES_PIPELINE_RULES.md#5-리드-계약-연결-후보-dry-run-절차) 참조.

### R4 시드·데이터 동기 (마이그레이션 아님 — 개발 시드 한정)

- T2: 신규 고객 행 추가 없이 기존 고객 1건(㈜세림) UPDATE 로 사업자 정보 예시 반영 — 회계 대사값 불변.
- T4: seed_dev 에 2026-06 작업 기록 25건 추가(전월 대비 증감 시연용) — 금액 데이터가 아니어서 회계 대사값 불변(스위트 3종 통과로 확인).
- `schema.sql`·`seed_core.sql` 에 R4 변경(사업자 컬럼·전체완료 단계·warranty_repairs·feature_attendance) 동기 완료.

---

## 2. R3 마이그레이션 (2026-07-22~23) — 4본

| 파일 | 요약 |
|---|---|
| `2026-07-22_r3_kernel.sql` | 공정 '대기중' 단계 + 계약→프로젝트 자동 생성 연결 스키마(계약 1:1, 상태 전이 계약) |
| `2026-07-22_schedule_slots.sql` | 일정 시간대 복수 선택 — `schedule_time_slots` 관계 테이블 신설(일정 1: 슬롯 N) + 직원 배정 DB 보강 |
| `2026-07-23_r3_acctverify.sql` | 입금 물리 삭제 금지 — payments status 에 'cancelled'(취소) 도입. 데이터 변경 없음(집계 계약 주석 문서화) |
| `2026-07-23_r3_contractflow.sql` | 계약 공사 정보 컬럼 + 분할 지급 비율 백필 + 견적 전환 보존 정보 백필 (kernel 의 비율 컬럼에 의존 — 파일명 순서 보장을 위해 날짜 개명됨) |

R3 보정 스크립트: `scripts/backfill_process_board.php` — 진행 상태 프로젝트 중 공정 미배치·깨진 공정 참조·첫 이력 누락 건을
'대기중' 배치/이력 보정(is_auto=1, reason '데이터 보정'), dry-run 기본.

## 3. R2 마이그레이션 (2026-07-22) — 5본

| 파일 | 요약 |
|---|---|
| `2026-07-22_supply_vat.sql` | 공급가액/부가세 분리 컬럼 추가 + 기존 데이터 백필 — **1회 적용 전제**(재실행 시 컬럼 중복 오류가 나는 것이 정상) |
| `2026-07-22_status_structure.sql` | 상태 구조 개편 — payments.kind(payment/refund) 신설(순입금 = 입금 − 환불), 계약·프로젝트 상태 enum 현행화 |
| `2026-07-22_costs_detail.sql` | costs 상세 확장 — 상태·수량·단가·작업자·공급처·증빙·조정사유 컬럼 + 비용 구분 표준 키화 |
| `2026-07-22_costs_indexes.sql` | 원가 조회 복합 인덱스 보강(재계산·소계·CSV 쿼리 커버) |
| `2026-07-22_drop_importance.sql` | 중요도(importance) 기능 전면 제거 — 프로젝트·리드 컬럼 삭제(화면·JS 코드도 동일 라운드에 제거) |

---

## 4. 적용 확인 방법

```bash
# 적용 여부 스팟 확인 (격리 개발 DB 기준 — 운영은 접속 정보만 교체)
mysql <DB> -e "SELECT stage_key, sort_order FROM process_stages WHERE stage_key='full_complete';"   # 1행이면 R4 process19 적용됨
mysql <DB> -e "SHOW COLUMNS FROM customers LIKE 'biz_reg_no';"                                       # 1행이면 R4 customer_biz 적용됨
mysql <DB> -e "SELECT setting_key, value FROM settings WHERE setting_key='feature_attendance';"      # 1행이면 R4 attendance 적용됨

# 적용 후 정합성 검증 (전부 통과가 정상)
bash scripts/run_acct_tests.sh && php scripts/reconcile_qa.php && bash scripts/qa_smoke.sh
```

*보정 실행 상세 근거: R6 = `.superpowers/sdd/r6-worklog.md` §[attend]·§[seed]·§[dbprefix] + `r6-dbprefix-report.md`·`r6-seed-report.md` · R4 = `r4-worklog.md` §[process]·§[salescrm]·§[attendance]·§[pipeline] · `r4-qa-report.md` §D. QA 판정 요약은 [QA_REPORT.md](QA_REPORT.md), 배포·롤백은 [DEPLOYMENT_REPORT.md](DEPLOYMENT_REPORT.md)·[ROLLBACK_GUIDE.md](ROLLBACK_GUIDE.md).*
