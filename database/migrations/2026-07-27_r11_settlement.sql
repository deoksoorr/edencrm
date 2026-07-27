-- R11 로컬(무프리픽스)판 — database/cafe24/010_r11_exception_settlement.sql 과 동일 내용.
-- 적용: php scripts/apply_local_migration.php database/migrations/2026-07-27_r11_settlement.sql

ALTER TABLE `payments`
  MODIFY `contract_id` INT UNSIGNED NULL COMMENT '계약 입금(일반) — project_id 와 택1(R11)',
  ADD COLUMN `project_id` INT UNSIGNED NULL COMMENT '예외 프로젝트 직접 입금 — contract_id 와 택1(R11)' AFTER `contract_id`,
  ADD COLUMN `method` VARCHAR(20) NULL COMMENT '입금 방식: transfer/cash/card/etc(R11)' AFTER `pay_type`,
  ADD COLUMN `payer_name` VARCHAR(100) NULL COMMENT '입금자명(R11)' AFTER `memo`,
  ADD COLUMN `created_by` INT UNSIGNED NULL COMMENT '등록자(로그인 사용자, R11)' AFTER `payer_name`,
  ADD INDEX `idx_payments_project` (`project_id`),
  ADD CONSTRAINT `fk_payments_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT;

ALTER TABLE `projects`
  ADD COLUMN `expected_amount` DECIMAL(14,0) NULL COMMENT '정산 예정 금액 — 예외 프로젝트 직접 입력, 수정 시 audit 이력(R11)' AFTER `contract_amount`,
  ADD COLUMN `settlement_status` VARCHAR(20) NOT NULL DEFAULT 'unsettled' COMMENT '정산 상태: unsettled/partial/settled/refunding/hold — 공정 상태와 분리(R11)' AFTER `status`,
  ADD INDEX `idx_projects_settlement` (`settlement_status`);

UPDATE `projects` p
LEFT JOIN `contracts` c ON c.id = p.contract_id AND c.deleted_at IS NULL
SET p.settlement_status = CASE
  WHEN p.status = 'settled' THEN 'settled'
  WHEN c.id IS NOT NULL AND COALESCE((SELECT SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)
       FROM `payments` pm WHERE pm.contract_id = c.id AND pm.status='paid'),0) > 0 THEN 'partial'
  ELSE 'unsettled' END
WHERE p.deleted_at IS NULL;
