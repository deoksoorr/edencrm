-- @5-1 quote_versions 전수(음수 추적)
SELECT v.id, v.quote_id, v.version_no, v.subtotal, v.vat, v.discount, v.total_amount, v.created_by, v.created_at, q.quote_no, q.status, q.deleted_at FROM {P}quote_versions v JOIN {P}quotes q ON q.id=v.quote_id ORDER BY v.id
---
-- @5-1b quote_items 전수
SELECT i.id, i.quote_version_id, i.name, i.area, i.qty, i.unit_price, i.material_cost, i.labor_cost, i.equipment_cost, i.outsourcing_cost, i.etc_cost, i.amount, (i.qty*i.unit_price) AS qty_x_price FROM {P}quote_items i ORDER BY i.id
---
-- @5-2 견적 전수
SELECT id, quote_no, lead_id, customer_id, status, current_version_id, valid_until, deleted_at, created_at FROM {P}quotes ORDER BY id
---
-- @6-1 상태값 분포: contracts.status / payment_status
SELECT 'contracts.status' AS col, status AS val, COUNT(*) AS c FROM {P}contracts GROUP BY status
UNION ALL SELECT 'contracts.payment_status', payment_status, COUNT(*) FROM {P}contracts GROUP BY payment_status
UNION ALL SELECT 'projects.status', status, COUNT(*) FROM {P}projects GROUP BY status
UNION ALL SELECT 'projects.settlement_status', settlement_status, COUNT(*) FROM {P}projects GROUP BY settlement_status
UNION ALL SELECT 'projects.contribution_mode', contribution_mode, COUNT(*) FROM {P}projects GROUP BY contribution_mode
UNION ALL SELECT 'quotes.status', status, COUNT(*) FROM {P}quotes GROUP BY status
UNION ALL SELECT 'payments.status', status, COUNT(*) FROM {P}payments GROUP BY status
UNION ALL SELECT 'payments.pay_type', pay_type, COUNT(*) FROM {P}payments GROUP BY pay_type
UNION ALL SELECT 'payments.method', COALESCE(method,'(NULL)'), COUNT(*) FROM {P}payments GROUP BY method
UNION ALL SELECT 'payments.kind', kind, COUNT(*) FROM {P}payments GROUP BY kind
UNION ALL SELECT 'customers.status', status, COUNT(*) FROM {P}customers GROUP BY status
UNION ALL SELECT 'customers.type', type, COUNT(*) FROM {P}customers GROUP BY type
UNION ALL SELECT 'costs.type', type, COUNT(*) FROM {P}costs GROUP BY type
UNION ALL SELECT 'costs.cost_status', cost_status, COUNT(*) FROM {P}costs GROUP BY cost_status
UNION ALL SELECT 'costs.category', category, COUNT(*) FROM {P}costs GROUP BY category
UNION ALL SELECT 'schedules.status', status, COUNT(*) FROM {P}schedules GROUP BY status
UNION ALL SELECT 'schedules.type', type, COUNT(*) FROM {P}schedules GROUP BY type
UNION ALL SELECT 'schedules.slot', COALESCE(slot,'(NULL)'), COUNT(*) FROM {P}schedules GROUP BY slot
UNION ALL SELECT 'site_bonuses.pay_status', pay_status, COUNT(*) FROM {P}site_bonuses GROUP BY pay_status
UNION ALL SELECT 'site_bonuses.calc_basis', COALESCE(calc_basis,'(NULL)'), COUNT(*) FROM {P}site_bonuses GROUP BY calc_basis
UNION ALL SELECT 'project_assignments.status', status, COUNT(*) FROM {P}project_assignments GROUP BY status
UNION ALL SELECT 'project_assignments.role', role, COUNT(*) FROM {P}project_assignments GROUP BY role
UNION ALL SELECT 'users.status', status, COUNT(*) FROM {P}users GROUP BY status
UNION ALL SELECT 'users.role_key', role_key, COUNT(*) FROM {P}users GROUP BY role_key
UNION ALL SELECT 'process_stages.process_type', process_type, COUNT(*) FROM {P}process_stages GROUP BY process_type
UNION ALL SELECT 'process_stages.stage_group', COALESCE(stage_group,'(NULL)'), COUNT(*) FROM {P}process_stages GROUP BY stage_group
UNION ALL SELECT 'employee_permissions.section', section, COUNT(*) FROM {P}employee_permissions GROUP BY section
UNION ALL SELECT 'employee_permissions.resource_key', resource_key, COUNT(*) FROM {P}employee_permissions GROUP BY resource_key
UNION ALL SELECT 'attendance_marks.mark_type', mark_type, COUNT(*) FROM {P}attendance_marks GROUP BY mark_type
---
-- @6-2 users.role_key vs roles.role_key 비정규화 캐시 불일치
SELECT u.id, u.login_id, u.role_id, u.role_key AS cached, r.role_key AS actual FROM {P}users u JOIN {P}roles r ON r.id=u.role_id WHERE u.role_key<>r.role_key
---
-- @6-3 상태 이력의 to_status 분포(코드 기대값 대조용)
SELECT 'contract_status_history.to_status' AS col, to_status AS val, COUNT(*) AS c FROM {P}contract_status_history GROUP BY to_status
UNION ALL SELECT 'contract_status_history.from_status', COALESCE(from_status,'(NULL)'), COUNT(*) FROM {P}contract_status_history GROUP BY from_status
UNION ALL SELECT 'project_status_history.to_status', to_status, COUNT(*) FROM {P}project_status_history GROUP BY to_status
UNION ALL SELECT 'project_status_history.from_status', COALESCE(from_status,'(NULL)'), COUNT(*) FROM {P}project_status_history GROUP BY from_status
---
-- @7-1 날짜: 시작일 > 종료일
SELECT 'contracts' AS t, id, start_date AS s, end_date AS e FROM {P}contracts WHERE start_date IS NOT NULL AND end_date IS NOT NULL AND start_date>end_date
UNION ALL SELECT 'projects(계획)', id, start_date, end_date FROM {P}projects WHERE start_date IS NOT NULL AND end_date IS NOT NULL AND start_date>end_date
UNION ALL SELECT 'projects(실제)', id, actual_start_date, actual_end_date FROM {P}projects WHERE actual_start_date IS NOT NULL AND actual_end_date IS NOT NULL AND actual_start_date>actual_end_date
UNION ALL SELECT 'schedules', id, event_date, end_date FROM {P}schedules WHERE event_date IS NOT NULL AND end_date IS NOT NULL AND event_date>end_date
UNION ALL SELECT 'project_assignments', id, start_date, end_date FROM {P}project_assignments WHERE start_date IS NOT NULL AND end_date IS NOT NULL AND start_date>end_date
UNION ALL SELECT 'goals', id, start_date, end_date FROM {P}goals WHERE start_date>end_date
UNION ALL SELECT 'warranty_repairs', id, requested_at, completed_at FROM {P}warranty_repairs WHERE requested_at IS NOT NULL AND completed_at IS NOT NULL AND requested_at>completed_at
---
-- @7-1b schedules: start_datetime > end_datetime
SELECT id, project_id, user_id, title, event_date, end_date, start_datetime, end_datetime, slot, type, status FROM {P}schedules ORDER BY id
---
-- @7-2 0000-00-00 / 제로 날짜
SELECT 'contracts' AS t, COUNT(*) AS c FROM {P}contracts WHERE CAST(contract_date AS CHAR) LIKE '0000%' OR CAST(start_date AS CHAR) LIKE '0000%' OR CAST(end_date AS CHAR) LIKE '0000%'
UNION ALL SELECT 'projects', COUNT(*) FROM {P}projects WHERE CAST(contract_date AS CHAR) LIKE '0000%' OR CAST(start_date AS CHAR) LIKE '0000%' OR CAST(end_date AS CHAR) LIKE '0000%' OR CAST(actual_start_date AS CHAR) LIKE '0000%' OR CAST(actual_end_date AS CHAR) LIKE '0000%'
UNION ALL SELECT 'payments', COUNT(*) FROM {P}payments WHERE CAST(due_date AS CHAR) LIKE '0000%' OR CAST(paid_date AS CHAR) LIKE '0000%'
UNION ALL SELECT 'customers', COUNT(*) FROM {P}customers WHERE CAST(desired_consult_date AS CHAR) LIKE '0000%' OR CAST(last_consult_date AS CHAR) LIKE '0000%' OR CAST(next_contact_date AS CHAR) LIKE '0000%'
UNION ALL SELECT 'costs', COUNT(*) FROM {P}costs WHERE CAST(spent_date AS CHAR) LIKE '0000%'
UNION ALL SELECT 'users', COUNT(*) FROM {P}users WHERE CAST(hire_date AS CHAR) LIKE '0000%'
---
-- @7-3 미래/과거 이상 날짜(오늘 2026-07-29 기준, +2년 초과 또는 2020 이전)
SELECT 'contracts.contract_date' AS col, id, CAST(contract_date AS CHAR) AS v FROM {P}contracts WHERE contract_date IS NOT NULL AND (contract_date>DATE_ADD(CURDATE(), INTERVAL 2 YEAR) OR contract_date<'2020-01-01')
UNION ALL SELECT 'contracts.end_date', id, CAST(end_date AS CHAR) FROM {P}contracts WHERE end_date IS NOT NULL AND (end_date>DATE_ADD(CURDATE(), INTERVAL 3 YEAR) OR end_date<'2020-01-01')
UNION ALL SELECT 'projects.end_date', id, CAST(end_date AS CHAR) FROM {P}projects WHERE end_date IS NOT NULL AND (end_date>DATE_ADD(CURDATE(), INTERVAL 3 YEAR) OR end_date<'2020-01-01')
UNION ALL SELECT 'payments.paid_date(미래)', id, CAST(paid_date AS CHAR) FROM {P}payments WHERE paid_date>CURDATE()
UNION ALL SELECT 'costs.spent_date(미래)', id, CAST(spent_date AS CHAR) FROM {P}costs WHERE spent_date>CURDATE()
UNION ALL SELECT 'users.hire_date', id, CAST(hire_date AS CHAR) FROM {P}users WHERE hire_date IS NOT NULL AND (hire_date>CURDATE() OR hire_date<'1980-01-01')
UNION ALL SELECT 'quotes.valid_until(과거)', id, CAST(valid_until AS CHAR) FROM {P}quotes WHERE deleted_at IS NULL AND valid_until IS NOT NULL AND valid_until<CURDATE()
---
-- @7-4 프로젝트 날짜 vs 계약 날짜 불일치
SELECT p.id, p.project_no, CAST(p.contract_date AS CHAR) AS p_cdate, CAST(ct.contract_date AS CHAR) AS c_cdate, CAST(p.start_date AS CHAR) AS p_s, CAST(ct.start_date AS CHAR) AS c_s, CAST(p.end_date AS CHAR) AS p_e, CAST(ct.end_date AS CHAR) AS c_e FROM {P}projects p JOIN {P}contracts ct ON ct.id=p.contract_id ORDER BY p.id
---
-- @8-1 이력 누락: 상태가 초기값이 아닌데 status_history 없는 계약
SELECT ct.id, ct.contract_no, ct.status, (SELECT COUNT(*) FROM {P}contract_status_history h WHERE h.contract_id=ct.id) AS hist, ct.deleted_at FROM {P}contracts ct ORDER BY ct.id
---
-- @8-2 이력 누락: 프로젝트 status_history
SELECT p.id, p.project_no, p.status, p.settlement_status, (SELECT COUNT(*) FROM {P}project_status_history h WHERE h.project_id=p.id) AS hist, (SELECT COUNT(*) FROM {P}project_process_history ph WHERE ph.project_id=p.id) AS proc_hist, p.deleted_at FROM {P}projects p ORDER BY p.id
---
-- @8-3 이력 최신값 vs 현재 status 불일치(계약)
SELECT ct.id, ct.contract_no, ct.status AS cur, h.to_status AS last_hist, h.changed_at FROM {P}contracts ct LEFT JOIN {P}contract_status_history h ON h.id=(SELECT h2.id FROM {P}contract_status_history h2 WHERE h2.contract_id=ct.id ORDER BY h2.changed_at DESC, h2.id DESC LIMIT 1) WHERE h.to_status IS NULL OR h.to_status<>ct.status
---
-- @8-4 이력 최신값 vs 현재 status 불일치(프로젝트)
SELECT p.id, p.project_no, p.status AS cur, h.to_status AS last_hist, h.changed_at FROM {P}projects p LEFT JOIN {P}project_status_history h ON h.id=(SELECT h2.id FROM {P}project_status_history h2 WHERE h2.project_id=p.id ORDER BY h2.changed_at DESC, h2.id DESC LIMIT 1) WHERE h.to_status IS NULL OR h.to_status<>p.status
---
-- @8-5 프로젝트 process_stage_id vs process_history 최신값 불일치
SELECT p.id, p.project_no, p.process_stage_id AS cur, h.to_stage_id AS last_hist, h.changed_at FROM {P}projects p LEFT JOIN {P}project_process_history h ON h.id=(SELECT h2.id FROM {P}project_process_history h2 WHERE h2.project_id=p.id ORDER BY h2.changed_at DESC, h2.id DESC LIMIT 1) WHERE COALESCE(p.process_stage_id,0)<>COALESCE(h.to_stage_id,0)
---
-- @8-6 계약 terminated 인데 contract_terminations 행 없음 / 반대
SELECT ct.id, ct.contract_no, ct.status, (SELECT COUNT(*) FROM {P}contract_terminations t WHERE t.contract_id=ct.id) AS term_rows FROM {P}contracts ct WHERE (ct.status='terminated') <> ((SELECT COUNT(*) FROM {P}contract_terminations t WHERE t.contract_id=ct.id)>0)
---
-- @8-7 site_bonuses 전수(중복·삭제 확인)
SELECT id, user_id, project_id, year, half, base_amount, calc_basis, contrib_revenue, contrib_profit, bonus_rate, calc_amount, confirmed_bonus, pay_status, contribution_pct_at_calc, deleted_at FROM {P}site_bonuses ORDER BY id
---
-- @9-1 project_stage_progress: pct 범위 밖 / 프로젝트 공정타입과 다른 stage
SELECT sp.project_id, sp.stage_id, sp.pct, s.process_type, s.name, p.work_type, p.construction_type, p.deleted_at FROM {P}project_stage_progress sp JOIN {P}process_stages s ON s.id=sp.stage_id JOIN {P}projects p ON p.id=sp.project_id WHERE sp.pct<0 OR sp.pct>100
---
-- @9-2 프로젝트 progress vs stage_progress 평균 불일치
SELECT p.id, p.project_no, p.progress, ROUND(AVG(sp.pct)) AS avg_pct, COUNT(sp.stage_id) AS stages, p.deleted_at FROM {P}projects p LEFT JOIN {P}project_stage_progress sp ON sp.project_id=p.id GROUP BY p.id ORDER BY p.id
---
-- @9-3 project_assignments contribution_pct 합계가 100이 아닌 프로젝트(active 배정만)
SELECT a.project_id, p.project_no, p.contribution_mode, SUM(a.contribution_pct) AS sum_pct, COUNT(*) AS n, GROUP_CONCAT(CONCAT(a.user_id,':',a.contribution_pct,':',a.status)) AS detail, p.deleted_at FROM {P}project_assignments a JOIN {P}projects p ON p.id=a.project_id GROUP BY a.project_id ORDER BY a.project_id
---
-- @9-4 process_stages 전수
SELECT id, stage_key, process_type, stage_group, name, sort_order, requires_confirm, is_active FROM {P}process_stages ORDER BY process_type, sort_order
