# R15 설계 — 반기 버튼 통합·입금탭 버튼명·폼 오류 개선·견적/계약 삭제·공통 휴지통

작성일: 2026-07-28 · 브랜치: `r15-trash-forms` · 사장 지시 5건.
**불가침**: 기존 DB 값·스키마 무접촉(마이그레이션·백필 없음). 완전삭제만 관리자가 명시 선택한 행에 한해 물리 삭제(요청 기능). 지시된 수정사항 외 작업 금지.

## 1. 반기 화면에 보너스 등록·변경 이력 버튼 (지시 #1)
- 실측: '변경 이력'은 순수 링크(이식 자유). '+ 보너스 등록'은 bonus/index.php 인라인 IIFE 모달(의존: $canManage·$formUsers·$projects·bonus.calc 라우트 — 전부 이식 가능, 자동열기 파라미터는 없음).
- **설계**: 모달 폼 JS+HTML을 공용 파셜 `app/views/bonus/_form_modal.php`로 추출(등록 경로만 필요 — row 액션은 bonus.index 전용 분기 유지). bonus/index.php는 파셜 include로 교체(동작 불변), halfyear/index.php에도 include. `BonusController::overview()`에 `canManage`·`formUsers` 전달 추가. halfyear page-actions: [+ 보너스 등록(canManage)] [변경 이력] [보너스 지급 현황(기존 유지)].

## 2. 계약 상세 입금 관리 '수정' → '입금내역 갱신' (지시 #2)
- 단일 지점 [contracts/show.php:165]. 동일 파일의 다른 '수정' 3곳(계약 수정 링크·모달 타이틀·툴팁)은 불변. 폭 제약 없음(테이블 셀 intrinsic).

## 3. 폼 필수표시 + 오류 페이지 이동 금지 (지시 #3)
실측 원인: ①프로젝트 save의 `Response::error(422)` 2곳(고객/고객명 미입력·고객 미존재)이 전면 오류 페이지 렌더(500과 동일 템플릿) ②견적·프로젝트 save에 try/catch 부재 → 날짜 오입력(22007)·글자수 초과(22001)가 진짜 500 ③필수 표시·required 불일치.
- **필수 표시 통일**(별표 `<span class="req">*</span>` + `required` 속성): 견적=고객·항목(안내문구) / 계약=고객·공사유형·계약금액(기존 유지 확인) / 프로젝트=생성사유(신규)·프로젝트명·공사유형·(일반)고객·(예외)고객 또는 고객명 — 예외 모드 고객명에 별표+안내, 기존 JS required 토글과 정합.
- **오류 페이지 → 플래시 리다이렉트**: ProjectsController save의 `Response::error(422)` 2곳을 `Response::redirect('projects.form', …, '메시지', 'error')`로 교체(폼 복귀+상단 플래시).
- **500 근본 차단**: 날짜 입력 전부 `Util::dateOrNull`로 교체(견적 1·계약 5·프로젝트 5 — 이미 존재하는 전용 헬퍼), 프로젝트 name/site_address/work_type `mb_substr` 캡+폼 maxlength, 견적 항목명 maxlength(quotes.js)+서버 캡, 금액류 상한 클램프(999,999,999,999). 견적 save 트랜잭션·프로젝트 insert/update를 try/catch로 감싸 실패 시 '저장 실패' 플래시 리다이렉트. `parseItems` 배열 가드.
- 스코프 외 기록: 수정 폼 빈칸 0 덮어쓰기(기존 동작)·입력값 세션 보존은 이번 지시에 없어 미변경(필수값은 클라 required가 선차단).

## 4. 견적·계약 삭제 (지시 #4)
- 견적: 소프트삭제 기존 존재(계약 전환 시 거부) — 유지, 휴지통 연계만.
- **계약 삭제 신설**: `contracts.delete`(POST, perm contract.manage) 소프트삭제. 거부: 연결 live 프로젝트 존재(먼저 프로젝트 삭제 안내 — 회계 정합), 진행 중 입금이 있어도 소프트삭제는 허용(집계는 deleted 필터가 자동 제외, 복원 가능). 상세 page-actions에 삭제 버튼(btn-danger·confirm)+Audit.

## 5. 공통 휴지통 + 완전삭제 (지시 #5)
대상: **견적·계약·프로젝트**(이번에 언급된 3개 — 확장 여지 구조).
- **휴지통 뷰**: 각 index에 `trash=1` 모드(해당 manage perm 열람) — `deleted_at IS NOT NULL` 목록(삭제일 표시), 행 액션 [복원] + [완전삭제(super_admin에게만 노출)]. index page-actions에 '휴지통' 버튼, 휴지통에서 '목록으로'. 프로젝트 휴지통도 기존 Scope 적용.
- **복원**(`*.restore`, POST, manage perm): deleted_at=NULL + Audit. 가드 — 프로젝트: 동일 계약의 live 프로젝트가 이미 존재하면 거부(uq_projects_contract 충돌 — ContractProjectService 기존 경고와 정합). 견적·계약: 가드 불요.
- **완전삭제**(`*.purge`, POST, 컨트롤러에서 `Rbac::isRole('super_admin')` 403 가드 — 라우터에 role 옵션 없음): **휴지통에 있는(soft-deleted) 행만** 대상. 참조 무결성 정책 = 참조 존재 시 거부(회계·감사 기록 보존, 공정단계 삭제와 동일 철학):
  - 견적: contracts가 참조(삭제분 포함, FK RESTRICT)하면 거부 / 아니면 트랜잭션으로 quote_items→quote_versions→quotes 물리 삭제.
  - 계약: payments·projects·contract_terminations 참조 시 거부 / 아니면 삭제(status_history는 CASCADE).
  - 프로젝트: RESTRICT 부모 7종(payments·costs·site_bonuses·project_files·schedules·work_logs·project_assignments) 중 존재 항목을 나열해 거부 / 아니면 삭제(이력·게이지·메모는 CASCADE).
  - 각 purge는 Db::transaction + Audit::log(before 스냅샷).
- 신규 테이블·컬럼 없음(기존 deleted_at 활용) → Db::TABLES·마이그레이션 불필요.

## QA·배포
회귀(신규 unit_r15: 계약 소프트삭제 가드·복원 가드·purge 참조 거부/성공·프로젝트 422→플래시) + qa_smoke + 브라우저 실측(폼 필수표시·플래시·휴지통 왕복·반기 등록 모달) → 운영 FTP(코드만) → verify + 운영 스모크.
