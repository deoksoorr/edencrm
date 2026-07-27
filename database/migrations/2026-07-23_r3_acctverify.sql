-- R3 acctverify — 입금(payments) 물리 삭제 금지: status 에 'cancelled'(취소) 도입 (P8)
-- 값 자체는 VARCHAR(20)이라 스키마 변경은 주석 문서화뿐이며, 데이터 변경 없음.
-- 집계 계약: 'paid' 만 입금 총액·순입금·미수금에 포함, 'pending' 만 예정행 동기화·알림 대상,
--            'cancelled' 는 모든 집계·알림에서 제외(기록·감사 추적 보존).
ALTER TABLE `payments`
  MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'pending'
  COMMENT 'pending/paid/cancelled — cancelled=취소(물리 삭제 대체, 집계 제외·기록 보존)';
