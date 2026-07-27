-- ============================================================================
-- EDEN CRM — 카페24 운영 마이그레이션 001 (database/cafe24/001_schema.sql)
-- 자동 생성: database/schema.sql 기준 (edencrm_ prefix 적용, R6 T4).
-- 대상: 공유 DB `<DB_ACCOUNT>` (MariaDB 10.6, utf8mb4_unicode_ci 각 테이블 명시).
-- 규칙: CREATE DATABASE/USE 금지 · DROP 없음(CREATE TABLE IF NOT EXISTS, 재실행 안전)
--       · 타 프로젝트 prefix(gnuland_/land_/landlanding_/opening_) 절대 미접촉
--       · FK "제약명"에만 edencrm 식별 포함(FK 명은 DB 전역 유일 — 충돌 방지).
--         UNIQUE/KEY(인덱스)명은 테이블 스코프(충돌 불가)인 데다 앱이 1062 오류
--         메시지의 인덱스명(uq_assign_active_pair)을 매칭하므로 원본 유지(변경 금지).
-- 실행 전: SHOW TABLES LIKE 'edencrm\_%' 로 기존 테이블 유무 확인(T12 절차).
-- 원본 스키마 변경 시 이 파일을 재생성할 것 (r6-dbprefix-report.md 참고).
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. 조직·권한
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edencrm_departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_key` VARCHAR(30) NOT NULL COMMENT 'super_admin/sales_manager/site_manager/staff/accountant',
  `name` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `perm_key` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `category` VARCHAR(50) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_perm_key` (`perm_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_role_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_permissions` (`role_id`, `permission_id`),
  CONSTRAINT `fk_edencrm_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `edencrm_roles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `edencrm_permissions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login_id` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `department_id` INT UNSIGNED NULL,
  `position` VARCHAR(50) NULL COMMENT '직급',
  `color` VARCHAR(7) NULL COMMENT '개인 색(고정 팔레트, 일정 표시용)',
  `role_id` INT UNSIGNED NOT NULL,
  `role_key` VARCHAR(30) NOT NULL COMMENT '비정규화 캐시(roles.role_key)',
  `hire_date` DATE NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `target_revenue` DECIMAL(14,0) NULL,
  `target_profit` DECIMAL(14,0) NULL,
  `profile_image` VARCHAR(255) NULL,
  `memo` TEXT NULL,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_login_id` (`login_id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_department` (`department_id`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_edencrm_users_department` FOREIGN KEY (`department_id`) REFERENCES `edencrm_departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_users_role` FOREIGN KEY (`role_id`) REFERENCES `edencrm_roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_user_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `is_grant` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=grant(추가부여) / 0=deny(제외)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_permissions` (`user_id`, `permission_id`),
  KEY `idx_user_permissions_user` (`user_id`),
  CONSTRAINT `fk_edencrm_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_user_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `edencrm_permissions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. 고객
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edencrm_customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('individual','company') NOT NULL DEFAULT 'individual',
  `is_business` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '사업자 여부',
  `biz_reg_no` VARCHAR(12) NULL COMMENT '사업자등록번호(000-00-00000)',
  `biz_name` VARCHAR(100) NULL COMMENT '상호(법인명)',
  `biz_ceo` VARCHAR(50) NULL COMMENT '대표자명',
  `biz_address` VARCHAR(255) NULL COMMENT '사업장 소재지',
  `biz_type` VARCHAR(100) NULL COMMENT '업태',
  `biz_item` VARCHAR(100) NULL COMMENT '종목',
  `biz_license_file_id` INT UNSIGNED NULL COMMENT '사업자등록증 파일(project_files.id)',
  `name` VARCHAR(100) NOT NULL,
  `company_name` VARCHAR(100) NULL,
  `contact_name` VARCHAR(50) NULL,
  `phone` VARCHAR(20) NULL,
  `email` VARCHAR(100) NULL,
  `address` VARCHAR(255) NULL,
  `site_address` VARCHAR(255) NULL,
  `source` VARCHAR(50) NULL COMMENT '유입경로: 홈페이지/블로그/소개/전화/현수막/재계약 등',
  `interest_type` VARCHAR(50) NULL COMMENT '관심공사(아파트도장/상가/공장/방수 등)',
  `expected_scale` VARCHAR(50) NULL,
  `expected_budget` DECIMAL(14,0) NULL,
  `desired_consult_date` DATE NULL,
  `sales_user_id` INT UNSIGNED NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active/inactive/blacklist',
  `tags` VARCHAR(255) NULL,
  `privacy_agreed` TINYINT(1) NOT NULL DEFAULT 0,
  `memo` TEXT NULL,
  `last_consult_date` DATE NULL,
  `next_contact_date` DATE NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customers_phone` (`phone`),
  KEY `idx_customers_email` (`email`),
  KEY `idx_customers_sales_user` (`sales_user_id`),
  KEY `idx_customers_status` (`status`),
  KEY `idx_customers_next_contact` (`next_contact_date`),
  KEY `idx_customers_deleted_at` (`deleted_at`),
  KEY `idx_customers_biz_reg_no` (`biz_reg_no`),
  CONSTRAINT `fk_edencrm_customers_sales_user` FOREIGN KEY (`sales_user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_customers_biz_license_file` FOREIGN KEY (`biz_license_file_id`) REFERENCES `edencrm_project_files` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_customer_contacts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `position` VARCHAR(50) NULL,
  `phone` VARCHAR(20) NULL,
  `email` VARCHAR(100) NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `memo` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_contacts_customer` (`customer_id`),
  CONSTRAINT `fk_edencrm_customer_contacts_customer` FOREIGN KEY (`customer_id`) REFERENCES `edencrm_customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_customer_activities` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `activity_type` VARCHAR(20) NOT NULL COMMENT 'call/visit/sms/email/note',
  `content` TEXT NULL,
  `activity_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_activities_customer` (`customer_id`),
  KEY `idx_customer_activities_user` (`user_id`),
  KEY `idx_customer_activities_type` (`activity_type`),
  KEY `idx_customer_activities_at` (`activity_at`),
  CONSTRAINT `fk_edencrm_customer_activities_customer` FOREIGN KEY (`customer_id`) REFERENCES `edencrm_customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edencrm_customer_activities_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. 영업 파이프라인
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edencrm_pipeline_stages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stage_key` VARCHAR(30) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_won` TINYINT(1) NOT NULL DEFAULT 0,
  `is_lost` TINYINT(1) NOT NULL DEFAULT 0,
  `color` VARCHAR(20) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pipeline_stages_key` (`stage_key`),
  KEY `idx_pipeline_stages_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_leads` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `sales_user_id` INT UNSIGNED NULL,
  `stage_id` INT UNSIGNED NOT NULL,
  `work_type` VARCHAR(50) NULL COMMENT '공사유형',
  `site_address` VARCHAR(255) NULL,
  `expected_amount` DECIMAL(14,0) NULL,
  `expected_cost` DECIMAL(14,0) NULL,
  `win_probability` DECIMAL(5,2) NULL,
  `expected_profit` DECIMAL(14,0) NULL COMMENT '앱 계산 저장(expected_amount-expected_cost)',
  `next_contact_date` DATE NULL,
  `last_activity_date` DATE NULL,
  `stage_entered_at` DATETIME NULL COMMENT '체류일수 계산용',
  `tags` VARCHAR(255) NULL,
  `memo` TEXT NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leads_customer` (`customer_id`),
  KEY `idx_leads_sales_user` (`sales_user_id`),
  KEY `idx_leads_stage` (`stage_id`),
  KEY `idx_leads_next_contact` (`next_contact_date`),
  KEY `idx_leads_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_edencrm_leads_customer` FOREIGN KEY (`customer_id`) REFERENCES `edencrm_customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_leads_sales_user` FOREIGN KEY (`sales_user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_leads_stage` FOREIGN KEY (`stage_id`) REFERENCES `edencrm_pipeline_stages` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. 견적
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edencrm_quotes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quote_no` VARCHAR(30) NOT NULL,
  `lead_id` INT UNSIGNED NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/sent/accepted/rejected/expired',
  `current_version_id` INT UNSIGNED NULL COMMENT 'quote_versions.id 참조(순환참조 방지를 위해 FK 미설정, 앱에서 무결성 보장)',
  `valid_until` DATE NULL,
  `memo` TEXT NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quotes_quote_no` (`quote_no`),
  KEY `idx_quotes_lead` (`lead_id`),
  KEY `idx_quotes_customer` (`customer_id`),
  KEY `idx_quotes_status` (`status`),
  KEY `idx_quotes_current_version` (`current_version_id`),
  KEY `idx_quotes_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_edencrm_quotes_lead` FOREIGN KEY (`lead_id`) REFERENCES `edencrm_leads` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_quotes_customer` FOREIGN KEY (`customer_id`) REFERENCES `edencrm_customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_quote_versions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quote_id` INT UNSIGNED NOT NULL,
  `version_no` INT UNSIGNED NOT NULL,
  `subtotal` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `vat` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `discount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `total_amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `note` TEXT NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quote_versions` (`quote_id`, `version_no`),
  KEY `idx_quote_versions_quote` (`quote_id`),
  KEY `idx_quote_versions_created_by` (`created_by`),
  CONSTRAINT `fk_edencrm_quote_versions_quote` FOREIGN KEY (`quote_id`) REFERENCES `edencrm_quotes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_quote_versions_created_by` FOREIGN KEY (`created_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_quote_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quote_version_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `area` DECIMAL(10,2) NULL COMMENT '면적(㎡)',
  `qty` DECIMAL(10,2) NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `material_cost` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `labor_cost` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `equipment_cost` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `outsourcing_cost` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `etc_cost` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_quote_items_version` (`quote_version_id`),
  CONSTRAINT `fk_edencrm_quote_items_version` FOREIGN KEY (`quote_version_id`) REFERENCES `edencrm_quote_versions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. 계약·입금
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edencrm_contracts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_no` VARCHAR(30) NOT NULL,
  `quote_id` INT UNSIGNED NULL,
  `quote_version_id` INT UNSIGNED NULL COMMENT '전환 시점 견적 버전',
  `original_quote_amount` DECIMAL(14,0) NULL COMMENT '전환 시점 견적 총액(원본 보존)',
  `adjust_amount` DECIMAL(14,0) NULL COMMENT '최종 계약액 − 원본 견적액(할인 음수/증액 양수)',
  `adjust_reason` VARCHAR(255) NULL COMMENT '할인·증액 사유',
  `converted_at` DATETIME NULL COMMENT '견적→계약 전환 일시',
  `converted_by` INT UNSIGNED NULL COMMENT '전환 처리자',
  `customer_id` INT UNSIGNED NOT NULL,
  `contract_date` DATE NULL,
  `contract_amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `supply_amount` DECIMAL(14,0) NULL COMMENT '공급가액(VAT 제외, 매출 인식 기준)',
  `vat_amount` DECIMAL(14,0) NULL COMMENT '부가세(예수금)',
  `down_payment` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `down_pct` DECIMAL(5,2) NULL COMMENT '계약금 비율(%)',
  `middle_payment` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `middle_pct` DECIMAL(5,2) NULL COMMENT '중도금 비율(%)',
  `balance_payment` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `balance_pct` DECIMAL(5,2) NULL COMMENT '잔금 비율(%) — 반올림 보정 귀속',
  `start_date` DATE NULL COMMENT '착공예정',
  `end_date` DATE NULL COMMENT '준공예정',
  `warranty_period` VARCHAR(20) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft(작성중)/active(계약 진행)/on_hold(계약 보류)/completed(계약 완료)/cancelled(계약 취소)/terminated(계약 파기)',
  `payment_status` VARCHAR(20) NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid/partial/paid',
  `contract_file_id` INT UNSIGNED NULL COMMENT 'project_files.id 참조',
  `special_terms` TEXT NULL,
  `work_name` VARCHAR(150) NULL COMMENT '공사명(프로젝트 자동 생성 시 프로젝트명)',
  `site_address` VARCHAR(255) NULL COMMENT '현장 주소',
  `work_type` VARCHAR(50) NULL COMMENT '공사 유형',
  `memo` TEXT NULL COMMENT '메모',
  `sales_user_id` INT UNSIGNED NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contracts_contract_no` (`contract_no`),
  KEY `idx_contracts_quote` (`quote_id`),
  KEY `idx_contracts_customer` (`customer_id`),
  KEY `idx_contracts_sales_user` (`sales_user_id`),
  KEY `idx_contracts_status` (`status`),
  KEY `idx_contracts_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_edencrm_contracts_quote` FOREIGN KEY (`quote_id`) REFERENCES `edencrm_quotes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_contracts_customer` FOREIGN KEY (`customer_id`) REFERENCES `edencrm_customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_contracts_sales_user` FOREIGN KEY (`sales_user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` INT UNSIGNED NOT NULL,
  `pay_type` VARCHAR(20) NOT NULL COMMENT 'down/middle/balance/etc',
  `kind` ENUM('payment','refund') NOT NULL DEFAULT 'payment' COMMENT '입금 구분: payment=정상 입금 / refund=환불(양수 금액, 합산 시 차감)',
  `amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `due_date` DATE NULL,
  `paid_date` DATE NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/paid/cancelled — cancelled=취소(물리 삭제 대체, 집계 제외·기록 보존)',
  `memo` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_contract` (`contract_id`),
  KEY `idx_payments_status` (`status`),
  KEY `idx_payments_due_date` (`due_date`),
  CONSTRAINT `fk_edencrm_payments_contract` FOREIGN KEY (`contract_id`) REFERENCES `edencrm_contracts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 계약 파기 상세 — 단순 삭제 금지, 파기 정보 별도 보관.
-- 첨부는 edencrm_project_files(entity_type='contract_termination', entity_id=본 테이블 id) 재사용.
CREATE TABLE IF NOT EXISTS `edencrm_contract_terminations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` INT UNSIGNED NOT NULL,
  `terminated_date` DATE NOT NULL COMMENT '파기일',
  `reason` VARCHAR(500) NOT NULL COMMENT '파기 사유',
  `processed_by` INT UNSIGNED NULL COMMENT '파기 처리자(로그인 사용자 자동)',
  `refund_amount` DECIMAL(14,0) NOT NULL DEFAULT 0 COMMENT '환불 금액(payments kind=refund 행으로도 기록)',
  `penalty_amount` DECIMAL(14,0) NOT NULL DEFAULT 0 COMMENT '위약금(별도 축 — 확정 매출 아님)',
  `settlement_amount` DECIMAL(14,0) NOT NULL DEFAULT 0 COMMENT '정산 금액(별도 축)',
  `project_action` VARCHAR(20) NULL COMMENT '연결 프로젝트 처리: cancel(취소)/terminate(파기·별도정산)/pause(중단)/keep(유지)',
  `memo` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ct_contract` (`contract_id`),
  KEY `idx_ct_processed_by` (`processed_by`),
  CONSTRAINT `fk_edencrm_ct_contract` FOREIGN KEY (`contract_id`) REFERENCES `edencrm_contracts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_ct_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 계약 상태 이력 — 모든 상태 전환 기록(StatusService 경유).
CREATE TABLE IF NOT EXISTS `edencrm_contract_status_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` INT UNSIGNED NOT NULL,
  `from_status` VARCHAR(20) NULL,
  `to_status` VARCHAR(20) NOT NULL,
  `changed_by` INT UNSIGNED NULL,
  `reason` VARCHAR(500) NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_csh_contract` (`contract_id`),
  KEY `idx_csh_changed_at` (`changed_at`),
  CONSTRAINT `fk_edencrm_csh_contract` FOREIGN KEY (`contract_id`) REFERENCES `edencrm_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edencrm_csh_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. 프로젝트·공정
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edencrm_process_stages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stage_key` VARCHAR(30) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `requires_confirm` TINYINT(1) NOT NULL DEFAULT 0,
  `color` VARCHAR(20) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_process_stages_key` (`stage_key`),
  KEY `idx_process_stages_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_no` VARCHAR(30) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `contract_id` INT UNSIGNED NULL,
  `site_address` VARCHAR(255) NULL,
  `work_type` VARCHAR(50) NULL,
  `contract_amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `supply_amount` DECIMAL(14,0) NULL COMMENT '공급가액(VAT 제외, 매출 인식 기준)',
  `vat_amount` DECIMAL(14,0) NULL COMMENT '부가세(예수금)',
  `estimated_cost` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `actual_cost` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `process_stage_id` INT UNSIGNED NULL,
  `process_entered_at` DATETIME NULL COMMENT '현재 공정 진입 일시(보드 정렬 기준, 수정일 정렬 금지)',
  `status` VARCHAR(20) NOT NULL DEFAULT 'preparing' COMMENT 'preparing(진행 예정)/in_progress(진행 중)/paused(일시 중단)/cancelled(취소=착공 전 철회)/terminated(파기=진행 중 계약관계 종료)/completed(완료)/warranty(하자보수)/settled(정산 완료)',
  `contract_date` DATE NULL,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `actual_start_date` DATE NULL,
  `actual_end_date` DATE NULL,
  `sales_user_id` INT UNSIGNED NULL,
  `site_manager_id` INT UNSIGNED NULL,
  `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
  `contribution_mode` VARCHAR(10) NOT NULL DEFAULT 'ratio' COMMENT 'main/ratio/role',
  `memo` TEXT NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_projects_project_no` (`project_no`),
  UNIQUE KEY `uq_projects_contract` (`contract_id`),
  KEY `idx_projects_customer` (`customer_id`),
  KEY `idx_projects_contract` (`contract_id`),
  KEY `idx_projects_process_stage` (`process_stage_id`),
  KEY `idx_projects_status` (`status`),
  KEY `idx_projects_sales_user` (`sales_user_id`),
  KEY `idx_projects_site_manager` (`site_manager_id`),
  KEY `idx_projects_end_date` (`end_date`),
  KEY `idx_projects_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_edencrm_projects_customer` FOREIGN KEY (`customer_id`) REFERENCES `edencrm_customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_projects_contract` FOREIGN KEY (`contract_id`) REFERENCES `edencrm_contracts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_projects_process_stage` FOREIGN KEY (`process_stage_id`) REFERENCES `edencrm_process_stages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_projects_sales_user` FOREIGN KEY (`sales_user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_projects_site_manager` FOREIGN KEY (`site_manager_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_project_process_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `from_stage_id` INT UNSIGNED NULL,
  `to_stage_id` INT UNSIGNED NOT NULL,
  `changed_by` INT UNSIGNED NULL,
  `reason` VARCHAR(255) NULL,
  `is_auto` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '자동 전환 여부(계약 연동·데이터 보정 등)',
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pph_project` (`project_id`),
  KEY `idx_pph_to_stage` (`to_stage_id`),
  KEY `idx_pph_changed_by` (`changed_by`),
  KEY `idx_pph_changed_at` (`changed_at`),
  CONSTRAINT `fk_edencrm_pph_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edencrm_pph_from_stage` FOREIGN KEY (`from_stage_id`) REFERENCES `edencrm_process_stages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_pph_to_stage` FOREIGN KEY (`to_stage_id`) REFERENCES `edencrm_process_stages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_pph_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 하자보수 건(R4 process) — 사진은 edencrm_project_files(entity_type='warranty_repair', entity_id=본 테이블 id) 재사용.
CREATE TABLE IF NOT EXISTS `edencrm_warranty_repairs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `content` VARCHAR(500) NOT NULL COMMENT '하자 내용',
  `requested_at` DATE NULL COMMENT '요청일',
  `requested_by` INT UNSIGNED NULL COMMENT '요청 접수자(users)',
  `assignee_id` INT UNSIGNED NULL COMMENT '처리 담당(users)',
  `due_date` DATE NULL COMMENT '처리 예정일',
  `completed_at` DATE NULL COMMENT '처리 완료일',
  `memo` VARCHAR(500) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open(접수)/in_progress(처리 중)/done(완료)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wr_project` (`project_id`),
  KEY `idx_wr_status` (`status`),
  KEY `idx_wr_assignee` (`assignee_id`),
  CONSTRAINT `fk_edencrm_wr_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edencrm_wr_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_wr_assignee` FOREIGN KEY (`assignee_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='하자보수 건 — 사진은 project_files(entity_type=warranty_repair) 재사용';

-- 프로젝트 상태 이력 — edencrm_project_process_history 패턴 + 파기·취소 부가정보(detail_json).
CREATE TABLE IF NOT EXISTS `edencrm_project_status_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `from_status` VARCHAR(20) NULL,
  `to_status` VARCHAR(20) NOT NULL,
  `changed_by` INT UNSIGNED NULL,
  `reason` VARCHAR(500) NULL,
  `detail_json` TEXT NULL COMMENT '파기·취소 부가정보(처리일/청구·환불 금액/정산 여부/후속 조치 등 JSON)',
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_psh_project` (`project_id`),
  KEY `idx_psh_changed_at` (`changed_at`),
  CONSTRAINT `fk_edencrm_psh_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edencrm_psh_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_project_assignments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` VARCHAR(20) NOT NULL COMMENT '현장책임자/도장작업자/보조/실측/자재/검수/영업',
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `planned_hours` DECIMAL(6,1) NULL,
  `actual_hours` DECIMAL(6,1) NULL,
  `contribution_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active/ended',
  `memo` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `active_pair` VARCHAR(40)
    GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN CONCAT(`project_id`, '-', `user_id`) ELSE NULL END) VIRTUAL
    COMMENT 'active 배정 중복 차단용(NULL 다중 허용) — R3 schedstaff',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assign_active_pair` (`active_pair`),
  KEY `idx_project_assignments_project` (`project_id`),
  KEY `idx_project_assignments_user` (`user_id`),
  KEY `idx_project_assignments_status` (`status`),
  CONSTRAINT `fk_edencrm_project_assignments_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_project_assignments_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. 일정·작업일지·첨부
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edencrm_schedules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NULL,
  `user_id` INT UNSIGNED NOT NULL COMMENT '주 담당(생성자/대표 참여자) — 참여자 전체는 schedule_participants',
  `title` VARCHAR(150) NOT NULL,
  `event_date` DATE NULL COMMENT '일정 날짜(시간대 슬롯 기반)',
  `slot` VARCHAR(10) NULL COMMENT '하위호환 미러: 첫(가장 이른) 슬롯의 legacy 키 am/pm/night — 원본은 schedule_time_slots',
  `start_datetime` DATETIME NOT NULL COMMENT '슬롯 기준 대표 시각(호환용, 앱이 event_date+slot 으로 산출)',
  `end_datetime` DATETIME NOT NULL,
  `all_day` TINYINT(1) NOT NULL DEFAULT 0,
  `type` VARCHAR(20) NOT NULL DEFAULT 'work' COMMENT 'work/meeting/etc',
  `color` VARCHAR(20) NULL COMMENT '(미사용 — 색상은 참여자 개인색에서 산출)',
  `status` VARCHAR(20) NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled/completed/cancelled',
  `memo` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_schedules_project` (`project_id`),
  KEY `idx_schedules_user` (`user_id`),
  KEY `idx_schedules_start` (`start_datetime`),
  KEY `idx_schedules_end` (`end_datetime`),
  KEY `idx_schedules_date_slot` (`event_date`, `slot`),
  CONSTRAINT `fk_edencrm_schedules_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_schedules_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 일정 참여자(다대다): 한 일정에 여러 직원 배정
CREATE TABLE IF NOT EXISTS `edencrm_schedule_participants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sched_part` (`schedule_id`, `user_id`),
  KEY `idx_sp_user` (`user_id`),
  CONSTRAINT `fk_edencrm_sp_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `edencrm_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edencrm_sp_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 일정 시간대 슬롯(다대다, R3 schedstaff): 한 일정에 오전/오후/야간 복수 선택 — 원본 저장소
CREATE TABLE IF NOT EXISTS `edencrm_schedule_time_slots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id` INT UNSIGNED NOT NULL,
  `slot` ENUM('morning','afternoon','night') NOT NULL COMMENT '오전/오후/야간',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sts_schedule_slot` (`schedule_id`, `slot`),
  KEY `idx_sts_slot` (`slot`),
  CONSTRAINT `fk_edencrm_sts_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `edencrm_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_work_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL COMMENT '작성자',
  `work_date` DATE NOT NULL,
  `start_time` TIME NULL,
  `end_time` TIME NULL,
  `process_stage_id` INT UNSIGNED NULL,
  `content` TEXT NULL,
  `materials` VARCHAR(255) NULL,
  `material_qty` VARCHAR(100) NULL,
  `equipment` VARCHAR(255) NULL,
  `weather` VARCHAR(20) NULL,
  `progress` TINYINT UNSIGNED NULL COMMENT '0-100',
  `issues` TEXT NULL,
  `delay_reason` VARCHAR(255) NULL,
  `next_work` VARCHAR(255) NULL,
  `confirmed_by` INT UNSIGNED NULL,
  `confirmed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_work_logs_project` (`project_id`),
  KEY `idx_work_logs_user` (`user_id`),
  KEY `idx_work_logs_date` (`work_date`),
  KEY `idx_work_logs_process_stage` (`process_stage_id`),
  KEY `idx_work_logs_confirmed_by` (`confirmed_by`),
  CONSTRAINT `fk_edencrm_work_logs_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_work_logs_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_work_logs_process_stage` FOREIGN KEY (`process_stage_id`) REFERENCES `edencrm_process_stages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_work_logs_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_project_files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NULL,
  `entity_type` VARCHAR(30) NULL COMMENT '범용 첨부 대상(contract/quote/worklog 등)',
  `entity_id` INT UNSIGNED NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `path` VARCHAR(500) NOT NULL,
  `size` INT UNSIGNED NOT NULL DEFAULT 0,
  `mime` VARCHAR(100) NULL,
  `uploaded_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project_files_project` (`project_id`),
  KEY `idx_project_files_entity` (`entity_type`, `entity_id`),
  KEY `idx_project_files_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_edencrm_project_files_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_project_files_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_work_log_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_log_id` INT UNSIGNED NOT NULL,
  `file_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_work_log_photos_worklog` (`work_log_id`),
  KEY `idx_work_log_photos_file` (`file_id`),
  CONSTRAINT `fk_edencrm_work_log_photos_worklog` FOREIGN KEY (`work_log_id`) REFERENCES `edencrm_work_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_edencrm_work_log_photos_file` FOREIGN KEY (`file_id`) REFERENCES `edencrm_project_files` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 계약서 파일은 edencrm_project_files 생성 이후 지정하는 순환참조 컬럼 — FK_CHECKS=0 상태이므로
-- edencrm_contracts 테이블에 이미 컬럼은 있으나 참조 무결성은 앱 레벨에서 보장(비고 참고).

-- ----------------------------------------------------------------------------
-- 8. 원가·목표
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edencrm_costs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `type` ENUM('estimate','actual') NOT NULL DEFAULT 'actual',
  `cost_status` VARCHAR(20) NOT NULL DEFAULT 'confirmed' COMMENT 'draft(임시 저장)/pending(확인 대기)/confirmed(확정)/cancelled(취소)',
  `category` VARCHAR(30) NOT NULL COMMENT 'material/labor/outsourcing/equipment/transport/meal/waste/etc',
  `item_name` VARCHAR(150) NULL COMMENT '내용/자재명',
  `spec` VARCHAR(100) NULL COMMENT '규격(예: 18L, KCC 숲으로)',
  `qty` DECIMAL(10,2) NULL COMMENT '수량(자재 등)',
  `unit` VARCHAR(20) NULL COMMENT '단위(말/EA/㎡/식 등)',
  `unit_price` DECIMAL(14,0) NULL COMMENT '단가(자재) / 일당·시급(인건)',
  `amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `worker_id` INT UNSIGNED NULL COMMENT '작업자(직원, 인건비)',
  `worker_name` VARCHAR(50) NULL COMMENT '작업자명(외부 인력 직접 입력)',
  `work_days` DECIMAL(5,2) NULL COMMENT '작업 일수(인건비)',
  `work_hours` DECIMAL(6,2) NULL COMMENT '작업 시간(인건비, 일수 대신)',
  `vendor` VARCHAR(100) NULL COMMENT '공급처/거래처(자재비 등)',
  `receipt_file_id` INT UNSIGNED NULL COMMENT '증빙 파일(project_files.id)',
  `adjust_reason` VARCHAR(255) NULL COMMENT '자동계산(수량×단가·일수×일당)과 다른 수동 금액 사유',
  `spent_date` DATE NULL,
  `memo` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_costs_project_status` (`project_id`, `cost_status`),
  KEY `idx_costs_type` (`type`),
  KEY `idx_costs_category` (`category`),
  KEY `idx_costs_spent_date` (`spent_date`),
  KEY `idx_costs_created_by` (`created_by`),
  KEY `idx_costs_cost_status` (`cost_status`),
  KEY `idx_costs_worker` (`worker_id`),
  KEY `fk_costs_receipt_file` (`receipt_file_id`),
  CONSTRAINT `fk_edencrm_costs_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_costs_created_by` FOREIGN KEY (`created_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_costs_worker` FOREIGN KEY (`worker_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_costs_receipt_file` FOREIGN KEY (`receipt_file_id`) REFERENCES `edencrm_project_files` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_targets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `year` SMALLINT UNSIGNED NOT NULL,
  `month` TINYINT UNSIGNED NOT NULL,
  `target_revenue` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `target_profit` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `target_contracts` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '목표 계약 건수',
  `target_projects` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '목표 프로젝트 수',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_targets_user_year_month` (`user_id`, `year`, `month`),
  KEY `idx_targets_user` (`user_id`),
  KEY `idx_targets_year_month` (`year`, `month`),
  CONSTRAINT `fk_edencrm_targets_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 회사 목표(월/분기/연간) — 대시보드 달성률 산정 기준
CREATE TABLE IF NOT EXISTS `edencrm_company_targets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_type` VARCHAR(10) NOT NULL COMMENT 'month/quarter/year',
  `year` SMALLINT UNSIGNED NOT NULL,
  `period_no` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'month 1-12 / quarter 1-4 / year 0',
  `target_revenue` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `target_profit` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_target` (`period_type`, `year`, `period_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. 알림·감사·설정·기타
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edencrm_notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(30) NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` VARCHAR(500) NULL,
  `link_route` VARCHAR(100) NULL,
  `link_params` VARCHAR(255) NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`),
  KEY `idx_notifications_is_read` (`is_read`),
  KEY `idx_notifications_created_at` (`created_at`),
  CONSTRAINT `fk_edencrm_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(50) NOT NULL,
  `entity` VARCHAR(50) NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `before_json` TEXT NULL,
  `after_json` TEXT NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_user` (`user_id`),
  KEY `idx_audit_logs_entity` (`entity`, `entity_id`),
  KEY `idx_audit_logs_created_at` (`created_at`),
  CONSTRAINT `fk_edencrm_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(50) NOT NULL,
  `value` VARCHAR(255) NULL,
  `group` VARCHAR(50) NULL,
  `label` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login_id` VARCHAR(50) NOT NULL,
  `ip` VARCHAR(45) NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_login_id` (`login_id`),
  KEY `idx_login_attempts_ip` (`ip`),
  KEY `idx_login_attempts_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_holidays` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `holiday_date` DATE NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_holidays_date` (`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 근태 수동 마킹(R6 T1) — 관리자가 등록하는 지각·무단결근. 자동 판정·휴가 집계는 R6 에서 폐지.
-- UNIQUE(user_id, mark_date): 같은 날 1상태만(중복 등록 원천 차단, 서버는 422).
-- 상태 변경=UPDATE, 해제=DELETE — 등록·변경·해제 모두 edencrm_audit_logs 에 기록한다.
CREATE TABLE IF NOT EXISTS `edencrm_attendance_marks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '대상 직원',
  `mark_date` DATE NOT NULL,
  `mark_type` ENUM('late','absent') NOT NULL COMMENT 'late=지각(출근일수에 포함) · absent=무단결근(출근일수에서 제외)',
  `memo` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NOT NULL COMMENT '등록 관리자(perm attendance.manage)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_marks_user_date` (`user_id`, `mark_date`),
  KEY `idx_attendance_marks_date` (`mark_date`),
  KEY `idx_attendance_marks_created_by` (`created_by`),
  CONSTRAINT `fk_edencrm_attendance_marks_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_edencrm_attendance_marks_created_by` FOREIGN KEY (`created_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
