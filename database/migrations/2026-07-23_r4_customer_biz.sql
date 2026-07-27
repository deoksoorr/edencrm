-- R4 T2(salescrm): 고객 사업자등록증 관리 — customers 확장
-- is_business 토글 + 사업자 정보 6필드 + 등록증 파일(project_files FK, entity_type='customer_license')
-- 실행: mysql eden_crm < database/migrations/2026-07-23_r4_customer_biz.sql

ALTER TABLE `customers`
  ADD COLUMN `is_business` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '사업자 여부' AFTER `type`,
  ADD COLUMN `biz_reg_no` VARCHAR(12) NULL COMMENT '사업자등록번호(000-00-00000)' AFTER `is_business`,
  ADD COLUMN `biz_name` VARCHAR(100) NULL COMMENT '상호(법인명)' AFTER `biz_reg_no`,
  ADD COLUMN `biz_ceo` VARCHAR(50) NULL COMMENT '대표자명' AFTER `biz_name`,
  ADD COLUMN `biz_address` VARCHAR(255) NULL COMMENT '사업장 소재지' AFTER `biz_ceo`,
  ADD COLUMN `biz_type` VARCHAR(100) NULL COMMENT '업태' AFTER `biz_address`,
  ADD COLUMN `biz_item` VARCHAR(100) NULL COMMENT '종목' AFTER `biz_type`,
  ADD COLUMN `biz_license_file_id` INT UNSIGNED NULL COMMENT '사업자등록증 파일(project_files.id)' AFTER `biz_item`,
  ADD KEY `idx_customers_biz_reg_no` (`biz_reg_no`),
  ADD CONSTRAINT `fk_customers_biz_license_file` FOREIGN KEY (`biz_license_file_id`) REFERENCES `project_files` (`id`) ON DELETE SET NULL;
