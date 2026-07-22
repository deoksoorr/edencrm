<?php /** @var array $rows @var array $p @var array $filters @var array $statusLabels @var array $statusBadge @var array $payStatusLabels @var array $payStatusBadge */ ?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">계약 관리</h1>
      <div class="page-sub">전체 <?= number_format($p['total']) ?>건</div>
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
    <button type="submit" class="btn btn-outline">검색</button>
    <?php if ($filters['q'] || $filters['status'] || $filters['payment_status']): ?>
      <a href="<?= e(url('contracts.index')) ?>" class="btn btn-ghost">초기화</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-icon">▤</div>
      <div class="empty-title">등록된 계약이 없습니다.</div>
      <?php if (can('contract.manage')): ?>
        <a href="<?= e(url('contracts.form')) ?>" class="btn btn-primary">첫 계약 등록하기</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>계약번호</th><th>고객</th><th class="num">계약금액</th><th>결제상태</th><th class="num">미수금</th><th>착공예정</th><th>준공예정</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><a href="<?= e(url('contracts.show', ['id' => $r['id']])) ?>"><?= e($r['contract_no']) ?></a></td>
              <td class="ellipsis"><?= e($r['customer_name']) ?></td>
              <td class="num"><?= money($r['contract_amount']) ?></td>
              <td><span class="badge <?= e($payStatusBadge[$r['payment_status']] ?? 'badge-muted') ?>"><?= e($payStatusLabels[$r['payment_status']] ?? $r['payment_status']) ?></span></td>
              <td class="num <?= (float) $r['receivable'] > 0 ? 'text-danger' : '' ?>"><?= money((float) $r['receivable']) ?></td>
              <td><?= fmtdate($r['start_date']) ?></td>
              <td><?= fmtdate($r['end_date']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <div class="page-info"><?= number_format($p['from']) ?>-<?= number_format($p['to']) ?> / <?= number_format($p['total']) ?></div>
      <?php
        $qs = $filters;
        for ($i = 1; $i <= $p['pages']; $i++):
          $qs['page'] = $i;
      ?>
        <a class="<?= $i === $p['page'] ? 'cur' : '' ?>" href="<?= e(url('contracts.index', $qs)) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
