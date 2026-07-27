-- R2 T7(refactor): costs 조회 인덱스 보강.
-- 원가 재계산(recalcProject)·소계(subtotals)·상세 목록/CSV 는 전부
-- WHERE project_id = ? AND cost_status = 'confirmed' [...] 패턴 — 복합 인덱스로 커버.
-- 기존 단일 idx_costs_project(project_id) 는 복합 인덱스의 leftmost prefix 로 중복이라 제거
-- (fk_costs_project FK 는 새 복합 인덱스가 대신 지원). spent_date 는 기존 idx_costs_spent_date 유지.
-- 적용: mysql --socket=.devdb/mysql.sock -ueden_crm_user -p'...' eden_crm < database/migrations/2026-07-22_costs_indexes.sql

ALTER TABLE `costs`
  ADD KEY `idx_costs_project_status` (`project_id`, `cost_status`),
  DROP KEY `idx_costs_project`;
