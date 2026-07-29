-- @2-0 소프트삭제 현황(테이블별)
SELECT 'customers' AS t, COUNT(*) AS total, SUM(deleted_at IS NOT NULL) AS deleted FROM {P}customers
UNION ALL SELECT 'leads', COUNT(*), SUM(deleted_at IS NOT NULL) FROM {P}leads
UNION ALL SELECT 'quotes', COUNT(*), SUM(deleted_at IS NOT NULL) FROM {P}quotes
UNION ALL SELECT 'contracts', COUNT(*), SUM(deleted_at IS NOT NULL) FROM {P}contracts
UNION ALL SELECT 'projects', COUNT(*), SUM(deleted_at IS NOT NULL) FROM {P}projects
UNION ALL SELECT 'site_bonuses', COUNT(*), SUM(deleted_at IS NOT NULL) FROM {P}site_bonuses
UNION ALL SELECT 'goals', COUNT(*), SUM(deleted_at IS NOT NULL) FROM {P}goals
UNION ALL SELECT 'users', COUNT(*), SUM(deleted_at IS NOT NULL) FROM {P}users
---
-- @2-1 삭제된 고객을 참조하는 살아있는 리드
SELECT l.id, l.customer_id, c.name AS cust_name, c.deleted_at FROM {P}leads l JOIN {P}customers c ON c.id=l.customer_id WHERE l.deleted_at IS NULL AND c.deleted_at IS NOT NULL
---
-- @2-2 삭제된 고객을 참조하는 살아있는 견적
SELECT q.id, q.quote_no, q.customer_id, c.name AS cust_name, c.deleted_at FROM {P}quotes q JOIN {P}customers c ON c.id=q.customer_id WHERE q.deleted_at IS NULL AND c.deleted_at IS NOT NULL
---
-- @2-3 삭제된 리드를 참조하는 살아있는 견적
SELECT q.id, q.quote_no, q.lead_id, l.deleted_at FROM {P}quotes q JOIN {P}leads l ON l.id=q.lead_id WHERE q.deleted_at IS NULL AND l.deleted_at IS NOT NULL
---
-- @2-4 삭제된 고객을 참조하는 살아있는 계약
SELECT ct.id, ct.contract_no, ct.customer_id, c.name, c.deleted_at FROM {P}contracts ct JOIN {P}customers c ON c.id=ct.customer_id WHERE ct.deleted_at IS NULL AND c.deleted_at IS NOT NULL
---
-- @2-5 삭제된 견적을 참조하는 살아있는 계약
SELECT ct.id, ct.contract_no, ct.quote_id, q.quote_no, q.deleted_at FROM {P}contracts ct JOIN {P}quotes q ON q.id=ct.quote_id WHERE ct.deleted_at IS NULL AND q.deleted_at IS NOT NULL
---
-- @2-6 삭제된 계약을 참조하는 살아있는 프로젝트
SELECT p.id, p.project_no, p.contract_id, ct.contract_no, ct.deleted_at FROM {P}projects p JOIN {P}contracts ct ON ct.id=p.contract_id WHERE p.deleted_at IS NULL AND ct.deleted_at IS NOT NULL
---
-- @2-7 삭제된 고객을 참조하는 살아있는 프로젝트
SELECT p.id, p.project_no, p.customer_id, c.name, c.deleted_at FROM {P}projects p JOIN {P}customers c ON c.id=p.customer_id WHERE p.deleted_at IS NULL AND c.deleted_at IS NOT NULL
---
-- @2-8 삭제된 프로젝트를 참조하는 살아있는 입금/원가/일정/보너스/배정
SELECT 'payments' AS t, pm.id, pm.project_id AS ref FROM {P}payments pm JOIN {P}projects p ON p.id=pm.project_id WHERE p.deleted_at IS NOT NULL
UNION ALL SELECT 'costs', c.id, c.project_id FROM {P}costs c JOIN {P}projects p ON p.id=c.project_id WHERE p.deleted_at IS NOT NULL
UNION ALL SELECT 'schedules', s.id, s.project_id FROM {P}schedules s JOIN {P}projects p ON p.id=s.project_id WHERE p.deleted_at IS NOT NULL
UNION ALL SELECT 'site_bonuses', b.id, b.project_id FROM {P}site_bonuses b JOIN {P}projects p ON p.id=b.project_id WHERE p.deleted_at IS NOT NULL AND b.deleted_at IS NULL
UNION ALL SELECT 'project_assignments', a.id, a.project_id FROM {P}project_assignments a JOIN {P}projects p ON p.id=a.project_id WHERE p.deleted_at IS NOT NULL
UNION ALL SELECT 'work_logs', w.id, w.project_id FROM {P}work_logs w JOIN {P}projects p ON p.id=w.project_id WHERE p.deleted_at IS NOT NULL
UNION ALL SELECT 'project_memos', m.id, m.project_id FROM {P}project_memos m JOIN {P}projects p ON p.id=m.project_id WHERE p.deleted_at IS NOT NULL
---
-- @2-9 삭제된 계약을 참조하는 살아있는 입금
SELECT pm.id, pm.contract_id, pm.amount, pm.status, ct.contract_no, ct.deleted_at FROM {P}payments pm JOIN {P}contracts ct ON ct.id=pm.contract_id WHERE ct.deleted_at IS NOT NULL
---
-- @2-10 역방향: 살아있는 자식이 있는데 부모가 삭제되지 않은 정상 케이스 확인용 - 삭제 부모의 자식 전수
SELECT 'quotes of deleted customer' AS t, COUNT(*) AS c FROM {P}quotes q JOIN {P}customers cu ON cu.id=q.customer_id WHERE cu.deleted_at IS NOT NULL
UNION ALL SELECT 'contracts of deleted quote', COUNT(*) FROM {P}contracts ct JOIN {P}quotes q ON q.id=ct.quote_id WHERE q.deleted_at IS NOT NULL
UNION ALL SELECT 'projects of deleted contract', COUNT(*) FROM {P}projects p JOIN {P}contracts ct ON ct.id=p.contract_id WHERE ct.deleted_at IS NOT NULL
---
-- @2-11 삭제된 사용자를 담당자로 가진 살아있는 데이터
SELECT 'customers.sales_user_id' AS col, c.id AS row_id, c.sales_user_id AS uid, u.name, u.status, u.deleted_at FROM {P}customers c JOIN {P}users u ON u.id=c.sales_user_id WHERE c.deleted_at IS NULL AND (u.deleted_at IS NOT NULL OR u.status<>'active')
UNION ALL SELECT 'leads.sales_user_id', l.id, l.sales_user_id, u.name, u.status, u.deleted_at FROM {P}leads l JOIN {P}users u ON u.id=l.sales_user_id WHERE l.deleted_at IS NULL AND (u.deleted_at IS NOT NULL OR u.status<>'active')
UNION ALL SELECT 'contracts.sales_user_id', ct.id, ct.sales_user_id, u.name, u.status, u.deleted_at FROM {P}contracts ct JOIN {P}users u ON u.id=ct.sales_user_id WHERE ct.deleted_at IS NULL AND (u.deleted_at IS NOT NULL OR u.status<>'active')
UNION ALL SELECT 'projects.sales_user_id', p.id, p.sales_user_id, u.name, u.status, u.deleted_at FROM {P}projects p JOIN {P}users u ON u.id=p.sales_user_id WHERE p.deleted_at IS NULL AND (u.deleted_at IS NOT NULL OR u.status<>'active')
UNION ALL SELECT 'projects.site_manager_id', p.id, p.site_manager_id, u.name, u.status, u.deleted_at FROM {P}projects p JOIN {P}users u ON u.id=p.site_manager_id WHERE p.deleted_at IS NULL AND (u.deleted_at IS NOT NULL OR u.status<>'active')
UNION ALL SELECT 'project_assignments.user_id', a.id, a.user_id, u.name, u.status, u.deleted_at FROM {P}project_assignments a JOIN {P}users u ON u.id=a.user_id WHERE (u.deleted_at IS NOT NULL OR u.status<>'active')
UNION ALL SELECT 'site_bonuses.user_id', b.id, b.user_id, u.name, u.status, u.deleted_at FROM {P}site_bonuses b JOIN {P}users u ON u.id=b.user_id WHERE b.deleted_at IS NULL AND (u.deleted_at IS NOT NULL OR u.status<>'active')
UNION ALL SELECT 'schedules.user_id', s.id, s.user_id, u.name, u.status, u.deleted_at FROM {P}schedules s JOIN {P}users u ON u.id=s.user_id WHERE (u.deleted_at IS NOT NULL OR u.status<>'active')
UNION ALL SELECT 'costs.worker_id', c.id, c.worker_id, u.name, u.status, u.deleted_at FROM {P}costs c JOIN {P}users u ON u.id=c.worker_id WHERE (u.deleted_at IS NOT NULL OR u.status<>'active')
UNION ALL SELECT 'payments.created_by', pm.id, pm.created_by, u.name, u.status, u.deleted_at FROM {P}payments pm JOIN {P}users u ON u.id=pm.created_by WHERE (u.deleted_at IS NOT NULL OR u.status<>'active')
UNION ALL SELECT 'employee_permissions.user_id', e.id, e.user_id, u.name, u.status, u.deleted_at FROM {P}employee_permissions e JOIN {P}users u ON u.id=e.user_id WHERE (u.deleted_at IS NOT NULL OR u.status<>'active')
---
-- @2-12 사용자 전수(담당자 유효성 판단 기준)
SELECT id, login_id, name, role_key, status, deleted_at FROM {P}users ORDER BY id
---
-- @2-13 담당자가 NULL 인 살아있는 데이터(권한 필터에서 누락 위험)
SELECT 'customers.sales_user_id NULL' AS t, COUNT(*) AS c FROM {P}customers WHERE deleted_at IS NULL AND sales_user_id IS NULL
UNION ALL SELECT 'leads.sales_user_id NULL', COUNT(*) FROM {P}leads WHERE deleted_at IS NULL AND sales_user_id IS NULL
UNION ALL SELECT 'contracts.sales_user_id NULL', COUNT(*) FROM {P}contracts WHERE deleted_at IS NULL AND sales_user_id IS NULL
UNION ALL SELECT 'projects.sales_user_id NULL', COUNT(*) FROM {P}projects WHERE deleted_at IS NULL AND sales_user_id IS NULL
UNION ALL SELECT 'projects.site_manager_id NULL', COUNT(*) FROM {P}projects WHERE deleted_at IS NULL AND site_manager_id IS NULL
UNION ALL SELECT 'projects.customer_id NULL', COUNT(*) FROM {P}projects WHERE deleted_at IS NULL AND customer_id IS NULL
UNION ALL SELECT 'projects.contract_id NULL', COUNT(*) FROM {P}projects WHERE deleted_at IS NULL AND contract_id IS NULL
UNION ALL SELECT 'costs.created_by NULL', COUNT(*) FROM {P}costs WHERE created_by IS NULL
UNION ALL SELECT 'payments.created_by NULL', COUNT(*) FROM {P}payments WHERE created_by IS NULL
