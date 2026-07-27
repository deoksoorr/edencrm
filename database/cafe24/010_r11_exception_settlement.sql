-- ============================================================================
-- R11 (2026-07-27): 예외 프로젝트 입금·정산 — 공통 입금 원장 확장 + 정산 상태 분리
--
-- 설계(D1): 별도 테이블 대신 기존 payments 원장을 공용화한다.
--   - contract_id NULL 허용 + project_id(실 FK) 추가 — 행마다 정확히 하나만 세팅(앱 검증).
--   - source_type 은 파생값(contract_id 有 → 계약 입금 / project_id 有 → 예외 프로젝트 직접 입금).
--   - 집계(AccountingService)가 UNION 없이 단일 테이블을 유지해 중복 산식 위험 제거.
-- 정산 상태(D5): projects.settlement_status — 공정 상태와 분리.
--   unsettled(미정산)/partial(일부 정산)/settled(정산 완료·수동 전용)/refunding(환불 진행)/hold(정산 보류)
-- ============================================================================

ALTER TABLE `edencrm_payments`
  MODIFY `contract_id` INT UNSIGNED NULL COMMENT '계약 입금(일반) — project_id 와 택1(R11)',
  ADD COLUMN `project_id` INT UNSIGNED NULL COMMENT '예외 프로젝트 직접 입금 — contract_id 와 택1(R11)' AFTER `contract_id`,
  ADD COLUMN `method` VARCHAR(20) NULL COMMENT '입금 방식: transfer/cash/card/etc(R11)' AFTER `pay_type`,
  ADD COLUMN `payer_name` VARCHAR(100) NULL COMMENT '입금자명(R11)' AFTER `memo`,
  ADD COLUMN `created_by` INT UNSIGNED NULL COMMENT '등록자(로그인 사용자, R11)' AFTER `payer_name`,
  ADD INDEX `idx_payments_project` (`project_id`),
  ADD CONSTRAINT `fk_payments_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE RESTRICT;

ALTER TABLE `edencrm_projects`
  ADD COLUMN `expected_amount` DECIMAL(14,0) NULL COMMENT '정산 예정 금액 — 예외 프로젝트 직접 입력, 수정 시 audit 이력(R11)' AFTER `contract_amount`,
  ADD COLUMN `settlement_status` VARCHAR(20) NOT NULL DEFAULT 'unsettled' COMMENT '정산 상태: unsettled/partial/settled/refunding/hold — 공정 상태와 분리(R11)' AFTER `status`,
  ADD INDEX `idx_projects_settlement` (`settlement_status`);

-- 백필: 프로젝트 상태 'settled'(정산 완료)는 정산 완료로, 그 외 연결 계약 순입금>0 이면 일부 정산.
-- 완납이어도 자동 settled 로 만들지 않는다(D5 — 정산 완료는 수동 가드 전용).
UPDATE `edencrm_projects` p
LEFT JOIN `edencrm_contracts` c ON c.id = p.contract_id AND c.deleted_at IS NULL
SET p.settlement_status = CASE
  WHEN p.status = 'settled' THEN 'settled'
  WHEN c.id IS NOT NULL AND COALESCE((SELECT SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)
       FROM `edencrm_payments` pm WHERE pm.contract_id = c.id AND pm.status='paid'),0) > 0 THEN 'partial'
  ELSE 'unsettled' END
WHERE p.deleted_at IS NULL;
