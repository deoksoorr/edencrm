-- 상태 구조 개편 (R2 status 에이전트) — 기존 DB 대상 1회 적용.
-- 1) payments.kind (payment/refund) 신설: 순입금 = Σ payment − Σ refund (브리프 §1)
-- 2) contracts.status / projects.status 확정 enum 코멘트 현행화 (타입은 기존 VARCHAR 유지,
--    값 검증은 서버측 컨트롤러/StatusService 가 강제 — 기존 코드베이스 status 컬럼 규약과 동일)
-- 3) 계약 파기 상세(contract_terminations) — 단순 삭제 금지, 파기 정보 별도 보관
-- 4) 상태 이력(contract_status_history / project_status_history) — 모든 상태 전환 기록
-- 주의: ADD COLUMN IF NOT EXISTS 미사용(MySQL 8 문법 오류) — 재실행 시 중복 오류로 실패해야 정상.

-- 1) payments.kind
ALTER TABLE `payments`
  ADD COLUMN `kind` ENUM('payment','refund') NOT NULL DEFAULT 'payment'
    COMMENT '입금 구분: payment=정상 입금 / refund=환불(양수 금액, 합산 시 차감)' AFTER `pay_type`;

-- 2) 상태 enum 코멘트 현행화
ALTER TABLE `contracts`
  MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'draft'
    COMMENT 'draft(작성중)/active(계약 진행)/on_hold(계약 보류)/completed(계약 완료)/cancelled(계약 취소)/terminated(계약 파기)';
ALTER TABLE `projects`
  MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'preparing'
    COMMENT 'preparing(진행 예정)/in_progress(진행 중)/paused(일시 중단)/cancelled(취소=착공 전 철회)/terminated(파기=진행 중 계약관계 종료)/completed(완료)/warranty(하자보수)/settled(정산 완료)';

-- 3) 계약 파기 상세 (첨부는 project_files entity_type='contract_termination', entity_id=본 테이블 id)
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

-- 4) 계약 상태 이력
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

-- 5) 프로젝트 상태 이력 (project_process_history 패턴 + 파기·취소 부가정보 detail_json)
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
