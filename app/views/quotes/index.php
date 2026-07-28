<?php /** @var array $rows @var array $p @var float $sumTotal @var array $range @var array $filters @var array $statusLabels @var array $statusBadge */ ?>
<?php $filterOn = $filters['q'] !== '' || $filters['status'] !== '' || $filters['period'] !== ''; ?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">견적 관리<?php if ($filters['trash']): ?> <span class="badge badge-muted">휴지통</span><?php endif; ?></h1>
      <div class="page-sub"><?= $filterOn ? '조회' : '전체' ?> <?= number_format($p['total']) ?>건 · 총액(VAT 포함) <?= money($sumTotal) ?>원</div>
    </div>
    <div class="page-actions">
      <?php if ($filters['trash']): ?>
        <a href="<?= e(url('quotes.index')) ?>" class="btn btn-ghost">목록으로</a>
      <?php else: ?>
        <?php /* R16: 휴지통은 최고운영자 전용(trash.manage = ADMIN_ONLY) — 등록 권한과 분리 */ ?>
        <?php if (can('trash.manage')): ?>
          <a href="<?= e(url('quotes.index', ['trash' => 1])) ?>" class="btn btn-ghost">휴지통</a>
        <?php endif; ?>
        <?php if (can('quote.manage')): ?>
          <a href="<?= e(url('quotes.form')) ?>" class="btn btn-primary">+ 견적 등록</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <form class="toolbar" method="get" action="<?= e(url('quotes.index')) ?>">
    <input type="hidden" name="r" value="quotes.index">
    <?php if (!empty($filters['trash'])): ?><input type="hidden" name="trash" value="1"><?php endif; ?>
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
      <div class="empty-title"><?= $filters['trash'] ? '휴지통이 비어 있습니다.' : '등록된 견적이 없습니다.' ?></div>
      <?php if (!$filters['trash'] && can('quote.manage')): ?>
        <a href="<?= e(url('quotes.form')) ?>" class="btn btn-primary">견적 등록</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>견적번호</th><th>고객</th><th class="num" title="공급가액 + 부가세 − 할인 · VAT 포함">총액(VAT 포함)</th><th>상태</th><th>유효기간</th><th>작성일</th>
            <?php if ($filters['trash']): ?><th>삭제일</th><th>관리</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php if ($filters['trash']): ?><?= e($r['quote_no']) ?><?php else: ?><a href="<?= e(url('quotes.show', ['id' => $r['id']])) ?>"><?= e($r['quote_no']) ?></a><?php endif; ?></td>
              <td class="ellipsis"><?= e($r['customer_name']) ?></td>
              <td class="num"><?= money($r['total_amount'] !== null ? (float) $r['total_amount'] : null) ?></td>
              <td><span class="badge <?= e($statusBadge[$r['status']] ?? 'badge-muted') ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
              <td><?= fmtdate($r['valid_until']) ?></td>
              <td><?= fmtdate($r['created_at']) ?></td>
              <?php if ($filters['trash']): ?>
                <td><?= fmtdate($r['deleted_at']) ?></td>
                <td class="nowrap">
                  <form method="post" action="<?= e(url('quotes.restore')) ?>" style="display:inline"><?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline">복원</button></form>
                  <?php if (is_role('super_admin')): ?>
                  <form method="post" action="<?= e(url('quotes.purge')) ?>" style="display:inline"
                        data-purge data-purge-kind="견적"
                        data-purge-label="<?= e($r['quote_no'] ?? ('#' . (int) $r['id'])) ?>"
                        data-purge-scope="견적 버전·견적 항목"><?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">완전삭제</button></form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
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
