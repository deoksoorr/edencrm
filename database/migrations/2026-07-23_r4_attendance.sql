-- R4 T4 근태 복구 — feature_attendance 신설(기본 ON).
-- 배경: R2 에서 feature_worklog 기본 OFF 시 대시보드 출근 섹션이 같은 플래그에 묶여 함께 사라짐
--       (DashboardController::employeeWork 의 feature_worklog 가드). 사용자 지시 = 근태 표시 복구.
-- 결정: 출근 현황·출근 분석은 work_logs 데이터 기반이되 feature_attendance(기본 '1')로 분리.
--       작업일지 메뉴·작성(feature_worklog)은 기존 정책 유지(기본 OFF) — 정책 변경 아님.
-- 스키마 변경 없음(설정 행 1건 추가). 이미 있으면 건드리지 않는다(운영자 변경 값 보존).
INSERT INTO `settings` (`setting_key`, `value`, `group`, `label`)
SELECT 'feature_attendance', '1', '운영 기능',
       '근태 표시(대시보드 출근 현황·리포트 직원 출근 분석) — 작업일지 메뉴(feature_worklog)와 별개'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'feature_attendance');
