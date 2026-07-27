-- ============================================================================
-- EDEN CRM 개발용 최소 시드 (R6 T2 — 더미데이터 정리 재기준)
-- 직원 4명 + 무난한 일정 3건만 시딩한다. 업무 더미(고객·리드·견적·계약·프로젝트·
-- 배정·공정이력·입금·비용·작업일지·알림·타깃·하자보수·감사로그 등)는 전부 비운다.
-- 빈 데이터에서 전 화면이 0원·0건 빈 상태로 무오류 렌더되는 것이 기준선이다.
-- seed_core.sql(역할·권한·단계·설정·공휴일) 적재 후 실행. 비밀번호는 전부 password123!.
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ── 기존 업무 데이터 전체 정리 (seed_core 참조데이터는 보존) ──
DELETE FROM `user_permissions`;
DELETE FROM `customer_contacts`;
DELETE FROM `customer_activities`;
DELETE FROM `leads`;
DELETE FROM `quote_items`;
DELETE FROM `quote_versions`;
DELETE FROM `quotes`;
DELETE FROM `payments`;
DELETE FROM `contract_terminations`;
DELETE FROM `contract_status_history`;
DELETE FROM `contracts`;
DELETE FROM `warranty_repairs`;
DELETE FROM `work_log_photos`;
DELETE FROM `project_files`;
DELETE FROM `work_logs`;
DELETE FROM `costs`;
DELETE FROM `project_process_history`;
DELETE FROM `project_status_history`;
DELETE FROM `project_assignments`;
DELETE FROM `schedule_time_slots`;
DELETE FROM `schedule_participants`;
DELETE FROM `schedules`;
DELETE FROM `projects`;
DELETE FROM `customers`;
DELETE FROM `targets`;
DELETE FROM `company_targets`;
DELETE FROM `notifications`;
DELETE FROM `audit_logs`;
DELETE FROM `login_attempts`;
DELETE FROM `attendance_marks`;
DELETE FROM `users`;
DELETE FROM `departments`;

-- ── AUTO_INCREMENT 리셋(빈 테이블 전부 1부터 — 리셋 정합) ──
ALTER TABLE `user_permissions`        AUTO_INCREMENT = 1;
ALTER TABLE `customer_contacts`       AUTO_INCREMENT = 1;
ALTER TABLE `customer_activities`     AUTO_INCREMENT = 1;
ALTER TABLE `leads`                   AUTO_INCREMENT = 1;
ALTER TABLE `quote_items`             AUTO_INCREMENT = 1;
ALTER TABLE `quote_versions`          AUTO_INCREMENT = 1;
ALTER TABLE `quotes`                  AUTO_INCREMENT = 1;
ALTER TABLE `payments`                AUTO_INCREMENT = 1;
ALTER TABLE `contract_terminations`   AUTO_INCREMENT = 1;
ALTER TABLE `contract_status_history` AUTO_INCREMENT = 1;
ALTER TABLE `contracts`               AUTO_INCREMENT = 1;
ALTER TABLE `warranty_repairs`        AUTO_INCREMENT = 1;
ALTER TABLE `work_log_photos`         AUTO_INCREMENT = 1;
ALTER TABLE `project_files`           AUTO_INCREMENT = 1;
ALTER TABLE `work_logs`               AUTO_INCREMENT = 1;
ALTER TABLE `costs`                   AUTO_INCREMENT = 1;
ALTER TABLE `project_process_history` AUTO_INCREMENT = 1;
ALTER TABLE `project_status_history`  AUTO_INCREMENT = 1;
ALTER TABLE `project_assignments`     AUTO_INCREMENT = 1;
ALTER TABLE `schedule_time_slots`     AUTO_INCREMENT = 1;
ALTER TABLE `schedule_participants`   AUTO_INCREMENT = 1;
ALTER TABLE `schedules`               AUTO_INCREMENT = 1;
ALTER TABLE `projects`                AUTO_INCREMENT = 1;
ALTER TABLE `customers`               AUTO_INCREMENT = 1;
ALTER TABLE `targets`                 AUTO_INCREMENT = 1;
ALTER TABLE `company_targets`         AUTO_INCREMENT = 1;
ALTER TABLE `notifications`           AUTO_INCREMENT = 1;
ALTER TABLE `audit_logs`              AUTO_INCREMENT = 1;
ALTER TABLE `login_attempts`          AUTO_INCREMENT = 1;
ALTER TABLE `attendance_marks`        AUTO_INCREMENT = 1;
ALTER TABLE `users`                   AUTO_INCREMENT = 1;
ALTER TABLE `departments`             AUTO_INCREMENT = 1;

-- ── 부서 2 ──
INSERT INTO `departments` (`id`,`name`,`description`,`sort_order`) VALUES
(1,'현장시공팀','도장·방수 현장 시공',1),
(2,'경영지원팀','대표·관리·영업',2);

-- ── 직원 4명(대표1 + 현장팀장1 + 현장인력2) — password123! ──
INSERT INTO `users`
(`id`,`login_id`,`email`,`password_hash`,`name`,`phone`,`department_id`,`position`,`color`,`role_id`,`role_key`,`hire_date`,`status`,`must_change_password`,`created_at`) VALUES
(1,'admin','admin@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','김덕수','010-1000-0001',2,'대표','#1a56db',1,'super_admin','2019-03-01','active',0,NOW()),
(2,'chays','chays@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','차윤석','010-2000-0002',1,'현장팀장','#0f9d58',3,'site_manager','2021-05-01','active',0,NOW()),
(3,'maeng','maeng@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','맹기현','010-2000-0003',1,'도장기능공','#e8710a',4,'staff','2022-08-01','active',0,NOW()),
(4,'chaws','chaws@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','차우석','010-2000-0004',1,'도장보조','#d93025',4,'staff','2023-03-01','active',0,NOW());

-- ── 일정 3건(프로젝트 미연결 무난한 예시 — 회의·현장방문·작업) ──
--    slot 컬럼은 하위호환 미러(첫 슬롯 legacy 키 am/pm/night), 원본은 schedule_time_slots.
INSERT INTO `schedules`
(`id`,`project_id`,`user_id`,`title`,`event_date`,`slot`,`start_datetime`,`end_datetime`,`type`,`status`,`created_at`) VALUES
(1,NULL,1,'주간 업무 회의','2026-07-24','am','2026-07-24 09:00:00','2026-07-24 12:00:00','meeting','scheduled',NOW()),
(2,NULL,2,'신규 현장 방문 답사','2026-07-27','am','2026-07-27 09:00:00','2026-07-27 18:00:00','site_visit','scheduled',NOW()),
(3,NULL,3,'창고 자재 정리','2026-07-29','pm','2026-07-29 13:00:00','2026-07-29 18:00:00','work','scheduled',NOW());
INSERT INTO `schedule_participants` (`schedule_id`,`user_id`) VALUES
(1,1),(1,2),(1,3),(1,4),(2,2),(2,3),(3,3),(3,4);
INSERT INTO `schedule_time_slots` (`schedule_id`,`slot`) VALUES
(1,'morning'),(2,'morning'),(2,'afternoon'),(3,'afternoon');

SET FOREIGN_KEY_CHECKS = 1;
