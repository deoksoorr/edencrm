-- ============================================================================
-- R4 process(T3): 공정 19단계 확장 + 하자보수 상세 테이블
--   1) process_stages 에 'full_complete'(전체완료, sort_order 19, requires_confirm 1) 추가
--      → '대기중(0) + 1~19단계' 구조. 기존 최종 공정(준공검사 17·하자보수 18) 뒤의 종결 단계.
--   2) warranty_repairs 신설 — 프로젝트별 하자보수 건 관리(내용/요청일/요청자/담당/예정일/완료일/메모/상태).
--      사진은 별도 테이블 없이 project_files(entity_type='warranty_repair', entity_id=warranty_repairs.id) 재사용.
-- 적용: mysql --socket=.devdb/mysql.sock -u eden_crm_user -p eden_crm < database/migrations/2026-07-23_r4_process19.sql
-- 롤백:
--   DELETE FROM process_stages WHERE stage_key='full_complete';  -- 참조 프로젝트 없을 때만
--   DROP TABLE IF EXISTS warranty_repairs;
-- ============================================================================

-- 1) 전체완료 단계 (id 19 고정 — seed_core 와 동일. 이미 있으면 no-op)
INSERT INTO `process_stages` (`id`, `stage_key`, `name`, `sort_order`, `requires_confirm`, `color`)
SELECT 19, 'full_complete', '전체완료', 19, 1, '#0d9488'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `process_stages` WHERE `stage_key` = 'full_complete');

-- 2) 하자보수 상세
CREATE TABLE IF NOT EXISTS `warranty_repairs` (
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
