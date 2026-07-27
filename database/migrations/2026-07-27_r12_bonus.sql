-- R12 로컬(무프리픽스)판 — database/cafe24/011_r12_bonus_confirmed.sql 과 동일 내용.
-- 적용: php scripts/apply_local_migration.php database/migrations/2026-07-27_r12_bonus.sql

ALTER TABLE `site_bonuses`
  ADD COLUMN `contrib_profit` DECIMAL(14,0) NULL
    COMMENT '적용 순이익 = (프로젝트 확정매출 공급가 − 지출) × 기여율 스냅샷(R12)' AFTER `contrib_revenue`,
  CHANGE COLUMN `paid_amount` `confirmed_bonus` DECIMAL(14,0) NOT NULL DEFAULT 0
    COMMENT '확정 보너스 = 실제 지급 금액(산정액과 별개, 관리자 확정) — 지급완료 시 이 금액만 지급(R12)';

UPDATE `site_bonuses`
  SET `confirmed_bonus` = `calc_amount`
  WHERE `confirmed_bonus` = 0 AND `pay_status` IN ('unpaid') AND `calc_amount` > 0;

UPDATE `site_bonuses` SET `pay_status` = 'paid' WHERE `pay_status` = 'partial';
