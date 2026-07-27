-- 2026-07-22 중요도(importance) 기능 전면 제거
-- 프로젝트·리드 양쪽에서 importance 컬럼을 삭제한다.
-- (뷰·컨트롤러·JS·CSS 의 importance 코드도 동일 커밋에서 제거됨 — fixer T5)

ALTER TABLE `projects` DROP COLUMN `importance`;
ALTER TABLE `leads` DROP COLUMN `importance`;
