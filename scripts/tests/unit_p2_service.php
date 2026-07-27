<?php
/**
 * AccountingService P2 확장 — R6 T2 빈 시드 재기준.
 * computeSplit 은 자체 견적 픽스처(트랜잭션 롤백)로 검증하고,
 * 시드 실측 절은 빈 데이터 기준선(전 지표 0·uid 스코프 0)으로 재작성.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
echo "AccountingService P2 확장 — 빈 시드 재기준\n";

// ── computeSplit: 견적 연결 시 견적 VAT 비율 안분(픽스처 생성-롤백) ──
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    $cid = Db::insert('customers', ['type' => 'company', 'name' => 'TEST-P2SPLIT', 'status' => 'active']);
    $qid = Db::insert('quotes', ['quote_no' => 'TQ-P2-SPLIT', 'customer_id' => $cid, 'status' => 'accepted']);
    $qvid = Db::insert('quote_versions', ['quote_id' => $qid, 'version_no' => 1, 'subtotal' => 34622500,
        'vat' => 3462250, 'discount' => 622500, 'total_amount' => 37462250, 'created_by' => 1]);
    Db::update('quotes', ['current_version_id' => $qvid], 'id = :id', [':id' => $qid]);

    $s = AccountingService::computeSplit(37462250, $qid);
    t_int('split(견적 연결) 공급', 34000000, $s['supply']);
    t_int('split(견적 연결) 부가세', 3462250, $s['vat']);
    t_int('split 정합', 37462250, $s['supply'] + $s['vat']);
} finally {
    $pdo->rollBack();
}

// 견적 없음/무효 견적 id → ÷1.1 fallback
$s2 = AccountingService::computeSplit(18500000, null);
t_int('split(무견적) 공급', 16818182, $s2['supply']);
t_int('split(무견적) 정합', 18500000, $s2['supply'] + $s2['vat']);
t_int('split(존재하지 않는 견적 id) → ÷1.1 fallback', 16818182,
    AccountingService::computeSplit(18500000, 999999)['supply']);

// ── 빈 시드 기준선: 업무 데이터 0건 → 전 지표 0 ──
$mf = date('Y-m-01');
$mt = date('Y-m-t');
t_int('확정원가(빈 시드)=0', 0, AccountingService::confirmedCost());
t_int('직원2 귀속매출(빈 시드)=0', 0, AccountingService::employeeConfirmedRevenue(2));
t_int('가중 파이프라인(빈 시드)=0', 0, AccountingService::weightedPipeline());
t_int('이번달 확정매출(빈 시드)=0', 0, AccountingService::confirmedRevenue($mf, $mt));
t_int('이번달 수주액(빈 시드)=0', 0, AccountingService::contractedAmount($mf, $mt));
t_int('미수금(빈 시드)=0', 0, AccountingService::receivable());
// contractedAmount uid 스코프: 담당 프로젝트 없음 → 전 직원 0
t_int('이번달 수주액(직원2 스코프)=0', 0, AccountingService::contractedAmount($mf, $mt, 2));
t_int('이번달 수주액(직원3 스코프)=0', 0, AccountingService::contractedAmount($mf, $mt, 3));

exit(t_summary());
