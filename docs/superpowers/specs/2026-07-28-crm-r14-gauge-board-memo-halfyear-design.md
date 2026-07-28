# R14 설계 — 공정 게이지 보드·카드 메모·예외 계약총액 연동·반기 실적 개편

작성일: 2026-07-28 · 브랜치: `r14-gauge-board`
사장 지시 6건 + 세션 1건(Claude 기록 1년 — settings 처리 완료). PC/모바일 반응형 전제.
확정 결정(사장): 게이지↔상태 **완전 자동** · 배치 **상태 그룹+카드내 게이지** · 반기 **계약=담당영업/공사=기여도** · 세션=Claude 기록.

---

## 1. 공정 보드 전면 개편 — 드래그 → 카드내 공정 게이지 (지시 #6, #1 흡수)

### 1.1 데이터 모델 (신규)
```sql
CREATE TABLE project_stage_progress (
  project_id INT UNSIGNED NOT NULL,
  stage_id   INT UNSIGNED NOT NULL,          -- process_stages(실공정만: waiting·warranty_repair·full_complete 제외)
  pct        TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0~100
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, stage_id),
  FK project→projects(CASCADE), stage→process_stages(RESTRICT), user→users(RESTRICT)
);
```
- `projects.process_stage_id`(현재 공정)와 `project_process_history`는 **유지** — 게이지에서 파생해 `ProcessService::moveStage`로만 갱신(이력·요약 헤더·기존 연동 보존).
- `projects.progress` 재정의: 위치 기반 → **게이지 평균**(해당 유형 활성 실공정 pct 평균).

### 1.2 파생 규칙 (서버 단일 출처 `ProcessService`)
| 게이지 상태 | 파생 |
|---|---|
| 전부 0 | 현재 공정 = 대기중(waiting) |
| 일부 >0 | 현재 공정 = **pct>0 인 가장 뒤(위치 최대) 공정** · status preparing/paused → `in_progress` 자동 |
| 전부 100 | 응답 `all_done:true` → 클라 확인 팝업 → 승인 시 `completed`(R13 T6이 전체완료 이동 처리) |
| completed 상태에서 게이지 <100 수정 | 재개 `in_progress` + 현재 공정 재파생 (R13 재개 규칙 계승) |
- **하자보수**: 드래그 대신 **카드 버튼** → status `warranty` + `moveStage(warranty_repair)`. 종료는 기존 warranty→completed 경로.
- cancelled/terminated 보드 제외(기존). R13 `boardStatusFor` 드래그 규칙은 게이지 이벤트 규칙으로 대체(전체완료·하자보수·진행 전환 의미 동일).

### 1.3 API
- `process.progress.set` (POST, perm process.move): project_id, stage_id, pct → upsert + 파생 실행. 응답: `{pct, progress, status, status_label, badge_class, current_stage_id, current_stage_name, group, all_done}` — **카드 배지 텍스트·클래스까지 JS 즉시 갱신(#1 버그 근본 해결: 기존 handleDrop이 배지 텍스트를 안 바꾸던 문제 포함)**. 상태 그룹이 바뀌면 카드 DOM을 해당 그룹으로 이동.
- `process.complete.confirm` (POST): all_done 확인 후 완료 처리(applyProjectStatus completed).
- `process.warranty.set` (POST): 하자보수 전환/해제.
- 기존 `process.move`(드래그)와 process-board.js 드래그 코드는 **제거**.

### 1.4 화면 (board.php 재구성 · PC/모바일 반응형)
- 유형 탭(도장/인테리어) 유지. **상태 그룹 4개**: 대기중(preparing) / 진행 중(in_progress·paused-배지) / 하자보수(warranty) / 종결(completed·settled).
- 카드: 기존 메타(고객·주소·영업·배정·일정·사진) + **공정 게이지 목록** — 착공준비/시공/마무리 그룹별 접기(현재 진행 그룹 펼침), 각 공정 `N. 이름 [슬라이더 step5] pct%`. 상단에 전체 진행률 바 + 현재 공정 칩 + 상태 배지.
- 입력: 슬라이더(터치) + 0/100 퀵버튼, 변경 시 디바운스 저장 → 응답으로 카드 갱신.
- 그리드: PC 그룹당 `auto-fill minmax(340px,1fr)`, ≤900px 1컬럼(기존 브레이크포인트 계승).
- **마이그레이션 백필**: 현 `process_stage_id` 위치 P 기준 — 위치<P 공정 pct=100, 위치=P pct=50, 대기중=전부 0, 완료·정산·전체완료=전부 100 (현 보드 상태 보존).

## 2. 카드 메모 — 일자별 작업 메모 (지시 #5)
```sql
CREATE TABLE project_memos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL, memo_date DATE NOT NULL,
  content VARCHAR(1000) NOT NULL, created_by INT UNSIGNED NULL,
  created_at/updated_at DATETIME, KEY (project_id, memo_date), FKs
);
```
- 재사용 검토 결과: `work_logs`는 현장일지형(무겁고 feature OFF), `schedules`는 일정 의미 — **신규 경량 테이블 채택**.
- 카드 '메모' 버튼 → `EDEN.modal` 레이어팝업: 일자 내림차순 목록(작성자·내용) + 등록 폼(날짜 기본 오늘 + 텍스트). 최근 메모 수 배지를 카드에 표시.
- 라우트 `process.memo.list/save/delete` — 조회는 보드 열람권, 등록/삭제는 process.move 권한.

## 3. 예외 프로젝트 계약 총액 연동 (지시 #2)
근본 원인: 요약 헤더는 `projects.contract_amount`(3,000만), 정산탭은 `projects.expected_amount`(NULL) — 이원화.
- **단일화**: 예외 프로젝트의 정산 기준 총액 = `contract_amount`(>0) 우선, 레거시 fallback `expected_amount`. 적용 지점: `projectPaySummary` 예외 분기·`RECEIVABLE_EXCEPTION_COND`·receivable()/receivableCount()·projects.index `$expectedExpr`·`recalcProjectSettlement` SELECT.
- 정산탭: '수정' 버튼·`expectedSave` 라우트 **제거**(고정 표시). 총액 수정은 프로젝트 수정 폼의 '계약금액'에서만. 프로젝트 폼의 별도 '예정 금액' 입력도 제거(이중 입력 해소, 컬럼은 legacy fallback 용도로 유지).
- **마이그레이션 백필**: 예외 & contract_amount=0 & expected_amount>0 → contract_amount=expected_amount + computeSplit로 supply/vat 동기 세팅. (참고: 수주액·예상 매출 등 공급가 집계에 예외 건이 반영되기 시작 — 실제 계약 총액이므로 의도된 정합.)
- 자동 '전액 입금 완료'(R13)는 그대로 — 기준값만 contract_amount로 바뀜.

## 4. 반기 실적 계약/공사 탭 + 용어 정리 (지시 #3, #4)
- `직원별 반기 실적`을 **탭 2개**로: 
  - **계약 실적**(담당영업 기준): 직원 | 계약금액(수주·공급가, 기존 `contractedAmountByUser` 재사용) | **매출금액(입금)** = 담당 계약+담당 예외 프로젝트의 순입금(현금·VAT포함, paid_date 기간) — 신규 집계.
  - **공사 실적**(배정·기여도 기준): 직원 | **담당 프로젝트 수**(`employeeProjectCountByUser` 재사용) | 기여도 반영 순이익 누적(`employeeConfirmedByUser`) | 보너스 지급(확정).
  - 기존 '입금(기여율 귀속)' 단독 컬럼은 폐지(계약탭 매출금액으로 대체) — 워딩 문제 해소.
- **용어 리네임**(화면 전수, 탐색으로 file:line 확보): 산정 대상 매출→**총매출**, 기여도 적용 매출/적용 매출→**기여도 반영 매출**, 적용 순이익→**기여도 반영 순이익**. 대상: halfyear/index.php:175, bonus/index.php(9곳+JS), bonus/history.php:14, projects/_tab_staff.php:75, 관련 컨트롤러 주석.
- **직원 성과 메뉴 제거**: Nav 항목만 제거(Nav.php:28). 라우트·페이지는 유지 — 직원 상세 '성과 보기'·대시보드 링크·qa_smoke가 참조.

## 5. 세션 (완료)
Claude Code `cleanupPeriodDays: 365` 적용 완료(~/.claude/settings.json). CRM 로그인 세션은 변경 없음.

## 6. QA·배포
- 회귀: 기존 스위트 + 신규 unit_r14(게이지 파생·자동 상태·계약총액 연동·메모 CRUD·반기 집계).
- 브라우저 실측: PC 1440/모바일 390 스크린샷(P4 인프라 재사용) — 보드 게이지 조작·즉시 반영·메모 팝업·정산탭·반기 탭.
- 마이그레이션: `database/cafe24/012_r14.sql`(신규 테이블 2 + 백필 2종) — dry-run 후 운영 적용.
- 배포: 로컬 QA 통과 → FTP 업로드 → 운영 재검증(사장 지시로 QC 통과 시 자동 진행).

## 7. 위험
- 보드 개편은 사용 습관 변화(드래그 소멸) — 게이지 백필로 현 상태 무손실 이전.
- `progress` 의미 변경(위치→게이지 평균)이 목록·상세 표시에 파급 — 표시 지점 점검 포함.
- 계약총액 백필로 공급가 집계(수주액·예상 매출) 증가 가능 — 의도된 정합이나 사장 안내 필요.
