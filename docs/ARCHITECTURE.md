# EDEN CRM — 아키텍처 설계서

도장회사 내부 CRM·영업·공정관리 시스템. PHP 8.2+ / MySQL 8+ 기준, 프레임워크 없는 경량 자체 구조.
Cafe24 등 일반 PHP 호스팅 배포를 전제로 하며 mod_rewrite 에 의존하지 않는다.

## 1. 기술 원칙

- PHP 8.2+, PDO Prepared Statement 만 사용 (문자열 조립 쿼리 금지)
- 세션 기반 인증, 모든 변경 요청 CSRF 검증, 모든 요청 서버측 권한 재검증
- 외부 프레임워크 미도입. 프론트 라이브러리는 Chart.js 4, SortableJS 만 로컬 번들
- 캘린더/스케줄러는 자체 경량 구현 (FullCalendar 리소스 뷰는 유료라 배제)
- 업로드 파일은 DocumentRoot 밖 저장, 다운로드는 PHP 스크립트 경유

## 2. 폴더 구조

```
eden_crm/
├── public/                  # DocumentRoot (php -S 127.0.0.1:8080 -t public)
│   ├── index.php            # 프론트 컨트롤러 (유일한 진입점)
│   ├── assets/css/app.css   # 전체 공통 스타일 (단일 파일)
│   ├── assets/js/           # app.js(공통), kanban.js, scheduler.js, charts.js ...
│   └── assets/vendor/       # chart.umd.js, Sortable.min.js (로컬 복사본)
├── app/
│   ├── bootstrap.php        # 설정 로드→세션→core require→라우팅 준비
│   ├── config/config.php    # 공통 설정 + config.local.php 로드
│   ├── config/config.local.php   # DB 접속 정보 등 (git 제외, .example 제공)
│   ├── routes.php           # 라우트 테이블 (아래 4절)
│   ├── core/                # Db, Auth, Rbac, Csrf, View, Response, Audit, Util, Upload
│   ├── controllers/         # XxxController.php — 클래스, 액션 = public 메서드
│   ├── models/              # 테이블 단위 쿼리 함수 모음 (정적 클래스)
│   └── views/
│       ├── layout/          # header.php(사이드바+톱바), footer.php, login-layout
│       └── <module>/        # customers/, pipeline/, projects/ ...
├── storage/
│   ├── uploads/             # 업로드 원본 (docroot 밖, 랜덤 파일명 저장)
│   └── logs/                # PHP 에러 로그
├── database/
│   ├── schema.sql           # 전체 스키마 (DROP 없이 CREATE TABLE IF NOT EXISTS 금지 — 명시적 생성)
│   ├── seed_core.sql        # 필수 시드: 역할·권한·단계·설정 (운영에도 필요)
│   └── seed_dev.sql         # 더미 데이터 (운영 배포 시 제외)
└── docs/                    # 본 문서, PLAN.md, README
```

## 3. 요청 처리 흐름

1. 모든 요청은 `public/index.php?r=<route>` 로 진입 (예: `?r=customers.index`, POST 동일)
2. `bootstrap.php` 가 설정·세션·core 로드
3. `routes.php` 의 라우트 테이블에서 `r` 값 조회 → 없으면 404
4. 라우트에 선언된 `perm` 을 **라우터가 강제** (`Rbac::require`) — 컨트롤러가 깜빡해도 차단됨
5. `login`, `logout` 외 모든 라우트는 로그인 필수 (라우터에서 일괄 처리)
6. POST/AJAX 변경 요청은 라우터에서 `Csrf::verify()` 일괄 수행 (GET 은 조회 전용 — 변경 GET 금지)
7. 컨트롤러 액션 실행 → `View::render()` 또는 `Response::json()`

라우트 테이블 형식 (`app/routes.php`):

```php
return [
  'customers.index' => ['CustomersController', 'index',  'perm' => 'customer.view'],
  'customers.save'  => ['CustomersController', 'save',   'perm' => 'customer.manage', 'method' => 'POST'],
  'login'           => ['AuthController', 'loginForm',   'public' => true],
  ...
];
```

## 4. 코어 API (시그니처 고정 — 모든 모듈이 이대로 사용)

```php
Db::pdo(): PDO                                    // 싱글턴, utf8mb4, ERRMODE_EXCEPTION
Db::run(string $sql, array $params = []): PDOStatement
Db::all($sql, $params): array   Db::one($sql, $params): ?array   Db::val($sql, $params): mixed
Db::insert(string $table, array $data): int       // lastInsertId 반환
Db::update(string $table, array $data, string $where, array $params): int

Auth::attempt(string $loginId, string $password): bool  // 실패기록·잠금·세션재발급 포함
Auth::user(): ?array          // 현재 사용자 행 (users + role_key)
Auth::id(): int               Auth::check(): bool
Auth::logout(): void          // 세션 완전 파기
Auth::requireLogin(): void    // 미로그인 → login 리다이렉트 (AJAX 는 401 JSON)

Rbac::can(string $perm): bool             // super_admin 은 항상 true
Rbac::require(string $perm): void         // 불가 시 403 페이지 / 403 JSON
Rbac::isRole(string ...$roleKeys): bool

Csrf::token(): string      Csrf::field(): string   // hidden input HTML
Csrf::verify(): void       // POST 본문 _csrf 또는 X-CSRF-Token 헤더 검증, 실패 시 419 종료

View::render(string $tpl, array $data = [], string $layout = 'default'): void
  // $tpl = 'customers/index' → app/views/customers/index.php, e() 이스케이프 필수

Response::json(mixed $data, int $status = 200): never    // {ok:true, data:...} 형식
Response::error(string $msg, int $status = 400): never   // {ok:false, error:msg}
Response::redirect(string $route, array $params = [], ?string $flash = null): never

Audit::log(string $action, string $entity, ?int $entityId, ?array $before, ?array $after): void

Util::e(?string $s): string               // htmlspecialchars 단축 (뷰에서 e() 전역함수로 노출)
Util::money(?float $n): string            // 12,345,678 형식 (원)
Util::pct(?float $n): string              // 12.3% 형식, null → '-'
Util::paginate(int $total, int $page, int $per = 20): array  // [pages, offset, ...]
Util::input(string $key, $default = null)  Util::postInt/postFloat/postStr(...)  // 검증 헬퍼

Upload::save(array $file, string $subdir, array $allowedExt): array
  // 확장자·MIME·크기(10MB) 검증, 이중확장자 거부, storage/uploads/{subdir}/{랜덤}.ext 저장
  // 반환 [path, original_name, size, mime]
Upload::send(int $fileId): never          // 권한 검사 후 readfile 스트리밍
```

## 5. 인증·세션 정책

- 비밀번호 `password_hash()` (bcrypt), 검증 `password_verify()`
- 로그인 5회 연속 실패 시 15분 잠금 (`login_attempts` 테이블, IP+계정 기준)
- 로그인 성공 시 `session_regenerate_id(true)` — 세션 고정 방어
- 세션 쿠키: httponly, samesite=Lax. 유휴 60분 초과 시 자동 로그아웃
- `users.status='inactive'` 또는 soft-delete 계정 로그인 차단 (세션 검증 시에도 매 요청 확인)
- `must_change_password=1` 이면 비밀번호 변경 페이지 외 접근 차단
- 마지막 로그인 일시·IP 기록. 로그인/로그아웃/실패는 감사 로그 기록

## 6. 권한 모델 (RBAC)

역할(role_key): `super_admin`(사장) · `sales_manager`(영업 관리자) · `site_manager`(현장 관리자) · `staff`(일반 직원) · `accountant`(회계)

권한 키 (permissions.perm_key):

| 키 | 의미 |
|---|---|
| customer.view / customer.manage / customer.delete / customer.export | 고객 열람/등록·수정/삭제/CSV |
| pipeline.view / pipeline.manage | 영업기회 열람 / 카드 생성·이동·수정 |
| quote.view / quote.manage | 견적 |
| contract.view / contract.manage | 계약 |
| project.view_all / project.view_assigned / project.manage / project.assign | 프로젝트 전체열람/담당열람/생성·수정/직원배정 |
| process.move | 공정 단계 드래그 이동 |
| schedule.view_all / schedule.manage | 일정 전체 열람 / 일정 생성·이동 (본인 일정 열람은 로그인만으로 허용) |
| worklog.create / worklog.view_all / worklog.confirm | 작업일지 작성/전체열람/관리자확인 |
| cost.manage | 비용(원가) 등록·수정 |
| finance.view / payment.manage | 손익·미수금 열람 / 입금·정산 관리 |
| report.view / report.export | 리포트 열람 / CSV 다운로드 |
| performance.view_all | 전 직원 성과·기여도 열람 (본인 성과는 항상 열람 가능) |
| staff.view / staff.manage | 직원 목록 / 계정 생성·수정·비활성화·비번초기화 |
| settings.manage | 시스템 설정, 영업단계·공정단계 관리 |
| audit.view | 감사 로그 열람 |

역할별 부여 (seed_core.sql 에서 시딩):

- **super_admin**: 전체 (코드상 `Rbac::can` 이 무조건 true — role_permissions 미조회)
- **sales_manager**: customer.view/manage/delete/export, pipeline.*, quote.*, contract.*, project.view_all, report.view/export, finance.view
- **site_manager**: customer.view, project.view_assigned, project.assign, process.move, schedule.view_all, schedule.manage, worklog.*, cost.manage
- **staff**: project.view_assigned, worklog.create (+본인 일정·본인 성과)
- **accountant**: customer.view, contract.view, project.view_all, finance.view, payment.manage, cost.manage, report.view, report.export

**데이터 범위 규칙 (IDOR 방지):** 목록·상세·수정 쿼리는 권한에 따라 WHERE 를 강제한다.
`project.view_all` 이 없으면 프로젝트 조회는 반드시 `배정(project_assignments) 또는 담당(sales_user_id/site_manager_id)` 조건 포함.
고객·견적·계약 상세도 동일 원칙. 타인 성과·급여성 정보는 `performance.view_all` 필요.

## 7. DB 규칙

- DB명 `eden_crm`, utf8mb4_unicode_ci, InnoDB, 모든 테이블 `id INT UNSIGNED AUTO_INCREMENT PK`
- 컬럼: snake_case, FK 는 `<참조테이블단수>_id`, 금액 `DECIMAL(14,0)` (원 단위), 비율 `DECIMAL(5,2)`
- 공통 컬럼: `created_at DATETIME DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME ... ON UPDATE`
- soft delete 는 복구·이력 보존이 필요한 곳만: users, customers, projects, quotes, contracts → `deleted_at DATETIME NULL`
- 외래키 ON DELETE RESTRICT 기본 (기록 소실 방지), 로그성 테이블만 CASCADE 허용
- 조회 패턴에 맞는 인덱스: 상태·담당자·날짜 컬럼

### 테이블 목록 (28개)

users, departments, roles, permissions, role_permissions, user_permissions,
customers, customer_contacts(추가 연락처), customer_activities(상담·활동 타임라인),
pipeline_stages, leads(영업기회), quotes, quote_versions, quote_items,
contracts, payments(입금), projects, process_stages, project_process_history,
project_assignments, schedules, work_logs, work_log_photos, project_files,
costs(비용), targets(월별 목표실적), notifications, audit_logs, settings, login_attempts, holidays

핵심 관계:
- leads.customer_id → customers, leads.stage_id → pipeline_stages, leads.sales_user_id → users
- quotes.lead_id/customer_id, quote_versions.quote_id(버전 이력), quote_items.quote_version_id
- contracts.quote_id/customer_id → projects.contract_id (계약→프로젝트 전환)
- projects.process_stage_id → process_stages, projects.status(코드 문자열, 9-2절 상태값)
- project_assignments(project_id, user_id, role, start_date, end_date, contribution_pct)
- costs.project_id(예상/실제 구분 컬럼 `type ENUM('estimate','actual')`, 항목 category)
- payments.contract_id (계약금/중도금/잔금 pay_type, 예정일·입금일 → 미수금 산출)

## 8. 계산 산식 (BackEnd 공통 — `app/core/Calc.php` 로 단일화)

```
예상 순이익      = 예상 계약금액 − 예상 총원가
예상 순이익률(%) = 계약금액 > 0 ? 순이익 ÷ 계약금액 × 100 : null   ← 0 나눗셈 금지, null 은 화면에 '-'
가중 예상 매출   = 예상 계약금액 × 성공확률(%) ÷ 100
실제 순이익      = 실제 매출(계약금액) − 실제 총원가            ← 음수(적자) 그대로 표시
매출 달성률(%)   = 목표 > 0 ? 실제 ÷ 목표 × 100 : null
직원 수익 기여액 = 프로젝트 실제 순이익 × 기여도(%) ÷ 100
미수금          = 계약금액 − Σ(입금완료 payments.amount)
```

기여도: project_assignments.contribution_pct 합이 프로젝트당 100 이 되도록 저장 시 검증
(배분 방식 선택: 주담당 100 / 비율 직접입력 / 역할별 기본배분 — projects.contribution_mode).

## 9. UI 규약

- 화이트·그레이 기반 기업용 SaaS. 포인트 컬러 `#1a56db`(파랑) 1색 + 상태 색상(성공 초록/경고 주황/위험 빨강)
- PC: 좌측 고정 사이드바(240px) + 상단 툴바. 모바일(≤768px): 햄버거 → 오버레이 사이드바
- 과도한 라운드·그림자·그라데이션·이모지 UI 금지. 카드 radius 6px, border 1px #e5e7eb 기본
- 표: 가로 스크롤 컨테이너(`overflow-x:auto`), 긴 텍스트 `text-overflow:ellipsis`
- 금액 우측정렬·천단위 콤마, 퍼센트 소수1자리, 적자는 빨강
- 모든 목록: 검색 + 필터 + 정렬 + 서버측 페이지네이션 (per=20)
- 로딩 상태(버튼 disabled+스피너), 빈 상태(안내문+주요 액션 버튼), 오류 토스트 구현
- JS: `fetch` 사용, 공통 래퍼 `api(route, data)` (app.js) — CSRF 헤더 자동 첨부, `{ok:false}` 시 토스트
- 차트는 Chart.js, 칸반 DnD 는 SortableJS, 캘린더는 자체 구현(월 그리드 + 직원별 주간 타임라인)

## 10. 환경 설정 분리

- `config.php`: 공통 (앱명, 세션 정책, 업로드 제한, 타임존 Asia/Seoul)
- `config.local.php`: DB host/name/user/pass, APP_ENV(`local`/`production`), BASE_URL — **git 제외**
- `config.local.example.php` 제공. 운영 배포 시 display_errors=0, 로그 파일 기록
- 하드코딩된 절대 URL·계정정보 금지. 경로는 `BASE_PATH`/`url($route)` 헬퍼 사용

## 11. 더미 데이터 정책

- `seed_core.sql`: 역할·권한·영업단계 12·공정단계 18·기본설정·공휴일 — 운영 필수
- `seed_dev.sql`: 직원 12, 고객 55+, 영업기회 32+, 진행 프로젝트 20+, 완료 10+ (지연·적자·고수익·미수금 포함),
  일정 충돌 케이스, 작업일지 누락 프로젝트 포함. 운영 배포 시 이 파일만 제외하면 됨
- 테스트 계정: admin(슈퍼관리자) / 각 역할별 1개 이상. 초기 비밀번호는 README 에 명시 + 운영 전 변경 경고
