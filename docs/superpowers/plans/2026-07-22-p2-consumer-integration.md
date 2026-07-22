# P2 소비자 통합 (대시보드·성과·리포트 → AccountingService + write-path) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** 신규 계약·프로젝트 저장 시 공급가/부가세를 채우고(write-path), 대시보드 4종·성과·리포트가 전부 `AccountingService`의 단일 산식(공급가 기준·준공 인식·완료only 기여)을 쓰도록 통합해 화면 숫자를 교정한다.

**Architecture:** P1의 `AccountingService`를 소비. 컨트롤러의 흩어진 원시 SQL 집계를 서비스 호출로 교체하고, 뷰의 라벨·컬럼을 정책에 맞게 재구성한다. 검증은 P1 테스트 러너에 seed 기반 기대값 assertion을 추가.

**Tech Stack:** PHP 8.2, MySQL 8(.devdb), 기존 경량 PHP 테스트 러너.

## Global Constraints

- 손익(매출·순이익·성과)=공급가액(supply_amount). 현금(입금·미수금)=계약총액(contract_amount).
- 확정 매출/순이익 = `status='completed'`·기준일 `actual_end_date`. 예상=preparing/in_progress. cancelled·deleted 제외.
- 직원 확정 기여액 = 완료 프로젝트만 `(supply−actual_cost)×pct`. 가중 순이익률 = 귀속순이익 ÷ 귀속매출.
- 미수금 = Σ 계약별 GREATEST(0, 총액−입금), terminated·deleted 제외.
- 분모 ≤0/목표 없음 → null → 화면 '-' 또는 '목표 미설정'/'산출 불가'. 0으로 임의표시 금지.
- `supply_amount + vat_amount = contract_amount` 항상 성립(write-path).
- 담당(sales/site)과 참여(assignment) 구분. 한 프로젝트 매출·순이익을 여러 직원에게 반복 합산 금지.
- 같은 지표는 대시보드·성과·리포트에서 **동일 서비스 메서드**로 계산(값 불일치 금지).
- 모든 쿼리 `Db` prepared statement. `Calc`/`AccountingService` 재사용, 산식 중복 금지.
- 기존 seed 기준 정상값: 완료 프로젝트 0건 → 확정매출·확정순이익·직원 확정기여 **모두 0**(정상). 계약1 공급 34,000,000, 이번달(2026-07) 수주액 34,000,000. 미수금 = 계약1 37,462,250 − 입금 11,238,675 = 26,223,575.

**참조:** 스펙 `docs/superpowers/specs/2026-07-22-eden-crm-accounting-and-ui-audit-design.md` (§1·2·7·10·12), P1 계획.

---

### Task 1: AccountingService P2 확장 (write-path split·확정원가·귀속매출·파이프라인) + 테스트

**Files:**
- Modify: `app/core/AccountingService.php`
- Create: `scripts/tests/unit_p2_service.php`
- Modify: `scripts/tests/run.php` (스위트 목록에 `unit_p2_service` 추가)

**Interfaces (Produces):**
- `computeSplit(int $contractAmount, ?int $quoteId = null): array` → `['supply'=>int, 'vat'=>int]`. quote 연결·total>0이면 `vat = round(contractAmount × qv.vat / qv.total_amount)`(계약금액 편집에도 비례), 아니면 `vat = deriveVat(contractAmount)`. `supply = contractAmount − vat`.
- `confirmedCost(?string $from=null, ?string $to=null): int` — 완료 프로젝트 Σ`actual_cost`(actual_end_date 기준).
- `employeeConfirmedRevenue(int $uid, ?string $from=null, ?string $to=null): int` — 완료 프로젝트 Σ`(supply_amount × contribution_pct/100)`(귀속 매출; 가중 순이익률 분모).
- `weightedPipeline(?int $uid=null): int` — open 리드 Σ`weightedRevenue(expected_amount, win_probability)`.

- [ ] **Step 1: 실패 테스트 작성** — Create `scripts/tests/unit_p2_service.php`

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
echo "AccountingService P2 확장\n";

// computeSplit: 견적1 연결(총액 37,462,250 = 공급 34,000,000 + vat 3,462,250)
$s = AccountingService::computeSplit(37462250, 1);
t_int('split(견적1) 공급', 34000000, $s['supply']);
t_int('split(견적1) 부가세', 3462250, $s['vat']);
t_int('split 정합', 37462250, $s['supply'] + $s['vat']);
// 견적 없음 → ÷1.1
$s2 = AccountingService::computeSplit(18500000, null);
t_int('split(무견적) 공급', 16818182, $s2['supply']);
t_int('split(무견적) 정합', 18500000, $s2['supply'] + $s2['vat']);

// seed: 완료 프로젝트 0 → 확정원가 0
t_int('확정원가(seed)=0', 0, AccountingService::confirmedCost());
// seed: 직원2 귀속매출(완료 없음)=0
t_int('직원2 귀속매출(seed)=0', 0, AccountingService::employeeConfirmedRevenue(2));
// weightedPipeline: seed 리드 없음 → 0 (leads 미시딩)
t_int('가중 파이프라인(seed)=0', 0, AccountingService::weightedPipeline());

exit(t_summary());
```

- [ ] **Step 2: 실행 → 실패 확인** — `php scripts/tests/unit_p2_service.php` → FAIL(computeSplit 미정의).

- [ ] **Step 3: 구현** — Modify `app/core/AccountingService.php` (클래스에 추가)

```php
    /** 계약금액을 공급가/부가세로 분리. 견적 연결·total>0이면 견적 vat 비례, 아니면 ÷(1+rate). */
    public static function computeSplit(int $contractAmount, ?int $quoteId = null): array
    {
        $vat = null;
        if ($quoteId) {
            $row = Db::one("SELECT qv.vat, qv.total_amount FROM quotes q
                JOIN quote_versions qv ON qv.id = q.current_version_id WHERE q.id = :id", [':id' => $quoteId]);
            if ($row && (int) $row['total_amount'] > 0) {
                $vat = (int) round($contractAmount * (int) $row['vat'] / (int) $row['total_amount']);
            }
        }
        if ($vat === null) { $vat = self::deriveVat($contractAmount); }
        return ['supply' => $contractAmount - $vat, 'vat' => $vat];
    }

    /** 확정(완료) 실제원가 합. */
    public static function confirmedCost(?string $from = null, ?string $to = null): int
    {
        $p = [];
        $r = self::range('actual_end_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(actual_cost),0) FROM projects
            WHERE deleted_at IS NULL AND status='completed' AND actual_end_date IS NOT NULL $r", $p);
    }

    /** 직원 귀속 확정매출(완료 프로젝트 Σ 공급가×기여도) — 가중 순이익률 분모. */
    public static function employeeConfirmedRevenue(int $uid, ?string $from = null, ?string $to = null): int
    {
        $p = [':u' => $uid];
        $r = self::range('p.actual_end_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(p.supply_amount * pa.contribution_pct/100),0)
            FROM project_assignments pa JOIN projects p ON p.id=pa.project_id
            WHERE p.deleted_at IS NULL AND p.status='completed' AND p.actual_end_date IS NOT NULL
              AND pa.user_id=:u $r", $p);
    }

    /** open 리드 가중 예상매출 합. $uid=null 전체. */
    public static function weightedPipeline(?int $uid = null): int
    {
        $scope = $uid !== null ? ' AND l.sales_user_id=:u' : '';
        $p = $uid !== null ? [':u' => $uid] : [];
        $sum = 0.0;
        foreach (Db::all("SELECT l.expected_amount, l.win_probability FROM leads l
            JOIN pipeline_stages ps ON ps.id=l.stage_id
            WHERE l.deleted_at IS NULL AND ps.is_won=0 AND ps.is_lost=0 $scope", $p) as $l) {
            $sum += Calc::weightedRevenue((float) ($l['expected_amount'] ?? 0), (float) ($l['win_probability'] ?? 0));
        }
        return (int) round($sum);
    }
```

- [ ] **Step 4: run.php 스위트 목록 갱신** — Modify `scripts/tests/run.php`: `$suites` 배열에 `'unit_p2_service'` 를 `'unit_profit'` 뒤에 추가.

- [ ] **Step 5: 실행 → 통과** — `php scripts/tests/unit_p2_service.php` → PASS 8 · FAIL 0. 그리고 `php scripts/tests/run.php` → 전체 통과.

- [ ] **Step 6: 커밋**
```bash
git add app/core/AccountingService.php scripts/tests/unit_p2_service.php scripts/tests/run.php
git commit -m "feat(acct): P2 서비스 확장(computeSplit·확정원가·귀속매출·가중파이프라인) + 테스트

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Write-path — 계약·프로젝트 저장 시 공급가/부가세 채우기 + 프로젝트 P&L 공급가 기준

**Files:**
- Modify: `app/controllers/ContractsController.php` (`save`, `toProject`)
- Modify: `app/controllers/ProjectsController.php` (`save`, `show`)

**Consumes:** `AccountingService::computeSplit`, `supplyOf`, `projectActualProfit`, `projectActualProfitRate`.

- [ ] **Step 1: ContractsController::save — split 저장.** `save()`에서 `$data` 배열(현 `app/controllers/ContractsController.php:217-231`)에 계약금액 분리를 추가한다. `$data` 구성 직후:
```php
$split = AccountingService::computeSplit((int) $contractAmount, $quoteId);
$data['supply_amount'] = $split['supply'];
$data['vat_amount']    = $split['vat'];
```
(신규·수정 모두 반영되도록 `$data`에 넣는다. `$quoteId`는 이미 위에서 계산됨.)

- [ ] **Step 2: ContractsController::toProject — 계약의 split 승계.** `toProject()`의 `Db::insert('projects', [...])`(현 `:332-350`)에 계약의 값을 프로젝트 자기 계약액 기준으로 파생해 추가:
```php
'supply_amount'    => (int) $contract['contract_amount'] - (int) ($contract['vat_amount'] ?? AccountingService::deriveVat((int) $contract['contract_amount'])),
'vat_amount'       => (int) ($contract['vat_amount'] ?? AccountingService::deriveVat((int) $contract['contract_amount'])),
```
(프로젝트 contract_amount = 계약 contract_amount 이므로 invariant 성립.)

- [ ] **Step 3: ProjectsController::save — split 저장.** `save()`의 `$data`(현 `:278-298`)에 추가:
```php
$camt = (int) $data['contract_amount'];
$linkedVat = null;
if ($id) { $linkedVat = Db::val("SELECT c.vat_amount FROM contracts c JOIN projects p ON p.contract_id=c.id WHERE p.id=:id", [':id'=>$id]); }
$vat = $linkedVat !== null ? (int) round($camt * ((int)$linkedVat) / max(1,(int)Db::val("SELECT contract_amount FROM contracts c JOIN projects p ON p.contract_id=c.id WHERE p.id=:id",[':id'=>$id]))) : AccountingService::deriveVat($camt);
$data['vat_amount'] = $vat;
$data['supply_amount'] = $camt - $vat;
```
간결화를 위해, 연결 계약이 없거나 신규면 `AccountingService::computeSplit($camt, null)` 사용:
```php
$split = AccountingService::computeSplit((int) $data['contract_amount'], null);
$data['supply_amount'] = $split['supply'];
$data['vat_amount']    = $split['vat'];
```
(프로젝트 폼에는 quote 연결이 없으므로 ÷1.1 파생이 타당. 연결 계약 프로젝트는 대개 toProject로 생성되어 이미 채워짐. 이 단순형을 사용한다.)

- [ ] **Step 4: ProjectsController::show — P&L 공급가 기준.** `show()`의 `$calc`(현 `:143-158`)를 공급가 기준으로 교체:
```php
$supply = AccountingService::supplyOf($project);
$calc = [
    'contract_amount'       => $contractAmount,
    'supply_amount'         => $supply,
    'vat_amount'            => AccountingService::vatOf($project),
    'estimated_cost'        => $estimatedCost,
    'actual_cost'           => $actualCost,
    'estimated_profit'      => Calc::profit($supply, $estimatedCost),
    'estimated_profit_rate' => Calc::profitRate($supply, $estimatedCost),
    'actual_profit'         => Calc::profit($supply, $actualCost),
    'actual_profit_rate'    => Calc::profitRate($supply, $actualCost),
];
```
(주의: `$project`에 `supply_amount`/`vat_amount` 컬럼이 SELECT `p.*`로 포함됨.)

- [ ] **Step 5: 검증 — 저장 경로 무결성.** dev DB에서 기존 계약1을 재저장하지 않고, `computeSplit` 단위테스트(Task1)로 로직을 신뢰. 추가로 `php -l` 로 두 컨트롤러 문법 확인:
```bash
php -l app/controllers/ContractsController.php && php -l app/controllers/ProjectsController.php
```
그리고 invariant 재확인:
```bash
mysql --socket=.devdb/mysql.sock -ueden_crm_user -p'EdenCrm!local2026' eden_crm -e "SELECT COUNT(*) v FROM projects WHERE deleted_at IS NULL AND supply_amount+vat_amount<>contract_amount;"
```
→ 0.

- [ ] **Step 6: 커밋**
```bash
git add app/controllers/ContractsController.php app/controllers/ProjectsController.php
git commit -m "feat(acct): write-path 공급가/부가세 저장(계약·프로젝트) + 프로젝트 P&L 공급가 기준

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: 사장 대시보드 → AccountingService (KPI·재무·직원성과 재구성)

**Files:**
- Modify: `app/controllers/DashboardController.php` (`bossKpi`, `finance`, `staffPerformance`, `receivableTotal`/`receivableCount`, `monthlyTrend`, `monthRevenue`/`monthCost` 정리)
- Modify: `app/views/dashboard/boss.php` (KPI 라벨·재무 패널·직원 성과 표)

**Consumes:** `AccountingService::confirmedRevenue/confirmedProfit/confirmedCost/contractedAmount/expectedRevenue/receivable/weightedPipeline/employeeConfirmedContribution/employeeConfirmedRevenue/companyConfirmedProfit/achievement`.

- [ ] **Step 1: bossKpi 재정의.** 월 범위 `$mFrom=date('Y-m-01'); $mTo=date('Y-m-t');` 계산 후:
  - `revenue` = `AccountingService::confirmedRevenue($mFrom,$mTo)` (라벨 "이번 달 확정매출", 전월 델타는 confirmedRevenue 전월 범위).
  - `profit`  = `AccountingService::confirmedProfit($mFrom,$mTo)` (라벨 "이번 달 확정순이익").
  - `active`/`delayed`/`pending` 유지.
  - `recv` = `AccountingService::receivable()`.
  기존 `monthRevenue()`/`monthCost()`는 confirmedRevenue/Cost로 대체(삭제 또는 위임). 전월 델타용 범위: `date('Y-m-01', strtotime('first day of last month'))` ~ `date('Y-m-t', strtotime('last month'))`.

- [ ] **Step 2: finance() 재정의.** 반환 키를 서비스 기반으로:
```php
return [
  'revenue'        => AccountingService::confirmedRevenue($mFrom,$mTo),   // 확정매출
  'contracted'     => AccountingService::contractedAmount($mFrom,$mTo),   // 이번달 수주액(신규)
  'pipeline'       => AccountingService::weightedPipeline(),              // 가중 예상매출
  'expected_rev'   => AccountingService::expectedRevenue(),               // 진행+미착공 공급가
  'actual_cost'    => AccountingService::confirmedCost($mFrom,$mTo),
  'confirmed_profit'=> AccountingService::confirmedProfit($mFrom,$mTo),
  'profit_rate'    => Calc::profitRate((float)AccountingService::confirmedRevenue($mFrom,$mTo), (float)AccountingService::confirmedCost($mFrom,$mTo)),
  'receivable'     => AccountingService::receivable(),
  'goal'           => $this->goal(null),  // goal actual 도 confirmedRevenue 로 (아래 Step 3)
];
```

- [ ] **Step 3: goal() actual = 확정매출.** `goal(null)`의 `$actual = $this->monthRevenue(0)` 를 `AccountingService::confirmedRevenue($mFrom,$mTo)` 로, 개인 목표 `goal($uid)`의 actual 은 `AccountingService::contractedAmount` 를 sales_user 범위로 — 단, 개인 범위 메서드가 없으므로 이 태스크에서는 회사 목표만 서비스로 바꾸고 개인은 Task4에서 처리(여기서는 `goal(null)`만 수정). `rate = AccountingService::achievement($actual,$target)`.

- [ ] **Step 4: staffPerformance() 전면 재작성.** 각 직원(sales_manager/site_manager/staff)에 대해:
```php
$out[] = [
  'user_id'   => $id,
  'name'      => $usr['name'],
  'role'      => $roleLabel[$usr['role_key']] ?? '직원',
  'assigned'  => (담당 프로젝트수: sales_user/site_manager/assignment DISTINCT — 기존 cntBy 유지),
  'contracted'=> (int) Db::val("SELECT COALESCE(SUM(supply_amount),0) FROM projects WHERE deleted_at IS NULL AND status<>'cancelled' AND sales_user_id=:u AND contract_date BETWEEN :f AND :t", [':u'=>$id,':f'=>$mFrom,':t'=>$mTo]), // 담당 수주액(공급, 이번달)
  'contrib'   => AccountingService::employeeConfirmedContribution($id),        // 확정 순이익 기여(전기간 완료)
  'attr_rev'  => AccountingService::employeeConfirmedRevenue($id),             // 귀속 확정매출
  'margin'    => Calc::profitRate((float)AccountingService::employeeConfirmedRevenue($id), (float)AccountingService::employeeConfirmedRevenue($id) - (float)AccountingService::employeeConfirmedContribution($id)), // 가중 순이익률 = 기여÷귀속매출; 아래 주: rate = contrib/attr_rev*100
  'company_rate' => AccountingService::achievement((float)AccountingService::employeeConfirmedContribution($id), (float)AccountingService::companyConfirmedProfit()), // 회사 순이익 기여율
  'ontime'    => (기존 onBy 일정 준수율 유지),
];
```
정확한 가중 순이익률은 `margin = Calc::rate(contrib, attr_rev)`(귀속순이익÷귀속매출×100, attr_rev≤0→null). company_rate 는 `Calc::rate(contrib, companyConfirmedProfit)`.
표시·정렬은 contrib 큰 순. 회사 전체 확정순이익 `companyConfirmedProfit()`은 루프 밖에서 1회 조회해 재사용(N+1 방지).

- [ ] **Step 5: boss.php 뷰 갱신.**
  - KPI: "이번 달 확정매출"(그대로), 2번째 카드 라벨을 "이번 달 확정순이익"으로, note는 값<0이면 '적자'.
  - 재무 패널(`:96-103`): 항목을 확정매출·이번달 수주액·예상매출(가중)·실제원가·확정순이익률·미수금으로. `$finance` 새 키 사용.
  - 직원 성과 표(`:167-183`): 컬럼을 `직원 · 담당 · 이번달 수주(담당) · 순이익 기여(확정) · 순이익률(가중) · 회사기여율 · 일정준수`로 교체. 각 금액 셀 `moneyCell()`(정확값 tooltip). 순이익률/기여율은 `pct()`(null→'-'). **직원명 클릭 → `performance.user?id=` 링크(근거: 구성 프로젝트).** 헤더에 산정기준 tooltip: "순이익 기여=완료 프로젝트만, 공급가 기준".

- [ ] **Step 6: 검증.** `php -l` 대시보드/뷰. 그리고 seed 기대값 테스트를 `scripts/tests/unit_p2_service.php`에 추가(확정매출 이번달=0, 수주액 이번달=34,000,000, receivable=26,223,575):
```php
$mf=date('Y-m-01'); $mt=date('Y-m-t');
t_int('이번달 확정매출(seed)=0', 0, AccountingService::confirmedRevenue($mf,$mt));
t_int('이번달 수주액(seed)=34,000,000', 34000000, AccountingService::contractedAmount($mf,$mt));
t_int('미수금(seed)=26,223,575', 26223575, AccountingService::receivable());
```
`php scripts/tests/run.php` → 전체 통과.

- [ ] **Step 7: 커밋**
```bash
git add app/controllers/DashboardController.php app/views/dashboard/boss.php scripts/tests/unit_p2_service.php
git commit -m "feat(dash): 사장 대시보드 AccountingService 통합(확정매출·확정순이익·미수금·직원성과 재구성)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: 성과(Performance) 컨트롤러·뷰 → AccountingService

**Files:**
- Modify: `app/controllers/PerformanceController.php` (`computePerformance`, `user`)
- Modify: `app/views/performance/index.php`, `app/views/performance/user.php`

- [ ] **Step 1: computePerformance 공급가·완료·가중 기준.** 완료 프로젝트 매출/원가/순이익을 공급가 기준으로, 평균순이익률을 **가중**(Σ순이익/Σ공급가)으로 교체:
  - `total_revenue` = `AccountingService::employeeConfirmedRevenue($uid)`(귀속) 또는 담당 완료 공급가 합(정책: 귀속 사용).
  - `total_profit` = `AccountingService::employeeConfirmedContribution($uid)`.
  - `avg_profit_rate` = `Calc::rate(total_profit, total_revenue)`(가중; total_revenue≤0→null). 기존 per-project 평균 삭제.
  - 월 매출/달성률: `month_revenue` = 담당(sales_user) 이번달 수주 공급가; `revenue_achieve_rate = AccountingService::achievement(month_revenue, target_revenue)`.
- [ ] **Step 2: user() 기여 상세 공급가 기준.** `contributionRows`의 `projectProfit`을 `AccountingService::projectActualProfit($a)`(공급 기준, 단 완료만 확정으로 표시), `companyProfit = AccountingService::companyConfirmedProfit()`, `companyContributionRate = Calc::rate($totalContribution, $companyProfit)`. 진행중 프로젝트는 별도 '예상' 표기(완료만 확정 합산).
- [ ] **Step 3: 뷰 갱신.** `performance/index.php`·`user.php`의 매출/순이익/순이익률 라벨을 "확정매출/확정순이익/순이익률(가중)"으로, null→'-'/'산출 불가'. 작업일지 작성률 컬럼은 P3에서 기능 플래그로 감싸므로 이 태스크에선 유지.
- [ ] **Step 4: 검증.** `php -l` 컨트롤러·뷰. `php scripts/tests/run.php` 통과. 대시보드 staffPerformance 와 performance computePerformance 가 동일 직원에 대해 동일 contrib/margin 을 반환하는지 seed로 확인(둘 다 서비스 사용 → 일치).
- [ ] **Step 5: 커밋** `git commit -m "feat(perf): 성과 화면 공급가·완료·가중 순이익률 통합"` (+trailer).

---

### Task 5: 리포트(Reports) 컨트롤러·뷰 → AccountingService

**Files:**
- Modify: `app/controllers/ReportsController.php` (월별추이·프로젝트손익·미수금·목표달성)
- Modify: `app/views/reports/index.php` (라벨)

- [ ] **Step 1: 월별 추이 준공·공급 기준.** 월별 매출=완료 프로젝트 공급가(actual_end_date 월), 순이익=공급−actual_cost. `AccountingService::confirmedRevenue/confirmedProfit`를 월 루프로 호출(6개월). VAT포함 contract_amount·contract_date 기준 제거.
- [ ] **Step 2: 프로젝트별 손익 공급가.** per-project `AccountingService::projectActualProfit/Rate($row)` 사용(공급 기준). SELECT에 `supply_amount` 포함.
- [ ] **Step 3: 미수금·목표 통합.** 미수금 섹션을 `AccountingService::receivable()` 및 계약별 GREATEST(0,…) 기준으로. 목표달성 actual=confirmedRevenue(기간)/confirmedProfit(기간), `AccountingService::achievement`.
- [ ] **Step 4: 뷰 라벨.** "확정매출/확정순이익", null 상태 표기.
- [ ] **Step 5: 검증.** `php -l`. `php scripts/tests/run.php` 통과. 리포트 월별 합계 = 대시보드 monthlyTrend 동일값(둘 다 서비스).
- [ ] **Step 6: 커밋** `git commit -m "feat(reports): 리포트 준공·공급가 기준 통합(월별추이·손익·미수금·목표)"` (+trailer).

---

### Task 6: 영업/현장/직원 대시보드 금액 지표 → AccountingService + 스모크

**Files:**
- Modify: `app/controllers/DashboardController.php` (`salesKpi`, `goal($uid)`, `finance`가 sales에 쓰이면 정리)
- Modify: `app/views/dashboard/sales.php` (라벨; site/staff 는 금액 비노출이라 변경 최소)

- [ ] **Step 1: salesKpi 통합.** `pipeline` = `AccountingService::weightedPipeline($uid)`, `revenue`(이번달 내 수주) = 공급 기준 `AccountingService::contractedAmount($mFrom,$mTo)`를 sales_user 범위로 계산하는 내부 헬퍼(또는 인라인 supply 합, sales_user_id=$uid, contract_date 이번달). 전환율/계약임박/오늘연락 유지.
- [ ] **Step 2: 개인 goal actual 공급 기준.** `goal($uid)` actual = sales_user 이번달 수주 공급 합. `achievement` 사용.
- [ ] **Step 3: sales.php 라벨** "예상매출/이번달 수주(공급)". site/staff 는 금액 비노출 확인만.
- [ ] **Step 4: 전체 스모크.** 변경된 전 PHP 파일 `php -l` 일괄:
```bash
for f in app/controllers/DashboardController.php app/controllers/PerformanceController.php app/controllers/ReportsController.php app/controllers/ContractsController.php app/controllers/ProjectsController.php app/core/AccountingService.php app/views/dashboard/boss.php app/views/dashboard/sales.php; do php -l "$f" || echo "LINT FAIL $f"; done
```
그리고 `php scripts/tests/run.php` → 전체 통과.
- [ ] **Step 5: 커밋** `git commit -m "feat(dash): 영업/현장/직원 대시보드 금액지표 서비스 통합 + 스모크"` (+trailer).

---

## Self-Review

**Spec coverage:** write-path(스펙 P2 1순위)=T2 ✓ · 대시보드 통합=T3·T6 ✓ · 성과=T4 ✓ · 리포트=T5 ✓ · 동일지표 단일서비스=전 태스크 ✓ · 담당vs참여·가중순이익률·회사기여율=T3·T4 ✓ · null 상태표기=T3~T5 ✓ · 근거(직원→performance.user 링크)=T3 ✓.

**주의(실행자용):** 뷰 편집은 기존 partial·CSS 클래스를 유지하며 데이터 키만 교체(레이아웃 대공사는 P4). 각 컨트롤러 메서드는 `$mFrom/$mTo` 월 범위를 한 번 계산해 재사용. `companyConfirmedProfit()`·`confirmedRevenue()` 등은 루프 밖 1회 조회(N+1 금지). seed 완료 프로젝트가 0이라 확정값 다수가 0으로 표시되는 것은 정상(P5 시드에서 완료 프로젝트 추가 예정).

**드릴다운:** 본 계획은 직원 성과→performance.user(구성 프로젝트) 링크로 최소 근거를 제공. KPI 카드별 상세 모달(스펙 §12 풀 드릴다운)은 P4 UI에서 여력에 따라 추가(미완 시 운영 제한사항으로 보고).
