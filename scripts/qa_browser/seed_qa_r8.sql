-- R8 QA 더미데이터 (로컬 dev 전용 — 운영 반입 금지)
-- 조건: 직원 6명, 도장 5 + 인테리어 5 + 미지정 1 프로젝트, 상·하반기 계약/입금,
--       흑자/적자, 지급/부분/미지급/취소 보너스, 담당자 변경 이력, 완료/진행/예정/취소.
SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- 직원 2명 추가(총 6명) — 비번 password123!
INSERT INTO `users` (`id`,`login_id`,`email`,`password_hash`,`name`,`phone`,`department_id`,`position`,`color`,`role_id`,`role_key`,`hire_date`,`status`,`must_change_password`,`created_at`) VALUES
(5,'qa_a','qa_a@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','QA직원A','010-9000-0005',1,'현장소장','#7c3aed',3,'site_manager','2024-01-02','active',0,NOW()),
(6,'qa_b','qa_b@edenpaint.co.kr','$2y$12$ZofWFaeS/pvHMtM285rBbe.wlCMuI1.nZZk3.UKk2gFtX1NtDZl.O','QA직원B','010-9000-0006',1,'도장기능공','#0891b2',4,'staff','2024-06-01','active',0,NOW());

-- 고객
INSERT INTO `customers` (`id`,`type`,`name`,`phone`,`status`,`privacy_agreed`,`created_at`) VALUES
(200,'company','QA더미_발주처A','010-5000-0001','active',1,NOW()),
(201,'company','QA더미_발주처B','010-5000-0002','active',1,NOW());

-- 계약 (공급가/부가세/총액)
INSERT INTO `contracts` (`id`,`contract_no`,`customer_id`,`contract_date`,`contract_amount`,`supply_amount`,`vat_amount`,`status`,`sales_user_id`,`work_name`,`construction_type`,`created_at`) VALUES
(301,'QA-C1',200,'2026-01-15',33000000,30000000,3000000,'completed',1,'QA P1 외벽도장','painting',NOW()),
(302,'QA-C2',200,'2026-04-01',22000000,20000000,2000000,'completed',1,'QA P2 옥상방수(적자)','painting',NOW()),
(303,'QA-C3',201,'2026-07-05',44000000,40000000,4000000,'active',1,'QA P3 공장도장','painting',NOW()),
(304,'QA-C4',201,'2026-07-15',11000000,10000000,1000000,'active',1,'QA P4 상가도장(담당변경)','painting',NOW()),
(305,'QA-C5',200,'2026-02-01', 8800000, 8000000, 800000,'cancelled',1,'QA P5 취소건','painting',NOW()),
(306,'QA-C6',201,'2026-06-20',16500000,15000000,1500000,'active',1,'QA I1 사무실 인테리어','interior',NOW()),
(307,'QA-C7',200,'2026-07-10',13200000,12000000,1200000,'active',1,'QA I2 카페 인테리어','interior',NOW()),
(308,'QA-C8',200,'2026-03-05', 9900000, 9000000, 900000,'completed',1,'QA I4 상반기 인테리어','interior',NOW());

-- 입금 (paid) / 예정(pending)
INSERT INTO `payments` (`contract_id`,`pay_type`,`kind`,`amount`,`paid_date`,`status`) VALUES
(301,'down','payment',16500000,'2026-02-15','paid'),
(301,'balance','payment',16500000,'2026-03-20','paid'),
(302,'balance','payment',22000000,'2026-05-30','paid'),
(303,'down','payment',10000000,'2026-07-10','paid'),
(303,'balance','payment',34000000,NULL,'pending'),
(304,'down','payment', 5000000,'2026-07-20','paid'),
(306,'balance','payment',16500000,'2026-07-15','paid'),
(307,'down','payment', 3000000,'2026-07-18','paid'),
(308,'balance','payment', 9900000,'2026-04-10','paid');

-- 프로젝트: 도장 5(P1 완료흑자 H1 / P2 완료적자 H1 / P3 진행 H2 / P4 진행·담당변경 / P5 취소)
--          인테리어 5(I1 완료흑자 H2 / I2 진행 / I3 예정 / I4 완료 H1 / I5 진행) + 미지정 U1
INSERT INTO `projects` (`id`,`project_no`,`name`,`customer_id`,`contract_id`,`work_type`,`construction_type`,`contract_amount`,`supply_amount`,`vat_amount`,`estimated_cost`,`actual_cost`,`process_stage_id`,`process_entered_at`,`status`,`contract_date`,`actual_start_date`,`actual_end_date`,`sales_user_id`,`site_manager_id`,`progress`,`created_at`) VALUES
(401,'QA-P1','QA P1 외벽도장(완료·흑자)',200,301,'아파트외벽 도장','painting',33000000,30000000,3000000,22000000,20000000,19,NOW(),'completed','2026-01-15','2026-02-01','2026-03-31',1,5,100,NOW()),
(402,'QA-P2','QA P2 옥상방수(완료·적자)',200,302,'옥상방수','painting',22000000,20000000,2000000,18000000,25000000,19,NOW(),'completed','2026-04-01','2026-04-10','2026-05-31',1,5,100,NOW()),
(403,'QA-P3','QA P3 공장도장(진행)',201,303,'공장도장','painting',44000000,40000000,4000000,30000000,5000000,12,NOW(),'in_progress','2026-07-05','2026-07-08',NULL,1,5,63,NOW()),
(404,'QA-P4','QA P4 상가도장(담당변경)',201,304,'상가 도장','painting',11000000,10000000,1000000,8000000,2000000,9,NOW(),'in_progress','2026-07-15','2026-07-16',NULL,1,6,47,NOW()),
(405,'QA-P5','QA P5 취소건',200,305,'외벽도장','painting',8800000,8000000,800000,6000000,0,20,NOW(),'cancelled','2026-02-01',NULL,NULL,1,NULL,0,NOW()),
(406,'QA-I1','QA I1 사무실 인테리어(완료·흑자)',201,306,'사무실 인테리어','interior',16500000,15000000,1500000,10000000,9000000,19,NOW(),'completed','2026-06-20','2026-06-25','2026-07-20',1,5,100,NOW()),
(407,'QA-I2','QA I2 카페 인테리어(진행)',200,307,'카페 인테리어','interior',13200000,12000000,1200000,9000000,3000000,29,NOW(),'in_progress','2026-07-10','2026-07-12',NULL,1,5,53,NOW()),
(408,'QA-I3','QA I3 인테리어(예정)',201,NULL,'주택 인테리어','interior',0,5000000,500000,4000000,0,20,NOW(),'preparing',NULL,NULL,NULL,1,NULL,0,NOW()),
(409,'QA-I4','QA I4 상반기 인테리어(완료)',200,308,'점포 인테리어','interior',9900000,9000000,900000,7500000,7000000,19,NOW(),'completed','2026-03-05','2026-03-10','2026-04-15',1,6,100,NOW()),
(410,'QA-I5','QA I5 철거 진행',201,NULL,'내부 철거·인테리어','interior',0,6000000,600000,5000000,1000000,25,NOW(),'in_progress',NULL,'2026-07-22',NULL,1,6,29,NOW()),
(411,'QA-U1','QA U1 유형 미지정',200,NULL,'기타 보수',NULL,0,3000000,300000,2000000,0,20,NOW(),'preparing',NULL,NULL,NULL,1,NULL,0,NOW());

-- 배정(기여율) — P4 는 담당자 변경 이력(maeng ended → qa_b active)
INSERT INTO `project_assignments` (`project_id`,`user_id`,`role`,`start_date`,`end_date`,`contribution_pct`,`status`) VALUES
(401,5,'현장책임자','2026-02-01','2026-03-31',60.00,'active'),
(401,6,'도장작업자','2026-02-01','2026-03-31',40.00,'active'),
(402,5,'현장책임자','2026-04-10','2026-05-31',100.00,'active'),
(403,5,'현장책임자','2026-07-08',NULL,100.00,'active'),
(404,3,'현장책임자','2026-07-15','2026-07-16',50.00,'ended'),
(404,6,'현장책임자','2026-07-16',NULL,50.00,'active'),
(406,6,'현장책임자','2026-06-25','2026-07-20',100.00,'active'),
(407,5,'현장책임자','2026-07-12',NULL,50.00,'active'),
(407,6,'도장작업자','2026-07-12',NULL,50.00,'active'),
(409,6,'현장책임자','2026-03-10','2026-04-15',100.00,'active'),
(410,6,'현장책임자','2026-07-22',NULL,100.00,'active');

-- 비용(확정 지출 — costTotal 검증용, projects.actual_cost 와 일치)
INSERT INTO `costs` (`project_id`,`type`,`cost_status`,`category`,`item_name`,`amount`,`spent_date`,`created_by`) VALUES
(401,'actual','confirmed','material','QA 페인트 자재',12000000,'2026-02-20',1),
(401,'actual','confirmed','labor','QA 인건비',8000000,'2026-03-15',1),
(402,'actual','confirmed','outsourcing','QA 방수 외주',25000000,'2026-05-20',1),
(403,'actual','confirmed','material','QA 자재 선투입',5000000,'2026-07-15',1),
(404,'actual','confirmed','material','QA 자재',2000000,'2026-07-18',1),
(406,'actual','confirmed','outsourcing','QA 인테리어 시공',9000000,'2026-07-10',1),
(407,'actual','confirmed','material','QA 목자재',3000000,'2026-07-16',1),
(409,'actual','confirmed','material','QA 마감재',7000000,'2026-04-05',1),
(410,'actual','confirmed','labor','QA 철거 인건',1000000,'2026-07-23',1);

-- 공정 이력(각 프로젝트 최소 1건 — 이력 총수 검증 기준)
INSERT INTO `project_process_history` (`project_id`,`from_stage_id`,`to_stage_id`,`changed_by`,`reason`,`is_auto`) VALUES
(401,NULL,20,1,'QA 시드 초기 배치',1),(401,20,19,1,'QA 완료',0),
(402,NULL,20,1,'QA 시드 초기 배치',1),(402,20,19,1,'QA 완료',0),
(403,NULL,20,1,'QA 시드 초기 배치',1),(403,20,12,1,'QA 진행',0),
(404,NULL,20,1,'QA 시드 초기 배치',1),(404,20,9,1,'QA 진행',0),
(405,NULL,20,1,'QA 시드 초기 배치',1),
(406,NULL,20,1,'QA 시드 초기 배치',1),(406,20,19,1,'QA 완료',0),
(407,NULL,20,1,'QA 시드 초기 배치',1),(407,20,29,1,'QA 진행',0),
(408,NULL,20,1,'QA 시드 초기 배치',1),
(409,NULL,20,1,'QA 시드 초기 배치',1),(409,20,19,1,'QA 완료',0),
(410,NULL,20,1,'QA 시드 초기 배치',1),(410,20,25,1,'QA 진행',0),
(411,NULL,20,1,'QA 시드 초기 배치',1);

-- 현장 보너스: 지급완료 / 부분지급 / 미지급 / 취소
INSERT INTO `site_bonuses` (`id`,`user_id`,`project_id`,`year`,`half`,`base_amount`,`calc_basis`,`calc_amount`,`paid_amount`,`pay_date`,`pay_status`,`paid_by`,`memo`,`contribution_pct_at_calc`,`created_by`) VALUES
(501,5,401,2026,1,10000000,'현장 순이익의 10%',1000000,1000000,'2026-07-10','paid',1,'QA 지급완료',60.00,1),
(502,6,401,2026,1,10000000,'현장 순이익의 4%',400000,200000,'2026-07-12','partial',1,'QA 부분지급',40.00,1),
(503,5,403,2026,2,40000000,'수주 공급가의 1.25%',500000,0,NULL,'unpaid',NULL,'QA 미지급',100.00,1),
(504,3,404,2026,2,10000000,'수주 공급가의 3%',300000,0,NULL,'cancelled',NULL,'QA 지급취소 사례',50.00,1);

INSERT INTO `site_bonus_history` (`bonus_id`,`action`,`before_json`,`after_json`,`reason`,`changed_by`) VALUES
(501,'create',NULL,'{"calc_amount":1000000,"pay_status":"unpaid"}','QA 시드',1),
(501,'pay','{"paid_amount":0,"pay_status":"unpaid"}','{"paid_amount":1000000,"pay_status":"paid"}','QA 시드 지급',1),
(502,'create',NULL,'{"calc_amount":400000}','QA 시드',1),
(503,'create',NULL,'{"calc_amount":500000}','QA 시드',1),
(504,'create',NULL,'{"calc_amount":300000}','QA 시드',1),
(504,'cancel','{"pay_status":"unpaid"}','{"pay_status":"cancelled"}','QA 지급 취소 사례',1);

SET FOREIGN_KEY_CHECKS = 1;
