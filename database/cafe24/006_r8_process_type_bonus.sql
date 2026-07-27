-- ============================================================================
-- R8 공정 도장/인테리어 분리 + 현장 보너스 원장 (2026-07-27)
-- 1) process_stages: 공사유형(process_type)·그룹(stage_group)·사용여부·설명 컬럼 추가
--    기존 20행 = 도장/공통 분류, 인테리어 17단계 신규 시드(id 21-37 고정).
-- 2) projects/contracts: construction_type(도장 painting/인테리어 interior) 추가.
--    기존 프로젝트는 work_type 키워드로만 안전 분류(불명확 건은 NULL=미지정, 관리자 1회 지정).
-- 3) site_bonuses(지급 원장, 소프트삭제) + site_bonus_history(변경 전/후 JSON 이력).
-- 4) 권한 bonus.manage 추가. ALTER 는 1회 실행 전제(재실행 시 중복 컬럼 오류), INSERT 는 멱등.
-- ============================================================================

-- ── 1. process_stages 확장 ──
ALTER TABLE `edencrm_process_stages`
  ADD COLUMN `process_type` VARCHAR(20) NOT NULL DEFAULT 'painting' COMMENT 'painting/interior/common(대기·하자·완료)' AFTER `stage_key`,
  ADD COLUMN `stage_group` VARCHAR(20) NULL COMMENT 'waiting/prep/build/finish/defect/complete' AFTER `process_type`,
  ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부(비활성=보드 미노출, 이력 보존)' AFTER `requires_confirm`,
  ADD COLUMN `description` VARCHAR(255) NULL AFTER `color`;

ALTER TABLE `edencrm_process_stages`
  ADD INDEX `idx_process_stages_type` (`process_type`, `sort_order`);

-- 기존 20행 분류: 대기중·하자보수·전체완료 = 공통(양 탭 노출), 나머지 17단계 = 도장
UPDATE `edencrm_process_stages` SET `process_type` = 'common'
 WHERE `stage_key` IN ('waiting', 'warranty_repair', 'full_complete');

UPDATE `edencrm_process_stages` SET `stage_group` = CASE
  WHEN `stage_key` = 'waiting' THEN 'waiting'
  WHEN `stage_key` IN ('site_survey', 'drawing', 'material_order', 'prep') THEN 'prep'
  WHEN `stage_key` IN ('protection', 'pressure_wash', 'surface_prep', 'crack_repair', 'putty',
                       'waterproofing', 'primer', 'paint_1st', 'paint_2nd', 'paint_3rd', 'drying') THEN 'build'
  WHEN `stage_key` IN ('site_cleanup', 'final_inspection') THEN 'finish'
  WHEN `stage_key` = 'warranty_repair' THEN 'defect'
  WHEN `stage_key` = 'full_complete' THEN 'complete'
  ELSE `stage_group` END
 WHERE `stage_group` IS NULL;

-- 인테리어 17단계 시드(id 21-37 고정 — 환경 간 동일성). 이미 있으면 무시.
INSERT IGNORE INTO `edencrm_process_stages`
  (`id`, `stage_key`, `process_type`, `stage_group`, `name`, `sort_order`, `requires_confirm`, `is_active`, `color`) VALUES
(21, 'int_survey',      'interior', 'prep',   '현장실측',   1,  0, 1, '#94a3b8'),
(22, 'int_drawing',     'interior', 'prep',   '도면작성',   2,  0, 1, '#a1a1aa'),
(23, 'int_material',    'interior', 'prep',   '자재발주',   3,  0, 1, '#60a5fa'),
(24, 'int_prep',        'interior', 'prep',   '착공준비',   4,  0, 1, '#38bdf8'),
(25, 'int_demolition',  'interior', 'build',  '철거',       5,  0, 1, '#f87171'),
(26, 'int_facility',    'interior', 'build',  '설비',       6,  0, 1, '#fb923c'),
(27, 'int_electric',    'interior', 'build',  '전기',       7,  1, 1, '#facc15'),
(28, 'int_lightweight', 'interior', 'build',  '경량',       8,  0, 1, '#a3e635'),
(29, 'int_carpentry',   'interior', 'build',  '목공',       9,  0, 1, '#4ade80'),
(30, 'int_film',        'interior', 'build',  '필름',       10, 0, 1, '#2dd4bf'),
(31, 'int_paint',       'interior', 'build',  '도장',       11, 0, 1, '#22d3ee'),
(32, 'int_tile',        'interior', 'build',  '타일',       12, 0, 1, '#60a5fa'),
(33, 'int_floor',       'interior', 'build',  '바닥',       13, 0, 1, '#818cf8'),
(34, 'int_floor_demo',  'interior', 'build',  '바닥철거',   14, 0, 1, '#a78bfa'),
(35, 'int_deco_tile',   'interior', 'build',  '데코타일',   15, 0, 1, '#c084fc'),
(36, 'int_cleanup',     'interior', 'finish', '마무리 공정', 16, 0, 1, '#34d399'),
(37, 'int_inspection',  'interior', 'finish', '준공검수',   17, 1, 1, '#22c55e');

-- ── 2. projects/contracts 공사유형 ──
ALTER TABLE `edencrm_projects`
  ADD COLUMN `construction_type` VARCHAR(20) NULL COMMENT 'painting(도장)/interior(인테리어)/NULL(미지정—관리자 1회 지정)' AFTER `work_type`;
ALTER TABLE `edencrm_projects`
  ADD INDEX `idx_projects_construction_type` (`construction_type`);

ALTER TABLE `edencrm_contracts`
  ADD COLUMN `construction_type` VARCHAR(20) NULL COMMENT 'painting/interior — 프로젝트 전환 시 승계' AFTER `work_type`;

-- 안전 분류: 명백한 키워드 매칭만 자동 분류, 불명확 건은 NULL 유지(임의 일괄 배정 금지)
UPDATE `edencrm_projects` SET `construction_type` = 'painting'
 WHERE `construction_type` IS NULL AND `deleted_at` IS NULL
   AND `work_type` REGEXP '도장|방수|외벽|옥상|페인트|칠';
UPDATE `edencrm_projects` SET `construction_type` = 'interior'
 WHERE `construction_type` IS NULL AND `deleted_at` IS NULL
   AND `work_type` REGEXP '인테리어|타일|목공|필름|리모델링';

-- ── 3. 현장 보너스 지급 원장 + 변경 이력 ──
CREATE TABLE IF NOT EXISTS `edencrm_site_bonuses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '지급 대상 직원(고유 ID 연결 — 이름 문자열 금지)',
  `project_id` INT UNSIGNED NULL COMMENT '대상 현장(프로젝트, 선택)',
  `year` SMALLINT UNSIGNED NOT NULL,
  `half` TINYINT UNSIGNED NOT NULL COMMENT '1=상반기(1/1-6/30), 2=하반기(7/1-12/31)',
  `base_amount` DECIMAL(14,0) NOT NULL DEFAULT 0 COMMENT '보너스 산정 대상 금액',
  `calc_basis` VARCHAR(100) NULL COMMENT '산정 기준(예: 현장 순이익의 10%)',
  `calc_amount` DECIMAL(14,0) NOT NULL DEFAULT 0 COMMENT '산정 금액',
  `paid_amount` DECIMAL(14,0) NOT NULL DEFAULT 0 COMMENT '실제 지급 금액',
  `pay_date` DATE NULL,
  `pay_status` VARCHAR(20) NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid/partial/paid/cancelled',
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
  CONSTRAINT `fk_sb_user` FOREIGN KEY (`user_id`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sb_project` FOREIGN KEY (`project_id`) REFERENCES `edencrm_projects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sb_paid_by` FOREIGN KEY (`paid_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sb_created_by` FOREIGN KEY (`created_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edencrm_site_bonus_history` (
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
  CONSTRAINT `fk_sbh_bonus` FOREIGN KEY (`bonus_id`) REFERENCES `edencrm_site_bonuses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sbh_user` FOREIGN KEY (`changed_by`) REFERENCES `edencrm_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. 권한: 보너스 관리 ──
INSERT INTO `edencrm_permissions` (`perm_key`, `name`, `category`)
SELECT 'bonus.manage', '현장 보너스 등록·지급 관리', 'finance'
 WHERE NOT EXISTS (SELECT 1 FROM `edencrm_permissions` WHERE `perm_key` = 'bonus.manage');

INSERT INTO `edencrm_role_permissions` (`role_id`, `permission_id`)
SELECT 1, p.`id` FROM `edencrm_permissions` p
 WHERE p.`perm_key` = 'bonus.manage'
   AND NOT EXISTS (SELECT 1 FROM `edencrm_role_permissions` rp
                    WHERE rp.`role_id` = 1 AND rp.`permission_id` = p.`id`);
