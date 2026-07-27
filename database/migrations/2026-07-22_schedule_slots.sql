-- ============================================================================
-- R3 schedstaff(T4): 일정 시간대 복수 선택 + 직원 배정 DB 보강
--   1) schedule_time_slots 관계 테이블 신설 (일정 1건 : 슬롯 N개, 원본 저장소)
--   2) 기존 schedules.slot 단일값 백필 (am→morning, pm→afternoon, night→night)
--      - slot 값이 없고 all_day=1 → morning+afternoon
--      - slot 값이 없고 all_day=0 → start_datetime 시각 유추(~12 morning / 12~18 afternoon / 18~ night)
--   3) schedules.slot 은 하위호환 미러로 유지(첫=가장 이른 슬롯의 legacy 키 am/pm/night)
--      — 신규 저장의 원본은 schedule_time_slots (ScheduleController 가 항상 동기화)
--   4) project_assignments: status='active' 동일 (project_id, user_id) 중복을 DB 레벨 차단
--      (생성 컬럼 기반 부분 UNIQUE — status 이력 행('ended' 다수 등)과 충돌하지 않음)
-- ============================================================================

-- 1) 관계 테이블
CREATE TABLE IF NOT EXISTS `schedule_time_slots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id` INT UNSIGNED NOT NULL,
  `slot` ENUM('morning','afternoon','night') NOT NULL COMMENT '오전/오후/야간',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sts_schedule_slot` (`schedule_id`, `slot`),
  KEY `idx_sts_slot` (`slot`),
  CONSTRAINT `fk_sts_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2-a) slot 값이 있는 행 백필 (legacy am/pm/night + 방어적으로 신 키도 수용)
INSERT IGNORE INTO `schedule_time_slots` (`schedule_id`, `slot`)
SELECT id,
       CASE slot
         WHEN 'am' THEN 'morning'
         WHEN 'pm' THEN 'afternoon'
         WHEN 'night' THEN 'night'
         WHEN 'morning' THEN 'morning'
         WHEN 'afternoon' THEN 'afternoon'
       END
FROM `schedules`
WHERE slot IN ('am','pm','night','morning','afternoon');

-- 2-b) slot 값 없음 + all_day=1 → morning + afternoon
INSERT IGNORE INTO `schedule_time_slots` (`schedule_id`, `slot`)
SELECT id, 'morning' FROM `schedules`
WHERE (slot IS NULL OR slot NOT IN ('am','pm','night','morning','afternoon')) AND all_day = 1;
INSERT IGNORE INTO `schedule_time_slots` (`schedule_id`, `slot`)
SELECT id, 'afternoon' FROM `schedules`
WHERE (slot IS NULL OR slot NOT IN ('am','pm','night','morning','afternoon')) AND all_day = 1;

-- 2-c) slot 값 없음 + all_day=0 → start_datetime 시각 유추
INSERT IGNORE INTO `schedule_time_slots` (`schedule_id`, `slot`)
SELECT id,
       CASE
         WHEN HOUR(start_datetime) < 12 THEN 'morning'
         WHEN HOUR(start_datetime) < 18 THEN 'afternoon'
         ELSE 'night'
       END
FROM `schedules`
WHERE (slot IS NULL OR slot NOT IN ('am','pm','night','morning','afternoon')) AND all_day = 0;

-- 3) 하위호환 미러 정합: slot 이 비어 있던 행에 첫(가장 이른) 슬롯의 legacy 키 채움
UPDATE `schedules` s
JOIN (
  SELECT schedule_id,
         SUBSTRING_INDEX(GROUP_CONCAT(slot ORDER BY FIELD(slot,'morning','afternoon','night')), ',', 1) AS first_slot
  FROM `schedule_time_slots`
  GROUP BY schedule_id
) t ON t.schedule_id = s.id
SET s.slot = CASE t.first_slot WHEN 'morning' THEN 'am' WHEN 'afternoon' THEN 'pm' ELSE 'night' END
WHERE s.slot IS NULL OR s.slot NOT IN ('am','pm','night');

ALTER TABLE `schedules`
  MODIFY `slot` VARCHAR(10) NULL COMMENT '하위호환 미러: 첫(가장 이른) 슬롯의 legacy 키 am/pm/night — 원본은 schedule_time_slots';

-- 4) 배정 active 중복 DB 차단 (부분 UNIQUE 대안: status='active' 일 때만 값이 생기는 생성 컬럼)
ALTER TABLE `project_assignments`
  ADD COLUMN `active_pair` VARCHAR(40)
    GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN CONCAT(`project_id`, '-', `user_id`) ELSE NULL END) VIRTUAL
    COMMENT 'active 배정 중복 차단용(NULL 다중 허용) — R3 schedstaff',
  ADD UNIQUE KEY `uq_assign_active_pair` (`active_pair`);
