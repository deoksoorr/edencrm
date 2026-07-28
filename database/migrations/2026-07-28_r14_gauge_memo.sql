-- R14 (2026-07-28): 공정 게이지 보드 + 카드 일자별 메모.
-- project_stage_progress: 카드내 공정별 진행률(0~100). 실공정만(공통 예약 제외 — 앱 레벨 검증).
-- project_memos: 보드 카드 일자별 작업 메모(경량 — work_logs와 별개, 항상 ON).
CREATE TABLE IF NOT EXISTS `project_stage_progress` (
  `project_id` INT UNSIGNED NOT NULL,
  `stage_id`   INT UNSIGNED NOT NULL COMMENT '실공정(process_stages) — waiting/warranty_repair/full_complete 제외(앱 검증)',
  `pct`        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '진행률 0~100',
  `updated_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`project_id`, `stage_id`),
  KEY `idx_psp_stage` (`stage_id`),
  CONSTRAINT `fk_psp_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_psp_stage` FOREIGN KEY (`stage_id`) REFERENCES `process_stages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_psp_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_memos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `memo_date`  DATE NOT NULL COMMENT '작업 일자',
  `content`    VARCHAR(1000) NOT NULL COMMENT '작업 내용 메모',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pmemo_project_date` (`project_id`, `memo_date`),
  CONSTRAINT `fk_pmemo_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pmemo_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
