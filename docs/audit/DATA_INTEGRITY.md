# DB · 데이터 무결성 감사 보고서 (T4)

- **대상**: 운영 DB `<DB_ACCOUNT>` (MariaDB 10.6.17-log), prefix `edencrm_`, 46개 테이블
- **감사 일시**: 2026-07-29 09:00 KST
- **방식**: 운영 DB **읽기 전용**(SELECT/SHOW/EXPLAIN 만). 쓰기 구문 0건. 로컬 dev DB 미변경.
- **도구**: `scripts/audit/prod_query.php` (SELECT/SHOW/EXPLAIN/WITH 만 허용하는 가드 내장 + `SET SESSION TRANSACTION READ ONLY`)
- **쿼리 파일**: `scripts/audit/q_01_orphans.sql` · `q_02_softdelete.sql` · `q_03_dup_amount.sql` · `q_04_status_date.sql` · `q_05_agg_explain.sql` · `q_06_explain.sql` · `q_07_null_idx.sql`

## 데이터 규모 (감사 시점)

| 테이블 | 전체 | 삭제(휴지통) | 살아있음 |
|---|---:|---:|---:|
| customers | 6 | 2 | 4 |
| leads | 2 | 0 | 2 |
| quotes | 4 | 2 | 2 |
| contracts | 5 | 1 | 4 |
| projects | 11 | 7 | 4 |
| payments | 13 | (소프트삭제 없음) | 13 |
| costs | 4 | (소프트삭제 없음) | 4 |
| site_bonuses | 5 | 5 | 0 |
| users | 7 | 0 | 7 |
| employee_permissions | 36 | – | 36 |
| audit_logs | 392 | – | 392 |

---

# 1. Orphan / FK 정합성 — ✅ 정상 (0건)

운영 DB에는 **84개 FK 제약이 실제로 활성**(`information_schema.KEY_COLUMN_USAGE` 확인)이며, FK가 없는 컬럼까지 포함해 LEFT JOIN 으로 전수 검사했다.

| 검사 | 결과 |
|---|---|
| quotes→customers / quotes→leads | 0건 |
| quotes.current_version_id→quote_versions (FK 없음) | 0건 / NULL 0건 / 교차참조 0건 |
| quote_versions→quotes, quote_items→quote_versions | 0건 |
| contracts→customers / →quotes | 0건 |
| contracts.quote_version_id / .converted_by / .contract_file_id (FK 없음) | 0건 |
| projects→contracts / →customers / →process_stages | 0건 |
| payments→contracts / →projects, 양쪽 NULL 고아 입금 | 0건 |
| costs→projects | 0건 |
| project_assignments→projects/users | 0건 |
| schedules→projects/users, schedule_participants, schedule_time_slots | 0건 |
| employee_permissions→users | 0건 |
| site_bonuses→projects/users | 0건 |
| contract_status_history / project_status_history / project_process_history / site_bonus_history / goal_history / project_stage_progress → 부모 | 0건 |
| work_logs, work_log_photos, warranty_repairs, customer_contacts, customer_activities, contract_terminations, project_memos, notifications, audit_logs, attendance_marks, leads | 0건 |

**폴리모픽 참조**(FK 불가): `project_files.entity_type/entity_id` 2건 모두 `customer_license` → customers 1, 5 로 정상. `audit_logs.entity_id` 는 참조 무결성 검증 불가(설계상 허용).

---

# 2. 소프트 삭제 정합성 — ⚠️ 문제 3건

## 2-A. 【심각】 삭제된 부모를 참조하는 입금·원가 때문에 확정매출·원가가 전부 0

**검사 쿼리** (`q_05_agg_explain.sql` @A-1 — `AccountingService::confirmedRevenue()` 산식 그대로 재현)

```sql
SELECT
  ROUND(SUM(CASE WHEN ((pm.contract_id IS NOT NULL AND c.deleted_at IS NULL)
                    OR (pm.project_id  IS NOT NULL AND pj.deleted_at IS NULL))
    THEN (CASE WHEN pm.contract_id IS NOT NULL AND c.contract_amount>0 AND c.supply_amount IS NOT NULL
               THEN (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)*c.supply_amount/c.contract_amount
               ELSE (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)*0.9090909090909091 END)
    ELSE 0 END)) AS revenue_with_delete_filter, ...
FROM edencrm_payments pm
LEFT JOIN edencrm_contracts c  ON c.id  = pm.contract_id
LEFT JOIN edencrm_projects  pj ON pj.id = pm.project_id
WHERE pm.status='paid'
```

**실제 결과**

| 지표 | 삭제 필터 적용(현재 화면 값) | 삭제 필터 미적용 |
|---|---:|---:|
| 확정매출(공급가) | **0원** | 49,772,727원 |
| 원가 총액(costs) | **0원** | 20,600,000원 |

`status='paid'` 인 입금 **7건 전부**가 소프트삭제된 계약/프로젝트에 매달려 있어 집계에서 통째로 빠진다.

| 입금 id | 부모 | 종류 | 금액 | paid_date | 부모 삭제일 |
|---:|---|---|---:|---|---|
| 1 | contract 1 (C-20260723-001) | payment | 275,000 | 2026-07-20 | 2026-07-29 06:55:21 |
| 2 | contract 1 (C-20260723-001) | payment | 2,475,000 | 2026-07-23 | 2026-07-29 06:55:21 |
| 10 | project 10 (P2026-0007) | payment | 30,000,000 | 2026-07-27 | 2026-07-28 09:32:29 |
| 14 | project 12 (P2026-0008) | payment | 5,000,000 | 2026-07-28 | 2026-07-28 09:41:30 |
| 15 | project 12 (P2026-0008) | refund | −3,000,000 | 2026-07-28 | 2026-07-28 09:41:30 |
| 16 | project 13 (P2026-0009) | payment | 30,000,000 | 2026-07-28 | 2026-07-28 15:26:30 |
| 17 | project 13 (P2026-0009) | refund | −10,000,000 | 2026-07-28 | 2026-07-28 15:26:30 |

원가 4건(id 1,2,3,5 / 300,000 + 300,000 + 10,000,000 + 10,000,000 = 20,600,000)도 전부 삭제된 프로젝트(1, 7, 10) 소속.

**근본 원인** — `ContractsController::deleteBlockReason()` (`app/controllers/ContractsController.php:905-909`) 은 **살아있는 프로젝트만** 차단하고 **입금 참조는 검사하지 않는다.**

```php
public static function deleteBlockReason(int $contractId): ?string
{
    $pid = Db::val("SELECT id FROM projects WHERE contract_id = :c AND deleted_at IS NULL LIMIT 1", [':c' => $contractId]);
    return $pid !== null ? '프로젝트로 전환된 계약입니다. ...' : null;
}
```

`audit_logs` id=427 에 `contract_delete / contracts / 1 / 2026-07-29 06:55:21 / user 1(admin)` 기록 확인 — 입금 2건(2,750,000원)이 달린 계약이 **아무 경고 없이** 휴지통으로 이동했고, 그 즉시 확정매출에서 2,500,000원(공급가)이 사라졌다.

- **심각도**: 높음 (경영 지표 오표시)
- **영향**: 대시보드 KPI · 리포트 월별 추이 · 반기 실적 · 목표 달성률 · 보너스 산정(BonusService) 전부
- **자동 보정**: 불가 — 삭제가 의도였는지 실수였는지 사람 판단 필요
- **권장 조치**: (a) 사장/관리자에게 "이 4건이 정말 삭제 대상인가" 확인 → 아니면 휴지통에서 복원, (b) 코드 측: `deleteBlockReason` 에 `payments WHERE contract_id=? AND status='paid'` 참조 검사를 추가하거나, 최소한 "확정매출 N원이 집계에서 제외됩니다" 경고를 노출. `purgeBlockReason`(`:929-941`)은 이미 입금을 차단하고 있어 완전삭제는 막혀 있다(복원 가능).

## 2-B. 【중간】 삭제된 고객을 참조하는 살아있는 리드 1건 · 견적 1건

| 자식 | id | 참조 고객 | 고객 삭제일 |
|---|---|---|---|
| leads | 1 | customer 1 (고객1) | 2026-07-24 10:58:44 |
| quotes | 1 (Q20260723-001, status=`accepted`) | customer 1 (고객1) | 2026-07-24 10:58:44 |

**근본 원인** — `CustomersController::delete()` (`app/controllers/CustomersController.php:472`) 에 자식 참조 가드가 **전혀 없다.** 살아있는 리드/견적/계약/프로젝트 유무를 확인하지 않고 바로 `deleted_at` 을 찍는다.

**표시 영향** — 견적 목록·파이프라인 목록은 `JOIN customers c ON c.id = q.customer_id` 를 쓰되 `c.deleted_at` 을 필터하지 않는다(`QuotesController.php:71,78,90,127,353`, `PipelineController.php:144,221,252`). 따라서 화면에서 사라지지는 않고 **삭제된 고객의 이름이 그대로 살아있는 목록에 렌더링된다.**

- **심각도**: 중간 (데이터 손실은 없으나 휴지통 개념이 새는 상태)
- **자동 보정**: 부분 가능 — 고객 1을 복원하면 즉시 정합. 다만 "고객1"은 이름상 테스트 데이터로 보임
- **권장 조치**: 사장 판단 후 고객 1 복원 또는 리드 1 / 견적 1 동반 삭제. 코드 측은 고객 삭제 시 자식 참조 가드 추가 (계약/견적의 `deleteBlockReason` 패턴 준용)

## 2-C. 【낮음】 삭제된 프로젝트를 참조하는 자식 레코드 21건

| 자식 테이블 | 건수 | id 목록 (참조 프로젝트) |
|---|---:|---|
| payments | 6 | 10(→10), 14·15(→12), 16·17(→13), 19(→15) |
| costs | 4 | 1·2(→1), 3(→7), 5(→10) |
| project_assignments | 11 | 1,2,3(→1), 9,10(→5), 12,14(→7), 17,18(→10), 21(→12), 22(→13) |
| schedules / work_logs / project_memos / site_bonuses(살아있는) | 0 | – |

집계 코드(`AccountingService`)는 전부 `deleted_at IS NULL` 로 걸러내므로 **금액 왜곡은 없다.** 다만 프로젝트를 복원하면 이 자식들이 한꺼번에 되살아난다는 점만 인지 필요.

**정상 확인**: 삭제된 리드→살아있는 견적 0건 / 삭제된 견적→살아있는 계약 0건 / 삭제된 계약→살아있는 프로젝트 0건 / 삭제된 고객→살아있는 계약·프로젝트 0건.

---

# 3. 담당자(직원) 참조 — ✅ 정상

- **삭제·비활성 직원을 담당자로 가진 데이터: 0건.** users 7명 전원 `status='active'`, `deleted_at IS NULL`. `sales_user_id` / `site_manager_id` / `created_by` / `updated_by` / `worker_id` / `user_id` 전 컬럼 검사.
- `users.role_key`(비정규화 캐시) vs `roles.role_key` 불일치: **0건**.

## ⚠️ 담당자 NULL 실태 (살아있는 행 기준)

| 항목 | NULL 건수 / 전체 |
|---|---|
| customers.sales_user_id | 4 / 4 (**전부 미지정**) |
| contracts.sales_user_id | 1 / 4 (계약 5 = C-20260728-001) |
| projects.sales_user_id | 1 / 4 |
| projects.site_manager_id | 4 / 4 (**전부 미지정**) |
| payments.created_by | 7 / 13 |
| leads.sales_user_id | 0 / 2 |

**영향**: `Scope`(권한 범위) 필터가 담당자 기준으로 목록을 좁히는 경우, 담당자 NULL 인 행은 super_admin 외에는 아무에게도 보이지 않을 수 있다. 특히 **고객 4건 전부가 영업 담당 미지정**이라 sales_manager 계정에서 고객 목록이 비어 보일 소지가 있다.
- **자동 보정**: 불가 (누구를 담당자로 지정할지는 업무 판단)

## ⚠️ 코드 측 role_key 하드코딩 누락

`DashboardController.php:164, 220, 321, 675` 는 직원 목록을 `role_key IN ('sales_manager','site_manager','staff')` 로 조회한다 — **`super_admin` 과 `accountant` 가 제외**된다. 운영 DB의 `super_admin`(user 1, 김덕수)은 프로젝트 5·7·10·12·13 에 배정되어 있고 site_bonuses 4건(id 3,4,5,6)의 수령자다.
- **현재 실질 영향 = 0건** — 해당 프로젝트·보너스가 모두 소프트삭제 상태라 지금은 표에 나올 데이터가 없다.
- **잠재 영향**: 앞으로 super_admin 이 프로젝트에 배정되면 직원 성과·보너스 표에서 조용히 누락된다.

---

# 4. 중복 — ⚠️ 문제 1건

| 검사 | 결과 |
|---|---|
| 중복 고객 (name + phone, 살아있음) | **0건** |
| 중복 고객 (name, 삭제 포함 전수) | 0건 |
| 중복 사업자등록번호 | 0건 |
| 중복 계약번호 (uq_contracts_contract_no) | 0건 |
| 중복 프로젝트번호 (uq_projects_project_no) | 0건 |
| 중복 견적번호 (uq_quotes_quote_no) | 0건 |
| 중복 권한 (user_id + resource_key, uq_emp_perm) | **0건** |
| 중복 quote_versions (quote_id + version_no) | 0건 |
| 중복 프로젝트 배정 (project_id + user_id) | 0건 |
| 한 계약에 프로젝트 2개 이상 | 0건 |
| 한 견적에 계약 2개 이상 | 0건 |
| **중복 site_bonuses (user + project + year + half)** | **1건** |

## 4-A. 【낮음】 중복 보너스 원장 1건

```
user_id=1, project_id=10, year=2026, half=2  →  id 3, 4
  id 3: base 30,000,000 / rate 10.00 / calc 1,800,000 / confirmed 1,200,000 / pay_status=paid
  id 4: base 27,272,727 / rate  5.00 / calc   818,182 / confirmed   300,000 / pay_status=paid
```

두 행 모두 `deleted_at` 이 찍혀 있어 **현재 집계 영향 0원**. 인덱스 `idx_sb_user_period(user_id, year, half)` 는 **UNIQUE 가 아니어서** DB 차원의 중복 방지가 없다(R12 확정보너스 재계산 과정의 잔재로 추정).
- **자동 보정**: 불가 — 어느 쪽이 맞는 산정인지 업무 판단 필요. 단, 둘 다 삭제 상태라 방치해도 무해.

## 4-B. 번호 채번기 — ✅ 안전

`nextContractNo()`(`ContractsController.php:1077-1085`) · `nextQuoteNo()`(`QuotesController.php:552-560`) 는 `WHERE ... LIKE prefix ORDER BY no DESC LIMIT 1 FOR UPDATE` 로, `generateProjectNo()`(`ProjectsController.php:923-939`, `ContractProjectService.php:158-172`) 는 `COUNT(*)` + 존재확인 재시도 루프로 채번한다. **어느 쪽도 `deleted_at` 을 필터하지 않으므로** 소프트삭제된 번호를 재사용하지 않는다(UNIQUE 충돌 없음). 완전삭제(purge) 후에도 재시도 루프 + uniqid 폴백으로 방어됨.

---

# 5. 금액 · 집계 정합성 — ⚠️ 문제 2건

| 검사 | 결과 |
|---|---|
| 계약: contract_amount ≠ supply + vat | **0건** (5건 전부 정합) |
| 계약: supply_amount / vat_amount NULL | **0건** |
| 계약: 계약금 + 중도금 + 잔금 ≠ 계약총액 | **0건** |
| 프로젝트: contract_amount ≠ 계약의 contract_amount | **0건** |
| 프로젝트: supply/vat 정합, NULL | **0건** |
| quote_versions: subtotal + vat − discount ≠ total_amount | **0건** |
| quote_versions: 항목 합계 ≠ subtotal | **0건** (4건 전부 일치) |
| 계약: original_quote_amount + adjust_amount ≠ contract_amount | 0건 (견적 연결 2건 모두 일치) |
| payments: status=paid 인데 paid_date NULL / 반대 | **0건** |
| 음수 금액 | **1건** (아래) |
| payment_status ↔ 입금합계 불일치 | **1건** (아래) |

## 5-A. 【중간】 음수 견적 총액 — quote_versions id=2

```
id=2, quote_id=2 (Q20260723-002), version_no=1
subtotal=0, vat=0, discount=32,000,000, total_amount=-32,000,000
quote_items id=2: name='도장', area=12334, qty=1, unit_price=0, amount=0
```

할인액이 소계보다 크게 입력되어 총액이 **−32,000,000원**이 됐다. 견적 2는 2026-07-24 소프트삭제됨 → 현재 집계 영향 없음.

- **원인**: 견적 저장 시 `discount ≤ subtotal + vat` 검증 부재
- **심각도**: 중간 (지금은 무해하나 살아있는 견적에서 재발하면 견적 합계 카드가 음수가 됨 — 견적 목록의 `SUM(qv.total_amount)` 합계 카드에 그대로 반영)
- **자동 보정**: 가능 (삭제 상태이므로 방치 무해). 코드 측 하한 검증 추가 권장

## 5-B. 【정보】 완납 상태 ↔ 입금합계 — contract 1

```
contract 1 (C-20260723-001): status=terminated, payment_status=paid,
contract_amount=2,750,000, 순입금(paid)=2,750,000, 잔액=0
```

금액상으로는 **정확히 완납**이고 `payment_status='paid'` 도 맞다. 다만 `status='terminated'`(파기)인데 완납 표시가 함께 남아 있고, `contract_terminations` 행도 1건 존재(정상). 파기 계약의 완납 배지 표시는 업무 규칙 확인 대상.

살아있는 계약 4건은 전부 `payment_status='unpaid'` / 순입금 0원 — **정합**. (입금 6건이 `pending` 상태이므로 순입금 0이 맞다.)

## 5-C. 【정보】 프로젝트 estimated_cost vs costs 합계 불일치 4건

| project | estimated_cost | costs(type=estimate) 합 | actual_cost | costs(type=actual) 합 | 삭제 |
|---|---:|---:|---:|---:|---|
| 5 (P2026-0005) | 1,000,000 | 0 | 0 | 0 | 삭제됨 |
| 7 (P2026-0006) | 10,000,000 | 0 | 10,000,000 | 10,000,000 | 삭제됨 |
| 10 (P2026-0007) | 10,000,000 | 0 | 10,000,000 | 10,000,000 | 삭제됨 |
| 13 (P2026-0009) | 10,000,000 | 0 | 0 | 0 | 삭제됨 |

`actual_cost` 는 전부 일치(`CostService.php:51-55` 가 `type='actual' AND cost_status='confirmed'` 만 롤업). **`estimated_cost` 는 costs 원장에서 파생되는 값이 아니라 프로젝트에 직접 입력하는 예산값**이므로 이 차이는 설계상 정상이다. 4건 모두 삭제 상태. — **문제 아님**

## 5-D. 확정매출 집계에 삭제 데이터 포함 여부 — ✅ 코드는 올바름

`AccountingService::PAY_SOURCE_COND`(`app/core/AccountingService.php:82-84`)와 `PAY_PROJECT_JOIN`(`:417-419`)이 계약·프로젝트 양쪽의 `deleted_at IS NULL` 을 강제한다. `costTotal`(`:597-598`), `contractedAmount`(`:160-161`), `expectedRevenue`(`:149-150`) 모두 동일. **삭제 데이터가 매출에 섞여 들어오는 경로는 없다.** (문제는 반대 방향 — 2-A 참조)

**참고 실측값** (삭제 필터 적용, 현재 화면에 나오는 값):

| 지표 | 값 |
|---|---:|
| 확정매출 | 0원 |
| 원가 총액 | 0원 |
| 미수금(active/on_hold/completed 계약) | 120,000,000원 (계약 2·3·4·5) |
| 수주액(contractedAmount) | 109,090,909원 (프로젝트 4건) |
| 예상매출(expectedRevenue) | 109,090,909원 (프로젝트 4건) |

---

# 6. 날짜 — ✅ 정상 (0건)

| 검사 | 결과 |
|---|---|
| 시작일 > 종료일 (contracts / projects 계획·실제 / schedules / project_assignments / goals / warranty_repairs) | **0건** |
| schedules: start_datetime > end_datetime | **0건** (2건 모두 정상 범위) |
| `0000-00-00` 제로 날짜 (contracts / projects / payments / customers / costs / users) | **0건** |
| 미래 이상값 (계약일 +2년 초과, 종료일 +3년 초과, 미래 입금일·지출일·입사일) | **0건** |
| 과거 이상값 (2020 이전) | **0건** |
| 프로젝트 날짜 vs 계약 날짜 불일치 (contract_date / start_date / end_date) | **0건** (5쌍 전부 일치) |
| status=completed/settled 인데 actual_end_date NULL | **0건** |

## ⚠️ 참고: 진행중인데 실제 착공일 미기록 1건

```
project 14 (P2026-0010, 라운지액스 마포점): status=in_progress, progress=75%, actual_start_date=NULL
```
공정 게이지는 75% 진행인데 실제 착공일이 비어 있다. 준공월 귀속 집계에는 `actual_end_date` 만 쓰이므로 금액 영향은 없다. — 운영 입력 누락

---

# 7. 상태값(enum/허용값) — ✅ 정상 (허용값 밖 0건)

코드 화이트리스트(`StatusService` / 각 Controller 상수 / `Perm::resources()`)를 전수 추출해 운영 DB 실제 분포와 대조했다. **허용값 밖의 값은 한 건도 없다.**

| 컬럼 | 코드 허용값 | DB 실제 분포 | 판정 |
|---|---|---|---|
| contracts.status | draft/active/on_hold/completed/cancelled/terminated | active 3, completed 1, terminated 1 | ✅ |
| contracts.payment_status | unpaid/partial/paid | unpaid 4, paid 1 | ✅ |
| projects.status | preparing/in_progress/paused/cancelled/terminated/completed/warranty/settled | in_progress 4, completed 3, settled 3, preparing 1 | ✅ |
| projects.settlement_status | unsettled/partial/settled/refunding/hold | unsettled 8, partial 1, refunding 1, settled 1 | ✅ |
| projects.contribution_mode | main/ratio/role (**POST 검증 없음**) | ratio 9, main 1, role 1 | ✅ |
| quotes.status | draft/sent/accepted/rejected/expired | draft 3, accepted 1 | ✅ |
| payments.status | pending/paid/cancelled | paid 7, pending 6 | ✅ |
| payments.pay_type | down/middle/balance/etc (+ 정산경로 `refund`) | balance 6, down 3, etc 3, **refund 1** | ⚠️ 아래 |
| payments.method | transfer/cash/card/etc + NULL | NULL 7, cash 3, transfer 3 | ✅ |
| payments.kind | payment/refund | payment 11, refund 2 | ✅ |
| customers.status | active/inactive/blacklist | active 6 | ✅ |
| costs.type / cost_status / category | estimate·actual / draft·pending·confirmed·cancelled / 8종 | actual 4 / confirmed 4 / material 3·labor 1 | ✅ |
| schedules.type / slot / status | work·meeting·site_visit·other / am·pm·night / (**서버 화이트리스트 없음**) | work 2 / am 2 / scheduled 2 | ✅ |
| schedule_time_slots.slot | morning/afternoon/night (DB ENUM) | morning 2, afternoon 2 | ✅ |
| site_bonuses.pay_status | unpaid/paid/cancelled (R12에서 `partial` 폐지) | paid 5 — **레거시 `partial` 0건** | ✅ |
| site_bonuses.calc_basis | (레거시 자유입력, R9-2 이후 미기록) | 전부 NULL | ✅ |
| project_assignments.status | active/ended (**PHP 검증 없음**) | active 11 | ✅ |
| project_assignments.role | 7종 한글 리터럴 | 현장책임자 10, 도장작업자 1 | ✅ (스키마 주석의 단축형 `보조`/`실측` 등 **0건**) |
| users.status / role_key | active·inactive / 5종 | active 7 / super_admin 1·sales_manager 1·site_manager 4·staff 1 | ✅ (accountant 0명) |
| process_stages.process_type / stage_group | painting·interior·common / 6종 | interior 17·painting 13·common 3 / build 18·prep 8·finish 4·complete 1·defect 1·waiting 1 | ✅ |
| employee_permissions.section / resource_key | 3종 / 10종 (`Perm.php:29-53`) | 정확히 그 3종 / 그 10종 | ✅ **사문화 키 0건** |
| contract_status_history.to_status | (contracts.status 와 동일 집합) | active 3, draft 3, completed 2, terminated 1 | ✅ |
| project_status_history.to_status | (projects.status 와 동일 집합) | in_progress 22, completed 17, preparing 5, settled 3, warranty 3, paused 1 | ✅ |
| attendance_marks.mark_type | late/absent (DB ENUM) | absent 1 | ✅ |

## 7-A. 【낮음】 `payments.pay_type = 'refund'` 는 라벨 맵에 없음

`SettlementController.php:91` 이 환불 입금에 `pay_type='refund'` 를 직접 쓰는데, `ContractsController::PAY_TYPE_LABELS`(`:18`)에는 `refund` 키가 없다. 운영 DB에 **payment id=17 이 실제로 `pay_type='refund'`**.

**실제 위험은 없음** — 모든 표시 지점이 `?? ` 폴백을 쓴다:
- `DashboardController.php:252` → `PAY_TYPE_LABELS[$r['pay_type']] ?? $r['pay_type']`
- `app/views/contracts/show.php:160` / `app/views/projects/_tab_settlement.php:95` → `$payTypeLabels[$pm['pay_type']] ?? $pm['pay_type']`

즉 라벨 대신 원문 `refund` 가 노출될 뿐 `Undefined array key` 경고는 발생하지 않는다. 라벨 맵에 `'refund' => '환불'` 추가만 하면 해소.

## 7-B. 【정보】 `projects.status='settled'` 3건은 전환 경로가 없는 레거시

`StatusService::PROJECT_TRANSITIONS`(`app/core/StatusService.php:68-80`)에서 R12 이후 `settled` 로 **들어가는 경로가 제거**됐고 나가는 경로는 `settled → in_progress`(사유 필수)만 남아 있다. 운영 DB의 `status='settled'` 3건(project 1, 5, 7)은 **전부 소프트삭제 상태**라 실질 영향 없음.

## 7-C. 【정보】 서버 검증이 없는 쓰기 경로 (현재 오염 0건)

아래 컬럼들은 화이트리스트 검증 없이 POST 값이 그대로 저장되지만, **운영 DB 실측 결과 오염된 값은 하나도 없다.** 예방 차원의 기록:

| 컬럼 | 위치 |
|---|---|
| `schedules.status` | `ScheduleController.php:149` → 저장 `:188` |
| `project_assignments.status` | `AssignmentsController.php:65` |
| `projects.contribution_mode` | `ProjectsController.php:651` → 저장 `:684` |

특히 `project_assignments` 는 `uq_assign_active_pair` UNIQUE 가 `status='active'` 기반 생성컬럼에 걸려 있어, 오타 status 가 들어가면 중복 배정 가드를 조용히 우회한다.

---

# 8. 이력 테이블 누락 — ✅ 정상 (0건)

| 검사 | 결과 |
|---|---|
| 계약 5건 중 contract_status_history 0행인 계약 | **0건** (각 1~2행 보유) |
| 프로젝트 11건 중 project_status_history 0행인 프로젝트 | **0건** (각 1~16행) |
| 프로젝트 11건 중 project_process_history 0행인 프로젝트 | **0건** (각 1~44행) |
| 이력 최신 to_status ≠ 현재 contracts.status | **0건** |
| 이력 최신 to_status ≠ 현재 projects.status | **0건** |
| 이력 최신 to_stage_id ≠ 현재 projects.process_stage_id | **0건** |
| status='terminated' 인데 contract_terminations 없음 / 반대 | **0건** |

상태 이력 체계는 **완전히 정합**하다.

## 8-A. 【낮음】 audit_logs ID 시퀀스에 90개 공백

```
COUNT(*)=392, MIN(id)=1, MAX(id)=482  →  누락 ID 90개
```
가장 큰 연속 공백은 **432–467 (36개)** 로 2026-07-29 08:29:43 ~ 08:39:30 사이. InnoDB 는 롤백된 트랜잭션의 auto_increment 를 반환하지 않으므로, **감사 로그를 포함한 트랜잭션이 롤백된 흔적**으로 보인다(업무 변경도 함께 롤백됐다면 정합). 별도의 감사 로그 삭제·보존 정책 코드는 없다(`AuditController.php` 에 purge/retention 없음).
- **자동 보정**: 불가 / 불필요. 감사 로그 누락이 실제 미기록인지 확인하려면 해당 시각대(08:29–08:39)의 관리자 작업을 사람이 대조해야 한다.

---

# 9. 인덱스 — ⚠️ 구조적 공백 (현재 성능 영향은 없음)

## 9-A. ✅ 정상 확인

- **FK 인덱스 누락: 0건** — 84개 FK 컬럼 전부 어떤 인덱스의 선두 컬럼.
- **소프트삭제 인덱스**: contracts / customers / leads / projects / quotes / site_bonuses / users 전부 보유. **`goals.deleted_at` 만 없음**(goals 0행 — 무해).
- **중복(prefix 포함) 인덱스 5쌍** — 같은 선두 컬럼에 일반 인덱스 + UNIQUE 가 공존:

| 테이블 | 선두 컬럼 | 인덱스 |
|---|---|---|
| employee_permissions | user_id | `idx_emp_perm_user`, `uq_emp_perm(user_id,resource_key)` |
| projects | contract_id | `idx_projects_contract`, `uq_projects_contract` |
| quote_versions | quote_id | `idx_quote_versions_quote`, `uq_quote_versions(quote_id,version_no)` |
| targets | user_id | `idx_targets_user`, `uq_targets_user_year_month` |
| user_permissions | user_id | `idx_user_permissions_user`, `uq_user_permissions(user_id,permission_id)` |

각 쌍에서 **일반 인덱스는 UNIQUE 의 prefix 라 완전히 중복**이다. 삭제해도 안전하나 현재 데이터 규모에서 이득이 없다(전체 인덱스 용량 합계 3MB 미만).

## 9-B. ⚠️ 집계 핫패스 인덱스 부재

| 필요 인덱스 | 존재 | 쓰이는 곳 |
|---|---|---|
| `payments(paid_date)` | **없음** | `confirmedRevenue()` 기간 필터 (`AccountingService.php:108`) |
| `payments(status, paid_date)` 복합 | **없음** | 동일 — 가장 효과 큼 |
| `payments(contract_id, status)` 복합 | **없음** | `PAID_SUM_SQL` / `LAST_PAID_SQL` 상관 서브쿼리 (`:176`, `:184`) |
| `costs(spent_date, type, cost_status)` 복합 | **없음** | `costTotal()` 기간 집계 |
| `projects(contract_date)` | **없음** | `contractedAmount()` (`:160`) |
| `projects(actual_end_date)` | **없음** | 준공월 귀속 집계 (`:475`) |
| `contracts(contract_date)` | **없음** | 계약 목록 기본 정렬 |
| `projects(status, deleted_at)` 복합 | 없음 (단일 2개는 있음 — index_merge 로 커버) | 목록/보드 |

## 9-C. EXPLAIN 실측

| 쿼리 | type | rows | 비고 |
|---|---|---:|---|
| 확정매출 집계 (기간 포함) | `pm: ALL` | 13 | `paid_date` 인덱스 없음 → **full scan** |
| 계약 목록 (미수금 서브쿼리) | `c: ref(idx_contracts_deleted_at)` | 3 | 서브쿼리는 `idx_payments_contract` 사용 + rowid filter ✅ |
| 프로젝트 목록 | `index_merge(deleted_at ∩ status)` | 1 | ✅ |
| 원가 기간 집계 | `cs: ALL` + BNL join | 4 | 복합 인덱스 없음 |
| 직원 귀속 매출 (기여도 조인) | `pm: ALL` + BNL join, temporary+filesort | 13 | 가장 무거운 형태 |
| 고객 목록 (LIKE 검색) | `index` | 6 | 선행 와일드카드 `%김%` → 인덱스 사용 불가(설계상) |
| 공정보드 | `ref(idx_projects_deleted_at)` | 4 | ✅ |
| 감사로그 목록 | `a: ALL` + filesort | 412 | `idx_audit_logs_created_at` 있으나 옵티마이저가 미채택(테이블 소형) |

> **현재는 전 테이블이 500행 미만이라 full scan 이 오히려 빠르다. 위 인덱스 공백은 지금 조치할 필요가 없고, payments 가 수천 행대로 커지는 시점에 `payments(status, paid_date)` 부터 추가하면 된다.**

---

# 10. NULL 처리 — ⚠️ 잠재 (현재 경고 유발 0건)

## 10-A. ✅ 뷰 계층은 NULL-안전

공용 헬퍼가 nullable 시그니처다: `e(?string)`(`app/core/Util.php:7`), `money(?float)`(`:13`), `fmtdate(?string)`(`:328`). 라벨 맵 조회는 뷰 전반에서 `?? 폴백` 을 사용한다. 따라서 `fmtdate($r['valid_until'])`, `fmtdate($pm['paid_date'])`, `$payTypeLabels[...] ?? ...` 등은 전부 안전.

## 10-B. 살아있는 행의 NULL 실태 (경고 후보 모집단)

| 테이블 | 컬럼 | NULL / 전체 |
|---|---|---|
| contracts (4건) | contract_file_id | 4/4 |
| | construction_type, warranty_period, quote_id | 3/4 |
| | start_date, end_date, site_address | 2/4 |
| | sales_user_id, work_name, work_type | 1/4 |
| projects (4건) | customer_name_snapshot, expected_amount, actual_end_date, site_manager_id | 4/4 |
| | start_date, end_date, site_address | 2/4 |
| | actual_start_date, sales_user_id | 1/4 |
| customers (4건) | address, site_address, source, interest_type, sales_user_id | 4/4 |
| | phone, email | 3/4 |
| | company_name, contact_name | 2/4 |
| | biz_reg_no (is_business=1 인데 미입력) | 1 |

`projects.customer_id` / `contract_id` NULL 0건, `customer_name_snapshot` 과 동시 NULL 0건 → **고객 표시 불가 프로젝트 없음.**

## 10-C. ⚠️ NULL 산술에서 조용히 0이 되는 지점 (파이프라인)

`leads.expected_amount` / `expected_cost` / `win_probability` 가 NULL 일 때 `(float) null === 0.0` 으로 강등되어 **`-` 대신 `0원`이 표시**되고 합계를 과소 계상한다. 형제 코드(`app/views/pipeline/index.php:69`, `app/views/customers/show.php:181`)는 올바르게 `!== null` 을 검사하므로 일관성 문제이기도 하다.

| 위치 | 표현식 |
|---|---|
| `app/views/pipeline/show.php:68` | `money((float) $l['expected_amount'])` |
| `app/views/pipeline/show.php:69` | `money((float) $l['expected_cost'])` |
| `app/views/pipeline/show.php:72` | `money((float) $l['weighted_revenue'])` |
| `app/views/pipeline/board.php:49` | `moneyShort((float) $l['expected_amount'])` |
| `app/controllers/PipelineController.php:181` | `$columns[$g]['sum'] += (float) $l['expected_amount'];` |
| `app/controllers/PipelineController.php:269-270` | `(float) $lead['expected_amount']` / `['expected_cost']` → `Calc::profit()` |
| `app/controllers/ContractsController.php:659` | `(float) $before['supply_amount'] !== (float) $data['supply_amount']` — 레거시 NULL 이 `0.0` 과 같아져 변경 감지 스킵 |

**운영 DB 실측 — 해당 사례 1건 존재:**

| lead | expected_amount | expected_cost | win_probability | expected_profit |
|---|---:|---:|---:|---:|
| 1 | 2,000,000 | 500,000 | 50.00 | 1,500,000 |
| 2 | 10,000,000 | 0 | **NULL** | 10,000,000 |

**lead 2 의 `win_probability` 가 NULL** 이므로 파생값 `weighted_revenue`(= expected_amount × win_probability)가 NULL 이 되고, `pipeline/show.php:72` 의 `money((float) $l['weighted_revenue'])` 가 이를 **`0원`으로 표시**한다("미입력"이 아니라 "0원"). 리드 상세 화면 1곳의 표시 문제이며 금액 집계에는 영향 없음.

## 10-D. ⚠️ 가드 없는 라벨 맵 조회 1곳

```php
// app/controllers/ProjectsController.php:454
Response::error('허용되지 않는 상태 전환입니다: ' . self::STATUSES[$from] . ' → ' . self::STATUSES[$to], 422);
```
`$from` 은 DB 원문(`$project['status']`)이며 사전 검증이 없다(`$to` 는 `:446` 에서 검증됨). **운영 DB의 projects.status 는 8종 허용값 안에 전부 들어 있으므로 현재 경고는 발생하지 않는다.**

---

# 11. 기타 구조적 발견

## 11-A. 【중간】 `uq_projects_contract` 를 소프트삭제 프로젝트가 점유

```
contract 1 (C-20260723-001, status=terminated, 삭제됨)
  └ project 1 (P2026-0001, 삭제됨 2026-07-26) 가 uq_projects_contract UNIQUE 슬롯 점유
```

`projects.contract_id` 에 UNIQUE(`uq_projects_contract`)가 걸려 있는데 소프트삭제 행도 슬롯을 차지한다. 계약 1을 복원한 뒤 "프로젝트로 전환"을 시도하면 1062 가 나고, 코드는 이를 감지해 명시적으로 막는다:

```php
// app/core/ContractProjectService.php:137-138
// 소프트삭제된 프로젝트가 UNIQUE 를 점유 — 자동 복구 대신 관리자 확인 유도(계약 전환도 롤백)
throw new RuntimeException('이 계약에는 삭제된 프로젝트가 연결되어 있어 자동 생성할 수 없습니다. 관리자 확인이 필요합니다.');
```

- **판정**: 코드가 인지하고 안전하게 처리 중 — **버그 아님**. 다만 계약 1을 되살리려면 project 1 도 함께 복원해야 한다는 운영 지식이 필요.
- **현재 영향 대상**: 1건 (계약 1 ↔ 프로젝트 1)

## 11-B. 【낮음】 기여율 합계 ≠ 100% 인 프로젝트 2건

| project | contribution_mode | 배정 | 합계 |
|---|---|---|---:|
| 12 (P2026-0008) | ratio | user 1 : 50% | **50%** |
| 13 (P2026-0009) | ratio | user 1 : 50% | **50%** |

두 프로젝트 모두 소프트삭제 상태. 나머지 4개 프로젝트(1, 5, 7, 10)는 정확히 100%. DB·코드 어느 쪽에도 "합계 100%" 강제가 없어 재발 가능하다. 기여액 산식이 `amount × contribution_pct/100` 이므로 합이 50% 면 **매출/원가의 절반이 어느 직원에게도 귀속되지 않는다.**

- **현재 영향**: 0원 (둘 다 삭제 상태)
- **자동 보정**: 불가 (나머지 50%를 누구에게 줄지 업무 판단)

## 11-C. ✅ 공정 진행률(progress)은 정합

`projects.progress` 와 `project_stage_progress` 를 대조했다. 산식은 `ProcessService::recalcProgressFromGauges()`(`app/core/ProcessService.php:117-136`) — **분모는 해당 공정유형의 활성 게이지 공정 전체 수**이고 행이 없는 공정은 0 으로 계산된다(단순 평균이 아니다).

| project | 유형 | 저장 progress | sum(pct) | 게이지 공정 수 | 검산 |
|---|---|---:|---:|---:|---|
| 2 (P2026-0002) | interior | 69 | 1,165 | 17 | 1165/17 = 68.5 → **69** ✅ |
| 3 (P2026-0003) | painting | 38 | 495 | 13 | 495/13 = 38.1 → **38** ✅ |
| 4 (P2026-0004) | painting | 92 | 1,200 | 13 | 1200/13 = 92.3 → **92** ✅ |
| 14 (P2026-0010) | interior | 75 | 1,275 | 17 | 1275/17 = 75.0 → **75** ✅ |

`pct` 범위(0~100) 위반 **0건**, 프로젝트 공정유형과 다른 stage 참조 **0건**.

---

# 즉시 조치 필요

1. **[심각] 확정매출·원가가 0원으로 표시되는 상태를 사장에게 즉시 고지** — `status='paid'` 입금 7건 전부가 삭제된 계약/프로젝트에 매달려 있어 대시보드·리포트·반기·목표 달성률·보너스가 모두 0원이다. 필터 미적용 시 확정매출 49,772,727원 / 원가 20,600,000원. 원인은 데이터 손상이 아니라 **삭제 이력**이므로 복원으로 즉시 되돌릴 수 있다.
2. **[심각] 계약 소프트삭제 가드에 입금 참조 검사 추가** — `ContractsController::deleteBlockReason()`(`:905-909`)이 살아있는 프로젝트만 막고 입금은 검사하지 않는다. 2026-07-29 06:55:21 에 입금 2건(2,750,000원)이 달린 C-20260723-001 이 경고 없이 삭제됐다(audit id 427). 최소한 "확정매출 N원이 집계에서 빠집니다" 경고 노출 필요.
3. **[중간] 고객 소프트삭제 가드 부재** — `CustomersController::delete()`(`:472`)에 자식 참조 검사가 전혀 없다. 그 결과 삭제된 customer 1 을 **살아있는 lead 1 · quote 1(Q20260723-001, accepted)** 이 참조 중이고, 견적/파이프라인 목록이 `JOIN customers` 에 `deleted_at` 필터를 걸지 않아 **삭제된 고객 이름이 살아있는 목록에 그대로 노출**된다.
4. **[낮음] 견적 할인액 상한 검증 추가** — `quote_versions` id=2 의 `total_amount = -32,000,000`(discount 32,000,000 > subtotal 0). 현재는 삭제 상태라 무해하나, 견적 목록 합계 카드가 `SUM(qv.total_amount)` 라 살아있는 견적에서 재발하면 합계가 음수가 된다.
5. **[낮음] `PAY_TYPE_LABELS` 에 `'refund' => '환불'` 추가** — `SettlementController.php:91` 이 쓰는 값이 라벨 맵에 없어 화면에 원문 `refund` 가 노출된다(경고는 안 남 — 모든 지점이 `??` 폴백 사용). 운영 DB 실제 1건(payment id 17).

# 사장 판단 필요

1. **삭제된 4건이 정말 삭제 대상인가** — contract 1(C-20260723-001, 입금 2,750,000원 완납·파기 처리됨), project 10 / 12 / 13(예외 프로젝트, 입금 각 30,000,000 / 2,000,000 순 / 20,000,000 순). 테스트 데이터라면 그대로 두고, 실 거래라면 휴지통에서 복원해야 매출 지표가 정상화된다. **자동 보정 불가.**
2. **삭제된 고객 "고객1"(customer 1) 처리** — 복원할지, 아니면 이를 참조하는 lead 1 / quote 1(Q20260723-001, accepted)도 함께 삭제할지.
3. **담당자 미지정 데이터** — 고객 4건 **전부** `sales_user_id` 미지정, 프로젝트 4건 **전부** `site_manager_id` 미지정, 계약 1건·프로젝트 1건 영업담당 미지정. 권한 범위(Scope) 필터가 담당자 기준으로 좁히는 화면에서 sales_manager 계정에 아무것도 안 보일 수 있다. 담당자 배정이 필요하다.
4. **중복 보너스 원장 1건** — user 1 / project 10 / 2026 하반기에 id 3(확정 1,200,000원)과 id 4(확정 300,000원)가 공존. 둘 다 삭제 상태라 지금은 무해하지만, 어느 산정이 맞는지 확정하고 `idx_sb_user_period` 를 UNIQUE 로 승격할지 결정 필요.
5. **기여율 50%만 배정된 프로젝트 2건**(12, 13) — 나머지 50%를 누구에게 귀속시킬지. 둘 다 삭제 상태.
6. **파기(terminated) 계약의 완납 배지** — contract 1 이 `status='terminated'` + `payment_status='paid'` 로 공존한다. 금액상 완납이 맞으나(2,750,000 = 2,750,000), 파기 계약에 완납 표시를 유지할지 업무 규칙 확인.
7. **감사 로그 ID 공백 90개** — 특히 432–467(36개, 2026-07-29 08:29~08:39). 롤백된 트랜잭션 흔적으로 추정되나, 해당 시간대 관리자 작업이 실제로 기록 없이 반영됐는지는 사람이 대조해야 한다.
8. **계약 1 복원 시 프로젝트 1 동반 복원 필요** — `uq_projects_contract` 를 소프트삭제된 project 1 이 점유 중이라, 계약만 복원하면 "프로젝트로 전환"이 막힌다(코드가 명시적 오류로 안내). 운영 절차에 반영 필요.

---

## 부록: 정상 확인 항목 (한 줄 요약)

- FK 정합성 84개 관계 전수 검사 — **orphan 0건**
- 중복 고객/계약번호/프로젝트번호/견적번호/권한 레코드/견적버전/프로젝트배정 — **전부 0건**
- 계약·프로젝트 VAT/공급가 정합, 계약금+중도금+잔금 합, 견적 항목합↔소계, 계약↔프로젝트 금액 동기화 — **불일치 0건**
- 날짜(시작>종료 / 0000-00-00 / 미래·과거 이상값 / 계약↔프로젝트 날짜) — **0건**
- 상태값 27개 컬럼 코드 화이트리스트 대조 — **허용값 밖 0건**
- 상태 이력(계약/프로젝트/공정) 누락 및 최신값↔현재값 불일치 — **0건**
- `users.role_key` 비정규화 캐시 vs `roles.role_key` — **불일치 0건**
- 삭제·비활성 직원을 담당자로 가진 데이터 — **0건**
- FK 인덱스 누락 — **0건**
- 공정 진행률(progress) ↔ 게이지 원장 정합 — **4건 전부 검산 일치**
- `employee_permissions.resource_key` 사문화(코드에 없는) 키 — **0건**
