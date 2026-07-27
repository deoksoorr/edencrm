# 근태 관리 규칙 (신규 · R6 최종 구조)

**분석 > 리포트 > 직원 출근** 탭과 사장 대시보드 '이번 달 출근 현황'이 따르는 근태 집계·관리 기준 문서입니다.
집계 단일 출처는 `app/core/AttendanceService.php`, 수동 마킹은 `app/controllers/AttendanceController.php` 입니다.
화면 요약은 [PRODUCT_MANUAL 15번](PRODUCT_MANUAL.md#15-직원-출근-분석-r6-최종-구조) 참조.

> **R6 에서 근태 구조가 최종 확정되었습니다.** R5 의 자동 지각·조퇴 판정과 휴가 표시를 폐지하고,
> **지각·무단결근을 관리자가 직접 등록**하는 구조 + **통계 3종**(출근 일수·지각·무단결근)으로 단순화했습니다.

---

## 1. 휴가 전면 비노출 (데이터는 보존)

- 일정 유형에서 **'휴가(vacation)' 옵션이 제거**되었습니다(`Stages::scheduleTypes()` 에서 키 삭제 → 캘린더 폼·프로젝트 인라인 폼·scheduler.js 옵션에서 자동 소멸).
- 직접 `type=vacation` 으로 POST 해도 서버가 **422 거부**("휴가 유형은 더 이상 사용하지 않습니다.") — 무단 저장 차단.
- **기존 vacation 일정 데이터는 DB 에 보존**하되(삭제 안 함), 일정 조회(`ScheduleController::data`)가 `s.type <> 'vacation'` 로 필터해 **화면에 표시하지 않습니다**.
- 캘린더·대시보드·분석·CSV·그래프·통계 합계에서 휴가 항목이 **전부 제거**되었습니다(0·빈값 잔재 없음 — 항목 자체 제거).
- 자동 지각·조퇴 판정에 쓰던 설정 `attendance_work_start`/`attendance_work_end` 는 **설정 화면에서 숨겨졌습니다**(`SettingsController::HIDDEN_KEYS` — 노출·저장 대상 제외, 행 데이터는 보존).

---

## 2. 수동 지각·무단결근 마킹 (관리자 직접 등록·해제)

### 원천 테이블 `attendance_marks`

| 컬럼 | 내용 |
|---|---|
| `user_id` | 대상 직원(FK users, ON DELETE RESTRICT) |
| `mark_date` | 대상 날짜(DATE) |
| `mark_type` | `late`(지각) / `absent`(무단결근) ENUM — 같은 날 **1상태만** |
| `memo` | 사유·메모(최대 255자, 선택) |
| `created_by` | 등록 관리자(FK users) |
| `created_at` / `updated_at` | 등록·수정 시각 |
| **UNIQUE(`user_id`, `mark_date`)** | 같은 직원·같은 날짜 1행 강제 — 중복·동시 등록 원천 차단 |

### 등록·변경·해제 규칙 (`AttendanceController`)

| 동작 | 조건 | 처리 |
|---|---|---|
| **등록** | 해당 날짜에 마크 없음 | INSERT (`mode=created`) |
| **상태 변경** | 다른 상태 선택(또는 메모 수정) | UPDATE (`mode=updated`) — 기존→변경 값 감사 기록 |
| **같은 상태 재등록** | 같은 상태 + 같은 메모 | **422 거부**("이미 … 상태가 등록되어 있습니다") — 중복 방지 |
| **동시 등록 경합** | 두 요청이 동시에 INSERT | UNIQUE 위반(SQLSTATE 23000) 포착 → **422**("같은 날짜에 이미 … 등록") |
| **해제** | 마크 존재 | DELETE (`mode=deleted`) — 화면은 2단 확인(EDEN.confirm) |
| **미래 날짜 등록** | `mark_date > 오늘` | **422 거부**("미래 날짜에는 … 등록할 수 없습니다") — 사전 등록 무의미 |
| **잘못된 상태값** | `late`/`absent` 외 | **422 거부** |
| **없는 직원** | 삭제/미존재 user | **422 거부** |

### 마킹 UI

- 위치: 분석 '**직원 출근**' 탭 상단 관리자용 카드 — **`attendance.manage` 권한자에게만 렌더**(권한 없으면 마크업·JSON 자체 미출력).
- 직원 선택 → 월간 캘린더(서버 렌더, 월요일 시작) → **날짜 클릭 → 상태 선택 모달**(지각/무단결근 라디오 + 메모, 해제는 확인 절차).
- 상태 색·라벨: **지**=주황(지각) · **결**=적색(무단결근) · **●**=출근. 그리드에도 동일 뱃지 오버레이(무단결근 날은 ● 미표시).

---

## 3. 권한 (`attendance.manage`)

- 마킹(등록·변경·해제)에는 신설 권한 **`attendance.manage`** 가 필요합니다(seed_core permissions + **super_admin 기본 부여**).
- 라우터가 강제: `attendance.mark`/`attendance.unmark` = **POST + CSRF + perm attendance.manage + feature attendance**. 권한 없는 사용자는 URL·API 직접 접근 시 **403**.
- **조회**(출근 분석 탭 자체)는 기존 `report.view` 권한 — 조회 권한만 있고 마킹 권한이 없으면 통계는 보되 마킹 카드는 나오지 않습니다.
- 근태 화면 전체는 운영 토글 `feature_attendance`(기본 켜짐)로 게이트됩니다(작업일지 토글과 별도).

---

## 4. 출근 일수 반영 기준 (무단결근 제외 · 지각 포함)

출근 일수는 작업 기록·작업 일정·마크를 결합해 계산합니다(`AttendanceService::presenceMatrix` — R7 단일 출처, 유일한 구현).

```
출근 일수 = ( work_logs 의 (user_id, work_date)
            ∪ 업무 일정 참여일(type ∈ 작업 work·회의 meeting·현장방문 site_visit —
              취소(cancelled) 일정 제외, 미래 날짜 제외,
              기간 일정(end_date)은 기간 내 각 날짜로 확장·오늘까지 절단) ) DISTINCT 일수
          − 무단결근(absent) 마크와 겹치는 날 제외
```

- **R7 변경**: 운영은 작업일지 기능이 꺼져 있어(`feature_worklog=0`) **업무 일정 참여가 사실상의 출근 기록**입니다. 같은 날 작업일지+일정, 일정 여러 건이 있어도 날짜 병합으로 **1일**입니다.
- **무단결근으로 등록된 날은 출근 일수에서 제외**됩니다(그날 기록이 있어도 제외).
- **지각으로 등록된 날은 출근 일수에 그대로 포함**됩니다(지각도 출근).
- `daysByUser`(일수) · `matrixByUser`(그리드 ●) · `monthlyTotals`(월별 추이) 세 메서드가 **presenceMatrix 하나를 소비** → 그리드의 ● 개수와 출근 일수가 항상 일치합니다. 대시보드·리포트·직원상세·CSV 모두 이 서비스만 사용합니다.
- **정책 확정(R7)**: 작업(work)·회의(meeting)·현장방문(site_visit)은 근무 실재로 출근 포함, 기타(other)는 성격 불명(개인 일정 가능)이라 제외 — `AttendanceService::PRESENCE_SCHEDULE_TYPES` 가 단일 출처.
- **주의**: 일정은 물리 삭제되므로, work 일정을 삭제하면 해당 날짜 출근도 함께 사라집니다(작업일지 기반과 다른 점).

---

## 5. 월별 통계 3종

성과·분석·CSV·대시보드가 쓰는 지표는 **오직 3종**입니다(조퇴·휴가·미출근·출근율 지표 폐지):

| 통계 | 정의 |
|---|---|
| **출근 일수** | §4 산식(work_logs DISTINCT − 무단결근 겹침 제외) |
| **지각 횟수** | 해당 월 `late` 마크 수(출근 일수에는 포함) |
| **무단결근 횟수** | 해당 월 `absent` 마크 수 |

- 분석 KPI = 출근 파생(총/평균/최다/최소 + 전월 대비 증감) + 지각·무단결근 합계. 상세 표·CSV = 출근 일수 / 지각(회) / 무단결근(회) / 전월 출근 일수 / 전월 대비 증감(일) / 출근 일자.
- **영업일(근무 예정) 수**는 통계가 아니라 캘린더 렌더·막대 차트 축 참고용으로만 유지합니다(출근율 분모 왜곡 방지 — 지표에서 제거).
- 비활성(inactive) 직원의 과거 통계는 재직 필터로 조회할 수 있습니다(테스트 계정 제외 없음 — 전 계정 실계정 취급).

---

## 6. 감사 로그와 변경 이력 확인

등록·변경·해제 전부 **감사 로그(`audit_logs`)** 에 기록됩니다(관리자 `user_id`·IP·User-Agent·시각은 `Audit::log` 가 자동 부여).

| 액션(action) | 기록 내용 |
|---|---|
| `attendance_mark_create` | 직원ID·날짜·상태·메모(after), before=없음 |
| `attendance_mark_update` | before=기존 행 전체(기존 상태) · after=변경된 상태·메모(기존→변경 추적) |
| `attendance_mark_delete` | before=삭제된 행 전체 · after=해제 사유(reason) |

- **변경 이력 확인 방법**: **관리 > 감사 로그**에서 entity(`attendance_marks`) 또는 action(`attendance_mark_*`)으로 필터하면 누가·언제·어떤 직원의 어느 날짜 상태를 등록/변경/해제했는지, 기존 상태→변경 상태와 사유까지 확인할 수 있습니다.
- 해제(DELETE)는 물리 삭제이지만 **삭제 전 행 전체가 감사 로그에 보존**되므로 사후 추적이 가능합니다.

---

## 7. 정책/후속 검토

| 항목 | 현행 |
|---|---|
| **작업 기록 없는 직원의 출근** | 사무·영업직처럼 work_logs 를 쓰지 않으면 출근 0 — 집계 대상 범위는 정책 미확정(근태 평가에 그대로 쓰지 말 것) |
| **휴가 재도입** | 현재 전면 비노출(데이터 보존). 재도입 시 반차·분모 처리 정책 확정 후 별도 설계 |

*근거: `app/core/AttendanceService.php`(통계 3종·산식) · `app/controllers/AttendanceController.php`(마킹 규칙) · `database/migrations/2026-07-23_r6_attendance_marks.sql`(스키마·권한) · `.superpowers/sdd/r6-attend-report.md` · `r6-worklog.md` §[attend].*
