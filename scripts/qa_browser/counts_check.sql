-- 마이그레이션 전후 정합 카운트(§9) — 로컬은 그대로, 운영은 테이블명에 edencrm_ 접두사 치환 후 사용
SELECT '프로젝트 총개수' k, COUNT(*) v FROM projects WHERE deleted_at IS NULL
UNION ALL SELECT '진행 중', COUNT(*) FROM projects WHERE deleted_at IS NULL AND status='in_progress'
UNION ALL SELECT '완료', COUNT(*) FROM projects WHERE deleted_at IS NULL AND status IN ('completed','settled')
UNION ALL SELECT '계약 총액(유효)', COALESCE(SUM(contract_amount),0) FROM contracts WHERE deleted_at IS NULL AND status NOT IN ('cancelled','terminated')
UNION ALL SELECT '입금 총액(정상)', COALESCE(SUM(amount),0) FROM payments WHERE status='paid' AND kind='payment'
UNION ALL SELECT '지출 총액(확정)', COALESCE(SUM(amount),0) FROM costs WHERE cost_status='confirmed' AND type='actual'
UNION ALL SELECT '직원 총수', COUNT(*) FROM users
UNION ALL SELECT '공정 이력 총수', COUNT(*) FROM project_process_history
UNION ALL SELECT '공정 단계 수', COUNT(*) FROM process_stages;
