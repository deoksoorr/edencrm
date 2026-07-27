-- ============================================================================
-- R7-F5 리드 파이프라인 단계 정합 보정 (2026-07-23)
-- 증상: 계약 완료·완납인데 대시보드 깔때기에는 리드가 '상담·현장'에 잔류(전환율 0%).
-- 원인: 계약 체결 흐름에서 리드 원본 stage 자동 전이 부재(코드 수정과 함께 배포).
-- 규칙: 종결(is_won/is_lost) 리드 불변 · 진행/보류/완료 계약 연결 → contract_won ·
--       작성중(draft) 계약 연결 → contract_pending(승리경로 후퇴 금지, 보류 재활성 허용). 멱등.
-- ============================================================================

-- (1) 진행·보류·완료 계약이 연결된 리드 → 계약완료(contract_won)
UPDATE `edencrm_leads` l
  JOIN `edencrm_quotes` q ON q.lead_id = l.id AND q.deleted_at IS NULL
  JOIN `edencrm_contracts` c ON c.quote_id = q.id AND c.deleted_at IS NULL
       AND c.status IN ('active','on_hold','completed')
  JOIN `edencrm_pipeline_stages` cur ON cur.id = l.stage_id AND cur.is_won = 0 AND cur.is_lost = 0
  JOIN `edencrm_pipeline_stages` tgt ON tgt.stage_key = 'contract_won'
   SET l.stage_id = tgt.id, l.stage_entered_at = NOW()
 WHERE l.deleted_at IS NULL;

-- (2) 작성중(draft) 계약만 연결된 리드 → 계약대기(contract_pending)
UPDATE `edencrm_leads` l
  JOIN `edencrm_quotes` q ON q.lead_id = l.id AND q.deleted_at IS NULL
  JOIN `edencrm_contracts` c ON c.quote_id = q.id AND c.deleted_at IS NULL AND c.status = 'draft'
  JOIN `edencrm_pipeline_stages` cur ON cur.id = l.stage_id AND cur.is_won = 0 AND cur.is_lost = 0
  JOIN `edencrm_pipeline_stages` tgt ON tgt.stage_key = 'contract_pending'
   SET l.stage_id = tgt.id, l.stage_entered_at = NOW()
 WHERE l.deleted_at IS NULL
   AND (cur.sort_order < tgt.sort_order OR cur.stage_key IN ('on_hold','no_response'));
