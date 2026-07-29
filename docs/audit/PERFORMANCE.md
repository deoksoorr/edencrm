# T8 — 성능 측정 및 최적화 감사 (2026-07-29)

> **원칙: 측정 없는 최적화 금지.** 이 문서의 모든 수치는 재현 가능한 실측이다.
> 추정치는 반드시 "(추정)" 으로 표시했고, 근거가 없는 항목은 아예 권고하지 않았다.

---

## 0. 측정 방법 — 왜 별도 DB 를 만들었나

운영 DB(2026-07-29 실측)는 **가장 큰 테이블이 audit_logs 412행**이고 payments 13행, projects 11행,
contracts 3행이다. 로컬 개발 DB는 그보다도 비어 있다(customers 1행, projects 0행).
**이 상태로는 어떤 라우트를 재도 전부 1~10ms 라 병목이 드러나지 않는다.**

그래서 측정 전용 DB `eden_crm_perf` 를 만들고 두 가지 규모로 같은 코드 경로를 측정했다.

| 규모 | 의미 | customers | contracts | projects | payments | costs | audit_logs | schedules |
|---|---|---|---|---|---|---|---|---|
| **S1** | 현재 운영 규모 근사 | 12 | 6 | 12 | 18 | 48 | 500 | 20 |
| **S2** | 성장 규모(연 300건 계약 × 3년 + 로그 누적) | 2,824 | 2,904 | 2,948 | 11,731 | 29,818 | 196,861 | 20,031 |

* 시더: `scripts/audit/perf_seed.php` (DB 이름이 `_perf` 로 끝나지 않으면 실행 거부 — 운영·개발 DB 오염 방지)
* 프로브: `scripts/audit/perf_probe.php`
* 쿼리별 분해: `scripts/audit/perf_slowq.php`
* 인덱스 전/후 벤치: `scripts/audit/perf_index_bench.php`
* 운영 MariaDB 가드 검증: `scripts/audit/perf_mariadb_guard_check.php` (READ ONLY 세션)

**쿼리 수 세는 법**: `SHOW SESSION STATUS` 델타는 쓰지 않았다. 요청마다 PHP 가 새 커넥션을 열어
세션 카운터가 초기화되고, GLOBAL 델타는 같은 MySQL 인스턴스의 다른 개발 서버 트래픽에 오염되기 때문이다.
대신 **전용 DB 계정(`eden_perf`)으로 앱을 띄우고 `mysql.general_log` 를 그 계정으로 필터**해
요청 1건이 실행한 문장을 **전부 원문 그대로** 채집했다. 그래서 아래 쿼리 수는 추정이 아니라 실측이다.

응답시간은 general_log 를 끈 상태로 7회 반복한 **중앙값**(PHP 내장 서버, 로컬 소켓 MySQL 9.6).
절대값이 아니라 **규모 간·수정 전후 비교값**으로 읽어야 한다.

---

## 1. Before 측정표

`admin`(super_admin) 로그인 상태. 쿼리 수는 규모와 무관하게 동일했다(= 데이터 양에 비례해
쿼리가 늘어나는 진짜 N+1 은 없고, **고정 개수의 무거운 쿼리**가 문제라는 뜻).

| 라우트 | 쿼리 수 | 고유 형태 | S1 응답(ms) | S2 응답(ms) | S2 증가율 | S2 응답 바이트 |
|---|---:|---:|---:|---:|---:|---:|
| `home` | **85** | 59 | 10.6 | **3,112.8** | **294배** | 46,580 |
| `performance.index` | 32 | 17 | 8.2 | **1,647.0** | **201배** | 16,911 |
| `reports.data` | 38 | 19 | 8.0 | **559.8** | 70배 | 567,786 |
| `halfyear.index` | 28 | 24 | 10.1 | 183.5 | 18배 | 341,427 |
| `process.board` | 13 | 13 | 4.6 | 106.6 | 23배 | **26,938,992** |
| `pipeline.index` | 11 | 11 | 3.2 | 105.4 | 33배 | **2,344,570** |
| `projects.index` | 7 | 7 | 6.0 | 89.4 | 15배 | 71,931 |
| `staff.index` | 12 | 12 | 7.2 | 75.6 | 11배 | 18,908 |
| `dashboard.data` | 20 | **4** | 7.2 | 68.9 | 10배 | 374 |
| `contracts.index` | 5 | 5 | 4.6 | 31.6 | 7배 | 50,177 |
| `quotes.index` | 6 | 6 | 2.7 | 20.5 | 8배 | 39,797 |
| `schedule.index` | 5 | 5 | 1.8 | 17.5 | 10배 | 247,213 |
| `customers.index` | 7 | 7 | 5.4 | 13.7 | 3배 | 47,032 |
| `reports.index` | 3 | 3 | 2.8 | 9.5 | 3배 | 18,200 |

**즉시 눈에 띄는 것 3가지**

1. `home` 이 **요청 1건에 85쿼리**. 고유 형태는 59개 — 나머지 26개는 같은 집계의 반복이다.
2. `dashboard.data` 는 20쿼리인데 **고유 형태가 4개뿐**. 즉 **16쿼리가 순수 중복**이다.
3. `process.board` 가 S2 에서 **27MB HTML** 을 뱉는다(목록 상한 없음). DB 시간은 69ms 뿐이라
   이건 쿼리 문제가 아니라 **렌더 상한 부재** 문제다.

---

## 2. 최대 병목 — `AccountingService::PAY_PROJECT_JOIN` 의 `OR` 조인

### 2.1 무엇이 문제인가

`app/core/AccountingService.php:415-419`

```php
private const PAY_PROJECT_JOIN =
    " LEFT JOIN contracts c ON c.id = pm.contract_id AND c.deleted_at IS NULL
      JOIN projects pj2 ON pj2.deleted_at IS NULL
           AND (pj2.id = pm.project_id OR (pm.contract_id IS NOT NULL AND pj2.contract_id = pm.contract_id))
      ";
```

조인 조건이 **서로 다른 두 컬럼에 대한 `OR`** 다. 이 형태는 어떤 인덱스로도 해결되지 않는다.
옵티마이저가 `projects` 를 인덱스로 좁힐 수 없어 **카테시안 곱을 만든 뒤 필터**한다.

S2 EXPLAIN(MySQL 9.6, `EXPLAIN FORMAT=TREE`) 실측:

```
-> Filter: ((pj2.id = pm.project_id) or ((pj2.contract_id = pm.contract_id) and (pm.contract_id is not null)))
    -> Inner hash join (no condition)          ← 조인 조건 없는 해시 조인 = 전체 조합
        -> Table scan on pj2  (rows=2948)
        -> Hash
            -> Index lookup on pm using idx_payments_status (status='paid')  (rows=9000)
```

`projects` 2,948행 × `payments(paid)` 9,000행 = **약 2,650만 조합**을 만들고 나서 걸러낸다.

운영(MariaDB 10.6.17)에서도 같은 계획이 나온다 — 읽기 전용 EXPLAIN 실측:

```
pj2  ref  idx_projects_deleted_at  rows=4    Using temporary; Using filesort
pm   ALL  (key=NULL)               rows=13   Using join buffer (flat, BNL join)
```

`pm` 이 `ALL` + BNL(블록 네스티드 루프) — 지금은 13행이라 공짜지만, **행 수에 정비례해 폭발**한다.

### 2.2 영향 범위

| 호출자 | 파일:줄 | 영향 라우트 |
|---|---|---|
| `employeeConfirmedRevenue()` | `app/core/AccountingService.php:424` | 개인 성과 |
| `employeeConfirmedByUser()` | `app/core/AccountingService.php:443` | `home`, `performance.index`, `halfyear.index` |
| `employeePaidByUser()` | `app/core/AccountingService.php:491` | `home`, 대시보드 직원 성과 |

### 2.3 재작성안과 실측 효과 — **동일 결과, 최대 57배**

`OR` 를 **두 개의 LEFT JOIN + COALESCE** 로 분해하면 양쪽 다 인덱스를 탄다
(`payments.project_id` → `idx_payments_project`, `projects.contract_id` → `uq_projects_contract` UNIQUE).

```sql
-- 현재 (느림)
JOIN projects pj2 ON pj2.deleted_at IS NULL
     AND (pj2.id = pm.project_id OR (pm.contract_id IS NOT NULL AND pj2.contract_id = pm.contract_id))
JOIN project_assignments pa ON pa.project_id = pj2.id AND pa.contribution_pct > 0

-- 제안 (동일 결과)
LEFT JOIN projects pjp ON pjp.id = pm.project_id          AND pjp.deleted_at IS NULL
LEFT JOIN projects pjc ON pjc.contract_id = pm.contract_id AND pjc.deleted_at IS NULL
JOIN project_assignments pa ON pa.project_id = COALESCE(pjp.id, pjc.id) AND pa.contribution_pct > 0
```

S2 실측 (반환값 4행 전부 소수점까지 일치 확인):

| 쿼리 | 현재 | 재작성 | 배율 |
|---|---:|---:|---:|
| `employeePaidByUser` (입금 기여) | 1,229.8 ms | **25.3 ms** | **48.6배** |
| `employeeConfirmedByUser` (귀속 매출) | 1,535.1 ms | **26.7 ms** | **57.5배** |

`home` 의 총 DB 시간 3,089.6ms 중 **2,714.7ms(88%)** 가 이 두 형태다.
재작성하면 DB 시간 약 427ms 로 떨어진다(추정 — 나머지 쿼리 실측 합 + 재작성 실측 합).
`performance.index` 는 1,662.1ms 중 1,571.8ms(94.5%)가 이 형태다.

> ⚠️ `pjp`/`pjc` 가 **동시에 매치되는 행이 있으면 결과가 달라진다.** 스키마상
> `payments.contract_id` 와 `project_id` 는 택1(R11 주석)이고 `uq_projects_contract` 가 UNIQUE 라
> 현재 데이터에서는 동시 매치가 불가능하지만, 적용 시 **양쪽 산식 결과 일치 회귀 테스트**를 붙일 것.
> 위 실측에서는 4개 사용자 전부 값이 일치했다.

---

## 3. 중복·반복 쿼리 (대시보드 집계)

### 3.1 `confirmedProfit` 이 `confirmedRevenue` 를 다시 계산한다 — 모든 집계가 정확히 2배

`dashboard.data` 실측: 총 20쿼리 / **고유 4형태**. `perf_slowq` 로 리터럴까지 포함해 세면
**모든 쿼리가 예외 없이 "× 2회"** 로 나온다.

```
#1  10.3 ms × 2회     #2  6.4 ms × 2회     #3  4.8 ms × 2회
#4   4.7 ms × 2회     #5  4.6 ms × 2회     #6  4.6 ms × 2회
```

원인 — `app/controllers/DashboardController.php:740-746`:

```php
foreach ($months as $ym) {           // 고정 6개월
    $rev    = AccountingService::confirmedRevenue($from, $to);   // ① 매출 집계
    $profit = AccountingService::confirmedProfit($from, $to);    // ② 내부에서 ①을 다시 실행 + 원가
}
```

6개월 × (매출 1 + 매출 1 + 원가 1) = **18쿼리**. 매출 6회는 순수 낭비다.

**수정**: `AccountingService::confirmedRevenue()` / `costTotal()` 에 요청 스코프 메모이제이션
(`static $memo[$from.'|'.$to]`) 추가. 두 메서드는 순수 읽기라 부작용이 없다.
→ `dashboard.data` 20쿼리 → **8쿼리(-60%)**, `home` 85 → 약 70쿼리(추정).

더 근본적으로는 6개월 루프 자체를 `GROUP BY DATE_FORMAT(pm.paid_date,'%Y-%m')` 한 방으로 접을 수 있다
(18쿼리 → 2쿼리). 단 `ReportsController::monthlyTrend`(`app/controllers/ReportsController.php:130-134`)가
"대시보드와 값이 같아야 한다"는 제약이 있으므로 **두 곳을 같은 헬퍼로 동시에** 바꿔야 한다.

### 3.2 같은 기간을 서로 다른 메서드로 3번 계산

`app/controllers/DashboardController.php` 에서 **이번 달(`Y-m-01` ~ `Y-m-t`)** 확정매출을 3곳이 각각 계산한다:

| 줄 | 호출 | 기간 |
|---|---|---|
| `:269` | `confirmedRevenue($mFrom, $mTo)` (bossKpi) | 이번 달 |
| `:560` | `confirmedRevenue($mFrom, $mTo)` (finance) | 이번 달 — **269와 완전 동일** |
| `:591` | `confirmedRevenue(date('Y-m-01'), date('Y-m-t'))` (goal) | 이번 달 — **동일 기간을 다른 표현으로** |

여기에 `:275`/`:568` 의 `confirmedProfit($mFrom,$mTo)` 이 내부에서 매출을 2번 더 부른다 → **이번 달 매출만 5회**.
원가도 `:273 costTotal` / `:561 confirmedCost`(= `costTotal` 별칭) / `confirmedProfit` 내부 2회 = **4회**.

3.1 의 메모이제이션 한 줄이면 이 항목도 같이 해소된다(같은 기간 → 같은 캐시 키).

### 3.3 기간 조건 불일치 — 사실 확인

`:591` 은 `date('Y-m-01')`, `:269`/`:560` 은 `$mFrom = date('Y-m-01')`. **문자열 결과가 동일**하므로
값 불일치는 없다. 다만 리터럴이 흩어져 있어 향후 기간 정의가 바뀔 때 어긋나기 쉽다 —
`$mFrom`/`$mTo` 를 한 곳에서 계산해 넘기는 편이 안전하다. (**버그 아님, 유지보수 위험**)

### 3.4 권한 없는 사용자에게 불필요한 계산 — 확인 결과 문제 없음

`DashboardController::processBoardCounts(?int $uid)` 등은 `Scope`/`siteScope` 로 사용자 범위를
**쿼리 레벨에서** 좁히고 있고, 사장 전용 블록(`bossKpi`)은 호출 자체가 분기되어 있다.
super_admin 으로 측정한 85쿼리가 상한이며, 일반 직원은 이보다 적다. **개선 대상 아님.**

### 3.5 모든 페이지에 붙는 알림 카운트

`app/views/layout/default.php:9` 가 **모든 페이지 렌더마다** 실행된다:

```php
$unread = (int) Db::val("SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0", ...);
```

`NotificationsController::index()` 는 `:33` 에서 같은 값을 또 구한다 → **알림 페이지에서는 2회**.
S2 실측 1.94ms (index_merge). 인덱스 개선으로 0.32ms 가 된다(§5).

---

## 4. N+1 (루프 안의 쿼리)

전 컨트롤러·코어·뷰를 훑어 루프 내부 쿼리를 전수 확인했다.
**헬퍼가 이미 캐시/배치인 것은 제외**했다(`Settings::get` = 전역배열, `Stages::*` = static 캐시,
`Perm::of` = uid 캐시, `PipelineStageService::attachSignals` = 이미 `WHERE IN` 배치,
`AttendanceService` = 이미 날짜범위+uid 배치, `Calc`/`Util`/`AccountingService::contribution` = 순수 함수).

### 4.1 읽기 경로 (목록·상세 렌더) — 우선순위 순

| # | 위치 | 루프 대상 | 반복 쿼리 | 추가 쿼리 수 | 수정안 |
|---|---|---|---|---|---|
| 1 | `app/controllers/TargetsController.php:67` → `:68` | `goals` (하드 `LIMIT 200`) | `GoalService::progress()` → `GoalService.php:209`→`:91` → `AccountingService.php:449,460,473` 등 | **목표당 3~4 집계 = 최대 600~800** | 목표를 `(metric, subject_type, 기간)` 으로 묶어 `employeeConfirmedByUser($from,$to)` 를 **기간별 1회**만 호출(이미 `uid⇒값` 맵을 반환한다). `GoalService.php:81` 의 부서원 조회는 `WHERE department_id IN (...)` 로 선조회 |
| 2 | `app/controllers/PerformanceController.php:51` → `:52` | 활성 직원 전원 | `computePerformance()` 내부 `:166`, `:229`, `:230`, `GoalService.php:292`/`:306`, `:267`, `:283` | **직원당 7 = 50명이면 350** | `:45-49` 의 `$bulk` 프리로드가 **`AccountingService` 4개만** 처리했다. 나머지 7개도 같은 방식으로: leads 카운트 2개는 `GROUP BY sales_user_id`, goals/targets 는 `WHERE user_id IN (...)`, `holidays` 범위 조회는 루프 밖으로 완전 이동 |
| 3 | `app/controllers/PerformanceController.php:115` → `:117` | 직원의 **전체** 배정 프로젝트(상한 없음) | `AccountingService::projectConfirmedRevenue()` → `:122` + `:191` | **프로젝트당 2 = 100건이면 200** | `:102` 외부 쿼리에 `c.supply_amount`, `c.contract_amount` 를 조인하고 기존 `AccountingService::PAID_SUM_SQL` 상수(`:184`)를 상관 서브쿼리로 붙인다. **`StaffController.php:153-170` 이 이미 정확히 이 패턴을 쓰고 있다** — 그대로 따라가면 된다 |
| 4 | `app/controllers/DashboardController.php:740` → `:743,744` | 고정 6개월 | `confirmedRevenue` ×2 + `costTotal` | **18** | §3.1 |
| 5 | `app/controllers/ReportsController.php:130` → `:133,134` | 고정 6개월 | 위와 동일 | **18** | §3.1 과 **동시에** 수정(값 일치 제약) |
| 6 | `app/controllers/ReportsController.php:367` → `:368` | `from`~`to` 사이 월 — **사용자 입력이라 상한 없음** | `SELECT ... FROM company_targets WHERE year=:y AND period_no=:m` | 5년 조회 시 **60** | `WHERE period_type='month' AND (year,period_no) IN (...)` 한 방 |
| 7 | `app/controllers/ProcessController.php:40` → `:42,44` | 공정 미배치/깨진 FK 프로젝트 | `ProcessService::initWaiting`/`moveStage` (각 SELECT 1~2 + UPDATE + INSERT) | 정상 0, 사고 시 **깨진 건수 × 3~5** | **GET 페이지가 쓰기를 한다.** ① `ProcessService::waitingStageId()`(`ProcessService.php:16`)를 루프 인자에서 매번 호출 → `static` 메모 추가 ② 배치 `UPDATE ... WHERE id IN (...)` + 다중행 INSERT ③ 근본적으로는 마이그레이션/크론으로 이동 |

### 4.2 쓰기 경로

| # | 위치 | 루프 대상 | 반복 쿼리 | 수정안 |
|---|---|---|---|---|
| 8 | `app/controllers/BonusController.php:793` → `:816,817,818,826` | 대상 직원(2~10) | INSERT + **방금 넣은 행 재 SELECT** + 이력 INSERT + audit INSERT | `:817` 재조회 제거(`$data + ['id'=>$newId]` 로 구성). 이력·audit 은 루프 후 다중행 INSERT. **5N → N+2** |
| 9 | `app/core/BonusService.php:34` → `:54,55` | 프로젝트의 비취소 보너스 행 | UPDATE + 이력 INSERT | **입금 저장마다 호출된다**(`StatusService.php:233`). 이력은 다중행 INSERT, 갱신은 `CASE WHEN id=...` 한 방 |
| 10 | `app/controllers/NotificationsController.php` 7개 루프 (`:161,186,211,233,255,277,302`) | 오늘 대상 행 | `Notif::push` → `Notif.php:14` INSERT | 7개 생성기의 행을 모아 **다중행 INSERT 1회**. 중복 방지(`:142`)는 이미 메모리 맵이라 추가 조회 불필요. `Notif::pushMany`(`Notif.php:29`)도 현재 `push` 루프라 같이 수정 |
| 11 | `app/controllers/QuotesController.php:297` → `:298` | 견적 품목(5~100) | `Db::insert('quote_items', ...)` | 다중행 INSERT(500행 단위 청크) |
| 12 | `app/core/Perm.php:283` → `:284` | 리소스 키(15~25) | `Db::insert('employee_permissions', ...)` | `:282` DELETE 뒤 다중행 INSERT 1회 |
| 13 | `app/controllers/SettingsController.php:209` → `:210` | 공정 단계(15~40) | `Db::update('process_stages', ...)` | `UPDATE ... SET sort_order = CASE id WHEN ... END WHERE id IN (...)`. `$ids` 는 `:198` 에서 이미 `intval` |
| 14 | `app/controllers/TargetsController.php:105` → `:106` | 고정 17 | upsert | 다중행 `VALUES (...),(...) ON DUPLICATE KEY UPDATE` — 17 → 1 |
| 15 | `app/controllers/ProcessController.php:209` → `:210` | 게이지 공정(10~20) | `SELECT pct FROM project_stage_progress WHERE project_id=:p AND stage_id=:s` | `WHERE project_id=:p` 한 방으로 받아 PHP 에서 판정. **`ProcessService.php:103,126,181` 이 이미 이 패턴** |
| 16 | `app/controllers/ProjectsController.php:926` / `app/core/ContractProjectService.php:161` | 번호 충돌 재시도 ≤5 | 루프 안에서 `COUNT(*)` 재실행 — **루프 불변식** | `COUNT(*)` 를 루프 밖으로. 5 → 1 |
| 17 | `app/controllers/ScheduleController.php:327`/`:362` | 슬롯 ≤3 / 참여자 1~20 | INSERT | 선행 DELETE 뒤 다중행 INSERT |

### 4.3 지금은 N+1 이 아니지만 한 발짝 남은 것

* `app/core/ProcessService.php:16`(`waitingStageId`), `:26`(`stageIdByKey`) — **불변 시드 데이터를 읽는데 캐시가 없다.** 이미 `ProcessController.php:44` 에서 루프마다 호출된다. `static` 메모 필수.
* `app/core/Scope.php:52,55,58`(`canAccessProject`) — 호출마다 SELECT 1회, 메모 없음. 현재는 전부 단건 가드지만 행 루프에 들어가는 순간 N+1.
* `app/core/Perm.php:206`(`isSuperAdminId`) — `Auth::id()` 와 같으면 DB 를 안 타지만, **다른 uid 로 부르면 매번 SELECT**. 현재 `Rbac::perms()` 가 항상 `Auth::id()` 를 넘겨서 안전할 뿐이다.

---

## 5. 인덱스

### 5.1 운영 EXPLAIN — 지금은 전부 풀스캔이지만 비용이 0

읽기 전용 EXPLAIN(MariaDB 10.6.17) 실측:

| 쿼리 | type | key | rows |
|---|---|---|---|
| 입금 기간 집계 | `ALL` | NULL | 13 |
| 원가 기간 집계 | `ALL` | NULL | 4 |
| 감사 로그 1페이지 (`ORDER BY created_at DESC LIMIT 20`) | `ALL` | NULL | 414 |
| 미읽음 알림 수 | `index_merge` | `idx_notifications_user` ∩ `idx_notifications_is_read` | 8 |

**결론: 현재 운영 규모에서 인덱스를 추가해도 이득이 없다.** 판단 기준은 "지금 느린가"가 아니라
**"업무량에 비례해 무한히 커지는 테이블인가"** 로 잡았다.

### 5.2 후보 8개 전/후 실측 — 채택 2개, 기각 6개

`scripts/audit/perf_index_bench.php`, S2 규모, 각 7회 중앙값. 벤치는 인덱스를 만들고 재측정한 뒤 **되돌린다**.

| 후보 인덱스 | 전(ms) | 후(ms) | 배율 | 인덱스 크기 | 판정 |
|---|---:|---:|---:|---:|---|
| `payments (status, paid_date)` | 6.22 | **0.28** | **22.0배** | 240 KB | ✅ **채택** |
| `notifications (user_id, is_read)` | 1.94 | **0.32** | **6.1배** | 400 KB | ✅ **채택** |
| `contracts (deleted_at, contract_date)` | 0.73 | 0.09 | 8.0배 | 64 KB | ⏳ 나중 (절대값 0.64ms) |
| `costs (cost_status, type, spent_date)` | 1.15 | 1.03 | 1.1배 | 1,552 KB | ❌ 기각 |
| `audit_logs (entity, created_at)` | 0.11 | 0.11 | 1.0배 | 5,648 KB | ❌ 기각 |
| `schedules (project_id, event_date)` | 0.68 | 0.72 | 0.9배 | 368 KB | ❌ 기각 |
| `project_stage_progress (project_id, pct)` | 0.94 | 1.06 | **0.9배(느려짐)** | 1,552 KB | ❌ 기각 |
| `projects (deleted_at, id)` | 0.08 | 0.08 | 1.0배 | 48 KB | ❌ 기각 |

기각 사유:
* **costs** — 기존 `idx_costs_spent_date` 가 이미 range 로 잘 걸린다(rows 1,130→820, 시간 차이 없음). 1.5MB 를 쓰고 0.12ms 를 산다.
* **audit_logs** — `idx_audit_logs_created_at` 만으로 충분. 5.6MB 인덱스를 만들고 개선 0.
* **project_stage_progress** — PRIMARY 가 `(project_id, stage_id)` **클러스터 인덱스**라 `pct` 가 이미 같은 페이지에 있다. 보조 인덱스는 순수 오버헤드(실제로 느려짐).
* **schedules / projects** — 옵티마이저가 새 인덱스를 선택조차 하지 않았다(EXPLAIN key 변화 없음).

### 5.3 중복 인덱스 — 5쌍 검증 완료

"선행 접두(leftmost prefix)가 다른 인덱스에 완전히 포함"되는 쌍을 운영 `information_schema.STATISTICS`
전수(총 46테이블)로 확인했다. **선행 감사가 지목한 5쌍이 정확히 맞다.**

| # | 테이블 | 중복 인덱스 | 흡수하는 인덱스 | FK 안전성 |
|---|---|---|---|---|
| 1 | `edencrm_projects` | `idx_projects_contract (contract_id)` | `uq_projects_contract (contract_id)` UNIQUE — **완전 동일** | `fk_projects_contract` 는 UNIQUE 가 지원 ✅ |
| 2 | `edencrm_employee_permissions` | `idx_emp_perm_user (user_id)` | `uq_emp_perm (user_id, resource_key)` | 선행 컬럼 일치 ✅ |
| 3 | `edencrm_targets` | `idx_targets_user (user_id)` | `uq_targets_user_year_month (user_id, year, month)` | 선행 컬럼 일치 ✅ |
| 4 | `edencrm_quote_versions` | `idx_quote_versions_quote (quote_id)` | `uq_quote_versions (quote_id, version_no)` | 선행 컬럼 일치 ✅ |
| 5 | `edencrm_user_permissions` | `idx_user_permissions_user (user_id)` | `uq_user_permissions (user_id, permission_id)` | 선행 컬럼 일치 ✅ |

FK 는 "해당 컬럼이 **선행 컬럼인** 인덱스"면 충족되므로 5개 모두 삭제 가능하다.
다만 **대상 테이블이 전부 소형이고 쓰기 빈도도 낮다**(targets 0행, user_permissions 0행,
quote_versions 4행, employee_permissions 36행, projects 11행). 지금 얻는 것은 수십 KB 와
INSERT 당 인덱스 갱신 1건뿐이다 → **마이그레이션에 넣지 않았다.** DDL 은 아래에 적어 두었으니
스키마 정리 작업을 할 때 함께 처리하면 된다.

```sql
ALTER TABLE `edencrm_projects`             DROP KEY `idx_projects_contract`;
ALTER TABLE `edencrm_employee_permissions` DROP KEY `idx_emp_perm_user`;
ALTER TABLE `edencrm_targets`              DROP KEY `idx_targets_user`;
ALTER TABLE `edencrm_quote_versions`       DROP KEY `idx_quote_versions_quote`;
ALTER TABLE `edencrm_user_permissions`     DROP KEY `idx_user_permissions_user`;
```

**반대로, 중복이 아니라고 확인한 것**(오탐 방지용 기록):
`costs(cost_status)` vs `costs(project_id, cost_status)` — `cost_status` 가 선행이 아님.
`schedule_participants(user_id)` vs `(schedule_id, user_id)` — 선행 아님.
`process_stages(sort_order)` vs `(process_type, sort_order)` — 선행 아님.
`project_stage_progress(stage_id)` vs PRIMARY`(project_id, stage_id)` — 선행 아님.
`attendance_marks(mark_date)` vs `(user_id, mark_date)` — 선행 아님.

### 5.4 마이그레이션 — 멱등성 패턴 검증

파일: `database/migrations/2026-07-29_perf_indexes.sql` (로컬) / `database/cafe24/014_perf_indexes.sql` (운영)

**엔진 차이가 함정이다**: 로컬은 MySQL 9.6, 운영은 MariaDB 10.6.17.
* MariaDB 는 `ALTER TABLE ... ADD KEY IF NOT EXISTS` 를 지원한다.
* **MySQL 은 인덱스에 대해 `IF NOT EXISTS` 를 지원하지 않는다.**
* 마이그레이션 러너(`deploy/run_migration.php`, `scripts/apply_local_migration.php`)는 세미콜론으로
  문장을 쪼개 실행하므로 **저장 프로시저(`BEGIN...END`)를 쓸 수 없다.**

→ 두 엔진 모두에서 되는 유일한 방법인 **information_schema 판정 + `PREPARE`/`EXECUTE`** 를 썼다.

```sql
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edencrm_payments'
      AND INDEX_NAME = 'idx_payments_status_paid_date') > 0,
  'DO 0',
  'ALTER TABLE `edencrm_payments` ADD KEY `idx_payments_status_paid_date` (`status`, `paid_date`)');
PREPARE stmt_perf_1 FROM @sql;
EXECUTE stmt_perf_1;
DEALLOCATE PREPARE stmt_perf_1;
```

**검증 결과(추측 아님)**

| 항목 | 결과 |
|---|---|
| MySQL 9.6 측정 DB — 깨끗한 상태에서 1·2·3회 연속 실행 | 3회 모두 "적용 완료", 인덱스 상태 동일 ✅ |
| **실제 로컬 개발 DB(`eden_crm`) 에 적용 + 재적용** | 2회 모두 "적용 완료" ✅ |
| 최종 인덱스 상태 | `payments(status, paid_date)`, `notifications(user_id, is_read)` 생성 / 흡수된 접두 인덱스 3개(`idx_payments_status`, `idx_notifications_user`, `idx_notifications_is_read`) 삭제 ✅ |
| FK 3개(`fk_notifications_user`, `fk_payments_contract`, `fk_payments_project`) | 전부 보존 ✅ |
| 적용 후 측정 DB 14개 라우트 재측정 | 전부 HTTP 200 ✅ |
| 적용 후 **개발 서버(:8080) 18개 라우트 스모크** | `home` `customers.index` `pipeline.index` `quotes.index` `contracts.index` `projects.index` `process.board` `schedule.index` `reports.index` `reports.data` `staff.index` `halfyear.index` `performance.index` `dashboard.data` `notifications.index` `audit.index` `settings.index` `targets.index` — **전부 HTTP 200** ✅ |
| MariaDB 10.6.17 운영 — `information_schema` 판정부 | 존재 인덱스 1행 / 미존재 0행 — 정상 ✅ |
| MariaDB — `IF(...)` 분기부 | 이미 존재할 때 `'DO 0'` 선택 확인 ✅ |
| MariaDB — `PREPARE FROM @sql` / `EXECUTE` / `DEALLOCATE` | 동작 확인 ✅ |
| MariaDB — `DO 0` no-op | 동작 확인 ✅ |

> 운영 검증은 `SET SESSION TRANSACTION READ ONLY` 세션에서 SELECT/`DO 0` 만 실행했다.
> **운영 스키마·데이터는 일절 변경하지 않았다** (`scripts/audit/perf_mariadb_guard_check.php`).

**`ANALYZE TABLE` 은 일부러 뺐다.** 결과 집합을 돌려주는데 러너가 이를 소비하지 않아
다음 문장에서 `Cannot execute queries while other unbuffered queries are active` 로 중단된다(실측 확인).
InnoDB 는 DDL 직후 통계를 자동 갱신하므로 없어도 새 인덱스가 즉시 선택된다.

### 5.5 마이그레이션 적용 효과 (S2 재측정)

| 라우트 | 인덱스 전 | 인덱스 후 | 배율 |
|---|---:|---:|---:|
| `dashboard.data` | 68.9 ms | **19.6 ms** | **3.5배** |
| `reports.index` | 9.5 ms | 4.1 ms | 2.3배 |
| `schedule.index` | 17.5 ms | 9.2 ms | 1.9배 |
| `halfyear.index` | 183.5 ms | 161.4 ms | 1.14배 |
| `contracts.index` | 31.6 ms | 27.5 ms | 1.15배 |
| `reports.data` | 559.8 ms | 527.8 ms | 1.06배 |
| `home` | 3,112.8 ms | 3,156.4 ms | **변화 없음** |
| `performance.index` | 1,647.0 ms | 1,744.2 ms | **변화 없음** |

`home`/`performance.index` 가 꿈쩍도 안 하는 이유는 §2 의 `OR` 조인이 **인덱스로 해결 불가능한
구조 문제**이기 때문이다. **인덱스로는 이 두 라우트를 절대 못 고친다 — 코드 수정만이 답이다.**

---

## 6. 정적 리소스

### 6.1 크기

총 **424 KB**(13개 파일) → gzip **138 KB**.

> 측정 시점 2026-07-29 10:00. `app.css`/`app.js` 는 다른 작업(T-병행)이 동시에 편집 중이라
> 바이트 수가 수 KB 단위로 흔들린다(재확인: app.css 78,018 / app.js 12,401).
> **아래 결론(중복 라이브러리 없음·페이지별 분리 완료·gzip 이미 적용·Cache-Control 부재)은
> 구조적 사실이라 이 변동과 무관하다.**

| 파일 | 원본 | gzip |
|---|---:|---:|
| `public/assets/vendor/chart.umd.js` (Chart.js v4.4.1, 이미 minify) | 205,125 | 69,549 |
| `public/assets/css/app.css` | 81,252 | 20,775 |
| `public/assets/vendor/Sortable.min.js` | 44,581 | — |
| `public/assets/js/scheduler.js` | 23,278 | — |
| `public/assets/js/process-board.js` | 18,165 | — |
| `public/assets/js/app.js` | 13,119 | 5,237 |
| 나머지 7개 (reports, report_attendance, quotes, dashboard, perm-matrix, purge-confirm, pipeline) | 38,306 | — |

### 6.2 중복 라이브러리 — 없음

`chart.umd.js` 는 Chart.js 4.4.1 단일 버전, `Sortable.min.js` 단일 버전. 중복·다중 버전 없음.
자체 호스팅이라 외부 CDN 요청도 없다(CSP `default-src 'self'` 와 일관).

**로딩도 이미 페이지별로 분리되어 있다** — 모든 페이지가 무조건 받는 것은
`app.css`(81KB) + `app.js`(13KB) + `purge-confirm.js`(2KB) 뿐이다.

| 무거운 라이브러리 | 로드되는 페이지 |
|---|---|
| `vendor/chart.umd.js` (205KB) | `dashboard`(`DashboardController.php:111`), `reports.index`(`ReportsController.php:22`), `reports.attendance`(`:422`) 만 |
| `vendor/Sortable.min.js` (45KB) | `settings.stages`(`SettingsController.php:174`) 만 |

→ **번들 분리 개선 여지 없음. 이미 최적.**

### 6.3 캐시 헤더 — 가설 검증 결과

> 과제 가설: "`Cache-Control: no-store` 가 모든 응답에 붙어 정적 자산에도 낭비가 발생하는가?"

**틀렸다.** 운영 실측(`<SERVICE_URL>`):

| 대상 | 실제 헤더 |
|---|---|
| `index.php?r=login` (PHP) | `cache-control: no-store, no-cache, must-revalidate` ← PHP 세션 모듈(`session.cache_limiter`)이 붙임 |
| `assets/css/app.css` | `vary: Accept-Encoding` / `last-modified` / `etag` — **`Cache-Control` 없음** |
| `assets/js/app.js` | `x-content-type-options: nosniff`, `x-frame-options: SAMEORIGIN`, `etag`, `last-modified` |

정적 자산은 Apache 가 직접 서빙하므로(루트 `.htaccess` 의 `RewriteRule ^(.*)$ public/$1`)
PHP 의 `no-store` 는 **자산에 붙지 않는다.** 그러므로 그 항목은 개선 대상이 아니다.

**gzip 도 이미 켜져 있다** — 실측:
```
$ curl -sI -H "Accept-Encoding: gzip" .../assets/css/app.css
content-encoding: gzip        ← 이미 압축됨
```
`.htaccess` 에 `mod_deflate` 설정이 없는데도 호스트(openresty 프론트 + Apache) 기본값으로 동작한다.
→ **압축 개선 여지 없음.**

**진짜 남은 문제는 하나뿐: 정적 자산에 `Cache-Control` 이 전혀 없다.**
현재는 `ETag`/`Last-Modified` 기반 조건부 GET → 매 페이지 이동마다 자산 개수만큼 304 왕복이 발생한다
(실측: `If-None-Match` 로 요청 시 `HTTP/2 304` 정상 반환).

다행히 **자산 URL 은 이미 `?v=filemtime()` 으로 버저닝되어 있다**
(`app/views/layout/default.php:20-21,82-86`, `blank.php:8`) — 배포하면 URL 이 바뀐다.
따라서 **긴 max-age 를 안전하게 붙일 수 있다.**

`mod_headers` 가 자산에도 적용되고 있음은 확인했다(자산 응답에 `x-content-type-options` 가 붙어 있다).
루트 `.htaccess` 의 보안 헤더 블록에 아래를 추가하면 된다:

```apache
# ── 정적 자산 장기 캐시 (URL 은 ?v=filemtime 으로 버저닝되어 있어 안전) ──
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|png|jpe?g|gif|svg|webp|woff2?|ico)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
```

> ⚠️ `.htaccess` 는 T8 소유 파일이 아니라 **직접 수정하지 않았다.** 위 블록은 제안이다.
> `blank.php:18` 의 `app.js` 만 `?v=` 가 빠져 있으니(`app/views/layout/blank.php:18`)
> 캐시 헤더를 넣기 전에 그 한 줄에도 버전을 붙여야 한다. **선행 조건이다.**

### 6.4 이미지 최적화

`public/assets/` 에 이미지 파일이 **0개**다(전부 CSS/JS). 사용자 업로드 이미지는 `storage/uploads/`
로 가고 웹 접근이 차단되어 있어 `ProjectsController::download` 를 거친다. **최적화 대상 없음.**

---

## 7. `SELECT *` — 100건 중 실제로 고칠 값어치가 있는 6건

전수 조사 결과 리터럴 `SELECT *` **100건**(`Db::one` 85 / `Db::all` 15) + `SELECT 별칭.*` **44건**.

**대부분은 그대로 두는 게 맞다:**
* **감사 스냅샷(A1, 약 40건)** — `Audit::log()` 의 before/after 로 행 전체를 `json_encode` 한다. 컬럼을 줄이면 **감사 원장이 조용히 잘린다.** 절대 손대지 말 것. (`ContractsController.php:458,628,1072,1096`, `ProcessController.php:335,423,488`, `QuotesController.php:261,330`, `SettlementController.php:64,137`, `ProjectsController.php:599,769`, `BonusController.php:494,716,817`, `CostsController.php:122,174`, `TargetsController.php:126,263,285,367` 등)
* **휴지통 복원·완전삭제 가드(12건)** — 존재 확인용으로 읽은 행을 그대로 audit before 로 재사용한다. 동일 이유로 유지. (`ContractsController.php:976,994`, `QuotesController.php:437,455`, `PipelineController.php:449,474`, `ProjectsController.php:824,842`, `CustomersController.php:608,641`)
* **`app/core/BonusService.php:22`** — `SELECT *` 결과를 `:57-59` 에서 `site_bonus_history` 의 before/after JSON 으로 직렬화한다. **필수.**
* **단건 조회(1행)** — 폭이 넓어도 1행이라 실익이 없다.

**고칠 값어치가 있는 것만 추렸다** (목록/집계이면서 사용 컬럼 비율이 낮고 TEXT 를 끌고 오는 것):

| 우선 | 위치 | 테이블 | 사용/전체 컬럼 | 불필요하게 끌고 오는 TEXT |
|---|---|---|---|---|
| 1 | `app/controllers/CustomersController.php:116` | `contracts` | **4 / 36** | `special_terms` TEXT, `memo` TEXT |
| 2 | `app/controllers/CustomersController.php:117` | `projects` | **4 / 33** | `memo` TEXT |
| 3 | `app/controllers/ProjectsController.php:114` (`p.*`, 페이징 목록) | `projects` | 12 / 33 | `memo` TEXT — **행 수가 가장 많은 목록** |
| 4 | `app/controllers/CustomersController.php:68`, `:97`(휴지통) (`c.*`, 페이징 목록) | `customers` | 12 / 32 | `memo` TEXT |
| 5 | `app/controllers/QuotesController.php:163` | `project_files` | **2 / 11** | — |
| 6 | `app/controllers/NotificationsController.php:30` | `notifications` | 8 / 10 | — |

5·6번은 **같은 파일/이웃 파일에 이미 올바른 버전이 있다**:
* `ContractsController.php:198-200` 이 `SELECT id, original_name, size FROM project_files` 로 정확히 같은 일을 한다.
* `NotificationsController.php:49` 가 필요한 8컬럼을 이미 명시적으로 나열한다 — `:30` 이 그것을 그대로 쓰면 된다.

추가로 `StaffController.php:113`, `PerformanceController.php:86` 의 `u.*` 는
**`password_hash` 를 뷰 레이어까지 실어 나른다.** 성능보다 노출면 관점에서 좁히는 게 좋다.

`AuditController.php:40` 의 `a.*` 는 `before_json`/`after_json` TEXT 를 실제로 `<details>` 에 렌더하므로 **유지**.

---

## 8. 목록 상한 부재 — 규모가 커지면 브라우저가 먼저 죽는다

DB 문제가 아니라 **렌더 상한 문제**다. S2 실측 응답 크기:

| 라우트 | 상한 | S1 출력 | S2 출력 | DB 시간(S2) |
|---|---|---:|---:|---:|
| `process.board` | **없음** (`app/controllers/ProcessController.php:61` 부근) | 123 KB | **26.9 MB** | 69 ms |
| `pipeline.index` | **없음** (`app/controllers/PipelineController.php:139-150`) | 26 KB | **2.3 MB** | — |
| `schedule.index` | — | 13 KB | 247 KB | — |
| `projects.index` | ✅ `LIMIT/OFFSET` (`:121`) | 29 KB | 72 KB | — |
| `customers.index` | ✅ 페이징 | 23 KB | 47 KB | — |

`process.board` 는 보드 상태(preparing/in_progress/paused/warranty) 프로젝트를 **전부** 카드로 그린다.
S2 에서 2,251건 → 27 MB. DB 는 69ms 밖에 안 쓰는데 HTML 생성·전송·파싱이 전부를 잡아먹는다.
`pipeline.index` 의 목록 쿼리도 `LIMIT` 이 없다(휴지통 모드 쿼리 `:225` 에만 `LIMIT 300` 이 있다).

**현재 운영(프로젝트 11건, 리드 2건)에서는 전혀 문제가 아니다.**
프로젝트가 200~300건을 넘어서면(대략 1~2년) 대응이 필요하다 — 상태 그룹별 카드 상한 + "더 보기",
또는 공정 그룹 단위 지연 로딩.

---

## 9. 권고 — 우선순위별 정리

### 9.1 즉시 적용 권장

| # | 항목 | 근거 수치 | 담당 파일 | 상태 |
|---|---|---|---|---|
| **1** | **`PAY_PROJECT_JOIN` 의 `OR` 조인을 LEFT JOIN ×2 + COALESCE 로 재작성** | 1,229.8→25.3ms (48.6배), 1,535.1→26.7ms (57.5배), 결과 완전 일치. `home` DB 시간의 88%, `performance.index` 의 94.5% | `app/core/AccountingService.php:415-419` | **미적용(T8 소유 아님 — 코드 수정 필요)** |
| **2** | **`confirmedRevenue`/`costTotal` 요청 스코프 메모이제이션** | `dashboard.data` 20쿼리 중 **16개가 순수 중복**(모든 쿼리가 정확히 ×2). 20→8쿼리(-60%) | `app/core/AccountingService.php:109`, `:597` | 미적용 |
| **3** | **인덱스 마이그레이션 적용** | `dashboard.data` 68.9→19.6ms (3.5배), `reports.index` 2.3배, `schedule.index` 1.9배 | `database/cafe24/014_perf_indexes.sql` | ✅ **작성·로컬 검증 완료**(운영 미적용) |
| **4** | **`PerformanceController` `$bulk` 프리로드 확장** | 직원당 7쿼리 × 50명 = 350쿼리 | `app/controllers/PerformanceController.php:45-52` | 미적용 |
| **5** | **`TargetsController` 목표 진행률 배치화** | 목표 200건 × 3~4 집계 = 최대 800쿼리 | `app/controllers/TargetsController.php:67-68` | 미적용 |
| **6** | **`ProcessService::waitingStageId`/`stageIdByKey` static 메모** | 불변 시드 조회인데 호출마다 SELECT, 이미 루프 안에서 호출됨 | `app/core/ProcessService.php:16,26` | 미적용 (한 줄 수정) |
| **7** | **정적 자산 `Cache-Control: max-age=31536000, immutable`** | 자산에 `Cache-Control` 부재 → 페이지 이동마다 자산 수만큼 304 왕복. URL 은 이미 `?v=filemtime` 버저닝 | `.htaccess` (+ `app/views/layout/blank.php:18` 에 `?v=` 추가가 선행 조건) | 미적용 (T8 소유 아님) |

### 9.2 나중에 필요 (지금은 불필요)

| 항목 | 지금 불필요한 이유 | 필요해지는 시점 |
|---|---|---|
| `process.board` / `pipeline.index` 목록 상한 | 프로젝트 11건·리드 2건 → 123KB / 26KB | 프로젝트 **200~300건**부터. 3,000건이면 27MB |
| `contracts (deleted_at, contract_date)` 인덱스 | 8배지만 절대값 0.64ms 절감 | 계약 **10,000건** 이상 |
| `ReportsController.php:367` 월별 목표 루프 | 사용자가 5년 범위를 고르면 60쿼리 | 리포트 장기 범위 조회가 실제로 쓰일 때 |
| `SELECT *` 목록 6건 좁히기 | TEXT 컬럼 전송 낭비지만 현재 총량이 작다 | 목록 페이지 응답이 체감될 때 |
| 쓰기 경로 다중행 INSERT (§4.2 #8~#17) | 견적 품목·알림·권한 모두 소량 | 견적 품목 100개 이상, 알림 대량 생성 시 |
| 중복 인덱스 5쌍 제거 | 대상 테이블 전부 0~36행 | 스키마 정리 작업과 함께 |

### 9.3 불필요 (하지 말 것)

| 항목 | 실측 근거 |
|---|---|
| `costs (cost_status, type, spent_date)` 인덱스 | 1.15→1.03ms (1.1배). 1.5MB 쓰고 0.12ms 산다. 기존 `idx_costs_spent_date` 로 충분 |
| `audit_logs (entity, created_at)` 인덱스 | 0.11→0.11ms. 5.6MB 인덱스에 개선 0 |
| `project_stage_progress (project_id, pct)` 인덱스 | 0.94→**1.06ms (느려짐)**. PRIMARY 가 클러스터라 `pct` 가 이미 같은 페이지에 있다 |
| `schedules (project_id, event_date)`, `projects (deleted_at, id)` 인덱스 | 옵티마이저가 선택조차 안 함(EXPLAIN key 불변) |
| gzip 압축 설정 추가 | **이미 켜져 있다** — 운영 실측 `content-encoding: gzip` |
| 정적 자산의 `no-store` 제거 | **애초에 붙어 있지 않다** — PHP 응답에만 붙고 자산은 Apache 직접 서빙 |
| JS 번들 분리·지연 로딩 | 이미 페이지별 분리 완료. 무조건 로드는 96KB 뿐 |
| 이미지 최적화 | `public/assets/` 에 이미지 0개 |
| `DashboardController` 권한별 계산 생략 | `Scope`/`siteScope` 가 이미 쿼리 레벨에서 좁힌다. 일반 직원은 super_admin(85쿼리)보다 적게 실행 |
| 감사 스냅샷·휴지통 가드의 `SELECT *` 좁히기 | 행 전체가 audit/이력 JSON 으로 직렬화된다. 좁히면 **원장이 조용히 손상된다** |

---

## 10. 재현 방법

```bash
# 1) 측정 전용 DB 생성 (로컬 소켓, root)
mysql --socket=.devdb/mysql.sock -uroot -e "CREATE DATABASE IF NOT EXISTS eden_crm_perf \
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysqldump --socket=.devdb/mysql.sock -uroot --single-transaction --skip-lock-tables \
  --set-gtid-purged=OFF eden_crm | mysql --socket=.devdb/mysql.sock -uroot eden_crm_perf

# 2) 전용 계정(general_log 필터용)
mysql --socket=.devdb/mysql.sock -uroot -e "
  CREATE USER IF NOT EXISTS 'eden_perf'@'localhost' IDENTIFIED BY 'PerfProbe!2026';
  GRANT ALL PRIVILEGES ON eden_crm_perf.* TO 'eden_perf'@'localhost'; FLUSH PRIVILEGES;"

# 3) config.perf.php 작성 후 전용 서버 기동 (개발 서버 :8080 과 분리)
EDEN_CONFIG_LOCAL=/path/to/config.perf.php php -S 127.0.0.1:8099 -t public &

# 4) 시딩 → 측정
php scripts/audit/perf_seed.php  --db eden_crm_perf --scale s1
php scripts/audit/perf_probe.php --runs 7 --label S1 --json /tmp/perf_s1.json
php scripts/audit/perf_seed.php  --db eden_crm_perf --scale s2
php scripts/audit/perf_probe.php --runs 7 --label S2 --json /tmp/perf_s2.json

# 5) 라우트별 쿼리 분해 / 인덱스 후보 벤치
php scripts/audit/perf_slowq.php       --route home --top 10
php scripts/audit/perf_index_bench.php --db eden_crm_perf --runs 7

# 6) 마이그레이션 적용 + 멱등성 확인 (여러 번 실행해도 무해)
EDEN_CONFIG_LOCAL=/path/to/config.perf.php \
  php scripts/apply_local_migration.php database/migrations/2026-07-29_perf_indexes.sql

# 7) 운영 MariaDB 가드 패턴 검증 (READ ONLY — 스키마 변경 없음)
php scripts/audit/perf_mariadb_guard_check.php
```

**측정 후 정리** (perf DB·계정·로그는 감사용 임시물이다):

```bash
mysql --socket=.devdb/mysql.sock -uroot -e "
  SET GLOBAL general_log='OFF';
  DROP DATABASE IF EXISTS eden_crm_perf;
  DROP USER IF EXISTS 'eden_perf'@'localhost';"
```
