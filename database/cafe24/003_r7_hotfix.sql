-- ============================================================================
-- R7 핫픽스 마이그레이션 (2026-07-23) — additive only, edencrm_ prefix 전용.
-- T5: 기간 일정 지원 컬럼(end_date) + 기존 행 backfill (멱등: IF NOT EXISTS)
-- T8: 공정 종결(full_complete)인데 상태 미완료인 레거시 정합 보정(현 운영 0건 — 안전망)
-- T10: 프로젝트가 생성된 draft 계약 → active 승격 + 상태 이력(운영 계약 1건 해당)
-- 금지: DROP/TRUNCATE/CREATE DATABASE 없음. 타 프로젝트 테이블 무접촉.
-- ============================================================================

-- (1) T5 기간 일정
ALTER TABLE `edencrm_schedules`
  ADD COLUMN IF NOT EXISTS `end_date` DATE NULL COMMENT '기간 일정 종료일(단일일=event_date 와 동일)' AFTER `event_date`;
ALTER TABLE `edencrm_schedules`
  ADD KEY IF NOT EXISTS `idx_schedules_end_date` (`end_date`);
UPDATE `edencrm_schedules` SET `end_date` = `event_date` WHERE `end_date` IS NULL;

-- (2) T8 레거시 정합: 공정=종결인데 상태가 완료 계열이 아닌 프로젝트 (이력 먼저, 멱등)
INSERT INTO `edencrm_project_status_history` (project_id, from_status, to_status, changed_by, reason)
SELECT p.id, p.status, 'completed', NULL, '공정 종결=완료 정합 보정(T8 레거시 매핑)'
  FROM `edencrm_projects` p
  JOIN `edencrm_process_stages` ps ON ps.id = p.process_stage_id
 WHERE p.deleted_at IS NULL AND ps.stage_key = 'full_complete'
   AND p.status NOT IN ('completed','settled','cancelled','terminated');

UPDATE `edencrm_projects` p
  JOIN `edencrm_process_stages` ps ON ps.id = p.process_stage_id
   SET p.status = 'completed', p.progress = 100,
       p.actual_end_date = COALESCE(p.actual_end_date, DATE(p.process_entered_at), CURDATE())
 WHERE p.deleted_at IS NULL AND ps.stage_key = 'full_complete'
   AND p.status NOT IN ('completed','settled','cancelled','terminated');

-- (3) T10 정합: 프로젝트가 있는 draft 계약 → active (이력 먼저, 멱등)
INSERT INTO `edencrm_contract_status_history` (contract_id, from_status, to_status, changed_by, reason)
SELECT c.id, 'draft', 'active', NULL, '프로젝트 전환 정합 보정(T10) — draft 인데 프로젝트 존재'
  FROM `edencrm_contracts` c
 WHERE c.deleted_at IS NULL AND c.status = 'draft'
   AND EXISTS (SELECT 1 FROM `edencrm_projects` pj WHERE pj.contract_id = c.id AND pj.deleted_at IS NULL);

UPDATE `edencrm_contracts` c
   SET c.status = 'active'
 WHERE c.deleted_at IS NULL AND c.status = 'draft'
   AND EXISTS (SELECT 1 FROM `edencrm_projects` pj WHERE pj.contract_id = c.id AND pj.deleted_at IS NULL);
