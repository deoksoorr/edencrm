# EDEN CRM — 회계·통계 정합성 + UI 재검수 설계 스펙

> 상태: 승인 대기(사용자 리뷰) · 작성 2026-07-22
> 확정 정책: 공급가액(VAT 제외) 기준 · 준공(완료) 매출 인식 · 완료 프로젝트만 기여액 · P1(회계 코어) 우선
> 이 문서는 "기준 정의(6절)" + "전수 계산식 목록(5절)" + "회계 집계 기준(8·9절)"의 단일 출처다.
> 코드/쿼리는 이 문서의 정의를 따른다. 화면·서비스가 지표를 다르게 계산하면 **이 문서가 옳고 코드가 틀린 것**이다.

---

## 0. 배경 — 반려된 결과물의 실제 원인

첨부 캡처의 "직원 성과" 숫자(이번달 매출 0인데 순이익 기여 3,936만)를 원천 데이터까지 역산해 재현했다.

**차윤석(현장팀장) 순이익 기여 3,936만원 분해** — `DashboardController::staffPerformance()` [app/controllers/DashboardController.php:454-460]:
- P1 대명건설: (계약액 37,462,250 − 실제원가 9,800,000) × 40% = 11,064,900
- P2 이수아 (**미착공/preparing, 실제원가 0**): (18,500,000 − **0**) × 100% = **18,500,000**
- P3 한빛 (**미착공, 실제원가 0**): (9,800,000 − **0**) × 100% = **9,800,000**
- 합계 39,364,900 ≈ 3,936만

즉 이 값의 75%가 **아직 착공도 안 한 프로젝트를 "원가 0이므로 계약액 전액이 이익"으로 계상**한 것이다.

### 확인된 구조적 오류 5종

| # | 오류 | 원인 위치 |
|---|---|---|
| 1 | 미착공/진행중 프로젝트를 확정 이익으로 계상 | 기여액 쿼리가 `status` 무관 전체 대상, `actual_cost=0`이면 계약액 전액이 이익 |
| 2 | 부가세를 매출·이익에 포함 | `contract_amount`(VAT 포함 총액)를 매출로 직접 사용. 공급가/부가세 분리 미저장 |
| 3 | "이번달 매출"과 "기여액"의 기간·귀속축 불일치 | 매출=이번달·`sales_user_id` / 기여액=전체기간·`배정`. 한 행에 이질적 기준 혼재 |
| 4 | 같은 "순이익"이 화면마다 다르게 계산 | 대시보드 기여(전체·VAT포함) ≠ 성과 총순이익(완료건만) ≠ 회사순이익(전체 contract−actual) |
| 5 | 목표/일정준수 전부 '-' | 개인 targets 미시딩·완료 프로젝트 0 → null(처리 자체는 정상, 시각적으로 전부 대시) |

---

## 1. 확정 회계 정책 & 용어 사전 (6절)

> 아래 정의는 코드 주석/문서에 그대로 반영한다. "매출"이라는 단어 하나로 계약액·공급가액·입금액을 섞지 않는다.

### 1.1 금액의 두 축 — 손익(공급가액) vs 현금(총액)

| 축 | 대상 지표 | 기준 금액 | 이유 |
|---|---|---|---|
| **손익 축** | 매출·순이익·순이익률·성과·기여액·목표달성률 | **공급가액(VAT 제외)** | 부가세는 예수금(pass-through)이며 회사 손익이 아니다 |
| **현금 축** | 입금액·미수금·수금 | **계약총액(VAT 포함)** | 고객은 부가세 포함 총액을 실제로 납부한다 |

이 분리가 이 시스템 회계의 핵심이다. 손익 축과 현금 축을 혼용하지 않는다.

### 1.2 용어 정의

| 용어 | 정의 | 산식 / 기준 컬럼 |
|---|---|---|
| **계약총액** | 계약서상 전체 금액(VAT 포함) | `contracts.contract_amount` / `projects.contract_amount` |
| **부가세** | 예수금 | `vat_amount` (신규). 견적 연결 시 `quote_version.vat`, 없으면 `contract_amount − round(contract_amount/1.1)` |
| **공급가액** | 매출 인식 기준 순액(VAT 제외) | `supply_amount` (신규) = `contract_amount − vat_amount` |
| **계약(수주)액** | 계약 체결 실적 | 공급가액 합, 기준일 `contract_date`. "이번달 수주" KPI 전용 |
| **예상 매출** | 미완료(preparing/in_progress) 프로젝트의 공급가액 | 상태 ∈ {preparing,in_progress} 의 `supply_amount` |
| **확정(인식) 매출** | 준공 완료된 프로젝트의 공급가액 | 상태 = `completed`, 기준일 `actual_end_date` 의 `supply_amount` |
| **가중 예상매출** | 영업 리드 파이프라인 기대치 | `expected_amount × win_probability/100` (리드 단계, 계약 이전) |
| **실제 원가** | 프로젝트 확정 비용 | `projects.actual_cost` = `SUM(costs WHERE type='actual')` 캐시(`CostsController::refreshActualCost`). 단일 경로로 통일(show의 live 재합산 제거) |
| **예상 원가** | 착수 전 계획 원가 | `projects.estimated_cost` (폼 권위값). `costs`(type=estimate)는 현재 미집계 → 사용 안 함 명시 |
| **예상 순이익** | 예상 매출 − 예상 총원가 | `supply_amount − estimated_cost` |
| **확정 순이익** | 확정 매출 − 실제 원가 (완료건) | `supply_amount − actual_cost`, 음수=적자 그대로 |
| **순이익률** | 순이익 ÷ 공급가액 × 100 | 분모=공급가액. 분모 ≤0 → null('산출 불가') |
| **입금액** | 실제 수금액 | `payments` 중 `status='paid'` 의 `amount` 합(VAT 포함) |
| **미수금** | 수금 대상 − 입금 + 환불·취소 조정 | `contract_amount(총액) − Σpaid`. 취소 계약 제외 |
| **직원 확정 기여액** | 완료 프로젝트 확정순이익 × 본인 기여도 | 상태=completed 만, `Σ((supply−actual_cost) × contribution_pct/100)` |
| **직원 예상 기여액** | 진행중 프로젝트 잠정순이익 × 기여도 | 별도 표기(확정과 합산 금지), 미착공(원가 미확정) 제외 |
| **회사 확정 순이익** | 완료 프로젝트 확정순이익 합 | 기여율 분모. 동일(완료·공급가) 기준 |
| **회사 순이익 기여율** | 직원 확정 기여액 ÷ 회사 확정 순이익 × 100 | 분모 ≤0 → null |

### 1.3 부가세 처리 기준 (명문화)

- 매출·순이익·순이익률·직원 성과·목표달성률은 **공급가액(VAT 제외)** 로 계산한다.
- 입금·미수금·수금은 고객이 실제 납부하는 **계약총액(VAT 포함)** 으로 계산한다.
- `supply_amount + vat_amount = contract_amount` 를 항상 만족(반올림 오차는 vat에 흡수).
- 견적이 연결된 계약은 견적의 `vat`를 그대로 사용해 정합을 보장한다(예: 계약1 총액 37,462,250 = 공급 34,000,000 + VAT 3,462,250 + 할인반영).

---

## 2. 기간·기준일 정책 (8절)

같은 데이터라도 지표마다 기간 포함 기준일이 다르다. 화면·필터가 바뀌어도 아래 기준일을 고정한다.

| 지표 | 기준일 | 컬럼 |
|---|---|---|
| 신규 문의 | 등록일 | `leads.created_at` / `customers.created_at` |
| 계약(수주) 실적 | 계약 확정일 | `contracts.contract_date` / `projects.contract_date` |
| 예상 매출(가중) | 현재 파이프라인 스냅샷 | 기간필터 없음(현재 상태) |
| **확정 매출** | **실제 준공일** | `projects.actual_end_date` (status=completed) |
| 입금액 | 실제 입금일 | `payments.paid_date` (status=paid) |
| 실제 원가(추이) | 프로젝트 준공 월 | `projects.actual_end_date` (확정매출과 동일 축) |
| 프로젝트 완료 | 실제 준공일 | `projects.actual_end_date` |
| 직원 계약 성과 | 계약 담당(sales_user_id) 기준 | `projects.contract_date` |
| 직원 기여 성과 | 프로젝트 준공 기준 | `projects.actual_end_date` (배정 기여도) |

- 월/분기/연/사용자지정 필터는 위 기준일 컬럼에 동일하게 적용한다.
- 월별 추이 차트(매출·순이익)는 **확정 매출 축(actual_end_date)** 으로 통일한다(현재는 contract_date라 인식시점 불일치).

---

## 3. 상태값 집계 대상 (9절)

| 상태 | 매출 | 순이익 | 예상매출 | 프로젝트 수 | 비고 |
|---|---|---|---|---|---|
| 리드: 신규~견적~협상 | ✕ | ✕ | 가중치 반영 | ✕ | leads, 계약 이전 |
| 리드: 보류/미응답/실주/취소 | ✕ | ✕ | ✕(제외) | ✕ | is_lost/보류 제외 |
| 계약 완료(contract_won) | ✕(수주액만) | ✕ | ✕ | ✕ | 리드는 수주 카운트만 |
| 프로젝트 preparing | ✕ | ✕ | 공급가액 | 진행 카운트 | 착공 전 |
| 프로젝트 in_progress | ✕ | 예상(잠정) | 공급가액 | 진행 카운트 | 미인식 |
| 프로젝트 completed | **확정 공급가액** | **확정** | ✕ | 완료 카운트 | 인식 시점 |
| 프로젝트 paused | ✕ | ✕ | 공급가액(주의표시) | 중단 | |
| 프로젝트 cancelled | ✕(전면 제외) | ✕ | ✕ | ✕ | 매출·성과·수 모두 제외 |
| soft-deleted(deleted_at) | ✕ | ✕ | ✕ | ✕ | 전 쿼리 `deleted_at IS NULL` 강제 |

**중복 집계 금지 규칙:**
- 계약 1건과 그로부터 생성된 프로젝트 1건을 매출로 이중 계상하지 않는다(매출 원천 = 프로젝트, 계약은 수주/미수금 원천).
- 견적 버전 여러 개를 각각 매출로 합산하지 않는다(current_version만).
- 프로젝트 참여 직원 N명에게 전체 매출/순이익을 N배 반복 합산하지 않는다(기여도 × 1회).
- 계약에 payments N건·costs M건이 붙어도 `SUM(contract_amount)`가 N×M배 되지 않도록 JOIN 분리 또는 상관 서브쿼리 사용.

---

## 4. 전수 계산식 목록 (5절)

전 모듈 코드 전수 조사 결과. 중앙 `Calc`가 있으나 **다수 호출부가 원시 SQL로 우회**하며, 같은 지표가 화면마다 다른 산식·기준으로 계산되고 있다.

### 4.1 교차 모듈 충돌 — 통합 대상 (핵심 결함 A~I)

> 이것이 "숫자가 화면마다 다르게 보이는" 근본 원인이다. `AccountingService`로 각 지표를 **단일 정의**로 통합한다.

- **A. `contract_amount`(VAT 포함)를 매출로 직접 사용 — 전면**: Reports L129,224,248,269,304,370 · Dashboard L180,440,456,520,569,599 · Performance L92,119,141,161,164,180 · Contracts L60,110,339,434 · Projects L143. → 전부 **공급가액(supply_amount)** 로 교체(손익 축).
- **B. 미수금 5정의 난립**: ① Reports L313 건별 floor(≥0) 합 ② Dashboard L599 전역 net(과입금 상쇄) ③ Contracts L60/110 건별 음수 허용 ④ Notifications `genPaymentOverdue` 연체 예정 payments ⑤ Dashboard L606 건수. → **단일 정의**: 미수금 = Σ(취소 아닌 계약별 max(0, 총액 − 입금)), 과입금은 건별 0 floor, 취소 계약 제외.
- **C. 전환율 4정의**: ① Dashboard L614/Performance L219 전기간 won/total ② Reports L190 기간(created_at) won/total ③ Reports L207 견적→계약 비율. → **정의 분리·명명**: `계약전환율`(리드 WON÷전체, 기간=created_at) 과 `견적전환율`(견적→계약)을 별개 지표로 명확히 하고 화면 라벨을 다르게.
- **D. "순이익" 원가 기준 불일치**: Dashboard bossKpi L137 = **estimated_cost** / 그 외(monthlyTrend L529, Reports, Performance 완료분) = **actual_cost**. 같은 "순이익" 라벨, 다른 입력. → **예상 순이익=estimated_cost, 확정 순이익=actual_cost** 로 라벨·산식 분리, KPI는 확정(준공·actual) 기준.
- **E. 직원 매출 vs 순이익 귀속축 불일치**: 매출은 `sales_user_id` 100% 귀속(Dashboard L440, Reports L269), 순이익은 `contribution_pct` 분할(Dashboard L456, Performance L102). → 7절 "담당 vs 참여" 분리 표기로 해소(같은 행에서 다른 축 혼용 금지).
- **F. 평균 순이익률 방법론 불일치**: Performance L186 = 프로젝트별 비율의 산술평균 / Reports L258 = 합산 비율(pooled). → **가중(pooled) 평균으로 통일**(7.3절).
- **G. 원가 원천 이중화**: `actual_cost`는 `SUM(costs type='actual')` 캐시(CostsController::refreshActualCost L13-21)인데 ProjectsController::show L145에서 **live 재합산**(2경로). `costs type='estimate'`는 **어디서도 집계 안 됨**(dead), `estimated_cost`는 수기 폼값(계약전환 시 0). → **actual_cost = costs(actual) 캐시 단일 경로**, show의 재계산 제거. estimated_cost는 프로젝트 폼값을 권위로 유지(costs estimate는 미사용 명시).
- **H. 작업일지 분모 불일치**: Performance L255 영업일(일·공휴일 제외) / Dashboard attendance L125 최다근무자 대비 비율 / 미작성 판정은 오늘(Dashboard) vs 전일(Notifications). → 작성률 분모=영업일로 통일(기능 ON일 때만), 미작성 판정일 통일.
- **I. 증감 델타 인라인 계산**: Dashboard L679가 `Calc` 미사용. → `Calc::rate` 계열로 통일.

### 4.2 모듈별 현행 산식 인벤토리 (요약)

| 모듈 | 지표 | 위치 | 현행 산식 | 원천/기준일 | 교정 |
|---|---|---|---|---|---|
| Reports | 월별 매출/원가/순이익 | L128-147 | Σcontract_amount / Σactual_cost / Calc::profit | projects, contract_date | 공급가액·준공일 축 |
| Reports | 프로젝트별 손익 | L223-239 | Calc::profit/Rate(contract_amount, actual_cost) | projects, contract_date | 공급가액 |
| Reports | 공사유형별 평균수익률 | L246-259 | pooled SUM/SUM | projects | 가중(유지) |
| Reports | 목표대비 달성률 | L347-382 | company_targets vs Σcontract_amount | contract_date | 공급가액, null전파 |
| Reports | 원가초과율 | L328-343 | (actual−estimated)/estimated | projects | 유지(예상 축) |
| Dashboard | 이번달 확정매출 | L566-574 | Σcontract_amount, contract_date월 | projects ⚠VAT | 준공(actual_end_date)·공급가 |
| Dashboard | 예상순이익 KPI | L136-138 | profit(rev, estimated_cost) | projects | 예상=공급−estimated |
| Dashboard | 직원 순이익 기여 | L455-460 | Σ(contract−actual)×pct, 전기간·전상태 | assignments ⚠VAT | 완료건·공급가만 |
| Dashboard | 일정 준수율 | L462-486 | ontime÷done(완료) | projects | 정의 유지 |
| Dashboard | 미수금 총액 | L596-604 | 전역 net | contracts ⚠VAT | 건별 floor·취소제외 |
| Dashboard | 영업 전환율 | L614-621 | won/total, 전기간 | leads | 정의 확정(4.1C) |
| Performance | 총매출/원가/순이익(완료) | L164-210 | status=completed Σ | projects ⚠VAT | 공급가액 |
| Performance | 평균순이익률 | L166-186 | 프로젝트별 비율 평균 | projects | 가중으로 통일 |
| Performance | 계약전환율 | L196-219 | won/total 전기간 | leads | 4.1C |
| Performance | 작업일지 작성률 | L224-256 | 작성일÷영업일 | work_logs, holidays | 기능 ON만 |
| Pipeline | 리드 예상순이익/률 | L106-107 | profit/Rate(expected_amount, expected_cost) | leads | 유지(리드 축) |
| Pipeline | 가중 예상매출 | L108 | weightedRevenue | leads | 유지 |
| Quotes | 소계/VAT/총액 | L373-379 | Σamount, ×vat_rate, subtotal+vat−discount | quote_items | 유지(견적 원천) |
| Contracts | 미수금(목록/상세) | L60,104-110 | contract−Σpaid(음수허용) | contracts ⚠VAT | 4.1B |
| Costs/Projects | actual_cost 캐시 | Costs L13-21 | SUM(costs actual) | costs | 단일경로(4.1G) |
| Process | 공정 진행률 | L110-111 | sort_order/MAX×100 | process_stages | 유지 |
| Assignments | 기여도 합계 검증 | L89-141 | Σpct ≤100.01 | assignments | 유지·강화 |

### 4.3 통합 목표 (지표 → 단일 정의)

| 지표 | 통합 정의(본 문서 1·2절) | 현행 위치(교체 대상) |
|---|---|---|
| 이번달 확정매출 | 완료 프로젝트 공급가액(actual_end_date) | Dashboard monthRevenue (contract_date·VAT포함) |
| 이번달 수주액(신규) | 계약 공급가액(contract_date) | — (신설) |
| 예상 순이익 | 공급가액 − estimated_cost | finance()/bossKpi (혼용) |
| 확정 순이익률 | (공급가액−actual_cost)/공급가액 | Calc::profitRate(계약액 기반 호출) |
| 미수금 | Σ 건별 max(0, 총액 − 입금), 취소 제외 | 5정의 통합(4.1B) |
| 직원 확정 기여 | 완료건 (공급−actual)×pct | staffPerformance/Performance (전체·VAT포함) |
| 계약 전환율 | WON ÷ 전체 리드(created_at 기간) | conversionRate/Performance(중복) |
| 견적 전환율 | 계약연결 견적 ÷ 전체 견적 | Reports quoteConversion |
| 평균 순이익률 | 가중(Σ순이익/Σ공급가액) | Performance(산술평균) |
| 일정 준수율 | 기한내 완료 ÷ 완료 | staffPerformance/Performance(중복) |
| 작업일지 작성률 | 작성일수 ÷ 영업일수 (기능 ON만) | worklogRate() |
| 증감 델타 | Calc 계열 | Dashboard 인라인(L679) |

---

## 5. 아키텍처 — 단일 회계 서비스로 통합

### 5.1 `AccountingService` (신규 `app/core/AccountingService.php`)

모든 금액·매출·원가·순이익·기여액·달성률의 **단일 출처**. 대시보드 4종·성과·리포트·프로젝트 상세가 전부 이 서비스만 호출한다. 산식별 메서드로 책임을 분리(한 함수에 전부 밀어넣지 않음 — 테스트 가능성 유지).

핵심 메서드(초안):
- `projectSupply(array $project): int` — 공급가액(supply_amount, 없으면 파생)
- `projectVat / projectContract` — 부가세 / 총액
- `confirmedRevenue($from,$to,$scope)` — 완료(준공일 기준) 공급가액 합
- `expectedRevenue($scope)` — 미완료 공급가액 합
- `contractedAmount($from,$to,$scope)` — 수주액(계약일 기준)
- `actualProfit / expectedProfit / profitRate`
- `receivable($scope)` — 총액 − 입금(현금 축)
- `employeeContribution($uid, 'confirmed'|'expected')` — 완료건 기여액 / 진행중 잠정
- `companyConfirmedProfit($from,$to)` — 기여율 분모
- `goalAchievement($actual,$target)` — null 전파('목표 미설정')
- 각 지표는 **근거 조회용** 상세(구성 프로젝트 목록·건수) 반환 변형 제공 → 드릴다운.

**예외 처리 규약(7절):** 분모 0/음수 → null(0% 임의표시 금지) · 목표 없음 → null('목표 미설정') · 매출 없음 → null('산출 불가') · actual_cost 미입력 완료건 → '원가 미확정' 플래그 · 음수 순이익 → 적자 표시 · 취소/삭제 → 집계 제외.

### 5.2 `Settings` (신규 `app/core/Settings.php`)

`settings` 테이블 기반 기능 플래그·환경값 캐시 조회. `Settings::get($key,$default)`, `Settings::enabled($key)`. 요청당 1회 로드 후 정적 캐시. 변경은 기존 `SettingsController::save()` → `Audit::log('settings_update')` 로 이미 감사됨(변경자/전후값/시각).

### 5.3 스키마 변경 (마이그레이션)

- `contracts`: `supply_amount DECIMAL(14,0)`, `vat_amount DECIMAL(14,0)` 추가.
- `projects`: `supply_amount`, `vat_amount` 추가.
- 백필: 견적 연결 계약은 `vat_amount = quote_version.vat`, `supply = contract_amount − vat`; 미연결은 `vat = contract_amount − round(contract_amount/1.1)`. 프로젝트는 연결 계약값 승계, 미연결은 파생.
- `settings`: `feature_worklog`(group='운영 기능', 기본 `'0'`) 시드(seed_core).
- `schema.sql`·`seed_core.sql` 갱신 + 기존 DB용 `ALTER` 마이그레이션 스크립트(`database/migrations/`).

---

## 6. 작업일지 기능 플래그 (3·4절)

### 6.1 기본 비활성(OFF) 시 적용 지점 — 전수 touchpoint 맵

**중요 발견**: 현재 라우터([public/index.php:66-69])는 `perm`만 강제하고 기능 플래그 훅이 없다. 게다가 `worklogs.index`·`worklogs.show`는 **perm이 없어**(로그인만 하면 접근) 권한만으로는 차단 불가. → **라우터에 기능 플래그 가드를 추가**(라우트 메타에 `feature` 키를 두고, 해당 기능 OFF면 "비활성화된 기능" 안내로 전환)한다. 플래그는 아직 코드에 존재하지 않음(신규).

OFF 시 가드 대상 16지점(각각 `Settings::enabled('feature_worklog')` 또는 라우트 `feature` 메타로 처리):

| # | 위치 | file:line | 유형 | OFF 동작 |
|---|---|---|---|---|
| 1 | 라우트 6종 | routes.php:89-94 | API+페이지 | 6개 전부 라우터 가드로 차단(안내 페이지) |
| 2 | 사이드 메뉴 | Nav.php:25 (`worklogs.index`,'작업일지') | 메뉴 | 숨김 |
| 3 | 사장 출근현황 섹션+링크 | boss.php:73-88(링크76) / DashboardController.php:108-129 | KPI집계+링크 | 섹션·집계 미실행 |
| 4 | 사장 주의항목 | boss.php:149 / DashboardController.php:302 | 주의 | key `worklog` 제외 |
| 5 | 현장 KPI 카드 | site.php:26 / siteKpi:233 | KPI | 카드·집계 제외 |
| 6 | 현장 주의항목 | site.php:3 `$pick`+:57 | 주의 | `$pick`에서 worklog 제거 |
| 7 | 직원 "작업일지 작성" 버튼 | staff.php:14 | 버튼 | 숨김 |
| 8 | 직원 KPI 카드 | staff.php:24 / staffKpi:261 | KPI | 카드·집계 제외 |
| 9 | 성과 작성률 지표 | PerformanceController.php:220,225-256 | KPI집계 | 지표 skip |
| 10 | 성과 표 컬럼 | performance/index.php:26,50 | 렌더 | 컬럼 숨김 |
| 11 | 성과 상세 카드 | performance/user.php:48-49 | 렌더 | 카드 숨김 |
| 12 | 알림 생성기 | NotificationsController.php:90,246-268 (type `worklog_missing`) | 알림 | `genWorklogMissing()` skip |
| 13 | 확인 알림 push | WorklogsController.php:239-246 (type `worklog`) | 알림 | 라우트 차단으로 자연 비활성 |
| 14 | 알림 라벨 맵 | notifications/index.php:10 | 렌더 | 무해(행 없음) |
| 15 | 프로젝트 상세 작업일지 카드+버튼 | projects/show.php:207-232 / 쿼리 ProjectsController.php:187-191 | 버튼+목록 | 카드 숨김, 쿼리 skip |
| 16 | 대시보드 출근/미작성 집계 | DashboardController.php:108-129,645-652 | 백그라운드 쿼리 | 미실행 |

- 정적 JS에는 작업일지 참조 없음(확인 스크립트는 컨트롤러 인라인, 범용 `api()` 사용). → JS 변경 최소.
- 관리자 위치: **시스템 설정 > 운영 기능** 토글(사용/사용 안 함), 감사로그 기록. 기존 데이터 보존, ON 복원 시 정상.

### 6.2 향후 운영 활성화 조건 분석 (4절, 기획 에이전트 보고)

작업일지를 실제 운영에 쓰려면 필요한 미비점(현재 활성화하지 않는 근거): 모바일 작성 편의·현장 사진 업로드·오프라인/임시저장·관리자 승인 플로우·수정 이력·근무시간 vs 작업시간 구분·다중 작업자 공동참여·표준공정 선택·자재 사용량·위치/개인정보 고지·작성 강제 정책·성과반영 신뢰성. → 본 작업에서는 억지 활성화하지 않고 플래그로 봉인.

---

## 7. 직원 성과 재설계 (10·11절)

### 7.1 담당 vs 참여 분리

- **담당(영업)**: `sales_user_id` — 수주액·계약목표달성률의 귀속 주체.
- **담당(현장)**: `site_manager_id` — 공정·일정준수 귀속.
- **참여(배정)**: `project_assignments` + `contribution_pct` — 순이익 기여액 귀속.
- 한 프로젝트에 여러 직원이 배정돼도 계약금액·순이익 전체를 각자에게 반복 합산하지 않는다.

### 7.2 표시 컬럼(대시보드/성과 통일)

담당 프로젝트수 · 참여 프로젝트수 · 완료 · 진행 · 지연 · 담당 계약액(공급) · 확정 매출 · 실제원가 · 확정 순이익 · **확정 기여액** · 예상 기여액(별도) · 평균 순이익률(가중) · 목표매출/달성률 · 목표순이익/달성률 · 회사 순이익 기여율 · 일정 준수율 · (작업일지 작성률 — 기능 ON일 때만).

### 7.3 평균 순이익률 — 가중 평균 채택

회사 성과 비교에는 단순 평균이 아니라 **가중 평균**을 사용한다:

`가중 평균 순이익률 = Σ(직원 귀속 확정순이익) ÷ Σ(직원 귀속 확정 공급가액) × 100`

단순 평균과 혼용 금지. 성과 카드/표에는 산정 기준을 툴팁으로 명시. 각 직원 수치 클릭 → 구성 프로젝트·금액 근거(드릴다운).

### 7.4 기여도 무결성 (11절)

- 프로젝트별 기여도 합계 100% 검증(확정 시 100% 아니면 경고/차단).
- 퇴사·비활성 직원의 기존 기여도 보존.
- 확정되지 않은(진행중) 기여도는 확정 성과에 합산하지 않고 '예상'으로만.
- 기여도 수정 이력 저장, 변경 시 대시보드·리포트 즉시 재계산.
- 순손실도 기여 비율대로 정상 배분(적자 배분).

> 현재 seed는 완료 프로젝트가 0건이라 **확정 기여액=0(정상)**. 실현 데이터 시연을 위해 P5 시드에 완료 프로젝트(실제원가 포함) ≥1건을 추가한다.

---

## 8. UI / 상태 표시 규칙 (16절)

계산 결과 상태를 텍스트로 구분한다(모든 비정상을 0으로 표시 금지):

| 상태 | 표시 |
|---|---|
| 정상 계산 | 값(축약 + 정확값 툴팁) |
| 목표 미설정 | "목표 미설정"(회색) |
| 원가 미확정 | "원가 미확정" |
| 입금 미완료 | 미수금 강조 |
| 데이터 부족 / 산출 불가 | "산출 불가" |
| 적자 | 음수 강조(빨강) |
| 기능 비활성화 | "비활성"(작업일지 등) |

- 0원/미입력/산출불가를 서로 구분.
- 직원 성과에서 값 없는 직원을 거대한 0 막대로 표시하지 않고 상태 텍스트로 안내.
- 단위 일관(원/만/억), 정확값은 상세·툴팁.
- 첨부 화면(견적·계약 목록, 직원 성과)은 브라우저에서 콘솔·PHP 오류·렌더 오류를 **실측**한 뒤 원인 교정(CSS 임시 덮어쓰기 금지). PC/태블릿/모바일 반응형, 최대폭 제어.

---

## 9. 권한 & 민감정보 (15절)

- 사장/회계: 전체 손익·직원 성과. 영업관리자: 허용된 영업 매출·목표. 현장관리자: 허용 원가 범위. 일반직원: 본인 성과만.
- URL/API 파라미터 변조로 타 직원 매출·순이익·목표 조회 차단(`Scope` 쿼리레벨, IDOR 방지) — 회귀 테스트 포함.
- 작업일지 기능 OFF 시 직접 API 호출도 차단(라우터 가드).

---

## 10. 대사 테스트 A~G (14절)

수동 계산 = 시스템 결과를 표로 대조(예상/실제/통과). 모두 공급가액(VAT 제외) 손익 기준과 일치.

| 케이스 | 입력 | 기대값 |
|---|---|---|
| A 정상이익 | 공급가 100,000,000·VAT 10,000,000·실제원가 70,000,000·완료 | 확정 순이익 30,000,000 · 순이익률 30% |
| B 적자 | 인정매출(공급) 50,000,000·실제원가 60,000,000·완료 | 순이익 −10,000,000 · 순이익률 −20% |
| C 부분입금 | 계약총액 100,000,000·입금 40,000,000 | 미수금 60,000,000(현금 축, VAT 포함) |
| D 2인 기여 | 프로젝트 확정순이익 20,000,000·A70%·B30% | A 14,000,000 · B 6,000,000 · 합 20,000,000 |
| E 목표 미설정 | 목표 NULL | 달성률 = "목표 미설정"(0% 아님) |
| F 계약 취소 | 완료 후 취소 | 매출·성과·수에서 제외, 이력 보존 |
| G 중복 JOIN | 계약1·입금3·비용5·직원2 | 계약액 30회 중복 합산 안 됨(정확 1회) |

각 케이스용 검증 데이터/스크립트를 별도 생성(운영 시드와 분리).

---

## 11. 단계 계획 P1~P6 & 완료 조건 (17절)

- **P1 · 회계 코어** (첫 체크포인트): 정책 상수화 + `AccountingService` + `Settings` + 스키마/백필 마이그레이션 + 대사 테스트 A~G 스크립트. **여기서 사용자 검수.**
- **P2 · 집계 통합**: 대시보드 4종·성과·리포트·프로젝트가 서비스만 사용, 근거 드릴다운, 권한별 노출. 화면 합계 = DB 원천 일치.
- **P3 · 작업일지 플래그**: 기본 OFF, 전 touchpoint 가드, 데이터 보존, 감사로그, OFF/ON 회귀.
- **P4 · UI/레이아웃**: 첨부 화면 브라우저 실측 → 렌더/콘솔/PHP 오류 원인 교정, 상태 구분, 반응형, before/after 캡처.
- **P5 · 시드 + QA 대사**: 시드 현실화(완료 프로젝트 포함), A~G 수동 대사, 권한/기간/상태 회귀.
- **P6 · 리팩토링/최적화**: 중복 산식·SQL 제거, N+1 제거, 인덱스, 미사용 작업일지 로딩 제거, 문서화, 로컬 서버 실행 후 종합 보고.

**완료 판정(17절 전 조건)**: 레이아웃·콘솔·PHP·API 오류 0 · 작업일지 기본 OFF 및 토글 · OFF 시 메뉴/API/알림/KPI 제외 · 데이터 보존 · 계산식 목록화·기준 정의 완료 · 대시보드=리포트 통합 · 직원 중복집계 제거 · 기여도 합계 검증 · 목표 미설정 표시 · 적자/환불/취소/부분입금 처리 · A~G 대사 통과 · 화면=상세 합계 일치 · 권한별 노출 정상 · PC/태블릿/모바일 정상 · before/after 검수.

---

## 12. 리팩토링·최적화 원칙 (18절)

중복 계산식·SQL 제거, 공통 회계 서비스 통합, N+1/중복 렌더 제거, 조건별 인덱스, 작업일지 OFF 시 불필요 로딩 방지, 미사용 CSS/JS/위젯 제거, 콘솔·디버그·하드코딩 제거, 계산식 명칭·변수명 통일, README/지표 사전 문서화. **단, 의미 불명 공통함수 하나에 전부 밀어넣지 않고 산식별 책임·테스트 가능성 유지.**
