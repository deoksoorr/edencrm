# R11 — 예외 프로젝트 입금·정산 + 공정 보드 잠금 제거·매핑 재검증 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 예외 프로젝트(계약 미연결)에 입금·정산 원장을 붙여 확정 매출·순이익·성과·보너스까지 끊김 없이 연결하고, 공정 보드의 잠금 기능을 제거하며 단계 매핑을 DB 기준 동적 표시로 재구축한다.

**Architecture:** 기존 `payments` 원장을 확장(계약 입금 + 예외 프로젝트 직접 입금 공용, `project_id` 실FK — 스펙 옵션 1의 안전 변형)하고, `AccountingService`를 입금(현금) 기준 단일 산식으로 통일한다. 정산 상태는 `projects.settlement_status` 로 공정 상태와 분리한다. 공정 보드는 requires_confirm·완료잠금·full_complete 게이트를 제거하고 단계 번호를 유형별 위치 번호(1..N)로 동적 산출한다.

**Tech Stack:** PHP 8(자체 MVC), MySQL/MariaDB(cafe24 prefix `edencrm_`), SortableJS, 자체 QA 스위트(scripts/tests, reconcile_qa).

## Global Constraints

- 공정 이동은 반드시 `ProcessService::moveStage` 경유(직접 UPDATE 금지 — R3 커널).
- 현금 데이터 물리 삭제 금지 — 입금 취소는 `status='cancelled'` 전환.
- 상태 변경은 `StatusService` 경유 + 이력 기록. 감사는 `Audit::log`.
- 집계 산식은 `AccountingService` 단일 출처(중복 구현 금지).
- 컨트롤러가 `Response::json`만 하는 액션에 네이티브 폼 금지 — `wantsJson()` 분기 필수(R10-F 재발 방지).
- 하드코딩 단계 번호 금지 — 공정 마스터(DB)에서 동적 산출.
- 운영 DB 는 `deploy/run_migration.php` 로만 쓰기, 배포는 `deploy/deploy.sh`.

## 확정 설계 결정

- **D1 원장**: `payments` 에 `project_id`(FK) 추가 + `contract_id` NULL 허용. 행마다 정확히 하나만 세팅(앱 검증). 별도 테이블 대신 공통 원장 → 모든 집계가 UNION 없이 단일 테이블 유지. `method`(입금 방식)/`payer_name`(입금자명)/`created_by`(등록자) 컬럼 추가.
- **D2 확정 매출 = 입금 기준 통일(사장 지시로 R7 완납 기준 대체)**: `confirmedRevenue` = Σ순입금(paid, payment−refund, 계약+예외 공통), 귀속=paid_date, VAT 포함 현금 축(R10 보너스 산식과 동일 축).
- **D3 확정 순이익 = 확정 매출(입금) − 원가 총액(costs confirmed, spent_date)**. 완료 모집단(actual_end_date) 기반 산식 폐기. 직원 귀속도 입금×기여도.
- **D4 예정 금액**: `projects.expected_amount`(예외 전용 입력·수정, audit `project_expected_amount_change`). 일반 프로젝트 예정 금액 = 계약 총액.
- **D5 정산 상태**: `projects.settlement_status` — unsettled(미정산)/partial(일부 정산)/settled(정산 완료)/refunding(환불 진행)/hold(정산 보류). unsettled/partial 은 입금 이벤트 시 자동 재계산, settled 는 수동 전용(가드: 미수금 0 + pending 입금/환불 0), hold/refunding 수동 토글(사유 기록). settled 후 미수금 재발생 → partial 자동 강등 + audit. 레거시 프로젝트 상태 'settled' 전환에도 동일 가드.
- **D6 입금 상태(파생, 저장 안 함)**: none(미입금)/partial(일부 입금)/paid(완납)/over(초과 입금) + 환불 발생 배지.
- **D7 잠금 제거**: requires_confirm 사용처 전부(보드 🔒·JS confirm·검수 대기 KPI 3곳·설정 체크박스·move 응답), full_complete 게이트, 완료·정산 카드 .locked 이동 차단 제거. 완료·정산 카드 이동 시 in_progress 자동 재개(`settled→in_progress` 전이 신설, 사유 자동 기록). cancelled/terminated 는 보드 비노출 유지. DB 컬럼 requires_confirm 은 보존(비파괴)하되 읽지 않음.
- **D8 단계 번호 = 유형별 위치 번호**: 활성 단계 ordered 목록(유형+공통, sort_order,id)에서 대기중=0, 실공정 1..N. 그룹 라벨 범위·진행률 분모(N)·건너뜀 경고(위치차 ≥2)·full_complete 직행 판정(from 그룹 finish/defect) 모두 위치 번호·그룹 기준. sort_order 원값 화면 노출 제거.

---

### Task 2: 스키마 — 010 마이그레이션

**Files:**
- Create: `database/cafe24/010_r11_exception_settlement.sql`
- Create: `database/migrations/2026-07-27_r11_settlement.sql` (로컬 무프리픽스판)
- Create: `scripts/apply_local_migration.php` (로컬 dev DB 적용 러너 — app config.local 로 PDO 접속)
- Modify: `database/schema.sql:378-395`(payments), `461-510`(projects)

**Interfaces:**
- Produces: `payments.project_id`(NULL FK projects), `payments.contract_id`(NULL 허용), `payments.method`, `payments.payer_name`, `payments.created_by`, `projects.expected_amount`, `projects.settlement_status`(기본 'unsettled').

- [ ] cafe24 SQL 작성 (아래 원문):

```sql
-- R11: 예외 프로젝트 입금·정산 — 공통 입금 원장 확장 + 정산 상태 분리
ALTER TABLE `edencrm_payments`
  MODIFY `contract_id` INT UNSIGNED NULL COMMENT '계약 입금(일반) — project_id 와 택1',
  ADD COLUMN `project_id` INT UNSIGNED NULL COMMENT '예외 프로젝트 직접 입금 — contract_id 와 택1(R11)' AFTER `contract_id`,
  ADD COLUMN `method` VARCHAR(20) NULL COMMENT '입금 방식: transfer/cash/card/etc(R11)' AFTER `pay_type`,
  ADD COLUMN `payer_name` VARCHAR(100) NULL COMMENT '입금자명(R11)' AFTER `memo`,
  ADD COLUMN `created_by` INT UNSIGNED NULL COMMENT '등록자(R11)' AFTER `payer_name`,
  ADD INDEX `idx_payments_project` (`project_id`),
  ADD CONSTRAINT `fk_payments_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE RESTRICT;

ALTER TABLE `edencrm_projects`
  ADD COLUMN `expected_amount` DECIMAL(14,0) NULL COMMENT '정산 예정 금액 — 예외 프로젝트 직접 입력(R11)' AFTER `contract_amount`,
  ADD COLUMN `settlement_status` VARCHAR(20) NOT NULL DEFAULT 'unsettled' COMMENT '정산 상태: unsettled/partial/settled/refunding/hold(R11)' AFTER `status`,
  ADD INDEX `idx_projects_settlement` (`settlement_status`);

-- 백필: settled 상태 프로젝트는 정산 완료, 그 외 순입금>0 이면 일부 정산
UPDATE `edencrm_projects` p
LEFT JOIN `edencrm_contracts` c ON c.id = p.contract_id AND c.deleted_at IS NULL
SET p.settlement_status = CASE
  WHEN p.status = 'settled' THEN 'settled'
  WHEN c.id IS NOT NULL AND COALESCE((SELECT SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)
       FROM `edencrm_payments` pm WHERE pm.contract_id = c.id AND pm.status='paid'),0) > 0 THEN 'partial'
  ELSE 'unsettled' END
WHERE p.deleted_at IS NULL;
```

- [ ] 로컬판(무프리픽스) 동일 작성, `database/schema.sql` 컬럼 정의 동기화
- [ ] `php scripts/apply_local_migration.php database/migrations/2026-07-27_r11_settlement.sql` 로 dev DB 적용, DESCRIBE 검증
- [ ] 커밋 `feat(r11): 공통 입금 원장 확장(project_id·method·payer)·정산 상태 스키마 + 010`

### Task 3: 백엔드 — 회계 통일·정산 서비스·입금 CRUD

**Files:**
- Modify: `app/core/AccountingService.php` — 산식 개편(아래), `app/core/StatusService.php`, `app/controllers/BonusController.php:347-351`, `app/controllers/ContractsController.php`(savePayment 에 method/payer/created_by), `app/controllers/ProjectsController.php`(save 예정금액·show 정산 데이터·index 집계/필터), `app/routes.php`
- Create: `app/controllers/SettlementController.php`

**Interfaces (Produces):**
- `AccountingService::projectNetPaid(int $projectId): int` — 예외 직결 입금 순액
- `AccountingService::projectPaySummary(array $project): array{expected:int, paid:int, refund:int, pendingCnt:int, outstanding:int, pay_status:string}` — 일반=계약축/예외=프로젝트축 자동 분기
- `AccountingService::confirmedRevenue/confirmedProfit/paidTotal/refundTotal/receivable/receivableCount/recentPaidPayments/employee*ByUser` — 입금 기준 통일판(시그니처 불변)
- `StatusService::recalcProjectSettlement(int $projectId): string` — D5 자동 전이 적용, 반환=결과 상태
- `StatusService::PROJECT_TRANSITIONS['settled'] = ['in_progress']` + REASON_REQUIRED `'settled>in_progress'`
- Routes: `projects.payment.save|cancel`(POST, perm payment.manage) → SettlementController::paymentSave/paymentCancel, `projects.settlement.update`(POST, perm payment.manage) → settlementUpdate, `projects.expected.save`(POST, perm payment.manage) → expectedSave, `projects.payment.history`(GET) → paymentHistory
- 핵심 SQL 조각: `PAY_NET_CASE = "CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END"`, 전사 순입금 = payments(status='paid') ⟕ contracts(deleted 제외)/projects(deleted 제외) 양립 조건

- [ ] AccountingService: `confirmedRevenue` 를 아래로 교체(기존 CONFIRMED_CONTRACT_COND·LAST_PAID_SQL 은 참조 제거):

```php
/** 확정 매출(입금 기준, R11 통일) = Σ순입금(paid, payment−refund) — 계약 입금 + 예외 프로젝트 직접 입금.
 *  귀속 = paid_date. 미입금 제외·환불/취소 차감. 일반·예외 동일 공통 산식(사장 지시 — R7 완납 기준 대체). */
public static function confirmedRevenue(?string $from = null, ?string $to = null): int
{
    $p = [];
    $r = self::range('pm.paid_date', $from, $to, $p);
    return (int) Db::val("SELECT COALESCE(SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END),0)
        FROM payments pm
        LEFT JOIN contracts c ON c.id = pm.contract_id
        LEFT JOIN projects pj ON pj.id = pm.project_id
        WHERE pm.status='paid'
          AND ((pm.contract_id IS NOT NULL AND c.deleted_at IS NULL)
            OR (pm.project_id IS NOT NULL AND pj.deleted_at IS NULL)) $r", $p);
}
```

- [ ] `paidTotal`/`refundTotal` 도 동일 모집단(LEFT JOIN 양축)으로 확장. `confirmedProfit($f,$t) = confirmedRevenue($f,$t) − costTotal($f,$t)`, `confirmedCost` 는 `costTotal` 위임(호출부 의미 유지). `companyConfirmedProfit` 별칭 유지.
- [ ] `receivable()/receivableCount()` 에 예외 축 추가: `+ Σ max(0, expected_amount − 프로젝트 순입금)` (is_exception=1, deleted 제외, status NOT IN cancelled/terminated, expected_amount>0).
- [ ] `employeePaidByUser` 에 예외 입금 경로 추가(pm.project_id → projects → assignments). `employeeConfirmedByUser/Revenue/Contribution` 을 입금×기여도(paid_date 귀속, cost=spent_date×기여도) 기준으로 재작성 — revenue=순입금 귀속, contrib=revenue−cost.
- [ ] `recentPaidPayments` — LEFT JOIN 전환, 고객명 `COALESCE(cu.name, pj.customer_name_snapshot)`, 예외 행 프로젝트명 직접.
- [ ] `projectNetPaid`, `projectPaySummary`(입금 상태 파생: none/partial/paid/over) 신설.
- [ ] StatusService: `recalcProjectSettlement`(D5 규칙), `recalcContractPaymentStatus` 말미에 연결 프로젝트 정산 재계산 추가, settled→in_progress 전이 허용+사유 필수.
- [ ] SettlementController 신설 — paymentSave(등록·수정, kind payment/refund, 환불 상한=현재 순입금, 예외 프로젝트 전용 검증: is_exception=1 && contract_id NULL, `$canFinance` 스코프, Audit payment_create/update), paymentCancel(status='cancelled' 전환, Audit payment_cancel), expectedSave(전·후·수정자 Audit), settlementUpdate(settle 가드/hold/refunding/release), paymentHistory(JSON — payments 행 + audit 발췌). 전 액션 `Response::json` + Scope 가드.
- [ ] ContractsController::savePayment `$data` 에 method/payer_name/created_by(신규시) 추가.
- [ ] BonusController::projectBonusBase:

```php
private static function projectBonusBase(array $p): int
{
    $contractId = (int) ($p['contract_id'] ?? 0);
    if ($contractId > 0) { return max(0, AccountingService::contractNetPaid($contractId)); }
    // R11: 예외 프로젝트 — 프로젝트 직접 입금(순액) 기준(동일 산식 축)
    if ((int) ($p['is_exception'] ?? 0) === 1) { return max(0, AccountingService::projectNetPaid((int) $p['id'])); }
    return 0;
}
```

- [ ] ProjectsController::save — 예외 프로젝트 `expected_amount` 입력 수용(수정 시 변경되면 `project_expected_amount_change` audit before/after+수정자), show()에 `paySummary`·`projectPayments`(예외)·`settlementAudit` 전달, index()에 예정/입금/미수금/정산 컬럼 서브쿼리 + `pay_status`/`settlement` 필터 추가.
- [ ] 단위 테스트(unit_r11) 골격 추가 후 `php scripts/tests/run.php` 기존+신규 통과, 커밋

### Task 4: UI — 입금·정산 탭·목록·폼

**Files:**
- Create: `app/views/projects/_tab_settlement.php`
- Modify: `app/views/projects/show.php:152-186`(탭 추가), `app/views/projects/index.php`(컬럼·필터), `app/views/projects/form.php`(예정 금액), `app/views/halfyear/index.php`(라벨 '확정 매출(입금 기준)'), `app/views/dashboard/boss.php`(툴팁 문구), `public/assets/js/`(탭 내 AJAX — 인라인 스크립트로 충분 시 뷰에 포함)

- [ ] '입금·정산' 탭($canFinance 게이트): KPI(예정 금액/누적 입금/미수금/입금 상태/정산 상태), 일반=계약 입금 요약 테이블+계약 보기 링크, 예외=입금 등록·수정·취소·환불 폼(금액/입금일/방식/입금자명/구분/메모) + 행 테이블(등록자 표시) + 예정 금액 수정 + 정산 상태 컨트롤(정산 완료 처리·보류·환불 진행·해제) + 변경 이력(audit)
- [ ] 목록: 예정 금액·누적 입금·미수금·입금 상태·정산 상태 컬럼($canFinance), 필터 select(미입금/일부 입금/완납/미수금 있음/정산 완료/정산 보류) — 기존 reg_type '예외만'과 병용
- [ ] 폼: 예외 프로젝트에 '정산 예정 금액' 입력(신규+수정)
- [ ] 로컬 브라우저 확인 후 커밋

### Task 5: 공정 보드 — 잠금 제거·위치 번호 매핑

**Files:**
- Modify: `app/controllers/ProcessController.php`(move 175-177 완료차단 제거→자동 재개, 207-245 게이트·17 하드코딩 제거, 249-254 분모 위치화, computeSummary inspect 제거), `app/core/Stages.php`(위치 번호 헬퍼 `processStagePositions(type): array{positions: map, total:int}`), `app/views/process/board.php`(17·43·47-50·81-83·100·104·113 라인), `public/assets/js/process-board.js`(requiresConfirm·gate·locked 제거), `app/controllers/SettingsController.php:191`, `app/views/settings/stages.php:197,232`, `app/controllers/DashboardController.php`(processBoardCounts inspect→doing, inspectionPending 제거), `app/views/dashboard/boss.php:204-215`, `app/views/dashboard/site.php:26`, `app/views/projects/_tab_process.php`(sort 비교 위치화 — done 판정)
- StatusService: settled→in_progress (Task 3에서 선반영)

- [ ] Stages 위치 번호 헬퍼(활성 유형+공통, sort_order·id 순, waiting=0, 실공정 1..N) — 보드 라벨·진행률·이동 판정 공용
- [ ] move(): 완료·정산 카드 이동 허용(대상≠full_complete 이면 StatusService 경유 in_progress 재개, 사유 '공정 보드 이동 재개'), 게이트·requires_confirm 응답 제거, 진행률 = round(pos/N×100)
- [ ] 보드 뷰·JS·설정·대시보드에서 잠금·검수 흔적 제거, 그룹 범위 = 위치 번호(비연속 그룹은 'n개 공정')
- [ ] 새 공정 추가 시 자동 매핑 확인(설정에서 추가 → 보드 반영), 도장/인테리어 상호 격리 확인, 커밋

### Task 6: 자동 테스트·로컬 QA

- [ ] `scripts/tests/unit_r11_settlement.php` — projectPaySummary 파생(none/partial/paid/over), settle 가드(미수금>0 거부·pending 거부·0원+정리 시 허용), 자동 강등, 예외 보너스 base, confirmedRevenue 통일(계약+예외 합산·환불 차감), 위치 번호 매핑(도장/인테리어/비활성 건너뜀)
- [ ] 기존 스위트 산식 기대값 R11 기준으로 갱신(unit_profit·unit_r3_acctverify 등 — 완납 기준 검증부), `php scripts/tests/run.php` 전체 통과
- [ ] `php scripts/reconcile_qa.php` 대사 스크립트 R11 산식으로 갱신·통과, `bash scripts/qa_smoke.sh`
- [ ] 로컬 브라우저 시나리오: 스펙 17단계(생성→일부입금→확정매출→종결→정산가드→잔금→정산완료→반기·성과·보너스→환불 차감→이력) 실측
- [ ] 커밋 `test(qa): R11 정산·보드 회귀 스위트`

### Task 7: 운영 배포·실측 검증

- [ ] `deploy/backup.sh` 또는 DB 덤프(edencrm_%) 백업
- [ ] `php deploy/run_migration.php database/cafe24/010_r11_exception_settlement.sql --dry` → 실행
- [ ] `CONFIRM=yes ./deploy/deploy.sh` → `./deploy/verify.sh`
- [ ] 운영 실측: 공정 보드(이동 자유·단계 라벨=설정 100% 일치·유형 격리·신규 공정 자동 매핑), 예외 프로젝트 17단계 시나리오(QA 계정 — prod_r10_verify 패턴, 종료 후 완전 원복: QA 생성물 하드삭제·감사로그만 잔존)
- [ ] harness verify/hold 기록, 메모리 갱신, 커밋

## Self-Review

- 스펙 커버리지: 입금·정산 탭 항목 14종(T4), 입금 상태 6종(D5·D6 — 환불 발생=배지, 정산 보류=정산 상태), 예정 금액 입력·수정 이력(T3), 매출 기준 4원칙(D2), 순이익 산식(D3), 공정/정산 상태 분리(D5), 종결≠정산완료 가드(T3 settle 가드), 목록 표시·필터 7종(T4), 원장 방식 선택 근거(D1), 연동 8종(대시보드/반기/성과/보너스/손익/감사/히스토리 — T3 서비스 개편으로 전파), 보너스 경고(R10 기존 + 예외 base 확장), 잠금 제거 5항목(D7), 매핑 검증 5항목(D8+T7). 갭 없음.
- 타입 일관성: projectPaySummary 반환 키(expected/paid/refund/pendingCnt/outstanding/pay_status)를 T3·T4 공용. settlement_status enum 5종 전 구간 동일.
