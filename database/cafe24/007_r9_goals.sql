-- ============================================================================
-- R9 목표 원장 (2026-07-27) — 카페24 공유 DB(edencrm_ prefix) 전용
-- 1) goals: 유형(매출·순이익·계약액·계약건수·입금·프로젝트수) × 대상(회사·부서·직원)
--           × 기간(월·분기·반기·연간·사용자지정) 목표 원장. 소프트 삭제만.
--    달성률·상태는 저장하지 않음 — 조회 시 GoalService 가 실데이터로 계산.
-- 2) goal_history: 모든 변경 전/후 JSON 이력(등록/수정/종료/중단/삭제).
-- company_targets 는 변경하지 않는다(대시보드·리포트 무회귀).
-- CREATE 는 IF NOT EXISTS 로 멱등 — 재실행 안전.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `edencrm_goals` (
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
  CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_goals_dept` FOREIGN KEY (`department_id`) REFERENCES `edencrm_departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_goals_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_goals_created_by` FOREIGN KEY (`created_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_goal_history` (
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
  CONSTRAINT `fk_gh_goal` FOREIGN KEY (`goal_id`) REFERENCES `edencrm_goals` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_gh_user` FOREIGN KEY (`changed_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
