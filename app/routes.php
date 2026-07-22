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
    'customers.export'  => ['CustomersController', 'export', 'perm' => 'customer.export'],
    'activities.save'   => ['ActivitiesController', 'save',  'perm' => 'customer.view', 'method' => 'POST'],

    // ── 영업 파이프라인 (T4) ──
    'pipeline.index'    => ['PipelineController', 'index',  'perm' => 'pipeline.view'],
    'pipeline.board'    => ['PipelineController', 'board',  'perm' => 'pipeline.view'],
    'pipeline.move'     => ['PipelineController', 'move',   'perm' => 'pipeline.manage', 'method' => 'POST'],
    'pipeline.patch'    => ['PipelineController', 'patch',  'perm' => 'pipeline.manage', 'method' => 'POST'],
    'pipeline.form'     => ['PipelineController', 'form',   'perm' => 'pipeline.manage'],
    'pipeline.save'     => ['PipelineController', 'save',   'perm' => 'pipeline.manage', 'method' => 'POST'],
    'pipeline.show'     => ['PipelineController', 'show',   'perm' => 'pipeline.view'],
    'pipeline.delete'   => ['PipelineController', 'delete', 'perm' => 'pipeline.manage', 'method' => 'POST'],

    // ── 견적 (T5) ──
    'quotes.index'      => ['QuotesController', 'index',  'perm' => 'quote.view'],
    'quotes.show'       => ['QuotesController', 'show',   'perm' => 'quote.view'],
    'quotes.form'       => ['QuotesController', 'form',   'perm' => 'quote.manage'],
    'quotes.save'       => ['QuotesController', 'save',   'perm' => 'quote.manage', 'method' => 'POST'],
    'quotes.print'      => ['QuotesController', 'printView', 'perm' => 'quote.view'],
    'quotes.delete'     => ['QuotesController', 'delete', 'perm' => 'quote.manage', 'method' => 'POST'],

    // ── 계약 (T5) ──
    'contracts.index'   => ['ContractsController', 'index',  'perm' => 'contract.view'],
    'contracts.show'    => ['ContractsController', 'show',   'perm' => 'contract.view'],
    'contracts.form'    => ['ContractsController', 'form',   'perm' => 'contract.manage'],
    'contracts.save'    => ['ContractsController', 'save',   'perm' => 'contract.manage', 'method' => 'POST'],
    'contracts.toproject'=> ['ContractsController', 'toProject', 'perm' => 'project.manage', 'method' => 'POST'],
    'payments.save'     => ['ContractsController', 'savePayment', 'perm' => 'payment.manage', 'method' => 'POST'],
    'payments.delete'   => ['ContractsController', 'deletePayment', 'perm' => 'payment.manage', 'method' => 'POST'],

    // ── 프로젝트 (T6) ──
    'projects.index'    => ['ProjectsController', 'index'],   // 범위는 컨트롤러가 권한별 처리
    'projects.show'     => ['ProjectsController', 'show'],
    'projects.form'     => ['ProjectsController', 'form',   'perm' => 'project.manage'],
    'projects.save'     => ['ProjectsController', 'save',   'perm' => 'project.manage', 'method' => 'POST'],
    'projects.delete'   => ['ProjectsController', 'delete', 'perm' => 'project.manage', 'method' => 'POST'],
    'projects.upload'   => ['ProjectsController', 'upload', 'method' => 'POST'],
    'files.download'    => ['ProjectsController', 'download'],

    // ── 공정 보드 (T6) ──
    'process.board'     => ['ProcessController', 'board'],
    'process.move'      => ['ProcessController', 'move',   'perm' => 'process.move', 'method' => 'POST'],
    'process.history'   => ['ProcessController', 'history'],
    'process.history.update' => ['ProcessController', 'historyUpdate', 'perm' => 'process.move', 'method' => 'POST'],

    // ── 일정/배정 (T7) ──
    'schedule.index'    => ['ScheduleController', 'index'],
    'schedule.data'     => ['ScheduleController', 'data'],
    'schedule.save'     => ['ScheduleController', 'save',  'perm' => 'schedule.manage', 'method' => 'POST'],
    'schedule.move'     => ['ScheduleController', 'move',  'perm' => 'schedule.manage', 'method' => 'POST'],
    'schedule.delete'   => ['ScheduleController', 'delete','perm' => 'schedule.manage', 'method' => 'POST'],
    'assignments.save'  => ['AssignmentsController', 'save', 'perm' => 'project.assign', 'method' => 'POST'],
    'assignments.delete'=> ['AssignmentsController', 'delete', 'perm' => 'project.assign', 'method' => 'POST'],

    // ── 작업일지 (T7) ──
    'worklogs.index'    => ['WorklogsController', 'index'],
    'worklogs.form'     => ['WorklogsController', 'form',   'perm' => 'worklog.create'],
    'worklogs.save'     => ['WorklogsController', 'save',   'perm' => 'worklog.create', 'method' => 'POST'],
    'worklogs.show'     => ['WorklogsController', 'show'],
    'worklogs.confirm'  => ['WorklogsController', 'confirm', 'perm' => 'worklog.confirm', 'method' => 'POST'],
    'worklogs.photo'    => ['WorklogsController', 'uploadPhoto', 'perm' => 'worklog.create', 'method' => 'POST'],

    // ── 비용 (T8) ──
    'costs.save'        => ['CostsController', 'save',   'perm' => 'cost.manage', 'method' => 'POST'],
    'costs.delete'      => ['CostsController', 'delete', 'perm' => 'cost.manage', 'method' => 'POST'],

    // ── 목표(KPI) 관리 ──
    'targets.index'     => ['TargetsController', 'index',    'perm' => 'settings.manage'],
    'targets.save'      => ['TargetsController', 'save',     'perm' => 'settings.manage', 'method' => 'POST'],

    // ── 성과/리포트 (T8) ──
    'performance.index' => ['PerformanceController', 'index'],
    'performance.user'  => ['PerformanceController', 'user'],
    'reports.index'     => ['ReportsController', 'index',  'perm' => 'report.view'],
    'reports.data'      => ['ReportsController', 'data',   'perm' => 'report.view'],
    'reports.export'    => ['ReportsController', 'export', 'perm' => 'report.export'],

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
