# CRM R15 Implementation Plan — 반기 버튼·입금탭 버튼명·폼 오류 개선·계약 삭제·공통 휴지통

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 반기 화면에 보너스 등록·이력 버튼 직결, 계약 입금 버튼명 정리, 견적/계약/프로젝트 폼의 오류페이지 이동 근절+필수 표시, 계약 삭제 신설, 견적·계약·프로젝트 공통 휴지통(복원+최고관리자 완전삭제)을 구현한다.

**Architecture:** 순수 PHP MVC. 휴지통은 신규 테이블 없이 기존 `deleted_at` 소프트삭제를 재사용(각 index의 `trash=1` 모드). 완전삭제는 컨트롤러 `Rbac::isRole('super_admin')` 403 가드(라우터 role 옵션 없음) + "참조 존재 시 거부" 정책(FK RESTRICT·회계 보존). 폼 검증은 `Response::error` 전면페이지를 플래시 리다이렉트로 교체하고 `Util::dateOrNull`·mb_substr 캡·try/catch로 500을 차단.

**Tech Stack:** PHP 8.x, MySQL(.devdb 로컬/운영 prefix edencrm_), 경량 테스트 러너, vanilla JS(EDEN.modal/api).

## Global Constraints

- **기존 DB 값·스키마 무접촉**: 마이그레이션·백필·컬럼 추가 금지. Db::TABLES 변경 불필요(기존 테이블만 사용).
- 완전삭제(purge)는 **soft-deleted 행만** 대상, `Rbac::isRole('super_admin')` 아니면 403(`Audit::log('access_denied', …)` + `View::renderError(403, …)` — ProjectsController.php:528-534 패턴). 참조 존재 시 422 플래시로 거부(참조 종류 나열).
- 폼 검증 실패는 **항상** `Response::redirect(<폼 라우트>, …, '메시지', 'error')` — `Response::error()`로 전면 페이지 렌더 금지.
- 필수 표시 관례: `<span class="req">*</span>` + `required` 속성.
- 지시 외 변경 금지(예: 빈칸 0-덮어쓰기 기존 동작·old-input 세션 보존은 스코프 외).
- 테스트 트랜잭션 롤백. 배포는 QA 통과 후 코드만 FTP(사장 사전 승인).
- 커밋 트레일러: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`

---

## Task 1: 반기 버튼 통합(보너스 등록 모달 파셜화) + 계약 입금 버튼명

**Files:**
- Create: `app/views/bonus/_form_modal.php`
- Modify: `app/views/bonus/index.php`, `app/views/halfyear/index.php`, `app/controllers/BonusController.php`(overview), `app/views/contracts/show.php`(:165 한 지점)

**Interfaces:**
- Produces: 파셜 `_form_modal.php` — 요구 변수 `$canManage`(bool)·`$formUsers`·`$projects`·`$f{year,half}`. 내부에 '등록(new)' 경로 JS 전체(USERS/PROJECTS/CUR 주입, formHtml, autofillCalc→bonus.calc, submit→bonus.save, document 위임 핸들러). bonus.index 전용 row 액션(edit/pay/cancel/del)은 파셜에 두되 `tr[data-bonus]` 부재 시 자연 no-op(기존 위임 구조 그대로).

- [ ] **Step 1**: bonus/index.php의 `<?php if ($canManage): ?>` IIFE 블록(:141-418)을 통째로 `_form_modal.php`로 이동(내용 무수정 — USERS/PROJECTS/CUR 주입부 포함). bonus/index.php 해당 위치는 `<?php include __DIR__ . '/_form_modal.php'; ?>`로 교체. '+ 보너스 등록' 버튼(:56-58)은 bonus/index.php에 유지.
- [ ] **Step 2**: `BonusController::overview()` render 배열(:238-257)에 추가 — `'canManage' => $canManage = Rbac::can('bonus.manage'),` 와 `'formUsers' => $canManage ? Db::all("SELECT id, name FROM users WHERE deleted_at IS NULL AND status='active' ORDER BY name") : [],` (index()의 :267-280 쿼리와 동일 문구 복사).
- [ ] **Step 3**: halfyear/index.php page-actions(:31-33)를 다음으로 교체:

```php
    <div class="page-actions">
      <?php if (!empty($canManage)): ?>
        <button type="button" class="btn btn-primary" data-bact="new">+ 보너스 등록</button>
      <?php endif; ?>
      <a href="<?= e(url('bonus.history', ['year' => $f['year'], 'half' => $f['half']])) ?>" class="btn btn-outline">변경 이력</a>
      <a href="<?= e(url('bonus.index', ['year' => $f['year'], 'half' => $f['half']])) ?>" class="btn btn-outline">보너스 지급 현황</a>
    </div>
```
파일 말미(마지막 `</div>` 직전 아님 — 최하단)에 `<?php include __DIR__ . '/../bonus/_form_modal.php'; ?>` 추가.
- [ ] **Step 4**: contracts/show.php:165 — 그 버튼의 라벨 텍스트만 `수정` → `입금내역 갱신` (같은 파일 :21 계약 수정 링크·:297 모달 타이틀·:83 툴팁은 불변).
- [ ] **Step 5**: 검증 — php -l 4파일, 로컬 렌더: halfyear에 버튼 3개+모달 JS 존재(`data-bact="new"`·`data-bonus-form`), bonus.index 동작 불변(등록 모달 열림), contracts 상세 '입금내역 갱신' 렌더. `php scripts/tests/run.php` ✅.
- [ ] **Step 6**: 커밋 `feat(r15): 반기 화면 보너스 등록·변경 이력 직결(모달 파셜화)+계약 입금 '입금내역 갱신'`

---

## Task 2: 폼 검증 개편 — 오류페이지 금지·필수 표시·500 차단 (TDD 일부)

**Files:**
- Modify: `app/controllers/ProjectsController.php`(save), `app/controllers/QuotesController.php`(save·parseItems), `app/controllers/ContractsController.php`(날짜만), `app/views/projects/form.php`, `app/views/quotes/form.php`, `public/assets/js/quotes.js`
- Create/Modify: `scripts/tests/unit_r15.php`(신규) + `scripts/tests/run.php` 등록

**Interfaces:** 검증 실패 = 플래시 리다이렉트(신규는 폼으로, 수정은 id 유지). 날짜 파싱 단일 출처 `Util::dateOrNull`.

- [ ] **Step 1(RED)**: `scripts/tests/unit_r15.php` 생성 — 트랜잭션 롤백 골격(unit_r14 헤더 컨벤션) + 우선 서비스 레벨로 검증 가능한 것: `Util::dateOrNull('2026-13-99') === null`, `Util::dateOrNull('') === null`, `Util::dateOrNull('2026-07-28') === '2026-07-28'`(기존 헬퍼 스모크 — 컨트롤러 치환의 안전망). run.php에 `'unit_r15'` 등록. (컨트롤러 리다이렉트는 Step 6 curl로 검증.)
- [ ] **Step 2**: ProjectsController::save —
  - `:614-618` `Response::error('고객을 선택하거나 고객명을 입력하세요.', 422)` → `Response::redirect('projects.form', $id ? ['id' => $id] : [], '고객을 선택하거나 고객명을 입력하세요.', 'error');`
  - `:632-634` 동일 패턴으로 교체(`'선택한 고객을 찾을 수 없습니다.'`).
  - 날짜 5곳(:668-672) `Util::nullIfEmpty(Util::postStr('X'))` → `Util::dateOrNull(Util::postStr('X'))`.
  - 길이 캡: `'name' => mb_substr($name, 0, 150)`, site_address `mb_substr(...,0,255)`, work_type `mb_substr(...,0,50)` (nullIfEmpty 유지 조합: `Util::nullIfEmpty(mb_substr(Util::postStr('site_address'), 0, 255))`).
  - 금액 클램프: contract_amount/estimated_cost `min(999999999999, max(0, (int) round(...)))`.
  - insert/update(:706·:735 포함 주변 공정 초기화까지) try/catch(\Throwable) → `Response::redirect('projects.form', $id ? ['id'=>$id] : [], '저장에 실패했습니다. 입력값을 확인해주세요.', 'error')` (원문 메시지는 error_log로만).
- [ ] **Step 3**: QuotesController::save — `:224` `Util::nullIfEmpty(...)` → `Util::dateOrNull(Util::postStr('valid_until'))`; `:243` `$this->parseItems(is_array($_POST['items'] ?? null) ? $_POST['items'] : [])`; parseItems 내 항목명 `mb_substr($name, 0, 100)`·금액 클램프(999999999999); `:253` `Db::transaction(...)` 전체를 try/catch → 실패 플래시(`'저장에 실패했습니다...'`, 폼 복귀 id 유지).
- [ ] **Step 4**: ContractsController::save — 날짜 6곳(:397 contract_date, :405-406 start/end, :417-419 due 3종) `Util::nullIfEmpty` → `Util::dateOrNull`. (기존 try/catch·캡은 유지 — 무변경.)
- [ ] **Step 5**: 폼 필수 표시 —
  - projects/form.php: 생성사유(:32 인접 라벨)·프로젝트명(:38)·공사유형(:85) 라벨에 별표(기존 없으면 추가, 관례 `<span class="req">*</span>`), 일반 모드 고객(:44) 별표, 예외 모드 고객명 입력 라벨에 별표+`(고객 미선택 시 필수)` 안내. 각 input 유지 중인 required 속성 확인·누락분 추가. name maxlength=150·site_address 255·work_type 50 부여.
  - quotes/form.php: 고객(:20) 별표 확인, 항목 테이블 상단에 `<span class="muted fs-12">항목 1개 이상 필수 · 항목명 100자 이내</span>` 안내. quotes.js 항목명 input에 `maxlength="100"` 추가.
- [ ] **Step 6(GREEN·통합)**: php -l 전 파일 + node --check quotes.js + `php scripts/tests/run.php` ✅. 로컬 curl(관리자 세션): ①프로젝트 신규 POST(projects.save)에 고객·고객명 없이 → **302 리다이렉트**(Location projects.form) + 후속 GET에 플래시 문구, 422/500 아님 ②잘못된 날짜(`start_date=abc`) POST → 302(저장 성공, 날짜 NULL) — 500 아님 확인 후 생성행 삭제 ③견적 items 스칼라 POST → 500 아님. audit/notifications 정리.
- [ ] **Step 7**: 커밋 `fix(r15): 폼 검증 개편 — 오류페이지 이동 근절(플래시)·필수 표시·dateOrNull/길이캡/try-catch 500 차단`

---

## Task 3: 계약 삭제 + 휴지통 백엔드(복원·완전삭제 3엔티티) (TDD)

**Files:**
- Modify: `app/routes.php`, `app/controllers/ContractsController.php`, `app/controllers/QuotesController.php`, `app/controllers/ProjectsController.php`, `scripts/tests/unit_r15.php`

**Interfaces:**
- 라우트(전부 POST): `contracts.delete`(perm contract.manage — routes의 기존 계약 perm 키 확인 후 동일 키 사용), `quotes.restore`/`quotes.purge`(perm quote.manage), `contracts.restore`/`contracts.purge`(계약 perm), `projects.restore`/`projects.purge`(perm project.manage). purge는 컨트롤러에서 super_admin 403 가드.
- 응답: 전부 `Response::redirect(<index 라우트>, ['trash' => 1], '메시지'[, 'error'])` (JSON 불요 — form POST).

- [ ] **Step 1(RED)**: unit_r15.php에 추가(모든 픽스처 트랜잭션 내 생성·롤백, **기존 행 무접촉**) — 컨트롤러가 아닌 동작 규칙을 검증할 수 있게 아래 가드 로직은 컨트롤러 private 대신 **정적 헬퍼로 구현**해 테스트: `ContractsController::deleteBlockReason(int $contractId): ?string`(live 프로젝트 존재 시 사유 반환), `QuotesController::purgeBlockReason(int $quoteId): ?string`, `ContractsController::purgeBlockReason(...)`, `ProjectsController::purgeBlockReason(...)`(참조 존재 시 '입금 N건, 비용 N건…' 나열), `ProjectsController::restoreBlockReason(int $projectId): ?string`(동일 계약 live 프로젝트 → 사유). 테스트: ①계약+live 프로젝트 → deleteBlockReason not null / 프로젝트 soft-delete 후 null ②quote+계약행 → purgeBlock not null; 계약행 없으면 null ③프로젝트+payments 1건 → purgeBlock에 '입금' 포함; 무참조 프로젝트 → null ④contract_id 있는 soft-deleted 프로젝트 + 동일 계약 신규 live 프로젝트 → restoreBlock not null ⑤무참조 견적 purge 실행 헬퍼(`QuotesController::purgeQuote($id)` 정적) → quotes/quote_versions/quote_items 물리 삭제 확인.
- [ ] **Step 2(구현)**: 각 컨트롤러에 정적 가드 헬퍼 + 액션 구현:

```php
// ContractsController — 소프트삭제(견적 delete 패턴 준용)
public static function deleteBlockReason(int $contractId): ?string
{
    $pid = Db::val("SELECT id FROM projects WHERE contract_id = :c AND deleted_at IS NULL LIMIT 1", [':c' => $contractId]);
    return $pid !== null ? '프로젝트로 전환된 계약입니다. 먼저 해당 프로젝트를 삭제(휴지통)하세요.' : null;
}
public function delete(): void
{
    $id = (int) Util::postInt('id', 0);
    $contract = Db::one("SELECT * FROM contracts WHERE id = :id AND deleted_at IS NULL", [':id' => $id]);
    if (!$contract) { Response::redirect('contracts.index', [], '계약을 찾을 수 없습니다.', 'error'); }
    $reason = self::deleteBlockReason($id);
    if ($reason !== null) { Response::redirect('contracts.show', ['id' => $id], $reason, 'error'); }
    Db::update('contracts', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
    Audit::log('contract_delete', 'contracts', $id, $contract, null);
    Response::redirect('contracts.index', [], '계약이 휴지통으로 이동되었습니다.');
}
```

purge 공통 골격(예: 프로젝트 — 견적·계약도 동일 구조, 참조 목록만 상이):

```php
/** 완전삭제 차단 사유(참조 존재 시) — RESTRICT 부모 나열. 없으면 null. */
public static function purgeBlockReason(int $projectId): ?string
{
    $refs = [];
    foreach ([
        ['payments', 'project_id', '입금'], ['costs', 'project_id', '비용'],
        ['site_bonuses', 'project_id', '보너스'], ['project_files', 'project_id', '파일'],
        ['schedules', 'project_id', '일정'], ['work_logs', 'project_id', '작업일지'],
        ['project_assignments', 'project_id', '직원 배정'],
    ] as [$t, $col, $label]) {
        $n = (int) Db::val("SELECT COUNT(*) FROM `$t` WHERE `$col` = :id", [':id' => $projectId]);
        if ($n > 0) { $refs[] = "{$label} {$n}건"; }
    }
    return $refs ? ('연결된 기록(' . implode(', ', $refs) . ')이 있어 완전삭제할 수 없습니다. 기록 보존을 위해 휴지통에 유지하세요.') : null;
}
public function purge(): void
{
    if (!Rbac::isRole('super_admin')) {
        Audit::log('access_denied', 'project', null, null, ['action' => 'project_purge']);
        http_response_code(403);
        View::renderError(403, '접근 권한 없음', '완전삭제는 최고 관리자만 가능합니다.');
        return;
    }
    $id = (int) Util::postInt('id', 0);
    $row = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NOT NULL", [':id' => $id]);
    if (!$row) { Response::redirect('projects.index', ['trash' => 1], '휴지통에 있는 프로젝트만 완전삭제할 수 있습니다.', 'error'); }
    $reason = self::purgeBlockReason($id);
    if ($reason !== null) { Response::redirect('projects.index', ['trash' => 1], $reason, 'error'); }
    Db::transaction(function () use ($id) {
        Db::run("DELETE FROM projects WHERE id = :id", [':id' => $id]); // 이력·게이지·메모는 FK CASCADE
    });
    Audit::log('project_purge', 'project', $id, $row, null);
    Response::redirect('projects.index', ['trash' => 1], '완전삭제되었습니다.');
}
```
- 견적 purge: block = `Db::val("SELECT id FROM contracts WHERE quote_id = :id LIMIT 1")`(삭제분 포함 — FK RESTRICT); 실행 = 트랜잭션 내 `DELETE quote_items WHERE quote_version_id IN (SELECT id FROM quote_versions WHERE quote_id=:id)` → `DELETE quote_versions` → `DELETE quotes`. 정적 `purgeQuote(int $id): void`로 분리(테스트 호출용) 후 액션이 사용.
- 계약 purge: block = payments/projects/contract_terminations 참조(각 COUNT, 삭제분 포함) 나열; 실행 = `DELETE contracts`(status_history CASCADE).
- restore 3종: `deleted_at IS NOT NULL` 확인 → (프로젝트만 restoreBlockReason 가드) → `Db::update(..., ['deleted_at' => null], ...)` + `Audit::log('*_restore', ...)` → trash 목록으로 플래시.
- ProjectsController 기존 `delete()`의 플래시 문구를 '휴지통으로 이동되었습니다.'로 통일(동작 불변). QuotesController delete 문구 동일 통일.
- routes.php에 7개 라우트 추가(기존 계약 perm 키는 파일에서 확인해 동일하게).
- [ ] **Step 3(GREEN)**: unit_r15 전체 PASS + `php scripts/tests/run.php` ✅ + php -l.
- [ ] **Step 4**: 커밋 `feat(r15): 계약 삭제(소프트)+3엔티티 복원·완전삭제 백엔드 — 참조 거부·super_admin 가드 (TDD)`

---

## Task 4: 휴지통 UI(3 index trash 모드) + 계약 삭제 버튼

**Files:**
- Modify: `app/controllers/QuotesController.php`(index), `ContractsController.php`(index), `ProjectsController.php`(index) — trash 파라미터·WHERE 분기·뷰 플래그
- Modify: `app/views/quotes/index.php`, `app/views/contracts/index.php`, `app/views/projects/index.php`, `app/views/contracts/show.php`(삭제 버튼)

**Interfaces:** GET `trash=1` — index 컨트롤러: `$trash = Util::int('trash', 0) === 1;` WHERE의 `deleted_at IS NULL` → `IS NOT NULL`, 뷰에 `'trash' => $trash` 전달(+pager filters에 trash 유지). 목록 SELECT에 `deleted_at` 포함.

- [ ] **Step 1**: 3개 index 컨트롤러에 trash 분기(각 $where 구성 지점 — Quotes :44, Contracts :67, Projects :59). 프로젝트는 Scope 유지.
- [ ] **Step 2**: 3개 index 뷰 —
  - page-actions: 일반 모드에 `<a href="<?= e(url('X.index', ['trash' => 1])) ?>" class="btn btn-ghost">휴지통</a>` 추가(manage perm 게이트: quotes `can('quote.manage')`, contracts 동일 계약 perm, projects `can('project.manage')`). trash 모드에선 제목 옆 `휴지통` 배지 + '목록으로' 버튼(트래시 param 제거 링크), 생성 버튼 숨김.
  - trash 모드 테이블: 기존 컬럼 유지 + '삭제일' 컬럼 + '관리' 컬럼 — 행마다:
```php
<td class="nowrap">
  <form method="post" action="<?= e(url('X.restore')) ?>" style="display:inline"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
    <button type="submit" class="btn btn-sm btn-outline">복원</button></form>
  <?php if (is_role('super_admin')): ?>
  <form method="post" action="<?= e(url('X.purge')) ?>" style="display:inline"
        onsubmit="return confirm('완전삭제하면 되돌릴 수 없습니다. 진행할까요?');"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
    <button type="submit" class="btn btn-sm btn-danger">완전삭제</button></form>
  <?php endif; ?>
</td>
```
  - trash 모드에서 행 클릭 상세 이동은 유지하되(참고용) 없으면 생략 — 각 뷰의 기존 row 링크 구조 존중.
- [ ] **Step 3**: contracts/show.php page-actions에 삭제 버튼(파기 버튼 옆, terminated 아니어도 표시):
```php
<form method="post" action="<?= e(url('contracts.delete')) ?>" style="display:inline"
      onsubmit="return confirm('이 계약을 휴지통으로 이동하시겠습니까?');"><?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $contract['id'] ?>">
  <button type="submit" class="btn btn-danger">삭제</button></form>
```
(perm 게이트는 페이지 기존 관리 버튼과 동일 조건.)
- [ ] **Step 4**: 검증 — php -l 전 파일. 로컬 curl: 견적 1건 삭제→`?r=quotes.index&trash=1`에 노출→restore→일반 목록 복귀; 프로젝트 무참조 1건 purge(super admin)→완전삭제; 참조 있는 건 purge→거부 플래시. 각 시나리오 임시 데이터 생성·정리(기존 행 무접촉).
- [ ] **Step 5**: 커밋 `feat(r15): 휴지통 UI(견적·계약·프로젝트 trash 모드·복원·완전삭제)+계약 삭제 버튼`

---

## Task 5: 통합 QA
- [ ] 회귀: `php scripts/tests/run.php` ✅ · `bash scripts/qa_smoke.sh` PASS · `php scripts/reconcile_qa.php`(로그 잔재 정리 후 56/0).
- [ ] 브라우저(PC 1440/모바일 390, puppeteer): ①반기 화면 보너스 등록 모달 열림·이력 링크 ②계약 상세 '입금내역 갱신' ③프로젝트 신규 폼 필수 별표·빈 제출 시 브라우저 차단·서버 우회 POST 시 플래시(오류페이지 아님) ④계약 삭제→휴지통→복원 왕복 ⑤무참조 견적 완전삭제(super) / 참조 견적 거부 문구 ⑥콘솔 오류 0. 스크린샷 저장.
- [ ] harness verify 기록.

## Task 6: 운영 배포·재검증
- [ ] 마이그레이션 없음 확인 → `./deploy/deploy.sh` dry 검토 → `CONFIRM=yes` 실배포 → `./deploy/verify.sh` 그린 → 운영 비로그인 스모크(로그인 페이지·오류 문자열 0). harness 기록·메모리 갱신.

## Self-Review
- 스펙 커버리지: #1→T1, #2→T1(Step4), #3→T2, #4→T3(delete)+T4(버튼), #5→T3(백엔드)+T4(UI). ✅
- 타입: `*BlockReason(int): ?string` / `purgeQuote(int): void` 정적 — 테스트·액션 공용. 라우트 7종 T3↔T4 일치.
- DB 불가침: 신규 테이블·컬럼·백필 없음. purge는 사용자가 지정한 soft-deleted 행만.
