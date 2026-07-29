-- @N-1 살아있는 행 기준 NULL 실태 — 계약
SELECT 'contracts' AS t, COUNT(*) AS alive,
 SUM(contract_date IS NULL) AS contract_date, SUM(start_date IS NULL) AS start_date, SUM(end_date IS NULL) AS end_date,
 SUM(quote_id IS NULL) AS quote_id, SUM(sales_user_id IS NULL) AS sales_user_id, SUM(work_name IS NULL OR work_name='') AS work_name,
 SUM(site_address IS NULL OR site_address='') AS site_address, SUM(work_type IS NULL OR work_type='') AS work_type,
 SUM(construction_type IS NULL OR construction_type='') AS construction_type, SUM(warranty_period IS NULL) AS warranty_period,
 SUM(contract_file_id IS NULL) AS contract_file_id
FROM edencrm_contracts WHERE deleted_at IS NULL
---
-- @N-2 살아있는 행 기준 NULL 실태 — 프로젝트
SELECT COUNT(*) AS alive, SUM(customer_id IS NULL) AS customer_id, SUM(contract_id IS NULL) AS contract_id,
 SUM(customer_name_snapshot IS NULL) AS cust_snapshot, SUM(site_address IS NULL OR site_address='') AS site_address,
 SUM(work_type IS NULL OR work_type='') AS work_type, SUM(construction_type IS NULL OR construction_type='') AS construction_type,
 SUM(expected_amount IS NULL) AS expected_amount, SUM(process_stage_id IS NULL) AS process_stage_id,
 SUM(process_entered_at IS NULL) AS process_entered_at, SUM(contract_date IS NULL) AS contract_date,
 SUM(start_date IS NULL) AS start_date, SUM(end_date IS NULL) AS end_date,
 SUM(actual_start_date IS NULL) AS actual_start_date, SUM(actual_end_date IS NULL) AS actual_end_date,
 SUM(sales_user_id IS NULL) AS sales_user_id, SUM(site_manager_id IS NULL) AS site_manager_id
FROM edencrm_projects WHERE deleted_at IS NULL
---
-- @N-3 살아있는 행 기준 NULL 실태 — 고객
SELECT COUNT(*) AS alive, SUM(phone IS NULL OR phone='') AS phone, SUM(email IS NULL OR email='') AS email,
 SUM(address IS NULL OR address='') AS address, SUM(site_address IS NULL OR site_address='') AS site_address,
 SUM(source IS NULL OR source='') AS source, SUM(interest_type IS NULL OR interest_type='') AS interest_type,
 SUM(sales_user_id IS NULL) AS sales_user_id, SUM(company_name IS NULL) AS company_name, SUM(contact_name IS NULL) AS contact_name,
 SUM(is_business=1 AND (biz_reg_no IS NULL OR biz_reg_no='')) AS biz_no_missing, SUM(privacy_agreed=0) AS privacy_not_agreed
FROM edencrm_customers WHERE deleted_at IS NULL
---
-- @N-4 프로젝트: actual_end_date NULL 인데 status=completed/settled (완료 귀속 집계 누락 후보)
SELECT id, project_no, status, settlement_status, CAST(actual_start_date AS CHAR) AS a_start, CAST(actual_end_date AS CHAR) AS a_end, deleted_at FROM edencrm_projects WHERE status IN ('completed','settled') AND actual_end_date IS NULL
---
-- @N-5 프로젝트: 진행중인데 actual_start_date NULL
SELECT id, project_no, status, progress, CAST(actual_start_date AS CHAR) AS a_start, deleted_at FROM edencrm_projects WHERE status='in_progress' AND actual_start_date IS NULL
---
-- @N-6 계약: 일반(비예외) 프로젝트인데 customer_name_snapshot / 예외인데 customer_id 둘 다 NULL
SELECT id, project_no, is_exception, customer_id, customer_name_snapshot, deleted_at FROM edencrm_projects WHERE customer_id IS NULL AND (customer_name_snapshot IS NULL OR customer_name_snapshot='')
---
-- @I-1 중복/중첩 인덱스 후보(같은 선두 컬럼 인덱스 2개 이상)
SELECT s.TABLE_NAME, s.COLUMN_NAME AS first_col, GROUP_CONCAT(DISTINCT s.INDEX_NAME) AS indexes, COUNT(DISTINCT s.INDEX_NAME) AS n
FROM information_schema.STATISTICS s
WHERE s.TABLE_SCHEMA='<DB_ACCOUNT>' AND s.TABLE_NAME LIKE 'edencrm\_%' AND s.SEQ_IN_INDEX=1
GROUP BY s.TABLE_NAME, s.COLUMN_NAME HAVING COUNT(DISTINCT s.INDEX_NAME)>1
---
-- @I-2 테이블 크기·인덱스 크기
SELECT TABLE_NAME, TABLE_ROWS, ROUND(DATA_LENGTH/1024) AS data_kb, ROUND(INDEX_LENGTH/1024) AS idx_kb
FROM information_schema.TABLES WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME LIKE 'edencrm\_%' AND INDEX_LENGTH>DATA_LENGTH ORDER BY INDEX_LENGTH DESC
---
-- @I-3 payments/costs 집계 핫패스 인덱스 존재 여부 확인
SELECT 'payments(paid_date)' AS need, IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_payments' AND COLUMN_NAME='paid_date'),'있음','없음') AS status
UNION ALL SELECT 'payments(status,paid_date) 복합', IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_payments' AND INDEX_NAME IN (SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_payments' AND COLUMN_NAME='paid_date' AND SEQ_IN_INDEX=2)),'있음','없음')
UNION ALL SELECT 'payments(contract_id,status) 복합', IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_payments' AND COLUMN_NAME='status' AND SEQ_IN_INDEX=2),'있음','없음')
UNION ALL SELECT 'payments(kind)', IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_payments' AND COLUMN_NAME='kind'),'있음','없음')
UNION ALL SELECT 'projects(status,deleted_at) 복합', IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_projects' AND COLUMN_NAME='deleted_at' AND SEQ_IN_INDEX=2),'있음','없음')
UNION ALL SELECT 'contracts(status,deleted_at) 복합', IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_contracts' AND COLUMN_NAME='deleted_at' AND SEQ_IN_INDEX=2),'있음','없음')
UNION ALL SELECT 'costs(spent_date,type,cost_status) 복합', IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_costs' AND COLUMN_NAME='spent_date' AND SEQ_IN_INDEX>1),'있음','없음')
UNION ALL SELECT 'projects(contract_date)', IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_projects' AND COLUMN_NAME='contract_date'),'있음','없음')
UNION ALL SELECT 'projects(actual_end_date)', IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_projects' AND COLUMN_NAME='actual_end_date'),'있음','없음')
UNION ALL SELECT 'contracts(contract_date)', IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='<DB_ACCOUNT>' AND TABLE_NAME='edencrm_contracts' AND COLUMN_NAME='contract_date'),'있음','없음')
---
-- @I-4 uq_projects_contract 가 소프트삭제 프로젝트로 점유된 계약(재생성 불가)
SELECT p.id AS proj_id, p.project_no, p.contract_id, p.deleted_at AS proj_deleted, c.contract_no, c.status AS contract_status, c.deleted_at AS contract_deleted
FROM edencrm_projects p JOIN edencrm_contracts c ON c.id=p.contract_id WHERE p.deleted_at IS NOT NULL
---
-- @I-5 audit_logs 최근 삭제/복원 액션(휴지통 운영 흔적)
SELECT id, user_id, action, entity, entity_id, CAST(created_at AS CHAR) AS at FROM edencrm_audit_logs WHERE action LIKE '%delete%' OR action LIKE '%restore%' OR action LIKE '%purge%' ORDER BY id DESC LIMIT 40
