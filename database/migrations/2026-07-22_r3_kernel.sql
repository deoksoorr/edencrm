-- R3 커널: 공정 '대기중' 단계 + 계약→프로젝트 자동 생성 연결 스키마
-- 상태 전이 계약: 계약 active 전환 → 프로젝트 자동 생성(계약 1:1) → 프로젝트 in_progress
--                → 공정 stage 'waiting' 자동 배치(ProcessService::initWaiting) → 이후 수동 공정 이동

-- 1) 공정 첫 단계 '대기중' (sort_order 0 = 보드 최좌측/최상단 고정)
INSERT INTO `process_stages` (`stage_key`, `name`, `sort_order`, `requires_confirm`, `color`)
SELECT 'waiting', '대기중', 0, 0, '#f59e0b'
WHERE NOT EXISTS (SELECT 1 FROM `process_stages` WHERE `stage_key` = 'waiting');

-- 2) 공정 이력에 자동 변경 여부
ALTER TABLE `project_process_history`
  ADD COLUMN `is_auto` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '자동 전환 여부(계약 연동·데이터 보정 등)' AFTER `reason`;

-- 3) 프로젝트: 현재 공정 진입 일시(대기중 탭 정렬 기준) + 계약 1:1 중복 생성 방지
ALTER TABLE `projects`
  ADD COLUMN `process_entered_at` DATETIME NULL COMMENT '현재 공정 진입 일시(보드 정렬 기준, 수정일 정렬 금지)' AFTER `process_stage_id`,
  ADD UNIQUE KEY `uq_projects_contract` (`contract_id`);

-- 4) 계약: 분할 지급 비율 + 견적 전환 보존 정보
ALTER TABLE `contracts`
  ADD COLUMN `down_pct` DECIMAL(5,2) NULL COMMENT '계약금 비율(%)' AFTER `down_payment`,
  ADD COLUMN `middle_pct` DECIMAL(5,2) NULL COMMENT '중도금 비율(%)' AFTER `middle_payment`,
  ADD COLUMN `balance_pct` DECIMAL(5,2) NULL COMMENT '잔금 비율(%) — 반올림 보정 귀속' AFTER `balance_payment`,
  ADD COLUMN `quote_version_id` INT UNSIGNED NULL COMMENT '전환 시점 견적 버전' AFTER `quote_id`,
  ADD COLUMN `original_quote_amount` DECIMAL(14,0) NULL COMMENT '전환 시점 견적 총액(원본 보존)' AFTER `quote_version_id`,
  ADD COLUMN `adjust_amount` DECIMAL(14,0) NULL COMMENT '최종 계약액 − 원본 견적액(할인 음수/증액 양수)' AFTER `original_quote_amount`,
  ADD COLUMN `adjust_reason` VARCHAR(255) NULL COMMENT '할인·증액 사유' AFTER `adjust_amount`,
  ADD COLUMN `converted_at` DATETIME NULL COMMENT '견적→계약 전환 일시' AFTER `adjust_reason`,
  ADD COLUMN `converted_by` INT UNSIGNED NULL COMMENT '전환 처리자' AFTER `converted_at`;

-- 5) 기존 공정 보유 프로젝트의 진입 시각 백필(최근 공정 이력 → 없으면 updated_at)
UPDATE `projects` p
LEFT JOIN (
  SELECT project_id, MAX(changed_at) AS entered
  FROM `project_process_history` GROUP BY project_id
) h ON h.project_id = p.id
SET p.process_entered_at = COALESCE(h.entered, p.updated_at)
WHERE p.process_stage_id IS NOT NULL AND p.process_entered_at IS NULL;
