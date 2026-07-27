-- ============================================================================
-- R12 (2026-07-27): 보너스 산식·용어 개편 (사장 실사용 피드백 #2·#3)
--   - 적용 순이익(contrib_profit) 컬럼 신설 — (확정매출−지출)×기여율 스냅샷.
--   - 지급액(paid_amount) → 확정 보너스(confirmed_bonus) 개명. "산정액은 참고, 확정 보너스만 지급".
--   - 부분지급(partial) 개념 폐지 → 미지급(unpaid)/지급완료(paid)/취소(cancelled) 이진 상태.
-- ============================================================================

ALTER TABLE `edencrm_site_bonuses`
  ADD COLUMN `contrib_profit` DECIMAL(14,0) NULL
    COMMENT '적용 순이익 = (프로젝트 확정매출 공급가 − 지출) × 기여율 스냅샷(R12)' AFTER `contrib_revenue`,
  CHANGE COLUMN `paid_amount` `confirmed_bonus` DECIMAL(14,0) NOT NULL DEFAULT 0
    COMMENT '확정 보너스 = 실제 지급 금액(산정액과 별개, 관리자 확정) — 지급완료 시 이 금액만 지급(R12)';

-- 미지급/부분지급 행의 확정 보너스 기본값 = 산정액(참고값) — 관리자가 아직 확정 보너스를 입력 안 한 레거시 행.
UPDATE `edencrm_site_bonuses`
  SET `confirmed_bonus` = `calc_amount`
  WHERE `confirmed_bonus` = 0 AND `pay_status` IN ('unpaid') AND `calc_amount` > 0;

-- 부분지급(partial) → 지급완료(paid) 정규화 (부분지급 개념 폐지 — 확정 보너스=기 지급 금액).
UPDATE `edencrm_site_bonuses` SET `pay_status` = 'paid' WHERE `pay_status` = 'partial';
