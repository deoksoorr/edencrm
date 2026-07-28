# CRM R13 Implementation Plan — 보드·상태 동기화 / 예외 입금·정산 / 보너스 실시간 재계산

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 프로젝트 상태와 공정 보드를 양방향 동기화하고, 예외 프로젝트 입금/정산을 유형화·자동완료로 개편하며, 보너스·손실값을 실입금 기준으로 항상 재계산한다.

**Architecture:** 순수 PHP MVC(오토로더 없음, `app/bootstrap.php`가 코어 명시 로드). 상태 축(`StatusService`)과 공정 축(`ProcessService`)은 분리 유지하되 전환 지점에서 서로를 호출해 동기화. 모든 입금 이벤트가 지나가는 단일 훅 `StatusService::recalcProjectSettlement()`에 보너스 재계산을 건다. 회계 집계 단일 출처는 `AccountingService`.

**Tech Stack:** PHP 8.x, MySQL/MariaDB(로컬 `.devdb` 소켓), 경량 테스트 러너(`scripts/tests/lib.php`: `t_int/t_true/t_float/t_summary`), 트랜잭션 롤백 픽스처.

## Global Constraints

- `process_stage_id` 변경은 **반드시 `ProcessService` 경유**(직접 UPDATE 금지, 이력 기록).
- 공정 축(status↔보드)과 정산 축(`settlement_status`)은 **독립** — 이번 작업에서 섞지 않는다. "완료↔전체완료"=공정 축, "전액 입금 완료"=정산 축.
- 신규 코어 클래스는 `app/bootstrap.php` 로드 목록(line 11-12)에 추가해야 런타임에서 인식된다.
- 보너스 재계산은 `confirmed_bonus`(실지급 확정)와 `contribution_pct_at_calc`(기여율 스냅샷)를 **보존**한다.
- 테스트는 `Db::pdo()->beginTransaction()` … `rollBack()`으로 잔재 0.
- 운영 반영은 로컬 전 시나리오 통과 후에만. 운영 DB 쓰기·FTP 배포는 사장 승인 게이트.
- 라벨 정확 문구: 정산 `settled` = **"전액 입금 완료"**, 입금 유형 = **계약금/중도금/잔금**(pay_type down/middle/balance), 관리 버튼 = **"입금내역 갱신"**, 예외 총액 라벨 = **"계약 총액"**.

---

## 파일 구조 (생성/수정)

- **생성:** `app/core/BonusService.php` — 프로젝트 단위 보너스 원장 재계산(단일 진입점).
- **생성:** `scripts/tests/unit_r13.php` — R13 신규 동작 회귀.
- **수정:** `app/core/StatusService.php` — 정산 자동승격·라벨·완료→보드 이동·보너스 훅.
- **수정:** `app/core/ProcessService.php` — `stageIdByKey()` 헬퍼.
- **수정:** `app/controllers/ProcessController.php` — 하자보수 드래그 자동전환, preparing/paused→in_progress 보강.
- **수정:** `app/controllers/SettlementController.php` — 입금 유형(pay_type) 검증, 환불 유형 고정, 계약총액 필수.
- **수정:** `app/views/projects/_tab_settlement.php` — 유형 select, 계약총액 라벨, 관리 버튼명, 수동 정산완료 버튼 제거, 입금자명 컬럼 제거.
- **수정:** `app/views/projects/show.php` — 진행시작·하자보수 전환 버튼 제거.
- **수정:** `app/controllers/BonusController.php` — 산식 primitive를 `BonusService`로 위임(중복 제거).
- **수정:** `app/bootstrap.php`, `scripts/tests/run.php` — 로드·스위트 등록.

---

## Phase 1 — 예외 프로젝트 입금·정산 (기능 2)

### Task 1: 정산 자동 '전액 입금 완료' 승격 + 라벨 변경

**Files:**
- Modify: `app/core/StatusService.php` (SETTLEMENT_LABELS, `recalcProjectSettlement`)
- Create: `scripts/tests/unit_r13.php`
- Modify: `scripts/tests/run.php`

**Interfaces:**
- Consumes: `AccountingService::projectPaySummary($project): array{expected,paid,refund,pendingCnt,outstanding,pay_status,expected_set}`
- Produces: `StatusService::recalcProjectSettlement(int $projectId): string` — 이제 전액 입금 시 `'settled'` 자동 반환/기록.

- [ ] **Step 1: 실패 테스트 작성** — `scripts/tests/unit_r13.php` 생성

```php
<?php
/** R13 — 정산 자동완료·보너스 재계산·보드 동기화 회귀 (트랜잭션 롤백). */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/StatusService.php';
require_once APP_PATH . '/core/ProcessService.php';
// require_once APP_PATH . '/core/BonusService.php';  // Task 4에서 주석 해제(파일 생성 후)
require_once APP_PATH . '/core/Audit.php';
require_once APP_PATH . '/core/Auth.php';
require_once APP_PATH . '/core/Stages.php';

echo "R13 회귀 (트랜잭션 롤백)\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    // ── Task 1: 정산 자동 승격 ──
    $xp = Db::insert('projects', ['project_no' => 'R13-X1', 'name' => 'R13예외', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => '직접고객', 'contract_amount' => 0,
        'expected_amount' => 5000000, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    // 전액 입금(계약총액 == 입금)
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'down', 'amount' => 2000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'balance', 'amount' => 3000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_true('전액 입금 → 자동 전액 입금 완료(settled)', StatusService::recalcProjectSettlement($xp) === 'settled');
    // 환불로 미수금 재발생 → 자동 강등
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'refund', 'kind' => 'refund', 'amount' => 1000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_true('환불 후 미수금 재발생 → partial 자동 강등', StatusService::recalcProjectSettlement($xp) === 'partial');
    // 라벨 변경
    t_true("라벨: settled = '전액 입금 완료'", StatusService::SETTLEMENT_LABELS['settled'] === '전액 입금 완료');
    // 계약총액 미설정이면 자동 승격 안 됨
    $xp2 = Db::insert('projects', ['project_no' => 'R13-X2', 'name' => 'R13미설정', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => '직접고객', 'contract_amount' => 0,
        'expected_amount' => null, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    Db::insert('payments', ['project_id' => $xp2, 'pay_type' => 'down', 'amount' => 1000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_true('계약총액 미설정 → settled 안 됨(partial)', StatusService::recalcProjectSettlement($xp2) === 'partial');

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "  [FAIL] 예외: " . $e->getMessage() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
```

- [ ] **Step 2: 실패 확인**

Run: `php scripts/tests/unit_r13.php`
Expected: FAIL — `BonusService` 파일 없음(치명적) 또는 자동승격 미구현으로 첫 단언 실패.

- [ ] **Step 3: 라벨 변경** — `app/core/StatusService.php` `SETTLEMENT_LABELS`의 `'settled'` 값 변경

찾기: `'settled' => '정산 완료',`
바꾸기: `'settled' => '전액 입금 완료',`

- [ ] **Step 4: `recalcProjectSettlement` 자동승격 구현** — 아래로 메서드 본문 교체

```php
    public static function recalcProjectSettlement(int $projectId): string
    {
        $project = Db::one("SELECT id, contract_id, is_exception, expected_amount, settlement_status
            FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$project) {
            return 'unsettled';
        }
        $current = (string) $project['settlement_status'];
        if (in_array($current, ['hold', 'refunding'], true)) {
            return $current; // 수동 상태 유지(해제는 정산 상태 컨트롤에서만)
        }
        $sum = AccountingService::projectPaySummary($project);
        // R13: 전액 입금(계약총액 설정·미수금 0·대기 0·입금>0) → '전액 입금 완료'(settled) 자동 승격.
        if ($sum['expected_set'] && $sum['outstanding'] <= 0 && $sum['pendingCnt'] === 0 && $sum['paid'] > 0) {
            if ($current !== 'settled') {
                Db::update('projects', ['settlement_status' => 'settled'], 'id = :id', [':id' => $projectId]);
                Audit::log('project_settlement_change', 'project', $projectId,
                    ['settlement_status' => $current],
                    ['settlement_status' => 'settled', 'reason' => '전액 입금 자동 완료']);
            }
            return 'settled';
        }
        // 전액이 아닌데 settled 였으면 → 미수금 재발생/대기 발생으로 partial 자동 강등(감사 기록).
        if ($current === 'settled') {
            Db::update('projects', ['settlement_status' => 'partial'], 'id = :id', [':id' => $projectId]);
            Audit::log('project_settlement_change', 'project', $projectId,
                ['settlement_status' => 'settled'],
                ['settlement_status' => 'partial', 'reason' => '미수금 재발생 자동 강등', 'outstanding' => $sum['outstanding']]);
            return 'partial';
        }
        $next = $sum['paid'] > 0 ? 'partial' : 'unsettled';
        if ($next !== $current) {
            Db::update('projects', ['settlement_status' => $next], 'id = :id', [':id' => $projectId]);
        }
        return $next;
    }
```

- [ ] **Step 5: 스위트 등록** — `scripts/tests/run.php` `$suites` 배열 끝에 `'unit_r13'` 추가

찾기: `'unit_r11_settlement'];`
바꾸기: `'unit_r11_settlement', 'unit_r13'];`

- [ ] **Step 6: 통과 확인**

> `BonusService` require는 Task 4까지 주석 상태이므로 이 시점엔 Task 1 단언만 존재해 정상 실행된다.

Run: `php scripts/tests/unit_r13.php`
Expected: PASS — 자동 승격/강등/라벨/미설정 4단언 통과.

- [ ] **Step 7: 기존 R11 회귀 확인(자동승격 영향)**

Run: `php scripts/tests/unit_r11_settlement.php`
Expected: PASS. (R11 테스트는 전액 입금 시점에 recalc를 호출하지 않고 수동 settled 후 환불→강등만 검증하므로 영향 없음. 만약 실패하면 해당 단언의 기대를 자동승격 동작에 맞게 조정.)

- [ ] **Step 8: 커밋**

```bash
git add app/core/StatusService.php scripts/tests/unit_r13.php scripts/tests/run.php
git commit -m "feat(r13): 정산 전액입금 자동완료(settled)+라벨 '전액 입금 완료' + R13 회귀 스위트"
```

---

### Task 2: 입금 등록 유형화 + 환불 유형 고정 (서버)

**Files:**
- Modify: `app/controllers/SettlementController.php:73-119` (`paymentSave`)

**Interfaces:**
- Consumes: POST `pay_type`(down/middle/balance), `kind`(payment/refund).
- Produces: `payments.pay_type` = 계약금/중도금/잔금 또는 환불='refund'; `payer_name`=null(미수집).

- [ ] **Step 1: pay_type 검증 로직 추가** — `paymentSave`의 `$status` 처리 직후(현재 :87 이후, 환불 분기 전)에 삽입

```php
        // R13: 입금 유형(계약금/중도금/잔금). 환불은 'refund' 고정.
        $payType = Util::postStr('pay_type', '');
        if ($kind === 'refund') {
            $payType = 'refund';
        } elseif (!in_array($payType, ['down', 'middle', 'balance'], true)) {
            $this->fail('입금 유형(계약금/중도금/잔금)을 선택하세요.', 422, $projectId);
        }
```

- [ ] **Step 2: `$data` 배열 수정** — pay_type 하드코딩·payer_name 교체

찾기:
```php
            'pay_type'    => 'etc',
```
바꾸기:
```php
            'pay_type'    => $payType,
```

찾기:
```php
            'payer_name'  => $payerName,
```
바꾸기:
```php
            'payer_name'  => null, // R13: 입금자명 미수집(유형으로 대체)
```

- [ ] **Step 3: PHP 문법 검사**

Run: `php -l app/controllers/SettlementController.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: 통합 지점 확인** — `paymentSave`는 이미 끝에서 `StatusService::recalcProjectSettlement($projectId)`를 호출(입금·환불 시 정산·보너스 자동 반영 훅). 별도 수정 불필요. 확인만.

Run: `grep -n "recalcProjectSettlement" app/controllers/SettlementController.php`
Expected: `paymentSave`/`paymentCancel`/`expectedSave`에서 호출 확인.

- [ ] **Step 5: 커밋**

```bash
git add app/controllers/SettlementController.php
git commit -m "feat(r13): 예외 입금 유형화(pay_type 계약금/중도금/잔금)·환불 유형 고정·입금자명 미수집"
```

---

### Task 3: 입금·정산 탭 UI — 유형 select·계약총액·버튼명·수동 정산완료 제거

**Files:**
- Modify: `app/views/projects/_tab_settlement.php`

**Interfaces:**
- Consumes: `$payTypeLabels = ['down'=>'계약금','middle'=>'중도금','balance'=>'잔금','etc'=>'기타']`(이미 전달됨).

- [ ] **Step 1: 예정 금액 → 계약 총액 라벨** — `_tab_settlement.php:24-25`

찾기:
```php
    <div class="kv" title="<?= $isExceptionLedger ? '정산 예정 금액 — 예외 프로젝트 직접 입력(수정 이력 보존)' : '연결 계약 총액(VAT 포함)' ?>">
      <div class="kv-label">예정 금액<?= $isExceptionLedger ? '(정산 예정)' : '(계약 총액)' ?></div>
```
바꾸기:
```php
    <div class="kv" title="<?= $isExceptionLedger ? '계약 총액 — 예외 프로젝트 직접 입력(수정 이력 보존)·전액 입금 판정 기준' : '연결 계약 총액(VAT 포함)' ?>">
      <div class="kv-label">계약 총액</div>
```

- [ ] **Step 2: 수동 '정산 완료 처리' 버튼 제거** — `_tab_settlement.php:49-52` 삭제

찾기:
```php
    <?php if ($settleStatus !== 'settled'): ?>
      <button type="button" class="btn btn-sm btn-primary" data-settle-action="settle"
              <?= $canSettle ? '' : 'disabled title="미수금 0원 + 대기 건 정리 후 처리 가능"' ?>>정산 완료 처리</button>
    <?php endif; ?>
```
바꾸기: (빈 줄 — 자동화로 대체, 안내 문구만)
```php
    <?php /* R13: 전액 입금 시 '전액 입금 완료' 자동 처리 — 수동 버튼 제거 */ ?>
```

- [ ] **Step 3: `$canSettle` 미사용 정리** — `_tab_settlement.php:16`

찾기:
```php
$canSettle = $ps['outstanding'] <= 0 && $ps['pendingCnt'] === 0;
```
바꾸기: (삭제 — 더 이상 참조 없음)
```php
```

- [ ] **Step 4: 입금자명 컬럼 제거(헤더)** — `_tab_settlement.php:91`

찾기:
```php
          <th>구분</th><th class="num">금액</th><th>방식</th><th>입금자명</th><th>예정일</th><th>입금일</th>
```
바꾸기:
```php
          <th>구분</th><th class="num">금액</th><th>방식</th><th>예정일</th><th>입금일</th>
```

- [ ] **Step 5: 입금자명 컬럼 제거(셀)** — `_tab_settlement.php:104` 삭제

찾기:
```php
              <td><?= e($pm['payer_name'] ?: '-') ?></td>
```
바꾸기: (삭제)
```php
```

- [ ] **Step 6: 관리 '수정' 버튼 → '입금내역 갱신' + data 속성 교체** — `_tab_settlement.php:113-117`

찾기:
```php
                    <button type="button" class="btn btn-sm btn-outline btn-edit-ppm"
                      data-id="<?= (int) $pm['id'] ?>" data-kind="<?= e($pm['kind']) ?>" data-amount="<?= (int) $pm['amount'] ?>"
                      data-method="<?= e($pm['method'] ?? '') ?>" data-payer="<?= e($pm['payer_name'] ?? '') ?>"
                      data-due="<?= e($pm['due_date'] ?? '') ?>" data-paid="<?= e($pm['paid_date'] ?? '') ?>"
                      data-status="<?= e($pm['status']) ?>" data-memo="<?= e($pm['memo'] ?? '') ?>">수정</button>
```
바꾸기:
```php
                    <button type="button" class="btn btn-sm btn-outline btn-edit-ppm"
                      data-id="<?= (int) $pm['id'] ?>" data-kind="<?= e($pm['kind']) ?>" data-amount="<?= (int) $pm['amount'] ?>"
                      data-method="<?= e($pm['method'] ?? '') ?>" data-paytype="<?= e($pm['pay_type'] ?? '') ?>"
                      data-due="<?= e($pm['due_date'] ?? '') ?>" data-paid="<?= e($pm['paid_date'] ?? '') ?>"
                      data-status="<?= e($pm['status']) ?>" data-memo="<?= e($pm['memo'] ?? '') ?>">입금내역 갱신</button>
```

- [ ] **Step 7: 폼의 입금자명 → 유형 select** — `_tab_settlement.php:214`

찾기:
```php
      '<div class="field"><label class="field-label">입금자명</label><input type="text" name="payer_name" class="input" value="' + esc(pm.payer || '') + '" maxlength="100"></div>' +
```
바꾸기:
```php
      (isRefund
        ? '<div class="field"><label class="field-label">유형</label><input type="text" class="input" value="환불" readonly><input type="hidden" name="pay_type" value="refund"></div>'
        : '<div class="field"><label class="field-label">유형 <span class="req">*</span></label><select name="pay_type" class="select" required>' +
            ['down', 'middle', 'balance'].map(function (k) {
              var lbl = { down: '계약금', middle: '중도금', balance: '잔금' }[k];
              return '<option value="' + k + '"' + (pm.pay_type === k ? ' selected' : '') + '>' + lbl + '</option>';
            }).join('') + '</select></div>') +
```

- [ ] **Step 8: 수정 모달이 pay_type 전달하도록** — `_tab_settlement.php:239-242`

찾기:
```php
        body: payFormHtml({
          id: btn.dataset.id, amount: btn.dataset.amount, method: btn.dataset.method, payer: btn.dataset.payer,
          due: btn.dataset.due, paid: btn.dataset.paid, status: btn.dataset.status, memo: btn.dataset.memo,
        }, btn.dataset.kind), footer: false,
```
바꾸기:
```php
        body: payFormHtml({
          id: btn.dataset.id, amount: btn.dataset.amount, method: btn.dataset.method, pay_type: btn.dataset.paytype,
          due: btn.dataset.due, paid: btn.dataset.paid, status: btn.dataset.status, memo: btn.dataset.memo,
        }, btn.dataset.kind), footer: false,
```

- [ ] **Step 9: 계약 총액 모달 라벨·필수화** — `_tab_settlement.php:262-267`

찾기:
```php
      title: '정산 예정 금액 수정',
      body: '<form data-ajax action-route="projects.expected.save" data-reload class="form">' +
        '<input type="hidden" name="_csrf" value="' + (window.EDEN.CSRF || '') + '">' +
        '<input type="hidden" name="project_id" value="' + PROJECT_ID + '">' +
        '<div class="field"><label class="field-label">정산 예정 금액(원)</label>' +
        '<input type="text" inputmode="decimal" name="expected_amount" class="input" value="<?= $p['expected_amount'] !== null ? (int) $p['expected_amount'] : '' ?>" placeholder="비워두면 미설정"></div>' +
```
바꾸기:
```php
      title: '계약 총액 수정',
      body: '<form data-ajax action-route="projects.expected.save" data-reload class="form">' +
        '<input type="hidden" name="_csrf" value="' + (window.EDEN.CSRF || '') + '">' +
        '<input type="hidden" name="project_id" value="' + PROJECT_ID + '">' +
        '<div class="field"><label class="field-label">계약 총액(원) <span class="req">*</span></label>' +
        '<input type="text" inputmode="decimal" name="expected_amount" class="input" required value="<?= $p['expected_amount'] !== null ? (int) $p['expected_amount'] : '' ?>" placeholder="전액 입금 판정 기준 금액"></div>' +
```

- [ ] **Step 10: 문법·렌더 스모크**

Run: `php -l app/views/projects/_tab_settlement.php`
Expected: `No syntax errors detected`

- [ ] **Step 11: 커밋**

```bash
git add app/views/projects/_tab_settlement.php
git commit -m "feat(r13): 입금·정산 탭 UI — 유형 select·계약총액 라벨/필수·관리버튼 '입금내역 갱신'·수동 정산완료 버튼 제거·입금자명 컬럼 제거"
```

---

## Phase 2 — 보너스 실시간 재계산 (기능 4)

### Task 4: `BonusService::recalcForProject` 생성

**Files:**
- Create: `app/core/BonusService.php`
- Modify: `app/bootstrap.php:11-12` (로드 목록)

**Interfaces:**
- Consumes: `AccountingService::projectConfirmedRevenue(array $p): int`; `projects.*`; `site_bonuses.*`.
- Produces: `BonusService::recalcForProject(int $projectId): int` — 재계산된 행 수 반환.

- [ ] **Step 1: BonusService require 주석 해제 + 실패 테스트 추가** — `scripts/tests/unit_r13.php`

먼저 상단의 require 주석을 해제:

찾기: `// require_once APP_PATH . '/core/BonusService.php';  // Task 4에서 주석 해제(파일 생성 후)`
바꾸기: `require_once APP_PATH . '/core/BonusService.php';`

그리고 rollback 직전(`$pdo->rollBack();` 앞)에 삽입:

```php
    // ── Task 4: 보너스 실시간 재계산 ──
    $u = Db::insert('users', ['login_id' => 'r13emp', 'password_hash' => 'x', 'name' => 'R13직원', 'role' => 'staff', 'status' => 'active']);
    $bp = Db::insert('projects', ['project_no' => 'R13-B1', 'name' => 'R13보너스', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => '직접', 'contract_amount' => 0, 'expected_amount' => 11000000,
        'actual_cost' => 0, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    Db::insert('project_assignments', ['project_id' => $bp, 'user_id' => $u, 'contribution_pct' => 100, 'status' => 'active']);
    // 입금 1,100,000 (VAT 10% → 공급가 1,000,000)
    Db::insert('payments', ['project_id' => $bp, 'pay_type' => 'down', 'amount' => 1100000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    // 보너스 원장 1행(기여율 100·보너스율 10). base=공급가 1,000,000.
    $bid = Db::insert('site_bonuses', ['user_id' => $u, 'project_id' => $bp, 'year' => (int) date('Y'), 'half' => (int) date('n') <= 6 ? 1 : 2,
        'base_amount' => 1000000, 'contrib_revenue' => 1000000, 'contrib_profit' => 1000000, 'bonus_rate' => 10.00,
        'calc_amount' => 100000, 'confirmed_bonus' => 100000, 'pay_status' => 'unpaid', 'contribution_pct_at_calc' => 100.00]);
    // 환불 550,000 (공급가 500,000) → base 500,000·calc 50,000 재계산 기대. confirmed_bonus 보존.
    Db::insert('payments', ['project_id' => $bp, 'pay_type' => 'refund', 'kind' => 'refund', 'amount' => 550000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    BonusService::recalcForProject($bp);
    $b = Db::one("SELECT * FROM site_bonuses WHERE id = :id", [':id' => $bid]);
    t_int('환불 후 base_amount 재계산 = 500,000', 500000, (int) $b['base_amount']);
    t_int('환불 후 contrib_revenue = 500,000', 500000, (int) $b['contrib_revenue']);
    t_int('환불 후 calc_amount = 50,000', 50000, (int) $b['calc_amount']);
    t_int('confirmed_bonus 보존(100,000)', 100000, (int) $b['confirmed_bonus']);
    t_int('contribution_pct_at_calc 보존(100)', 100, (int) $b['contribution_pct_at_calc']);
```

- [ ] **Step 2: 실패 확인**

Run: `php scripts/tests/unit_r13.php`
Expected: FAIL — `Class "BonusService" not found` 또는 값 불일치.

- [ ] **Step 3: `BonusService` 생성** — `app/core/BonusService.php`

```php
<?php
/**
 * 보너스 원장 실시간 재계산(R13) — 단일 진입점.
 * 입금/환불/철회/파기/계약금 변경 등 확정매출·지출 변동 시 해당 프로젝트의 보너스 원장
 * 파생 컬럼(base_amount·contrib_revenue·contrib_profit·calc_amount)을 실입금 기준으로 다시 쓴다.
 * confirmed_bonus(실지급 확정)와 contribution_pct_at_calc(기여율 스냅샷)는 절대 건드리지 않는다.
 * 캐싱 스냅샷 유지 금지 — 산정값은 항상 현재 순입금(입금−환불) 기준.
 */
class BonusService
{
    /** @return int 재계산으로 값이 바뀐 보너스 행 수 */
    public static function recalcForProject(int $projectId): int
    {
        if ($projectId <= 0) {
            return 0;
        }
        $proj = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$proj) {
            return 0;
        }
        $rows = Db::all(
            "SELECT * FROM site_bonuses
             WHERE project_id = :p AND deleted_at IS NULL AND pay_status <> 'cancelled'",
            [':p' => $projectId]
        );
        if (!$rows) {
            return 0;
        }
        $revenue    = AccountingService::projectConfirmedRevenue($proj); // 확정매출(공급가·입금 기준)
        $base       = max(0, $revenue);
        $profitBase = $revenue - (int) ($proj['actual_cost'] ?? 0);       // 적용 순이익 분자(음수 가능)

        $changed = 0;
        foreach ($rows as $b) {
            $pct  = $b['contribution_pct_at_calc'] !== null ? (float) $b['contribution_pct_at_calc'] : null;
            $rate = $b['bonus_rate'] !== null ? (float) $b['bonus_rate'] : null;
            // 기여율 스냅샷 보존 — 스냅샷 기준 비례 재계산(스냅샷 없으면 기존값 유지).
            $contribRev    = $pct !== null ? (int) round($base * $pct / 100) : (int) $b['contrib_revenue'];
            $contribProfit = $pct !== null ? (int) round($profitBase * $pct / 100) : (int) $b['contrib_profit'];
            $calc          = ($rate !== null) ? (int) round($contribRev * $rate / 100) : (int) $b['calc_amount'];

            if ((int) $b['base_amount'] === $base
                && (int) $b['contrib_revenue'] === $contribRev
                && (int) $b['contrib_profit'] === $contribProfit
                && (int) $b['calc_amount'] === $calc) {
                continue; // 변경 없음
            }
            $data = [
                'base_amount'     => $base,
                'contrib_revenue' => $contribRev,
                'contrib_profit'  => $contribProfit,
                'calc_amount'     => $calc,
            ];
            Db::update('site_bonuses', $data, 'id = :id', [':id' => (int) $b['id']]);
            Db::insert('site_bonus_history', [
                'bonus_id'    => (int) $b['id'],
                'action'      => 'recalc',
                'before_json' => json_encode($b, JSON_UNESCAPED_UNICODE),
                'after_json'  => json_encode(array_merge($b, $data), JSON_UNESCAPED_UNICODE),
                'reason'      => '입금/환불 이벤트 자동 재계산(R13)',
                'changed_by'  => null,
            ]);
            $changed++;
        }
        return $changed;
    }
}
```

- [ ] **Step 4: 부트스트랩 로드 등록** — `app/bootstrap.php:12`

찾기: `'StatusService', 'ProcessService',`
바꾸기: `'StatusService', 'ProcessService', 'BonusService',`

- [ ] **Step 5: 통과 확인**

Run: `php scripts/tests/unit_r13.php`
Expected: PASS — base 500,000 · contrib_revenue 500,000 · calc 50,000 · confirmed_bonus 100,000 보존 · pct 100 보존.

- [ ] **Step 6: 커밋**

```bash
git add app/core/BonusService.php app/bootstrap.php scripts/tests/unit_r13.php
git commit -m "feat(r13): BonusService.recalcForProject — 실입금 기준 보너스/손실 재계산(확정보너스·기여율 스냅샷 보존)"
```

---

### Task 5: 단일 훅 연결 + BonusController 위임

**Files:**
- Modify: `app/core/StatusService.php` (`recalcProjectSettlement` 말미)
- Modify: `app/controllers/BonusController.php` (primitive 위임 — 선택적 중복 제거)

**Interfaces:**
- Consumes: `BonusService::recalcForProject(int $projectId): int`

- [ ] **Step 1: 실패 테스트 추가** — `scripts/tests/unit_r13.php` Task 4 블록 끝(환불 단언 뒤, rollback 앞)에 삽입

```php
    // ── Task 5: recalcProjectSettlement 훅이 보너스까지 재계산 ──
    // 위 $bp 는 환불 반영됐지만, 훅 경로로도 동일 결과가 나오는지 재입금으로 검증.
    Db::insert('payments', ['project_id' => $bp, 'pay_type' => 'balance', 'amount' => 550000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    StatusService::recalcProjectSettlement($bp); // 훅 안에서 BonusService 호출되어야 함
    $b2 = Db::one("SELECT * FROM site_bonuses WHERE id = :id", [':id' => $bid]);
    t_int('훅 경로 재계산: 재입금 후 base = 1,000,000', 1000000, (int) $b2['base_amount']);
    t_int('훅 경로 재계산: calc = 100,000', 100000, (int) $b2['calc_amount']);
```

- [ ] **Step 2: 실패 확인**

Run: `php scripts/tests/unit_r13.php`
Expected: FAIL — 훅 미연결이면 base가 500,000에 머무름.

- [ ] **Step 3: 훅 연결** — `app/core/StatusService.php` `recalcProjectSettlement` 안, `$sum = AccountingService::projectPaySummary($project);` 바로 다음 줄에 삽입

```php
        BonusService::recalcForProject($projectId); // R13: 입금 이벤트 단일 훅 — 보너스/손실 실시간 재계산
```

> 위치 근거: hold/refunding 조기 반환보다 뒤이지만, 그 두 상태에서도 환불이 나면 보너스는 재계산돼야 하므로 `$sum` 계산 직후(모든 승격/강등 분기보다 앞)에 둔다. `recalcForProject`는 정산 상태를 건드리지 않아 재귀 없음.

- [ ] **Step 4: 통과 확인**

Run: `php scripts/tests/unit_r13.php`
Expected: PASS — 훅 경로 base 1,000,000 · calc 100,000.

- [ ] **Step 5: BonusController 위임(중복 제거)** — `app/controllers/BonusController.php`

`projectBonusBase`/`projectProfitBase`는 로직이 짧고 이미 `AccountingService`를 부르므로 **그대로 두어도 무방**. 중복 제거는 선택 사항 — 시간/리스크 고려해 이번 릴리스에선 **위임 생략**하고 BonusService만 신규 경로로 사용한다(YAGNI). (BonusController.save/saveBulk 산식은 등록·수정 시점 계산이라 기존 유지.)

- [ ] **Step 6: 전체 회귀**

Run: `php scripts/tests/run.php`
Expected: `✅ 전체 통과`

- [ ] **Step 7: 커밋**

```bash
git add app/core/StatusService.php scripts/tests/unit_r13.php
git commit -m "feat(r13): 입금 이벤트 단일 훅(recalcProjectSettlement)에서 보너스 실시간 재계산 연결"
```

---

## Phase 3 — 프로젝트 ↔ 공정 보드 동기화 (기능 1)

### Task 6: 완료 → 전체완료 자동 이동 (상태→보드)

**Files:**
- Modify: `app/core/ProcessService.php` (`stageIdByKey` 헬퍼)
- Modify: `app/core/StatusService.php` (`applyProjectStatus` completed 분기)

**Interfaces:**
- Produces: `ProcessService::stageIdByKey(string $key): ?int`

- [ ] **Step 1: 실패 테스트 추가** — `scripts/tests/unit_r13.php` rollback 앞에 삽입

```php
    // ── Task 6: 완료 → 전체완료 자동 이동 ──
    $wid = ProcessService::waitingStageId();
    $fc  = ProcessService::stageIdByKey('full_complete');
    t_true("stageIdByKey('full_complete') 존재", $fc !== null);
    $cp = Db::insert('projects', ['project_no' => 'R13-C1', 'name' => 'R13완료', 'customer_id' => null,
        'is_exception' => 0, 'customer_name_snapshot' => '직접', 'contract_amount' => 0,
        'status' => 'in_progress', 'process_stage_id' => $wid]);
    $cpRow = Db::one("SELECT * FROM projects WHERE id = :id", [':id' => $cp]);
    StatusService::applyProjectStatus($cpRow, 'completed', ['reason' => '테스트 완료']);
    $after = Db::one("SELECT status, process_stage_id FROM projects WHERE id = :id", [':id' => $cp]);
    t_true('완료 상태', $after['status'] === 'completed');
    t_int('완료 → 공정 전체완료 자동 이동', $fc, (int) $after['process_stage_id']);
```

- [ ] **Step 2: 실패 확인**

Run: `php scripts/tests/unit_r13.php`
Expected: FAIL — `stageIdByKey` 없음.

- [ ] **Step 3: `stageIdByKey` 헬퍼 추가** — `app/core/ProcessService.php` `waitingStageId()` 아래에 삽입

```php
    /** stage_key 로 공정 단계 ID 조회(없으면 null). */
    public static function stageIdByKey(string $key): ?int
    {
        $id = Db::val("SELECT id FROM process_stages WHERE stage_key = :k", [':k' => $key]);
        return $id !== null ? (int) $id : null;
    }
```

- [ ] **Step 4: `applyProjectStatus` completed 분기에 보드 이동 추가** — `app/core/StatusService.php`, completed 블록의 `$data['progress'] = 100;` 다음 줄에 삽입

```php
            // R13: 완료 처리 시 공정 보드 카드를 '전체완료'로 자동 이동(상태→보드 동기화). 같은 단계면 no-op.
            $fcId = ProcessService::stageIdByKey('full_complete');
            if ($fcId !== null) {
                ProcessService::moveStage((int) $project['id'], $fcId, $opts['actor_id'] ?? null, '완료 처리 자동 종결', true);
            }
```

> 주의: `applyProjectStatus`는 트랜잭션 내부 호출 전제라 `moveStage`도 같은 트랜잭션에 포함된다. 보드에서 전체완료로 드래그하는 경로(`ProcessController::move`)는 이미 moveStage 후 applyProjectStatus(completed)를 부르므로 이 moveStage는 no-op(같은 단계).

- [ ] **Step 5: 통과 확인**

Run: `php scripts/tests/unit_r13.php`
Expected: PASS — 완료 상태 + process_stage_id == full_complete.

- [ ] **Step 6: 회귀(보드 이동→완료 경로 무결)**

Run: `php scripts/tests/run.php`
Expected: `✅ 전체 통과`

- [ ] **Step 7: 커밋**

```bash
git add app/core/ProcessService.php app/core/StatusService.php scripts/tests/unit_r13.php
git commit -m "feat(r13): 완료 처리 시 공정 보드 '전체완료' 자동 이동(상태→보드 동기화) + stageIdByKey"
```

---

### Task 7: 하자보수 드래그 자동전환 + 진행 시작 보강 (보드→상태)

**Files:**
- Modify: `app/controllers/ProcessController.php:228-245` (`move` 트랜잭션 내 상태 전환 규칙)

**Interfaces:**
- Consumes: `$toStage['stage_key']`, `$project['status']`, `StatusService::applyProjectStatus`.

- [ ] **Step 1: 근본원인 재현(디버깅)** — "보드 이동해도 진행중이 안 된다"는 보고를 로컬에서 재현. 현재 규칙은 `preparing` + 대기중→실공정만 in_progress. `paused`(일시중단)에서 이동하거나, 이미 대기중이 아닌 데서 이동하면 안 걸림 → 이 갭을 메운다.

Run(확인용): `grep -n "applyProjectStatus" app/controllers/ProcessController.php`
Expected: full_complete·재개·preparing 3분기 확인.

- [ ] **Step 2: 실패 테스트 추가** — `scripts/tests/unit_r13.php` rollback 앞에 삽입

```php
    // ── Task 7: 하자보수 드래그 자동전환 + paused→in_progress 보강 ──
    $wrId = ProcessService::stageIdByKey('warranty_repair');
    $prep = ProcessService::stageIdByKey('prep'); // 착공준비(도장)
    t_true("stageIdByKey('warranty_repair') 존재", $wrId !== null);
    // 이 테스트는 컨트롤러 트랜잭션 로직을 직접 부르기 어려우므로, 규칙 함수화한 StatusService 헬퍼로 검증한다(Step 3에서 도입).
    t_true('완료→하자보수 매핑 규칙', StatusService::boardStatusFor('warranty_repair', 'completed') === 'warranty');
    t_true('대기중 외 실공정 이동 + preparing → in_progress', StatusService::boardStatusFor('prep', 'preparing') === 'in_progress');
    t_true('paused → 실공정 이동 시 in_progress', StatusService::boardStatusFor('prep', 'paused') === 'in_progress');
    t_true('전체완료 이동 → completed', StatusService::boardStatusFor('full_complete', 'in_progress') === 'completed');
    t_null('대기중 이동은 상태전환 없음', StatusService::boardStatusFor('waiting', 'preparing'));
```

- [ ] **Step 3: 규칙을 `StatusService::boardStatusFor` 로 추출** — `app/core/StatusService.php` 에 신규 정적 메서드 추가(보드 이동 시 목표 status 결정, 없으면 null)

```php
    /**
     * R13: 공정 보드 이동 시 프로젝트 상태 목표 결정. null=상태 전환 없음.
     * 규칙: 전체완료→completed / 하자보수→warranty / (대기중·전체완료 외 실공정)+preparing|paused→in_progress.
     * 완료·정산에서 실공정으로 되돌리는 '재개'는 호출부(ProcessController)에서 별도 처리.
     */
    public static function boardStatusFor(string $toStageKey, string $currentStatus): ?string
    {
        if ($toStageKey === 'full_complete') {
            return in_array($currentStatus, ['completed', 'settled'], true) ? null : 'completed';
        }
        if ($toStageKey === 'warranty_repair') {
            return $currentStatus === 'warranty' ? null : 'warranty';
        }
        if ($toStageKey !== ProcessService::WAITING_KEY
            && in_array($currentStatus, ['preparing', 'paused'], true)) {
            return 'in_progress';
        }
        return null;
    }
```

- [ ] **Step 4: 통과 확인(규칙 단위)**

Run: `php scripts/tests/unit_r13.php`
Expected: PASS — 매핑 5단언 통과.

- [ ] **Step 5: `ProcessController::move` 가 규칙을 사용하도록 교체** — `app/controllers/ProcessController.php:231-244` 의 3분기 if/elseif 블록을 아래로 교체

```php
                // R13: 보드 이동 → 상태 동기화(규칙 단일 출처 StatusService::boardStatusFor).
                $target = StatusService::boardStatusFor($toStage['stage_key'], $project['status']);
                if ($target !== null && $target !== $project['status']) {
                    $reasonMap = [
                        'completed'   => '공정 보드 종결(전체완료) 자동 완료',
                        'warranty'    => '공정 보드 하자보수 이동 자동 전환',
                        'in_progress' => '공정 시작(보드 이동) 자동 전환',
                    ];
                    StatusService::applyProjectStatus($project, $target, ['reason' => $reasonMap[$target] ?? '보드 이동 자동 전환']);
                } elseif ($toStage['stage_key'] !== 'full_complete'
                    && $toStage['stage_key'] !== 'warranty_repair'
                    && in_array($project['status'], ['completed', 'settled'], true)) {
                    // 종결/하자 외 실공정으로 되돌림 → 재개
                    StatusService::applyProjectStatus($project, 'in_progress', ['reason' => '공정 보드 이동 재개(종결 해제)']);
                }
```

> 주의: 교체 대상은 기존 `if ($toStage['stage_key'] === 'full_complete' …) { … } elseif (…) { … } elseif (…) { … }` 전체. 앞뒤의 `ProcessService::moveStage(...)` 호출과 `Db::update('projects', ['progress'…])`는 그대로 둔다. `applyProjectStatus(completed)`가 Task 6에 의해 전체완료로 moveStage를 또 부르지만 같은 단계라 no-op.

- [ ] **Step 6: 문법 검사 + 회귀**

Run: `php -l app/controllers/ProcessController.php && php scripts/tests/run.php`
Expected: `No syntax errors detected` + `✅ 전체 통과`

- [ ] **Step 7: 커밋**

```bash
git add app/controllers/ProcessController.php app/core/StatusService.php scripts/tests/unit_r13.php
git commit -m "feat(r13): 보드→상태 동기화 규칙 단일화(boardStatusFor) — 하자보수 드래그 자동전환·paused 진행시작 보강"
```

---

### Task 8: 진행 시작·하자보수 전환 버튼 제거 (프로젝트 상세 UI)

**Files:**
- Modify: `app/views/projects/show.php:102-108` (전환 버튼 루프)

**Interfaces:** 없음(뷰).

- [ ] **Step 1: 버튼 루프에 필터 추가** — `app/views/projects/show.php:102`

찾기:
```php
        <?php foreach ($allowedTransitions as $to):
          $tLabel = $fromToLabels[$p['status'] . '>' . $to] ?? $transitionLabels[$to] ?? ($statuses[$to] ?? $to);
```
바꾸기:
```php
        <?php foreach ($allowedTransitions as $to):
          // R13: 진행 시작·하자보수 전환 버튼 제거 — 진행 시작/하자보수는 공정 보드 이동으로 자동 처리.
          if (in_array($to, ['in_progress', 'warranty'], true)) { continue; }
          $tLabel = $fromToLabels[$p['status'] . '>' . $to] ?? $transitionLabels[$to] ?? ($statuses[$to] ?? $to);
```

- [ ] **Step 2: 문법 검사**

Run: `php -l app/views/projects/show.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: 커밋**

```bash
git add app/views/projects/show.php
git commit -m "feat(r13): 프로젝트 상세에서 진행시작·하자보수 전환 버튼 제거(보드 이동으로 자동화)"
```

---

## Phase 4 — 통합 QA + 배포

### Task 9: 통합 QA (로컬)

**Files:** 없음(검증). 필요 시 회귀 기대치 조정.

- [ ] **Step 1: 전체 회귀 스위트**

Run: `php scripts/tests/run.php`
Expected: `✅ 전체 통과`

- [ ] **Step 2: 대사·스모크**

Run: `php scripts/reconcile_qa.php && bash scripts/qa_smoke.sh`
Expected: 오류 없음(로그인 admin/password123!).

- [ ] **Step 3: 브라우저 시나리오(로컬 `scripts/start_dev.sh` 기동 후 http://127.0.0.1:8080)** — 설계 §6 체크리스트 전 항목 육안 확인:
  - 프로젝트 완료 → 보드 전체완료 카드 이동
  - 착공준비 드래그 → 진행 중 자동 / 진행시작·하자보수 버튼 미표시
  - 하자보수 컬럼 드래그 → 하자보수 상태
  - 예외 프로젝트: 계약 총액 입력 → 유형(계약금/중도금/잔금) 입금 → 전액 시 '전액 입금 완료' 자동
  - 환불 등록(유형 '환불' 고정) → 정산 자동 강등 + 보너스/손실 재계산
  - 관리 버튼 '입금내역 갱신' 표기
  - 견적 등록 화면 정상(리드 항목 그대로)
  - 관리자 화면 표시·DB 무결성

- [ ] **Step 4: QA 기록** (harness)

```bash
node "/Users/deoksookim/Desktop/코드/claude code/telegram-control/harness_progress.js" verify --add "R13 로컬 통합 QA 통과 — 회귀+브라우저 전 시나리오"
```

### Task 10: 운영 배포 + 재검증 (사장 승인 게이트)

- [ ] **Step 1: 마이그레이션 필요성 판단** — 신규 컬럼 없음. `payments.pay_type`는 VARCHAR라 값 확장만(마이그레이션 불필요). `payer_name` 유지. **DB 마이그레이션 없음** 확인.

Run: `grep -n "pay_type" database/schema.sql`
Expected: `pay_type VARCHAR(20)` (ENUM 아님 → 값 제약 없음).

- [ ] **Step 2: 배포 dry-run**

Run: `./deploy/deploy.sh`
Expected: dry-run diff 검토(수정 파일: StatusService/ProcessService/ProcessController/SettlementController/BonusService/_tab_settlement/show/bootstrap).

- [ ] **Step 3: 실배포(사장 승인 후)**

Run: `CONFIRM=yes ./deploy/deploy.sh`
Expected: 업로드 완료.

- [ ] **Step 4: 운영 재검증** — 운영 URL에서 §6 핵심 시나리오 재현(admin 세션). 특히 예외 프로젝트 전액 입금 자동완료·환불 보너스 재계산.

- [ ] **Step 5: 완료 기록**

```bash
node "/Users/deoksookim/Desktop/코드/claude code/telegram-control/harness_progress.js" verify --add "R13 운영 배포·재검증 완료"
```

---

## Self-Review (작성자 점검)

- **스펙 커버리지:** ①완료→전체완료(Task 6)·진행시작 제거(Task 7,8)·하자보수 버튼 제거(Task 7,8) / ②수정→입금내역 갱신(Task 3)·유형화(Task 2,3)·환불 유형 고정(Task 2,3)·자동 전액입금완료(Task 1)·예정금액→계약총액(Task 3)·정산완료 라벨(Task 1) / ④보너스 재계산(Task 4,5). ③ 제외(설계 확정). ✅ 전부 태스크 매핑됨.
- **미결/주의:** Task 1 Step 6의 순환 require 주의(BonusService 먼저 or 임시 주석). Task 7의 컨트롤러 교체는 기존 3분기 블록 전체 대상.
- **타입 일관성:** `boardStatusFor(string,string):?string`, `stageIdByKey(string):?int`, `recalcForProject(int):int`, `recalcProjectSettlement(int):string` — 태스크 간 시그니처 일치 확인.

## Execution Handoff

작성 완료. 실행 방식 선택 필요(아래 응답 참조).
