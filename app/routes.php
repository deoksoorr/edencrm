<?php
/**
 * 라우트 레지스트리. 형식:
 *   'route.key' => ['ControllerClass', 'action', 옵션...]
 * 옵션:
 *   'perm'   => 'perm_key'   라우터가 Rbac::require 강제 (미지정 시 로그인만 필요)
 *   'public' => true          비로그인 허용
 *   'method' => 'POST'        허용 메서드 (POST 면 CSRF 자동 검증)
 *
 * 컨트롤러 파일은 app/controllers/<ControllerClass>.php 이며 요청 시 로드된다.
 * 아직 구현되지 않은 라우트는 해당 라우트 접근 시에만 오류가 난다(다른 라우트에 영향 없음).
 *
 * R16 규약
 *  - 삭제는 쓰기와 분리한다: *.delete perm(리소스 delete 액션)을 쓴다.
 *  - 휴지통 복원·완전삭제는 trash.manage(ADMIN_ONLY) + 컨트롤러 Perm::requireSuperAdmin 이중 가드.
 *  - perm 을 일부러 두지 않는 라우트 = '본인 데이터 예외'(컨트롤러가 이미 본인 스코프를 쿼리 레벨로 강제):
 *    home, dashboard, dashboard.data, notifications.*, performance.index, performance.user,
 *    halfyear.index, bonus.index, bonus.history, targets.index, targets.goal.history,
 *    targets.goal.progress, logout, password.*
 */
return [
    // ── 인증 (T3) ──
    'login'            => ['AuthController', 'loginForm', 'public' => true],
    'login.submit'     => ['AuthController', 'login', 'public' => true, 'method' => 'POST'],
    'logout'           => ['AuthController', 'logout'],
    'password.change'  => ['AuthController', 'changeForm'],
    'password.update'  => ['AuthController', 'changePassword', 'method' => 'POST'],

    // ── 홈/대시보드 (T3 골격, T8 확장) ──
    'home'             => ['DashboardController', 'index'],
    'dashboard'        => ['DashboardController', 'index'],
    'dashboard.data'   => ['DashboardController', 'data'],

    // ── 고객 CRM (T4) ──
    'customers.index'   => ['CustomersController', 'index',  'perm' => 'customer.view'],
    'customers.show'    => ['CustomersController', 'show',   'perm' => 'customer.view'],
    'customers.form'    => ['CustomersController', 'form',   'perm' => 'customer.manage'],
    'customers.save'    => ['CustomersController', 'save',   'perm' => 'customer.manage', 'method' => 'POST'],
    'customers.delete'  => ['CustomersController', 'delete', 'perm' => 'customer.delete', 'method' => 'POST'],
    'customers.dupcheck'=> ['CustomersController', 'dupCheck', 'perm' => 'customer.manage'],
    'customers.merge'   => ['CustomersController', 'merge',  'perm' => 'customer.manage', 'method' => 'POST'],
    // 사업자등록증 (R4 T2) — 업로드/교체/삭제=customer.manage, 열람=customer.view(+Scope)
    'customers.license.upload'   => ['CustomersController', 'licenseUpload',   'perm' => 'customer.manage', 'method' => 'POST'],
    'customers.license.delete'   => ['CustomersController', 'licenseDelete',   'perm' => 'customer.manage', 'method' => 'POST'],
    'customers.license.download' => ['CustomersController', 'licenseDownload', 'perm' => 'customer.view'],
    'customers.export'  => ['CustomersController', 'export', 'perm' => 'customer.export'],
    'activities.save'   => ['ActivitiesController', 'save',  'perm' => 'customer.view', 'method' => 'POST'],

    // ── 영업 파이프라인 (T4 → R4 T7 조회 전용 전환) ──
    // 단계·상태 변경 쓰기 라우트(pipeline.move/patch)와 AJAX 보드(pipeline.board)는 기능 제거(→404).
    // 표시 단계는 PipelineStageService 자동 산정 — 원본 리드 관리(form/save/delete)만 유지.
    'pipeline.index'    => ['PipelineController', 'index',  'perm' => 'pipeline.view'],
    'pipeline.form'     => ['PipelineController', 'form',   'perm' => 'pipeline.manage'],
    'pipeline.save'     => ['PipelineController', 'save',   'perm' => 'pipeline.manage', 'method' => 'POST'],
    'pipeline.show'     => ['PipelineController', 'show',   'perm' => 'pipeline.view'],
    'pipeline.delete'   => ['PipelineController', 'delete', 'perm' => 'pipeline.delete', 'method' => 'POST'],

    // ── 견적 (T5) ──
    'quotes.index'      => ['QuotesController', 'index',  'perm' => 'quote.view'],
    'quotes.show'       => ['QuotesController', 'show',   'perm' => 'quote.view'],
    'quotes.form'       => ['QuotesController', 'form',   'perm' => 'quote.manage'],
    'quotes.leads'      => ['QuotesController', 'leadOptions', 'perm' => 'quote.manage'], // 고객별 영업기회 AJAX(견적 폼 — 서버 쿼리 단일 출처)
    'quotes.save'       => ['QuotesController', 'save',   'perm' => 'quote.manage', 'method' => 'POST'],
    'quotes.print'      => ['QuotesController', 'printView', 'perm' => 'quote.view'],
    'quotes.delete'     => ['QuotesController', 'delete', 'perm' => 'quote.delete', 'method' => 'POST'],
    // R16: 휴지통 복원·완전삭제는 최고운영자 전용(trash.manage = ADMIN_ONLY) + 컨트롤러 requireSuperAdmin 이중 가드
    'quotes.restore'    => ['QuotesController', 'restore', 'perm' => 'trash.manage', 'method' => 'POST'],
    'quotes.purge'      => ['QuotesController', 'purge',   'perm' => 'trash.manage', 'method' => 'POST'],

    // ── 계약 (T5) ──
    'contracts.index'   => ['ContractsController', 'index',  'perm' => 'contract.view'],
    'contracts.show'    => ['ContractsController', 'show',   'perm' => 'contract.view'],
    'contracts.form'    => ['ContractsController', 'form',   'perm' => 'contract.manage'],
    'contracts.quotedata'=> ['ContractsController', 'quoteData', 'perm' => 'contract.manage'], // 견적→계약 자동 입력용 읽기전용 AJAX
    'contracts.save'    => ['ContractsController', 'save',   'perm' => 'contract.manage', 'method' => 'POST'],
    'contracts.toproject'=> ['ContractsController', 'toProject', 'perm' => 'project.manage', 'method' => 'POST'],
    'contracts.terminate'=> ['ContractsController', 'terminate', 'perm' => 'contract.manage', 'method' => 'POST'],
    // R15: 소프트삭제 + 휴지통 복원·완전삭제. R16: 복원·완전삭제는 최고운영자 전용(trash.manage) + 컨트롤러 이중 가드
    'contracts.delete'  => ['ContractsController', 'delete',  'perm' => 'contract.delete', 'method' => 'POST'],
    'contracts.restore' => ['ContractsController', 'restore', 'perm' => 'trash.manage', 'method' => 'POST'],
    'contracts.purge'   => ['ContractsController', 'purge',   'perm' => 'trash.manage', 'method' => 'POST'],
    'payments.save'     => ['ContractsController', 'savePayment', 'perm' => 'payment.manage', 'method' => 'POST'],
    'payments.delete'   => ['ContractsController', 'deletePayment', 'perm' => 'payment.manage', 'method' => 'POST'],

    // ── 프로젝트 (T6) ──
    // R16: 읽기 게이트(project.view_all → field.projects read). 행 범위는 Scope::projectWhere 가 별도로 좁힌다.
    'projects.index'    => ['ProjectsController', 'index',  'perm' => 'project.view_all'],
    'projects.show'     => ['ProjectsController', 'show',   'perm' => 'project.view_all'],
    'projects.form'     => ['ProjectsController', 'form',   'perm' => 'project.manage'],
    'projects.save'     => ['ProjectsController', 'save',   'perm' => 'project.manage', 'method' => 'POST'],
    'projects.delete'   => ['ProjectsController', 'delete', 'perm' => 'project.delete', 'method' => 'POST'],
    // R15: 휴지통 복원·완전삭제. R16: 최고운영자 전용(trash.manage) + 컨트롤러 requireSuperAdmin 이중 가드
    'projects.restore'  => ['ProjectsController', 'restore', 'perm' => 'trash.manage', 'method' => 'POST'],
    'projects.purge'    => ['ProjectsController', 'purge',   'perm' => 'trash.manage', 'method' => 'POST'],
    'projects.transition'=> ['ProjectsController', 'transition', 'perm' => 'project.manage', 'method' => 'POST'],
    'projects.upload'   => ['ProjectsController', 'upload', 'perm' => 'project.view_all', 'method' => 'POST'],
    'files.download'    => ['ProjectsController', 'download', 'perm' => 'project.view_all'],
    // ── R11: 예외 프로젝트 입금·정산(프로젝트 상세 '입금·정산' 탭) — 입금 CRUD 는 계약 입금과 동일 perm ──
    'projects.payment.save'     => ['SettlementController', 'paymentSave',      'perm' => 'payment.manage', 'method' => 'POST'],
    'projects.payment.cancel'   => ['SettlementController', 'paymentCancel',    'perm' => 'payment.manage', 'method' => 'POST'],
    'projects.settlement.update'=> ['SettlementController', 'settlementUpdate', 'perm' => 'payment.manage', 'method' => 'POST'],

    // ── 공정 보드 (T6) ──
    'process.board'     => ['ProcessController', 'board', 'perm' => 'process.view'],
    'process.progress.set'    => ['ProcessController', 'progressSet', 'perm' => 'process.move', 'method' => 'POST'],
    'process.complete.confirm'=> ['ProcessController', 'completeConfirm', 'perm' => 'process.move', 'method' => 'POST'],
    'process.warranty.set'    => ['ProcessController', 'warrantySet', 'perm' => 'process.move', 'method' => 'POST'],
    'process.group.set'       => ['ProcessController', 'groupSet', 'perm' => 'process.move', 'method' => 'POST'],
    'process.memo.list'       => ['ProcessController', 'memoList', 'perm' => 'process.view'],
    'process.memo.save'       => ['ProcessController', 'memoSave', 'perm' => 'process.move', 'method' => 'POST'],
    'process.memo.delete'     => ['ProcessController', 'memoDelete', 'perm' => 'process.delete', 'method' => 'POST'],
    'process.history'   => ['ProcessController', 'history', 'perm' => 'process.view'],
    'process.history.update' => ['ProcessController', 'historyUpdate', 'perm' => 'process.move', 'method' => 'POST'],
    // 하자보수 CRUD (R4 T3 — warranty_repairs, 사진은 project_files entity_type='warranty_repair' 재사용)
    'process.warranty.save'   => ['ProcessController', 'warrantySave',   'perm' => 'project.manage', 'method' => 'POST'],
    'process.warranty.delete' => ['ProcessController', 'warrantyDelete', 'perm' => 'process.delete', 'method' => 'POST'],
    'process.warranty.photo'  => ['ProcessController', 'warrantyPhoto',  'perm' => 'project.manage', 'method' => 'POST'],
    // R8: 공사 유형 미지정 프로젝트의 관리자 1회 지정(도장/인테리어)
    'process.settype'   => ['ProcessController', 'setType', 'perm' => 'project.manage', 'method' => 'POST'],

    // ── 일정/배정 (T7) ──
    'schedule.index'    => ['ScheduleController', 'index', 'perm' => 'schedule.view_all'],
    'schedule.data'     => ['ScheduleController', 'data',  'perm' => 'schedule.view_all'],
    'schedule.save'     => ['ScheduleController', 'save',  'perm' => 'schedule.manage', 'method' => 'POST'],
    'schedule.move'     => ['ScheduleController', 'move',  'perm' => 'schedule.manage', 'method' => 'POST'],
    'schedule.delete'   => ['ScheduleController', 'delete','perm' => 'schedule.delete', 'method' => 'POST'],
    'assignments.save'  => ['AssignmentsController', 'save', 'perm' => 'project.assign', 'method' => 'POST'],
    'assignments.delete'=> ['AssignmentsController', 'delete', 'perm' => 'project.assign', 'method' => 'POST'],

    // ── 작업일지 (T7) ──
    'worklogs.index'    => ['WorklogsController', 'index', 'perm' => 'worklog.view_all', 'feature' => 'worklog'],
    'worklogs.form'     => ['WorklogsController', 'form',   'perm' => 'worklog.create', 'feature' => 'worklog'],
    'worklogs.save'     => ['WorklogsController', 'save',   'perm' => 'worklog.create', 'method' => 'POST', 'feature' => 'worklog'],
    'worklogs.show'     => ['WorklogsController', 'show', 'perm' => 'worklog.view_all', 'feature' => 'worklog'],
    'worklogs.confirm'  => ['WorklogsController', 'confirm', 'perm' => 'worklog.confirm', 'method' => 'POST', 'feature' => 'worklog'],
    'worklogs.photo'    => ['WorklogsController', 'uploadPhoto', 'perm' => 'worklog.create', 'method' => 'POST', 'feature' => 'worklog'],

    // ── 비용 (T8) ──
    'costs.save'        => ['CostsController', 'save',   'perm' => 'cost.manage', 'method' => 'POST'],
    'costs.cancel'      => ['CostsController', 'cancel', 'perm' => 'cost.delete', 'method' => 'POST'], // 물리 삭제 금지 — 상태 전환
    'costs.export'      => ['CostsController', 'export', 'perm' => 'cost.view'], // 추가 열람 검사(finance.view/cost.manage)는 컨트롤러가 유지

    // ── 목표(KPI) 관리 — R9: 조회는 컨트롤러 스코프(직원=is_public 본인 관련만), 쓰기=settings.manage ──
    'targets.index'         => ['TargetsController', 'index'],
    'targets.save'          => ['TargetsController', 'save',         'perm' => 'settings.manage', 'method' => 'POST'],
    'targets.goal.save'     => ['TargetsController', 'goalSave',     'perm' => 'settings.manage', 'method' => 'POST'],
    'targets.goal.end'      => ['TargetsController', 'goalEnd',      'perm' => 'settings.manage', 'method' => 'POST'],
    'targets.goal.delete'   => ['TargetsController', 'goalDelete',   'perm' => 'settings.manage', 'method' => 'POST'],
    'targets.goal.history'  => ['TargetsController', 'goalHistory'],  // 스코프: 컨트롤러(visibleGoalOr404)
    'targets.goal.progress' => ['TargetsController', 'goalProgress'], // 스코프: 컨트롤러(visibleGoalOr404)

    // ── 성과/리포트 (T8) ──
    'performance.index' => ['PerformanceController', 'index'],
    'performance.user'  => ['PerformanceController', 'user'],
    // ── R8: 반기 현황(매출·순이익·보너스) + 현장 보너스 원장 ──
    //    조회 perm 없음 = 컨트롤러가 Scope(본인/performance.view_all) 강제, 쓰기 = bonus.manage
    'halfyear.index'    => ['BonusController', 'overview'],
    'bonus.index'       => ['BonusController', 'index'],
    'bonus.history'     => ['BonusController', 'history'],
    'bonus.save'        => ['BonusController', 'save',   'perm' => 'bonus.manage', 'method' => 'POST'],
    'bonus.delete'      => ['BonusController', 'delete', 'perm' => 'bonus.manage', 'method' => 'POST'],
    'bonus.calc'        => ['BonusController', 'calcInfo', 'perm' => 'bonus.manage'], // 폼 자동 채움(R9-2)
    'reports.index'     => ['ReportsController', 'index',  'perm' => 'report.view'],
    'reports.data'      => ['ReportsController', 'data',   'perm' => 'report.view'],
    'reports.export'    => ['ReportsController', 'export', 'perm' => 'report.export'],
    // ── 직원 출근 분석 (R4 T4) — work_logs 기반, feature_attendance(기본 ON)로 작업일지 메뉴와 분리 게이트 ──
    // R16: 전 직원 출근 통계는 사장 전용 — attendance.manage(ADMIN_ONLY)로 승격
    'reports.attendance'        => ['ReportsController', 'attendance',       'perm' => 'attendance.manage', 'feature' => 'attendance'],
    'reports.attendance_export' => ['ReportsController', 'attendanceExport', 'perm' => 'attendance.manage', 'feature' => 'attendance'],
    // ── 근태 수동 마킹 (R6 T1) — 지각·무단결근 등록·변경·해제. perm attendance.manage(신설, super_admin 기본) ──
    'attendance.mark'           => ['AttendanceController', 'mark',   'perm' => 'attendance.manage', 'method' => 'POST', 'feature' => 'attendance'],
    'attendance.unmark'         => ['AttendanceController', 'unmark', 'perm' => 'attendance.manage', 'method' => 'POST', 'feature' => 'attendance'],

    // ── 알림 (T8) ──
    'notifications.index' => ['NotificationsController', 'index'],
    'notifications.list'  => ['NotificationsController', 'listData'],
    'notifications.read'  => ['NotificationsController', 'read', 'method' => 'POST'],
    'notifications.readall'=> ['NotificationsController', 'readAll', 'method' => 'POST'],

    // ── 직원 관리 (T8) ──
    'staff.index'       => ['StaffController', 'index',  'perm' => 'staff.view'],
    'staff.show'        => ['StaffController', 'show',   'perm' => 'staff.view'],
    'staff.form'        => ['StaffController', 'form',   'perm' => 'staff.manage'],
    'staff.save'        => ['StaffController', 'save',   'perm' => 'staff.manage', 'method' => 'POST'],
    'staff.resetpw'     => ['StaffController', 'resetPassword', 'perm' => 'staff.manage', 'method' => 'POST'],
    'staff.toggle'      => ['StaffController', 'toggleActive', 'perm' => 'staff.manage', 'method' => 'POST'],

    // ── 설정 (T6/T8) ──
    'settings.index'    => ['SettingsController', 'index',  'perm' => 'settings.manage'],
    'settings.stages'   => ['SettingsController', 'stages', 'perm' => 'settings.manage'],
    'settings.stage.save'=> ['SettingsController', 'saveStage', 'perm' => 'settings.manage', 'method' => 'POST'],
    'settings.stage.delete'=> ['SettingsController', 'deleteStage', 'perm' => 'settings.manage', 'method' => 'POST'],
    'settings.save'     => ['SettingsController', 'save', 'perm' => 'settings.manage', 'method' => 'POST'],

    // ── 감사 로그 (T8) ──
    'audit.index'       => ['AuditController', 'index', 'perm' => 'audit.view'],
];
