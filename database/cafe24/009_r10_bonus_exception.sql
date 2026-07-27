-- ============================================================================
-- R10 (2026-07-27) — 카페24 공유 DB(edencrm_ prefix) 전용
-- 1) site_bonuses.bonus_rate: 보너스율(%) — 산정 금액 = 기여도 적용 매출 × 보너스율/100.
--    산정 대상 매출 정의도 R10 부터 '누적 확정 입금(입금−환불)' 기준으로 변경(코드 레벨 — 컬럼 재사용).
-- 2) projects 예외 프로젝트: customer_id NULL 허용 + is_exception + 고객명 스냅샷.
--    백필: 계약 미연결 수동 생성 프로젝트(기존 '예외 프로젝트 생성' 경로) = is_exception=1.
-- ALTER 는 1회 실행 전제(재실행 시 중복 컬럼 오류).
-- ============================================================================

ALTER TABLE `edencrm_site_bonuses`
  ADD COLUMN `bonus_rate` DECIMAL(5,2) NULL
    COMMENT '보너스율(%) — 산정=기여도적용매출×율/100(R10)' AFTER `contrib_revenue`;

ALTER TABLE `edencrm_projects`
  MODIFY `customer_id` INT UNSIGNED NULL,
  ADD COLUMN `is_exception` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '예외 프로젝트(수동 생성·계약 미연결) 여부' AFTER `customer_id`,
  ADD COLUMN `customer_name_snapshot` VARCHAR(150) NULL
    COMMENT '예외 프로젝트 고객명 자유입력 스냅샷' AFTER `is_exception`;

-- 백필: 계약 미연결 프로젝트 = 예외 생성 경로(project_exception_create)로만 만들어질 수 있음
UPDATE `edencrm_projects` SET `is_exception` = 1
 WHERE `contract_id` IS NULL AND `deleted_at` IS NULL;
