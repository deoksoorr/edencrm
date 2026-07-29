-- @E-1 확정매출 집계 EXPLAIN (기간 필터 포함)
EXPLAIN SELECT COALESCE(SUM(pm.amount),0) FROM edencrm_payments pm
 LEFT JOIN edencrm_contracts c ON c.id=pm.contract_id LEFT JOIN edencrm_projects pj ON pj.id=pm.project_id
 WHERE pm.status='paid' AND ((pm.contract_id IS NOT NULL AND c.deleted_at IS NULL) OR (pm.project_id IS NOT NULL AND pj.deleted_at IS NULL))
   AND pm.paid_date >= '2026-01-01' AND pm.paid_date <= '2026-12-31'
---
-- @E-2 계약 목록 EXPLAIN (미수금 상관 서브쿼리 포함)
EXPLAIN SELECT c.*, cu.name,
  COALESCE((SELECT SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END) FROM edencrm_payments pm WHERE pm.contract_id=c.id AND pm.status='paid'),0) AS paid,
  (SELECT MAX(pm.paid_date) FROM edencrm_payments pm WHERE pm.contract_id=c.id AND pm.status='paid' AND pm.kind='payment') AS last_paid
 FROM edencrm_contracts c JOIN edencrm_customers cu ON cu.id=c.customer_id
 WHERE c.deleted_at IS NULL ORDER BY c.contract_date DESC LIMIT 20
---
-- @E-3 프로젝트 목록 EXPLAIN
EXPLAIN SELECT p.*, cu.name AS customer_name, u.name AS sales_name FROM edencrm_projects p
 LEFT JOIN edencrm_customers cu ON cu.id=p.customer_id LEFT JOIN edencrm_users u ON u.id=p.sales_user_id
 WHERE p.deleted_at IS NULL AND p.status='in_progress' ORDER BY p.id DESC LIMIT 20
---
-- @E-4 원가 집계 EXPLAIN (기간)
EXPLAIN SELECT COALESCE(SUM(cs.amount),0) FROM edencrm_costs cs JOIN edencrm_projects pj ON pj.id=cs.project_id AND pj.deleted_at IS NULL
 WHERE cs.type='actual' AND cs.cost_status='confirmed' AND cs.spent_date>='2026-01-01' AND cs.spent_date<='2026-12-31'
---
-- @E-5 직원 귀속 매출 EXPLAIN (기여도 조인)
EXPLAIN SELECT pa.user_id, SUM(pm.amount*pa.contribution_pct/100) FROM edencrm_payments pm
 LEFT JOIN edencrm_contracts c ON c.id=pm.contract_id AND c.deleted_at IS NULL
 JOIN edencrm_projects pj2 ON pj2.deleted_at IS NULL AND ((pm.contract_id IS NOT NULL AND pj2.contract_id=pm.contract_id) OR (pm.project_id IS NOT NULL AND pj2.id=pm.project_id))
 JOIN edencrm_project_assignments pa ON pa.project_id=pj2.id AND pa.contribution_pct>0
 WHERE pm.status='paid' GROUP BY pa.user_id
---
-- @E-6 고객 목록 EXPLAIN (검색)
EXPLAIN SELECT c.* FROM edencrm_customers c WHERE c.deleted_at IS NULL AND (c.name LIKE '%김%' OR c.phone LIKE '%010%') ORDER BY c.id DESC LIMIT 20
---
-- @E-7 공정보드 EXPLAIN
EXPLAIN SELECT p.id, p.name, s.stage_key, sp.pct FROM edencrm_projects p
 LEFT JOIN edencrm_process_stages s ON s.id=p.process_stage_id
 LEFT JOIN edencrm_project_stage_progress sp ON sp.project_id=p.id
 WHERE p.deleted_at IS NULL AND p.status IN ('preparing','in_progress','paused','warranty')
---
-- @E-8 감사로그 목록 EXPLAIN
EXPLAIN SELECT a.*, u.name FROM edencrm_audit_logs a LEFT JOIN edencrm_users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 30
