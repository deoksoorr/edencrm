-- ============================================================================
-- EDEN CRM — 카페24 운영 마이그레이션 002 (database/cafe24/002_core.sql)
-- seed_core.sql 상당의 운영 필수 데이터(역할·권한·매핑·영업 12단계·공정 20단계·
-- 기본 설정·2026 공휴일)를 edencrm_ prefix 테이블에 적재한다. R6 T4 생성.
--
-- 원칙 (seed_core.sql 과의 차이):
--   · DELETE / AUTO_INCREMENT 리셋 없음 — 운영 데이터 파괴 금지.
--   · 전 구문 idempotent: UNIQUE 키(role_key/perm_key/stage_key/setting_key/
--     holiday_date/role_id+permission_id)를 가드로 INSERT IGNORE 사용.
--     재실행 시 기존 행을 덮어쓰지 않는다(운영에서 조정한 설정값 보존).
--   · 개발 더미(계정·고객·업무 데이터) 없음 — 관리자 계정은 T12 절차에서 별도 생성.
-- 선행: 001_schema.sql 적재 완료 상태. (MariaDB 10.6 / utf8mb4)
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 역할 (ARCHITECTURE 6절) — id 고정(1~5), role_key UNIQUE 가드
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `edencrm_roles` (`id`, `role_key`, `name`, `description`) VALUES
(1, 'super_admin',    '사장',        '전체 권한(코드상 무조건 허용)'),
(2, 'sales_manager',  '영업관리자',  '고객·영업기회·견적·계약 전체 관리'),
(3, 'site_manager',   '현장관리자',  '프로젝트 배정·공정·작업일지·비용 관리'),
(4, 'staff',          '일반직원',    '배정된 프로젝트 열람·작업일지 작성'),
(5, 'accountant',     '회계',        '계약·재무·미수금·비용 열람 및 정산');

-- ----------------------------------------------------------------------------
-- 권한 (perm_key UNIQUE 가드)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `edencrm_permissions` (`perm_key`, `name`, `category`) VALUES
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
('audit.view',          '감사 로그 열람',        'audit'),
('attendance.manage',   '근태 지각·무단결근 마킹', 'report');

-- ----------------------------------------------------------------------------
-- 역할별 권한 매핑 — role_key·perm_key 조인(고정 id 의존 제거),
-- UNIQUE(role_id, permission_id) 가드로 재실행 안전
-- ----------------------------------------------------------------------------

-- super_admin: 전체
INSERT IGNORE INTO `edencrm_role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `edencrm_roles` r CROSS JOIN `edencrm_permissions` p
WHERE r.`role_key` = 'super_admin';

-- sales_manager
INSERT IGNORE INTO `edencrm_role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `edencrm_roles` r CROSS JOIN `edencrm_permissions` p
WHERE r.`role_key` = 'sales_manager' AND p.`perm_key` IN (
  'customer.view','customer.manage','customer.delete','customer.export',
  'pipeline.view','pipeline.manage',
  'quote.view','quote.manage',
  'contract.view','contract.manage',
  'project.view_all',
  'report.view','report.export',
  'finance.view'
);

-- site_manager
INSERT IGNORE INTO `edencrm_role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `edencrm_roles` r CROSS JOIN `edencrm_permissions` p
WHERE r.`role_key` = 'site_manager' AND p.`perm_key` IN (
  'customer.view',
  'project.view_assigned','project.assign',
  'process.move',
  'schedule.view_all','schedule.manage',
  'worklog.create','worklog.view_all','worklog.confirm',
  'cost.manage'
);

-- staff
INSERT IGNORE INTO `edencrm_role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `edencrm_roles` r CROSS JOIN `edencrm_permissions` p
WHERE r.`role_key` = 'staff' AND p.`perm_key` IN (
  'project.view_assigned',
  'worklog.create'
);

-- accountant
INSERT IGNORE INTO `edencrm_role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `edencrm_roles` r CROSS JOIN `edencrm_permissions` p
WHERE r.`role_key` = 'accountant' AND p.`perm_key` IN (
  'customer.view',
  'contract.view',
  'project.view_all',
  'finance.view','payment.manage',
  'cost.manage',
  'report.view','report.export'
);

-- ----------------------------------------------------------------------------
-- 영업단계 12단계 (stage_key UNIQUE 가드, id 고정)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `edencrm_pipeline_stages` (`id`, `stage_key`, `name`, `sort_order`, `is_won`, `is_lost`, `color`) VALUES
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
-- 공정단계: 대기중(0) + 19단계 (stage_key UNIQUE 가드, id 고정)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `edencrm_process_stages` (`id`, `stage_key`, `name`, `sort_order`, `requires_confirm`, `color`) VALUES
(20, 'waiting',          '대기중',         0,  0, '#f59e0b'),
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
(18, 'warranty_repair',  '하자보수',       18, 1, '#ef4444'),
(19, 'full_complete',    '전체완료',       19, 1, '#0d9488');

-- ----------------------------------------------------------------------------
-- 기본 설정 (setting_key UNIQUE 가드 — 재실행 시 운영 조정값을 덮지 않음)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `edencrm_settings` (`setting_key`, `value`, `group`, `label`) VALUES
('app_name',            'EDEN CRM', 'general', '앱 이름'),
('vat_rate',             '10',      'finance', '부가세율(%)'),
('session_idle_min',     '60',      'security','세션 유휴 제한(분)'),
('login_max_attempts',   '5',       'security','로그인 연속 실패 허용 횟수'),
('lock_minutes',         '15',      'security','계정 잠금 시간(분)'),
('page_size',            '20',      'general', '목록 페이지당 건수'),
('upload_max_size_mb',   '10',      'upload',  '업로드 최대 용량(MB)'),
('company_name',         '에덴도장', 'general', '회사명'),
('timezone',             'Asia/Seoul','general','시간대'),
('feature_worklog',      '0',       '운영 기능','직원 작업일지 사용'),
('feature_attendance',   '1',       '운영 기능','근태 표시(대시보드 출근 현황·리포트 직원 출근 분석) — 작업일지 메뉴(feature_worklog)와 별개'),
('attendance_work_start','09:00',   '운영 기능','근태 기준 출근 시각(HH:MM) — 첫 작업 기록이 이 시각보다 늦으면 지각'),
('attendance_work_end',  '18:00',   '운영 기능','근태 기준 퇴근 시각(HH:MM) — 마지막 작업 기록이 이 시각보다 이르면 조퇴'),
('feature_pipeline_quick_alerts', '0', '운영 기능','파이프라인 연락 빠른필터(오늘 연락·연락 지남·3일+ 미접촉·계약 임박) 노출'),
('feature_dashboard_sales_alerts', '0', '운영 기능','대시보드 영업·계약 현황 주의 칩(계약 대기·계약 대기 지연·연락 예정일 경과·미접촉 고객) 노출');

-- ----------------------------------------------------------------------------
-- 2026년 대한민국 공휴일 (holiday_date UNIQUE 가드)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `edencrm_holidays` (`holiday_date`, `name`) VALUES
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
