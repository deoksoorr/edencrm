# R13 설계 — 프로젝트·공정 보드 동기화 / 예외 프로젝트 입금·정산 / 보너스 실시간 재계산

작성일: 2026-07-28
브랜치: `r13-board-payment-bonus`
관련 메모: R10 기여도·보너스, R11 예외 입금·정산, R12 확정매출 VAT제외·보너스

---

## 1. 목표와 스코프

사장 지시 4건 중 3건을 구현한다. **③ 견적 연결 영업기회(리드) 제거는 스코프에서 제외**한다 —
리드 연결은 담당자 지정 외에 영업기회 보드의 단계 자동판정에도 쓰이며, 사장이 "영업기회는 건드리지 말라"고 결정.

| # | 기능 | 상태 |
|---|---|---|
| 1 | 프로젝트 ↔ 공정 보드 상태 동기화 | 구현 |
| 2 | 예외 프로젝트 입금/정산 개편 | 구현 |
| 3 | 견적 연결 영업기회 제거 | **제외** |
| 4 | 보너스 실시간 재계산(캐시 금지) | 구현 |

**불변 원칙**
- 공정 축(프로젝트 status ↔ 공정 보드)과 정산 축(settlement_status)은 R11/R12에서 분리됨 — 계속 분리 유지.
  이번 작업에서 "완료↔전체완료"는 공정 축, "전액 입금 완료"는 정산 축이며 서로 독립.
- `process_stage_id` 변경은 반드시 `ProcessService` 경유(직접 UPDATE 금지, 이력 기록).
- 운영 반영 전 로컬에서 전 시나리오 검증. 운영 직접 수정 금지.

---

## 2. 기능 1 — 프로젝트 ↔ 공정 보드 동기화

### 2.1 현재 동작(코드 실측)
- 보드 → 상태(존재): `ProcessController::move()` [app/controllers/ProcessController.php:228-245]
  - 전체완료로 이동 → status=completed
  - 전체완료에서 벗어남(완료/정산완료였음) → status=in_progress(재개)
  - 대기중에서 실공정으로 이동(preparing) → status=in_progress
- 상태 → 보드(**없음**): `StatusService::applyProjectStatus()` [app/core/StatusService.php:240-272] 은 `process_stage_id`를 건드리지 않음.
  → **완료 버튼을 눌러도 카드가 전체완료 컬럼으로 이동하지 않음** (지시의 빠진 부분).
- `ProcessService::moveStage()` 는 status를 절대 건드리지 않음(순수 보드 이동+이력).

### 2.2 목표 매핑

| 프로젝트 상태 | 공정 보드 위치 | 전환 트리거 |
|---|---|---|
| 진행 예정(preparing) | 대기중 | 프로젝트 생성 시 |
| 진행 중(in_progress) | 대기중·전체완료 외 실공정 | 착공준비 등으로 드래그(자동) |
| 하자보수(warranty) | 하자보수 컬럼 | 하자보수 컬럼으로 드래그(자동) |
| 완료(completed) | 전체완료 컬럼 | 완료 버튼 클릭(자동 이동) |
| 일시중단(paused) | 현재 컬럼 유지 | 이동 없음 |
| 취소/파기 | 보드 제외 | 기존 유지 |

### 2.3 변경

1. **완료 버튼 → 전체완료 자동이동 (신규, 상태→보드)**
   - `StatusService::applyProjectStatus($p, 'completed', …)` 안에서 `ProcessService::moveStage($id, full_complete_id, …, '완료 처리 자동 종결')` 호출.
   - full_complete는 common 단계라 도장/인테리어 양쪽 보드에서 동일 사용.
   - 무한루프 방지: `moveStage`는 status를 재전환하지 않음. 보드에서 전체완료로 드래그하는 경로(`ProcessController::move`)는 먼저 moveStage 후 applyProjectStatus(completed)를 부르는데, 그 안의 moveStage(full_complete)는 이미 같은 단계라 no-op.

2. **진행 시작 버튼 제거 (상태 UI)**
   - `show.php`의 상태 전환 버튼 중 `data-to="in_progress"`("진행 시작") 렌더 제거.
   - 착공준비 등 실공정으로 드래그 시 in_progress 자동전환(기존 로직) 유지·보강.
   - ⚠️ 검증 필요: 사장은 "보드 이동해도 진행 시작 버튼을 눌러야 진행중이 된다"고 보고. 현재 로컬 코드엔 자동전환이 있으므로 (a) 운영 배포본이 구버전이거나 (b) 특정 시나리오(예: paused 상태에서 이동, 또는 대기중이 아닌 데서 이동)에서 안 걸리는 것. **systematic-debugging으로 재현→근본원인 확인 후** 모든 "대기중→실공정" 및 "실공정 간" 이동에서 preparing/paused→in_progress가 확실히 걸리도록 보강.

3. **하자보수 전환 버튼 제거 + 보드 드래그 자동전환 (신규)**
   - `show.php` 상태 전환 버튼 중 `data-to="warranty"`("하자보수 전환") 렌더 제거.
   - `ProcessController::move()`에 규칙 추가: to_stage.stage_key === 'warranty_repair' 이고 현재 status가 completed/in_progress/paused 이면 status=warranty 자동전환.
   - 하자보수 컬럼에서 다시 실공정으로 빼면 in_progress(재개) — 기존 "종결 해제" 로직과 일관.
   - warranty 상태·보드 하자보수 컬럼·하자보수 관리 패널(warranty_repairs)은 유지.

### 2.4 파일
- [app/controllers/ProcessController.php] — move() 규칙 추가(warranty), preparing/paused 보강
- [app/core/StatusService.php] — applyProjectStatus(completed→전체완료 이동); PROJECT_TRANSITIONS에서 진행시작/하자보수 버튼 제거 영향 반영(전환 자체는 허용 유지하되 UI 버튼만 제거할지, 전환 목록에서 뺄지 결정: **UI에서만 제거, 내부 전환 규칙은 유지**하여 보드 자동전환이 applyProjectStatus를 통해 동작)
- [app/views/projects/show.php] — 버튼 렌더 필터(in_progress·warranty 제외)
- [public/assets/js/process-board.js] — 드래그 응답 status 동기화(기존) 확인

---

## 3. 기능 2 — 예외 프로젝트 입금/정산

### 3.1 현재 동작(코드 실측)
- 공통 원장 `payments`(contract_id XOR project_id), `kind`(payment/refund), `pay_type`(down/middle/balance/etc), `payer_name`, `method`, `status`.
- 예외 프로젝트 탭: [app/views/projects/_tab_settlement.php], 쓰기 [app/controllers/SettlementController.php].
- 입금 상태 산출: [app/core/AccountingService.php:229-256] `projectPaySummary()` — 예외는 `$expected = projects.expected_amount`, `$paid = projectNetPaid()`. 비교 `$paid === $expected`(strict) + expected 미설정 시 else 분기 → 어떤 입금이든 'partial'.
- 정산 상태: 별도 컬럼 `projects.settlement_status`(unsettled/partial/settled/refunding/hold), 라벨 [app/core/StatusService.php:92-98]. 수동 "정산 완료 처리" 버튼 [_tab_settlement.php:50-51].

### 3.2 변경

1. **버튼명 '수정' → '입금내역 갱신'** — [_tab_settlement.php:113] 라벨만 변경(동작 동일).

2. **입금 등록 유형화** — [_tab_settlement.php:214] payFormHtml
   - '입금자명(payer_name)' 입력란 **제거**.
   - **유형 드롭다운 추가**: 계약금/중도금/잔금 → 기존 `pay_type` 컬럼에 매핑(계약금=down, 중도금=middle, 잔금=balance).
   - 서버 [SettlementController::paymentSave] payer_name 처리 제거, pay_type 검증·저장 추가(허용값 down/middle/balance).
   - payer_name 컬럼은 스키마에 남기되(과거 데이터 보존) 신규 입력에서 미사용.

3. **환불 등록 유형 고정** — 환불 모달의 유형은 **'환불'(읽기전용)**. 저장은 `kind='refund'`(기존 유지)로 구분하고, `pay_type='refund'`로 저장해 유형 표시를 일관되게 한다.

4. **'예정 금액' → '계약 총액' 개편**
   - 라벨을 '계약 총액'으로 변경([_tab_settlement.php:24-32]). 예외 프로젝트는 계약서가 없어 이 값이 유일한 비교 기준 총액 → **제거하지 않고 필수화·명확화**.
   - 미설정 시 경고 배지 유지, 입금 상태 판정은 계약총액 설정 시에만.
   - 저장 경로(expectedSave, 프로젝트 폼) 라벨/문구 정리. 컬럼명 `expected_amount`는 유지(내부).

5. **자동 전액입금완료 (버그 수정 + 자동화)**
   - `projectPaySummary()` 비교를 `$paid >= $expected && $expected > 0 → 'paid'`(초과는 'over')로 수정. strict === 및 타입 불일치 제거.
   - `StatusService::recalcProjectSettlement()` [app/core/StatusService.php:206-233]: outstanding<=0 && expected_set && pendingCnt==0 이면 settlement_status='settled' **자동 승격**, 미달·환불 재발 시 자동 강등. 단 'hold'/'refunding'(수동)은 자동 덮어쓰기 금지(기존).

6. **정산 상태 명칭·수동 버튼**
   - `StatusService::SETTLEMENT_LABELS`의 'settled' 라벨 '정산 완료' → **'전액 입금 완료'**.
   - 수동 "정산 완료 처리" 버튼 [_tab_settlement.php:50-51] **제거**(자동화로 대체). settlementUpdate의 'settle' 액션도 제거/무력화. 'hold'(정산 보류)·'refunding'(환불 진행) 수동 액션은 유지.

### 3.3 파일
- [app/views/projects/_tab_settlement.php], [app/controllers/SettlementController.php],
  [app/core/AccountingService.php], [app/core/StatusService.php], [app/controllers/ProjectsController.php](expected_amount 폼 저장부)

---

## 4. 기능 4 — 보너스 실시간 재계산

### 4.1 근본 원인(코드 실측)
- 산식은 입금 기준(`AccountingService::projectConfirmedRevenue` 실시간 반영 가능)이나,
  결과가 보너스 등록/수정 시점에 `site_bonuses` 행(base_amount·contrib_revenue·contrib_profit·calc_amount)에 **스냅샷 저장**.
- 입금등록·수정·환불·철회(파기)·계약금변경 등 어떤 이벤트도 `site_bonuses`를 재계산하지 않음
  (모두 `recalcPaymentStatus`/`recalcProjectSettlement`만 호출). 손실값(=contrib_profit 음수)도 동일 문제.
- 단일 재계산 함수 부재. 산식은 `BonusController` 내부 private static에 있음.

### 4.2 변경

1. **신규 `app/core/BonusService.php`**
   - `recalcForProject(int $projectId): void`
   - 대상: 해당 project의 미삭제·미취소 `site_bonuses` 행.
   - 재계산 컬럼: `base_amount`(=projectConfirmedRevenue, 입금 기준), `contrib_revenue`(=base×기여율), `contrib_profit`(=(확정매출−actual_cost)×기여율, 손실 반영), `calc_amount`(=contrib_revenue×보너스율).
   - **`confirmed_bonus`(실지급 확정) 보존** — 소급 변경 금지(사장 결정).
   - 기여율은 산정시점 스냅샷 `contribution_pct_at_calc` 유지(과거 기여율 불변).
   - 변경분은 `site_bonus_history`에 감사 기록.
   - 산식 primitive(projectBonusBase/projectProfitBase/allocateContrib)는 BonusService로 이전, `BonusController::save/saveBulk`는 이를 재사용(중복 제거).

2. **단일 훅 연결**
   - [app/core/StatusService.php:206] `recalcProjectSettlement($projectId)` 말미에 `BonusService::recalcForProject($projectId)` 호출.
   - 모든 입금 이벤트가 `recalcContractPaymentStatus → recalcProjectSettlement` 또는 직접 `recalcProjectSettlement`를 지나가므로, 이 한 곳으로 계약입금·환불·파기/철회·예외 직접입금·계약금변경 전부 커버.
   - core→core 의존(StatusService→BonusService)만 발생, 컨트롤러 의존 없음.

### 4.3 파일
- 신규 [app/core/BonusService.php], [app/controllers/BonusController.php](delegate), [app/core/StatusService.php](hook)

---

## 5. 데이터 모델 영향
- **스키마 변경 최소.** 신규 컬럼 없음이 목표.
  - `payments.pay_type` 재사용(계약금/중도금/잔금), `payer_name` 유지(미사용화).
  - `projects.expected_amount` 유지(표시 라벨만 '계약 총액').
  - `site_bonuses`/`site_bonus_history` 구조 변경 없음(값 재계산만).
- 라벨/문구 변경은 코드 상수(StatusService·AccountingService·뷰)로 처리 → 마이그레이션 불필요 예상.
- 만약 pay_type 허용값 제약(ENUM) 조정이 필요하면 `database/cafe24/012_*.sql` 신설(현재 VARCHAR이면 불필요).

---

## 6. QA 체크리스트 (사장 지시 5)
로컬에서 전 항목 통과 후에만 운영 반영.

- [ ] 프로젝트 완료 → 공정 보드 전체완료 자동 이동
- [ ] 착공 준비 드래그 → 자동 진행 시작(in_progress)
- [ ] 하자보수 컬럼 드래그 → 자동 하자보수 상태 / 진행시작·하자보수 버튼 미표시
- [ ] 예외 프로젝트 입금 등록(유형: 계약금/중도금/잔금) 정상
- [ ] 환불 등록(유형 '환불' 고정) 정상
- [ ] 전액 입금 시 자동 '전액 입금 완료' (계약총액==입금액)
- [ ] 부분 입금 시 '일부 입금' 정확 표시(버그 재발 없음)
- [ ] 환불 후 정산 상태 자동 강등 + 보너스/손실 재계산
- [ ] 보너스 자동 재계산(입금·수정·환불·철회·계약금변경 각각)
- [ ] 손실값(contrib_profit) 환불 시 음수 자동 반영
- [ ] confirmed_bonus(실지급)는 소급 변경 안 됨
- [ ] 견적 등록 UI 정상(리드 항목 그대로, 사이드이펙트 없음)
- [ ] 연관 기능 사이드이펙트 / DB 무결성 / 관리자 화면 표시
- [ ] 기존 회귀: `php scripts/tests/run.php`, `php scripts/reconcile_qa.php`, `bash scripts/qa_smoke.sh`

---

## 7. 실행 방식 (Harness)

기능별 에이전트 3개(③ 제외):
- **A) 보드·상태 동기화** — 기능 1
- **B) 입금·정산** — 기능 2
- **C) 보너스 재계산** — 기능 4

⚠️ B·C가 `StatusService`, (일부) `AccountingService`를 공유 → **완전 병렬 금지**. 공유 코어 편집은 순차 조율:
1. 공유 코어 변경(StatusService recalc 훅·라벨·자동승격, AccountingService 비교식)을 먼저 일관되게 반영.
2. 이후 A/B/C의 독립 파일(뷰·컨트롤러·BonusService)을 병렬/순차 진행.

워크플로우: 로컬 개발 → 기능별 테스트(TDD) → 통합 테스트 → 통합 QA(회귀 포함) → 운영 FTP 업로드 → 운영 동일 시나리오 재검증.

배포는 기존 절차 준수: 로컬 검증 완료 후 `./deploy/deploy.sh`(CONFIRM=yes), 마이그레이션 필요 시 `php deploy/run_migration.php`. 운영 DB 쓰기·배포는 사장 승인 후.

---

## 8. 위험/미결
- **진행시작 자동전환 불일치**: 로컬 코드엔 존재 → 운영 재현으로 실제 갭 확인 필요(2.3-2).
- **보너스 재계산 성능**: 입금 이벤트마다 프로젝트 보너스 재계산 — 프로젝트당 보너스 행 소수라 부담 적음. 대량 시나리오는 회귀 perf 테스트로 확인.
- **정산 자동화와 기존 수동 상태 충돌**: hold/refunding 수동 상태는 자동 승격/강등에서 제외(기존 규칙 준수).
- **completed→전체완료 이동과 construction_type**: full_complete는 common이라 무관. 단 완료 후 재개 시 원래 유형 공정으로 돌아가는지 확인.
