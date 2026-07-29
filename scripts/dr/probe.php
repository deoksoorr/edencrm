<?php
/**
 * DR 테스트 — 운영/복구본 공통 측정 프로브.
 *
 * 비교의 신뢰도는 "양쪽을 같은 자로 쟀는가"에 달려 있다. 운영용 쿼리와 복구본용
 * 쿼리를 따로 쓰면 차이가 났을 때 그게 복구 결함인지 쿼리 차이인지 구분이 안 된다.
 * 그래서 측정 정의를 여기 한 곳에만 두고, 운영(읽기전용)과 복구본에 같은 SQL 을 던진다.
 * → 결과가 다르면 원인은 데이터뿐이다.
 *
 * 테이블 prefix 는 인자로 받는다. 운영·복구본 모두 edencrm_ 이지만 하드코딩하지 않는다.
 */

/**
 * 회계 규칙 상수 — docs/ACCOUNTING_RULES.md §2 를 그대로 옮긴다.
 *
 * 여기를 대충 잡으면 DR 판정이 통째로 무의미해진다. 실제로 첫 측정에서 "대기(pending)
 * 입금도 실입금"으로 잡는 바람에 미수금이 음수(-84,750,000)로 나왔다. 규칙 원문:
 *   순입금 = Σ 입금완료(paid, kind=payment) − Σ 환불(kind=refund)  … 대기·취소 제외
 *   미수금 = Σ 계약별 max(0, 계약총액 − 순입금)                      … 계약별 절사 후 합산
 *   확정매출 = 완납(순입금 ≥ 계약총액 > 0) 유효계약의 공급가액 합
 * 전체합끼리 빼는 방식은 초과입금 계약이 다른 계약의 미수를 상쇄해 미수금을 과소계상한다.
 */
const DR_PAYMENT_PAID   = "status = 'paid'";              // 순입금 모집단 — 대기·취소 제외
const DR_COST_CONFIRMED = "cost_status = 'confirmed'";    // 확정 지출만 원가 인식
const DR_CONTRACT_VALID = "deleted_at IS NULL AND status NOT IN ('cancelled','terminated')";

/** 계약별 순입금 서브쿼리 — 계약 귀속 입금만(프로젝트 직접 입금 제외). */
function dr_net_paid_sql(string $P): string
{
    return "SELECT contract_id, SUM(CASE WHEN kind='refund' THEN -amount ELSE amount END) AS net
              FROM `{$P}payments`
             WHERE " . DR_PAYMENT_PAID . " AND contract_id IS NOT NULL
             GROUP BY contract_id";
}

/**
 * 모든 측정을 수행해 구조화된 배열로 반환한다.
 *
 * @param callable $q  fn(string $sql, array $params = []): array — 조회 실행기
 *                     (운영은 RO 가드를 통과하는 실행기, 복구본은 일반 실행기)
 * @param string   $db     대상 스키마명
 * @param string   $prefix 테이블 prefix (예: 'edencrm_')
 */
function dr_probe(callable $q, string $db, string $prefix): array
{
    $P = $prefix;
    $out = [];

    // ── 1. 서버·스키마 환경 ────────────────────────────────────────────────
    $env = $q("SELECT VERSION() AS version, @@version_comment AS comment,
                      @@character_set_database AS cs, @@collation_database AS coll,
                      @@sql_mode AS sql_mode, @@time_zone AS tz, NOW() AS now_at")[0];
    $out['env'] = $env;

    // ── 2. 테이블 인벤토리 ────────────────────────────────────────────────
    // 이 스키마는 다른 프로젝트와 공유된다. prefix 로만 eden 소유를 구분하므로
    // "prefix 밖에 eden 테이블이 있는가"가 곧 백업 커버리지 갭 여부다.
    $allObjects = $q(
        "SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, AUTO_INCREMENT, TABLE_ROWS
           FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME", [$db]
    );
    $owned = $others = [];
    foreach ($allObjects as $t) {
        if (str_starts_with($t['TABLE_NAME'], $P)) $owned[] = $t;
        else $others[] = $t['TABLE_NAME'];
    }
    // 커버리지 갭 판정: eden 테이블명(prefix 제거)이 prefix 없이도 존재한다면
    // 그 테이블은 백업 대상 밖이다 — 즉 영원히 백업되지 않는 데이터가 있다는 뜻.
    $ownedShort = array_map(fn($t) => substr($t['TABLE_NAME'], strlen($P)), $owned);
    $unprefixed = array_values(array_intersect($ownedShort, $others));

    $out['inventory'] = [
        // 스키마명은 기록하지 않는다. 카페24는 DB명 = DB계정명 = FTP계정명이 같아서
        // 이 값을 산출물에 남기면 자격증명의 절반(아이디)을 노출하는 것과 같다.
        // 대조에도 쓰이지 않으므로(양쪽이 서로 다른 스키마인 게 정상) 마스킹한다.
        'schema_masked'       => substr($db, 0, 2) . str_repeat('*', max(0, strlen($db) - 2)),
        'prefix'              => $P,
        'objects_total'       => count($allObjects),
        'owned_total'         => count($owned),
        'owned_base_tables'   => count(array_filter($owned, fn($t) => $t['TABLE_TYPE'] === 'BASE TABLE')),
        'owned_views'         => count(array_filter($owned, fn($t) => $t['TABLE_TYPE'] === 'VIEW')),
        'foreign_object_count' => count($others),   // 타 프로젝트 테이블 — 이름은 기록하지 않는다
        'unprefixed_twins'    => $unprefixed,       // 비어 있어야 정상
        'owned'               => $owned,
    ];

    // ── 3. 뷰·트리거·프로시저 ─────────────────────────────────────────────
    // db_dump.php 는 SHOW TABLES(뷰 포함) 를 SHOW CREATE TABLE 로 뽑는다.
    // 즉 prefix 안에 뷰가 하나라도 있으면 덤프가 깨진다. 0 이어야 안전하다.
    $out['views']    = $q("SELECT TABLE_NAME FROM information_schema.VIEWS
                            WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?", [$db, $P . '%']);
    $out['triggers'] = $q("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
                             FROM information_schema.TRIGGERS
                            WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE LIKE ?", [$db, $P . '%']);
    $out['routines'] = $q("SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES
                            WHERE ROUTINE_SCHEMA = ?", [$db]);

    // ── 4. 제약·인덱스 ────────────────────────────────────────────────────
    $out['foreign_keys'] = $q(
        "SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ? AND REFERENCED_TABLE_NAME IS NOT NULL
          ORDER BY TABLE_NAME, CONSTRAINT_NAME", [$db, $P . '%']
    );
    $out['indexes'] = $q(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?
          GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
          ORDER BY TABLE_NAME, INDEX_NAME", [$db, $P . '%']
    );

    // ── 5. 컬럼 정의 ──────────────────────────────────────────────────────
    // 회계 관점: DECIMAL 정밀도가 한 자리라도 다르면 금액이 조용히 반올림된다.
    // 건수가 같아도 정밀도가 다르면 복구 실패로 본다.
    $out['columns'] = $q(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?
          ORDER BY TABLE_NAME, ORDINAL_POSITION", [$db, $P . '%']
    );
    $out['decimal_columns'] = array_values(array_filter(
        $out['columns'], fn($c) => str_starts_with(strtolower($c['COLUMN_TYPE']), 'decimal')
    ));

    // ── 6. 테이블별 실제 건수 ─────────────────────────────────────────────
    // information_schema.TABLE_ROWS 는 InnoDB 추정치라 비교에 쓸 수 없다. COUNT(*) 로 센다.
    $counts = [];
    foreach ($owned as $t) {
        if ($t['TABLE_TYPE'] !== 'BASE TABLE') continue;
        $name  = $t['TABLE_NAME'];
        $short = substr($name, strlen($P));
        $counts[$short] = (int) $q("SELECT COUNT(*) AS c FROM `$name`")[0]['c'];
    }
    ksort($counts);
    $out['counts'] = $counts;

    // ── 7. 소프트 삭제(휴지통) 분포 ───────────────────────────────────────
    $softTables = [];
    foreach ($out['columns'] as $c) {
        if ($c['COLUMN_NAME'] === 'deleted_at') $softTables[] = $c['TABLE_NAME'];
    }
    $soft = [];
    foreach ($softTables as $name) {
        $short = substr($name, strlen($P));
        $r = $q("SELECT SUM(deleted_at IS NULL) AS alive, SUM(deleted_at IS NOT NULL) AS trashed
                   FROM `$name`")[0];
        $soft[$short] = ['alive' => (int) $r['alive'], 'trashed' => (int) $r['trashed']];
    }
    ksort($soft);
    $out['soft_delete'] = $soft;

    // ── 8. 계정·권한 ──────────────────────────────────────────────────────
    $out['accounts'] = [
        'by_role'   => $q("SELECT role_key, status, COUNT(*) AS c FROM `{$P}users`
                            WHERE deleted_at IS NULL GROUP BY role_key, status ORDER BY role_key, status"),
        'deleted'   => (int) $q("SELECT COUNT(*) AS c FROM `{$P}users` WHERE deleted_at IS NOT NULL")[0]['c'],
        // 복구 직후 잠긴 계정으로 되살아나면 로그인 자체가 막힌다 — 실제 사고 시나리오다.
        'locked'    => (int) $q("SELECT COUNT(*) AS c FROM `{$P}users` WHERE locked_until IS NOT NULL")[0]['c'],
        'failed_gt0'=> (int) $q("SELECT COUNT(*) AS c FROM `{$P}users` WHERE failed_attempts > 0")[0]['c'],
        // 비밀번호 해시가 덤프 왕복에서 깨졌는지 — 알고리즘 접두어 분포만 본다(해시값 미출력).
        'hash_algo' => $q("SELECT LEFT(password_hash, 4) AS algo, COUNT(*) AS c,
                                  MIN(LENGTH(password_hash)) AS min_len, MAX(LENGTH(password_hash)) AS max_len
                             FROM `{$P}users` GROUP BY LEFT(password_hash, 4)"),
        'perm_rows' => [
            'user_permissions'     => (int) $q("SELECT COUNT(*) AS c FROM `{$P}user_permissions`")[0]['c'],
            'role_permissions'     => (int) $q("SELECT COUNT(*) AS c FROM `{$P}role_permissions`")[0]['c'],
            'employee_permissions' => (int) $q("SELECT COUNT(*) AS c FROM `{$P}employee_permissions`")[0]['c'],
        ],
    ];

    // ── 9. 회계 집계 ──────────────────────────────────────────────────────
    // 금액은 문자열로 뽑는다. float 로 비교하면 정밀도 손실이 "같다"로 둔갑한다.
    $out['accounting'] = [
        'contracts' => $q(
            "SELECT COUNT(*) AS cnt,
                    CAST(COALESCE(SUM(contract_amount),0) AS CHAR) AS contract_amount,
                    CAST(COALESCE(SUM(supply_amount),0)   AS CHAR) AS supply_amount,
                    CAST(COALESCE(SUM(vat_amount),0)      AS CHAR) AS vat_amount,
                    CAST(COALESCE(SUM(down_payment),0)    AS CHAR) AS down_payment,
                    CAST(COALESCE(SUM(middle_payment),0)  AS CHAR) AS middle_payment,
                    CAST(COALESCE(SUM(balance_payment),0) AS CHAR) AS balance_payment
               FROM `{$P}contracts` WHERE deleted_at IS NULL")[0],
        'contracts_by_status' => $q(
            "SELECT status, payment_status, COUNT(*) AS c,
                    CAST(COALESCE(SUM(contract_amount),0) AS CHAR) AS amt
               FROM `{$P}contracts` WHERE deleted_at IS NULL
              GROUP BY status, payment_status ORDER BY status, payment_status"),
        'payments' => $q(
            "SELECT kind, status, COUNT(*) AS c, CAST(COALESCE(SUM(amount),0) AS CHAR) AS amt
               FROM `{$P}payments` GROUP BY kind, status ORDER BY kind, status"),
        // 순입금 = 입금완료(paid) − 환불. 대기·취소 제외. 대시보드 현금축의 기준값.
        'payments_net' => $q(
            "SELECT CAST(COALESCE(SUM(CASE WHEN kind='refund' THEN -amount ELSE amount END),0) AS CHAR) AS net
               FROM `{$P}payments` WHERE " . DR_PAYMENT_PAID)[0],
        'costs' => $q(
            "SELECT cost_status, type, COUNT(*) AS c, CAST(COALESCE(SUM(amount),0) AS CHAR) AS amt
               FROM `{$P}costs` GROUP BY cost_status, type ORDER BY cost_status, type"),
        'costs_confirmed' => $q(
            "SELECT CAST(COALESCE(SUM(amount),0) AS CHAR) AS amt FROM `{$P}costs`
              WHERE " . DR_COST_CONFIRMED)[0],
        'quote_items' => $q(
            "SELECT COUNT(*) AS cnt, CAST(COALESCE(SUM(amount),0) AS CHAR) AS amt FROM `{$P}quote_items`")[0],
        'projects_by_status' => $q(
            "SELECT status, COUNT(*) AS c FROM `{$P}projects` WHERE deleted_at IS NULL
              GROUP BY status ORDER BY status"),
    ];

    // ── 9-b. 파생 회계값 (ACCOUNTING_RULES §1·§2 산식 그대로) ──────────────
    // 계약별로 순입금을 붙인 뒤 계약 단위로 판정한다. 전체합 뺄셈은 쓰지 않는다.
    $netSql = dr_net_paid_sql($P);
    $out['accounting']['derived'] = $q(
        "SELECT
            CAST(COALESCE(SUM(GREATEST(c.contract_amount - COALESCE(n.net,0), 0)),0) AS CHAR) AS receivable,
            CAST(COALESCE(SUM(CASE WHEN c.contract_amount > 0 AND COALESCE(n.net,0) >= c.contract_amount
                                   THEN c.supply_amount ELSE 0 END),0) AS CHAR) AS confirmed_revenue_supply,
            SUM(CASE WHEN c.contract_amount > 0 AND COALESCE(n.net,0) >= c.contract_amount
                     THEN 1 ELSE 0 END) AS fully_paid_contracts,
            COUNT(*) AS valid_contracts,
            CAST(COALESCE(SUM(COALESCE(n.net,0)),0) AS CHAR) AS net_paid_on_contracts
           FROM `{$P}contracts` c
           LEFT JOIN ($netSql) n ON n.contract_id = c.id
          WHERE " . DR_CONTRACT_VALID)[0];

    // 계약에 귀속되지 않는 예외 프로젝트 직접 입금(R11) — 미수금 산식 밖이라 따로 센다.
    $out['accounting']['project_direct_payments'] = $q(
        "SELECT COUNT(*) AS c, CAST(COALESCE(SUM(CASE WHEN kind='refund' THEN -amount ELSE amount END),0) AS CHAR) AS net
           FROM `{$P}payments` WHERE " . DR_PAYMENT_PAID . " AND project_id IS NOT NULL")[0];

    // 입금 귀속 분해 — R11 이후 입금은 contract_id 와 project_id 중 하나에 귀속된다.
    // 둘 다 NULL 인 행은 어느 집계에도 잡히지 않아 조용히 사라지는 금액이 된다.
    // FK 만으로는 잡히지 않으므로(NULL 은 FK 검사를 통과) 여기서 명시적으로 센다.
    $out['accounting']['payment_attribution'] = $q(
        "SELECT CASE WHEN contract_id IS NOT NULL AND project_id IS NOT NULL THEN 'both'
                     WHEN contract_id IS NOT NULL THEN 'contract'
                     WHEN project_id  IS NOT NULL THEN 'project'
                     ELSE 'unattributed' END AS attribution,
                status, kind, COUNT(*) AS c, CAST(COALESCE(SUM(amount),0) AS CHAR) AS amt
           FROM `{$P}payments`
          GROUP BY attribution, status, kind ORDER BY attribution, status, kind");

    // 계약별 순입금 원장 — 복구본과 계약 단위로 대조하기 위한 표(합계만으로는
    // 두 계약의 오차가 상쇄돼 "일치"로 보일 수 있다).
    $out['accounting']['per_contract'] = $q(
        "SELECT c.id, c.contract_no, c.status, c.payment_status,
                CAST(c.contract_amount AS CHAR) AS contract_amount,
                CAST(COALESCE(c.supply_amount,0) AS CHAR) AS supply_amount,
                CAST(COALESCE(n.net,0) AS CHAR) AS net_paid
           FROM `{$P}contracts` c
           LEFT JOIN ($netSql) n ON n.contract_id = c.id
          WHERE c.deleted_at IS NULL ORDER BY c.id");

    // ── 10. 참조 무결성(orphan) ───────────────────────────────────────────
    // FK 가 걸려 있어도 확인한다. 덤프가 FOREIGN_KEY_CHECKS=0 으로 감싸 들어가므로
    // import 성공이 정합성을 보장하지 않는다.
    $orphanDefs = [
        'quote_without_customer'    => "`{$P}quotes` a LEFT JOIN `{$P}customers` b ON a.customer_id=b.id WHERE a.customer_id IS NOT NULL AND b.id IS NULL",
        'contract_without_customer' => "`{$P}contracts` a LEFT JOIN `{$P}customers` b ON a.customer_id=b.id WHERE b.id IS NULL",
        'contract_without_quote'    => "`{$P}contracts` a LEFT JOIN `{$P}quotes` b ON a.quote_id=b.id WHERE a.quote_id IS NOT NULL AND b.id IS NULL",
        'project_without_contract'  => "`{$P}projects` a LEFT JOIN `{$P}contracts` b ON a.contract_id=b.id WHERE a.contract_id IS NOT NULL AND b.id IS NULL",
        'payment_without_parent'    => "`{$P}payments` a LEFT JOIN `{$P}contracts` c ON a.contract_id=c.id LEFT JOIN `{$P}projects` p ON a.project_id=p.id WHERE (a.contract_id IS NOT NULL AND c.id IS NULL) OR (a.project_id IS NOT NULL AND p.id IS NULL)",
        'cost_without_project'      => "`{$P}costs` a LEFT JOIN `{$P}projects` b ON a.project_id=b.id WHERE b.id IS NULL",
        'quote_item_without_quote'  => "`{$P}quote_items` a LEFT JOIN `{$P}quote_versions` b ON a.quote_version_id=b.id WHERE b.id IS NULL",
        'assignment_without_user'   => "`{$P}project_assignments` a LEFT JOIN `{$P}users` b ON a.user_id=b.id WHERE b.id IS NULL",
        'assignment_without_project'=> "`{$P}project_assignments` a LEFT JOIN `{$P}projects` b ON a.project_id=b.id WHERE b.id IS NULL",
        'userperm_without_user'     => "`{$P}user_permissions` a LEFT JOIN `{$P}users` b ON a.user_id=b.id WHERE b.id IS NULL",
        'empperm_without_user'      => "`{$P}employee_permissions` a LEFT JOIN `{$P}users` b ON a.user_id=b.id WHERE b.id IS NULL",
        'contract_without_sales'    => "`{$P}contracts` a LEFT JOIN `{$P}users` b ON a.sales_user_id=b.id WHERE a.sales_user_id IS NOT NULL AND b.id IS NULL",
        'schedule_without_user'     => "`{$P}schedules` a LEFT JOIN `{$P}users` b ON a.user_id=b.id WHERE a.user_id IS NOT NULL AND b.id IS NULL",
        'bonus_without_project'     => "`{$P}site_bonuses` a LEFT JOIN `{$P}projects` b ON a.project_id=b.id WHERE a.project_id IS NOT NULL AND b.id IS NULL",
        'memo_without_project'      => "`{$P}project_memos` a LEFT JOIN `{$P}projects` b ON a.project_id=b.id WHERE b.id IS NULL",
    ];
    $orphans = [];
    foreach ($orphanDefs as $k => $from) {
        $orphans[$k] = (int) $q("SELECT COUNT(*) AS c FROM $from")[0]['c'];
    }
    $out['orphans'] = $orphans;

    // ── 11. 중복 검사 ─────────────────────────────────────────────────────
    $out['duplicates'] = [
        'user_login_id'   => (int) $q("SELECT COUNT(*) AS c FROM (SELECT login_id FROM `{$P}users` GROUP BY login_id HAVING COUNT(*)>1) x")[0]['c'],
        'contract_no'     => (int) $q("SELECT COUNT(*) AS c FROM (SELECT contract_no FROM `{$P}contracts` GROUP BY contract_no HAVING COUNT(*)>1) x")[0]['c'],
        'user_permission' => (int) $q("SELECT COUNT(*) AS c FROM (SELECT user_id, permission_id FROM `{$P}user_permissions` GROUP BY user_id, permission_id HAVING COUNT(*)>1) x")[0]['c'],
        'role_permission' => (int) $q("SELECT COUNT(*) AS c FROM (SELECT role_id, permission_id FROM `{$P}role_permissions` GROUP BY role_id, permission_id HAVING COUNT(*)>1) x")[0]['c'],
    ];

    // ── 12. 첨부파일 레코드 ───────────────────────────────────────────────
    // DB 에 파일 경로가 N건인데 백업 안에 파일이 N건보다 적으면 "업로드 누락"이다.
    // 경로 문자열 자체는 개인정보가 될 수 있어 목록만 뽑고 보고서에는 마스킹한다.
    $out['files'] = [
        'project_files_count' => (int) $q("SELECT COUNT(*) AS c FROM `{$P}project_files`")[0]['c'],
        'project_files_rows'  => $q("SELECT id, entity_type, entity_id, path, size, mime FROM `{$P}project_files` ORDER BY id"),
        // 사업자등록증은 customers.biz_license_file_id → project_files.id 참조 구조다.
        'biz_license_linked'  => (int) $q("SELECT COUNT(*) AS c FROM `{$P}customers`
                                            WHERE biz_license_file_id IS NOT NULL")[0]['c'],
        'biz_license_orphan'  => (int) $q("SELECT COUNT(*) AS c FROM `{$P}customers` a
                                             LEFT JOIN `{$P}project_files` b ON a.biz_license_file_id=b.id
                                            WHERE a.biz_license_file_id IS NOT NULL AND b.id IS NULL")[0]['c'],
    ];

    // ── 13. 한글 왕복 검증 시료 ───────────────────────────────────────────
    // charset 이 깨졌는지 확인할 표본. HEX 로도 남겨 바이트 단위 비교가 가능하게 한다.
    $out['charset_sample'] = $q(
        "SELECT id, name, HEX(name) AS name_hex, CHAR_LENGTH(name) AS chars, LENGTH(name) AS bytes
           FROM `{$P}customers` ORDER BY id LIMIT 10");

    // ── 14. 감사 로그 ─────────────────────────────────────────────────────
    // 회계 감사 추적성의 근거. 유실되면 복구는 실패다.
    $out['audit'] = [
        'total'  => (int) $q("SELECT COUNT(*) AS c FROM `{$P}audit_logs`")[0]['c'],
        'range'  => $q("SELECT MIN(created_at) AS first_at, MAX(created_at) AS last_at,
                               MIN(id) AS min_id, MAX(id) AS max_id FROM `{$P}audit_logs`")[0],
        // LIMIT 을 걸지 않는다. 상위 N 개만 기록하면 "복구본에만 있는 액션" 검사에서
        // 꼬리에 있는 정상 액션들이 전부 미지의 값으로 오탐된다(실제로 26건 오탐 발생).
        'by_action' => $q("SELECT action, COUNT(*) AS c FROM `{$P}audit_logs`
                            GROUP BY action ORDER BY action"),
        // id 구간별 건수 체크포인트.
        //
        // 총건수 비교는 드리프트에 무너진다: 백업 이후 운영에서 로그가 늘면 복구본이
        // "감소"한 것처럼 보이고, 복구 환경에서 QA 를 돌리면 반대로 "증가"한 것처럼 보인다.
        // 어느 쪽도 복구 결함이 아니다. 반면 **양쪽에 공통으로 존재하는 id 구간**의 건수는
        // 물리 삭제가 없는 한 반드시 같아야 하므로, 이걸 비교하면 시점과 무관하게
        // "백업 시점까지의 이력이 온전한가"를 판정할 수 있다.
        'id_buckets' => $q("SELECT FLOOR(id/100)*100 AS bucket, COUNT(*) AS c
                              FROM `{$P}audit_logs` GROUP BY bucket ORDER BY bucket"),
    ];

    return $out;
}
