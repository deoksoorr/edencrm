-- ============================================================================
-- EDEN CRM — 전체 DDL (database/schema.sql)
-- 도장회사 내부 CRM. InnoDB / utf8mb4_unicode_ci. 이 파일은 DB 가 이미 선택된
-- 상태에서 실행된다 (CREATE DATABASE / USE 없음). 재실행 가능하도록 각 테이블
-- 앞에 DROP TABLE IF EXISTS 를 둔다.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. 조직·권한
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_key` VARCHAR(30) NOT NULL COMMENT 'super_admin/sales_manager/site_manager/staff/accountant',
  `name` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `perm_key` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `category` VARCHAR(50) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_perm_key` (`perm_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_permissions` (`role_id`, `permission_id`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
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
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_permissions`;
CREATE TABLE `user_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `is_grant` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=grant(추가부여) / 0=deny(제외)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_permissions` (`user_id`, `permission_id`),
  KEY `idx_user_permissions_user` (`user_id`),
  CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_user_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. 고객
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('individual','company') NOT NULL DEFAULT 'individual',
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
  CONSTRAINT `fk_customers_sales_user` FOREIGN KEY (`sales_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `customer_contacts`;
CREATE TABLE `customer_contacts` (
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
  CONSTRAINT `fk_customer_contacts_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `customer_activities`;
CREATE TABLE `customer_activities` (
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
  CONSTRAINT `fk_customer_activities_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_customer_activities_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. 영업 파이프라인
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS `pipeline_stages`;
CREATE TABLE `pipeline_stages` (
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

DROP TABLE IF EXISTS `leads`;
CREATE TABLE `leads` (
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
  `importance` VARCHAR(10) NULL COMMENT 'high/mid/low',
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
  CONSTRAINT `fk_leads_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_leads_sales_user` FOREIGN KEY (`sales_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_leads_stage` FOREIGN KEY (`stage_id`) REFERENCES `pipeline_stages` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. 견적
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS `quotes`;
CREATE TABLE `quotes` (
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
  CONSTRAINT `fk_quotes_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_quotes_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `quote_versions`;
CREATE TABLE `quote_versions` (
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
  CONSTRAINT `fk_quote_versions_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_quote_versions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `quote_items`;
CREATE TABLE `quote_items` (
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
  CONSTRAINT `fk_quote_items_version` FOREIGN KEY (`quote_version_id`) REFERENCES `quote_versions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. 계약·입금
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS `contracts`;
CREATE TABLE `contracts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_no` VARCHAR(30) NOT NULL,
  `quote_id` INT UNSIGNED NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `contract_date` DATE NULL,
  `contract_amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `supply_amount` DECIMAL(14,0) NULL COMMENT '공급가액(VAT 제외, 매출 인식 기준)',
  `vat_amount` DECIMAL(14,0) NULL COMMENT '부가세(예수금)',
  `down_payment` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `middle_payment` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `balance_payment` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `start_date` DATE NULL COMMENT '착공예정',
  `end_date` DATE NULL COMMENT '준공예정',
  `warranty_period` VARCHAR(20) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/active/completed/terminated',
  `payment_status` VARCHAR(20) NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid/partial/paid',
  `contract_file_id` INT UNSIGNED NULL COMMENT 'project_files.id 참조',
  `special_terms` TEXT NULL,
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
  CONSTRAINT `fk_contracts_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_contracts_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_contracts_sales_user` FOREIGN KEY (`sales_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` INT UNSIGNED NOT NULL,
  `pay_type` VARCHAR(20) NOT NULL COMMENT 'down/middle/balance/etc',
  `amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `due_date` DATE NULL,
  `paid_date` DATE NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/paid',
  `memo` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_contract` (`contract_id`),
  KEY `idx_payments_status` (`status`),
  KEY `idx_payments_due_date` (`due_date`),
  CONSTRAINT `fk_payments_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. 프로젝트·공정
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS `process_stages`;
CREATE TABLE `process_stages` (
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

DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
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
  `status` VARCHAR(20) NOT NULL DEFAULT 'preparing' COMMENT 'preparing/in_progress/paused/completed/warranty/cancelled',
  `contract_date` DATE NULL,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `actual_start_date` DATE NULL,
  `actual_end_date` DATE NULL,
  `sales_user_id` INT UNSIGNED NULL,
  `site_manager_id` INT UNSIGNED NULL,
  `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
  `importance` VARCHAR(10) NULL COMMENT 'high/mid/low',
  `contribution_mode` VARCHAR(10) NOT NULL DEFAULT 'ratio' COMMENT 'main/ratio/role',
  `memo` TEXT NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_projects_project_no` (`project_no`),
  KEY `idx_projects_customer` (`customer_id`),
  KEY `idx_projects_contract` (`contract_id`),
  KEY `idx_projects_process_stage` (`process_stage_id`),
  KEY `idx_projects_status` (`status`),
  KEY `idx_projects_sales_user` (`sales_user_id`),
  KEY `idx_projects_site_manager` (`site_manager_id`),
  KEY `idx_projects_end_date` (`end_date`),
  KEY `idx_projects_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_projects_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_projects_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_projects_process_stage` FOREIGN KEY (`process_stage_id`) REFERENCES `process_stages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_projects_sales_user` FOREIGN KEY (`sales_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_projects_site_manager` FOREIGN KEY (`site_manager_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_process_history`;
CREATE TABLE `project_process_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `from_stage_id` INT UNSIGNED NULL,
  `to_stage_id` INT UNSIGNED NOT NULL,
  `changed_by` INT UNSIGNED NULL,
  `reason` VARCHAR(255) NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pph_project` (`project_id`),
  KEY `idx_pph_to_stage` (`to_stage_id`),
  KEY `idx_pph_changed_by` (`changed_by`),
  KEY `idx_pph_changed_at` (`changed_at`),
  CONSTRAINT `fk_pph_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pph_from_stage` FOREIGN KEY (`from_stage_id`) REFERENCES `process_stages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pph_to_stage` FOREIGN KEY (`to_stage_id`) REFERENCES `process_stages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pph_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_assignments`;
CREATE TABLE `project_assignments` (
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
  PRIMARY KEY (`id`),
  KEY `idx_project_assignments_project` (`project_id`),
  KEY `idx_project_assignments_user` (`user_id`),
  KEY `idx_project_assignments_status` (`status`),
  CONSTRAINT `fk_project_assignments_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_project_assignments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. 일정·작업일지·첨부
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS `schedules`;
CREATE TABLE `schedules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NULL,
  `user_id` INT UNSIGNED NOT NULL COMMENT '주 담당(생성자/대표 참여자) — 참여자 전체는 schedule_participants',
  `title` VARCHAR(150) NOT NULL,
  `event_date` DATE NULL COMMENT '일정 날짜(시간대 슬롯 기반)',
  `slot` VARCHAR(10) NULL COMMENT '시간대 슬롯: am(오전)/pm(오후)/night(야간)',
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
  CONSTRAINT `fk_schedules_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_schedules_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 일정 참여자(다대다): 한 일정에 여러 직원 배정
DROP TABLE IF EXISTS `schedule_participants`;
CREATE TABLE `schedule_participants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sched_part` (`schedule_id`, `user_id`),
  KEY `idx_sp_user` (`user_id`),
  CONSTRAINT `fk_sp_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `work_logs`;
CREATE TABLE `work_logs` (
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
  CONSTRAINT `fk_work_logs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_work_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_work_logs_process_stage` FOREIGN KEY (`process_stage_id`) REFERENCES `process_stages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_work_logs_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_files`;
CREATE TABLE `project_files` (
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
  CONSTRAINT `fk_project_files_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_project_files_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `work_log_photos`;
CREATE TABLE `work_log_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_log_id` INT UNSIGNED NOT NULL,
  `file_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_work_log_photos_worklog` (`work_log_id`),
  KEY `idx_work_log_photos_file` (`file_id`),
  CONSTRAINT `fk_work_log_photos_worklog` FOREIGN KEY (`work_log_id`) REFERENCES `work_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_work_log_photos_file` FOREIGN KEY (`file_id`) REFERENCES `project_files` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 계약서 파일은 project_files 생성 이후 지정하는 순환참조 컬럼 — FK_CHECKS=0 상태이므로
-- contracts 테이블에 이미 컬럼은 있으나 참조 무결성은 앱 레벨에서 보장(비고 참고).

-- ----------------------------------------------------------------------------
-- 8. 원가·목표
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS `costs`;
CREATE TABLE `costs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `type` ENUM('estimate','actual') NOT NULL DEFAULT 'actual',
  `category` VARCHAR(30) NOT NULL COMMENT '자재비/인건비/일용직/외주/장비/차량/숙박/식비/폐기물/보험/추가/기타',
  `amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `spent_date` DATE NULL,
  `memo` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_costs_project` (`project_id`),
  KEY `idx_costs_type` (`type`),
  KEY `idx_costs_category` (`category`),
  KEY `idx_costs_spent_date` (`spent_date`),
  KEY `idx_costs_created_by` (`created_by`),
  CONSTRAINT `fk_costs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_costs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `targets`;
CREATE TABLE `targets` (
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
  CONSTRAINT `fk_targets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 회사 목표(월/분기/연간) — 대시보드 달성률 산정 기준
DROP TABLE IF EXISTS `company_targets`;
CREATE TABLE `company_targets` (
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

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
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
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
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
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
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

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
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

DROP TABLE IF EXISTS `holidays`;
CREATE TABLE `holidays` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `holiday_date` DATE NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_holidays_date` (`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
