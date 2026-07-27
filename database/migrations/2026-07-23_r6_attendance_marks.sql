-- R6 T1 근태 최종 구조 — 수동 지각·무단결근 마킹(attendance_marks) + perm attendance.manage 신설.
-- 배경(브리프 §1): R5 자동 지각·조퇴 판정과 휴가(vacation) 표시는 전면 비노출로 전환하고,
--   지각·무단결근은 관리자가 월간 캘린더에서 수동 등록하는 구조로 확정.
--   통계 3종만 사용: 출근일수(work_logs DISTINCT − absent 겹침 제외) · 지각(late 마크 수) · 무단결근(absent 마크 수).
-- UNIQUE(user_id, mark_date): 같은 날 1상태만 — 동시 등록 원천 차단(중복은 서버 422).
-- 상태 변경=UPDATE, 해제=DELETE(모두 Audit 기록). 기존 vacation 일정 데이터는 DB 보존(화면 미표시).

CREATE TABLE IF NOT EXISTS `attendance_marks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '대상 직원',
  `mark_date` DATE NOT NULL,
  `mark_type` ENUM('late','absent') NOT NULL COMMENT 'late=지각(출근일수에 포함) · absent=무단결근(출근일수에서 제외)',
  `memo` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NOT NULL COMMENT '등록 관리자(perm attendance.manage)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_marks_user_date` (`user_id`, `mark_date`),
  KEY `idx_attendance_marks_date` (`mark_date`),
  KEY `idx_attendance_marks_created_by` (`created_by`),
  CONSTRAINT `fk_attendance_marks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_attendance_marks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- perm attendance.manage 신설(등록·변경·해제 서버 검증용). 이미 있으면 건드리지 않는다.
INSERT INTO `permissions` (`perm_key`, `name`, `category`)
SELECT 'attendance.manage', '근태 지각·무단결근 마킹', 'report'
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `perm_key` = 'attendance.manage');

-- super_admin(role_id=1) 기본 부여 — Rbac 는 super_admin 을 코드로 무조건 허용하지만
-- seed_core 의 "완전성을 위해 전체 권한 매핑" 정책과 일관되게 매핑도 넣는다(idempotent).
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, p.`id` FROM `permissions` p
WHERE p.`perm_key` = 'attendance.manage'
  AND NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 1 AND rp.`permission_id` = p.`id`
  );
