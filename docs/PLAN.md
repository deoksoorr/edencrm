# EDEN CRM 실행 계획

각 태스크는 독립 서브에이전트가 구현하고, 태스크마다 스펙·품질 리뷰를 거친다.
모든 구현은 `docs/ARCHITECTURE.md` 의 폴더 구조·코어 API·권한 모델·산식·UI 규약을 따른다.

## T1 기획·아키텍처 설계 (완료 기준: ARCHITECTURE.md + PLAN.md 커밋)
산출물: docs/ARCHITECTURE.md, docs/PLAN.md, .gitignore, config.local.example.php

## T2 DB 스키마·시드 구축
산출물: database/schema.sql, database/seed_core.sql, database/seed_dev.sql
- ARCHITECTURE 7절의 28개 테이블 전부, FK·인덱스 포함
- seed_core: 역할 5·권한·역할권한 매핑·영업단계 12·공정단계 18·설정·2026 공휴일
- seed_dev: 직원 12, 고객 55+, 영업기회 32+, 프로젝트 30+(지연·적자·고수익·미수금·일지누락·일정충돌 포함)
- 완료 기준: `mysql < schema.sql && < seed_core.sql && < seed_dev.sql` 무오류, 주요 건수 검증 쿼리 통과

## T3 코어 프레임워크·인증·RBAC
산출물: public/index.php, app/bootstrap.php, app/config/*, app/routes.php(초기), app/core/* 전부,
AuthController(로그인/로그아웃/비밀번호 변경), 레이아웃(사이드바·톱바·로그인), app.css 기본, app.js(api 래퍼·토스트·모달)
- 완료 기준: 로그인/로그아웃/실패잠금/최초 비번변경/권한 403/CSRF 419 동작, 감사 로그 기록

## T4 고객 CRM·영업 파이프라인
산출물: CustomersController+views(목록/상세 타임라인/등록·수정/중복검사·병합), ActivitiesController,
PipelineController+kanban.js(SortableJS 칸반, 실패 롤백, 카드 상세 모달, 예상수익 자동계산)

## T5 견적·계약 관리
산출물: QuotesController(견적 CRUD·버전 이력·항목·인쇄뷰), ContractsController(계약·입금 payments·미수금·프로젝트 전환 연결)

## T6 프로젝트·공정 보드
산출물: ProjectsController(목록/상세/생성·수정/파일), ProcessController+공정 칸반(이동 권한·사유·이력·지연 표시),
SettingsController 중 영업단계·공정단계 관리

## T7 일정·배정·작업일지
산출물: AssignmentsController(배정·기여도), ScheduleController+scheduler.js(월 캘린더+직원별 주간 타임라인, DnD, 충돌 경고),
WorklogsController(작업일지 CRUD·사진 업로드·관리자 확인·수정 이력)

## T8 수익·성과·리포트·알림·대시보드
산출물: CostsController(비용), DashboardController(사장/직원 대시보드+Chart.js), PerformanceController(직원 성과·기여도),
ReportsController(기간 필터·CSV), NotificationsController(알림 생성 훅·읽음 처리), StaffController(직원 관리), AuditController

## T9 보안·QA 검증 (병렬 리뷰 에이전트)
- 보안: 스펙 21절 전 항목 실 테스트 (비로그인 접근, IDOR, SQLi, XSS, CSRF, 업로드, 권한 우회, 세션)
- QA: 스펙 23절 시나리오 A/B/C + 계산식·페이지네이션·빈데이터 테스트
- 발견 이슈는 수정 에이전트가 처리 후 재검증

## T10 리팩토링·최적화·서버 실행
- 미사용 코드·중복 제거, 쿼리·인덱스 점검, console.log 제거, README/설치 문서
- 로컬 서버 기동 + 최종 점검 + 완료 보고
