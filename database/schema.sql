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
  CONSTRAINT `fk_customers_sales_user` FOREIGN KEY (`sales_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_customers_biz_license_file` FOREIGN KEY (`biz_license_file_id`) REFERENCES `project_files` (`id`) ON DELETE SET NULL
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
  `construction_type` VARCHAR(20) NULL COMMENT 'painting/interior — 프로젝트 전환 시 승계',
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
  CONSTRAINT `fk_contracts_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_contracts_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_contracts_sales_user` FOREIGN KEY (`sales_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` INT UNSIGNED NULL COMMENT '계약 입금(일반) — project_id 와 택1(R11)',
  `project_id` INT UNSIGNED NULL COMMENT '예외 프로젝트 직접 입금 — contract_id 와 택1(R11)',
  `pay_type` VARCHAR(20) NOT NULL COMMENT 'down/middle/balance/etc',
  `method` VARCHAR(20) NULL COMMENT '입금 방식: transfer/cash/card/etc(R11)',
  `kind` ENUM('payment','refund') NOT NULL DEFAULT 'payment' COMMENT '입금 구분: payment=정상 입금 / refund=환불(양수 금액, 합산 시 차감)',
  `amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `due_date` DATE NULL,
  `paid_date` DATE NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/paid/cancelled — cancelled=취소(물리 삭제 대체, 집계 제외·기록 보존)',
  `memo` VARCHAR(255) NULL,
  `payer_name` VARCHAR(100) NULL COMMENT '입금자명(R11)',
  `created_by` INT UNSIGNED NULL COMMENT '등록자(로그인 사용자, R11)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_contract` (`contract_id`),
  KEY `idx_payments_project` (`project_id`),
  KEY `idx_payments_status` (`status`),
  KEY `idx_payments_due_date` (`due_date`),
  CONSTRAINT `fk_payments_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payments_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 계약 파기 상세 — 단순 삭제 금지, 파기 정보 별도 보관.
-- 첨부는 project_files(entity_type='contract_termination', entity_id=본 테이블 id) 재사용.
DROP TABLE IF EXISTS `contract_terminations`;
CREATE TABLE `contract_terminations` (
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
  CONSTRAINT `fk_ct_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ct_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 계약 상태 이력 — 모든 상태 전환 기록(StatusService 경유).
DROP TABLE IF EXISTS `contract_status_history`;
CREATE TABLE `contract_status_history` (
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
  CONSTRAINT `fk_csh_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_csh_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. 프로젝트·공정
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS `process_stages`;
CREATE TABLE `process_stages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stage_key` VARCHAR(30) NOT NULL,
  `process_type` VARCHAR(20) NOT NULL DEFAULT 'painting' COMMENT 'painting(도장)/interior(인테리어)/common(대기·하자·완료 공통)',
  `stage_group` VARCHAR(20) NULL COMMENT 'waiting/prep/build/finish/defect/complete',
  `name` VARCHAR(50) NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `requires_confirm` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부(비활성=보드 미노출, 이력 보존)',
  `color` VARCHAR(20) NULL,
  `description` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_process_stages_key` (`stage_key`),
  KEY `idx_process_stages_sort` (`sort_order`),
  KEY `idx_process_stages_type` (`process_type`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_no` VARCHAR(30) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `customer_id` INT UNSIGNED NULL COMMENT 'R10: 예외 프로젝트는 NULL 허용(고객명 스냅샷 사용)',
  `is_exception` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '예외 프로젝트(수동 생성·계약 미연결) 여부(R10)',
  `customer_name_snapshot` VARCHAR(150) NULL COMMENT '예외 프로젝트 고객명 자유입력 스냅샷(R10)',
  `contract_id` INT UNSIGNED NULL,
  `site_address` VARCHAR(255) NULL,
  `work_type` VARCHAR(50) NULL,
  `construction_type` VARCHAR(20) NULL COMMENT 'painting(도장)/interior(인테리어)/NULL(미지정—관리자 1회 지정)',
  `contract_amount` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `expected_amount` DECIMAL(14,0) NULL COMMENT '정산 예정 금액 — 예외 프로젝트 직접 입력, 수정 시 audit 이력(R11)',
  `supply_amount` DECIMAL(14,0) NULL COMMENT '공급가액(VAT 제외, 매출 인식 기준)',
  `vat_amount` DECIMAL(14,0) NULL COMMENT '부가세(예수금)',
  `estimated_cost` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `actual_cost` DECIMAL(14,0) NOT NULL DEFAULT 0,
  `process_stage_id` INT UNSIGNED NULL,
  `process_entered_at` DATETIME NULL COMMENT '현재 공정 진입 일시(보드 정렬 기준, 수정일 정렬 금지)',
  `status` VARCHAR(20) NOT NULL DEFAULT 'preparing' COMMENT 'preparing(진행 예정)/in_progress(진행 중)/paused(일시 중단)/cancelled(취소=착공 전 철회)/terminated(파기=진행 중 계약관계 종료)/completed(완료)/warranty(하자보수)/settled(정산 완료)',
  `settlement_status` VARCHAR(20) NOT NULL DEFAULT 'unsettled' COMMENT '정산 상태: unsettled/partial/settled/refunding/hold — 공정 상태와 분리(R11)',
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
  KEY `idx_projects_construction_type` (`construction_type`),
  KEY `idx_projects_status` (`status`),
  KEY `idx_projects_settlement` (`settlement_status`),
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
  `is_auto` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '자동 전환 여부(계약 연동·데이터 보정 등)',
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

-- 하자보수 건(R4 process) — 사진은 project_files(entity_type='warranty_repair', entity_id=본 테이블 id) 재사용.
DROP TABLE IF EXISTS `warranty_repairs`;
CREATE TABLE `warranty_repairs` (
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
  CONSTRAINT `fk_wr_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wr_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wr_assignee` FOREIGN KEY (`assignee_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='하자보수 건 — 사진은 project_files(entity_type=warranty_repair) 재사용';

-- 프로젝트 상태 이력 — project_process_history 패턴 + 파기·취소 부가정보(detail_json).
DROP TABLE IF EXISTS `project_status_history`;
CREATE TABLE `project_status_history` (
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
  CONSTRAINT `fk_psh_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_psh_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
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
  `active_pair` VARCHAR(40)
    GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN CONCAT(`project_id`, '-', `user_id`) ELSE NULL END) VIRTUAL
    COMMENT 'active 배정 중복 차단용(NULL 다중 허용) — R3 schedstaff',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assign_active_pair` (`active_pair`),
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
  `event_date` DATE NULL COMMENT '일정 시작일(시간대 슬롯 기반)',
  `end_date` DATE NULL COMMENT '기간 일정 종료일(단일일=event_date 와 동일) — R7 T5',
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
  KEY `idx_schedules_end_date` (`end_date`),
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

-- 일정 시간대 슬롯(다대다, R3 schedstaff): 한 일정에 오전/오후/야간 복수 선택 — 원본 저장소
DROP TABLE IF EXISTS `schedule_time_slots`;
CREATE TABLE `schedule_time_slots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id` INT UNSIGNED NOT NULL,
  `slot` ENUM('morning','afternoon','night') NOT NULL COMMENT '오전/오후/야간',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sts_schedule_slot` (`schedule_id`, `slot`),
  KEY `idx_sts_slot` (`slot`),
  CONSTRAINT `fk_sts_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE
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
  CONSTRAINT `fk_costs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_costs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_costs_worker` FOREIGN KEY (`worker_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_costs_receipt_file` FOREIGN KEY (`receipt_file_id`) REFERENCES `project_files` (`id`) ON DELETE RESTRICT
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

-- 근태 수동 마킹(R6 T1) — 관리자가 등록하는 지각·무단결근. 자동 판정·휴가 집계는 R6 에서 폐지.
-- UNIQUE(user_id, mark_date): 같은 날 1상태만(중복 등록 원천 차단, 서버는 422).
-- 상태 변경=UPDATE, 해제=DELETE — 등록·변경·해제 모두 audit_logs 에 기록한다.
DROP TABLE IF EXISTS `attendance_marks`;
CREATE TABLE `attendance_marks` (
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
  CONSTRAINT `fk_attendance_marks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_attendance_marks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. 현장 보너스 지급 원장 + 변경 이력 ──
CREATE TABLE IF NOT EXISTS `site_bonuses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '지급 대상 직원(고유 ID 연결 — 이름 문자열 금지)',
  `project_id` INT UNSIGNED NULL COMMENT '대상 현장(프로젝트, 선택)',
  `year` SMALLINT UNSIGNED NOT NULL,
  `half` TINYINT UNSIGNED NOT NULL COMMENT '1=상반기(1/1-6/30), 2=하반기(7/1-12/31)',
  `base_amount` DECIMAL(14,0) NOT NULL DEFAULT 0 COMMENT '산정 대상 매출(프로젝트 공급가 연동)',
  `calc_basis` VARCHAR(100) NULL COMMENT '(레거시) 산정 기준 텍스트 — R9-2부터 미입력, 과거 행 표시 폴백',
  `contrib_revenue` DECIMAL(14,0) NULL COMMENT '기여도 적용 매출 = 산정 대상 매출(공급가) × 기여율(R9-2/R12)',
  `contrib_profit` DECIMAL(14,0) NULL COMMENT '적용 순이익 = (프로젝트 확정매출 공급가 − 지출) × 기여율 스냅샷(R12)',
  `bonus_rate` DECIMAL(5,2) NULL COMMENT '보너스율(%) — 산정 금액 = 기여도 적용 매출 × 보너스율/100 (R10)',
  `calc_amount` DECIMAL(14,0) NOT NULL DEFAULT 0 COMMENT '산정 금액(참고) = 기여도 적용 매출 × 보너스율/100 (R10)',
  `confirmed_bonus` DECIMAL(14,0) NOT NULL DEFAULT 0 COMMENT '확정 보너스 = 실제 지급 금액(관리자 확정, 지급완료 시 이 금액만 지급 — R12)',
  `pay_date` DATE NULL,
  `pay_status` VARCHAR(20) NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid(미지급)/paid(지급완료)/cancelled(취소) — R12: partial 폐지',
  `paid_by` INT UNSIGNED NULL COMMENT '지급 처리 담당자',
  `memo` VARCHAR(500) NULL,
  `contribution_pct_at_calc` DECIMAL(5,2) NULL COMMENT '산정 시점 기여율(담당자 변경과 무관하게 당시 배분 기준 보존)',
  `created_by` INT UNSIGNED NULL,
  `deleted_at` DATETIME NULL COMMENT '소프트 삭제(물리 삭제 금지)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sb_user_period` (`user_id`, `year`, `half`),
  KEY `idx_sb_project` (`project_id`),
  KEY `idx_sb_status` (`pay_status`),
  KEY `idx_sb_deleted` (`deleted_at`),
  CONSTRAINT `fk_sb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sb_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sb_paid_by` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sb_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_bonus_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bonus_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(20) NOT NULL COMMENT 'create/update/pay/cancel/delete',
  `before_json` TEXT NULL COMMENT '변경 전 값(JSON)',
  `after_json` TEXT NULL COMMENT '변경 후 값(JSON)',
  `reason` VARCHAR(255) NULL COMMENT '변경 사유(마감된 반기 수정 시 필수)',
  `changed_by` INT UNSIGNED NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sbh_bonus` (`bonus_id`),
  KEY `idx_sbh_changed_at` (`changed_at`),
  CONSTRAINT `fk_sbh_bonus` FOREIGN KEY (`bonus_id`) REFERENCES `site_bonuses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sbh_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- R9 목표 원장 — 유형(매출·순이익·계약액·계약건수·입금·프로젝트수) × 대상(회사·부서·직원)
--                × 기간(월·분기·반기·연간·사용자지정). 달성률·상태는 저장하지 않고
--                조회 시 GoalService 가 실제 데이터(AccountingService)로 계산한다.
-- 원장 원칙: 소프트 삭제만, 모든 변경은 goal_history 에 전/후 JSON 보존.
-- company_targets(대시보드·리포트 회사 목표)는 별도 유지 — 회귀 없음.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `goals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL COMMENT '목표명',
  `metric` VARCHAR(20) NOT NULL COMMENT 'revenue/profit/contract_amount/contract_count/payment/project_count',
  `subject_type` VARCHAR(10) NOT NULL DEFAULT 'company' COMMENT 'company/department/user',
  `user_id` INT UNSIGNED NULL COMMENT 'subject_type=user 대상 직원',
  `department_id` INT UNSIGNED NULL COMMENT 'subject_type=department 대상 부서(팀)',
  `period_type` VARCHAR(10) NOT NULL COMMENT 'month/quarter/half/year/custom',
  `year` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'custom=0',
  `period_no` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'month 1-12 / quarter 1-4 / half 1-2 / year·custom 0',
  `start_date` DATE NOT NULL COMMENT '기간 시작 — 전 기간유형 공통 실적 집계 기준',
  `end_date` DATE NOT NULL COMMENT '기간 종료',
  `target_value` DECIMAL(14,0) NOT NULL COMMENT '목표값(금액 원 / 건수)',
  `owner_user_id` INT UNSIGNED NULL COMMENT '담당 직원(관리 책임)',
  `memo` VARCHAR(500) NULL,
  `is_public` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '대상 직원 본인 열람 허용',
  `status` VARCHAR(10) NOT NULL DEFAULT 'active' COMMENT 'active/ended/cancelled — 달성/미달은 조회 시 계산',
  `status_reason` VARCHAR(255) NULL COMMENT '종료/중단 사유',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL COMMENT '소프트 삭제(물리 삭제 금지)',
  PRIMARY KEY (`id`),
  KEY `idx_goals_range` (`start_date`, `end_date`),
  KEY `idx_goals_user_period` (`user_id`, `period_type`, `year`, `period_no`),
  KEY `idx_goals_status` (`status`, `deleted_at`),
  CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_goals_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_goals_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_goals_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goal_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `goal_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(20) NOT NULL COMMENT 'create/update/end/cancel/delete',
  `before_json` TEXT NULL COMMENT '변경 전 값(JSON)',
  `after_json` TEXT NULL COMMENT '변경 후 값(JSON)',
  `reason` VARCHAR(255) NULL COMMENT '변경 사유(종료·중단·삭제 시 기록)',
  `changed_by` INT UNSIGNED NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gh_goal` (`goal_id`),
  KEY `idx_gh_changed_at` (`changed_at`),
  CONSTRAINT `fk_gh_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_gh_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
