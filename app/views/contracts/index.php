<?php /** @var array $rows @var array $totals @var array $p @var array $range @var array $filters @var array $basisOptions @var array $sortLabels @var array $statusLabels @var array $statusBadge @var array $payStatusLabels @var array $payStatusBadge */ ?>
<?php
// 합계 카드(계약 총액·현재 입금 총액·미수금)는 R4 사용자 지시로 필터 연동 — 목록과 동일 WHERE 집계(R3 "전체 기준 고정" 대체)
$filtered = $filters['q'] !== '' || $filters['status'] !== '' || $filters['payment_status'] !== '' || $filters['period'] !== '';
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">계약 관리</h1>
      <div class="page-sub"><?= $filtered ? '조회' : '전체' ?> <?= number_format($p['total']) ?>건<?php if ($filtered): ?> <span class="muted" title="아래 합계는 현재 검색·기간 필터가 적용된 계약 기준입니다">(필터 적용 합계)</span><?php endif; ?>
        · <span title="<?= $filtered ? '조회된' : '전체' ?> 계약 금액 합 · VAT 포함">계약 총액(VAT 포함) <b class="mono"><?= money($totals['contract']) ?></b></span>
        <span class="muted" title="계약 총액 중 공급가액(VAT 제외) / 부가세 내역">(공급가액 <span class="mono"><?= money($totals['supply']) ?></span> + 부가세 <span class="mono"><?= money($totals['vat']) ?></span>)</span>
        · <span title="순입금(정상 입금 − 환불) 합 · 현금 기준 · VAT 포함">입금 총액(VAT 포함) <b class="mono"><?= money($totals['paid']) ?></b></span>
        <?php if (($totals['refund'] ?? 0) > 0): ?>
          · <span title="환불(kind=refund) 합 — 별도 축, 입금 총액에서 이미 차감됨">환불 총액 <b class="mono">−<?= money($totals['refund']) ?></b></span>
        <?php endif; ?>
        · <span title="Σ 계약별 max(0, 계약 총액 − 순입금) · 체결(진행) 이후 계약만 — 작성중·파기/취소 계약 제외">미수금 <b class="mono"><?= money($totals['receivable']) ?></b></span>
      </div>
      <div class="page-sub muted fs-12" title="확정 매출은 완납(순입금 ≥ 계약 총액) 계약의 공급가액 합(완납일 기준)입니다">※ 입금 총액은 VAT 포함 현금 기준 — 대시보드의 확정 매출(공급가액)은 완납 계약의 공급가액(VAT 제외) 합이라 서로 다릅니다.</div>
    </div>
    <div class="page-actions">
      <?php if (can('contract.manage')): ?>
        <a href="<?= e(url('contracts.form')) ?>" class="btn btn-primary">+ 계약 등록</a>
      <?php endif; ?>
    </div>
  </div>

  <form class="toolbar" method="get" action="<?= e(url('contracts.index')) ?>">
    <input type="hidden" name="r" value="contracts.index">
    <input type="text" name="q" class="input search" placeholder="계약번호 / 고객명 검색" value="<?= e($filters['q']) ?>">
    <select name="status" class="select">
      <option value="">전체 상태</option>
      <?php foreach ($statusLabels as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="payment_status" class="select">
      <option value="">전체 결제상태</option>
      <?php foreach ($payStatusLabels as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $filters['payment_status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort" class="select" title="목록 정렬 — 입금일 정렬 시 입금 없는 계약은 마지막, '입금 없음 우선/후순위'는 등록순 유지 + 입금 없음 그룹만 앞/뒤">
      <?php foreach ($sortLabels as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $filters['sort'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php View::partial('partials/period_filter', [
        'action'       => 'contracts.index',
        'filters'      => $filters,
        'range'        => $range,
        'basisOptions' => $basisOptions,
        'basisParam'   => 'basis',
    ]); ?>
    <button type="submit" class="btn btn-outline">검색</button>
    <?php if ($filtered || $filters['sort'] !== '' || $filters['basis'] !== 'contract_date'): ?>
      <a href="<?= e(url('contracts.index')) ?>" class="btn btn-ghost">초기화</a>
    <?php endif; ?>
  </form>
  <?php if ($filters['basis'] === 'last_paid' && $filters['period'] !== ''): ?>
    <div class="page-sub muted fs-12">※ '최근 입금일' 기준 기간 조회는 입금(paid) 기록이 있는 계약만 포함합니다 — 입금 없는 계약은 목록·합계에서 제외됩니다.</div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-icon">▤</div>
      <div class="empty-title"><?= $filtered ? '조건에 맞는 계약이 없습니다.' : '등록된 계약이 없습니다.' ?></div>
      <?php if (!$filtered && can('contract.manage')): ?>
        <a href="<?= e(url('contracts.form')) ?>" class="btn btn-primary">첫 계약 등록하기</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>계약번호</th><th>고객</th><th>상태</th>
            <th class="num" title="공급가액 + 부가세 · VAT 포함">계약 총액(VAT 포함)</th>
            <th>결제상태</th>
            <th class="num" title="순입금 = 정상 입금(paid) − 환불 · VAT 포함 현금 기준">현재 입금 총액</th>
            <th title="정상 입금(paid)의 입금일(paid_date) 최댓값 — 등록·수정일 아님, 환불·대기 제외">마지막 입금일</th>
            <th class="num" title="max(0, 계약 총액(VAT 포함) − 순입금(VAT 포함)) · 작성중·파기/취소 계약은 집계 제외">미수금</th>
            <th class="num" title="순입금 ÷ 계약 총액 × 100 · 총액 0원이면 '-'">입금률</th>
            <th>착공예정</th><th>준공예정</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): $ended = in_array($r['status'], ['terminated', 'cancelled'], true); ?>
            <tr>
              <td><a href="<?= e(url('contracts.show', ['id' => $r['id']])) ?>"><?= e($r['contract_no']) ?></a></td>
              <td class="ellipsis"><?= e($r['customer_name']) ?></td>
              <td><span class="badge <?= e($statusBadge[$r['status']] ?? 'badge-muted') ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
              <td class="num mono"><?= money($r['contract_amount']) ?></td>
              <td><span class="badge <?= e($payStatusBadge[$r['payment_status']] ?? 'badge-muted') ?>"><?= e($payStatusLabels[$r['payment_status']] ?? $r['payment_status']) ?></span></td>
              <td class="num mono" title="<?= $ended ? '파기/취소 계약 — 환불 차감이 반영된 순입금' : '순입금 = 정상 입금 − 환불' ?>"><?= money((float) $r['net_paid']) ?></td>
              <td><?= $r['last_paid'] !== null ? fmtdate($r['last_paid']) : '<span class="muted">입금 없음</span>' ?></td>
              <?php if ($ended): ?>
                <td class="num mono muted" title="파기/취소 계약 — 일반 미수금 집계 제외">제외</td>
              <?php elseif ($r['status'] === 'draft'): ?>
                <td class="num mono muted" title="작성중(체결 전) 계약 — 미수금 집계 제외(체결 후 집계)">제외(작성중)</td>
              <?php else: ?>
                <td class="num mono <?= (float) $r['receivable'] > 0 ? 'text-danger' : '' ?>"><?= money((float) $r['receivable']) ?></td>
              <?php endif; ?>
              <?php if ($ended): ?>
                <td class="num mono muted" title="파기/취소 계약 — 잔여 회차 청구 중단(분할 예정과 입금 내역이 다를 수 있음), 입금률 미표시">-</td>
              <?php else: ?>
                <td class="num mono"><?= e(pct(Calc::rate((float) $r['net_paid'], (float) $r['contract_amount']))) ?></td>
              <?php endif; ?>
              <td><?= fmtdate($r['start_date']) ?></td>
              <td><?= fmtdate($r['end_date']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php
      $qs = array_filter($filters, static fn ($v) => $v !== '' && $v !== null);
      View::partial('partials/pager', [
          'pg'  => $p,
          'url' => fn (int $pg): string => url('contracts.index', $qs + ['page' => $pg]),
      ]);
    ?>
  <?php endif; ?>
</div>
