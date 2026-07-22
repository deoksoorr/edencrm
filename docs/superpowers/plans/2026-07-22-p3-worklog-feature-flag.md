# P3 작업일지 기능 플래그 (기본 OFF) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** 작업일지 기능을 `settings.feature_worklog`(기본 '0'=OFF) 플래그로 봉인한다. OFF일 때 메뉴·API·대시보드 카드·KPI·알림·프로젝트 상세 버튼을 모두 제외하고, URL 직접 접근은 권한오류가 아니라 "비활성화된 기능" 안내로 전환한다. 데이터는 보존, 관리자가 시스템 설정에서 ON/OFF 토글(감사로그 기록).

**Architecture:** 라우터에 `feature` 메타 게이트를 추가하고, 소비자(대시보드·성과·알림·프로젝트상세)는 `Settings::enabled('feature_worklog')` 가드로 조건 렌더/집계한다. 데이터·컨트롤러 코드는 삭제하지 않는다.

**Tech Stack:** PHP 8.2, MySQL 8(.devdb). `Settings`(P1)·기존 `Audit`.

## Global Constraints

- 플래그 저장은 DB(`settings.feature_worklog`), 하드코딩 금지. 값 '1'=사용 / '0'=사용 안 함, 기본 '0'.
- OFF 시: 메뉴 숨김 · 대시보드 작업일지 카드/출근현황/주의항목 숨김 · 작성률 KPI 집계 제외 · 알림 생성 중단 · 프로젝트 상세 작업일지 카드/버튼 숨김 · 백그라운드 work_logs 쿼리 미실행 · 라우트(6개) 직접 접근 시 "비활성화된 기능" 안내(JSON 요청은 에러 JSON).
- 기존 work_logs 데이터 삭제 금지. ON 전환 시 전부 정상 복원.
- 토글 변경은 기존 `SettingsController::save()` → `Audit::log('settings_update')` 로 자동 감사(변경자·전후값·시각).
- **do NOT persist writes to the dev DB during tests** (transaction rollback만). 설정 토글 검증은 마지막에 원복.

**참조:** 스펙 §6.1 touchpoint 맵(16지점), §3. `Settings::enabled($key)`(P1).

---

### Task 1: 라우터 feature 게이트 + 설정 토글 UI + 메뉴 숨김

**Files:**
- Modify: `app/routes.php` (worklog 6개 라우트에 `'feature'=>'worklog'`)
- Modify: `public/index.php` (perm 체크 뒤 feature 게이트)
- Modify: `app/core/Nav.php` (worklog 메뉴 조건부)
- Modify: `app/views/settings/index.php` (운영 기능 그룹 토글)
- Create: `scripts/tests/unit_feature_flag.php`
- Modify: `scripts/tests/run.php` (스위트 추가)

- [ ] **Step 1: routes.php — worklog 라우트에 feature 메타.** `worklogs.index/form/save/show/confirm/photo` 6개 라우트 배열에 `'feature' => 'worklog'` 키를 추가한다(기존 perm/method 유지). 예: `'worklogs.index' => ['WorklogsController', 'index', 'feature' => 'worklog'],`.

- [ ] **Step 2: public/index.php — feature 게이트.** 권한 강제(`if (!empty($opts['perm'])) Rbac::require(...)`) **직후**에 삽입:
```php
// ── 4.5) 기능 플래그 게이트 ──
if (!empty($opts['feature']) && !Settings::enabled('feature_' . $opts['feature'])) {
    if (Response::wantsJson()) {
        Response::error('현재 비활성화된 기능입니다.', 404);
    }
    http_response_code(404);
    View::renderError(404, '비활성화된 기능', '이 기능은 현재 사용하도록 설정되어 있지 않습니다. 관리자에게 문의하세요.');
    exit;
}
```
(`Settings`는 bootstrap 로드 목록에 이미 등록됨.)

- [ ] **Step 3: Nav.php — worklog 메뉴 조건부.** `items()`의 '현장' 섹션에서 worklog 항목을 `Settings::enabled('feature_worklog')`일 때만 포함하도록 변경. 예:
```php
'현장' => array_values(array_filter([
    ['projects.index', '프로젝트', null, 'briefcase'],
    ['process.board', '공정 보드', null, 'trello'],
    ['schedule.index', '일정', null, 'calendar'],
    Settings::enabled('feature_worklog') ? ['worklogs.index', '작업일지', null, 'book'] : null,
])),
```

- [ ] **Step 4: settings/index.php — 운영 기능 토글.** `$groupLabels`에 `'운영 기능' => '운영 기능'`(또는 이미 그룹키가 한글이면 그대로) 추가. 그리고 값이 '0'/'1'인 플래그(setting_key가 `feature_`로 시작)는 text 대신 **select(사용/사용 안 함)** 로 렌더:
```php
<?php if (str_starts_with($r['setting_key'], 'feature_')): ?>
  <select name="<?= e($r['setting_key']) ?>" class="input">
    <option value="1"<?= $r['value']==='1'?' selected':'' ?>>사용</option>
    <option value="0"<?= $r['value']!=='1'?' selected':'' ?>>사용 안 함</option>
  </select>
<?php else: ?>
  <input type="text" name="<?= e($r['setting_key']) ?>" class="input" value="<?= e($r['value'] ?? '') ?>">
<?php endif; ?>
```

- [ ] **Step 5: 테스트.** Create `scripts/tests/unit_feature_flag.php`:
```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
echo "기능 플래그\n";
// 기본 OFF
t_true('feature_worklog 기본 OFF', Settings::enabled('feature_worklog') === false);
// routes.php 의 worklog 라우트에 feature 메타가 있는지(정적 검사)
$routes = require dirname(__DIR__,2).'/app/routes.php';
$missing = [];
foreach (['worklogs.index','worklogs.form','worklogs.save','worklogs.show','worklogs.confirm','worklogs.photo'] as $rk) {
    if (!isset($routes[$rk]) || ($routes[$rk]['feature'] ?? null) !== 'worklog') { $missing[] = $rk; }
}
t_int('worklog 라우트 feature 메타 누락 수', 0, count($missing));
exit(t_summary());
```
`run.php` `$suites`에 `'unit_feature_flag'` 추가.

- [ ] **Step 6: 검증.** `php -l public/index.php app/core/Nav.php app/views/settings/index.php app/routes.php`. `php scripts/tests/unit_feature_flag.php` → PASS 2. `php scripts/tests/run.php` → 전체 통과.

- [ ] **Step 7: 커밋** `git add app/routes.php public/index.php app/core/Nav.php app/views/settings/index.php scripts/tests/unit_feature_flag.php scripts/tests/run.php && git commit -m "feat(worklog): 라우터 feature 게이트 + 설정 토글 + 메뉴 숨김(기본 OFF)"` (+trailer).

---

### Task 2: 소비자 가드 (대시보드·성과·알림·프로젝트 상세)

**Files:** `app/controllers/DashboardController.php`, `app/views/dashboard/boss.php`, `app/views/dashboard/site.php`, `app/views/dashboard/staff.php`, `app/controllers/PerformanceController.php`, `app/views/performance/index.php`, `app/views/performance/user.php`, `app/controllers/NotificationsController.php`, `app/controllers/ProjectsController.php`, `app/views/projects/show.php`

- [ ] **Step 1: DashboardController 가드.** 상단에서 `$wl = Settings::enabled('feature_worklog');` 를 각 render 메서드에서 사용.
  - `renderBoss`: `workstatus`(출근현황=employeeWork) 는 `$wl ? $this->employeeWork() : ['today'=>$this->employeeWork()['today'] ?? [], 'attendance'=>[]]` — 단, employeeWork의 attendance(work_logs 집계)만 OFF 시 제외. 간단히 employeeWork()에 `$wl` 인자를 넘겨 attendance 쿼리를 skip.
  - `attention()`: worklog 항목을 `$wl`일 때만 배열에 포함.
  - `siteKpi`/`staffKpi`: `worklog` KPI를 `$wl`일 때만 포함(없으면 키 제거).
  - `worklogMissing()`: 호출부에서 `$wl` 아닐 때 아예 호출 안 함(0/미표시).
- [ ] **Step 2: boss.php.** "이번 달 출근 현황" 섹션(출근/작업일지) 전체를 `<?php if (!empty($workstatus['attendance'])): ?>`가 아니라 `<?php if ($wl): ?>`로 감싼다(뷰에 `$wl` 전달). 주의항목 루프는 컨트롤러가 이미 제외하므로 그대로.
  - 뷰에 `$wl` 를 넘기려면 renderBoss의 View::render 데이터에 `'wl' => $wl` 추가, boss.php 상단 `$wl = $wl ?? false;`.
- [ ] **Step 3: site.php / staff.php.** site.php의 worklog KPI 카드(:26)·주의항목(`$pick`에서 worklog 제거)은 컨트롤러 siteKpi/attention 제외로 자동. staff.php의 "작업일지 작성" 버튼(:14)·"오늘 일지 미작성" 카드(:24)는 `$wl` 가드로 감싼다(staffKpi에서 worklog 키 제외 + 뷰 버튼 조건부). 뷰에 `$wl` 전달.
- [ ] **Step 4: PerformanceController.** `computePerformance`에서 `worklog_rate`를 `Settings::enabled('feature_worklog') ? $this->worklogRate(...) : null` 로. 뷰(`performance/index.php` th/td, `user.php` 카드)는 `worklog_rate === null` 이면 컬럼/카드 숨김(또는 '-'); 헤더는 플래그로 조건부.
- [ ] **Step 5: NotificationsController.** `generateMissing()`에서 `genWorklogMissing()` 호출을 `if (Settings::enabled('feature_worklog')) { self::genWorklogMissing(); }` 로 감싼다.
- [ ] **Step 6: ProjectsController::show + projects/show.php.** show()의 `$workLogs` 쿼리를 `Settings::enabled('feature_worklog') ? (쿼리) : []` 로 skip. 뷰의 작업일지 카드(:207-232)를 `$wl` 가드로 감싸고 데이터로 `'wl'` 전달.
- [ ] **Step 7: 검증.** 변경 PHP 전부 `php -l`. `php scripts/tests/run.php` → 전체 통과. 컨트롤러 메서드를 reflection으로 호출해 OFF 시 대시보드 배열에 worklog 키가 없는지 확인(리뷰어가 렌더 검증).
- [ ] **Step 8: 커밋** `git add -A && git commit -m "feat(worklog): 소비자 가드(대시보드·성과·알림·프로젝트상세) OFF 시 제외"` (+trailer).

---

### Task 3: OFF/ON 회귀 검증 (서버 스모크 + 감사로그 + 데이터 보존)

**Files:** Create `scripts/worklog_flag_smoke.sh`

- [ ] **Step 1: 스모크 스크립트.** Create `scripts/worklog_flag_smoke.sh` — 로컬 서버(:8080)에 admin 로그인(쿠키+CSRF) 후:
  1. OFF(기본): `GET index.php?r=worklogs.index` → 응답에 "비활성화된 기능" 포함(200/404 무관, 권한오류 아님). 대시보드 HTML에 "작업일지" 메뉴 링크 없음.
  2. 설정 토글 ON: `POST settings.save` 로 `feature_worklog=1` → 302. 그 후 `GET worklogs.index` → 정상(작업일지 목록). 메뉴에 작업일지 노출.
  3. 원복: `feature_worklog=0` 로 되돌림.
  4. 데이터 보존: `SELECT COUNT(*) FROM work_logs` 가 토글 전후 동일.
  스크립트는 서버가 이미 떠 있다고 가정(없으면 안내). 각 단계 PASS/FAIL 출력, 실패 시 exit 1.
- [ ] **Step 2: 서버 기동 후 실행.** `php -S 127.0.0.1:8080 -t public` 백그라운드 기동 → `bash scripts/worklog_flag_smoke.sh` → 전 단계 PASS. (실행 후 `feature_worklog=0` 원복 확인.)
- [ ] **Step 3: 감사로그 확인.** 토글 시 `audit_logs`에 `settings_update` 가 before `feature_worklog=0` / after `1`(및 원복) 기록됐는지 확인:
```bash
mysql --socket=.devdb/mysql.sock -ueden_crm_user -p'EdenCrm!local2026' eden_crm -e "SELECT action,before_json,after_json,created_at FROM audit_logs WHERE action='settings_update' ORDER BY id DESC LIMIT 3;"
```
- [ ] **Step 4: 데이터 보존 확인.** work_logs 행수 토글 전후 불변(스모크에서 검증) + `SELECT COUNT(*) FROM work_logs;` 가 seed(17행)와 동일.
- [ ] **Step 5: 커밋** `git add scripts/worklog_flag_smoke.sh && git commit -m "test(worklog): OFF/ON 회귀 스모크(메뉴·라우트·데이터보존·감사로그)"` (+trailer). 검증 결과를 `harness_progress.js verify --id P3 --add "..."`.

---

## Self-Review
- 스펙 §6.1 16 touchpoint: 라우트(T1)·메뉴(T1)·설정토글(T1)·대시보드 3종·attention·KPI(T2)·성과 KPI/컬럼(T2)·알림(T2)·프로젝트상세(T2)·백그라운드 쿼리(T2) 모두 커버. 데이터 보존·감사·OFF/ON 복원=T3.
- **주의:** 최종적으로 `feature_worklog` 는 반드시 '0'(OFF)로 원복하고 커밋/보고한다(기본 비활성 요구). 서버 스모크의 토글은 검증 후 되돌린다.
- 컨트롤러 코드·work_logs 데이터·WorklogsController 는 삭제하지 않는다(플래그만).
