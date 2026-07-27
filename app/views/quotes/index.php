<?php /** @var array $rows @var array $p @var float $sumTotal @var array $range @var array $filters @var array $statusLabels @var array $statusBadge */ ?>
<?php $filterOn = $filters['q'] !== '' || $filters['status'] !== '' || $filters['period'] !== ''; ?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">견적 관리</h1>
      <div class="page-sub"><?= $filterOn ? '조회' : '전체' ?> <?= number_format($p['total']) ?>건 · 총액(VAT 포함) <?= money($sumTotal) ?>원</div>
    </div>
    <div class="page-actions">
      <?php if (can('quote.manage')): ?>
        <a href="<?= e(url('quotes.form')) ?>" class="btn btn-primary">+ 견적 등록</a>
      <?php endif; ?>
    </div>
  </div>

  <form class="toolbar" method="get" action="<?= e(url('quotes.index')) ?>">
    <input type="hidden" name="r" value="quotes.index">
    <input type="text" name="q" class="input search" placeholder="견적번호 / 고객명 검색" value="<?= e($filters['q']) ?>">
    <select name="status" class="select">
      <option value="">전체 상태</option>
      <?php foreach ($statusLabels as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php View::partial('partials/period_filter', [
        'action'  => 'quotes.index',
        'filters' => $filters,
        'range'   => $range,
    ]); ?>
    <button type="submit" class="btn btn-outline">검색</button>
    <?php if ($filterOn): ?>
      <a href="<?= e(url('quotes.index')) ?>" class="btn btn-ghost">초기화</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-icon">▤</div>
      <div class="empty-title">등록된 견적이 없습니다.</div>
      <?php if (can('quote.manage')): ?>
        <a href="<?= e(url('quotes.form')) ?>" class="btn btn-primary">첫 견적 등록하기</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>견적번호</th><th>고객</th><th class="num" title="공급가액 + 부가세 − 할인 · VAT 포함">총액(VAT 포함)</th><th>상태</th><th>유효기간</th><th>작성일</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><a href="<?= e(url('quotes.show', ['id' => $r['id']])) ?>"><?= e($r['quote_no']) ?></a></td>
              <td class="ellipsis"><?= e($r['customer_name']) ?></td>
              <td class="num"><?= money($r['total_amount'] !== null ? (float) $r['total_amount'] : null) ?></td>
              <td><span class="badge <?= e($statusBadge[$r['status']] ?? 'badge-muted') ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
              <td><?= fmtdate($r['valid_until']) ?></td>
              <td><?= fmtdate($r['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php
      $qs = array_filter($filters, static fn ($v) => $v !== '' && $v !== null);
      View::partial('partials/pager', [
          'pg'  => $p,
          'url' => fn (int $pg): string => url('quotes.index', $qs + ['page' => $pg]),
      ]);
    ?>
  <?php endif; ?>
</div>
