# EDEN CRM 기능 QA 보고서 (T3 · 기능 QA 에이전트)

- **일시**: 2026-07-29
- **대상**: 로컬 개발 서버 `http://127.0.0.1:8080/index.php` (`admin`), 로컬 DB `eden_crm`
- **HEAD**: `61f7845 security: 보안 헤더 6종 + IP 기준 로그인 스로틀` (테스트 중 타 에이전트가 `1e9be75`, `61f7845` 를 커밋 — 아래 §5 참고)
- **방식**: 실제 HTTP 요청(쿠키·CSRF 유지)으로 전체 업무 흐름을 구동하고, **모든 금액·건수는 DB 값과 화면 값을 직접 대조**했다.
  브라우저(Chrome/puppeteer)로 데스크톱 1440×900 · 모바일 390×844 를 별도 검증했다.
- **테스트 코드**: `scripts/qa_final/` (테스트 전용. `app/`·`public/`·`database/` 미변경)
  - `lib.js` 공통 HTTP/DB 헬퍼 · `cleanup.js` 시드 정리
  - `m1` 로그인·직원·권한·고객 / `m2` 영업기회·견적·계약·입금 / `m3` 프로젝트·공정보드·일정
  - `m4` 지출·예외정산·대시보드·분석·휴지통·라우트 스모크 / `m5` 브라우저·모바일 / `m6` 추가 관찰
  - 실행: `node scripts/qa_final/run_all.js`
- **시드**: `QAFINAL-` 접두 데이터(고객 25 · 영업기회 3 · 견적 3 · 계약 1 · 프로젝트 3 · 일정 8 · 입금 8 · 비용 6 · 직원 1)를 직접 생성해 흐름을 구동한 뒤 **전량 삭제(§6 COUNT=0 확인)**.

---

## 1. 총계

| 구분 | 건수 |
|---|---|
| ✅ PASS | **277** |
| ❌ FAIL | **9** (실제 결함 8종 — F-1~F-8) |
| ⚠️ WARN | **3** |
| ℹ️ INFO(관측) | 8 |
| **합계** | **297** |

검증 범위: 라우트 GET 스모크 49개(전부 200, PHP 진단 1건 제외) · 상세 화면 7종 · 브라우저 화면 26종(데스크톱/모바일) ·
콘솔 오류 0건 · 4xx/5xx 비정상 응답 0건.

---

## 2. 실패·이상 항목 (재현 절차 포함)

### F-1 · 입금 중복 저장 방지 없음 — **높음(금액 왜곡)**
| | |
|---|---|
| 화면 | 계약 상세 → 입금 등록 |
| 엔드포인트 | `POST index.php?r=payments.save` |
| 재현 | 계약 상세에서 "입금 등록"을 열고 동일 값(구분=중도금, 금액=4,400,000, 입금일=2026-08-05)으로 **저장을 3회** 누른다(네트워크 지연 시 더블클릭·새로고침 재전송으로 실제 발생). |
| 실제 | `payments` 3행 생성 (ids=4463,4464,4465). 실측 응답 `{"ok":true,"data":{"id":4463}}` → `{"id":4464}` → `{"id":4465}`. 순입금이 4,400,000 → 13,200,000 으로 부풀고 계약 `payment_status` 가 조기에 `paid`(완납)로 바뀐다. 대시보드 "입금 총액"·리포트 "확정 매출"에 그대로 반영된다. |
| 기대 | 동일 (계약, 구분, 금액, 입금일) 조합의 중복 등록을 막거나 최소한 경고 후 확인을 받아야 한다. |
| 원인 | `ContractsController::savePayment()` 에 멱등키·중복검사·유니크 인덱스가 없다. 계약 총액을 넘는 입금도 차단하지 않는다. |

### F-2 · 지출(비용) 중복 저장 방지 없음 — **높음(원가·순이익 왜곡)**
| | |
|---|---|
| 화면 | 프로젝트 상세 → 비용·원가 탭 → 비용 등록 |
| 엔드포인트 | `POST index.php?r=costs.save` |
| 재현 | 동일 값(구분=운송비, 금액=300,000, 지출일=오늘)으로 저장을 3회 누른다. |
| 실제 | `costs` 3행 생성 (ids=1124,1125,1126) → `projects.actual_cost` 900,000 으로 집계. 대시보드 "원가 총액", 리포트 "확정 순이익", 보너스 산식이 모두 3배 원가로 계산된다. |
| 기대 | 중복 등록 차단 또는 확인 절차. |
| 원인 | `CostsController::save()` 에 중복검사 없음(F-1 과 동일 유형). |

### F-3 · `performance.index` 에서 PHP Deprecated 메시지가 화면에 출력됨 — **중간**
| | |
|---|---|
| 화면 | 분석 → 성과(직원 성과) |
| 엔드포인트 | `GET index.php?r=performance.index` |
| 재현 | 부서 미지정(`users.department_id IS NULL`) 직원이 1명 이상인 상태로 `?r=performance.index` 접속. (로컬 기본 데이터가 이미 이 상태) |
| 실제 응답 | HTTP 200, 본문 **맨 앞(`<!DOCTYPE html>` 이전)** 에 다음이 출력됨:<br>`<br /><b>Deprecated</b>:  Using null as an array offset is deprecated, use an empty string instead in <b>.../app/controllers/PerformanceController.php</b> on line <b>59</b><br />` |
| 영향 | ① 사용자에게 서버 파일 **절대경로가 노출**된다. ② doctype 앞 출력이라 브라우저가 quirks 모드로 렌더 → 모바일 390px 에서 이 화면만 가로 스크롤(문서 폭 411px)이 생긴다. ③ 헤더 전송 이후 출력이므로 이 시점 이후 리다이렉트가 필요한 코드 경로에서는 "headers already sent" 로 이어질 수 있다. |
| 원인 | `app/controllers/PerformanceController.php:59` — `$depMap[$u['department_id']] ?? '-'` 에서 `department_id` 가 NULL 일 때 `$array[null]` 접근(PHP 8.1+ Deprecated). |
| 참고 | 전 라우트 49개 스캔 중 **이 1건만** 검출됨. |

### F-4 · 고객 등록/수정 폼에서 담당 영업을 지정할 수 없음 — **중간(기능 사용 불가)**
| | |
|---|---|
| 화면 | 고객 CRM → 고객 등록/수정 |
| 재현 | `?r=customers.form` 접속 → "담당 영업" 셀렉트를 연다. |
| 실제 | 옵션이 `["=미지정"]` **하나뿐**. 활성 직원 5명이 있어도 아무도 선택할 수 없다. 계약 폼(`contracts.form`)·프로젝트 폼(`projects.form`)은 동일 상황에서 활성 직원 5명을 모두 노출한다. |
| 원인 | `CustomersController::salesUserOptions()` (673행 부근) 및 `PipelineController::salesUserOptions()` (492행 부근)가 `WHERE role_key = 'sales_manager'` 로만 조회한다. 현재 DB에 `sales_manager` 역할 사용자가 0명이라 목록이 빈다. 계약·프로젝트 컨트롤러는 전 활성 직원을 노출해 **동일 개념 필드가 화면마다 다른 모집단**을 쓴다. |
| 영향 | 고객에 담당 영업을 붙일 수 없으므로 담당자별 고객 필터·범위 축소(`Scope::customerWhere` 의 fallback)·성과 귀속이 무력화된다. |

### F-5 · 영업기회 폼에서 활성 계정이 "(비활성)" 으로 표시됨 — **낮음(오표기)**
| | |
|---|---|
| 화면 | 영업 파이프라인 → 영업기회 등록 |
| 재현 | `admin`(김덕수, `status='active'`, super_admin)으로 `?r=pipeline.form` 접속 → "담당 영업" 셀렉트 확인. |
| 실제 | `["=미지정", "1=김덕수 (비활성)"]` — 재직 중인 계정에 "(비활성)" 이 붙는다. |
| 원인 | `PipelineController::salesUserOptions($includeId)` 가 `sales_manager` 목록에 없는 id 를 보강할 때 상태 확인 없이 `$extra['name'] .= ' (비활성)'` 를 붙인다(`CustomersController` 도 동일 코드). |

### F-6 · 시스템 설정 입력값 검증 부재 — **높음(자기 잠금·금액 오류)**
| | |
|---|---|
| 화면 | 관리 → 시스템 설정 |
| 엔드포인트 | `POST index.php?r=settings.save` |
| 재현/실제 (전부 실측) | ① `page_size = 0` 저장됨 → 고객 목록이 **DB 24건인데 화면 0건**(HTTP 200, 빈 목록). 데이터가 사라진 것처럼 보인다.<br>② `vat_rate = abc` 저장됨 → 이후 등록한 견적이 **소계 1,000,000 / VAT 0원 / 합계 1,000,000** 으로 저장된다(정상: VAT 100,000).<br>③ `login_max_attempts = -1` 저장됨 → 첫 로그인 실패에 즉시 계정 잠금.<br>④ `session_idle_min = 0` 저장됨 → **저장 즉시 본인 포함 전원이 매 요청마다 자동 로그아웃**되어 설정 화면으로 되돌아갈 수 없다(실측: 원복 시도 요청이 로그인 리다이렉트로 실패 → DB 직접 수정으로 복구). |
| 원인 | `SettingsController::save()` 는 POST 값을 `trim()` 만 하고 그대로 `UPDATE settings` 한다(범위·형식 검증 없음). |
| 기대 | 숫자 키에 정수/범위(예: `page_size 5~200`, `vat_rate 0~100`, `session_idle_min 5~1440`, `login_max_attempts 1~20`) 검증. |

### F-7 · 모바일 390×844 가로 스크롤 4개 화면 — **중간(모바일 사용성)**
실측 `document.scrollWidth` vs `clientWidth(390)`:

| 화면 | scrollWidth | 넘치는 요소 | 원인 |
|---|---|---|---|
| 계약 등록/수정 (`contracts.form`) | **434** | `h2` (405px) in `div.st` — "대금 지급 계획 — 계약금·중도금·잔금 (저장 시 입금 예정행 자동 동기화)" | `app.css:356` `.section-head h2{white-space:nowrap}` — 긴 제목이 줄바꿈되지 않는다 |
| 리포트 (`reports.index`) | **654** | `div.card` (642px) in `div.grid-2` (트랙 계산폭 642px) | `app.css:242` `.grid-2{grid-template-columns:1fr 1fr}` — `1fr` = `minmax(auto,1fr)` 이라 넓은 표(`table.data` min-content 640px)가 그리드 트랙을 밀어내는 **그리드 블로우아웃**. `.dash-duo`(`app.css:689`)는 같은 문제를 `minmax(0,1fr)` 로 이미 해결해 두었다 |
| 반기 현황 (`halfyear.index`) | **417** | `div.card` (404px) in `div.hy-split` (트랙 404.375px) | 위와 동일(그리드 트랙 min-content 하한) |
| 성과 (`performance.index`) | **411** | `b` (411px) — PHP Deprecated 메시지의 파일 절대경로 | **F-3 과 동일 원인** |

재현: Chrome DevTools iPhone 12/13(390×844) 또는 `node scripts/qa_final/m5_browser_mobile.js`.
나머지 22개 화면(대시보드·고객·견적·계약 상세·프로젝트·공정 보드·일정·설정·감사·휴지통 등)은 가로 스크롤 없음 —
목록 표는 `.table-wrap{overflow-x:auto}` 로 정상 처리된다(실측: 고객 목록 표 804px가 뷰포트 내부 스크롤).

### F-8 · 휴지통 감사 로그 액션명·엔티티 규약 불일치 — **낮음(감사 추적성)**
| | |
|---|---|
| 실측 | `audit_logs` 실제 기록: `trash_purge/trash_restore` + entity `customers`·`leads`·`contracts` (고객·영업기회·계약) vs `project_purge`/`project_restore` + entity **단수** `project` (프로젝트) vs `quote_restore` + entity `quotes` (견적) |
| 영향 | 감사 로그 화면·조회에서 "휴지통 완전삭제 전체"를 단일 조건(`action='trash_purge'`)으로 뽑으면 **프로젝트·견적 이력이 누락**된다. |
| 코드 | `ProjectsController.php:833,851` (`project_purge`/`project_restore`, entity `'project'`) vs `CustomersController::purge()`(`trash_purge`, entity `'customers'`) |

---

## 3. 경고(WARN)

| 항목 | 관측값 | 비고 |
|---|---|---|
| 공정 보드 KPI 툴팁 단계 수 | 툴팁 "실공정(1~**19**단계)" vs 카드 게이지 실제 **17**단계 | `Stages::processStagePositions()` 는 `common`(하자보수 18, 전체완료 19)을 포함해 세고, 카드 게이지는 `ProcessService::gaugeStages()`(유형 전용 17개)만 표시한다. **카드에 찍히는 번호 자체는 정확**(§4 참조) — 툴팁 분모만 다르다. |
| 상세 화면 404/403 응답 코드 불일치 | 존재하지 않는 id → `projects.show` = **403**, `customers.show` = **404** | `ProjectsController::show()` 는 미존재/권한없음을 모두 403 으로 합친다(열거 방지 의도로 보이나 다른 화면과 규약이 다르다). 500 은 발생하지 않음. |
| 모바일 터치 타겟 32px 미만 | 대시보드 13개(`a.section-link` 61×19 "전체 일정 →" 등), 고객 목록 20개(행 링크 98×16), 견적 목록 3개, 고객 등록폼 2개(체크박스 13×13), 영업기회 1개(`button.pf-enter` 1×1) | Apple HIG 44×44 / Material 48×48 권장 대비 작다. 특히 `button.pf-enter` 는 1×1px 로 사실상 탭 불가. |

---

## 4. 요구 항목별 확인 결과 요약

| # | 요구 항목 | 결과 |
|---|---|---|
| 1 | 로그인·직원 | ✅ 로그인/로그아웃/오답·미존재 계정 차단, 비로그인 리다이렉트, CSRF 403, POST-only 405, 최초 로그인 비밀번호 강제 변경, **5회 실패 계정 잠금**(+ 신설 IP 스로틀 20회/10분 동작 확인), 직원 등록/수정/비활성화/재활성화, **중복 아이디·중복 이메일 거부**, 비활성화 시 기존 세션 즉시 무효, super_admin 역할 강등·비활성화 거부, **권한 저장·수정·회수가 같은 세션에서 즉시 반영**(등록폼 200↔403 전환 실측) |
| 2 | 고객 CRM | ✅ 등록 25건/수정/상세/검색(이름·전화)/상태·출처 필터/페이지네이션(20+5=25, "총 25명")/활동 등록/중복검사 API/CSV/삭제→휴지통→복구→완전삭제/감사로그/중복요청 안전. ❌ 담당 영업 지정 불가(F-4) |
| 3 | 영업기회 | ✅ 등록/수정/단계변경(`stage_entered_at` 갱신)/예상이익 자동계산(매출−원가)/검색/삭제·휴지통·복구·완전삭제/고객 미선택 거부. ❌ 담당자 라벨 오표기(F-5) |
| 4 | 견적 | ✅ **해당 고객의 영업기회만 노출**(AJAX `quotes.leads` + 브라우저 실측: 고객 A 선택 시 2건, 고객 B 선택 시 0건 / 타 고객 `lead_id` 로 강제 POST 시 저장 거부), 버전 관리(v1 보존 + `current_version_id` 이동), 항목 계산(면적×수량×단가+원가 5종), 소계/VAT/할인/합계, 화면=DB 일치, 견적번호 자동채번, 인쇄 뷰 |
| 5 | 계약 | ✅ 견적→계약 전환(원견적액·조정액 스냅샷), 담당 영업 자동연결, 계약금/중도금/잔금 **퍼센트↔금액 정합 강제**(합계≠100 거부, 금액 불일치 거부), 공급가+VAT=총액, 납부계획 자동 생성, 상태, 입금총액, 완납 자동처리, 확정매출 반영, 동일 견적 중복계약 차단, 전환된 견적 삭제 차단 |
| 6 | 입금·지출 | ✅ 부분입금 `partial`→전액 `paid` 자동 전환, 입금 취소 후 재계산, 0원·음수 거부, 환불 상한, 예외 프로젝트 직접 입금·정산(완납 시 자동 `settled`), 비용 자동계산(자재=수량×단가, 인건비=일수×일당)·조정사유 강제·취소는 상태전환. ❌ **중복 저장 방지 없음(F-1, F-2)** |
| 7 | 프로젝트 | ✅ 계약 `active` 전환 시 자동생성(금액·공정 대기중·이력), 재요청 idempotent, 예외 프로젝트(사유 필수·super_admin), 수정, 상태 전이 규칙·사유 필수 검증, 상태이력, 삭제/휴지통/복구/완전삭제 + 참조 있으면 완전삭제 차단 |
| 8 | 공정 보드 | ✅ **화면 표시 단계 번호 = DB 실제 순번 완전 일치**(도장 17단계 1:1 대조, `sort_order` 와도 일치), 진행률 슬라이더(브라우저 실측 45% 입력 → DB `project_stage_progress.pct=45`), 전체 진행률=단계 평균, 현재공정=pct>0 최후방, 100% 클램프, 타 유형 공정 거부, 미완료 시 완료확인 거부, 전 공정 100% → 완료 + 전체완료 자동 이동, 그룹 이동, 상태이력, **권한 없는 직원 차단**(읽기만=보드 200/변경 403, 무권한=403), 중복요청 안전. ⚠️ 툴팁 분모(§3) |
| 9 | 일정 | ✅ 등록/수정/삭제/이동(기간 길이 보존), 기간일정, **직원 중복배정 감지**(저장 없이 `conflict:true` 반환 → `confirmed=1` 로 강제 저장), 오전 09–12·오후 13–18·야간 18–22 시간 매핑, 종일=3슬롯, 프로젝트 연동, 참여자·시간대 미선택 거부, 종료일<시작일 거부, 출근 분석 반영 |
| 10 | 대시보드 | ✅ 입금 총액·원가 총액이 DB 집계와 **원 단위 일치**(11,300,000 / 1,300,000), **삭제 데이터 제외**(예외 프로젝트 휴지통 이동 → 11,300,000 → 3,300,000) 및 **복구 시 재반영**(→ 11,300,000), 최근 입금·지출 위젯, 프로젝트·공정 현황, 기간(당월) 필터, **권한별 위젯**(일반직원에게 확정매출·원가·입금 KPI 미노출, 제목 "내 대시보드") |
| 11 | 분석 | ✅ 리포트 확정매출 10,272,727 = DB 재계산 10,272,727, 확정순이익 8,972,727 = 매출−원가, **월별 추이 그래프 값 = 확정매출 일치**, 신규 고객 24=24, 견적 전환율 분모 2=2. 접근 통제: 일반직원 403 → 분석 권한 부여 시 200, **출근 통계는 분석 권한이 있어도 사장 전용 403 유지** |
| 12 | 휴지통 | ✅ 5개 리소스(고객·영업기회·견적·계약·프로젝트) 목록/복원/완전삭제/`data-purge` 2단계 확인 UI/감사로그/중복요청 안전, 참조 있으면 완전삭제 차단(고객·견적·계약·프로젝트 각각 실측), **삭제 권한만 있는 직원은 목록·복원·완전삭제 전부 403**(5×3=15케이스). ❌ 감사 액션명 규약(F-8) |
| 13 | 모바일 | ✅ 26개 화면 중 22개 가로 스크롤 없음, 콘솔 오류 0건, 사이드바 오프캔버스(`left:-236px`) 정상, 목록 표 내부 가로 스크롤, 폼 입력·제출 동작, 공정 보드 카드 폭 정상. ❌ 4개 화면 가로 스크롤(F-7) ⚠️ 터치 타겟(§3) |

---

## 5. 테스트 중 타 에이전트가 수정한 항목

| 항목 | 초기 실측(FAIL) | 현재 HEAD |
|---|---|---|
| 견적 할인 음수 검증 | `discount=-1,000,000` 저장 → 소계 1,000,000 + VAT 100,000 인데 **합계 2,100,000** 으로 부풀림 | ✅ 해결 — `QuotesController::computeTotals()` 에 `max(0.0,$discount)` + `min($discount, $subtotal+$vat)` 상한 추가(커밋 `1e9be75`). 재검증 시 `discount=0` 저장 확인 |
| 로그인 IP 스로틀 | 없음 | 신설(커밋 `61f7845`, 20회/10분). QA 반복 실행 시 계정 잠금 검증보다 먼저 걸리므로 테스트 전 `login_attempts` 정리 필요 |

---

## 6. QAFINAL 데이터 정리 확인

`node scripts/qa_final/cleanup.js` 실행 후:

| 테이블 | `QAFINAL-` 잔재 |
|---|---|
| customers | 0 |
| leads | 0 |
| quotes | 0 |
| quote_items | 0 |
| contracts | 0 |
| projects | 0 |
| payments | 0 |
| costs | 0 |
| schedules | 0 |
| users (`qafinal_%`) | 0 |
| employee_permissions | 0 |
| customer_activities | 0 |
| project_memos | 0 |

환경 원복 확인: `settings` = `page_size 20 / vat_rate 10 / session_idle_min 60 / login_max_attempts 5`,
`users` 전원 `failed_attempts=0 / locked_until=NULL`.
(잔존 `customers` 1건은 다른 에이전트의 `QASEC-` 보안 테스트 데이터로 본 QA 범위 밖이다.)

---

## 7. 항목별 전체 결과표

아래는 실제로 실행해 얻은 297개 검증 항목 전량이다. 근거란의 수치는 모두 실측값이다.

### 1.로그인·세션  <sub>PASS 9 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 관리자 로그인 성공(302 redirect) | ✅ PASS | HTTP 302 → http://127.0.0.1:8080/index.php?r=home |
| 로그인 후 대시보드 200 | ✅ PASS | HTTP 200 |
| 대시보드에 사용자명 노출 | ✅ PASS | 대시보드 · EDEN CRM EDEN 도장 CRM 대시보드 영업 고객 CRM 영업 파이프라인 견적 계약 현장 프로젝트 공정 보드 일정 분석 리포트 반기 보너스 지급 현황 관리 직원 관리 목표 관리 시스템 설정 감사 |
| 오답 비밀번호 로그인 차단 | ✅ PASS | dashboard → HTTP 302 http://127.0.0.1:8080/index.php?r=login |
| 미존재 계정 로그인 차단 | ✅ PASS | HTTP 302 http://127.0.0.1:8080/index.php?r=login |
| 비로그인 보호 라우트 → 로그인 리다이렉트 | ✅ PASS | HTTP 302 http://127.0.0.1:8080/index.php?r=login |
| CSRF 토큰 없는 POST 403 차단 | ✅ PASS | HTTP 403 |
| POST 전용 라우트 GET 접근 405 | ✅ PASS | HTTP 405 |
| 로그아웃 후 세션 무효 | ✅ PASS | HTTP 302 http://127.0.0.1:8080/index.php?r=login |

### 2.직원·권한  <sub>PASS 24 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 직원 목록 200 | ✅ PASS | HTTP 200 |
| 직원 등록 성공 | ✅ PASS | HTTP 200, id=307, name=QAFINAL-직원A |
| 신규 직원 must_change_password=1 | ✅ PASS | must_change_password=1 |
| 중복 아이디 등록 거부 | ✅ PASS | HTTP 422, users(login_id=qafinal_emp)=1, body=요청을 처리할 수 없습니다 · EDEN CRM EDEN 도장 CRM 대시보드 영업 고객 CRM 영업 파이프라인 견적 계약 현장 프로젝트 공정 보드 일정 분석 리포트 반기 보너스 지급 현황 관리 직원 관리 목표 관리 시스템 설정 감사 로그 요청을 처리할 수 없습니다 김 |
| 중복 이메일 등록 거부 | ✅ PASS | HTTP 422, count=0 |
| 권한 매트릭스 저장(읽기 2건) | ✅ PASS | [{"resource_key":"sales.customers","can_read":"1","can_write":"0","can_delete":"0"},{"resource_key":"sales.leads","can_read":"1","can_write":"0","can_delete":"0"}] |
| 최초 로그인 시 비밀번호 변경 강제 | ✅ PASS | HTTP 302 http://127.0.0.1:8080/index.php?r=password.change |
| 비밀번호 변경 완료 → 강제 해제 | ✅ PASS | HTTP 302, must_change_password=0 |
| 직원(읽기 권한) 고객 목록 200 | ✅ PASS | HTTP 200 |
| 직원(쓰기 없음) 고객 등록폼 403 차단 | ✅ PASS | HTTP 403 |
| 직원(견적 권한 없음) 견적 목록 403 차단 | ✅ PASS | HTTP 403 |
| 권한 부여 후 같은 세션 즉시 반영(등록폼 200) | ✅ PASS | HTTP 200 |
| 권한 회수 후 즉시 차단(등록폼 403) | ✅ PASS | HTTP 403 |
| 권한 회수 후 목록도 차단(403) | ✅ PASS | HTTP 403 |
| 일반직원 직원관리 403 | ✅ PASS | HTTP 403 |
| 일반직원 리포트 403 | ✅ PASS | HTTP 403 |
| 일반직원 감사로그 403 | ✅ PASS | HTTP 403 |
| 일반직원 고객 휴지통 403 | ✅ PASS | HTTP 403 |
| 직원 정보 수정 반영 | ✅ PASS | name=QAFINAL-직원A수정 |
| 직원 비활성화 | ✅ PASS | HTTP 200, status=inactive |
| 비활성 계정 로그인 차단 | ✅ PASS | HTTP 302 http://127.0.0.1:8080/index.php?r=login |
| 비활성화 시 기존 세션 즉시 무효 | ✅ PASS | HTTP 302 http://127.0.0.1:8080/index.php?r=login |
| 직원 재활성화 | ✅ PASS | status=active |
| 최고운영자 역할 강등 거부 | ✅ PASS | HTTP 403, role_key=super_admin, msg=요청을 처리할 수 없습니다 · EDEN CRM EDEN 도장 CRM 대시보드 영업 고객 CRM 영업 파이프라인 견적 계약 현장 프로젝트 공정 보드 일정 분석 리포트 반기 보너스 지급 현황 관리 직원 관리 목표 관리 |

### 3.고객CRM  <sub>PASS 25 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 고객명 미입력 저장 거부(422) | ✅ PASS | HTTP 422, 요청을 처리할 수 없습니다 · EDEN CRM EDEN 도장 CRM 대시보드 영업 고객 CRM 영업 파이프라인 견적 계약 현장 프로젝트 공정 보드 일정 분석 리포트 반기 보너스 지급 현황 관리 직원 관리 목표 관리 |
| 개인정보 동의 없이 저장 거부 | ✅ PASS | HTTP 422, count=0 |
| 고객 25건 등록 | ✅ PASS | count=25 |
| 고객 검색(q=QAFINAL-) 결과 노출 | ✅ PASS | 1페이지 표시 20건, page_size=20 |
| 페이지네이션 1페이지 20건 제한 | ✅ PASS | 표시=20, page_size=20 |
| 2페이지 나머지 노출 | ✅ PASS | 2페이지=5 (기대 5) |
| 총 건수 25 표기 | ✅ PASS | 총 25명 |
| 상태 필터(inactive) 정확 | ✅ PASS | 표시=1 (DB inactive=1) |
| 출처 필터 정확 | ✅ PASS | 표시=12, DB=12 |
| 전화번호 부분검색 | ✅ PASS | QAFINAL-고객07 |
| 고객 상세 200 + 값 일치 | ✅ PASS | HTTP 200 |
| 고객 수정 반영 | ✅ PASS | {"name":"QAFINAL-고객01수정","phone":"010-9000-9999"} |
| 중복 고객 검사 API | ✅ PASS | {"ok":true,"data":{"candidates":[{"id":1749,"name":"QAFINAL-고객02","company_name":null,"phone":"010-9000-1002","email":"qafinal2@example.com","biz_reg_no":null}]}} |
| 고객 활동 등록 | ✅ PASS | HTTP 302, activities=1 |
| 고객 삭제 → 소프트삭제 | ✅ PASS | HTTP 302, deleted_at=2026-07-29 09:31:41 |
| 삭제 고객 일반 목록 제외 | ✅ PASS | 목록에서 미노출 |
| 삭제 고객 상세 404 | ✅ PASS | HTTP 404 |
| 휴지통 목록 200 + 삭제건 노출 | ✅ PASS | HTTP 200 |
| 휴지통 복구 | ✅ PASS | HTTP 302, deleted_at=NULL |
| 이미 복구된 건 재복구 요청 안전 처리 | ✅ PASS | HTTP 302, msg= |
| 완전삭제(참조 없음) 성공 | ✅ PASS | HTTP 302, rows=0 |
| 완전삭제 감사로그 기록 | ✅ PASS | audit rows=1 |
| 완전삭제 중복요청 안전 처리 | ✅ PASS | HTTP 302 |
| 미삭제 고객 완전삭제 거부 | ✅ PASS | HTTP 302, 존재유지 |
| 고객 CSV 내보내기 | ✅ PASS | HTTP 200, bytes=2946, content-type=text/csv; charset=utf-8 |

### 4.영업기회  <sub>PASS 14 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 영업기회 목록 200 | ✅ PASS | HTTP 200 |
| 영업기회 등록 | ✅ PASS | HTTP 302, id=449, loc=http://127.0.0.1:8080/index.php?r=pipeline.show&id=449 |
| 예상이익 자동계산(매출-원가) | ✅ PASS | {"expected_amount":"50000000","expected_cost":"30000000","expected_profit":"20000000","win_probability":"60.00","stage_id":"4"} |
| 영업기회 3건 생성 | ✅ PASS | lead1=449 lead2=450 other=451 |
| 고객 미선택 저장 거부 | ✅ PASS | HTTP 422 |
| 영업기회 수정 + 단계 변경 반영 | ✅ PASS | {"stage_id":"6","work_type":"QAFINAL-외벽도장수정","expected_amount":"55000000","expected_profit":"25000000","stage_entered_at":"2026-07-29 09:31:43"} |
| 단계 변경 시 stage_entered_at 갱신 | ✅ PASS | before=2026-07-29 09:31:42 after=2026-07-29 09:31:43 |
| 영업기회 상세 200 | ✅ PASS | HTTP 200 |
| 영업기회 검색 필터 | ✅ PASS | 검색 결과 확인 |
| 영업기회 삭제(소프트) | ✅ PASS | HTTP 302 |
| 영업기회 휴지통 목록 | ✅ PASS | HTTP 200 |
| 영업기회 복구 | ✅ PASS | deleted_at=NULL |
| 영업기회 완전삭제 | ✅ PASS | HTTP 302 |
| 영업기회 폼 담당영업 목록 | ✅ PASS | 옵션 1개 |

### 5.견적  <sub>PASS 16 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 견적폼 영업기회 AJAX — 해당 고객 것만 노출 | ✅ PASS | customer 1748 → leads=[450,449] (기대 [450,449]) |
| 다른 고객 영업기회는 미노출 | ✅ PASS | customer 1749 → leads=[] |
| 타 고객 영업기회 연결 견적 저장 거부 | ✅ PASS | HTTP 302, rows=0, loc=http://127.0.0.1:8080/index.php?r=quotes.form |
| 견적 항목 0개 저장 거부 | ✅ PASS | loc=http://127.0.0.1:8080/index.php?r=quotes.form |
| 견적 등록 | ✅ PASS | HTTP 302, id=417, loc=http://127.0.0.1:8080/index.php?r=quotes.show&id=417 |
| 항목별 금액 계산(면적×수량×단가+원가) | ✅ PASS | DB=9000000,3500000,1300000 기대=9000000,3500000,1300000 |
| 견적 소계/VAT/할인/합계 계산 | ✅ PASS | DB subtotal=13800000 vat=1380000 discount=500000 total=14680000 / 기대 13800000/1380000/14680000 |
| 견적번호 자동채번 | ✅ PASS | Q20260729-001 |
| 견적 상세 화면 금액 = DB 금액 | ✅ PASS | 화면에 소계 13,800,000=true, VAT 1,380,000=true, 합계 14,680,000=true |
| 견적 수정 시 새 버전 생성(v1 보존) | ✅ PASS | [{"id":"239","version_no":"1","subtotal":"13800000","vat":"1380000","total_amount":"14680000"},{"id":"240","version_no":"2","subtotal":"10000000","vat":"1000000","total_amount":"11000000"}] |
| current_version_id = 최신 버전 | ✅ PASS | current=240, v2=240 |
| v2 금액 재계산 | ✅ PASS | v2 subtotal=10000000 total=11000000 / 기대 10000000/11000000 |
| 견적 상태 변경(sent) | ✅ PASS | sent |
| 견적 할인 음수 입력 방어 | ✅ PASS | {"subtotal":"1000000","vat":"100000","discount":"0","total_amount":"1100000"} |
| 견적 인쇄 뷰 200 | ✅ PASS | HTTP 200 |
| 견적 목록 200 + 노출 | ✅ PASS | HTTP 200 |

### 6.계약  <sub>PASS 14 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 견적→계약 자동입력 AJAX | ✅ PASS | {"ok":true,"data":{"quote_id":417,"quote_no":"Q20260729-001","customer_id":1748,"customer_name":"QAFINAL-고객01수정","work_name":"QAFINAL-고객01수정 QAFINAL-외벽도장수정","site_address":null,"work_type":"QAFINAL-외벽도장수정","supply_amount":10000000,"vat_amount":100000 |
| 비율 합계 100 아님 → 저장 거부 | ✅ PASS | HTTP 302, loc=http://127.0.0.1:8080/index.php?r=contracts.form |
| 분할금액-비율 불일치 → 저장 거부 | ✅ PASS | HTTP 302 |
| 견적→계약 전환 등록 | ✅ PASS | HTTP 302, id=2468, loc=http://127.0.0.1:8080/index.php?r=contracts.show&id=2468 |
| 계약번호 자동채번 | ✅ PASS | C-20260729-001 |
| 계약 분할금 계산(30/40/30) | ✅ PASS | 3300000+4400000+3300000=11000000 / 총액 11000000 |
| 공급가+VAT = 계약총액 | ✅ PASS | supply=10000000 vat=1000000 total=11000000 |
| 견적 스냅샷 저장(원견적금액) | ✅ PASS | original=11000000 adjust=0 quoteTotal=11000000 |
| 담당 영업 자동연결 | ✅ PASS | sales_user_id=1 |
| 계약 저장 시 납부계획(pending) 3건 생성 | ✅ PASS | [{"pay_type":"down","amount":"3300000","due_date":"2026-07-05","status":"pending","kind":"payment"},{"pay_type":"middle","amount":"4400000","due_date":"2026-08-05","status":"pending","kind":"payment"},{"pay_type":"balance","amount":"3300000","due_date":"2026-09-05","status":"pending","kind":"payment"}] |
| 납부계획 금액 = 분할금 | ✅ PASS | [["down","3300000"],["middle","4400000"],["balance","3300000"]] |
| 계약 상세 화면 금액 = DB | ✅ PASS | 총액 1,100만:true, 계약금 330만:true, 잔금 330만:true |
| 동일 견적 중복 계약 차단 | ✅ PASS | HTTP 302 |
| 계약 전환 견적 삭제 차단 | ✅ PASS | HTTP 400, 요청을 처리할 수 없습니다 · EDEN CRM EDEN 도장 CRM 대시보드 영업 고객 CRM 영업 파이프라인 견적 계약 현장 프로젝트 공정 보드 일정 분석 리포트 반기 보너스 지급 현황 관리 직원 관리 목표 관리 |

### 7.입금  <sub>PASS 9 · FAIL 1 · WARN 0 · INFO 1</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 계약금 입금 등록 | ✅ PASS | {"ok":true,"data":{"id":4460}} |
| 부분입금 → payment_status=partial | ✅ PASS | payment_status=partial, netPaid=3300000 |
| 입금 중복 저장 방지 | ❌ FAIL | 동일 내용(contract=2468, pay_type=middle, amount=4400000, paid_date=2026-08-05) 3회 POST → payments 3행 생성 (ids=4463,4464,4465). 중복 방지 장치 없음 |
| 중도금 입금 후 납부계획 상태 | ℹ️ INFO | [{"id":"4461","status":"pending","amount":"4400000"},{"id":"4463","status":"paid","amount":"4400000"}] |
| 계약 상세 입금총액 = DB 합계 | ✅ PASS | DB netPaid=7700000 → 화면 표기 770만 존재=true |
| 전액 입금 → 완납(paid) 자동 처리 | ✅ PASS | payment_status=paid, netPaid=11000000, 계약총액=11000000 |
| 입금 취소 → 상태 재계산 | ✅ PASS | payment.status=cancelled, contract.payment_status=partial |
| 입금 취소 중복요청 차단 | ✅ PASS | {"ok":false,"error":"이미 취소된 입금 내역입니다."} |
| 0원 입금 거부 | ✅ PASS | {"ok":false,"error":"입금액을 올바르게 입력하세요."} |
| 음수 입금 거부 | ✅ PASS | {"ok":false,"error":"입금액을 올바르게 입력하세요."} |
| 재입금 후 완납 복귀 | ✅ PASS | payment_status=paid |

### 8.프로젝트  <sub>PASS 17 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 계약 진행 전환 → 프로젝트 자동생성 | ✅ PASS | HTTP 302, project=3001, loc=http://127.0.0.1:8080/index.php?r=contracts.show&id=2468 |
| 자동생성 프로젝트 계약금액 연동 | ✅ PASS | {"project_no":"P2026-0001","name":"QAFINAL-계약1","customer_id":"1748","contract_amount":"11000000","supply_amount":"10000000","vat_amount":"1000000","status":"in_progress","process_stage_id":"20","is_exception":"0","sales_user_id":"1","construction_type":"painting"} |
| 자동생성 프로젝트 번호/상태/유형 | ✅ PASS | {"project_no":"P2026-0001","name":"QAFINAL-계약1","customer_id":"1748","contract_amount":"11000000","supply_amount":"10000000","vat_amount":"1000000","status":"in_progress","process_stage_id":"20","is_exception":"0","sales_user_id":"1","construction_type":"painting"} |
| 자동생성 시 공정=대기중 + 이력 기록 | ✅ PASS | process_stage_id=20(대기중=20), history=1 |
| 프로젝트 상태이력 생성 | ✅ PASS | status_history=1 |
| 계약→프로젝트 중복 전환 요청 안전(기존 재사용) | ✅ PASS | {"ok":true,"data":{"id":3001,"created":false}} |
| 프로젝트 목록 200 + 노출 | ✅ PASS | HTTP 200 |
| 프로젝트 상세 200 + 금액 일치 | ✅ PASS | HTTP 200, 계약액 1,100만 |
| 프로젝트 수정 반영 | ✅ PASS | {"name":"QAFINAL-프로젝트1수정","site_address":"서울 강남구 A-2","estimated_cost":"6000000","site_manager_id":"2"} |
| 예외 프로젝트 생성 사유 미입력 거부 | ✅ PASS | HTTP 302, loc=http://127.0.0.1:8080/index.php?r=projects.form |
| 예외 프로젝트 생성 | ✅ PASS | id=3002, {"is_exception":"1","contract_id":"NULL","status":"in_progress","construction_type":"interior","supply_amount":"7272727","vat_amount":"727273","contract_amount":"8000000"} |
| 예외 프로젝트 공급가/VAT 자동분해 | ✅ PASS | supply=7272727+vat=727273=8000000 / total=8000000 |
| 허용되지 않은 상태 전이 거부 | ✅ PASS | {"ok":false,"error":"허용되지 않는 상태 전환입니다: 진행 중 → 정산 완료"} |
| 상태 전이(진행중→일시중단) | ✅ PASS | {"ok":true,"data":{"id":3001,"status":"paused"}} |
| 상태 전이 이력 기록 | ✅ PASS | {"from_status":"in_progress","to_status":"paused","reason":"NULL"} |
| 파기 전이 사유 필수 검증 | ✅ PASS | {"ok":false,"error":"이 전환은 처리 사유가 필요합니다."} |
| 상태 복귀(일시중단→진행중) | ✅ PASS | in_progress |

### 9.공정보드  <sub>PASS 26 · FAIL 0 · WARN 1 · INFO 1</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 공정 보드 200 | ✅ PASS | HTTP 200 |
| 보드에 프로젝트 카드 노출 | ✅ PASS | project_no=P2026-0001, data-project-id=3001 카드 존재=true |
| 보드 상태 그룹 4종 렌더 | ✅ PASS | 발견=대기중,진행 중,하자보수,종결 |
| 보드 그룹 구성 | ℹ️ INFO | 화면 그룹=대기중/진행 중/하자보수/종결 (요구사항의 대기중/착공준비/시공/완료/전체완료 는 카드 내부 공정 그룹으로 존재) |
| 공정 단계 번호 = DB 실제 순번(도장 17단계) | ✅ PASS | DB 17단계 / 화면 17단계 전부 일치 (1.현장실측, 2.도면작성, 3.자재발주, 4.착공준비 …) |
| 보드 KPI 툴팁 총 단계 수 불일치 | ⚠️ WARN | 툴팁 "1~19단계" vs 카드 게이지 실제 17단계 — 툴팁은 common(하자보수/전체완료) 포함 계산(Stages::processStagePositions) |
| 공정 진행률 설정(1단계 100%) | ✅ PASS | {"pct":100,"progress":6,"status":"in_progress","status_label":"진행 중","badge_class":"badge-info","group":"active","current_stage_id":1,"current_stage_name":"현장실측","current_stage_color":"#94a3b8","all_d |
| 진행률 DB 저장 | ✅ PASS | project_stage_progress.pct=100 |
| 전체 진행률 = 단계 평균 | ✅ PASS | projects.progress=6, 기대=round(100/17)=6 |
| 진행률 재계산(2단계 50% 추가) | ✅ PASS | progress=9, 기대=9 |
| 현재 공정 = pct>0 최후방 단계 | ✅ PASS | process_stage_id=2, 기대=2(도면작성) |
| 진행률 100 초과 클램프 | ✅ PASS | 100 |
| 타 공사유형 공정 설정 거부 | ✅ PASS | {"ok":false,"error":"이 프로젝트 유형의 공정이 아닙니다."} |
| 전 공정 100% 아님 → 완료확인 거부 | ✅ PASS | {"ok":false,"error":"아직 100%가 아닌 공정이 있어 완료 처리할 수 없습니다: 도면작성"} |
| 전 공정 100% → all_done 플래그 | ✅ PASS | all_done=true |
| 완료 확인 → status=completed | ✅ PASS | status=completed, {"status":"completed","status_label":"완료","badge_class":"badge-ok","group":"done","summary":{"total":1,"active":0,"waiting":0,"stages":0,"delayed":0," |
| 완료 시 전체완료 공정으로 자동 이동 | ✅ PASS | process_stage_id=19, full_complete=19 |
| 완료 중복요청 안전 처리 | ✅ PASS | {"status":"completed","status_label":"완료","badge_class":"badge-ok","group":"done","summary":{"total":1,"active":0,"waiti |
| 그룹 이동(종결→하자보수) | ✅ PASS | status=warranty |
| 그룹 이동(하자보수→종결) | ✅ PASS | status=completed |
| 잘못된 그룹 값 거부 | ✅ PASS | {"ok":false,"error":"잘못된 그룹입니다."} |
| 공정 이력 조회 | ✅ PASS | history rows=21 |
| 공정 이력 사유 수정 | ✅ PASS | reason=QAFINAL-이력사유수정 |
| 공정 읽기 권한 직원 보드 접근 200 | ✅ PASS | HTTP 200 |
| 공정 쓰기 권한 없는 직원 진행률 변경 차단 | ✅ PASS | HTTP 403, {"ok":false,"error":"이 작업을 수행할 권한이 없습니다."} |
| 공정 권한 없는 직원 보드 403 | ✅ PASS | HTTP 403 |
| 공사 유형 변경(인테리어→도장) | ✅ PASS | {"ok":true,"construction_type":"painting","moved_to_waiting":false} |
| 잘못된 공사 유형 거부 | ✅ PASS | {"ok":false,"error":"잘못된 요청입니다."} |

### 10.일정  <sub>PASS 22 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 일정 화면 200 | ✅ PASS | HTTP 200 |
| 일정 등록(오전) | ✅ PASS | {"ok":true,"data":{"id":308,"conflict":false}} |
| 오전 슬롯 시간 매핑(09:00~12:00) | ✅ PASS | {"event_date":"2026-07-06","end_date":"2026-07-06","slot":"am","start_datetime":"2026-07-06 09:00:00","end_datetime":"2026-07-06 12:00:00","type":"work","user_id":"2"} |
| 시간대 행 저장 | ✅ PASS | slots=morning |
| 참여 직원 미선택 거부 | ✅ PASS | {"ok":false,"error":"참여 직원을 한 명 이상 선택하세요."} |
| 시간대 미선택 거부 | ✅ PASS | {"ok":false,"error":"시간대(오전/오후/야간)를 1개 이상 선택하세요."} |
| 종료일 < 시작일 거부 | ✅ PASS | {"ok":false,"error":"종료일은 시작일보다 빠를 수 없습니다."} |
| 직원 중복 배정 감지(경고 반환·미저장) | ✅ PASS | {"ok":true,"data":{"conflict":true,"conflicts":[{"user_name":"차윤석","title":"QAFINAL-일정A"}]}} |
| 중복 확인 후 강제 저장 | ✅ PASS | {"ok":true,"data":{"id":309,"conflict":false}} |
| 다른 시간대는 중복 아님 | ✅ PASS | {"ok":true,"data":{"id":310,"conflict":false}} |
| 야간 슬롯 시간 매핑(18:00~22:00) | ✅ PASS | {"start_datetime":"2026-07-08 18:00:00","end_datetime":"2026-07-08 22:00:00","slot":"night"} |
| 종일 선택 시 3개 시간대 모두 저장 | ✅ PASS | slots=morning,afternoon,night |
| 기간 일정 저장(시작~종료) | ✅ PASS | {"event_date":"2026-07-13","end_date":"2026-07-17","start_datetime":"2026-07-13 09:00:00","end_datetime":"2026-07-17 18:00:00"} |
| 일정 조회 API — 등록 건 반영 | ✅ PASS | 기간 내 QAFINAL 일정 6건 (전체 9건) |
| 기간 일정 조회 결과 정확 | ✅ PASS | {"d":"2026-07-13","e":"2026-07-17","p":[{"schedule_id":313,"user_id":4,"name":"차우석","color":"#d93025"}]} |
| 일정-프로젝트 연동 표시 | ✅ PASS | project_id=3001 |
| 조회 기간 누락 시 422 | ✅ PASS | {"ok":false,"error":"조회 기간(from, to)이 필요합니다."} |
| 일정 이동 — 기간 길이 보존 | ✅ PASS | {"event_date":"2026-07-20","end_date":"2026-07-24"} |
| 일정 수정 반영 | ✅ PASS | {"title":"QAFINAL-일정A수정","type":"meeting","start_datetime":"2026-07-06 13:00:00"} |
| 출근 분석 화면 200 + 일정 반영 | ✅ PASS | HTTP 200, user3 일정일수(DB 단순계산)=6 |
| 일정 삭제 | ✅ PASS | {"ok":true,"data":{"id":308}} |
| 일정 중복 삭제 요청 차단 | ✅ PASS | {"ok":false,"error":"일정을 찾을 수 없습니다."} |

### 11.프로젝트휴지통  <sub>PASS 7 · FAIL 0 · WARN 0 · INFO 1</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 프로젝트 소프트삭제 | ✅ PASS | deleted_at=2026-07-29 09:31:45 |
| 프로젝트 휴지통 목록 | ✅ PASS | HTTP 200 |
| 참조 있는 프로젝트 완전삭제 차단 | ✅ PASS | HTTP 302, 잔존 |
| 프로젝트 복구 | ✅ PASS | deleted_at=NULL |
| 참조 없는 프로젝트 완전삭제 | ✅ PASS | HTTP 302 |
| 완전삭제 감사로그 | ✅ PASS | audit=[{"action":"project_purge","entity":"project"}] |
| 감사로그 액션명 규약 차이 | ℹ️ INFO | 프로젝트=project_purge/entity='project' vs 고객·견적·계약=trash_purge/entity 복수형 — 휴지통 감사 조회 시 단일 조건으로 잡히지 않음 |
| 완전삭제 중복요청 안전 | ✅ PASS | HTTP 302 |

### 12.지출·비용  <sub>PASS 12 · FAIL 1 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 비용 등록 + 금액 자동계산(수량×단가) | ✅ PASS | amount=1000000 (20×50,000=1,000,000) |
| 자동계산 금액과 불일치 시 조정사유 요구 | ✅ PASS | {"ok":false,"error":"자동계산 금액(100,000원)과 다른 금액을 입력하려면 조정 사유를 입력하세요."} |
| 인건비 자동계산(일수×일당) | ✅ PASS | amount=1000000 (5×200,000) |
| 음수 금액 비용 거부 | ✅ PASS | {"ok":false,"error":"금액은(는) 0 이상이어야 합니다."} |
| 잘못된 비용 구분 거부 | ✅ PASS | {"ok":false,"error":"비용 구분을 선택하세요 (자재비/인건비/외주비/장비비/운송비/식비/폐기물 처리비/기타)."} |
| 지출 중복 저장 방지 | ❌ FAIL | 동일 내용(project=3001, 운송비, 300,000원, 2026-07-29) 3회 POST → costs 3행 생성 (ids=1124,1125,1126) |
| 프로젝트 actual_cost 집계 반영 | ✅ PASS | projects.actual_cost=2300000, costs 확정합=2300000 |
| 비용 수정 반영 | ✅ PASS | {"item_name":"QAFINAL-페인트수정","amount":"1250000"} |
| 수정 후 원가 재집계 | ✅ PASS | actual_cost=2550000 |
| 비용 취소 = 상태 전환(물리삭제 아님) | ✅ PASS | {"ok":true,"data":{"id":1122,"cost_status":"cancelled"}} |
| 취소 비용은 원가 집계 제외 | ✅ PASS | actual_cost=1300000 (이전 2550000 − 1,250,000) |
| 비용 취소 중복요청 차단 | ✅ PASS | {"ok":false,"error":"이미 취소된 비용입니다."} |
| 비용 CSV 내보내기 | ✅ PASS | HTTP 200, bytes=442 |

### 13.예외입금·정산  <sub>PASS 10 · FAIL 0 · WARN 0 · INFO 1</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 일반(계약연결) 프로젝트 직접 입금 차단 | ✅ PASS | {"ok":false,"error":"일반 프로젝트의 입금은 연결된 계약 화면에서 관리합니다."} |
| 예외 프로젝트 입금 등록 | ✅ PASS | {"ok":true,"data":{"id":4467,"settlement_status":"partial"}} |
| 예외 프로젝트 순입금 집계 | ✅ PASS | netPaid=3000000 |
| 부분입금 시 정산상태 | ℹ️ INFO | settlement_status=partial |
| 미수금 있는 상태 정산완료 거부 | ✅ PASS | {"ok":false,"error":"미수금 5,000,000원이 남아 있어 정산 완료 처리할 수 없습니다."} |
| 환불 상한(순입금) 초과 거부 | ✅ PASS | {"ok":false,"error":"환불 금액이 누적 입금액(순입금 3,000,000원)을 초과할 수 없습니다."} |
| 예외 프로젝트 전액 입금 | ✅ PASS | netPaid=8000000, 총액=8000000 |
| 완납 후 정산완료 상태 도달 | ✅ PASS | 완납 직후 자동 상태=settled, settle 액션 응답={"ok":false,"error":"이미 해당 정산 상태입니다."}, 최종=settled |
| 정산완료 중복요청 차단 | ✅ PASS | {"ok":false,"error":"이미 해당 정산 상태입니다."} |
| 정산상태 자동 재계산 복귀 | ✅ PASS | settlement_status=settled |
| 예외 프로젝트 확정매출 = 순입금 공급가 환산 | ✅ PASS | netPaid=8000000, 기대 공급가=7272727(727만), 화면 노출=true |

### 14.대시보드  <sub>PASS 12 · FAIL 0 · WARN 0 · INFO 2</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 대시보드 200 | ✅ PASS | HTTP 200 |
| 대시보드 데이터 API | ✅ PASS | keys=monthly_trend |
| 대시보드 입금 총액 = DB 집계 | ✅ PASS | DB 입금총액(2026-07-01~2026-07-30)=11300000 → 1,130만 |
| 대시보드 원가 총액 = DB 집계 | ✅ PASS | DB 원가총액=1300000 → 130만 |
| 대시보드 건수 KPI DB 기준 | ℹ️ INFO | 계약 진행=1, 진행 중 프로젝트=1 |
| 삭제(휴지통) 프로젝트 입금 집계 제외 | ✅ PASS | 삭제 전 11300000 → 삭제 후 3300000 (예외 프로젝트 입금 8000000 제외), 화면 330만 노출=true |
| 삭제 후 원가 총액 | ℹ️ INFO | 1300000 → 1300000 |
| 복구 후 입금 집계 재반영 | ✅ PASS | 복구 후 11300000 (원래 11300000), 화면 노출=true |
| 최근 입금 위젯 노출 | ✅ PASS | 최근 입금 입금 완료(paid) · 입금일 순 · 환불·취소 제외 · VAT 포함 |
| 최근 지출 위젯 노출 | ✅ PASS | ok |
| 프로젝트·공정 현황 위젯 | ✅ PASS | ok |
| 일반직원 대시보드 200 | ✅ PASS | HTTP 200 |
| 일반직원에게 전사 재무 KPI 미노출 | ✅ PASS | 확정매출=false, 원가총액=false, 입금총액=false |
| 일반직원 대시보드 제목 = 내 대시보드 | ✅ PASS | 내 대시보드 · EDEN CRM EDEN 도장 CRM 대시보드 현장 프로젝트 내 정보 내 보너스 내 목표 내 대시보드 Q QAFINAL-직원A수 |

### 15.분석·리포트  <sub>PASS 20 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 리포트 200 | ✅ PASS | HTTP 200 |
| 리포트 데이터 API | ✅ PASS | keys=period,monthly_trend,new_customers,by_source,by_stage,sales_conversion,quote_conversion,project_pl,by_work_type,staff_performance,delayed_projects,receivables,cost_overrun,target_achievement |
| 리포트 확정매출(공급가) = DB 재계산 | ✅ PASS | 리포트 API actual_revenue=10272727, DB 재계산=10272727 (입금 3건, 기간 2026-07-01~2026-07-31) |
| 리포트 확정순이익 = 확정매출 − 원가 | ✅ PASS | API actual_profit=8972727, 계산=10272727-1300000=8972727 |
| 월별 추이 그래프 당월 매출 = 확정매출 | ✅ PASS | 그래프 2026-07=10272727, DB=10272727 |
| 신규 고객 수 = DB | ✅ PASS | API=24, DB=24 |
| 견적 전환율 분모 = DB 견적 수 | ✅ PASS | API=2, DB=2 |
| 출근 분석 200 | ✅ PASS | HTTP 200 |
| 일반직원 리포트 접근 403 | ✅ PASS | HTTP 403 |
| 일반직원 출근 분석 403 | ✅ PASS | HTTP 403 |
| 일반직원 전직원 성과 접근 제한 | ✅ PASS | performance.index HTTP 200 (본인 스코프 허용) |
| 분석 권한 부여 시 리포트 접근 200 | ✅ PASS | HTTP 200 |
| 분석 권한 있어도 출근 통계는 여전히 403(사장 전용) | ✅ PASS | HTTP 403 |
| 반기 현황 화면 200 | ✅ PASS | HTTP 200 |
| 보너스 원장 화면 200 | ✅ PASS | HTTP 200 |
| 목표 관리 화면 200 | ✅ PASS | HTTP 200 |
| 성과 화면 200 | ✅ PASS | HTTP 200 |
| 감사 로그 화면 200 | ✅ PASS | HTTP 200 |
| 시스템 설정 화면 200 | ✅ PASS | HTTP 200 |
| 알림 화면 200 | ✅ PASS | HTTP 200 |

### 16.휴지통  <sub>PASS 20 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 고객 휴지통 목록 200 | ✅ PASS | HTTP 200 |
| 고객 휴지통 복원·완전삭제 UI + 2단계 확인 | ✅ PASS | 복원 버튼=true, 완전삭제 버튼=true, data-purge(2단계 확인 훅)=true |
| 영업기회 휴지통 목록 200 | ✅ PASS | HTTP 200 |
| 영업기회 휴지통 복원·완전삭제 UI + 2단계 확인 | ✅ PASS | 복원 버튼=true, 완전삭제 버튼=true, data-purge(2단계 확인 훅)=true |
| 견적 휴지통 목록 200 | ✅ PASS | HTTP 200 |
| 견적 휴지통 복원·완전삭제 UI + 2단계 확인 | ✅ PASS | 복원 버튼=true, 완전삭제 버튼=true, data-purge(2단계 확인 훅)=true |
| 계약 휴지통 목록 200 | ✅ PASS | HTTP 200 |
| 계약 휴지통 복원·완전삭제 UI + 2단계 확인 | ✅ PASS | 복원 버튼=true, 완전삭제 버튼=true, data-purge(2단계 확인 훅)=true |
| 프로젝트 휴지통 목록 200 | ✅ PASS | HTTP 200 |
| 프로젝트 휴지통 복원·완전삭제 UI + 2단계 확인 | ✅ PASS | 복원 버튼=true, 완전삭제 버튼=true, data-purge(2단계 확인 훅)=true |
| 고객 휴지통: 삭제권한만 있는 직원 목록·복원·완전삭제 전부 403 | ✅ PASS | 목록 403 / 복원 403 / 완전삭제 403 |
| 영업기회 휴지통: 삭제권한만 있는 직원 목록·복원·완전삭제 전부 403 | ✅ PASS | 목록 403 / 복원 403 / 완전삭제 403 |
| 견적 휴지통: 삭제권한만 있는 직원 목록·복원·완전삭제 전부 403 | ✅ PASS | 목록 403 / 복원 403 / 완전삭제 403 |
| 계약 휴지통: 삭제권한만 있는 직원 목록·복원·완전삭제 전부 403 | ✅ PASS | 목록 403 / 복원 403 / 완전삭제 403 |
| 프로젝트 휴지통: 삭제권한만 있는 직원 목록·복원·완전삭제 전부 403 | ✅ PASS | 목록 403 / 복원 403 / 완전삭제 403 |
| 참조 있는 고객 완전삭제 차단 | ✅ PASS | HTTP 302, 고객 잔존 |
| 계약 참조 있는 견적 완전삭제 차단 | ✅ PASS | HTTP 302 |
| 프로젝트 전환된 계약 삭제 차단 | ✅ PASS | HTTP 302, deleted_at=NULL |
| 휴지통 복원·완전삭제 감사로그 기록 | ✅ PASS | [{"action":"trash_restore","entity":"customers","c":"16"},{"action":"trash_restore","entity":"leads","c":"9"},{"action":"trash_purge","entity":"leads","c":"9"},{"action":"trash_purge","entity":"customers","c":"9"},{"action":"project_restore","entity":"project","c":"12"},{"action":"project_purge","entity":"project","c":"6"}] |
| 휴지통 복원 중복요청 안전 처리 | ✅ PASS | HTTP 302 |

### 17.라우트스모크  <sub>PASS 4 · FAIL 2 · WARN 1 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 주요 화면 32종 200 응답 + PHP 오류 없음 | ❌ FAIL | performance.index → PHP 진단 노출 |
| 상세 화면 7종 200 + PHP 오류 없음 | ✅ PASS | 전부 정상 |
| 전 라우트 49개 PHP 진단(Deprecated/Warning/Notice/Fatal) 노출 없음 | ❌ FAIL | performance.index: <b>Deprecated</b>: Using null as an array offset is deprecated, use an empty string instead in <b>/Users/deoksookim/Desktop/코드/claude code/eden_crm/app/controllers/PerformanceController.php</b> on |
| 없는 라우트 404 | ✅ PASS | HTTP 404 |
| 없는 ID 상세 오류 페이지(500 아님) | ✅ PASS | HTTP 403 |
| 잘못된 ID 형식 안전 처리(500 아님) | ✅ PASS | HTTP 403 |
| 상세 404/403 응답 코드 불일치 | ⚠️ WARN | projects.show(없는 id)=HTTP 403 vs customers.show(없는 id)=HTTP 404 — 존재하지 않는 리소스에 리소스별로 다른 코드 반환 |

### 18.브라우저(데스크톱)  <sub>PASS 5 · FAIL 0 · WARN 0 · INFO 0</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 데스크톱 21개 화면 콘솔 오류 없음 | ✅ PASS | 오류 0건 |
| 데스크톱 21개 화면 4xx/5xx 응답 없음 | ✅ PASS | 4xx/5xx 0건 |
| 공정 보드 진행률 입력 → 서버 반영(브라우저) | ✅ PASS | 입력 45% → DB pct=45, 화면 총진행률=97%, {"before":"100","after":"45","stageId":"1","pct":"97%"} |
| 브라우저 폼 제출 → 고객 등록 | ✅ PASS | count=1, url=http://127.0.0.1:8080/index.php?r=customers.show&id=1773 |
| 견적 폼 — 고객 선택 시 해당 고객 영업기회만 로드 | ✅ PASS | 옵션 2개(선택안함 제외) / DB 해당 고객 영업기회 2건 — [":선택 안함","450:#450 · QAFINAL-내부인테리어 · 상담예약","449:#449 · QAFINAL-외벽도장수정 · 계약완료"] |

### 19.모바일(390x844)  <sub>PASS 5 · FAIL 1 · WARN 1 · INFO 1</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 모바일 21개 화면 가로 스크롤 없음 | ❌ FAIL | 계약 폼: scrollWidth 434 > viewport 390 (div.field w=173 right=393 in[div.form-grid < div.card.pad < form.form] "담당 영업 ", div.field w=173 right=393 in[div.form-grid < div.card.pad < form.form] "상태 ", div.field w=173 right=393 in[div.form-grid < div.card.pad < form.form] "공사 유형", div.field w=364 right=393 in[div.form-grid < div.card.pad < form.form] "현장 주소") \| 리포트: scrollWidth 654 > viewport 390 (table.data w=640 right=653 in[div.table-wrap.border-0 < div.card.mb-14 < div.page] "담당자전체 리드계약 건수계약률 데이터", table.data w=640 right=653 in[div.table-wrap.border-0 < div.card.mb-14 < div.page] "프로젝트상태공급가액(VAT 제외)원가 총액순이익순이", table.data w=640 right=653 in[div.table-wrap.border-0 < div.card.mb-14 < div.page |
| 모바일 21개 화면 콘솔 오류 없음 | ✅ PASS | 오류 0건 |
| 모바일 터치 타겟 32px 미만 요소 존재 | ⚠️ WARN | 대시보드: 13개 — a.section-link[61x19] "전체 일정 →", a.section-link[69x19] "파이프라인 →", a.section-link[61x19] "공정 보드 →", a.section-link[72x19] "상세 리포트 →", a.[108x15] "목표 관리에서 설정 →" \| 고객 목록: 20개 — a.[127x16] "QAFINAL-브라우저", a.[96x16] "QAFINAL-고객13", a.[98x16] "QAFINAL-고객03", a.[98x16] "QAFINAL-고객04", a.[98x16] "QAFINAL-고객05" \| 고객 등록폼: 2개 — input.[13x13] "" \| 영업기회: 1개 — button.pf-enter[1x1] "" \| 견적 목록: 3개 — button.pf-enter[1x1] "", a.[103x16] "Q20260729-00", a.[101x16] "Q20260729-00" \| 견적 상세: 1개 — a.[122x17] "C-20260729-0" |
| 모바일 네비게이션 구조 | ℹ️ INFO | {"toggleCount":1,"sidebarVisible":true,"sidebarWidth":236,"bodyWidth":390} |
| 모바일에서 본문이 뷰포트 폭 안에 들어옴 | ✅ PASS | clientWidth=390 |
| 모바일 목록 테이블 — 자체 가로 스크롤 처리 | ✅ PASS | {"tableWidth":804,"viewport":390,"scrollable":true} |
| 모바일 폼 입력·제출 동작 | ✅ PASS | count=1, url=http://127.0.0.1:8080/index.php?r=customers.show&id=1774 |
| 모바일 공정 보드 카드 폭 정상 | ✅ PASS | {"cards":2,"over":0,"sliderWidth":118,"sliderHeight":24} |

### 20.추가관찰  <sub>PASS 6 · FAIL 4 · WARN 0 · INFO 1</sub>

| 항목 | 결과 | 근거/관측값 |
|---|---|---|
| 고객 폼에서 담당 영업 지정 가능 | ❌ FAIL | customers.form 선택지 0개 (전체 옵션 ["=미지정"]) / 활성 직원 5명, sales_manager 역할 0명 · 계약 폼은 5명 노출 |
| 영업기회 폼 담당 영업 라벨 정확(활성 계정에 "(비활성)" 표기 없음) | ❌ FAIL | pipeline.form 옵션=["=미지정","1=김덕수 (비활성)"], 활성인데 "(비활성)" 표기=["1=김덕수 (비활성)"] |
| 납부계획 pending 행 정리 | ✅ PASS | [{"id":"4460","pay_type":"down","amount":"3300000","status":"paid"},{"id":"4463","pay_type":"middle","amount":"4400000","status":"paid"},{"id":"4462","pay_type":"balance","amount":"3300000","status":"cancelled"},{"id":"4466","pay_type":"balance","amount":"3300000","status":"paid"}] |
| 휴지통 감사로그 액션명 통일 | ❌ FAIL | 기록된 액션·엔티티=[{"action":"project_purge","entity":"project","c":"6"},{"action":"project_restore","entity":"project","c":"12"},{"action":"quote_restore","entity":"quotes","c":"6"},{"action":"trash_purge","entity":"customers","c":"9"},{"action":"trash_purge","entity":"leads","c":"9"},{"action":"trash_restore","entity":"customers","c":"17"},{"action":"trash_restore","entity":"leads","c":"9"}] — trash_purge/trash_restore(고객·영업기회·견적·계약, entity 복수형) vs project_purge/project_restore(프로젝트, entity 단수 'project') |
| 시스템 설정 입력값 검증 부재 | ❌ FAIL | page_size='0' 저장됨 → 고객 목록 표시 0건(DB 24건, HTTP 200) · vat_rate='abc' 저장됨 → 견적 VAT 0원(소계 1000000) · login_max_attempts='-1' 저장됨(-1) · session_idle_min='0' 저장됨(0, 저장 즉시 전원 자동 로그아웃 → 관리자 자기 잠금). SettingsController::save 는 POST 값을 그대로 UPDATE 한다(범위·형식 검증 없음) |
| 설정 원복 확인 | ✅ PASS | [{"setting_key":"login_max_attempts","value":"5"},{"setting_key":"page_size","value":"20"},{"setting_key":"session_idle_min","value":"60"},{"setting_key":"vat_rate","value":"10"}] |
| IP 기준 로그인 스로틀 동작 | ℹ️ INFO | 테스트 직전 10분 내 실패 로그인 0건 — 20건 이상이면 계정 단위 카운트 이전에 IP 스로틀이 먼저 차단(신설 기능, 정상) |
| 로그인 5회 실패 시 계정 잠금 | ✅ PASS | {"failed_attempts":"0","locked_until":"2026-07-29 09:47:49"} |
| 잠금 상태에서는 올바른 비밀번호도 차단 | ✅ PASS | HTTP 302 |
| 권한 없는 직원의 타 프로젝트 상세 차단 | ✅ PASS | HTTP 403 |
| 권한 없는 직원의 계약 상세 차단 | ✅ PASS | HTTP 403 |
