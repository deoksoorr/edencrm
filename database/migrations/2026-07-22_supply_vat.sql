-- 공급가액/부가세 분리 컬럼 추가 + 백필 (기존 DB 대상).
-- 주의: ADD COLUMN 에는 IF NOT EXISTS 를 쓰지 않는다 (MariaDB 전용 문법이며 MySQL 8 은 문법 오류를 낸다).
--       이 마이그레이션은 컬럼이 아직 없는 DB에 1회 적용하는 것을 전제로 한다(재실행 시 컬럼 중복 오류로 실패해야 정상).
-- contracts
ALTER TABLE `contracts`
  ADD COLUMN `supply_amount` DECIMAL(14,0) NULL AFTER `contract_amount`,
  ADD COLUMN `vat_amount`    DECIMAL(14,0) NULL AFTER `supply_amount`;
-- projects
ALTER TABLE `projects`
  ADD COLUMN `supply_amount` DECIMAL(14,0) NULL AFTER `contract_amount`,
  ADD COLUMN `vat_amount`    DECIMAL(14,0) NULL AFTER `supply_amount`;

-- 백필 1) 견적 연결 계약: 견적 vat 사용 (총액과 정확 정합)
UPDATE `contracts` c
  JOIN `quotes` q ON q.id = c.quote_id
  JOIN `quote_versions` qv ON qv.id = q.current_version_id
  SET c.vat_amount = qv.vat,
      c.supply_amount = c.contract_amount - qv.vat
  WHERE c.quote_id IS NOT NULL AND (c.supply_amount IS NULL OR c.vat_amount IS NULL);

-- 백필 2) 견적 미연결 계약: ÷1.1 파생 (vat_rate 10 가정)
UPDATE `contracts` c
  SET c.vat_amount = c.contract_amount - ROUND(c.contract_amount / 1.1),
      c.supply_amount = ROUND(c.contract_amount / 1.1)
  WHERE c.supply_amount IS NULL OR c.vat_amount IS NULL;

-- 백필 3) 프로젝트: 연결 계약값 승계
UPDATE `projects` p
  JOIN `contracts` c ON c.id = p.contract_id
  SET p.supply_amount = c.supply_amount, p.vat_amount = c.vat_amount
  WHERE p.contract_id IS NOT NULL AND (p.supply_amount IS NULL OR p.vat_amount IS NULL);

-- 백필 4) 계약 미연결 프로젝트: ÷1.1 파생
UPDATE `projects` p
  SET p.vat_amount = p.contract_amount - ROUND(p.contract_amount / 1.1),
      p.supply_amount = ROUND(p.contract_amount / 1.1)
  WHERE p.supply_amount IS NULL OR p.vat_amount IS NULL;
