# R16 진행 로그 — 직원별 세부 권한 관리 시스템

목적: 세션이 끊겨도 중단 지점부터 즉시 재개할 수 있도록 상태·DB 변경·다음 작업을 기록한다.
설계 원본: `docs/superpowers/specs/2026-07-29-r16-employee-permissions-design.md`
하네스 상태: `node "…/telegram-control/harness_progress.js" show`

최종 갱신: 2026-07-29 — **전체 완료 · 운영 배포·실검수 완료**

---

## 1. 태스크 상태

| ID | 작업 | 상태 | 커밋 |
|---|---|---|---|
| T1 | 수정사항(버튼 리네임·명칭 통일) | ✅ 완료 | `fix(r16): 버튼 리네임 + 명칭 통일` |
| T2 | 권한 스키마·판정엔진(Perm/isSuperAdmin) | ✅ 완료 | `feat(r16): 직원별 권한 엔진` |
| T3 | 라우트·컨트롤러 서버단 강제 + 취약점 12건 | ✅ 완료 | `feat(r16): 서버단 권한 강제` |
| T4 | 고객·영업기회 휴지통 신설 | ✅ 완료 | `feat(r16): 고객·영업기회 휴지통 신설` |
| T5 | 권한 매트릭스 UI·메뉴 노출제어 | ✅ 완료 | `feat(r16): 직원 권한 매트릭스 배선` |
| T6 | 대시보드 권한 필터링 | ✅ 완료 | `feat(r16): 대시보드 권한별 위젯 조립` |
| T7 | 로컬 QA(A~H)·보안 우회·회귀 | ✅ 완료 | 프로브 119 · 브라우저 43 · 단위 25스위트 |
| T8 | 운영 배포·DB 마이그레이션·실서버 검수 | ✅ 완료 | 2026-07-29 배포·검수·정리 완료 |

---

## 2. DB 변경사항

### 2-1. 신규 테이블
`employee_permissions` (운영: `edencrm_employee_permissions`)
- `user_id`(FK users CASCADE), `section`, `resource_key`, `can_read/can_write/can_delete`,
  `updated_by`(FK users SET NULL), `created_at`, `updated_at`
- `UNIQUE(user_id, resource_key)`, `KEY(user_id)`, `KEY(resource_key)`

### 2-2. 신규 perm_key 11종
`pipeline.delete`, `quote.delete`, `contract.delete`, `project.delete`, `process.view`,
`process.delete`, `schedule.delete`, `worklog.delete`, `cost.view`, `cost.delete`, `trash.manage`
→ super_admin 역할에 전량 매핑(CROSS JOIN, INSERT IGNORE)

### 2-3. 마이그레이션 파일
- 로컬: `database/migrations/2026-07-29_r16_permissions.sql` — **적용 완료**
- 운영: `database/cafe24/013_r16_permissions.sql` — **적용 완료** (2026-07-29)
- 데이터 변환: `scripts/migrate_permissions_r16.php`
  - 로컬 `--apply` **완료** (권한 행 10개)
  - 운영 `--prod --apply` **완료** — 권한 행 32개, 상실 권한 0건

### 2-4. 중요 — 코드 동기화 필수
`app/core/Db.php`의 `TABLES` 상수에 `employee_permissions`를 추가했다.
**신규 테이블을 만들 때 여기 등록하지 않으면 운영(prefix 사용)에서 500이 난다.** (R9 재발 방지)

---

## 3. 핵심 아키텍처 결정

1. **기존 Rbac 엔진 재사용, 판정 소스만 교체.** `Rbac::can($permKey)`가 `Perm::registry()`로
   `(resource_key, action)`을 찾아 `employee_permissions`를 조회한다. 라우터의 `perm` 옵션
   187개와 뷰의 `can()` 헬퍼 호출부는 한 줄도 바꾸지 않았다.
2. **기본 거부.** 미등록 perm_key·미등록 리소스·미등록 액션·행 부재는 전부 false.
3. **ADMIN_ONLY**(`Perm::adminOnly()`)는 레지스트리에 매핑하지 않는다 = 일반 직원에게 부여할
   수단 자체가 없다. 정산·전직원성과·출근·보너스·직원·설정·감사·휴지통.
4. **`isSuperAdmin`은 `roles.role_key`만 신뢰.** 세션 `role_key`·쿠키·user id 하드코딩 미사용.
5. **`Scope::canViewAllProjects()`** — 전체/배정 열람 범위는 `analytics.reports` read로 판정.
   `project.view_all`과 `project.view_assigned`가 매트릭스에서 하나로 합쳐져 구분 축이
   사라졌기 때문. 기존 5개 역할과 1:1로 일치해 가시 범위 변화 없음.
   **부작용:** 리포트 권한을 주면 전체 프로젝트·고객도 보인다 → 매트릭스 항목명에 명시함.
6. **role_permissions / user_permissions는 판정에서 제외.** 카탈로그·마이그레이션 소스로만
   보존한다(삭제하지 않음 — 롤백 대비).

---

## 4. 로컬 검증 현황

- `php scripts/tests/run.php` — 25 스위트 전건 통과 (신규: `unit_r16_perm` 61건, `unit_r16_trash` 24건)
- `bash scripts/qa_r16_probe.sh` — 119건 전건 통과 (T6 진행 중에는 dashboard 500으로 일시 하락)
- QA 계정: `php scripts/qa_r16_seed.php --seed` / `--list` / `--cleanup`
  (`qa_r16_a`~`qa_r16_h`, 비밀번호는 스크립트 상수. G는 기존 admin 사용)

---

## 5. 완료 상태 · 남은 판단 사항

전 태스크 완료. 운영 배포·실검수·QA 정리까지 끝났다.

**사장 판단이 필요한 항목**
1. 운영 휴지통에 **삭제된 고객 2건**(id=1 고객1, id=2 고객나, 2026-07-24 삭제)이 남아 있다.
   이번 작업으로 복원이 가능해졌으나, 실데이터라 임의로 복원하지 않았다.
   필요 시 관리 > 고객 > 휴지통에서 복원 또는 완전삭제.
2. 프로젝트 휴지통 7건, 견적 2건, 계약 1건도 동일하게 대기 중이다.
3. `리포트·손익 (전사 열람)` 권한을 부여하면 해당 직원에게 **전체 프로젝트·고객 목록**이
   함께 보인다(매트릭스 화면에 명시). 현재 영업관리자 1명만 보유.

**재검수 방법**
- 운영 권한 시스템: `bash deploy/verify_r16.sh` (검수용 임시 계정 선행 생성 필요)
- 운영 휴지통 수명주기: `php deploy/verify_r16_trash.php`
- 로컬: `php scripts/tests/run.php` · `bash scripts/qa_r16_probe.sh` · `node scripts/qa_browser/qa_r16_browser.js`

## 6. 운영 기준선 (2026-07-29 측정)

MariaDB 10.6.17 · 계정 7 (super_admin 1 · sales_manager 1 · site_manager 4 · staff 1 · **accountant 0**)
`user_permissions` 0행.

건수: users 7 · customers 6 · leads 2 · quotes 4 · contracts 5 · payments 13 · projects 11 ·
costs 4 · schedules 2 · site_bonuses 5 · audit_logs 377 · project_assignments 11
(전체: `deploy/prod_baseline.json`)

소프트 삭제: customers 2 · quotes 2 · contracts 1 · projects 7 · site_bonuses 5

**권한 변환 영향: 상실 권한 0건** (`docs/r16_perm_migration_prod_preview.md`).
회계 계정이 없어 정산 최고운영자 전용화의 영향을 받는 직원이 없다.

---

## 7. 롤백

- 파일: `database/backups/ftp_*` 의 직전 미러를 되돌린다.
- DB: `013_r16_permissions.sql`은 테이블 추가·perm 추가만 하므로 기존 데이터에 영향이 없다.
  되돌리려면 `DROP TABLE edencrm_employee_permissions` + 신규 perm_key 삭제.
  단 **코드가 구버전으로 돌아가야** 판정이 role_permissions 기반으로 복귀한다.
- 가장 안전한 롤백: 파일만 직전 커밋으로 되돌리면 신규 테이블이 있어도 무해하다
  (구버전 Rbac는 employee_permissions를 참조하지 않는다).

---

## 8. 운영 배포 결과 (2026-07-29)

- 파일 백업: `database/backups/ftp_20260729-082624`
- DB 백업: `database/backups/proddb_r16_pre_20260728-232641.sql` (45 tables, 1018 rows, 328KB)
- 스키마 마이그레이션: `013_r16_permissions.sql` 3문장 적용
- 권한 변환: 7계정 중 6계정에 32행 생성, super_admin 0행, **상실 권한 0건**
- 파일 업로드: 48개 (mirror --reverse, --delete 미사용, 운영 config·업로드 폴더 보존)
- 데이터 보존: 16개 업무 테이블 건수 배포 전과 **완전 일치**(audit_logs 만 검수 기록 +2)
- 실검수: 최고운영자 29항목 · 일반 직원 차단 20항목 · 휴지통 수명주기 14항목 전건 통과
- QA 정리: 운영 임시 계정 2개·QA 고객 전량 삭제 확인(잔존 0)

### 검수 중 발견·수정한 기존 결함
CSRF 실패 응답이 419(비표준)라 카페24 Apache 가 500 으로 변질시켜,
사용자에게 안내 문구 대신 서버 오류 페이지가 표시되고 있었다(R16 이전부터 존재).
요청 차단 자체는 정상이었고 데이터 변경도 없었다. 표준 403 으로 교체 후 재배포·재검증 완료.
