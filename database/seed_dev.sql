-- EDEN CRM 개발용 현실 시드 (2026-07 기준, 도장회사 실제 운영 형태)
-- 완료(정상이익/적자) · 진행중 · 예정 · 취소 프로젝트를 함께 시딩해 대시보드·리포트·성과가
-- 의미 있는 확정 수치(확정매출·확정순이익·미수금·기여도·파이프라인)를 보여주도록 구성한다.
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
(2,'경영지원팀','대표·관리·영업',2);

-- ── 직원 5명(대표1 + 영업팀장1 + 현장팀장1 + 현장인력2) — password123! ──
INSERT INTO `users`
(`id`,`login_id`,`email`,`password_hash`,`name`,`phone`,`department_id`,`position`,`color`,`role_id`,`role_key`,`hire_date`,`status`,`must_change_password`,`created_at`) VALUES
(1,'admin','admin@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','김대표','010-1000-0001',2,'대표','#1a56db',1,'super_admin','2019-03-01','active',0,NOW()),
(2,'chays','chays@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','차윤석','010-2000-0002',1,'현장팀장','#0f9d58',3,'site_manager','2021-05-01','active',0,NOW()),
(3,'maeng','maeng@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','맹기현','010-2000-0003',1,'도장기능공','#e8710a',4,'staff','2022-08-01','active',0,NOW()),
(4,'chaws','chaws@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','차우석','010-2000-0004',1,'도장보조','#d93025',4,'staff','2023-03-01','active',0,NOW()),
(5,'leesh','leesh@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','이상훈','010-2000-0005',2,'영업팀장','#7c3aed',2,'sales_manager','2020-01-15','active',0,NOW());

-- ── 고객 6 ──
INSERT INTO `customers`
(`id`,`type`,`name`,`company_name`,`contact_name`,`phone`,`address`,`site_address`,`source`,`sales_user_id`,`status`,`last_consult_date`,`created_at`) VALUES
(1,'company','대명건설','대명건설(주)','김상무','010-3000-0001','서울 강남구 테헤란로 152','서울 강남구 대명빌딩','소개',5,'active','2026-07-04',NOW()),
(2,'individual','이수아',NULL,'이수아','010-3000-0002','경기 성남시 분당구 판교로 20','경기 성남시 분당구 이수아 주택','홈페이지',5,'active','2026-07-15',NOW()),
(3,'company','한빛아파트 입주자대표회의','한빛아파트','박회장','010-3000-0003','인천 연수구 송도과학로 30','인천 연수구 한빛아파트','재계약',5,'active','2026-07-18',NOW()),
(4,'company','서초상가','서초상가번영회','이사장','010-4000-0004','서울 서초구 서초대로 100','서울 서초구 서초상가','전화',5,'active','2026-07-17',NOW()),
(5,'company','남동공단 ㈜세림','㈜세림','이대표','010-5000-0005','인천 남동구 남동공단 12길 5','인천 남동구 세림공장','현수막',5,'active','2026-07-19',NOW()),
(6,'company','판교오피스텔','판교오피스텔관리단','최대표','010-6000-0006','경기 성남시 분당구 판교역로 235','경기 성남시 분당구 판교오피스텔','홈페이지',5,'active','2026-07-01',NOW());

-- ── 견적 1건(면적기반 금액 시연, 대명건설 계약과 연결): 금액 = 단가(원/㎡) × 면적 × 수량 ──
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

-- ── 계약 4건 + 입금(부분입금/완납/미입금 시나리오) — 미수금 총액 46,523,575 ──
INSERT INTO `contracts`
(`id`,`contract_no`,`quote_id`,`customer_id`,`contract_date`,`contract_amount`,`supply_amount`,`vat_amount`,`down_payment`,`middle_payment`,`balance_payment`,`start_date`,`end_date`,`warranty_period`,`status`,`payment_status`,`sales_user_id`,`created_at`) VALUES
(1,'C2026-0001',1,1,'2026-07-08',37462250,34000000,3462250,11238675,15000000,11223575,'2026-07-10','2026-09-30','2년','active','partial',5,'2026-07-08 09:00:00'),
(2,'C2026-0002',NULL,2,'2026-06-01',22000000,20000000,2000000,6600000,0,15400000,'2026-06-05','2026-07-15','2년','completed','paid',5,'2026-06-01 10:00:00'),
(3,'C2026-0003',NULL,3,'2026-06-10',9900000,9000000,900000,5000000,0,4900000,'2026-06-15','2026-06-30','2년','active','partial',5,'2026-06-10 10:00:00'),
(4,'C2026-0004',NULL,4,'2026-07-18',15400000,14000000,1400000,4620000,5390000,5390000,'2026-07-25','2026-08-20','1년','active','unpaid',5,'2026-07-18 10:00:00');
INSERT INTO `payments`
(`id`,`contract_id`,`pay_type`,`amount`,`due_date`,`paid_date`,`status`,`created_at`) VALUES
(1,1,'down',11238675,'2026-07-10','2026-07-10','paid',NOW()),
(2,1,'middle',15000000,'2026-08-10',NULL,'pending',NOW()),
(3,1,'balance',11223575,'2026-09-30',NULL,'pending',NOW()),
(4,2,'down',6600000,'2026-06-01','2026-06-01','paid',NOW()),
(5,2,'balance',15400000,'2026-07-10','2026-07-10','paid',NOW()),
(6,3,'down',5000000,'2026-06-10','2026-06-10','paid',NOW()),
(7,3,'balance',4900000,'2026-06-30',NULL,'pending',NOW()),
(8,4,'down',4620000,'2026-07-25',NULL,'pending',NOW()),
(9,4,'middle',5390000,'2026-08-05',NULL,'pending',NOW()),
(10,4,'balance',5390000,'2026-08-20',NULL,'pending',NOW());

-- ── 프로젝트 6건: 진행1(P1) · 완료 정상이익1(P2) · 완료 적자1(P3) · 예정2(P4,P5) · 취소1(P6) ──
INSERT INTO `projects`
(`id`,`project_no`,`contract_id`,`customer_id`,`name`,`work_type`,`site_address`,`contract_amount`,`supply_amount`,`vat_amount`,`estimated_cost`,`actual_cost`,`process_stage_id`,`status`,`progress`,`contract_date`,`start_date`,`end_date`,`actual_start_date`,`actual_end_date`,`site_manager_id`,`sales_user_id`,`contribution_mode`,`created_at`) VALUES
(1,'P2026-0001',1,1,'대명건설 사옥 외벽 도장','아파트외벽','서울 강남구 대명빌딩',37462250,34000000,3462250,24560000,9800000,12,'in_progress',67,'2026-07-08','2026-07-10','2026-09-30','2026-07-10',NULL,2,5,'ratio',NOW()),
(2,'P2026-0002',2,2,'이수아 주택 방수 공사','옥상방수','경기 성남시 분당구 이수아 주택',22000000,20000000,2000000,13500000,14000000,17,'completed',100,'2026-06-01','2026-06-05','2026-07-15','2026-06-05','2026-07-10',2,5,'ratio',NOW()),
(3,'P2026-0003',3,3,'한빛아파트 계단실 도장','아파트내부','인천 연수구 한빛아파트',9900000,9000000,900000,7500000,10500000,17,'completed',100,'2026-06-10','2026-06-15','2026-06-30','2026-06-15','2026-07-05',2,5,'ratio',NOW()),
(4,'P2026-0004',4,4,'서초상가 리모델링','상가리모델링','서울 서초구 서초상가',15400000,14000000,1400000,9800000,0,NULL,'preparing',0,'2026-07-18','2026-07-25','2026-08-20',NULL,NULL,2,5,'ratio',NOW()),
(5,'P2026-0005',NULL,5,'세림 공장 에폭시 시공','공장에폭시','인천 남동구 세림공장',8800000,8000000,800000,5600000,0,NULL,'preparing',0,'2026-07-20','2026-08-01','2026-09-01',NULL,NULL,2,5,'ratio',NOW()),
(6,'P2026-0006',NULL,6,'판교오피스텔 외벽 도장','오피스텔외벽','경기 성남시 분당구 판교오피스텔',11000000,10000000,1000000,7000000,0,NULL,'cancelled',0,'2026-07-02',NULL,NULL,NULL,NULL,2,5,'ratio',NOW());

-- ── 배정(project_assignments) — 프로젝트별 기여도 합 100% ──
INSERT INTO `project_assignments`
(`id`,`project_id`,`user_id`,`role`,`start_date`,`end_date`,`contribution_pct`,`status`,`created_at`) VALUES
(1,1,2,'현장책임자','2026-07-10','2026-09-30',40.00,'active',NOW()),
(2,1,3,'도장작업자','2026-07-10','2026-09-30',35.00,'active',NOW()),
(3,1,4,'보조','2026-07-10','2026-09-30',25.00,'active',NOW()),
(4,2,2,'현장책임자','2026-06-05','2026-07-15',60.00,'ended',NOW()),
(5,2,3,'도장작업자','2026-06-05','2026-07-15',40.00,'ended',NOW()),
(6,3,3,'도장작업자','2026-06-15','2026-06-30',100.00,'ended',NOW()),
(7,4,2,'현장책임자','2026-07-25','2026-08-20',100.00,'active',NOW()),
(8,5,2,'현장책임자','2026-08-01','2026-09-01',100.00,'active',NOW());

-- ── 영업 파이프라인 리드 8건(sales_user 이상훈=5, customer_id 1~6 순환, 최근 30일 이내 생성) ──
INSERT INTO `leads`
(`id`,`customer_id`,`sales_user_id`,`stage_id`,`work_type`,`site_address`,`expected_amount`,`expected_cost`,`win_probability`,`expected_profit`,`importance`,`next_contact_date`,`last_activity_date`,`stage_entered_at`,`memo`,`created_at`) VALUES
(1,1,5,1,'아파트외벽','서울 강남구 대명빌딩',25000000,18000000,20.00,7000000,'mid','2026-07-24','2026-07-21','2026-07-21 09:00:00','신규 문의 접수, 상담 일정 조율 중','2026-07-21 09:00:00'),
(2,2,5,2,'옥상방수','경기 성남시 분당구 이수아 주택',30000000,21000000,30.00,9000000,'mid','2026-07-23','2026-07-19','2026-07-19 10:00:00','상담 예약 확정','2026-07-18 14:00:00'),
(3,3,5,3,'아파트내부','인천 연수구 한빛아파트',18000000,12600000,40.00,5400000,'low','2026-07-25','2026-07-16','2026-07-16 11:00:00','현장 실측 진행','2026-07-14 09:30:00'),
(4,4,5,5,'상가리모델링','서울 서초구 서초상가',42000000,30000000,60.00,12000000,'high','2026-07-28','2026-07-12','2026-07-12 14:00:00','견적서 발송 완료, 회신 대기','2026-07-08 11:00:00'),
(5,5,5,6,'공장에폭시','인천 남동구 세림공장',55000000,38000000,70.00,17000000,'high','2026-07-24','2026-07-09','2026-07-09 15:00:00','금액 협상 중','2026-07-02 10:00:00'),
(6,6,5,7,'오피스텔외벽','경기 성남시 분당구 판교오피스텔',33000000,23000000,85.00,10000000,'high','2026-07-23','2026-07-15','2026-07-15 09:00:00','계약서 검토 중','2026-06-28 09:00:00'),
(7,1,5,11,'지하주차장방수','서울 강남구 대명빌딩',20000000,14000000,0.00,6000000,'mid',NULL,'2026-07-01','2026-07-01 09:00:00','예산 초과로 실주','2026-06-25 10:00:00'),
(8,2,5,8,'옥상방수','경기 성남시 분당구 이수아 주택',22000000,15000000,100.00,7000000,'mid',NULL,'2026-06-30','2026-06-30 09:00:00','계약 체결(C2026-0002)','2026-06-22 09:00:00');

-- ── 오늘(2026-07-22) 일정 — 직원 업무 현황용 ──
INSERT INTO `schedules`
(`id`,`project_id`,`user_id`,`title`,`event_date`,`slot`,`start_datetime`,`end_datetime`,`type`,`status`,`created_at`) VALUES
(1,1,3,'대명건설 외벽 2차 도장 작업','2026-07-22','pm','2026-07-22 13:00:00','2026-07-22 18:00:00','work','scheduled',NOW()),
(2,4,2,'서초상가 리모델링 현장 실측','2026-07-22','am','2026-07-22 09:00:00','2026-07-22 12:00:00','work','scheduled',NOW());
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

-- ── 개인 목표(targets) — 이상훈(5) 2026-07 ──
INSERT INTO `targets` (`user_id`,`year`,`month`,`target_revenue`,`target_profit`,`target_contracts`,`target_projects`) VALUES
(5,2026,7,50000000,10000000,3,3);

-- ── 회사 목표(2026) — 대시보드 매출 목표 달성률 ──
INSERT INTO `company_targets` (`period_type`,`year`,`period_no`,`target_revenue`,`target_profit`) VALUES
('month',2026,7,50000000,15000000),('month',2026,8,60000000,18000000),('month',2026,9,55000000,16000000),
('quarter',2026,3,165000000,49000000),('year',2026,0,600000000,180000000);

-- 공급가액/부가세 백필 (모든 계약·프로젝트에 이미 명시값이 있어 no-op이지만 방어적으로 유지)
UPDATE contracts c JOIN quotes q ON q.id=c.quote_id JOIN quote_versions qv ON qv.id=q.current_version_id
  SET c.vat_amount=qv.vat, c.supply_amount=c.contract_amount-qv.vat WHERE c.quote_id IS NOT NULL;
UPDATE contracts c SET c.vat_amount=c.contract_amount-ROUND(c.contract_amount/1.1), c.supply_amount=ROUND(c.contract_amount/1.1) WHERE c.vat_amount IS NULL;
UPDATE projects p JOIN contracts c ON c.id=p.contract_id SET p.vat_amount=c.vat_amount, p.supply_amount=p.contract_amount-c.vat_amount WHERE p.contract_id IS NOT NULL;
UPDATE projects p SET p.vat_amount=p.contract_amount-ROUND(p.contract_amount/1.1), p.supply_amount=ROUND(p.contract_amount/1.1) WHERE p.vat_amount IS NULL;

SET FOREIGN_KEY_CHECKS = 1;
