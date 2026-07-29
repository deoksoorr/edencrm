-- @1-1 orphan: quotes -> customers
SELECT q.id, q.quote_no, q.customer_id FROM {P}quotes q LEFT JOIN {P}customers c ON c.id=q.customer_id WHERE q.customer_id IS NOT NULL AND c.id IS NULL
---
-- @1-2 orphan: quotes -> leads
SELECT q.id, q.quote_no, q.lead_id FROM {P}quotes q LEFT JOIN {P}leads l ON l.id=q.lead_id WHERE q.lead_id IS NOT NULL AND l.id IS NULL
---
-- @1-3 orphan(FK없음): quotes.current_version_id -> quote_versions
SELECT q.id, q.quote_no, q.current_version_id FROM {P}quotes q LEFT JOIN {P}quote_versions v ON v.id=q.current_version_id WHERE q.current_version_id IS NOT NULL AND v.id IS NULL
---
-- @1-3b quotes.current_version_id 가 NULL 인 살아있는 견적(금액 표시 불가 후보)
SELECT q.id, q.quote_no, q.status, q.current_version_id, (SELECT COUNT(*) FROM {P}quote_versions v WHERE v.quote_id=q.id) AS ver_cnt FROM {P}quotes q WHERE q.deleted_at IS NULL AND q.current_version_id IS NULL
---
-- @1-3c quotes.current_version_id 가 다른 견적의 버전을 가리키는 경우(교차참조)
SELECT q.id, q.quote_no, q.current_version_id, v.quote_id AS version_belongs_to FROM {P}quotes q JOIN {P}quote_versions v ON v.id=q.current_version_id WHERE v.quote_id <> q.id
---
-- @1-4 orphan: quote_versions -> quotes
SELECT v.id, v.quote_id FROM {P}quote_versions v LEFT JOIN {P}quotes q ON q.id=v.quote_id WHERE q.id IS NULL
---
-- @1-5 orphan: quote_items -> quote_versions
SELECT i.id, i.quote_version_id FROM {P}quote_items i LEFT JOIN {P}quote_versions v ON v.id=i.quote_version_id WHERE v.id IS NULL
---
-- @1-6 orphan: contracts -> customers
SELECT ct.id, ct.contract_no, ct.customer_id FROM {P}contracts ct LEFT JOIN {P}customers c ON c.id=ct.customer_id WHERE c.id IS NULL
---
-- @1-7 orphan: contracts -> quotes
SELECT ct.id, ct.contract_no, ct.quote_id FROM {P}contracts ct LEFT JOIN {P}quotes q ON q.id=ct.quote_id WHERE ct.quote_id IS NOT NULL AND q.id IS NULL
---
-- @1-8 orphan(FK없음): contracts.quote_version_id -> quote_versions
SELECT ct.id, ct.contract_no, ct.quote_version_id FROM {P}contracts ct LEFT JOIN {P}quote_versions v ON v.id=ct.quote_version_id WHERE ct.quote_version_id IS NOT NULL AND v.id IS NULL
---
-- @1-9 orphan(FK없음): contracts.converted_by -> users
SELECT ct.id, ct.contract_no, ct.converted_by FROM {P}contracts ct LEFT JOIN {P}users u ON u.id=ct.converted_by WHERE ct.converted_by IS NOT NULL AND u.id IS NULL
---
-- @1-10 orphan(FK없음): contracts.contract_file_id -> project_files
SELECT ct.id, ct.contract_no, ct.contract_file_id FROM {P}contracts ct LEFT JOIN {P}project_files f ON f.id=ct.contract_file_id WHERE ct.contract_file_id IS NOT NULL AND f.id IS NULL
---
-- @1-11 orphan: projects -> contracts
SELECT p.id, p.project_no, p.contract_id FROM {P}projects p LEFT JOIN {P}contracts ct ON ct.id=p.contract_id WHERE p.contract_id IS NOT NULL AND ct.id IS NULL
---
-- @1-12 orphan: projects -> customers
SELECT p.id, p.project_no, p.customer_id FROM {P}projects p LEFT JOIN {P}customers c ON c.id=p.customer_id WHERE p.customer_id IS NOT NULL AND c.id IS NULL
---
-- @1-13 orphan: payments -> contracts / projects
SELECT pm.id, pm.contract_id, pm.project_id, pm.amount FROM {P}payments pm LEFT JOIN {P}contracts ct ON ct.id=pm.contract_id LEFT JOIN {P}projects p ON p.id=pm.project_id WHERE (pm.contract_id IS NOT NULL AND ct.id IS NULL) OR (pm.project_id IS NOT NULL AND p.id IS NULL)
---
-- @1-13b payments: contract_id/project_id 둘 다 NULL(고아 입금)
SELECT pm.id, pm.pay_type, pm.amount, pm.status, pm.paid_date FROM {P}payments pm WHERE pm.contract_id IS NULL AND pm.project_id IS NULL
---
-- @1-14 orphan: costs -> projects
SELECT c.id, c.project_id FROM {P}costs c LEFT JOIN {P}projects p ON p.id=c.project_id WHERE p.id IS NULL
---
-- @1-15 orphan: project_assignments -> projects/users
SELECT a.id, a.project_id, a.user_id FROM {P}project_assignments a LEFT JOIN {P}projects p ON p.id=a.project_id LEFT JOIN {P}users u ON u.id=a.user_id WHERE p.id IS NULL OR u.id IS NULL
---
-- @1-16 orphan: schedules -> projects/users
SELECT s.id, s.project_id, s.user_id FROM {P}schedules s LEFT JOIN {P}projects p ON p.id=s.project_id LEFT JOIN {P}users u ON u.id=s.user_id WHERE (s.project_id IS NOT NULL AND p.id IS NULL) OR u.id IS NULL
---
-- @1-17 orphan: employee_permissions -> users
SELECT e.id, e.user_id, e.resource_key FROM {P}employee_permissions e LEFT JOIN {P}users u ON u.id=e.user_id WHERE u.id IS NULL
---
-- @1-18 orphan: site_bonuses -> projects/users
SELECT b.id, b.project_id, b.user_id FROM {P}site_bonuses b LEFT JOIN {P}projects p ON p.id=b.project_id LEFT JOIN {P}users u ON u.id=b.user_id WHERE (b.project_id IS NOT NULL AND p.id IS NULL) OR u.id IS NULL
---
-- @1-19 orphan: *_history -> 부모
SELECT 'contract_status_history' AS t, h.id, h.contract_id AS parent_id FROM {P}contract_status_history h LEFT JOIN {P}contracts c ON c.id=h.contract_id WHERE c.id IS NULL
UNION ALL SELECT 'project_status_history', h.id, h.project_id FROM {P}project_status_history h LEFT JOIN {P}projects p ON p.id=h.project_id WHERE p.id IS NULL
UNION ALL SELECT 'project_process_history', h.id, h.project_id FROM {P}project_process_history h LEFT JOIN {P}projects p ON p.id=h.project_id WHERE p.id IS NULL
UNION ALL SELECT 'site_bonus_history', h.id, h.bonus_id FROM {P}site_bonus_history h LEFT JOIN {P}site_bonuses b ON b.id=h.bonus_id WHERE b.id IS NULL
UNION ALL SELECT 'goal_history', h.id, h.goal_id FROM {P}goal_history h LEFT JOIN {P}goals g ON g.id=h.goal_id WHERE g.id IS NULL
UNION ALL SELECT 'project_stage_progress', 0, sp.project_id FROM {P}project_stage_progress sp LEFT JOIN {P}projects p ON p.id=sp.project_id WHERE p.id IS NULL
---
-- @1-20 orphan(폴리모픽, FK없음): project_files.entity_type/entity_id 및 project_id
SELECT f.id, f.project_id, f.entity_type, f.entity_id, f.original_name FROM {P}project_files f
---
-- @1-21 orphan: work_logs/work_log_photos/warranty_repairs/customer_contacts/customer_activities
SELECT 'work_logs' AS t, COUNT(*) AS orphan FROM {P}work_logs w LEFT JOIN {P}projects p ON p.id=w.project_id WHERE p.id IS NULL
UNION ALL SELECT 'work_log_photos', COUNT(*) FROM {P}work_log_photos wp LEFT JOIN {P}work_logs w ON w.id=wp.work_log_id WHERE w.id IS NULL
UNION ALL SELECT 'warranty_repairs', COUNT(*) FROM {P}warranty_repairs wr LEFT JOIN {P}projects p ON p.id=wr.project_id WHERE p.id IS NULL
UNION ALL SELECT 'customer_contacts', COUNT(*) FROM {P}customer_contacts cc LEFT JOIN {P}customers c ON c.id=cc.customer_id WHERE c.id IS NULL
UNION ALL SELECT 'customer_activities', COUNT(*) FROM {P}customer_activities ca LEFT JOIN {P}customers c ON c.id=ca.customer_id WHERE c.id IS NULL
UNION ALL SELECT 'schedule_participants', COUNT(*) FROM {P}schedule_participants sp LEFT JOIN {P}schedules s ON s.id=sp.schedule_id WHERE s.id IS NULL
UNION ALL SELECT 'schedule_time_slots', COUNT(*) FROM {P}schedule_time_slots ts LEFT JOIN {P}schedules s ON s.id=ts.schedule_id WHERE s.id IS NULL
UNION ALL SELECT 'contract_terminations', COUNT(*) FROM {P}contract_terminations ctr LEFT JOIN {P}contracts c ON c.id=ctr.contract_id WHERE c.id IS NULL
UNION ALL SELECT 'project_memos', COUNT(*) FROM {P}project_memos pm LEFT JOIN {P}projects p ON p.id=pm.project_id WHERE p.id IS NULL
UNION ALL SELECT 'notifications', COUNT(*) FROM {P}notifications n LEFT JOIN {P}users u ON u.id=n.user_id WHERE u.id IS NULL
UNION ALL SELECT 'audit_logs', COUNT(*) FROM {P}audit_logs a LEFT JOIN {P}users u ON u.id=a.user_id WHERE a.user_id IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'attendance_marks', COUNT(*) FROM {P}attendance_marks am LEFT JOIN {P}users u ON u.id=am.user_id WHERE u.id IS NULL
UNION ALL SELECT 'leads->customers', COUNT(*) FROM {P}leads l LEFT JOIN {P}customers c ON c.id=l.customer_id WHERE c.id IS NULL
UNION ALL SELECT 'leads->pipeline_stages', COUNT(*) FROM {P}leads l LEFT JOIN {P}pipeline_stages s ON s.id=l.stage_id WHERE s.id IS NULL
UNION ALL SELECT 'projects->process_stages', COUNT(*) FROM {P}projects p LEFT JOIN {P}process_stages s ON s.id=p.process_stage_id WHERE p.process_stage_id IS NOT NULL AND s.id IS NULL
UNION ALL SELECT 'project_stage_progress->stages', COUNT(*) FROM {P}project_stage_progress sp LEFT JOIN {P}process_stages s ON s.id=sp.stage_id WHERE s.id IS NULL
