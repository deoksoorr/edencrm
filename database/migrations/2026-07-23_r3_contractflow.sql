-- R3 contractflow: 계약 공사 정보 컬럼 + 분할 지급 비율 백필 + 견적 전환 보존 정보 백필
-- [T10] 2026-07-22 → 2026-07-23 개명: §2 백필이 r3_kernel 의 down_pct/middle_pct/balance_pct 에
--       의존하므로 파일명 정렬(=실행) 순서를 kernel 뒤로 보정(마이그레이션은 파일명 순 실행 규약).
-- 흐름: 견적 선택 → 계약 폼 자동 입력(수정 가능, 원본 견적 불변) → 계약 active 전환 시
--       ContractProjectService 가 이 공사 정보를 복사해 프로젝트를 자동 생성한다.

-- 1) 계약 공사 정보(견적→계약 자동 입력 대상이자 프로젝트 자동 생성 복사 원천)
ALTER TABLE `contracts`
  ADD COLUMN `work_name` VARCHAR(150) NULL COMMENT '공사명(프로젝트 자동 생성 시 프로젝트명)' AFTER `special_terms`,
  ADD COLUMN `site_address` VARCHAR(255) NULL COMMENT '현장 주소' AFTER `work_name`,
  ADD COLUMN `work_type` VARCHAR(50) NULL COMMENT '공사 유형' AFTER `site_address`,
  ADD COLUMN `memo` TEXT NULL COMMENT '메모' AFTER `work_type`;

-- 2) 기존 계약 분할 지급 비율 백필 — 금액에서 역산(기준: 계약 총액(VAT 포함)).
--    합계 100 보정은 잔금 귀속(balance_pct = 100 − down − middle). 세 금액 모두 0인 계약은 스킵(NULL 유지).
UPDATE `contracts`
SET `down_pct`    = ROUND(`down_payment`   / `contract_amount` * 100, 2),
    `middle_pct`  = ROUND(`middle_payment` / `contract_amount` * 100, 2),
    `balance_pct` = 100 - ROUND(`down_payment` / `contract_amount` * 100, 2)
                        - ROUND(`middle_payment` / `contract_amount` * 100, 2)
WHERE `contract_amount` > 0
  AND (`down_payment` + `middle_payment` + `balance_payment`) > 0
  AND (`down_payment` + `middle_payment`) <= `contract_amount`
  AND `down_pct` IS NULL AND `middle_pct` IS NULL AND `balance_pct` IS NULL;

-- 3) 견적 연결 계약의 전환 보존 정보 백필(현재 버전 기준, 기존 값 있으면 유지)
UPDATE `contracts` c
JOIN `quotes` q ON q.id = c.quote_id
JOIN `quote_versions` qv ON qv.id = q.current_version_id
SET c.quote_version_id      = qv.id,
    c.original_quote_amount = qv.total_amount,
    c.adjust_amount         = c.contract_amount - qv.total_amount,
    c.converted_at          = c.created_at,
    c.converted_by          = c.sales_user_id
WHERE c.quote_version_id IS NULL;

-- 4) 계약 공사 정보 백필: 연결 프로젝트(소프트삭제 제외) → 남은 건 고객 현장주소
UPDATE `contracts` c
JOIN `projects` p ON p.contract_id = c.id AND p.deleted_at IS NULL
SET c.work_name = p.name, c.site_address = p.site_address, c.work_type = p.work_type
WHERE c.work_name IS NULL;
UPDATE `contracts` c
JOIN `customers` cu ON cu.id = c.customer_id
SET c.site_address = cu.site_address
WHERE c.site_address IS NULL;
