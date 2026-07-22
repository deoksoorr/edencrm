-- ============================================================================
-- EDEN CRM — 운영 필수 시드 (database/seed_core.sql)
-- 역할·권한·역할권한 매핑, 영업단계 12, 공정단계 18, 기본 설정, 2026 공휴일.
-- 운영 배포 시에도 반드시 적재해야 하는 데이터. 재실행 가능(DELETE 후 INSERT).
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 역할 (ARCHITECTURE 6절)
-- ----------------------------------------------------------------------------
DELETE FROM `role_permissions`;
DELETE FROM `roles`;
ALTER TABLE `roles` AUTO_INCREMENT = 1;
ALTER TABLE `role_permissions` AUTO_INCREMENT = 1;

INSERT INTO `roles` (`id`, `role_key`, `name`, `description`) VALUES
(1, 'super_admin',    '사장',        '전체 권한(코드상 무조건 허용)'),
(2, 'sales_manager',  '영업관리자',  '고객·영업기회·견적·계약 전체 관리'),
(3, 'site_manager',   '현장관리자',  '프로젝트 배정·공정·작업일지·비용 관리'),
(4, 'staff',          '일반직원',    '배정된 프로젝트 열람·작업일지 작성'),
(5, 'accountant',     '회계',        '계약·재무·미수금·비용 열람 및 정산');

-- ----------------------------------------------------------------------------
-- 권한 (ARCHITECTURE 6절 표 전체)
-- ----------------------------------------------------------------------------
DELETE FROM `permissions`;
ALTER TABLE `permissions` AUTO_INCREMENT = 1;

INSERT INTO `permissions` (`perm_key`, `name`, `category`) VALUES
('customer.view',       '고객 열람',            'customer'),
('customer.manage',     '고객 등록·수정',        'customer'),
('customer.delete',     '고객 삭제',            'customer'),
('customer.export',     '고객 CSV 내보내기',     'customer'),
('pipeline.view',       '영업기회 열람',         'pipeline'),
('pipeline.manage',     '영업기회 카드 생성·이동·수정', 'pipeline'),
('quote.view',          '견적 열람',            'quote'),
('quote.manage',        '견적 등록·수정',        'quote'),
('contract.view',       '계약 열람',            'contract'),
('contract.manage',     '계약 등록·수정',        'contract'),
('project.view_all',    '프로젝트 전체 열람',     'project'),
('project.view_assigned','프로젝트 담당 열람',    'project'),
('project.manage',      '프로젝트 생성·수정',     'project'),
('project.assign',      '프로젝트 직원 배정',     'project'),
('process.move',        '공정 단계 드래그 이동',   'process'),
('schedule.view_all',   '일정 전체 열람',        'schedule'),
('schedule.manage',     '일정 생성·이동',        'schedule'),
('worklog.create',      '작업일지 작성',         'worklog'),
('worklog.view_all',    '작업일지 전체 열람',     'worklog'),
('worklog.confirm',     '작업일지 관리자 확인',    'worklog'),
('cost.manage',         '비용(원가) 등록·수정',    'cost'),
('finance.view',        '손익·미수금 열람',       'finance'),
('payment.manage',      '입금·정산 관리',        'finance'),
('report.view',         '리포트 열람',           'report'),
('report.export',       '리포트 CSV 다운로드',    'report'),
('performance.view_all','전 직원 성과·기여도 열람', 'performance'),
('staff.view',          '직원 목록 열람',        'staff'),
('staff.manage',        '직원 계정 관리',        'staff'),
('settings.manage',     '시스템 설정 관리',       'settings'),
('audit.view',          '감사 로그 열람',        'audit');

-- ----------------------------------------------------------------------------
-- 역할별 권한 매핑 (ARCHITECTURE 6절 "역할별 부여" 그대로)
-- super_admin 은 코드(Rbac::can)가 무조건 true 를 반환하므로 매핑이 없어도 되지만
-- 완전성을 위해 전체 권한을 부여해 둔다.
-- ----------------------------------------------------------------------------

-- super_admin: 전체
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

-- sales_manager: customer.*, pipeline.*, quote.*, contract.*, project.view_all, report.view/export, finance.view
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` WHERE `perm_key` IN (
  'customer.view','customer.manage','customer.delete','customer.export',
  'pipeline.view','pipeline.manage',
  'quote.view','quote.manage',
  'contract.view','contract.manage',
  'project.view_all',
  'report.view','report.export',
  'finance.view'
);

-- site_manager: customer.view, project.view_assigned, project.assign, process.move,
-- schedule.view_all/manage, worklog.*, cost.manage
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` WHERE `perm_key` IN (
  'customer.view',
  'project.view_assigned','project.assign',
  'process.move',
  'schedule.view_all','schedule.manage',
  'worklog.create','worklog.view_all','worklog.confirm',
  'cost.manage'
);

-- staff: project.view_assigned, worklog.create (+본인 일정·본인 성과는 로그인만으로 허용, 권한 불필요)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, `id` FROM `permissions` WHERE `perm_key` IN (
  'project.view_assigned',
  'worklog.create'
);

-- accountant: customer.view, contract.view, project.view_all, finance.view, payment.manage,
-- cost.manage, report.view/export
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 5, `id` FROM `permissions` WHERE `perm_key` IN (
  'customer.view',
  'contract.view',
  'project.view_all',
  'finance.view','payment.manage',
  'cost.manage',
  'report.view','report.export'
);

-- ----------------------------------------------------------------------------
-- 영업단계 12단계 (신규문의 ~ 취소)
-- ----------------------------------------------------------------------------
DELETE FROM `pipeline_stages`;
ALTER TABLE `pipeline_stages` AUTO_INCREMENT = 1;

INSERT INTO `pipeline_stages` (`id`, `stage_key`, `name`, `sort_order`, `is_won`, `is_lost`, `color`) VALUES
(1,  'new_inquiry',    '신규문의',   1,  0, 0, '#94a3b8'),
(2,  'consult_booked', '상담예약',   2,  0, 0, '#60a5fa'),
(3,  'site_survey',    '현장실측',   3,  0, 0, '#38bdf8'),
(4,  'quote_drafting',  '견적작성',  4,  0, 0, '#22d3ee'),
(5,  'quote_sent',     '견적발송',   5,  0, 0, '#2dd4bf'),
(6,  'negotiating',    '협상중',     6,  0, 0, '#facc15'),
(7,  'contract_pending','계약대기',  7,  0, 0, '#fb923c'),
(8,  'contract_won',   '계약완료',   8,  1, 0, '#22c55e'),
(9,  'on_hold',        '보류',       9,  0, 0, '#a1a1aa'),
(10, 'no_response',    '장기미응답', 10, 0, 0, '#78716c'),
(11, 'lost',           '실주',       11, 0, 1, '#ef4444'),
(12, 'cancelled',      '취소',       12, 0, 1, '#b91c1c');

-- ----------------------------------------------------------------------------
-- 공정단계 18단계 (현장실측 ~ 하자보수)
-- ----------------------------------------------------------------------------
DELETE FROM `process_stages`;
ALTER TABLE `process_stages` AUTO_INCREMENT = 1;

INSERT INTO `process_stages` (`id`, `stage_key`, `name`, `sort_order`, `requires_confirm`, `color`) VALUES
(1,  'site_survey',      '현장실측',       1,  0, '#94a3b8'),
(2,  'drawing',          '도면작성',       2,  0, '#a1a1aa'),
(3,  'material_order',   '자재발주',       3,  0, '#60a5fa'),
(4,  'prep',             '착공준비',       4,  0, '#38bdf8'),
(5,  'protection',       '양생/보양',      5,  0, '#22d3ee'),
(6,  'pressure_wash',    '고압세척',       6,  0, '#2dd4bf'),
(7,  'surface_prep',     '바탕처리(면처리)',7, 0, '#4ade80'),
(8,  'crack_repair',     '크랙보수',       8,  1, '#facc15'),
(9,  'putty',            '퍼티/퍼팅',      9,  0, '#fbbf24'),
(10, 'waterproofing',    '방수처리',       10, 1, '#fb923c'),
(11, 'primer',           '프라이머',       11, 0, '#f472b6'),
(12, 'paint_1st',        '1차도장',        12, 0, '#c084fc'),
(13, 'paint_2nd',        '2차도장',        13, 0, '#a78bfa'),
(14, 'paint_3rd',        '3차도장(마감)',   14, 1, '#818cf8'),
(15, 'drying',           '건조양생',       15, 0, '#60a5fa'),
(16, 'site_cleanup',     '현장정리',       16, 0, '#34d399'),
(17, 'final_inspection', '준공검사',       17, 1, '#22c55e'),
(18, 'warranty_repair',  '하자보수',       18, 1, '#ef4444');

-- ----------------------------------------------------------------------------
-- 기본 설정
-- ----------------------------------------------------------------------------
DELETE FROM `settings`;
ALTER TABLE `settings` AUTO_INCREMENT = 1;

INSERT INTO `settings` (`setting_key`, `value`, `group`, `label`) VALUES
('app_name',            'EDEN CRM', 'general', '앱 이름'),
('vat_rate',             '10',      'finance', '부가세율(%)'),
('session_idle_min',     '60',      'security','세션 유휴 제한(분)'),
('login_max_attempts',   '5',       'security','로그인 연속 실패 허용 횟수'),
('lock_minutes',         '15',      'security','계정 잠금 시간(분)'),
('page_size',            '20',      'general', '목록 페이지당 건수'),
('upload_max_size_mb',   '10',      'upload',  '업로드 최대 용량(MB)'),
('company_name',         '에덴도장', 'general', '회사명'),
('timezone',             'Asia/Seoul','general','시간대');

-- ----------------------------------------------------------------------------
-- 2026년 대한민국 공휴일
-- ----------------------------------------------------------------------------
DELETE FROM `holidays`;
ALTER TABLE `holidays` AUTO_INCREMENT = 1;

INSERT INTO `holidays` (`holiday_date`, `name`) VALUES
('2026-01-01', '신정'),
('2026-02-16', '설날 연휴'),
('2026-02-17', '설날'),
('2026-02-18', '설날 연휴'),
('2026-03-01', '삼일절'),
('2026-03-02', '삼일절 대체공휴일'),
('2026-05-05', '어린이날'),
('2026-05-24', '부처님오신날'),
('2026-05-25', '부처님오신날 대체공휴일'),
('2026-06-06', '현충일'),
('2026-08-15', '광복절'),
('2026-08-17', '광복절 대체공휴일'),
('2026-09-24', '추석 연휴'),
('2026-09-25', '추석'),
('2026-09-26', '추석 연휴'),
('2026-09-28', '추석 대체공휴일'),
('2026-10-03', '개천절'),
('2026-10-05', '개천절 대체공휴일'),
('2026-10-09', '한글날'),
('2026-12-25', '성탄절');

SET FOREIGN_KEY_CHECKS = 1;
