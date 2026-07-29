-- @3-1 중복 고객(이름+전화) — 삭제 제외
SELECT name, phone, COUNT(*) AS c, GROUP_CONCAT(id ORDER BY id) AS ids FROM {P}customers WHERE deleted_at IS NULL GROUP BY name, phone HAVING COUNT(*)>1
---
-- @3-1b 중복 고객(이름만) — 삭제 포함 전수
SELECT name, COUNT(*) AS c, GROUP_CONCAT(CONCAT(id,IF(deleted_at IS NULL,'','(삭제)')) ORDER BY id) AS ids FROM {P}customers GROUP BY name HAVING COUNT(*)>1
---
-- @3-1c 중복 사업자등록번호
SELECT biz_reg_no, COUNT(*) AS c, GROUP_CONCAT(id) AS ids FROM {P}customers WHERE biz_reg_no IS NOT NULL AND biz_reg_no<>'' GROUP BY biz_reg_no HAVING COUNT(*)>1
---
-- @3-2 중복 계약번호(삭제 포함/제외)
SELECT contract_no, COUNT(*) AS c, SUM(deleted_at IS NULL) AS alive, GROUP_CONCAT(id) AS ids FROM {P}contracts GROUP BY contract_no HAVING COUNT(*)>1
---
-- @3-3 중복 프로젝트번호
SELECT project_no, COUNT(*) AS c, SUM(deleted_at IS NULL) AS alive, GROUP_CONCAT(id) AS ids FROM {P}projects GROUP BY project_no HAVING COUNT(*)>1
---
-- @3-4 중복 견적번호
SELECT quote_no, COUNT(*) AS c, SUM(deleted_at IS NULL) AS alive, GROUP_CONCAT(id) AS ids FROM {P}quotes GROUP BY quote_no HAVING COUNT(*)>1
---
-- @3-5 중복 권한 레코드(user_id+resource_key)
SELECT user_id, section, resource_key, COUNT(*) AS c, GROUP_CONCAT(id) AS ids FROM {P}employee_permissions GROUP BY user_id, section, resource_key HAVING COUNT(*)>1
---
-- @3-5b employee_permissions 전수 요약(사용자별 행수·중복 resource_key 무시)
SELECT e.user_id, u.login_id, u.role_key, COUNT(*) AS rows_cnt, COUNT(DISTINCT e.resource_key) AS distinct_res FROM {P}employee_permissions e JOIN {P}users u ON u.id=e.user_id GROUP BY e.user_id, u.login_id, u.role_key ORDER BY e.user_id
---
-- @3-6 중복 quote_versions(quote_id+version_no)
SELECT quote_id, version_no, COUNT(*) AS c FROM {P}quote_versions GROUP BY quote_id, version_no HAVING COUNT(*)>1
---
-- @3-7 중복 프로젝트 배정(project_id+user_id, 상태 무시)
SELECT project_id, user_id, COUNT(*) AS c, GROUP_CONCAT(CONCAT(id,':',status,':',role)) AS detail FROM {P}project_assignments GROUP BY project_id, user_id HAVING COUNT(*)>1
---
-- @3-8 중복 site_bonuses(user+project+year+half)
SELECT user_id, project_id, year, half, COUNT(*) AS c, GROUP_CONCAT(id) AS ids FROM {P}site_bonuses GROUP BY user_id, project_id, year, half HAVING COUNT(*)>1
---
-- @3-9 한 계약에 프로젝트가 2개 이상(1:1 기대)
SELECT contract_id, COUNT(*) AS c, GROUP_CONCAT(CONCAT(id,IF(deleted_at IS NULL,'','(삭제)'))) AS project_ids FROM {P}projects WHERE contract_id IS NOT NULL GROUP BY contract_id HAVING COUNT(*)>1
---
-- @3-10 한 견적에 계약이 2개 이상
SELECT quote_id, COUNT(*) AS c, GROUP_CONCAT(id) AS ids FROM {P}contracts WHERE quote_id IS NOT NULL GROUP BY quote_id HAVING COUNT(*)>1
---
-- @4-1 계약 전수(금액·상태·VAT 검증용)
SELECT id, contract_no, customer_id, quote_id, contract_amount, supply_amount, vat_amount, down_payment, middle_payment, balance_payment, status, payment_status, contract_date, start_date, end_date, sales_user_id, deleted_at FROM {P}contracts ORDER BY id
---
-- @4-2 계약: VAT/공급가 정합 (contract_amount != supply+vat)
SELECT id, contract_no, contract_amount, supply_amount, vat_amount, (COALESCE(supply_amount,0)+COALESCE(vat_amount,0)) AS sum_sv, contract_amount-(COALESCE(supply_amount,0)+COALESCE(vat_amount,0)) AS diff, deleted_at FROM {P}contracts WHERE supply_amount IS NOT NULL AND ABS(contract_amount-(COALESCE(supply_amount,0)+COALESCE(vat_amount,0)))>1
---
-- @4-2b 계약: supply/vat 가 NULL 인 행(확정매출 VAT제외 집계에서 0 처리 위험)
SELECT id, contract_no, contract_amount, supply_amount, vat_amount, status, deleted_at FROM {P}contracts WHERE supply_amount IS NULL OR vat_amount IS NULL
---
-- @4-3 계약: 계약금+중도금+잔금 != 계약총액
SELECT id, contract_no, contract_amount, down_payment, middle_payment, balance_payment, (down_payment+middle_payment+balance_payment) AS sum3, contract_amount-(down_payment+middle_payment+balance_payment) AS diff, deleted_at FROM {P}contracts WHERE ABS(contract_amount-(down_payment+middle_payment+balance_payment))>1
---
-- @4-4 계약 총액 vs 입금 합계(입금 status 무관/paid 만)
SELECT ct.id, ct.contract_no, ct.status, ct.payment_status, ct.contract_amount,
  COALESCE(SUM(CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END),0) AS paid_all,
  COALESCE(SUM(CASE WHEN pm.status='paid' THEN (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END) ELSE 0 END),0) AS paid_only,
  ct.contract_amount - COALESCE(SUM(CASE WHEN pm.status='paid' THEN (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END) ELSE 0 END),0) AS remain,
  ct.deleted_at
FROM {P}contracts ct LEFT JOIN {P}payments pm ON pm.contract_id=ct.id GROUP BY ct.id ORDER BY ct.id
---
-- @4-5 프로젝트 전수(금액·상태)
SELECT id, project_no, name, customer_id, contract_id, is_exception, contract_amount, expected_amount, supply_amount, vat_amount, estimated_cost, actual_cost, status, settlement_status, progress, process_stage_id, contribution_mode, deleted_at FROM {P}projects ORDER BY id
---
-- @4-6 프로젝트: 계약과 금액 불일치(contract_amount)
SELECT p.id, p.project_no, p.contract_amount AS proj_amt, ct.contract_amount AS ctr_amt, p.contract_amount-ct.contract_amount AS diff, p.deleted_at AS p_del, ct.deleted_at AS c_del FROM {P}projects p JOIN {P}contracts ct ON ct.id=p.contract_id WHERE ABS(p.contract_amount-ct.contract_amount)>1
---
-- @4-6b 프로젝트: supply/vat 정합
SELECT id, project_no, contract_amount, supply_amount, vat_amount, contract_amount-(COALESCE(supply_amount,0)+COALESCE(vat_amount,0)) AS diff, deleted_at FROM {P}projects WHERE supply_amount IS NOT NULL AND ABS(contract_amount-(COALESCE(supply_amount,0)+COALESCE(vat_amount,0)))>1
---
-- @4-6c 프로젝트: supply/vat NULL
SELECT id, project_no, contract_amount, supply_amount, vat_amount, is_exception, status, deleted_at FROM {P}projects WHERE supply_amount IS NULL OR vat_amount IS NULL
---
-- @4-7 프로젝트: actual_cost vs costs 합계 불일치
SELECT p.id, p.project_no, p.actual_cost, COALESCE(SUM(CASE WHEN c.type='actual' THEN c.amount ELSE 0 END),0) AS sum_actual, p.estimated_cost, COALESCE(SUM(CASE WHEN c.type='estimate' THEN c.amount ELSE 0 END),0) AS sum_est, p.deleted_at FROM {P}projects p LEFT JOIN {P}costs c ON c.project_id=p.id GROUP BY p.id HAVING ABS(p.actual_cost-sum_actual)>1 OR ABS(p.estimated_cost-sum_est)>1
---
-- @4-8 음수 금액
SELECT 'contracts' AS t, id, contract_amount AS amt FROM {P}contracts WHERE contract_amount<0 OR down_payment<0 OR middle_payment<0 OR balance_payment<0 OR COALESCE(supply_amount,0)<0 OR COALESCE(vat_amount,0)<0
UNION ALL SELECT 'projects', id, contract_amount FROM {P}projects WHERE contract_amount<0 OR estimated_cost<0 OR actual_cost<0 OR COALESCE(expected_amount,0)<0
UNION ALL SELECT 'payments', id, amount FROM {P}payments WHERE amount<0
UNION ALL SELECT 'costs', id, amount FROM {P}costs WHERE amount<0
UNION ALL SELECT 'quote_versions', id, total_amount FROM {P}quote_versions WHERE total_amount<0 OR subtotal<0 OR vat<0
UNION ALL SELECT 'quote_items', id, amount FROM {P}quote_items WHERE amount<0
UNION ALL SELECT 'site_bonuses', id, calc_amount FROM {P}site_bonuses WHERE calc_amount<0 OR confirmed_bonus<0 OR base_amount<0
---
-- @4-9 입금 전수
SELECT pm.id, pm.contract_id, pm.project_id, pm.pay_type, pm.kind, pm.method, pm.amount, pm.status, pm.due_date, pm.paid_date, pm.created_by, ct.deleted_at AS ctr_del, p.deleted_at AS proj_del FROM {P}payments pm LEFT JOIN {P}contracts ct ON ct.id=pm.contract_id LEFT JOIN {P}projects p ON p.id=pm.project_id ORDER BY pm.id
---
-- @4-10 입금: status=paid 인데 paid_date NULL / status<>paid 인데 paid_date 존재
SELECT id, contract_id, project_id, status, paid_date, due_date, amount FROM {P}payments WHERE (status='paid' AND paid_date IS NULL) OR (status<>'paid' AND paid_date IS NOT NULL)
---
-- @4-11 quote_versions: subtotal+vat-discount != total_amount
SELECT v.id, v.quote_id, v.version_no, v.subtotal, v.vat, v.discount, v.total_amount, (v.subtotal+v.vat-v.discount) AS calc, v.total_amount-(v.subtotal+v.vat-v.discount) AS diff FROM {P}quote_versions v WHERE ABS(v.total_amount-(v.subtotal+v.vat-v.discount))>1
---
-- @4-12 quote_versions: 항목 합계 vs subtotal
SELECT v.id, v.quote_id, v.version_no, v.subtotal, COALESCE(SUM(i.amount),0) AS items_sum, v.subtotal-COALESCE(SUM(i.amount),0) AS diff, COUNT(i.id) AS item_cnt FROM {P}quote_versions v LEFT JOIN {P}quote_items i ON i.quote_version_id=v.id GROUP BY v.id
---
-- @4-13 계약금액 vs 원본견적금액 정합(original_quote_amount/adjust_amount)
SELECT ct.id, ct.contract_no, ct.quote_id, ct.quote_version_id, ct.original_quote_amount, ct.adjust_amount, ct.contract_amount, (COALESCE(ct.original_quote_amount,0)+COALESCE(ct.adjust_amount,0)) AS calc, v.total_amount AS ver_total FROM {P}contracts ct LEFT JOIN {P}quote_versions v ON v.id=ct.quote_version_id ORDER BY ct.id
---
-- @4-14 payment_status(완납) vs 입금합계 불일치
SELECT ct.id, ct.contract_no, ct.status, ct.payment_status, ct.contract_amount, COALESCE(SUM(CASE WHEN pm.status='paid' THEN (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END) ELSE 0 END),0) AS paid, ct.deleted_at FROM {P}contracts ct LEFT JOIN {P}payments pm ON pm.contract_id=ct.id GROUP BY ct.id HAVING (ct.payment_status='completed' AND paid < ct.contract_amount) OR (ct.payment_status<>'completed' AND paid >= ct.contract_amount AND ct.contract_amount>0)
---
-- @4-15 프로젝트 정산상태 vs 입금합계(project_id 기준)
SELECT p.id, p.project_no, p.status, p.settlement_status, p.contract_amount, COALESCE(SUM(CASE WHEN pm.status='paid' THEN (CASE WHEN pm.kind='refund' THEN -pm.amount ELSE pm.amount END) ELSE 0 END),0) AS paid_by_project, p.deleted_at FROM {P}projects p LEFT JOIN {P}payments pm ON pm.project_id=p.id GROUP BY p.id ORDER BY p.id
