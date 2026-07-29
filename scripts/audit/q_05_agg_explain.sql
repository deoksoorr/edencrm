-- @A-1 확정매출(코드 산식 그대로) — 삭제 필터 적용 vs 미적용 비교
SELECT
  ROUND(SUM(CASE WHEN ((pm.contract_id IS NOT NULL AND c.deleted_at IS NULL) OR (pm.project_id IS NOT NULL AND pj.deleted_at IS NULL))
    THEN (CASE WHEN pm.contract_id IS NOT NULL AND c.contract_amount>0 AND c.supply_amount IS NOT NULL
               THEN (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)*c.supply_amount/c.contract_amount
               ELSE (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)*0.9090909090909091 END)
    ELSE 0 END)) AS revenue_with_delete_filter,
  ROUND(SUM(CASE WHEN pm.contract_id IS NOT NULL AND c.contract_amount>0 AND c.supply_amount IS NOT NULL
               THEN (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)*c.supply_amount/c.contract_amount
               ELSE (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END)*0.9090909090909091 END)) AS revenue_no_filter
FROM edencrm_payments pm LEFT JOIN edencrm_contracts c ON c.id=pm.contract_id LEFT JOIN edencrm_projects pj ON pj.id=pm.project_id
WHERE pm.status='paid'
---
-- @A-2 삭제 데이터 때문에 집계에서 빠지는 입금(현금 총액 관점)
SELECT pm.id, pm.contract_id, pm.project_id, pm.kind, pm.amount, pm.paid_date, c.deleted_at AS ctr_del, pj.deleted_at AS proj_del
FROM edencrm_payments pm LEFT JOIN edencrm_contracts c ON c.id=pm.contract_id LEFT JOIN edencrm_projects pj ON pj.id=pm.project_id
WHERE pm.status='paid' AND NOT ((pm.contract_id IS NOT NULL AND c.deleted_at IS NULL) OR (pm.project_id IS NOT NULL AND pj.deleted_at IS NULL))
---
-- @A-3 원가 총액(costs) — 삭제 프로젝트 필터 적용/미적용
SELECT COALESCE(SUM(CASE WHEN pj.deleted_at IS NULL THEN cs.amount ELSE 0 END),0) AS cost_filtered,
       COALESCE(SUM(cs.amount),0) AS cost_all
FROM edencrm_costs cs JOIN edencrm_projects pj ON pj.id=cs.project_id
---
-- @A-4 costs 전수
SELECT c.id, c.project_id, c.type, c.cost_status, c.category, c.item_name, c.amount, c.spent_date, c.worker_id, c.created_by, p.deleted_at AS proj_del FROM edencrm_costs c JOIN edencrm_projects p ON p.id=c.project_id ORDER BY c.id
---
-- @A-5 미수금(RECEIVABLE_CONTRACT_COND) 실측
SELECT c.id, c.contract_no, c.status, c.contract_amount,
  COALESCE((SELECT SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END) FROM edencrm_payments pm WHERE pm.contract_id=c.id AND pm.status='paid'),0) AS net_paid,
  GREATEST(0, c.contract_amount - COALESCE((SELECT SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END) FROM edencrm_payments pm WHERE pm.contract_id=c.id AND pm.status='paid'),0)) AS receivable
FROM edencrm_contracts c WHERE c.deleted_at IS NULL AND c.status IN ('active','on_hold','completed')
---
-- @A-6 수주액(contractedAmount) 산식 실측 — projects.status NOT IN ('cancelled','terminated')
SELECT COALESCE(SUM(supply_amount),0) AS contracted, COUNT(*) AS n FROM edencrm_projects WHERE deleted_at IS NULL AND status NOT IN ('cancelled','terminated') AND contract_date IS NOT NULL
---
-- @A-7 예상매출(expectedRevenue) 실측
SELECT COALESCE(SUM(supply_amount),0) AS expected, COUNT(*) AS n FROM edencrm_projects WHERE deleted_at IS NULL AND status IN ('preparing','in_progress')
---
-- @B-1 인덱스 전수(테이블별)
SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols, MAX(NON_UNIQUE) AS non_unique
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME LIKE 'edencrm\_%'
GROUP BY TABLE_NAME, INDEX_NAME ORDER BY TABLE_NAME, INDEX_NAME
---
-- @B-2 FK 컬럼인데 인덱스 선두가 아닌 경우(누락 FK 인덱스 후보) — MySQL은 FK 생성 시 자동 인덱스를 만들므로 보통 0
SELECT k.TABLE_NAME, k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k
WHERE k.TABLE_SCHEMA='<DB_ACCOUNT>' AND k.TABLE_NAME LIKE 'edencrm\_%' AND k.REFERENCED_TABLE_NAME IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=k.TABLE_SCHEMA AND s.TABLE_NAME=k.TABLE_NAME AND s.COLUMN_NAME=k.COLUMN_NAME AND s.SEQ_IN_INDEX=1)
---
-- @B-3 소프트삭제 컬럼에 인덱스가 있는가(목록 쿼리 필수 필터)
SELECT t.TABLE_NAME, IF(s.INDEX_NAME IS NULL,'없음',s.INDEX_NAME) AS deleted_at_index
FROM information_schema.COLUMNS t
LEFT JOIN information_schema.STATISTICS s ON s.TABLE_SCHEMA=t.TABLE_SCHEMA AND s.TABLE_NAME=t.TABLE_NAME AND s.COLUMN_NAME='deleted_at' AND s.SEQ_IN_INDEX=1
WHERE t.TABLE_SCHEMA='<DB_ACCOUNT>' AND t.TABLE_NAME LIKE 'edencrm\_%' AND t.COLUMN_NAME='deleted_at'
---
-- @B-4 payments 인덱스 상세(집계 핫패스)
SHOW INDEX FROM edencrm_payments
