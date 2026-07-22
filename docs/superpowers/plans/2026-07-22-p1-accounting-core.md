# P1 회계 코어 (AccountingService · 공급가/부가세 · 대사테스트) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 모든 매출·원가·순이익·기여액·달성률 계산의 단일 출처인 `AccountingService`와 공급가/부가세 분리 스키마를 만들고, 대사 테스트 A~G로 회계 정합성을 검증한다.

**Architecture:** 프레임워크 없는 순수 PHP. 코어 클래스를 `app/core/`에 추가하고 `app/bootstrap.php`의 로드 목록에 등록한다. Composer/PHPUnit 없이 `scripts/tests/`의 경량 PHP 러너로 TDD한다(격리 개발 MySQL `.devdb:3307` 사용). 손익 축은 공급가액(VAT 제외), 현금 축은 계약총액(VAT 포함)으로 분리한다.

**Tech Stack:** PHP 8.2 (PDO), MySQL 8(.devdb 격리 인스턴스), 표준 라이브러리만.

## Global Constraints

- 손익(매출·순이익·순이익률·성과·기여액·달성률)은 **공급가액(supply_amount, VAT 제외)**. 현금(입금·미수금)은 **계약총액(contract_amount, VAT 포함)**. — 스펙 1.1
- `supply_amount + vat_amount = contract_amount` 항상 성립. 견적 연결 시 `vat_amount = quote_version.vat`, 미연결 시 `vat_amount = contract_amount − round(contract_amount / (1 + vat_rate/100))`. — 스펙 1.2
- 확정 매출/순이익 = `status='completed'` 프로젝트, 기준일 `actual_end_date`. 예상 = preparing/in_progress. 취소(cancelled)·soft-delete(`deleted_at IS NOT NULL`)는 전 집계에서 제외. — 스펙 2·3
- 분모 ≤ 0 → `null`(0% 임의 표시 금지). 목표 없음 → null('목표 미설정'). — 스펙 7
- 금액은 정수 원 단위(`int`). 순이익 음수(적자) 그대로 반환. — 스펙 1.2
- 기존 `Calc` 원자 함수는 유지·재사용(중복 로직 금지). `AccountingService`는 정책(공급가 기준)을 실어 `Calc`을 호출한다.
- DB 접속·쿼리는 `Db`(PDO Prepared Statement) 경유. 원시 문자열 보간 금지.
- 코어 신규 클래스는 `app/bootstrap.php:11-13` 로드 배열에 등록해야 웹에서 사용 가능.

**참조 문서:** `docs/superpowers/specs/2026-07-22-eden-crm-accounting-and-ui-audit-design.md`

**시드 유래 검증값(견적1/계약1/프로젝트1):** contract_amount 37,462,250 · quote vat 3,462,250 → supply 34,000,000 / vat 3,462,250. 프로젝트2 18,500,000(견적無) → vat 1,681,818 / supply 16,818,182. 프로젝트3 9,800,000 → vat 890,909 / supply 8,909,091.

---

### Task 1: 테스트 러너 + AccountingService 금액 원자(공급가/부가세)

**Files:**
- Create: `scripts/tests/lib.php` (assert 헬퍼)
- Create: `scripts/tests/bootstrap.php` (CLI 코어 로드)
- Create: `scripts/tests/unit_money.php` (테스트)
- Create: `app/core/AccountingService.php`
- Modify: `app/bootstrap.php:11-13` (로드 목록에 `AccountingService` 추가)

**Interfaces:**
- Produces:
  - `AccountingService::vatRate(): float` — 설정 vat_rate(기본 10)
  - `AccountingService::deriveVat(int $contract): int` — `$contract − (int) round($contract / (1 + vatRate/100))`
  - `AccountingService::supplyOf(array $row): int` — `supply_amount`>0 우선, 없으면 `contract_amount − vat_amount`, 그것도 없으면 `contract_amount − deriveVat(contract_amount)`. `$row['contract_amount']` 필수.
  - `AccountingService::vatOf(array $row): int` — `contract_amount − supplyOf($row)`
- Consumes: `Calc`, `Db`, `$GLOBALS['settings']`, `$GLOBALS['config']`

- [ ] **Step 1: assert 라이브러리 작성** — Create `scripts/tests/lib.php`

```php
<?php
/** 경량 테스트 러너 — Composer/PHPUnit 없이 사용. */
$GLOBALS['__T'] = ['pass' => 0, 'fail' => 0, 'fails' => []];

function t_int(string $label, int $expected, $actual): void {
    $a = (int) $actual;
    _t_record($a === $expected, $label, $expected, $a);
}
function t_float(string $label, ?float $expected, $actual, float $eps = 0.01): void {
    if ($expected === null) { _t_record($actual === null, $label, 'null', var_export($actual, true)); return; }
    if ($actual === null)   { _t_record(false, $label, (string) $expected, 'null'); return; }
    _t_record(abs((float) $actual - $expected) <= $eps, $label, (string) $expected, (string) $actual);
}
function t_null(string $label, $actual): void {
    _t_record($actual === null, $label, 'null', var_export($actual, true));
}
function t_true(string $label, bool $cond): void {
    _t_record($cond, $label, 'true', $cond ? 'true' : 'false');
}
function _t_record(bool $ok, string $label, $exp, $act): void {
    if ($ok) { $GLOBALS['__T']['pass']++; }
    else { $GLOBALS['__T']['fail']++; $GLOBALS['__T']['fails'][] = "$label — 기대 $exp, 실제 $act"; }
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
}
function t_summary(): int {
    $p = $GLOBALS['__T']['pass']; $f = $GLOBALS['__T']['fail'];
    echo "\n──────── 결과: PASS $p · FAIL $f ────────\n";
    foreach ($GLOBALS['__T']['fails'] as $m) { echo "  ✗ $m\n"; }
    return $f > 0 ? 1 : 0;
}
```

- [ ] **Step 2: CLI 부트스트랩 작성** — Create `scripts/tests/bootstrap.php`

```php
<?php
/** CLI 테스트 부트스트랩 — 세션/HTTP 없이 config 상수 + 코어 클래스만 로드. */
error_reporting(E_ALL);
ini_set('display_errors', '1');
$GLOBALS['config'] = require __DIR__ . '/../../app/config/config.php'; // APP_PATH 등 상수 define + 배열 반환
foreach (['Util', 'Db', 'Calc', 'Settings', 'AccountingService'] as $c) {
    $f = APP_PATH . '/core/' . $c . '.php';
    if (is_file($f)) { require_once $f; }
}
try {
    $map = [];
    foreach (Db::all("SELECT setting_key, value FROM settings") as $r) { $map[$r['setting_key']] = $r['value']; }
    $GLOBALS['settings'] = $map;
} catch (\Throwable $e) { $GLOBALS['settings'] = []; }
```

- [ ] **Step 3: 실패하는 테스트 작성** — Create `scripts/tests/unit_money.php`

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "AccountingService 금액 원자\n";

// vat_rate 기본 10 → 배수 1.1
t_float('vatRate 기본 10', 10.0, AccountingService::vatRate());

// 견적 연결(계약1): vat_amount 저장값 사용
$p1 = ['contract_amount' => 37462250, 'supply_amount' => 34000000, 'vat_amount' => 3462250];
t_int('공급가액(저장값)', 34000000, AccountingService::supplyOf($p1));
t_int('부가세(저장값)', 3462250, AccountingService::vatOf($p1));

// 미저장 → ÷1.1 파생 (프로젝트2)
$p2 = ['contract_amount' => 18500000];
t_int('부가세 파생(P2)', 1681818, AccountingService::vatOf($p2));
t_int('공급가액 파생(P2)', 16818182, AccountingService::supplyOf($p2));

// 파생 정합: supply + vat == contract
$s = AccountingService::supplyOf($p2); $v = AccountingService::vatOf($p2);
t_int('정합 supply+vat=contract', 18500000, $s + $v);

// vat_amount 만 저장(공급 미저장) → contract - vat
$p3 = ['contract_amount' => 9800000, 'vat_amount' => 890909];
t_int('공급가액(vat만 저장)', 8909091, AccountingService::supplyOf($p3));

exit(t_summary());
```

- [ ] **Step 4: 테스트 실행 → 실패 확인**

Run: `php scripts/tests/unit_money.php`
Expected: FAIL — `Class "AccountingService" not found` 또는 메서드 미정의로 오류.

- [ ] **Step 5: AccountingService 금액 원자 구현** — Create `app/core/AccountingService.php`

```php
<?php
/**
 * 회계 집계 단일 출처. 손익=공급가액(VAT 제외), 현금=계약총액(VAT 포함).
 * 정책은 이 클래스가 싣고, 원자 산술은 Calc 을 재사용한다.
 * 스펙: docs/superpowers/specs/2026-07-22-eden-crm-accounting-and-ui-audit-design.md
 */
class AccountingService
{
    /** 부가세율(%) — 설정 vat_rate, 기본 10. */
    public static function vatRate(): float
    {
        $v = $GLOBALS['settings']['vat_rate'] ?? ($GLOBALS['config']['VAT_RATE'] ?? 10);
        return (float) $v;
    }

    /** 계약총액에서 부가세 파생 = contract − round(contract / (1 + rate/100)). */
    public static function deriveVat(int $contract): int
    {
        $rate = self::vatRate();
        return $contract - (int) round($contract / (1 + $rate / 100));
    }

    /** 공급가액(VAT 제외). 저장 supply_amount>0 우선 → vat_amount 있으면 contract−vat → 없으면 파생. */
    public static function supplyOf(array $row): int
    {
        $contract = (int) ($row['contract_amount'] ?? 0);
        if (isset($row['supply_amount']) && (int) $row['supply_amount'] > 0) {
            return (int) $row['supply_amount'];
        }
        if (isset($row['vat_amount']) && $row['vat_amount'] !== null) {
            return $contract - (int) $row['vat_amount'];
        }
        return $contract - self::deriveVat($contract);
    }

    /** 부가세 = 계약총액 − 공급가액. */
    public static function vatOf(array $row): int
    {
        return (int) ($row['contract_amount'] ?? 0) - self::supplyOf($row);
    }
}
```

- [ ] **Step 6: 부트스트랩 로드 목록에 등록** — Modify `app/bootstrap.php:12`

기존:
```php
    'Util', 'Db', 'Response', 'Csrf', 'Audit', 'Auth', 'Rbac', 'View', 'Calc', 'Upload', 'Nav', 'Notif', 'Scope', 'Stages',
```
변경(끝에 추가):
```php
    'Util', 'Db', 'Response', 'Csrf', 'Audit', 'Auth', 'Rbac', 'View', 'Calc', 'Upload', 'Nav', 'Notif', 'Scope', 'Stages', 'Settings', 'AccountingService',
```
(`Settings` 는 Task 3에서 생성 — 이 시점엔 파일이 없어도 web 부트스트랩은 `require` 로 실패하므로, **Task 3 완료 전에는 Settings 항목을 넣지 말고 AccountingService 만 추가**한다. 즉 이 스텝에서는 `'Stages', 'AccountingService',` 까지만.)

정정된 변경:
```php
    'Util', 'Db', 'Response', 'Csrf', 'Audit', 'Auth', 'Rbac', 'View', 'Calc', 'Upload', 'Nav', 'Notif', 'Scope', 'Stages', 'AccountingService',
```

- [ ] **Step 7: 테스트 실행 → 통과 확인**

Run: `php scripts/tests/unit_money.php`
Expected: PASS 7 · FAIL 0

- [ ] **Step 8: 커밋**

```bash
git add scripts/tests/lib.php scripts/tests/bootstrap.php scripts/tests/unit_money.php app/core/AccountingService.php app/bootstrap.php
git commit -m "feat(acct): AccountingService 금액 원자(공급가/부가세) + CLI 테스트 러너

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: 손익 원자(공급가 기준 순이익·순이익률·기여액·달성률)

**Files:**
- Modify: `app/core/AccountingService.php`
- Create: `scripts/tests/unit_profit.php`

**Interfaces:**
- Consumes: Task 1 `supplyOf`, `Calc::profit/profitRate/contribution/achievement`
- Produces:
  - `AccountingService::projectActualProfit(array $p): int` — `supplyOf($p) − (int)$p['actual_cost']`
  - `AccountingService::projectActualProfitRate(array $p): ?float` — `Calc::profitRate(supplyOf($p), actual_cost)` (분모=공급가액)
  - `AccountingService::contribution(int $profit, float $pct): int` — `Calc::contribution` 위임(int 캐스팅)
  - `AccountingService::achievement(?float $actual, ?float $target): ?float` — 목표 ≤0/null → null

- [ ] **Step 1: 실패 테스트 작성** — Create `scripts/tests/unit_profit.php`

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "AccountingService 손익 원자 (대사 A·B·D·E)\n";

// 테스트 A: 공급 100,000,000 · 실제원가 70,000,000 → 순이익 30,000,000 · 률 30%
$A = ['contract_amount' => 110000000, 'supply_amount' => 100000000, 'vat_amount' => 10000000, 'actual_cost' => 70000000];
t_int('A 확정 순이익', 30000000, AccountingService::projectActualProfit($A));
t_float('A 순이익률 30%', 30.0, AccountingService::projectActualProfitRate($A));

// 테스트 B: 공급 50,000,000 · 실제원가 60,000,000 → -10,000,000 · -20%
$B = ['contract_amount' => 55000000, 'supply_amount' => 50000000, 'actual_cost' => 60000000];
t_int('B 적자 순이익', -10000000, AccountingService::projectActualProfit($B));
t_float('B 순이익률 -20%', -20.0, AccountingService::projectActualProfitRate($B));

// 순이익률 분모 0 → null
$Z = ['contract_amount' => 0, 'supply_amount' => 0, 'actual_cost' => 0];
t_null('공급 0 → 률 null', AccountingService::projectActualProfitRate($Z));

// 테스트 D: 프로젝트 확정순이익 20,000,000, A70%/B30%
t_int('D A 기여 70%', 14000000, AccountingService::contribution(20000000, 70.0));
t_int('D B 기여 30%', 6000000, AccountingService::contribution(20000000, 30.0));
t_int('D 합=20,000,000', 20000000,
    AccountingService::contribution(20000000, 70.0) + AccountingService::contribution(20000000, 30.0));

// 테스트 E: 목표 미설정 → 달성률 null
t_null('E 목표 0 → null', AccountingService::achievement(5000000, 0.0));
t_null('E 목표 null → null', AccountingService::achievement(5000000, null));
t_float('달성률 정상', 50.0, AccountingService::achievement(5000000, 10000000));

exit(t_summary());
```

- [ ] **Step 2: 실행 → 실패 확인**

Run: `php scripts/tests/unit_profit.php`
Expected: FAIL — `projectActualProfit` 미정의.

- [ ] **Step 3: 구현 추가** — Modify `app/core/AccountingService.php` (클래스 내부, `vatOf` 아래에 추가)

```php
    /** 확정 순이익 = 공급가액 − 실제원가 (음수=적자 그대로). */
    public static function projectActualProfit(array $p): int
    {
        return (int) Calc::profit(self::supplyOf($p), (float) ($p['actual_cost'] ?? 0));
    }

    /** 확정 순이익률(%) = (공급가액 − 실제원가) ÷ 공급가액 × 100. 공급 ≤0 → null. */
    public static function projectActualProfitRate(array $p): ?float
    {
        return Calc::profitRate((float) self::supplyOf($p), (float) ($p['actual_cost'] ?? 0));
    }

    /** 직원 기여액 = 프로젝트 순이익 × 기여도(%). */
    public static function contribution(int $profit, float $pct): int
    {
        return (int) Calc::contribution((float) $profit, $pct);
    }

    /** 달성률(%) = 실제 ÷ 목표 × 100. 목표 null/≤0 → null('목표 미설정'). */
    public static function achievement(?float $actual, ?float $target): ?float
    {
        if ($target === null || $target <= 0 || $actual === null) { return null; }
        return Calc::achievement($actual, $target);
    }
```

- [ ] **Step 4: 실행 → 통과 확인**

Run: `php scripts/tests/unit_profit.php`
Expected: PASS 11 · FAIL 0

- [ ] **Step 5: 커밋**

```bash
git add app/core/AccountingService.php scripts/tests/unit_profit.php
git commit -m "feat(acct): 공급가 기준 순이익·순이익률·기여액·달성률 원자 (대사 A/B/D/E)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Settings 코어 헬퍼 + feature_worklog 시드

**Files:**
- Create: `app/core/Settings.php`
- Create: `scripts/tests/unit_settings.php`
- Modify: `app/bootstrap.php:12` (로드 목록에 `Settings` 추가)
- Modify: `database/seed_core.sql` (feature_worklog 설정 행 추가)

**Interfaces:**
- Produces:
  - `Settings::get(string $key, $default = null)` — `$GLOBALS['settings'][$key] ?? $default`
  - `Settings::enabled(string $key): bool` — `(string) Settings::get($key, '0') === '1'`
- Consumes: `$GLOBALS['settings']` (bootstrap 가 DB에서 로드)

- [ ] **Step 1: seed_core 에 설정 행 추가** — Modify `database/seed_core.sql`

`settings` INSERT 블록(기존 setting_key 들과 같은 곳)에 아래 행을 추가한다. 그룹은 '운영 기능'.
```sql
INSERT INTO `settings` (`setting_key`, `value`, `group`, `label`) VALUES
  ('feature_worklog', '0', '운영 기능', '직원 작업일지 사용')
ON DUPLICATE KEY UPDATE `group`=VALUES(`group`), `label`=VALUES(`label`);
```
(기존 시드에 `INSERT INTO settings ... VALUES (...)` 형식이 있으면 그 리스트에 `('feature_worklog','0','운영 기능','직원 작업일지 사용')` 한 행만 추가해도 된다. 기본값 `'0'`=사용 안 함.)

- [ ] **Step 2: 개발 DB에 설정 반영** — Run

```bash
mysql --socket=.devdb/mysql.sock -ueden_crm_user -p'EdenCrm!local2026' eden_crm -e \
"INSERT INTO settings (setting_key,value,\`group\`,label) VALUES ('feature_worklog','0','운영 기능','직원 작업일지 사용') ON DUPLICATE KEY UPDATE label=VALUES(label);"
```
Expected: 오류 없이 종료(행 삽입 또는 갱신).

- [ ] **Step 3: 실패 테스트 작성** — Create `scripts/tests/unit_settings.php`

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "Settings 헬퍼 / feature_worklog\n";

t_true('feature_worklog 기본 OFF', Settings::enabled('feature_worklog') === false);
t_true('get 기본값', Settings::get('__none__', 'x') === 'x');
```
(러너 요약은 recon 통합 실행에서 처리하므로 이 파일 단독 실행 시 `exit(t_summary());` 를 마지막 줄에 추가한다.)

```php
exit(t_summary());
```

- [ ] **Step 4: 실행 → 실패 확인**

Run: `php scripts/tests/unit_settings.php`
Expected: FAIL — `Class "Settings" not found`.

- [ ] **Step 5: Settings 구현** — Create `app/core/Settings.php`

```php
<?php
/**
 * 시스템 설정/기능 플래그 조회. bootstrap 이 settings 테이블을 $GLOBALS['settings'] 로 로드한다.
 * 값은 문자열. 플래그는 '1'=사용 / '0'=사용 안 함.
 */
class Settings
{
    public static function get(string $key, $default = null)
    {
        $v = $GLOBALS['settings'][$key] ?? null;
        return $v === null ? $default : $v;
    }

    public static function enabled(string $key): bool
    {
        return (string) self::get($key, '0') === '1';
    }
}
```

- [ ] **Step 6: 부트스트랩 등록** — Modify `app/bootstrap.php:12`

```php
    'Util', 'Db', 'Response', 'Csrf', 'Audit', 'Auth', 'Rbac', 'View', 'Calc', 'Upload', 'Nav', 'Notif', 'Scope', 'Stages', 'AccountingService', 'Settings',
```

- [ ] **Step 7: 실행 → 통과 확인**

Run: `php scripts/tests/unit_settings.php`
Expected: PASS 2 · FAIL 0

- [ ] **Step 8: 커밋**

```bash
git add app/core/Settings.php scripts/tests/unit_settings.php app/bootstrap.php database/seed_core.sql
git commit -m "feat(settings): Settings 헬퍼 + feature_worklog 기본 OFF 시드

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: 스키마 마이그레이션 (supply_amount·vat_amount) + 백필

**Files:**
- Create: `database/migrations/2026-07-22_supply_vat.sql`
- Modify: `database/schema.sql` (contracts, projects DDL에 컬럼 추가)
- Modify: `database/seed_dev.sql` (백필 UPDATE 추가)
- Create: `scripts/tests/db_schema.php`

**Interfaces:**
- Produces: `contracts.supply_amount`, `contracts.vat_amount`, `projects.supply_amount`, `projects.vat_amount` (DECIMAL(14,0) NULL) — 채워진 값이 `supply+vat=contract` 정합.

- [ ] **Step 1: 마이그레이션 SQL 작성** — Create `database/migrations/2026-07-22_supply_vat.sql`

```sql
-- 공급가액/부가세 분리 컬럼 추가 + 백필 (기존 DB 대상, 재실행 안전).
-- contracts
ALTER TABLE `contracts`
  ADD COLUMN IF NOT EXISTS `supply_amount` DECIMAL(14,0) NULL AFTER `contract_amount`,
  ADD COLUMN IF NOT EXISTS `vat_amount`    DECIMAL(14,0) NULL AFTER `supply_amount`;
-- projects
ALTER TABLE `projects`
  ADD COLUMN IF NOT EXISTS `supply_amount` DECIMAL(14,0) NULL AFTER `contract_amount`,
  ADD COLUMN IF NOT EXISTS `vat_amount`    DECIMAL(14,0) NULL AFTER `supply_amount`;

-- 백필 1) 견적 연결 계약: 견적 vat 사용 (총액과 정확 정합)
UPDATE `contracts` c
  JOIN `quotes` q ON q.id = c.quote_id
  JOIN `quote_versions` qv ON qv.id = q.current_version_id
  SET c.vat_amount = qv.vat,
      c.supply_amount = c.contract_amount - qv.vat
  WHERE c.quote_id IS NOT NULL AND (c.supply_amount IS NULL OR c.vat_amount IS NULL);

-- 백필 2) 견적 미연결 계약: ÷1.1 파생 (vat_rate 10 가정)
UPDATE `contracts` c
  SET c.vat_amount = c.contract_amount - ROUND(c.contract_amount / 1.1),
      c.supply_amount = ROUND(c.contract_amount / 1.1)
  WHERE c.supply_amount IS NULL OR c.vat_amount IS NULL;

-- 백필 3) 프로젝트: 연결 계약값 승계
UPDATE `projects` p
  JOIN `contracts` c ON c.id = p.contract_id
  SET p.supply_amount = c.supply_amount, p.vat_amount = c.vat_amount
  WHERE p.contract_id IS NOT NULL AND (p.supply_amount IS NULL OR p.vat_amount IS NULL);

-- 백필 4) 계약 미연결 프로젝트: ÷1.1 파생
UPDATE `projects` p
  SET p.vat_amount = p.contract_amount - ROUND(p.contract_amount / 1.1),
      p.supply_amount = ROUND(p.contract_amount / 1.1)
  WHERE p.supply_amount IS NULL OR p.vat_amount IS NULL;
```

- [ ] **Step 2: 개발 DB에 마이그레이션 적용** — Run

```bash
mysql --socket=.devdb/mysql.sock -ueden_crm_user -p'EdenCrm!local2026' eden_crm < database/migrations/2026-07-22_supply_vat.sql
```
Expected: 오류 없이 종료.

- [ ] **Step 3: 정합 검증 테스트 작성** — Create `scripts/tests/db_schema.php`

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "스키마 백필 정합\n";

// 모든 계약: supply + vat = contract, NULL 없음
$badC = (int) Db::val("SELECT COUNT(*) FROM contracts WHERE deleted_at IS NULL AND (supply_amount IS NULL OR vat_amount IS NULL OR supply_amount+vat_amount<>contract_amount)");
t_int('계약 정합 위반 0', 0, $badC);
$badP = (int) Db::val("SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL AND (supply_amount IS NULL OR vat_amount IS NULL OR supply_amount+vat_amount<>contract_amount)");
t_int('프로젝트 정합 위반 0', 0, $badP);

// 시드 계약1: supply 34,000,000 / vat 3,462,250
$c1 = Db::one("SELECT supply_amount, vat_amount FROM contracts WHERE contract_no='C2026-0001'");
t_int('계약1 공급', 34000000, $c1['supply_amount']);
t_int('계약1 부가세', 3462250, $c1['vat_amount']);

// 시드 프로젝트2(견적無): supply 16,818,182
$p2 = Db::one("SELECT supply_amount, vat_amount FROM projects WHERE project_no='P2026-0002'");
t_int('프로젝트2 공급', 16818182, $p2['supply_amount']);

exit(t_summary());
```

- [ ] **Step 4: 실행 → 통과 확인**

Run: `php scripts/tests/db_schema.php`
Expected: PASS 5 · FAIL 0

- [ ] **Step 5: schema.sql 갱신(신규 설치용)** — Modify `database/schema.sql`

`contracts` DDL 의 `contract_amount` 줄(`database/schema.sql:325`) 바로 아래에 추가:
```sql
  `supply_amount` DECIMAL(14,0) NULL COMMENT '공급가액(VAT 제외, 매출 인식 기준)',
  `vat_amount` DECIMAL(14,0) NULL COMMENT '부가세(예수금)',
```
`projects` DDL 의 `contract_amount` 줄(`database/schema.sql:399`) 바로 아래에 동일 2줄 추가.

- [ ] **Step 6: seed_dev 백필 추가** — Modify `database/seed_dev.sql`

파일 끝의 `SET FOREIGN_KEY_CHECKS = 1;` **직전**에 추가(신규 설치 시에도 시드 데이터가 정합되도록):
```sql
-- 공급가액/부가세 백필 (schema 컬럼 기준)
UPDATE contracts c JOIN quotes q ON q.id=c.quote_id JOIN quote_versions qv ON qv.id=q.current_version_id
  SET c.vat_amount=qv.vat, c.supply_amount=c.contract_amount-qv.vat WHERE c.quote_id IS NOT NULL;
UPDATE contracts c SET c.vat_amount=c.contract_amount-ROUND(c.contract_amount/1.1), c.supply_amount=ROUND(c.contract_amount/1.1) WHERE c.vat_amount IS NULL;
UPDATE projects p JOIN contracts c ON c.id=p.contract_id SET p.supply_amount=c.supply_amount, p.vat_amount=c.vat_amount WHERE p.contract_id IS NOT NULL;
UPDATE projects p SET p.vat_amount=p.contract_amount-ROUND(p.contract_amount/1.1), p.supply_amount=ROUND(p.contract_amount/1.1) WHERE p.vat_amount IS NULL;
```

- [ ] **Step 7: 커밋**

```bash
git add database/migrations/2026-07-22_supply_vat.sql database/schema.sql database/seed_dev.sql scripts/tests/db_schema.php
git commit -m "feat(db): 공급가액/부가세 컬럼 + 백필 마이그레이션 (contracts·projects)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: DB 집계 메서드 (확정매출·예상·수주·미수금·회사순이익·직원기여)

**Files:**
- Modify: `app/core/AccountingService.php`
- Create: `scripts/tests/db_aggregations.php`

**Interfaces:**
- Consumes: Task 4 컬럼(`supply_amount`), `Db`
- Produces (모두 정수 원; 기간 null=전체):
  - `confirmedRevenue(?string $from=null, ?string $to=null): int` — 완료 프로젝트 Σsupply_amount, 기준 `actual_end_date`
  - `confirmedProfit(?string $from=null, ?string $to=null): int` — Σ(supply_amount − actual_cost), 완료, actual_end_date
  - `expectedRevenue(): int` — preparing/in_progress Σsupply_amount
  - `contractedAmount(?string $from=null, ?string $to=null): int` — 취소 아닌 Σsupply_amount, 기준 `contract_date`
  - `receivable(): int` — Σ 계약별 GREATEST(0, contract_amount − Σpaid), terminated·deleted 제외
  - `companyConfirmedProfit(?string $from=null, ?string $to=null): int` — confirmedProfit 별칭(회사 전체·기여율 분모)
  - `employeeConfirmedContribution(int $uid, ?string $from=null, ?string $to=null): int` — Σ((supply−actual_cost)×pct/100), 완료 프로젝트, actual_end_date

- [ ] **Step 1: 실패 테스트 작성 (트랜잭션 픽스처, 롤백)** — Create `scripts/tests/db_aggregations.php`

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "DB 집계 (대사 C·F·G) — 트랜잭션 롤백\n";

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    // 고객 1(픽스처)
    $cid = Db::insert('customers', ['type' => 'company', 'name' => 'TEST대사', 'status' => 'active']);

    // ── 대사 C: 미수금 = 계약총액 100,000,000 − 입금 40,000,000 = 60,000,000 ──
    $recvBefore = AccountingService::receivable();
    $conId = Db::insert('contracts', [
        'contract_no' => 'TC-RECV', 'customer_id' => $cid, 'contract_amount' => 100000000,
        'supply_amount' => 90909091, 'vat_amount' => 9090909, 'status' => 'active', 'payment_status' => 'partial',
    ]);
    Db::insert('payments', ['contract_id' => $conId, 'pay_type' => 'down', 'amount' => 40000000, 'status' => 'paid']);
    Db::insert('payments', ['contract_id' => $conId, 'pay_type' => 'middle', 'amount' => 60000000, 'status' => 'pending']);
    t_int('C 미수금 증분 60,000,000', 60000000, AccountingService::receivable() - $recvBefore);

    // ── 대사 F: 취소 프로젝트는 확정매출 제외 ──
    $revBefore = AccountingService::confirmedRevenue();
    Db::insert('projects', ['project_no' => 'TP-DONE', 'customer_id' => $cid, 'name' => '완료', 'contract_amount' => 55000000,
        'supply_amount' => 50000000, 'vat_amount' => 5000000, 'actual_cost' => 30000000, 'status' => 'completed', 'actual_end_date' => date('Y-m-d')]);
    Db::insert('projects', ['project_no' => 'TP-CANCEL', 'customer_id' => $cid, 'name' => '취소', 'contract_amount' => 22000000,
        'supply_amount' => 20000000, 'vat_amount' => 2000000, 'actual_cost' => 0, 'status' => 'cancelled', 'actual_end_date' => date('Y-m-d')]);
    t_int('F 확정매출 증분=완료분(50,000,000)만', 50000000, AccountingService::confirmedRevenue() - $revBefore);

    // ── 대사 G: 계약1·입금3·비용5·직원2 → 계약액 중복 합산 안 됨 ──
    $revG = AccountingService::confirmedRevenue();
    $gPid = Db::insert('projects', ['project_no' => 'TP-JOIN', 'customer_id' => $cid, 'name' => 'JOIN', 'contract_amount' => 110000000,
        'supply_amount' => 100000000, 'vat_amount' => 10000000, 'actual_cost' => 70000000, 'status' => 'completed', 'actual_end_date' => date('Y-m-d')]);
    $gCon = Db::insert('contracts', ['contract_no' => 'TC-JOIN', 'customer_id' => $cid, 'contract_amount' => 110000000,
        'supply_amount' => 100000000, 'vat_amount' => 10000000, 'status' => 'completed', 'payment_status' => 'paid']);
    for ($i = 0; $i < 3; $i++) { Db::insert('payments', ['contract_id' => $gCon, 'pay_type' => 'etc', 'amount' => 10000000, 'status' => 'paid']); }
    for ($i = 0; $i < 5; $i++) { Db::insert('costs', ['project_id' => $gPid, 'type' => 'actual', 'category' => '자재비', 'amount' => 14000000]); }
    Db::insert('project_assignments', ['project_id' => $gPid, 'user_id' => 2, 'role' => '현장책임자', 'contribution_pct' => 70]);
    Db::insert('project_assignments', ['project_id' => $gPid, 'user_id' => 3, 'role' => '도장작업자', 'contribution_pct' => 30]);
    t_int('G 확정매출 증분=공급 100,000,000(1회)', 100000000, AccountingService::confirmedRevenue() - $revG);

    // G 기여액: 직원2 = (100,000,000-70,000,000)*70% = 21,000,000 (해당 프로젝트분)
    $c2 = AccountingService::employeeConfirmedContribution(2);
    $c3 = AccountingService::employeeConfirmedContribution(3);
    t_true('G 직원2 기여 ≥ 21,000,000', $c2 >= 21000000);
    t_true('G 직원3 기여 ≥ 9,000,000', $c3 >= 9000000);

} finally {
    $pdo->rollBack();
}
exit(t_summary());
```

- [ ] **Step 2: 실행 → 실패 확인**

Run: `php scripts/tests/db_aggregations.php`
Expected: FAIL — `receivable`/`confirmedRevenue` 미정의.

- [ ] **Step 3: 집계 메서드 구현** — Modify `app/core/AccountingService.php` (클래스 내부에 추가)

```php
    // ── 기간 WHERE 헬퍼 (기준일 컬럼 지정) ──
    private static function range(string $col, ?string $from, ?string $to, array &$p): string
    {
        $sql = '';
        if ($from !== null) { $sql .= " AND $col >= :from"; $p[':from'] = $from; }
        if ($to !== null)   { $sql .= " AND $col <= :to";   $p[':to'] = $to; }
        return $sql;
    }

    /** 확정 매출 = 완료 프로젝트 공급가액 합(준공일 기준). */
    public static function confirmedRevenue(?string $from = null, ?string $to = null): int
    {
        $p = [];
        $r = self::range('actual_end_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(supply_amount),0) FROM projects
            WHERE deleted_at IS NULL AND status='completed' AND actual_end_date IS NOT NULL $r", $p);
    }

    /** 확정 순이익 = 완료 프로젝트 (공급가액 − 실제원가) 합. */
    public static function confirmedProfit(?string $from = null, ?string $to = null): int
    {
        $p = [];
        $r = self::range('actual_end_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(supply_amount - actual_cost),0) FROM projects
            WHERE deleted_at IS NULL AND status='completed' AND actual_end_date IS NOT NULL $r", $p);
    }

    /** 회사 확정 순이익(기여율 분모) — confirmedProfit 별칭. */
    public static function companyConfirmedProfit(?string $from = null, ?string $to = null): int
    {
        return self::confirmedProfit($from, $to);
    }

    /** 예상 매출 = 미완료(preparing/in_progress) 프로젝트 공급가액 합. */
    public static function expectedRevenue(): int
    {
        return (int) Db::val("SELECT COALESCE(SUM(supply_amount),0) FROM projects
            WHERE deleted_at IS NULL AND status IN ('preparing','in_progress')");
    }

    /** 수주액 = 취소 아닌 프로젝트 공급가액 합(계약일 기준). */
    public static function contractedAmount(?string $from = null, ?string $to = null): int
    {
        $p = [];
        $r = self::range('contract_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM(supply_amount),0) FROM projects
            WHERE deleted_at IS NULL AND status<>'cancelled' AND contract_date IS NOT NULL $r", $p);
    }

    /** 미수금(현금 축) = Σ 계약별 max(0, 계약총액 − 입금). terminated·삭제 제외. */
    public static function receivable(): int
    {
        return (int) Db::val("SELECT COALESCE(SUM(GREATEST(0,
                c.contract_amount - COALESCE((SELECT SUM(pm.amount) FROM payments pm WHERE pm.contract_id=c.id AND pm.status='paid'),0)
            )),0)
            FROM contracts c WHERE c.deleted_at IS NULL AND c.status<>'terminated'");
    }

    /** 직원 확정 기여액 = Σ 완료 프로젝트 (공급−실제원가) × 기여도. */
    public static function employeeConfirmedContribution(int $uid, ?string $from = null, ?string $to = null): int
    {
        $p = [':u' => $uid];
        $r = self::range('p.actual_end_date', $from, $to, $p);
        return (int) Db::val("SELECT COALESCE(SUM((p.supply_amount - p.actual_cost) * pa.contribution_pct/100),0)
            FROM project_assignments pa JOIN projects p ON p.id=pa.project_id
            WHERE p.deleted_at IS NULL AND p.status='completed' AND p.actual_end_date IS NOT NULL
              AND pa.user_id=:u $r", $p);
    }
```

- [ ] **Step 4: 실행 → 통과 확인**

Run: `php scripts/tests/db_aggregations.php`
Expected: PASS 6 · FAIL 0 (트랜잭션 롤백으로 개발 DB 무변화)

- [ ] **Step 5: 커밋**

```bash
git add app/core/AccountingService.php scripts/tests/db_aggregations.php
git commit -m "feat(acct): DB 집계(확정매출·예상·수주·미수금·회사순이익·직원기여) — 대사 C/F/G

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: 대사 테스트 A~G 통합 러너 + 요약표

**Files:**
- Create: `scripts/tests/run.php` (전체 스위트 실행 + A~G 대조표)
- Create: `scripts/run_acct_tests.sh` (편의 실행 스크립트)

**Interfaces:**
- Consumes: Task 1~5 전 테스트 파일
- Produces: `php scripts/tests/run.php` 단일 명령으로 전 스위트 실행, A~G 통과 여부 표 출력, 실패 시 종료코드 1.

- [ ] **Step 1: 통합 러너 작성** — Create `scripts/tests/run.php`

```php
<?php
/** 전체 회계 테스트 스위트 실행 + A~G 대사표. */
$suites = ['unit_money', 'unit_profit', 'unit_settings', 'db_schema', 'db_aggregations'];
$fail = 0;
foreach ($suites as $s) {
    echo "\n=== $s ===\n";
    $code = 0; passthru('php ' . escapeshellarg(__DIR__ . "/$s.php"), $code);
    $fail += $code;
}
echo "\n================ 대사 테스트 A~G 커버리지 ================\n";
$map = [
    'A 정상이익(공급100M·원가70M→순이익30M·30%)' => 'unit_profit',
    'B 적자(공급50M·원가60M→-10M·-20%)'          => 'unit_profit',
    'C 부분입금(총액100M·입금40M→미수60M)'        => 'db_aggregations',
    'D 2인 기여(순이익20M·70/30→14M/6M)'          => 'unit_profit',
    'E 목표 미설정(NULL→달성률 null)'             => 'unit_profit',
    'F 계약취소(확정매출 제외)'                   => 'db_aggregations',
    'G 중복 JOIN 방지(계약액 1회)'                => 'db_aggregations',
];
foreach ($map as $case => $suite) { printf("  %-45s → %s\n", $case, $suite); }
echo ($fail === 0 ? "\n✅ 전체 통과\n" : "\n❌ 실패 스위트 존재\n");
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 편의 스크립트 작성** — Create `scripts/run_acct_tests.sh`

```bash
#!/usr/bin/env bash
# 회계 대사 테스트 실행. 개발 MySQL(.devdb) 가동 상태여야 함.
set -e
cd "$(dirname "$0")/.."
php scripts/tests/run.php
```

- [ ] **Step 3: 실행 권한 + 전체 실행**

Run:
```bash
chmod +x scripts/run_acct_tests.sh && php scripts/tests/run.php
```
Expected: 각 스위트 PASS, 마지막에 `✅ 전체 통과`, 종료코드 0.

- [ ] **Step 4: 검증 결과 하네스 기록** — Run

```bash
node "/Users/deoksookim/Desktop/코드/claude code/telegram-control/harness_progress.js" verify --id P1 --add "대사 A~G 전체 통과: 공급가 기준 순이익·미수금·취소제외·중복JOIN방지 검증"
```

- [ ] **Step 5: 커밋**

```bash
git add scripts/tests/run.php scripts/run_acct_tests.sh
git commit -m "test(acct): 대사 A~G 통합 러너 + 커버리지 표

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review (완료)

**Spec coverage (P1 범위):**
- 1.1/1.2 공급가·부가세 분리 → Task 1·4 ✓ · 손익 산식 → Task 2 ✓
- 5절 AccountingService 단일 출처 → Task 1·2·5 ✓ · Settings → Task 3 ✓
- 5.3 스키마/백필 → Task 4 ✓ · feature_worklog 시드 → Task 3 ✓
- 10절 대사 A~G → Task 2(A·B·D·E)·5(C·F·G)·6(통합표) ✓
- 7절 null 전파(목표 미설정) → Task 2 achievement ✓
- (P2~P6는 별도 계획 — 대시보드 통합/드릴다운, 작업일지 플래그 적용, UI, 시드 현실화·QA, 리팩토링)

**Placeholder scan:** 모든 스텝에 실제 코드/명령/기대출력 포함. 플레이스홀더 없음.

**Type consistency:** `supplyOf/vatOf`(array→int), `projectActualProfit`(array→int), `achievement`(?float,?float→?float), 집계 메서드(?string,?string→int) — Task 간 시그니처 일치 확인.

**주의(실행자용):** `app/bootstrap.php:12` 로드 목록은 Task 1에서 `AccountingService` 만, Task 3에서 `Settings` 를 추가한다(존재하지 않는 파일을 require 하면 web 부트스트랩이 깨지므로 순서 준수). DB 테스트는 `.devdb` MySQL 가동 필요(미가동 시 `bash scripts/start_dev.sh`).
