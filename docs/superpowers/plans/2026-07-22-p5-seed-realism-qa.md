# P5 시드 현실화 + 화면=DB 대사 QA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** 개발 시드를 실제 도장회사처럼 현실화(완료·진행·예정·취소·적자·부분입금·파이프라인·목표)해 화면이 의미 있는 확정 수치를 보여주게 하고, 화면 집계 = DB 원천을 대사(reconcile)한다. 스펙 §13 이상 데이터 없음·§14 테스트 케이스 반영.

**Architecture:** `database/seed_dev.sql` 재작성(정확한 금액·기준일·기여도). AccountingService 산식으로 재계산되므로 시드 값만 현실화. 검증은 SQL 대사 + 브라우저 스크린샷.

**Tech Stack:** MySQL 8(.devdb), 브라우저(puppeteer-core + system Chrome).

## Global Constraints
- 모든 계약·프로젝트 `supply_amount + vat_amount = contract_amount`(정수 원). VAT율 10%.
- 프로젝트별 배정 기여도 합계 = 100%.
- 완료(준공) 프로젝트만 확정 매출/순이익/기여에 반영(actual_end_date 필수). 취소는 전 집계 제외.
- §13 이상 데이터 금지: 입금>계약액 없음 · 계약없는 매출 없음 · 중복 입금 없음 · 기여도>100% 없음 · 원가없는 완료 순이익 확정 없음(완료는 actual_cost 입력) · 취소건 매출반영 없음 · 미래날짜 실적반영 없음 · 기간 역전 없음 · 준공전 정산완료 없음 · 음수/비현실 % 없음 · 만원/원 단위 혼용 없음.
- 현재 날짜 기준 2026-07-22.

## 시드 설계 (정확값)

### 사용자(users)
| id | login | 이름 | role_key | role_id | 직급 |
|---|---|---|---|---|---|
| 1 | admin | 김대표 | super_admin | 1 | 대표 |
| 2 | chays | 차윤석 | site_manager | 3 | 현장팀장 |
| 3 | maeng | 맹기현 | staff | 4 | 도장기능공 |
| 4 | chaws | 차우석 | staff | 4 | 도장보조 |
| 5 | leesh | 이상훈 | sales_manager | 2 | 영업팀장 |
(비밀번호 전부 password123! — 기존 해시 재사용. 5는 dept 2.)

### 고객(customers) — 6
1 대명건설(company) · 2 이수아(individual) · 3 한빛아파트 입대의(company) · 4 서초상가(company) · 5 남동공단 ㈜세림(company) · 6 판교오피스텔(company, 취소건).

### 프로젝트(projects) — 6 (공급가=÷1.1 또는 견적)
| no | 고객 | 상태 | 계약액 | 공급가 | 부가세 | 실제원가 | contract_date | actual_end_date | end_date | sales | site |
|---|---|---|---|---|---|---|---|---|---|---|---|
| P2026-0001 대명 외벽 | 1 | in_progress | 37,462,250 | 34,000,000 | 3,462,250 | 9,800,000 | 2026-07-08 | NULL | 2026-09-30 | 5 | 2 |
| P2026-0002 이수아 방수 | 2 | completed | 22,000,000 | 20,000,000 | 2,000,000 | 14,000,000 | 2026-06-01 | 2026-07-10 | 2026-07-15 | 5 | 2 |
| P2026-0003 한빛 계단실 | 3 | completed(적자) | 9,900,000 | 9,000,000 | 900,000 | 10,500,000 | 2026-06-10 | 2026-07-05 | 2026-06-30 | 5 | 2 |
| P2026-0004 서초상가 리모델링 | 4 | preparing | 15,400,000 | 14,000,000 | 1,400,000 | 0 | 2026-07-18 | NULL | 2026-08-20 | 5 | 2 |
| P2026-0005 세림 공장 에폭시 | 5 | preparing | 8,800,000 | 8,000,000 | 800,000 | 0 | 2026-07-20 | NULL | 2026-09-01 | 5 | 2 |
| P2026-0006 판교 오피스텔 | 6 | cancelled | 11,000,000 | 10,000,000 | 1,000,000 | 0 | 2026-07-02 | NULL | NULL | 5 | 2 |

### 계약(contracts) + 입금(payments) — 미수금
| no | proj | 계약액 | 공급가 | 부가세 | contract_date | status | 입금(paid) | 미수금 |
|---|---|---|---|---|---|---|---|---|
| C2026-0001 | P1 | 37,462,250 | 34,000,000 | 3,462,250 | 2026-07-08 | active | down 11,238,675 paid; middle 15,000,000 pending; balance 11,223,575 pending | 26,223,575 |
| C2026-0002 | P2 | 22,000,000 | 20,000,000 | 2,000,000 | 2026-06-01 | completed | down 6,600,000 + balance 15,400,000 = 22,000,000 paid | 0 |
| C2026-0003 | P3 | 9,900,000 | 9,000,000 | 900,000 | 2026-06-10 | active | down 5,000,000 paid; balance 4,900,000 pending | 4,900,000 |
| C2026-0004 | P4 | 15,400,000 | 14,000,000 | 1,400,000 | 2026-07-18 | active | down 4,620,000 pending | 15,400,000 |
(P5 무계약, P6 취소=계약 없음 또는 terminated 제외.) **미수금 총액 = 26,223,575+0+4,900,000+15,400,000 = 46,523,575.**
견적: 기존 Q2026-0001(P1, vat 3,462,250) 유지. 나머지 계약은 견적 없이 ÷1.1.

### 배정(project_assignments) — 기여도 합 100%/프로젝트
- P1: 2 현장책임자 40 · 3 도장작업자 35 · 4 보조 25.
- P2(완료): 2 현장책임자 60 · 3 도장작업자 40.
- P3(완료,적자): 3 도장작업자 100.
- P4: 2 현장책임자 100. P5: 2 현장책임자 100.

### 목표(targets) — 이상훈(5) 2026-07: target_revenue 50,000,000, target_profit 10,000,000, target_contracts 3, target_projects 3. company_targets 기존 유지.

### 리드(leads) — 파이프라인(sales_user 5), stage_key 매핑은 pipeline_stages 참조
| stage_key | expected_amount | win_probability | expected_cost | importance |
|---|---|---|---|---|
| new_inquiry | 25,000,000 | 20 | 18,000,000 | mid |
| consult_booked | 30,000,000 | 30 | 21,000,000 | mid |
| site_survey | 18,000,000 | 40 | 12,600,000 | low |
| quote_sent | 42,000,000 | 60 | 30,000,000 | high |
| negotiating | 55,000,000 | 70 | 38,000,000 | high |
| contract_pending | 33,000,000 | 85 | 23,000,000 | high |
| lost | 20,000,000 | 0 | 14,000,000 | mid |
| contract_won | 22,000,000 | 100 | 15,000,000 | mid |
(각 리드 customer_id 1~6 순환, created_at 최근 30일 내, next_contact_date 다양, stage_entered_at 설정.)

## 기대 대사값(검증 기준 — 2026-07)
- **이번달 확정매출**(준공 July: P2 20,000,000 + P3 9,000,000) = **29,000,000**
- **이번달 확정순이익** = (20,000,000−14,000,000)+(9,000,000−10,500,000) = 6,000,000 + (−1,500,000) = **4,500,000**
- **이번달 확정원가** = 14,000,000+10,500,000 = **24,500,000**
- **이번달 수주액**(contract_date July, 취소 제외 공급가: P1 34,000,000 + P4 14,000,000) = **48,000,000**
- **예상매출**(preparing/in_progress 공급가: P1 34,000,000 + P4 14,000,000 + P5 8,000,000) = **56,000,000**
- **미수금** = **46,523,575**
- **직원 확정 기여**: 차윤석(2)=P2 60%×6,000,000=**3,600,000**; 맹기현(3)=P2 40%×6,000,000 + P3 100%×(−1,500,000)=2,400,000−1,500,000=**900,000**; 차우석(4)=**0**(완료 배정 없음).
- **회사 확정순이익** = 4,500,000. 기여율: 2→80.0%, 3→20.0% (합 100%).
- **가중 예상매출(파이프라인)** = 25M×.2+30M×.3+18M×.4+42M×.6+55M×.7+33M×.85 = 5,000,000+9,000,000+7,200,000+25,200,000+38,500,000+28,050,000 = **112,950,000**
- **영업 전환율** = won 1 / total 8 = **12.5%**
- 이상훈 매출목표 달성률 = 48,000,000/50,000,000 = **96.0%**

---

### Task 1: seed_dev.sql 재작성 + 적재 + invariant/이상데이터 검증

**Files:** Modify `database/seed_dev.sql`

- [ ] **Step 1** 위 설계대로 `seed_dev.sql` 재작성: users 5, customers 6, quotes(기존 Q1 유지), quote_versions/items(Q1), contracts 4 + payments, projects 6, project_assignments, schedules(오늘 일정 유지), work_logs(기존 17행 유지), leads 8, targets(user 5), company_targets(유지). 파일 끝 supply/vat 백필 UPDATE는 명시 저장하므로 제거하거나 유지(명시값이 있으면 백필 WHERE IS NULL 로 no-op). **모든 supply/vat 를 INSERT에 명시**해 invariant를 보장.
- [ ] **Step 2** 적재: `mysql --socket=.devdb/mysql.sock -ueden_crm_user -p'EdenCrm!local2026' eden_crm < database/seed_dev.sql`.
- [ ] **Step 3** invariant/이상데이터 검증 쿼리(모두 0이어야 함):
```sql
SELECT
 (SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL AND supply_amount+vat_amount<>contract_amount) inv_proj,
 (SELECT COUNT(*) FROM contracts WHERE deleted_at IS NULL AND supply_amount+vat_amount<>contract_amount) inv_con,
 (SELECT COUNT(*) FROM (SELECT project_id FROM project_assignments GROUP BY project_id HAVING ROUND(SUM(contribution_pct),2)<>100) x) contrib_ne_100,
 (SELECT COUNT(*) FROM contracts c WHERE (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.contract_id=c.id AND p.status='paid') > c.contract_amount) overpaid,
 (SELECT COUNT(*) FROM projects WHERE status='completed' AND actual_end_date IS NULL) completed_no_enddate,
 (SELECT COUNT(*) FROM projects WHERE actual_end_date IS NOT NULL AND actual_end_date>CURDATE()) future_completion;
```
- [ ] **Step 4** feature_worklog 여전히 '0' 확인(seed_dev는 settings 미변경). `SELECT value FROM settings WHERE setting_key='feature_worklog';`=0.
- [ ] **Step 5** 커밋 `git add database/seed_dev.sql && git commit -m "feat(seed): 현실 도장회사 시드(완료/진행/예정/취소·적자·부분입금·파이프라인·목표)"` (+trailer).

---

### Task 2: 화면=DB 대사 QA + 스크린샷

**Files:** Create `scripts/reconcile_qa.php`

- [ ] **Step 1** `scripts/reconcile_qa.php` — AccountingService 로 위 기대 대사값을 계산해 assert(기대=실제) 표 출력:
  confirmedRevenue(July)=29,000,000; confirmedProfit(July)=4,500,000; confirmedCost(July)=24,500,000; contractedAmount(July,null)=48,000,000; expectedRevenue()=56,000,000; receivable()=46,523,575; employeeConfirmedContribution(2)=3,600,000, (3)=900,000, (4)=0; companyConfirmedProfit()=4,500,000; weightedPipeline()=112,950,000. 각 PASS/FAIL, 종료코드.
- [ ] **Step 2** 실행 `php scripts/reconcile_qa.php` → 전 항목 PASS. (불일치 시 시드 수정.)
- [ ] **Step 3** 전체 회계 테스트 `php scripts/tests/run.php` → 여전히 통과(대사 A~G 픽스처는 트랜잭션 롤백이라 시드 무관).
- [ ] **Step 4** 스크린샷: 서버 기동 후 `node scratchpad/shots/shot.js p5-after` — 대시보드/리포트/성과가 이제 실제 확정 수치(확정매출 2,900만·확정순이익 450만·직원 기여 차윤석 360만/맹기현 90만·파이프라인·미수금)를 표시하는지 육안 확인. 적자 프로젝트(한빛) 음수 표시 확인.
- [ ] **Step 5** 화면 합계 = 상세 합계 대사: 리포트 미수금 리스트 합계 = 미수금 KPI(46,523,575); 직원 성과 기여 합 = 회사 확정순이익(4,500,000). 스크린샷/쿼리로 확인.
- [ ] **Step 6** 커밋 `git add scripts/reconcile_qa.php && git commit -m "test(qa): 화면=DB 대사(확정매출·순이익·기여·미수금·파이프라인) + 스크린샷"` (+trailer). 검증 harness 기록.

## Self-Review
- §13 이상데이터 전 항목 방지(Step 3 쿼리). §14 A(정상이익 P2 30%)·B(적자 P3)·C(부분입금 C1/C3)·D(2인 기여 P2)·F(취소 P6 제외) 실 시드 반영. 화면=DB 대사(Task2). 완료 프로젝트로 대시보드 실수치 표시.
- **주의:** feature_worklog 는 '0' 유지. 모든 금액 정수 원. 준공일 과거, end_date와의 관계로 일정준수(P2 on-time, P3 late) 자연 발생.
