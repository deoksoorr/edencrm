# 직원 기여(성과) 규칙 (신규 · R6)

**분석 > 직원 성과** 화면의 직원별 기여 매출·기여 원가·기여 순이익·기여율·목표 달성률이 어떻게 계산되는지를 정리한 문서입니다.
계산 단일 출처는 `app/core/AccountingService.php` 이며(성과 화면은 `PerformanceController`), 대시보드 직원 성과 요약과 **동일 산식**을 공유합니다.
회계 두 축·매출 인식의 큰 틀은 [ACCOUNTING_RULES.md](ACCOUNTING_RULES.md), 화면 사용법은 [PRODUCT_MANUAL.md](PRODUCT_MANUAL.md) 참조.

---

## 1. 핵심 원칙 — 완료 프로젝트만, 기여도 비율로 귀속

- 직원 성과는 **손익 축(공급가액, VAT 제외)** 으로 집계합니다(현금·부가세는 성과에 넣지 않음).
- **확정 기여는 완료(completed)·정산 완료(settled) 프로젝트만** 합산합니다 — 진행 중 공사는 참고용 예상으로만 표시(과대 계상 방지).
- 한 프로젝트의 매출·순이익을 **배정 기여도(`project_assignments.contribution_pct`) 비율**로 나눠 배정된 직원에게 귀속시켜, 여러 직원에게 **중복 합산하지 않습니다**.
- **담당(영업, `sales_user_id`)** 과 **참여(배정 기여도)** 는 다른 축입니다 — 수주액은 담당 영업 기준, 확정 기여는 배정 기여도 기준.
- 취소(cancelled)·파기(terminated) 프로젝트는 담당 수·확정 기여·예상 기여에서 **전부 제외**합니다.

---

## 2. 기여 지표 산식 (완료+정산 프로젝트, 준공일 `actual_end_date` 기준)

`p` = 프로젝트, `pa` = 배정(`pa.contribution_pct` = 기여도 %). 대상 모집단 = `status IN ('completed','settled') AND actual_end_date IS NOT NULL`(삭제 제외).

| 지표 | 산식 | 코드(AccountingService) |
|---|---|---|
| **기여 매출(귀속 매출)** | Σ ( `p.supply_amount` × `pa.contribution_pct` / 100 ) | `employeeConfirmedRevenue($uid)` · 배치 `employeeConfirmedByUser()['revenue']` |
| **기여 순이익(확정 기여)** | Σ ( (`p.supply_amount` − `p.actual_cost`) × `pa.contribution_pct` / 100 ) | `employeeConfirmedContribution($uid)` · 배치 `employeeConfirmedByUser()['contrib']` |
| **기여 원가** | 기여 매출 − 기여 순이익 | `PerformanceController`: `$totalCost = $attrRev − $contrib` |
| **평균 순이익률(가중)** | 기여 순이익 ÷ 기여 매출 × 100 | `Calc::rate($contrib, $attrRev)` — 분모 0 → null |

- 원가는 프로젝트의 **확정 실제원가(`actual_cost`)** 를 기여도 비율로 나눈 몫입니다(개별 비용 항목을 직원에게 직접 귀속하지 않음).
- **적자 프로젝트**는 음수 순이익이 그대로 기여 순이익에 반영됩니다(부호 유지 — 과장/축소 없음).

---

## 3. 기여도 배분 방식 (`projects.contribution_mode` — main / ratio / role)

프로젝트마다 배정 기여도(`contribution_pct`)를 어떻게 정할지 3가지 방식이 있습니다(프로젝트 등록/수정 폼의 '기여도 배분방식').

| 값 | 화면 표기 | 동작 |
|---|---|---|
| `main` | **주담당 100%** | 배정 시 `contribution_pct` 를 **100 으로 강제**(입력 무시). 단일 담당 프로젝트용 |
| `ratio` | **비율 직접입력** | 배정할 때 기여도(%)를 직접 입력(미입력 시 "기여도(%)를 입력하세요" 422). 스키마 기본값 |
| `role` | **역할별 기본배분** | 최소 지원 — 현재는 `ratio` 와 동일 취급(직접입력 요구). 역할 자동 배분 확정은 **정책/후속 검토** |

- **합계 제약**: 한 프로젝트에서 취소 아닌 배정들의 `contribution_pct` 합이 **100 을 넘으면 저장 차단**(허용 오차 0.01, "기여도 합계가 100%를 초과합니다"). 합이 100 미만인 것은 허용(미배정 몫 존재 가능).
- 배정 등록·변경은 감사 로그(`assignment_create`/`assignment_update`)에 기록되고, 동일 프로젝트·직원 active 중복 배정은 `UNIQUE(uq_assign_active_pair)` 로 차단됩니다.

---

## 4. Σ 기여 = 회사 확정 순이익 (항등)

기여도 배분 규칙 덕분에 아래 항등이 **항상** 성립합니다(R6 회계 검수 T6 에서 1원까지 대사 확인):

```
한 프로젝트의 전 직원 기여 순이익 합 = 그 프로젝트의 확정 순이익(공급가액 − 실제원가 × Σ기여도/100)
회사 전체:  Σ(직원 확정 기여) = 회사 확정 순이익 = confirmedProfit()
```

- 성과 상세 화면의 **회사 기여율** = 직원 확정 기여 ÷ **회사 확정 순이익**(`companyConfirmedProfit()` = `confirmedProfit()` 별칭) × 100 (`Calc::rate`, 분모 0 → null).
- 배정 기여도 합이 100 미만인 프로젝트가 있으면 회사 전체 Σ기여는 회사 순이익보다 작을 수 있습니다(미배정 몫) — 이는 항등 위반이 아니라 **미배정분**입니다.
- 이 항등은 대시보드 직원 성과·성과 분석·리포트가 동일 `AccountingService` 메서드를 쓰기 때문에 화면 간에도 유지됩니다.

---

## 5. 목표 달성률 (미설정은 null — '목표 미설정')

성과 화면의 달성률은 **당월 실적 ÷ 당월 목표 × 100** 입니다(`targets` 테이블 연/월).

| 달성률 | 실적(당월) | 목표(당월) |
|---|---|---|
| **매출 달성률** | 당월 수주(공급가액, 담당 영업 `contract_date` 기준) `contractedAmount($mFrom,$mTo,$uid)` | `targets.target_revenue` |
| **순이익 달성률** | 당월 확정 기여(준공일 기준) `employeeConfirmedContribution($uid,$mFrom,$mTo)` | `targets.target_profit` |

- **목표 미설정 처리**: 목표가 없거나(행 없음) 목표값이 0 이하이면 달성률은 0 이 아니라 **null → 화면 '목표 미설정'** 으로 표기합니다(`AccountingService::achievement()` 가 `$target === null || $target <= 0 || $actual === null` 이면 null 반환 — ÷0 안전).
- 매출 달성률의 실적은 **수주(계약일 기준)**, 순이익 달성률의 실적은 **확정 기여(준공일 기준)** 로 **기준일이 다릅니다**(정상 — 수주와 완성은 다른 시점).
- 목표는 **관리 > 목표 관리**에서 직원·연·월별로 입력합니다.

---

## 6. 배치 조회 (N+1 제거 — 값 동일)

전 직원을 순회하는 화면(성과 분석 index·대시보드 직원 성과)은 사용자당 단건 집계 대신 **배치 메서드**를 씁니다:

- `employeeConfirmedByUser($from,$to)` → `[uid => {contrib, revenue}]` (GROUP BY pa.user_id)
- `contractedAmountByUser($from,$to)` → `[uid => 수주 공급가액]` (GROUP BY sales_user_id)

배치 메서드는 단건 메서드(`employeeConfirmedContribution`/`Revenue`·`contractedAmount`)와 **모집단·SUM 식이 동일**해 값이 **1원까지 일치**합니다(R6 리팩토링 T10 등가 단위테스트 `unit_r6_perf_bulk.php` 26/0 으로 고정). 성과 **상세**(`user()`)는 단건 경로를 그대로 유지합니다.

---

## 7. 정책/후속 검토

| 항목 | 현행 |
|---|---|
| **역할별 기본배분(`role`) 자동 배분표** | 현재 `ratio` 와 동일(직접입력). 역할별 기본 비율 자동 적용은 정책 확정 후 구현 |
| **집계 대상 직원 범위** | 작업/배정 없는 사무·영업직은 0 으로 표시 — 근태 평가에 그대로 쓰지 말 것(정책 미확정) |

*근거: `app/core/AccountingService.php`(기여 집계) · `app/controllers/PerformanceController.php`(성과 조립) · `app/controllers/AssignmentsController.php`(배분 방식) · `.superpowers/sdd/r6-acct-report.md` §4(Σ기여=순이익 항등 1원 대사) · `r6-refactor-report.md` §H-2(배치==단건 등가).*
