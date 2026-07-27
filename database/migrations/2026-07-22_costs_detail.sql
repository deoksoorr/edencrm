-- R2 T3: costs 상세 확장 (브리프 §3) — 신규 테이블이 아니라 기존 costs 확장.
-- 1) 상태·수량·단가·작업자·공급처·증빙·조정사유 컬럼 추가
-- 2) category 를 표준 키(material/labor/outsourcing/equipment/transport/meal/waste/etc)로 표준화
-- 적용: mysql --socket=.devdb/mysql.sock -ueden_crm_user -p'...' eden_crm < database/migrations/2026-07-22_costs_detail.sql

ALTER TABLE `costs`
  ADD COLUMN `cost_status` VARCHAR(20) NOT NULL DEFAULT 'confirmed' COMMENT 'draft(임시 저장)/pending(확인 대기)/confirmed(확정)/cancelled(취소)' AFTER `type`,
  ADD COLUMN `item_name` VARCHAR(150) NULL COMMENT '내용/자재명' AFTER `category`,
  ADD COLUMN `spec` VARCHAR(100) NULL COMMENT '규격(예: 18L, KCC 숲으로)' AFTER `item_name`,
  ADD COLUMN `qty` DECIMAL(10,2) NULL COMMENT '수량(자재 등)' AFTER `spec`,
  ADD COLUMN `unit` VARCHAR(20) NULL COMMENT '단위(말/EA/㎡/식 등)' AFTER `qty`,
  ADD COLUMN `unit_price` DECIMAL(14,0) NULL COMMENT '단가(자재) / 일당·시급(인건)' AFTER `unit`,
  ADD COLUMN `worker_id` INT UNSIGNED NULL COMMENT '작업자(직원, 인건비)' AFTER `amount`,
  ADD COLUMN `worker_name` VARCHAR(50) NULL COMMENT '작업자명(외부 인력 직접 입력)' AFTER `worker_id`,
  ADD COLUMN `work_days` DECIMAL(5,2) NULL COMMENT '작업 일수(인건비)' AFTER `worker_name`,
  ADD COLUMN `work_hours` DECIMAL(6,2) NULL COMMENT '작업 시간(인건비, 일수 대신)' AFTER `work_days`,
  ADD COLUMN `vendor` VARCHAR(100) NULL COMMENT '공급처/거래처(자재비 등)' AFTER `work_hours`,
  ADD COLUMN `receipt_file_id` INT UNSIGNED NULL COMMENT '증빙 파일(project_files.id)' AFTER `vendor`,
  ADD COLUMN `adjust_reason` VARCHAR(255) NULL COMMENT '자동계산(수량×단가·일수×일당)과 다른 수동 금액 사유' AFTER `receipt_file_id`,
  MODIFY COLUMN `category` VARCHAR(30) NOT NULL COMMENT 'material/labor/outsourcing/equipment/transport/meal/waste/etc',
  ADD KEY `idx_costs_cost_status` (`cost_status`),
  ADD KEY `idx_costs_worker` (`worker_id`),
  ADD CONSTRAINT `fk_costs_worker` FOREIGN KEY (`worker_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_costs_receipt_file` FOREIGN KEY (`receipt_file_id`) REFERENCES `project_files` (`id`) ON DELETE RESTRICT;

-- 기존 한글 카테고리 → 표준 키 매핑 (표준 키가 아닌 값은 전부 매핑, 미지의 값은 etc)
UPDATE `costs` SET `category` = CASE `category`
    WHEN '자재비'        THEN 'material'
    WHEN '자재'          THEN 'material'
    WHEN '인건비'        THEN 'labor'
    WHEN '일용직'        THEN 'labor'
    WHEN '외주'          THEN 'outsourcing'
    WHEN '외주비'        THEN 'outsourcing'
    WHEN '장비'          THEN 'equipment'
    WHEN '장비비'        THEN 'equipment'
    WHEN '차량'          THEN 'transport'
    WHEN '운송비'        THEN 'transport'
    WHEN '식비'          THEN 'meal'
    WHEN '폐기물'        THEN 'waste'
    WHEN '폐기물 처리비' THEN 'waste'
    ELSE 'etc'
  END
WHERE `category` NOT IN ('material','labor','outsourcing','equipment','transport','meal','waste','etc');
