<?php /** @var array $quote @var array $items @var ?array $version @var array $versions @var ?array $contract @var array $attachments */ ?>
<div class="page">
  <div class="detail-head">
    <div>
      <div class="detail-title"><?= e($quote['quote_no']) ?>
        <span class="badge <?= e($statusBadge[$quote['status']] ?? 'badge-muted') ?>"><?= e($statusLabels[$quote['status']] ?? $quote['status']) ?></span>
      </div>
      <div class="detail-meta">
        고객 <?= e($quote['customer_name']) ?><?= $quote['customer_phone'] ? ' · ' . e($quote['customer_phone']) : '' ?>
        · 유효기간 <?= fmtdate($quote['valid_until']) ?>
        · 작성일 <?= fmtdate($quote['created_at']) ?>
      </div>
    </div>
    <div class="page-actions">
      <a href="<?= e(url('quotes.print', ['id' => $quote['id']])) ?>" target="_blank" class="btn btn-outline">인쇄</a>
      <?php if (can('quote.manage')): ?>
        <a href="<?= e(url('quotes.form', ['id' => $quote['id']])) ?>" class="btn btn-outline">수정</a>
        <?php if (!$contract): ?>
          <button type="button" class="btn btn-danger" id="btnDeleteQuote">삭제</button>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!$contract && can('contract.manage')): ?>
        <a href="<?= e(url('contracts.form', ['quote_id' => $quote['id']])) ?>" class="btn btn-primary">계약으로 전환</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($contract): ?>
    <div class="card pad">
      이 견적은 계약 <a href="<?= e(url('contracts.show', ['id' => $contract['id']])) ?>"><strong><?= e($contract['contract_no']) ?></strong></a> 으로 전환되었습니다.
    </div>
  <?php endif; ?>

  <div class="card pad">
    <div class="section-head"><div class="st"><h2>견적 항목 (v<?= (int) ($version['version_no'] ?? 1) ?>)</h2></div></div>
    <?php if (!$items): ?>
      <div class="empty"><div class="empty-title">등록된 항목이 없습니다.</div></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr>
            <th>항목명</th><th class="num">면적</th><th class="num">수량</th><th class="num">단가</th><th class="num">금액</th>
          </tr></thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td class="wrap"><?= e($it['name']) ?></td>
                <td class="num mono"><?= $it['area'] !== null ? number_format((float) $it['area'], 2) : '-' ?></td>
                <td class="num mono"><?= number_format((float) $it['qty'], 2) ?></td>
                <td class="num mono"><?= money($it['unit_price']) ?></td>
                <td class="num mono"><?= money($it['amount']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($version): ?>
      <div class="kv-row mt-16">
        <div class="kv"><div class="kv-label">공급가액</div><div class="kv-value"><?= moneyCell($version['subtotal']) ?></div></div>
        <div class="kv"><div class="kv-label">부가세</div><div class="kv-value"><?= moneyCell($version['vat']) ?></div></div>
        <div class="kv"><div class="kv-label">할인</div><div class="kv-value"><?= moneyCell($version['discount']) ?></div></div>
        <div class="kv"><div class="kv-label">총 금액</div><div class="kv-value" style="color:var(--brand)"><?= moneyCell($version['total_amount']) ?></div></div>
      </div>
      <?php if (!empty($version['note'])): ?><div class="field-hint mt-8">메모: <?= e($version['note']) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($quote['memo'])): ?>
      <div class="section-title mt-16">특이사항</div>
      <div style="white-space:pre-wrap"><?= e($quote['memo']) ?></div>
    <?php endif; ?>
  </div>

  <div class="card pad">
    <div class="section-head"><div class="st"><h2>버전 이력</h2></div></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>버전</th><th class="num">공급가액</th><th class="num">부가세</th><th class="num">할인</th><th class="num">총액</th><th>작성자</th><th>작성일</th></tr></thead>
        <tbody>
          <?php foreach ($versions as $v): ?>
            <tr<?= $v['id'] == $quote['current_version_id'] ? ' style="font-weight:600;background:#fafbfc"' : '' ?>>
              <td>v<?= (int) $v['version_no'] ?><?= $v['id'] == $quote['current_version_id'] ? ' <span class="badge badge-info">현재</span>' : '' ?></td>
              <td class="num mono"><?= money($v['subtotal']) ?></td>
              <td class="num mono"><?= money($v['vat']) ?></td>
              <td class="num mono"><?= money($v['discount']) ?></td>
              <td class="num mono"><?= money($v['total_amount']) ?></td>
              <td><?= e($v['created_by_name'] ?? '-') ?></td>
              <td class="nowrap"><?= fmtdate($v['created_at'], 'Y-m-d H:i') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card pad">
    <div class="section-head"><div class="st"><h2>첨부파일</h2></div></div>
    <?php if (!$attachments): ?>
      <div class="muted">첨부된 파일이 없습니다.</div>
    <?php else: ?>
      <?php foreach ($attachments as $f): ?>
        <div class="file-item">
          <div class="file-name"><?= e($f['original_name']) ?></div>
          <a href="<?= e(url('files.download', ['id' => $f['id']])) ?>" class="btn btn-sm btn-outline">다운로드</a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (can('quote.manage') && !$contract): ?>
<script>
(function(){
  var btn = document.getElementById('btnDeleteQuote');
  if (!btn) return;
  btn.addEventListener('click', async function () {
    var ok = await EDEN.confirm('이 견적을 삭제하시겠습니까?', { danger: true, okLabel: '삭제' });
    if (!ok) return;
    try {
      await api('quotes.delete', { id: <?= (int) $quote['id'] ?> });
      toast('삭제되었습니다.', 'success');
      location.href = EDEN.url('quotes.index');
    } catch (e) { toast(e.message, 'error'); }
  });
})();
</script>
<?php endif; ?>
