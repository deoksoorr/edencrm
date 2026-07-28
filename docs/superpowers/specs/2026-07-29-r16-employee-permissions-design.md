# R16 — 직원별 세부 권한 관리 시스템 설계

작성일: 2026-07-29
브랜치 기준: `r15-trash-forms` (R15 휴지통 완료 상태에서 이어감)

## 0. 목표

직원마다 영업·현장 기능별로 읽기·쓰기·삭제 권한을 개별 부여한다. 권한 없는 사용자는 URL
직접 접근·API 직접 호출·폼 위조로도 기능을 실행할 수 없다. 휴지통 복원·완전삭제는
최고운영자 전용으로 통제한다. 로컬 QA 후 운영 서버 배포·실검수까지 수행한다.

부수적으로 R16 수정사항(버튼 리네임, 명칭 통일)을 함께 반영한다.

---

## 1. 기존 구조 분석 결과

### 1-1. 사용자·역할

- `edencrm_users` — `role_id` FK → `edencrm_roles`, 비정규화 캐시 컬럼 `role_key`.
- 역할 5종: `super_admin`(사장), `sales_manager`(영업관리자), `site_manager`(현장관리자),
  `staff`(일반직원), `accountant`(회계).
- 세션: `$_SESSION['uid']`, `$_SESSION['role_key']`. `Auth::user()`가 매 요청 users+roles를
  조인 재조회하고 `status !== 'active'` 또는 `deleted_at` 이면 즉시 로그아웃
  (`app/core/Auth.php:113-134`).

### 1-2. 기존 권한 구조

이미 동작하는 RBAC가 있다.

- `edencrm_permissions`(perm_key 32종) / `edencrm_role_permissions` / `edencrm_user_permissions`
  (`is_grant` 1=추가, 0=제외).
- `Rbac::can($perm)` — super_admin 무조건 true, 그 외 역할권한 ∪ 개별grant − 개별deny
  (`app/core/Rbac.php:11-63`).
- 라우터(`public/index.php:70-72`)가 `routes.php`의 `perm` 옵션으로 `Rbac::require`를 일괄 강제.
- 메서드 강제 → 로그인 → CSRF(POST 전부) → perm → feature flag 순서로 이미 파이프라인이 있음.

**결론: 판정 엔진은 새로 만들지 않고 백엔드(판정 소스)만 교체한다.** 187개 라우트 게이트를
그대로 재사용하는 것이 누락 위험이 가장 낮다.

### 1-3. 발견된 결함 (이번에 함께 수정)

| # | 위치 | 문제 |
|---|---|---|
| V1 | `public/index.php` / `DashboardController.php:24-29` | `home`·`dashboard`·`dashboard.data`에 권한 검사 **0건**. `sales_manager`/`site_manager`/`staff`가 아닌 모든 역할이 `default:`로 전사 재무 대시보드(boss)를 받음 |
| V2 | `CustomersController.php:417-426` | `customers.dupcheck` Scope 미적용 — 전체 고객 이름·전화·이메일·사업자번호 검색 가능 |
| V3 | `QuotesController.php:455-461` | `quotes.leads` Scope 미적용 — 임의 고객의 영업기회 목록 노출 |
| V4 | `ContractsController.php:323-359` | `contracts.quotedata` 검사 없음 — 임의 견적의 고객·금액 JSON 덤프 |
| V5 | `AssignmentsController.php` 전체 | `Scope::`/`Rbac::` 호출 0건 — 임의 프로젝트에 배정 생성·삭제 |
| V6 | `AttendanceController.php` 전체 | `Scope::`/`Rbac::` 호출 0건 — 임의 직원 근태 마킹·해제 |
| V7 | `QuotesController.php:118-126`, `ContractsController.php:150` | `quotes.show`·`contracts.show` 행 단위 스코프 없음 — id만 알면 전건 열람 |
| V8 | `BonusController.php:169,187-188` | 본인 스코프 강제 상태에서도 `receivable`·`costReg`·`costDirect`가 전사 합계로 노출 |
| V9 | `ProcessController.php:37-43` | GET 페이지 `process.board`가 데이터를 변경(공정 복구 루프 + 이력 생성) |
| V10 | `CustomersController.php:438-439` | `customers.merge` Scope 미적용 — 임의 고객 병합(파괴적) |
| V11 | routes.php 다수 | 삭제 라우트가 쓰기 perm을 재사용 (`quotes.delete` → `quote.manage`) — 쓰기=삭제 상태 |
| V12 | `Scope.php:50` | 고객 스코프가 권한이 아닌 **역할** 기반(`isRole('super_admin','accountant','site_manager')`) |

### 1-4. 소프트 삭제 / 휴지통 현황

`deleted_at` 보유 8개 테이블 중:

| 테이블 | 소프트삭제 사용 | 휴지통 UI | 복원/완전삭제 |
|---|---|---|---|
| quotes | O | O | O (R15) |
| contracts | O | O | O (R15) |
| projects | O | O | O (R15) |
| customers | O | **X** | **X** — 지우면 회수 불능 |
| leads | O | **X** | **X** — 지우면 회수 불능 |
| goals | O | X | X |
| site_bonuses | O | X | X (단, `site_bonus_history` 원장 존재) |
| users | **미사용** | – | – (컬럼만 있고 기록자 없음) |

하드 삭제 경로: schedules, project_assignments, project_memos, warranty_repairs,
attendance_marks, pipeline_stages/process_stages, project_files(고객 사업자등록증).

---

## 2. 권한 대상 리소스

화면 표시명과 내부 리소스 키를 분리한다. 메뉴명이 바뀌어도 권한이 깨지지 않는다.

### 2-1. 영업 (section = `sales`)

| 리소스 키 | 표시명 | 대상 라우트 |
|---|---|---|
| `sales.customers` | 고객 CRM | customers.index/show/form/save/delete/dupcheck/merge/export, customers.license.*, activities.save |
| `sales.leads` | 영업기회 | pipeline.index/show/form/save/delete |
| `sales.quotes` | 견적 | quotes.index/show/form/save/delete/print/leads |
| `sales.contracts` | 계약 | contracts.index/show/form/save/delete/terminate/quotedata/toproject |

### 2-2. 현장 (section = `field`)

| 리소스 키 | 표시명 | 대상 라우트 |
|---|---|---|
| `field.projects` | 프로젝트 | projects.index/show/form/save/delete/transition/upload, files.download |
| `field.process_board` | 공정 보드 | process.* 전 13개 |
| `field.schedules` | 현장 일정 | schedule.index/data/save/move/delete, assignments.save/delete |
| `field.worklogs` | 작업일지 | worklogs.* 6개 |
| `field.costs` | 비용·원가 | costs.save/cancel/export |

### 2-3. 분석 (section = `analytics`) — 읽기 전용 행

| 리소스 키 | 표시명 | 대상 라우트 |
|---|---|---|
| `analytics.reports` | 리포트·손익 | reports.index/data/export, 프로젝트 목록의 원가·손익 컬럼 |

**쓰기·삭제 열은 UI에서 비활성 표시하고 서버에서도 거부한다.** 리포트는 조회 기능만 존재하므로
쓰기·삭제 개념이 없다. `employee_permissions` 행 자체는 동일 스키마를 쓰되
`can_write`/`can_delete`는 항상 0으로 강제된다.

### 2-4. 최고운영자 전용 (매트릭스 미노출, 항상 super_admin만)

- **정산·입금** — `payments.save/delete`, `projects.payment.save/cancel`,
  `projects.settlement.update` (perm `payment.manage`)
- **전 직원 성과·기여도** — `performance.index`(view_all), `performance.view_all`
- **전 직원 출근 통계** — `reports.attendance`, `reports.attendance_export`, `attendance.mark/unmark`
- **보너스 관리** — `bonus.save/delete/calc` (perm `bonus.manage`)
- **목표 쓰기** — `targets.save`, `targets.goal.*` (perm `settings.manage`)
- **직원 관리** — `staff.*` 6개
- **시스템 설정** — `settings.*` 5개
- **감사 로그** — `audit.index`
- **휴지통 전체** — 목록 조회·복원·완전삭제 (아래 5절)

### 2-5. 본인 데이터 열람 예외 (권한 무관 허용)

일반 직원 대시보드가 빈 화면이 되는 것을 막기 위해 아래는 본인 범위로만 유지한다.
이미 컨트롤러가 본인 스코프를 강제하고 있다.

- `notifications.*` — SQL에 `user_id = Auth::id()` 고정
- `performance.user` — `Scope::canViewUserPerformance` (본인 또는 view_all)
- `halfyear.index`, `bonus.index`, `bonus.history` — `performance.view_all` 없으면 본인 강제
- `targets.index`, `targets.goal.history/progress` — `is_public=1` + 본인/본인부서
- `password.change`, `password.update`, `logout`

---

## 3. 권한 데이터 구조

### 3-1. 스키마

```sql
CREATE TABLE IF NOT EXISTS `edencrm_employee_permissions` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `section`      VARCHAR(20)  NOT NULL COMMENT 'sales|field|analytics',
  `resource_key` VARCHAR(50)  NOT NULL COMMENT '고정 키 — 메뉴명 변경과 무관',
  `can_read`     TINYINT(1)   NOT NULL DEFAULT 0,
  `can_write`    TINYINT(1)   NOT NULL DEFAULT 0,
  `can_delete`   TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_by`   INT UNSIGNED NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_perm` (`user_id`, `resource_key`),
  KEY `idx_emp_perm_user` (`user_id`),
  KEY `idx_emp_perm_resource` (`resource_key`),
  CONSTRAINT `fk_edencrm_emp_perm_user`
    FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edencrm_emp_perm_updater`
    FOREIGN KEY (`updated_by`) REFERENCES `edencrm_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3-2. 정규화 테이블을 택한 이유 (JSON 단일 컬럼 대비)

1. **무결성** — `UNIQUE(user_id, resource_key)`로 중복 조합을 DB가 차단. JSON은 애플리케이션
   버그로 중복 키가 들어가도 막을 수단이 없다.
2. **정리 자동화** — FK `ON DELETE CASCADE`로 "직원 삭제 시 권한 데이터 정리" 요구가 자동 충족.
3. **역방향 검색** — "계약 삭제 권한 보유자 전원"을 인덱스 질의로 즉시 뽑을 수 있다. 감사·점검에
   필수인데 JSON에서는 전 행 스캔 + 파싱이 필요하다.
4. **변경 추적** — 행 단위 `updated_at`/`updated_by`로 어떤 리소스가 언제 누구에 의해 바뀌었는지
   남는다. JSON은 컬럼 전체가 한 덩어리라 diff를 별도 계산해야 한다.
5. **호환성** — 카페24 공유호스팅 MySQL 버전에 따라 JSON 함수 지원이 갈린다. 정규화 테이블은
   버전 의존이 없다.

비용은 조회 시 행이 늘어나는 것뿐이며, 사용자당 최대 10행이라 요청당 1회 조회로 전량 캐시된다.

### 3-3. 기존 테이블 처리

- `edencrm_permissions` / `edencrm_role_permissions` — **유지**. perm_key 카탈로그와
  마이그레이션 소스로 계속 쓴다. 판정에는 더 이상 사용하지 않는다.
- `edencrm_user_permissions` — **판정에서 제외**. 기존 행은 마이그레이션 시 유효 권한 계산에
  반영한 뒤 그대로 보존한다(롤백 대비, 삭제하지 않음).

---

## 4. 판정 엔진

### 4-1. 신설 모듈 `app/core/Perm.php`

```php
Perm::isSuperAdmin(?array $user = null): bool   // roles.role_key === 'super_admin'
Perm::can(int $userId, string $resourceKey, string $action): bool  // action: read|write|delete
Perm::of(int $userId): array                    // resource_key => [r,w,d]  (요청 단위 캐시)
Perm::resources(): array                        // 매트릭스 정의(표시명·section·허용 action)
Perm::registry(): array                         // perm_key => [resource_key, action]
Perm::requireSuperAdmin(string $context): void  // 감사 로그 + 403(JSON/HTML 분기)
```

`isSuperAdmin`은 **역할 컬럼 기준**으로만 판정한다. 특정 user id를 코드에 하드코딩하지 않는다.

### 4-2. `Rbac::can()` 재배선

```
Rbac::can($perm)
  ├─ 미로그인            → false
  ├─ super_admin         → true                       (기존 동작 유지)
  ├─ registry에 없는 키  → false                       (기본 거부, 오타 = 거부)
  ├─ ADMIN_ONLY 목록     → false                       (2-4절 전용 perm)
  └─ [resource, action] → Perm::can(uid, resource, action)
```

기존 호출부(`Rbac::can` 47곳, 라우터 `perm` 옵션 100여개, 뷰 `can()` 헬퍼)는 **한 줄도 바꾸지
않는다.** 판정 소스만 바뀐다.

### 4-3. perm_key ↔ (리소스, 액션) 레지스트리

| perm_key | 리소스 | 액션 |
|---|---|---|
| customer.view / customer.export | sales.customers | read |
| customer.manage | sales.customers | write |
| customer.delete | sales.customers | delete |
| pipeline.view | sales.leads | read |
| pipeline.manage | sales.leads | write |
| **pipeline.delete** (신설) | sales.leads | delete |
| quote.view | sales.quotes | read |
| quote.manage | sales.quotes | write |
| **quote.delete** (신설) | sales.quotes | delete |
| contract.view | sales.contracts | read |
| contract.manage | sales.contracts | write |
| **contract.delete** (신설) | sales.contracts | delete |
| project.view_all / project.view_assigned | field.projects | read |
| project.manage / project.assign | field.projects | write |
| **project.delete** (신설) | field.projects | delete |
| process.move | field.process_board | write |
| **process.view** (신설) | field.process_board | read |
| **process.delete** (신설) | field.process_board | delete |
| schedule.view_all | field.schedules | read |
| schedule.manage | field.schedules | write |
| **schedule.delete** (신설) | field.schedules | delete |
| worklog.view_all | field.worklogs | read |
| worklog.create / worklog.confirm | field.worklogs | write |
| **worklog.delete** (신설, 예약) | field.worklogs | delete |
| cost.manage | field.costs | write |
| **cost.view** (신설) | field.costs | read |
| **cost.delete** (신설) | field.costs | delete |
| report.view / report.export / finance.view | analytics.reports | read |

**ADMIN_ONLY**(항상 super_admin): `payment.manage`, `performance.view_all`, `bonus.manage`,
`attendance.manage`, `staff.view`, `staff.manage`, `settings.manage`, `audit.view`,
`trash.manage`(신설).

### 4-4. 라우트 변경

1. 삭제 라우트를 신설 delete 키로 교체 — `quotes.delete`, `contracts.delete`,
   `projects.delete`, `pipeline.delete`, `schedule.delete`, `process.memo.delete`,
   `costs.cancel`, `customers.license.delete`.
2. perm 없는 30개 라우트에 perm 부여:
   - `home`/`dashboard`/`dashboard.data` → 로그인만 + **컨트롤러 내 위젯별 권한 필터**(6절)
   - `projects.index/show/upload`, `files.download` → `project.view_all` 계열 read
   - `process.board`, `process.memo.list`, `process.history` → `process.view`
   - `schedule.index/data` → `schedule.view_all`
   - `worklogs.index/show` → `worklog.view_all`
   - `costs.export` → `cost.view`
   - `performance.index`, `halfyear.index`, `bonus.index`, `bonus.history`,
     `targets.index`, `targets.goal.history/progress`, `notifications.*` → 2-5절 본인 예외 유지
3. `reports.attendance`/`reports.attendance_export` → ADMIN_ONLY로 승격
   (전 직원 출근 통계는 사장 전용).

### 4-5. 종속 규칙 (UI + 서버 이중 검증)

1. 쓰기 부여 → 읽기 자동 활성
2. 삭제 부여 → 읽기 자동 활성
3. 읽기 해제 → 쓰기·삭제 동시 해제
4. 읽기 없이 쓰기/삭제만 있는 조합은 **저장하지 않음**(서버가 정규화 후 저장)
5. 삭제 권한은 휴지통 이동까지만 — 복원·완전삭제로 확장되지 않음
6. 매트릭스 UI에 복원·완전삭제 항목 없음
7. 프론트 값 불신 — 서버가 조합 재검증
8. 미등록 리소스 키 요청은 거부(저장 자체를 400으로 반려)

### 4-6. 캐시 무효화

`Perm::of()`는 요청 단위 static 캐시만 사용한다(`Rbac::$permCache`와 동일 수명).
권한 변경은 다음 요청부터 즉시 반영되며 세션에 권한을 저장하지 않는다.
`$_SESSION['role_key']`는 표시용이며 **판정에 사용하지 않는다**(쿠키·세션 위조 차단).
권한 저장 시 `Rbac::reset()` + `Perm::reset()`을 호출한다.

---

## 5. 휴지통 정책

### 5-1. 단계별 권한

| 단계 | 권한 |
|---|---|
| 1. 활성 데이터 삭제(휴지통 이동) | 해당 리소스 `delete` 권한 |
| 2. 휴지통 목록 조회 | **super_admin 전용** |
| 3. 복원 | **super_admin 전용** |
| 4. 완전삭제 | **super_admin 전용** |

`?trash=1` 목록 진입 자체를 `Perm::requireSuperAdmin()`으로 막는다. 라우트 perm
(`trash.manage`, ADMIN_ONLY)과 컨트롤러 내부 가드를 **이중**으로 건다.

```php
// 모든 restore/purge 액션 선두
Perm::requireSuperAdmin('quotes.purge');
```

일반 삭제 권한만 확인하는 구현은 금지한다.

### 5-2. 휴지통 확대

고객·영업기회는 소프트 삭제되면서 복원 경로가 없어 실질 데이터 유실이다. 견적·계약·프로젝트와
동일한 패턴으로 휴지통·복원·완전삭제를 신설한다(전부 super_admin 전용).

- `customers.restore` / `customers.purge` — 완전삭제 차단 조건: 견적·계약·프로젝트·영업기회 참조
- `pipeline.restore` / `pipeline.purge` — 차단 조건: 견적 참조

목표(goals)·보너스(site_bonuses)는 별도 이력 원장이 있고 사장 전용 화면이라 이번 범위에서 제외한다.

### 5-3. 완전삭제 안전장치

- super_admin 세션 재검증(`Perm::requireSuperAdmin`)
- CSRF 검증(라우터가 POST 전부 강제)
- 대상 데이터명 + 식별번호를 확인 문구에 표시
- 2단계 확인 — 1차 경고, 2차 대상명 확인
- 연관 데이터 삭제 범위 명시
- 트랜잭션 처리, 실패 시 전체 롤백
- 감사 로그(대상 삭제 후에도 잔존 — `audit_logs.entity_id`는 FK 없음)

문구 예시:

```
이 작업은 견적 Q-2026-001 과 관련 버전·항목 전부를 서버에서 완전히 삭제합니다.
완전삭제 후에는 복구할 수 없습니다.
```

---

## 6. 대시보드 권한 처리

`DashboardController::index()`의 `switch($u['role'])` + `default: renderBoss()`를 제거하고
**보유 권한 기반 위젯 조립**으로 바꾼다.

| 위젯 | 요구 권한 |
|---|---|
| kpi(확정매출·입금·원가·순이익), finance, cash | `analytics.reports` read **또는** super_admin |
| funnel, salesKpi, stageGroupDist | `sales.leads` read |
| 최근 견적 | `sales.quotes` read |
| 최근 계약, 계약 금액 | `sales.contracts` read |
| 고객 수 | `sales.customers` read |
| board, process, processChips | `field.process_board` read |
| 프로젝트 수·지연·미배정·내 프로젝트 | `field.projects` read |
| workstatus, scheduleSummary | `field.schedules` read |
| attend(출근 통계), perf(직원 성과), workload | super_admin |
| goal, 알림, 본인 일정 | 항상(본인 범위) |

권한 없는 위젯은 **0으로 표시하지 않고 위젯 자체를 렌더링하지 않으며**, 백엔드 쿼리도 실행하지
않는다. `dashboard.data` JSON도 동일 규칙으로 키를 제외한다.

`monthlyTrend`(6개월 전사 매출·순이익)는 `analytics.reports` read 없으면 응답에서 제외한다.

---

## 7. 직원 권한 설정 UI

`app/views/staff/form.php`에 업무 권한 설정 영역을 추가한다. super_admin만 접근·변경 가능
(`staff.form`/`staff.save`가 이미 ADMIN_ONLY).

```
업무 권한 설정                      [전체 선택] [전체 해제]
[영업 권한]                                  [영역 전체]
기능명          읽기        쓰기       삭제
고객 CRM         □          □          □      [행 전체]
영업기회         □          □          □      [행 전체]
견적             □          □          □      [행 전체]
계약             □          □          □      [행 전체]
                [열 전체]  [열 전체]  [열 전체]
[현장 권한]                                  [영역 전체]
프로젝트 / 공정 보드 / 현장 일정 / 작업일지 / 비용·원가
[분석 권한]
리포트·손익      □          –          –
```

기능: 전체 선택·해제, 영역별 선택, 행 전체, 열 전체, 현재 저장 권한 표시, 종속 규칙 자동 반영,
저장 성공·실패 알림, 저장 중 중복 요청 방지(버튼 disable + in-flight 플래그),
미저장 이탈 경고(`beforeunload`), 모바일 카드형 전환(가로 스크롤 대신 카드).

**노출하지 않는 항목**: 정산·분석 관리, 직원 관리, 권한 관리, 시스템 설정, 감사 로그,
휴지통 복원·완전삭제, 최고운영자 권한.

super_admin 대상 직원에게는 매트릭스 대신 "최고운영자는 전체 권한을 항상 보유합니다" 안내를
표시하고 편집을 막는다(자기 권한 실수 제거 방지).

### 7-1. 프론트엔드 노출 제어

- `Nav::items()` — 리소스 read 권한으로 메뉴 필터
- 목록·상세의 등록·수정 버튼 — write 권한
- 삭제 버튼 — delete 권한
- 공정 보드 슬라이더·인라인 수정 — write 권한 없으면 `readonly`/`disabled` + 이벤트 미바인딩
- 휴지통·복원·완전삭제 버튼 — super_admin만

UI 숨김은 편의일 뿐이며 서버 검증을 대체하지 않는다.

---

## 8. 감사 로그

기존 `edencrm_audit_logs`를 재사용한다(별도 테이블 신설 없음).

| action | entity | 기록 시점 |
|---|---|---|
| `permission_change` | `employee_permissions` | 직원 권한 저장(변경 전/후 매트릭스 diff) |
| `access_denied` | `permission` | 권한 없는 접근 시도(기존 `Rbac::require`가 이미 기록) |
| `superadmin_denied` | `permission` | 일반 직원의 휴지통·분석·관리 접근 시도 |
| `trash_move` | 각 엔티티 | 활성 → 휴지통 |
| `trash_restore` | 각 엔티티 | 복원 |
| `trash_purge` | 각 엔티티 | 완전삭제(대상 삭제 후에도 잔존) |

기록 항목: 실행 사용자(`user_id`), 대상(`entity`,`entity_id`), 작업(`action`),
변경 전(`before_json`)·후(`after_json`), 시간(`created_at`), IP·UA.
`Audit::mask()`가 password·토큰류를 이미 제거한다. DB 접속 정보는 로그에 넣지 않는다.

---

## 9. 기존 직원 권한 마이그레이션

운영 계정·데이터를 삭제하거나 초기화하지 않는다.

절차:

1. 각 사용자의 **현재 유효 perm 집합**을 계산 — `role_permissions ∪ user_permissions(grant) − user_permissions(deny)`
2. 레지스트리로 `(resource_key, action)`에 사상
3. 종속 규칙 정규화(write/delete 있으면 read ON)
4. `employee_permissions`에 UPSERT (`ON DUPLICATE KEY UPDATE`) — 멱등
5. super_admin은 행을 만들지 않음(코드가 무조건 허용)
6. 변환 전후 결과를 `docs/` 리포트로 기록

역할별 예상 결과:

| 역할 | 이전 | 이후 |
|---|---|---|
| super_admin | 전체 | 전체 (변화 없음) |
| sales_manager | 고객·영업기회·견적·계약 전체, 프로젝트 읽기, 리포트, 손익 | **동일 유지** (analytics.reports read 포함) |
| site_manager | 고객 읽기, 프로젝트·공정·일정·작업일지·비용 | 동일 유지 |
| staff | 프로젝트 읽기(배정), 작업일지 쓰기 | 동일 유지 |
| accountant | 고객·계약 읽기, 프로젝트 읽기, 손익, **정산**, 비용, 리포트 | **정산(payment.manage) 상실** — 사장 전용으로 이관 |

판단이 어려운 계정은 최소 권한(전부 거부)을 적용하고 보고한다.

---

## 10. R16 수정사항

### 10-1. 버튼 리네임

| 위치 | 이전 | 이후 |
|---|---|---|
| `app/views/halfyear/index.php:38` | `보너스 지급 현황` | `지급 이력` |
| `app/views/bonus/index.php:17` | `반기 보너스 지급 현황` | `이전으로` |
| `app/views/bonus/index.php:13` (H1) | `보너스 지급 현황` | `지급 이력` |
| `app/views/bonus/history.php:46` | `보너스 지급 현황` | `지급 이력` |

H1을 함께 바꾸는 이유: 버튼명과 도착 페이지 제목이 일치해야 한다는 명칭 통일 원칙.

### 10-2. 명칭 통일 (동일 기능·다른 이름만)

| 표준 | 통합 대상 |
|---|---|
| `CSV 다운로드` | CSV 내보내기(customers/index.php:22), CSV 출력(projects/_tab_costs.php:24) |
| `등록` | 작성·생성·추가·새 → `+ 일정 등록`, `+ 작업일지 등록`, `+ 예외 프로젝트 등록`, `+ 영업기회 등록`, `+ 고객 등록` |
| `수정` | 편집(settings/stages.php:92), 정보 수정(staff/show.php:26) |
| `목록으로` | 목록, ← 직원 목록, ← 직원 성과 목록, 보드(pipeline/show.php:34) |
| `검색` | 조회(bonus·halfyear·targets·reports 6곳) |
| `지급 처리` | 지급처리, 지급완료 처리 |
| `+ ` 접두 | 신규 등록 버튼 전체에 일관 적용 |

### 10-3. 중의성 해소 (오클릭 위험)

`취소`가 "폼 닫기"(12곳)와 "비가역 무효화"(입금·보너스·비용)로 동시에 쓰여, 확인 대화상자에
`취소`(닫기)와 `취소 처리`(실행)가 나란히 뜬다. 무효화 쪽을 분리한다.

| 위치 | 이전 | 이후 |
|---|---|---|
| `bonus/index.php:119` | `취소` | `보너스 무효` |
| `contracts/show.php:172` | `취소` | `입금 무효` |
| `projects/_tab_costs.php:110` | `취소` | `비용 무효` |
| 각 확인 대화상자 okLabel | `취소 처리` | `무효 처리` |
| `staff/index.php:100` | `비번초기화` | `비밀번호 재발급` |

`초기화`는 필터 초기화 용도로만 남긴다.

용어집은 `docs/UI_GLOSSARY.md`에 문서화한다.

---

## 11. QA 계획

### 11-1. 테스트 계정 (prefix `qa_`)

| 계정 | 권한 |
|---|---|
| qa_a | 영업 읽기만 |
| qa_b | 영업 읽기·쓰기 (삭제 차단) |
| qa_c | 영업 읽기·쓰기·삭제 |
| qa_d | 현장 읽기·쓰기 |
| qa_e | 고객 CRM만 (견적·계약 차단) |
| qa_f | 업무 권한 전무 |
| qa_g | 최고운영자(기존 admin 재사용) |
| qa_h | 권한 변경 즉시 반영 검증용 |

### 11-2. 검증 항목

- 권한별 UI 노출 / URL 직접 접근 / POST 위조 / AJAX·API 직접 호출
- 휴지통 목록·복원·완전삭제 우회(URL·POST·DELETE·개발자도구)
- 일반 직원의 분석·관리·정산 접근
- 다른 직원 ID로 권한 변경(수평·수직 상승)
- CSRF 없는 요청, 세션 만료 후 요청, 리소스 ID 조작
- 완전삭제 재전송·동시 요청
- 클라이언트 쿠키의 role 값 변조
- 최고운영자 전체 회귀(17절 전 기능)
- PC·모바일 레이아웃
- PHP Warning/Notice/Fatal, SQL 오류, 브라우저 콘솔 오류 0건

QA 종료 후 테스트 계정·데이터 전량 삭제. 기존 운영 데이터는 수정·삭제하지 않는다.

---

## 12. 운영 배포

1. `deploy/backup.sh` — 운영 파일 FTP 미러 백업
2. 운영 DB 백업(PDO 덤프, `edencrm_%` 한정)
3. 기존 데이터 건수 기록(users·customers·leads·quotes·contracts·payments·projects)
4. `database/cafe24/013_r16_permissions.sql` 작성 — CREATE TABLE IF NOT EXISTS + UPSERT,
   재실행 안전. DROP/TRUNCATE/CREATE DATABASE 없음
5. `php deploy/run_migration.php database/cafe24/013_r16_permissions.sql --dry` → 실행
6. `./deploy/deploy.sh` (CONFIRM=yes) — 운영 설정 파일·업로드 폴더 보존, mirror --delete 미사용
7. `./deploy/verify.sh` + 실서버 세션 검수(최고운영자·일반 직원 각각)
8. 건수 재확인, FK 무결성 확인, 오류 로그 점검

롤백 조건: 최고운영자 로그인 불가·권한 손상, 일반 직원의 분석·관리·휴지통 접근 가능,
데이터 손실, 마이그레이션 오류, 주요 페이지 500.

---

## 13. 작업 분해 (하네스)

| ID | 작업 | 담당 |
|---|---|---|
| T1 | 수정사항(버튼 리네임·명칭 통일) | frontend |
| T2 | 권한 스키마·판정엔진(Perm/isSuperAdmin/레지스트리) | backend |
| T3 | 라우트·컨트롤러 서버단 강제 + V1~V12 취약점 | backend |
| T4 | 휴지통 super_admin 전용 + 고객·영업기회 휴지통 신설 | backend |
| T5 | 권한 매트릭스 UI·메뉴/버튼 노출 제어 | frontend |
| T6 | 대시보드 권한 필터링 | frontend |
| T7 | 로컬 QA(A~H)·보안 우회·회귀 | qa |
| T8 | 운영 배포·DB 마이그레이션·실서버 검수 | deployer |

파일 소유권 분리: backend = `app/core/*`, `app/routes.php`, `app/controllers/*`,
`database/**`. frontend = `app/views/**`, `public/assets/**`, `app/core/Nav.php`.
겹치는 컨트롤러(`StaffController`, `DashboardController`)는 backend가 먼저 끝낸 뒤 frontend가 착수한다.
