-- ============================================================================
-- R7 운영 더미데이터 정리 (2026-07-23, 사장 지시)
-- 삭제: 고객·파이프라인(리드)·견적·계약·입금·프로젝트·공정이력·일정·비용 등 업무 데이터 전부
-- 유지: 직원(users)·역할/권한·설정·공휴일·파이프라인/공정 단계 마스터·회사목표·감사로그(기록)
-- FK 순서: 자식 → 부모. DELETE 만 사용(TRUNCATE 금지 게이트 준수). edencrm_ prefix 전용.
-- 사전 백업: database/backups/proddb_precleanup_20260723-144118.sql
-- ============================================================================

-- 일정(참여자·슬롯은 FK CASCADE)
DELETE FROM `edencrm_schedule_time_slots`;
DELETE FROM `edencrm_schedule_participants`;
DELETE FROM `edencrm_schedules`;

-- 작업일지·근태 마킹
DELETE FROM `edencrm_work_log_photos`;
DELETE FROM `edencrm_work_logs`;
DELETE FROM `edencrm_attendance_marks`;

-- 프로젝트 하위
DELETE FROM `edencrm_warranty_repairs`;
DELETE FROM `edencrm_costs`;
DELETE FROM `edencrm_project_assignments`;
DELETE FROM `edencrm_project_files`;
DELETE FROM `edencrm_project_process_history`;
DELETE FROM `edencrm_project_status_history`;
DELETE FROM `edencrm_projects`;

-- 계약 하위 → 계약
DELETE FROM `edencrm_payments`;
DELETE FROM `edencrm_contract_terminations`;
DELETE FROM `edencrm_contract_status_history`;
DELETE FROM `edencrm_contracts`;

-- 견적 하위 → 견적
DELETE FROM `edencrm_quote_items`;
DELETE FROM `edencrm_quote_versions`;
DELETE FROM `edencrm_quotes`;

-- 고객 하위 → 리드 → 고객
DELETE FROM `edencrm_customer_activities`;
DELETE FROM `edencrm_customer_contacts`;
DELETE FROM `edencrm_leads`;
DELETE FROM `edencrm_customers`;

-- 더미 데이터에서 파생된 알림
DELETE FROM `edencrm_notifications`;

-- 새 출발용 ID 리셋(자체 prefix 테이블만 — 타 프로젝트 무접촉)
ALTER TABLE `edencrm_customers` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_leads` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_quotes` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_quote_versions` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_quote_items` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_contracts` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_payments` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_projects` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_project_assignments` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_project_status_history` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_project_process_history` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_contract_status_history` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_schedules` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_costs` AUTO_INCREMENT = 1;
ALTER TABLE `edencrm_notifications` AUTO_INCREMENT = 1;
