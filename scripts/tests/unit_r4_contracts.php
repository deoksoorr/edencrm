<?php
/**
 * R4 T6 — 계약 목록 입금 컬럼·정렬·기간 필터 검증 (트랜잭션 롤백).
 *  1) 순입금(paid payment − refund) / 마지막 입금일(paid_date 최댓값 — 환불·pending 제외, 환불만 있으면 NULL)
 *  2) 입금률 0 나눗셈(계약 총액 0 → Calc::rate null → 화면 '-')
 *  3) 정렬 4종 NULL(입금 없음) 처리 — ContractsController::index 의 ORDER BY 와 동일 식
 *  4) 기간 필터 경계 = 견적 탭과 동일(Util::periodRange 단일 출처) + 폐구간·last_paid 기준 NULL 제외
 *  5) contractTotals(whereSql) 필터 연동 합계 = 목록 모집단 합
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

echo "R4 T6 계약 입금 컬럼·정렬·기간 필터 — 트랜잭션 롤백\n";

$PAID = AccountingService::PAID_SUM_SQL;
$LAST = AccountingService::LAST_PAID_SQL;

$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $cid = Db::insert('customers', ['type' => 'company', 'name' => 'T6대사', 'status' => 'active']);

    // A: 부분입금 + pending + 환불 — net 4,000,000 · last_paid 2026-02-20(뒤늦은 환불 3/15·pending 제외)
    $a = Db::insert('contracts', ['contract_no' => 'T6-A', 'customer_id' => $cid, 'contract_amount' => 10000000,
        'status' => 'active', 'payment_status' => 'partial', 'contract_date' => '2026-01-10', 'created_at' => '2026-01-01 09:00:00']);
    Db::insert('payments', ['contract_id' => $a, 'pay_type' => 'down', 'amount' => 3000000, 'status' => 'paid', 'paid_date' => '2026-01-10']);
    Db::insert('payments', ['contract_id' => $a, 'pay_type' => 'middle', 'amount' => 2000000, 'status' => 'paid', 'paid_date' => '2026-02-20']);
    Db::insert('payments', ['contract_id' => $a, 'pay_type' => 'balance', 'amount' => 5000000, 'status' => 'pending', 'due_date' => '2026-03-01']);
    Db::insert('payments', ['contract_id' => $a, 'pay_type' => 'etc', 'kind' => 'refund', 'amount' => 1000000, 'status' => 'paid', 'paid_date' => '2026-03-15']);

    // B: 환불만 있는 계약 — 마지막 입금일 NULL(입금 없음), 순입금 음수
    $b = Db::insert('contracts', ['contract_no' => 'T6-B', 'customer_id' => $cid, 'contract_amount' => 5000000,
        'status' => 'active', 'payment_status' => 'unpaid', 'contract_date' => '2026-01-31', 'created_at' => '2026-01-02 09:00:00']);
    Db::insert('payments', ['contract_id' => $b, 'pay_type' => 'etc', 'kind' => 'refund', 'amount' => 500000, 'status' => 'paid', 'paid_date' => '2026-02-01']);

    // C: 계약 총액 0(입금률 0 나눗셈) + 입금 1건(1/15)
    $c = Db::insert('contracts', ['contract_no' => 'T6-C', 'customer_id' => $cid, 'contract_amount' => 0,
        'status' => 'active', 'payment_status' => 'unpaid', 'contract_date' => '2026-02-01', 'created_at' => '2026-01-03 09:00:00']);
    Db::insert('payments', ['contract_id' => $c, 'pay_type' => 'etc', 'amount' => 100000, 'status' => 'paid', 'paid_date' => '2026-01-15']);

    // D: 입금 행 자체가 없는 계약(작성중) — 입금 없음 + 미수금 모집단 제외
    $d = Db::insert('contracts', ['contract_no' => 'T6-D', 'customer_id' => $cid, 'contract_amount' => 7000000,
        'status' => 'draft', 'payment_status' => 'unpaid', 'contract_date' => '2026-01-01', 'created_at' => '2026-01-04 09:00:00']);

    $ids = "$a,$b,$c,$d";
    $rows = [];
    foreach (Db::all("SELECT c.id, $PAID AS net_paid, $LAST AS last_paid,
            GREATEST(0, c.contract_amount - $PAID) AS receivable
        FROM contracts c WHERE c.id IN ($ids)") as $r) { $rows[(int) $r['id']] = $r; }

    // ── 1) 순입금·마지막 입금일 ──
    t_int('A 순입금 = 3,000,000+2,000,000−1,000,000 (pending 제외)', 4000000, $rows[$a]['net_paid']);
    t_true('A 마지막 입금일 = 2026-02-20 (뒤늦은 환불 3/15 무시)', $rows[$a]['last_paid'] === '2026-02-20');
    t_int('A 순입금(배치 SQL) = contractNetPaid 단건과 동일', AccountingService::contractNetPaid($a), (int) $rows[$a]['net_paid']);
    t_true('B(환불만) 마지막 입금일 = NULL → 화면 "입금 없음"', $rows[$b]['last_paid'] === null);
    t_int('B 순입금 = −500,000 (환불만)', -500000, $rows[$b]['net_paid']);
    t_true('D(입금 0건) 마지막 입금일 = NULL', $rows[$d]['last_paid'] === null);
    t_int('A 미수금 = max(0, 10,000,000 − 4,000,000)', 6000000, $rows[$a]['receivable']);

    // ── 2) 입금률 — 총액 0 이면 null('-'), 정상 계약은 순입금/총액 ──
    t_null('C 입금률: 총액 0 → Calc::rate null(화면 -)', Calc::rate((float) $rows[$c]['net_paid'], 0.0));
    t_float('A 입금률 = 40%', 40.0, Calc::rate((float) $rows[$a]['net_paid'], 10000000.0));

    // ── 3) 정렬 4종 (ContractsController::index ORDER BY 와 동일 식) — NULL 처리 명확화 ──
    $order = fn (string $ob): array => array_map('intval', array_column(
        Db::all("SELECT c.id FROM contracts c WHERE c.id IN ($ids) ORDER BY $ob"), 'id'));
    // last_paid: A=02-20, C=01-15, B=NULL, D=NULL / created_at: A<B<C<D
    t_true('정렬 paid_recent: [A,C] 최근순 → NULL 은 마지막(id DESC)', $order("($LAST IS NULL), $LAST DESC, c.id DESC") === [$a, $c, $d, $b]);
    t_true('정렬 paid_oldest: [C,A] 오래된순 → NULL 은 마지막(id ASC)', $order("($LAST IS NULL), $LAST ASC, c.id ASC") === [$c, $a, $b, $d]);
    t_true('정렬 no_paid_first: NULL 그룹 먼저(등록 최신순) → [D,B,C,A]', $order("($LAST IS NULL) DESC, c.created_at DESC, c.id DESC") === [$d, $b, $c, $a]);
    t_true('정렬 no_paid_last: NULL 그룹 마지막(등록 최신순) → [C,A,D,B]', $order("($LAST IS NULL), c.created_at DESC, c.id DESC") === [$c, $a, $d, $b]);

    // ── 4) 기간 필터: 계약 탭 = 견적 탭 동일 경계(Util::periodRange 단일 출처 — 자체 날짜 계산 금지 규약) ──
    $anchor = '2026-07-23';
    $expect = [
        'today'      => ['2026-07-23', '2026-07-23'],
        'this_week'  => ['2026-07-20', '2026-07-26'], // 주 시작=월요일
        'this_month' => ['2026-07-01', '2026-07-31'],
        'last_month' => ['2026-06-01', '2026-06-30'],
        'last_3m'    => ['2026-05-01', '2026-07-31'], // 당월 포함 3개 캘린더 월
        'this_year'  => ['2026-01-01', '2026-12-31'],
    ];
    foreach ($expect as $pk => [$ef, $et]) {
        $r = Util::periodRange($pk, null, null, $anchor);
        t_true("프리셋 $pk 경계 = $ef~$et (견적·계약 공통)", $r['from'] === $ef && $r['to'] === $et);
    }
    // 폐구간 검증(양끝 포함): this_month(1월 앵커) = 01-01~01-31 → 계약일 기준 A(1/10)·B(1/31)·D(1/1) 포함, C(2/1) 제외
    $rg = Util::periodRange('this_month', null, null, '2026-01-15');
    $cnt = (int) Db::val("SELECT COUNT(*) FROM contracts c WHERE c.id IN ($ids)
        AND c.contract_date >= :f AND c.contract_date <= :t", [':f' => $rg['from'], ':t' => $rg['to']]);
    t_int('계약일 기준 1월 조회 = 3건 (1/1·1/31 양끝 포함, 2/1 제외)', 3, $cnt);
    // 최근 입금일 기준: 같은 1월 범위 → C(1/15)만. NULL(입금 없음)·2월 입금은 자동 제외
    $cnt2 = (int) Db::val("SELECT COUNT(*) FROM contracts c WHERE c.id IN ($ids)
        AND $LAST >= :f AND $LAST <= :t", [':f' => $rg['from'], ':t' => $rg['to']]);
    t_int('최근 입금일 기준 1월 조회 = 1건 (C만 — 입금 없음 자동 제외)', 1, $cnt2);

    // ── 5) 합계 카드 필터 연동 — contractTotals(whereSql) = 목록 모집단 합(1쿼리 배치) ──
    $tot = AccountingService::contractTotals("c.deleted_at IS NULL AND c.id IN ($ids)");
    t_int('필터 합계 건수 = 4', 4, $tot['count']);
    t_int('필터 합계 계약 총액 = 22,000,000', 22000000, $tot['contract']);
    t_int('필터 합계 순입금 = 4,000,000−500,000+100,000 = 3,600,000', 3600000, $tot['paid']);
    // 미수금: A 6,000,000 + B max(0,5,000,000+500,000)=5,500,000 + C 0 (draft D 는 모집단 제외)
    t_int('필터 합계 미수금 = 11,500,000 (draft 제외·환불 재미수 포함)', 11500000, $tot['receivable']);
    // 전체 호출(기본 인자) 하위호환 — 기존 화면·테스트와 동일 모집단
    t_int('contractTotals() 기본 = 전체 삭제 제외 (건수 ≥ 4)', 1, AccountingService::contractTotals()['count'] >= 4 ? 1 : 0);

} finally {
    $pdo->rollBack();
}
exit(t_summary());
