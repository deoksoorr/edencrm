-- ============================================================================
-- EDEN CRM — 카페24 운영 롤백 (database/cafe24/rollback.sql)  ⚠️ 파괴적 작업
--
-- ██ 실행 확인 가드 ███████████████████████████████████████████████████████████
-- ██ 이 파일은 edencrm_ prefix 테이블 39개 "전체" 를 DROP 한다(데이터 소실). ██
-- ██ 실행 전 필수 절차(T12 코디네이터 전용 — 검수 에이전트 실행 금지):       ██
-- ██  1) deploy/backup.sh + DB 덤프(mysqldump --tables edencrm_...) 완료 확인 ██
-- ██  2) SHOW TABLES LIKE 'edencrm\_%' 결과가 아래 39개 목록과 일치 확인     ██
-- ██  3) 아래 안전핀(1=1 오류 유발문)을 직접 주석 처리해야만 실행 가능       ██
-- ██ 타 프로젝트 prefix(gnuland_/land_/landlanding_/opening_)·일반명 테이블은 ██
-- ██ 절대 건드리지 않는다. edencrm_ 이외의 DROP 문 추가 금지.               ██
-- ████████████████████████████████████████████████████████████████████████████

-- ▼▼ 안전핀: 실수 실행 방지용 강제 오류. 실제 롤백 시 이 한 줄을 주석 처리할 것. ▼▼
SIGNAL_SAFETY_PIN_REMOVE_THIS_LINE_TO_RUN;

SET FOREIGN_KEY_CHECKS = 0;

-- 자식(참조하는 쪽) → 부모 순. FK_CHECKS=0 이므로 순서 무관하나 가독성 위해 유지.
DROP TABLE IF EXISTS `edencrm_attendance_marks`;
DROP TABLE IF EXISTS `edencrm_holidays`;
DROP TABLE IF EXISTS `edencrm_login_attempts`;
DROP TABLE IF EXISTS `edencrm_settings`;
DROP TABLE IF EXISTS `edencrm_audit_logs`;
DROP TABLE IF EXISTS `edencrm_notifications`;
DROP TABLE IF EXISTS `edencrm_company_targets`;
DROP TABLE IF EXISTS `edencrm_targets`;
DROP TABLE IF EXISTS `edencrm_costs`;
DROP TABLE IF EXISTS `edencrm_work_log_photos`;
DROP TABLE IF EXISTS `edencrm_project_files`;
DROP TABLE IF EXISTS `edencrm_work_logs`;
DROP TABLE IF EXISTS `edencrm_schedule_time_slots`;
DROP TABLE IF EXISTS `edencrm_schedule_participants`;
DROP TABLE IF EXISTS `edencrm_schedules`;
DROP TABLE IF EXISTS `edencrm_project_assignments`;
DROP TABLE IF EXISTS `edencrm_project_status_history`;
DROP TABLE IF EXISTS `edencrm_warranty_repairs`;
DROP TABLE IF EXISTS `edencrm_project_process_history`;
DROP TABLE IF EXISTS `edencrm_projects`;
DROP TABLE IF EXISTS `edencrm_process_stages`;
DROP TABLE IF EXISTS `edencrm_contract_status_history`;
DROP TABLE IF EXISTS `edencrm_contract_terminations`;
DROP TABLE IF EXISTS `edencrm_payments`;
DROP TABLE IF EXISTS `edencrm_contracts`;
DROP TABLE IF EXISTS `edencrm_quote_items`;
DROP TABLE IF EXISTS `edencrm_quote_versions`;
DROP TABLE IF EXISTS `edencrm_quotes`;
DROP TABLE IF EXISTS `edencrm_leads`;
DROP TABLE IF EXISTS `edencrm_pipeline_stages`;
DROP TABLE IF EXISTS `edencrm_customer_activities`;
DROP TABLE IF EXISTS `edencrm_customer_contacts`;
DROP TABLE IF EXISTS `edencrm_customers`;
DROP TABLE IF EXISTS `edencrm_user_permissions`;
DROP TABLE IF EXISTS `edencrm_users`;
DROP TABLE IF EXISTS `edencrm_role_permissions`;
DROP TABLE IF EXISTS `edencrm_permissions`;
DROP TABLE IF EXISTS `edencrm_roles`;
DROP TABLE IF EXISTS `edencrm_departments`;

SET FOREIGN_KEY_CHECKS = 1;

-- 실행 후 확인: SHOW TABLES LIKE 'edencrm\_%';  → 0 rows 이어야 한다.
