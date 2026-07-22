-- EDEN CRM 개발용 최소 시드 (2026-07 리셋)
-- 더미데이터 전면 제거 후, 직원 3명 + 프로젝트 3건(진행 1·예정 2, 지연 없음)만 시딩.
-- seed_core.sql(역할·권한·단계·설정·공휴일) 적재 후 실행. 비밀번호는 전부 password123!.
SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ── 기존 데이터 정리 (seed_core 참조데이터는 보존) ──
DELETE FROM `user_permissions`;
DELETE FROM `customer_contacts`;
DELETE FROM `customer_activities`;
DELETE FROM `leads`;
DELETE FROM `quote_items`;
DELETE FROM `quote_versions`;
DELETE FROM `quotes`;
DELETE FROM `payments`;
DELETE FROM `contracts`;
DELETE FROM `project_files`;
DELETE FROM `work_log_photos`;
DELETE FROM `work_logs`;
DELETE FROM `costs`;
DELETE FROM `project_process_history`;
DELETE FROM `project_assignments`;
DELETE FROM `schedule_participants`;
DELETE FROM `schedules`;
DELETE FROM `projects`;
DELETE FROM `customers`;
DELETE FROM `targets`;
DELETE FROM `company_targets`;
DELETE FROM `notifications`;
DELETE FROM `audit_logs`;
DELETE FROM `login_attempts`;
DELETE FROM `users`;
DELETE FROM `departments`;

-- ── 부서 ──
INSERT INTO `departments` (`id`,`name`,`description`,`sort_order`) VALUES
(1,'현장시공팀','도장·방수 현장 시공',1),
(2,'경영지원팀','대표·관리',2);

-- ── 직원 4명(대표 1 + 현장 인력 3) — password123! ──
INSERT INTO `users`
(`id`,`login_id`,`email`,`password_hash`,`name`,`phone`,`department_id`,`position`,`color`,`role_id`,`role_key`,`hire_date`,`status`,`must_change_password`,`created_at`) VALUES
(1,'admin','admin@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','김대표','010-1000-0001',2,'대표','#1a56db',1,'super_admin','2019-03-01','active',0,NOW()),
(2,'chays','chays@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','차윤석','010-2000-0002',1,'현장팀장','#0f9d58',3,'site_manager','2021-05-01','active',0,NOW()),
(3,'maeng','maeng@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','맹기현','010-2000-0003',1,'도장기능공','#e8710a',4,'staff','2022-08-01','active',0,NOW()),
(4,'chaws','chaws@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','차우석','010-2000-0004',1,'도장보조','#d93025',4,'staff','2023-03-01','active',0,NOW());

-- ── 고객 3(프로젝트별) ──
INSERT INTO `customers`
(`id`,`type`,`name`,`company_name`,`contact_name`,`phone`,`address`,`site_address`,`source`,`sales_user_id`,`status`,`last_consult_date`,`created_at`) VALUES
(1,'company','대명건설','대명건설(주)','김상무','010-3000-0001','서울 강남구 테헤란로 152','서울 강남구 대명빌딩','소개',1,'active','2026-07-04',NOW()),
(2,'individual','이수아',NULL,'이수아','010-3000-0002','경기 성남시 분당구 판교로 20','경기 성남시 분당구 이수아 주택','홈페이지',1,'active','2026-07-15',NOW()),
(3,'company','한빛아파트 입주자대표회의','한빛아파트','박회장','010-3000-0003','인천 연수구 송도과학로 30','인천 연수구 한빛아파트','재계약',1,'active','2026-07-18',NOW());

-- ── 견적 1건(면적기반 금액 시연): 금액 = 단가(원/㎡) × 면적 × 수량 ──
INSERT INTO `quotes` (`id`,`quote_no`,`lead_id`,`customer_id`,`status`,`current_version_id`,`valid_until`,`created_at`) VALUES
(1,'Q2026-0001',NULL,1,'accepted',NULL,'2026-08-31','2026-07-05 10:00:00');
INSERT INTO `quote_versions`
(`id`,`quote_id`,`version_no`,`subtotal`,`vat`,`discount`,`total_amount`,`note`,`created_by`,`created_at`) VALUES
(1,1,1,34622500,3462250,622500,37462250,'최초 견적',1,'2026-07-05 10:00:00');
UPDATE `quotes` SET `current_version_id`=1 WHERE `id`=1;
INSERT INTO `quote_items`
(`id`,`quote_version_id`,`name`,`area`,`qty`,`unit_price`,`material_cost`,`labor_cost`,`equipment_cost`,`outsourcing_cost`,`etc_cost`,`amount`,`sort_order`) VALUES
(1,1,'외벽 도장',850.50,1.00,25000,8200000,7400000,900000,1200000,600000,21262500,1),
(2,1,'옥상 방수',320.00,1.00,35000,4300000,3800000,700000,900000,300000,11200000,2),
(3,1,'철제 난간 도장',120.00,1.00,18000,800000,900000,200000,150000,110000,2160000,3);

-- ── 계약 1건(견적 → 계약) + 입금 ──
INSERT INTO `contracts`
(`id`,`contract_no`,`quote_id`,`customer_id`,`contract_date`,`contract_amount`,`down_payment`,`middle_payment`,`balance_payment`,`start_date`,`end_date`,`warranty_period`,`status`,`payment_status`,`sales_user_id`,`created_at`) VALUES
(1,'C2026-0001',1,1,'2026-07-08',37462250,11238675,15000000,11223575,'2026-07-10','2026-09-30','2년','active','partial',1,'2026-07-08 09:00:00');
INSERT INTO `payments`
(`id`,`contract_id`,`pay_type`,`amount`,`due_date`,`paid_date`,`status`,`created_at`) VALUES
(1,1,'down',11238675,'2026-07-10','2026-07-10','paid',NOW()),
(2,1,'middle',15000000,'2026-08-10',NULL,'pending',NOW()),
(3,1,'balance',11223575,'2026-09-30',NULL,'pending',NOW());

-- ── 프로젝트 3건: 진행 1 · 예정 2 (지연 없음, 준공예정 모두 미래) ──
INSERT INTO `projects`
(`id`,`project_no`,`contract_id`,`customer_id`,`name`,`work_type`,`site_address`,`contract_amount`,`estimated_cost`,`actual_cost`,`process_stage_id`,`status`,`progress`,`contract_date`,`start_date`,`end_date`,`actual_start_date`,`actual_end_date`,`site_manager_id`,`sales_user_id`,`contribution_mode`,`created_at`) VALUES
(1,'P2026-0001',1,1,'대명건설 사옥 외벽 도장','아파트외벽','서울 강남구 대명빌딩',37462250,24560000,9800000,12,'in_progress',67,'2026-07-08','2026-07-10','2026-09-30','2026-07-10',NULL,2,1,'ratio',NOW()),
(2,'P2026-0002',NULL,2,'이수아 주택 방수 공사','옥상방수','경기 성남시 분당구 이수아 주택',18500000,12300000,0,NULL,'preparing',0,NULL,'2026-08-01','2026-10-15',NULL,NULL,2,1,'ratio',NOW()),
(3,'P2026-0003',NULL,3,'한빛아파트 계단실 도장','아파트내부','인천 연수구 한빛아파트',9800000,6400000,0,NULL,'preparing',0,NULL,'2026-08-10','2026-11-01',NULL,NULL,2,1,'ratio',NOW());

-- ── 배정(진행 프로젝트에 3명, 예정엔 현장팀장) ──
INSERT INTO `project_assignments`
(`id`,`project_id`,`user_id`,`role`,`start_date`,`end_date`,`contribution_pct`,`status`,`created_at`) VALUES
(1,1,2,'현장책임자','2026-07-10','2026-09-30',40.00,'active',NOW()),
(2,1,3,'도장작업자','2026-07-10','2026-09-30',35.00,'active',NOW()),
(3,1,4,'보조','2026-07-10','2026-09-30',25.00,'active',NOW()),
(4,2,2,'현장책임자','2026-08-01','2026-10-15',100.00,'active',NOW()),
(5,3,2,'현장책임자','2026-08-10','2026-11-01',100.00,'active',NOW());

-- ── 오늘(2026-07-22) 일정 — 직원 업무 현황용 ──
INSERT INTO `schedules`
(`id`,`project_id`,`user_id`,`title`,`event_date`,`slot`,`start_datetime`,`end_datetime`,`type`,`status`,`created_at`) VALUES
(1,1,3,'대명건설 외벽 상도 작업','2026-07-22','pm','2026-07-22 13:00:00','2026-07-22 18:00:00','work','scheduled',NOW()),
(2,2,2,'이수아 주택 현장 실측','2026-07-22','am','2026-07-22 09:00:00','2026-07-22 12:00:00','work','scheduled',NOW());
INSERT INTO `schedule_participants` (`schedule_id`,`user_id`) VALUES
(1,3),(1,4),(2,2);

-- ── 이번 달(2026-07) 작업일지 — 출근(작업일수)용 ──
INSERT INTO `work_logs` (`project_id`,`user_id`,`work_date`,`content`,`confirmed_by`,`confirmed_at`,`created_at`) VALUES
(1,2,'2026-07-10','현장 준비·바탕처리',1,'2026-07-11 09:00:00',NOW()),
(1,2,'2026-07-11','고압세척',1,'2026-07-12 09:00:00',NOW()),
(1,2,'2026-07-15','프라이머 도포',1,'2026-07-16 09:00:00',NOW()),
(1,2,'2026-07-16','1차 도장',1,'2026-07-17 09:00:00',NOW()),
(1,2,'2026-07-17','1차 도장',1,'2026-07-18 09:00:00',NOW()),
(1,2,'2026-07-18','1차 도장 마감',1,'2026-07-19 09:00:00',NOW()),
(1,2,'2026-07-20','2차 도장 준비',NULL,NULL,NOW()),
(1,2,'2026-07-21','2차 도장',NULL,NULL,NOW()),
(1,3,'2026-07-15','프라이머 도포',1,'2026-07-16 09:00:00',NOW()),
(1,3,'2026-07-16','1차 도장',1,'2026-07-17 09:00:00',NOW()),
(1,3,'2026-07-17','1차 도장',1,'2026-07-18 09:00:00',NOW()),
(1,3,'2026-07-18','1차 도장',1,'2026-07-19 09:00:00',NOW()),
(1,3,'2026-07-21','2차 도장',NULL,NULL,NOW()),
(1,4,'2026-07-16','자재 운반·보조',1,'2026-07-17 09:00:00',NOW()),
(1,4,'2026-07-17','도장 보조',1,'2026-07-18 09:00:00',NOW()),
(1,4,'2026-07-18','도장 보조',1,'2026-07-19 09:00:00',NOW()),
(1,4,'2026-07-21','도장 보조',NULL,NULL,NOW());

-- ── 회사 목표(2026) — 대시보드 매출 목표 달성률 ──
INSERT INTO `company_targets` (`period_type`,`year`,`period_no`,`target_revenue`,`target_profit`) VALUES
('month',2026,7,50000000,15000000),('month',2026,8,60000000,18000000),('month',2026,9,55000000,16000000),
('quarter',2026,3,165000000,49000000),('year',2026,0,600000000,180000000);

-- 공급가액/부가세 백필 (schema 컬럼 기준)
UPDATE contracts c JOIN quotes q ON q.id=c.quote_id JOIN quote_versions qv ON qv.id=q.current_version_id
  SET c.vat_amount=qv.vat, c.supply_amount=c.contract_amount-qv.vat WHERE c.quote_id IS NOT NULL;
UPDATE contracts c SET c.vat_amount=c.contract_amount-ROUND(c.contract_amount/1.1), c.supply_amount=ROUND(c.contract_amount/1.1) WHERE c.vat_amount IS NULL;
UPDATE projects p JOIN contracts c ON c.id=p.contract_id SET p.vat_amount=c.vat_amount, p.supply_amount=p.contract_amount-c.vat_amount WHERE p.contract_id IS NOT NULL;
UPDATE projects p SET p.vat_amount=p.contract_amount-ROUND(p.contract_amount/1.1), p.supply_amount=ROUND(p.contract_amount/1.1) WHERE p.vat_amount IS NULL;

SET FOREIGN_KEY_CHECKS = 1;
