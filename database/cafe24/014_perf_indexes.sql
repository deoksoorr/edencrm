-- ============================================================================
-- T8 성능 감사 (2026-07-29) — 실측 근거가 있는 인덱스만 추가한다.
-- 운영(카페24 / MariaDB 10.6.17, 공유 DB · prefix edencrm_) 용.
-- 로컬용 동일 내용은 database/migrations/2026-07-29_perf_indexes.sql.
-- 적용: php deploy/run_migration.php database/cafe24/014_perf_indexes.sql [--dry]
--
-- ── 근거 (scripts/audit/perf_index_bench.php, 성장 규모 S2 = payments 11.7k / notifications 20k) ──
--   payments(status, paid_date)     6.22ms → 0.28ms  (22.0배, 인덱스 240KB)
--   notifications(user_id, is_read) 1.94ms → 0.32ms  ( 6.1배, 인덱스 400KB)
-- 현재 운영 규모(payments 13행 / notifications 32행)에서는 두 인덱스 모두 체감 이득이 없다.
-- 그럼에도 지금 넣는 이유: 두 테이블은 업무량에 비례해 무한 증가하고(입금 원장·알림 누적),
-- 나중에 넣으려면 그때는 잠금 시간이 훨씬 길어진다. 지금은 테이블이 작아 즉시 끝난다.
--
-- ── 넣지 않은 후보(실측상 이득 없음 — docs/audit/PERFORMANCE.md '불필요' 참고) ──
--   costs(cost_status, type, spent_date)  1.15ms → 1.03ms (1.1배)  ← 기존 idx_costs_spent_date 로 충분
--   audit_logs(entity, created_at)        0.11ms → 0.11ms (1.0배)  ← 인덱스만 5.6MB
--   schedules(project_id, event_date)     0.68ms → 0.72ms (개선 없음)
--   project_stage_progress(project_id,pct)0.94ms → 1.06ms (오히려 느림 — PRIMARY 가 이미 클러스터)
--   projects(deleted_at, id)              0.08ms → 0.08ms (개선 없음)
--
-- ── 멱등성 ──
-- MariaDB 는 `ADD KEY IF NOT EXISTS` 를 지원하지만 MySQL 은 지원하지 않는다.
-- 두 엔진 모두에서 동작하도록 information_schema 판정 + PREPARE/EXECUTE 를 쓴다.
-- 동작 검증: MySQL 9.6 로컬에서 3회 연속 실행 무해(인덱스 상태 동일, FK 3개 보존, 18개 라우트 HTTP 200),
--            MariaDB 10.6.17 운영에서 판정부·분기부·PREPARE/EXECUTE·DO 0 전부 확인
--            (scripts/audit/perf_mariadb_guard_check.php — READ ONLY 세션, 운영 스키마 무변경).
-- 각 문장은 세미콜론 단위로 분리 실행된다(deploy/run_migration.php · scripts/apply_local_migration.php
-- 의 스플리터 규칙) — 그래서 저장 프로시저(BEGIN...END)가 아니라 PREPARE 방식을 쓴다.
-- ============================================================================

-- ── 1) payments(status, paid_date) ──────────────────────────────────────────
-- 대상: AccountingService::paidTotal / confirmedRevenue / receivable 등
--       `WHERE pm.status='paid' AND pm.paid_date BETWEEN ? AND ?` 전 구간.
-- 이 형태는 대시보드·리포트·반기 화면에서 요청당 6~14회 반복 실행된다(perf_probe 실측).
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edencrm_payments'
      AND INDEX_NAME = 'idx_payments_status_paid_date') > 0,
  'DO 0',
  'ALTER TABLE `edencrm_payments` ADD KEY `idx_payments_status_paid_date` (`status`, `paid_date`)');
PREPARE stmt_perf_1 FROM @sql;
EXECUTE stmt_perf_1;
DEALLOCATE PREPARE stmt_perf_1;

-- ── 2) notifications(user_id, is_read) ──────────────────────────────────────
-- 대상: `SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0`
--       app/views/layout/default.php:9 — **모든 페이지 렌더마다 1회** 실행된다.
-- 기존에는 idx_notifications_user + idx_notifications_is_read 의 index_merge 로 처리되어
-- 두 인덱스를 모두 읽고 교집합을 계산했다. 복합 인덱스 하나면 ref 단일 조회로 끝난다.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edencrm_notifications'
      AND INDEX_NAME = 'idx_notifications_user_read') > 0,
  'DO 0',
  'ALTER TABLE `edencrm_notifications` ADD KEY `idx_notifications_user_read` (`user_id`, `is_read`)');
PREPARE stmt_perf_2 FROM @sql;
EXECUTE stmt_perf_2;
DEALLOCATE PREPARE stmt_perf_2;

-- ── 3) 대체된 단일 인덱스 제거: notifications(is_read) ──────────────────────
-- is_read 는 값이 0/1 뿐이라 단독으로는 선택도가 거의 없다. 앱 전체를 확인한 결과
-- is_read 를 조건에 쓰는 쿼리(Notif.php:48, NotificationsController.php:22/33/53/74,
-- DashboardController.php:465, layout/default.php:9)는 **예외 없이 user_id 와 함께** 쓴다.
-- 따라서 2) 의 복합 인덱스가 이 인덱스의 유일한 용처를 완전히 대체한다.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edencrm_notifications'
      AND INDEX_NAME = 'idx_notifications_is_read') > 0
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edencrm_notifications'
      AND INDEX_NAME = 'idx_notifications_user_read') > 0,
  'ALTER TABLE `edencrm_notifications` DROP KEY `idx_notifications_is_read`',
  'DO 0');
PREPARE stmt_perf_3 FROM @sql;
EXECUTE stmt_perf_3;
DEALLOCATE PREPARE stmt_perf_3;

-- ── 4) 새로 만든 복합 인덱스에 흡수된 선행 접두 인덱스 제거: payments(status) ──
-- 1) 에서 만든 (status, paid_date) 의 leftmost prefix 가 정확히 (status) 다.
-- 옵티마이저는 (status) 단독 조건도 복합 인덱스로 처리하므로 남겨 둘 이유가 없고,
-- 남기면 입금 INSERT/UPDATE 마다 쓸데없는 인덱스 갱신 비용만 발생한다.
-- payments.status 에는 FK 가 없다(FK 는 contract_id·project_id) — 제약 위반 없음(실측 확인).
-- 가드: 대체 인덱스가 실제로 존재할 때만 삭제한다(1) 이 실패했는데 삭제만 되는 상황 방지).
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edencrm_payments'
      AND INDEX_NAME = 'idx_payments_status') > 0
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edencrm_payments'
      AND INDEX_NAME = 'idx_payments_status_paid_date') > 0,
  'ALTER TABLE `edencrm_payments` DROP KEY `idx_payments_status`',
  'DO 0');
PREPARE stmt_perf_4 FROM @sql;
EXECUTE stmt_perf_4;
DEALLOCATE PREPARE stmt_perf_4;

-- ── 5) 같은 이유로 notifications(user_id) 제거 ──────────────────────────────
-- 2) 에서 만든 (user_id, is_read) 의 leftmost prefix 가 (user_id) 다.
-- notifications.user_id 에는 FK(fk_notifications_user)가 있지만, FK 는 "선행 컬럼이
-- 해당 컬럼인 인덱스"면 충족되므로 복합 인덱스가 그대로 FK 를 지원한다(실측 확인).
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edencrm_notifications'
      AND INDEX_NAME = 'idx_notifications_user') > 0
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'edencrm_notifications'
      AND INDEX_NAME = 'idx_notifications_user_read') > 0,
  'ALTER TABLE `edencrm_notifications` DROP KEY `idx_notifications_user`',
  'DO 0');
PREPARE stmt_perf_5 FROM @sql;
EXECUTE stmt_perf_5;
DEALLOCATE PREPARE stmt_perf_5;

-- ── 통계 갱신에 대하여 ──────────────────────────────────────────────────────
-- 여기에 `ANALYZE TABLE` 을 넣지 않는다. ANALYZE 는 결과 집합을 돌려주는데
-- 마이그레이션 러너(scripts/apply_local_migration.php · deploy/run_migration.php)는
-- 문장을 exec/run 으로만 실행하고 결과를 소비하지 않아 다음 문장에서
-- "Cannot execute queries while other unbuffered queries are active" 로 중단된다(실측 확인).
-- InnoDB 는 DDL 직후 인덱스 통계를 자동 갱신하므로 별도 ANALYZE 없이도 새 인덱스가 즉시 선택된다.
-- 수동 갱신이 필요하면 배포 후 별도로 실행할 것:
--   ANALYZE TABLE `edencrm_payments`; ANALYZE TABLE `edencrm_notifications`;
