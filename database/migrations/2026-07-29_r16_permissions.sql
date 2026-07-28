-- R16 (2026-07-29): 직원별 세부 권한(영업·현장·분석 × 읽기·쓰기·삭제).
-- 로컬 dev 전용(prefix 없음). 운영은 database/cafe24/013_r16_permissions.sql 사용.
-- 규칙: DROP/TRUNCATE 없음 · CREATE TABLE IF NOT EXISTS + INSERT IGNORE 로 재실행 안전.

-- ----------------------------------------------------------------------------
-- 1) 직원별 권한 원장 — 정규화(직원 × 리소스 1행)
--    UNIQUE(user_id, resource_key) 로 중복 조합 차단, FK CASCADE 로 직원 삭제 시 자동 정리.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `employee_permissions` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `section`      VARCHAR(20)  NOT NULL COMMENT 'sales|field|analytics',
  `resource_key` VARCHAR(50)  NOT NULL COMMENT '고정 키 — 메뉴명이 바뀌어도 불변',
  `can_read`     TINYINT(1)   NOT NULL DEFAULT 0,
  `can_write`    TINYINT(1)   NOT NULL DEFAULT 0,
  `can_delete`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '휴지통 이동까지만 — 복원·완전삭제 아님',
  `updated_by`   INT UNSIGNED NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_perm` (`user_id`, `resource_key`),
  KEY `idx_emp_perm_user` (`user_id`),
  KEY `idx_emp_perm_resource` (`resource_key`),
  CONSTRAINT `fk_emp_perm_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_emp_perm_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2) 신설 perm_key — 쓰기와 삭제를 분리하기 위한 delete 키 + 누락 read 키 + 휴지통 키.
--    기존에는 삭제 라우트가 *.manage(쓰기)를 재사용해 쓰기=삭제 상태였다.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`perm_key`, `name`, `category`) VALUES
('pipeline.delete', '영업기회 삭제',        'pipeline'),
('quote.delete',    '견적 삭제',            'quote'),
('contract.delete', '계약 삭제',            'contract'),
('project.delete',  '프로젝트 삭제',        'project'),
('process.view',    '공정 보드 열람',        'process'),
('process.delete',  '공정 메모·하자 삭제',   'process'),
('schedule.delete', '일정 삭제',            'schedule'),
('worklog.delete',  '작업일지 삭제',        'worklog'),
('cost.view',       '비용(원가) 열람',       'cost'),
('cost.delete',     '비용(원가) 취소',       'cost'),
('trash.manage',    '휴지통 조회·복원·완전삭제(최고운영자 전용)', 'trash');

-- 3) super_admin 은 전체 perm 보유 — 신설 키까지 재매핑(UNIQUE 가드로 재실행 안전).
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r CROSS JOIN `permissions` p
WHERE r.`role_key` = 'super_admin';
