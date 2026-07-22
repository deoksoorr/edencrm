<?php
/** @var array $contract @var array $payments @var float $paidSum @var float $receivable @var ?array $project
 *  @var array $statusLabels @var array $statusBadge @var array $payStatusLabels @var array $payStatusBadge
 *  @var array $payRowLabels @var array $payTypeLabels */
?>
<div class="page">
  <div class="detail-head">
    <div>
      <div class="detail-title"><?= e($contract['contract_no']) ?>
        <span class="badge <?= e($statusBadge[$contract['status']] ?? 'badge-muted') ?>"><?= e($statusLabels[$contract['status']] ?? $contract['status']) ?></span>
        <span class="badge <?= e($payStatusBadge[$contract['payment_status']] ?? 'badge-muted') ?>"><?= e($payStatusLabels[$contract['payment_status']] ?? $contract['payment_status']) ?></span>
      </div>
      <div class="detail-meta">
        고객 <?= e($contract['customer_name']) ?><?= $contract['customer_phone'] ? ' · ' . e($contract['customer_phone']) : '' ?>
        <?php if (!empty($contract['quote_no'])): ?> · 원본견적 <a href="<?= e(url('quotes.show', ['id' => $contract['quote_id']])) ?>"><?= e($contract['quote_no']) ?></a><?php endif; ?>
        <?php if (!empty($contract['sales_user_name'])): ?> · 담당 <?= e($contract['sales_user_name']) ?><?php endif; ?>
      </div>
    </div>
    <div class="page-actions">
      <?php if (can('contract.manage')): ?>
        <a href="<?= e(url('contracts.form', ['id' => $contract['id']])) ?>" class="btn btn-outline">수정</a>
      <?php endif; ?>
      <?php if ($project): ?>
        <a href="<?= e(url('projects.show', ['id' => $project['id']])) ?>" class="btn btn-outline">프로젝트 보기 (<?= e($project['project_no']) ?>)</a>
      <?php elseif (can('project.manage')): ?>
        <button type="button" class="btn btn-primary" id="btnToProject">프로젝트로 전환</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">계약 요약</div></div>
    <div class="card-body">
      <div class="kv-row">
        <div class="kv"><div class="kv-label">계약금액</div><div class="kv-value"><?= money($contract['contract_amount']) ?></div></div>
        <div class="kv"><div class="kv-label">입금완료</div><div class="kv-value" style="color:var(--ok)"><?= money($paidSum) ?></div></div>
        <div class="kv"><div class="kv-label">미수금</div><div class="kv-value" style="color:<?= $receivable > 0 ? 'var(--danger)' : 'var(--ink)' ?>"><?= money($receivable) ?></div></div>
      </div>
      <div class="dl" style="margin-top:18px">
        <dt>계약일</dt><dd><?= fmtdate($contract['contract_date']) ?></dd>
        <dt>계약금/중도금/잔금</dt><dd><?= money($contract['down_payment']) ?> / <?= money($contract['middle_payment']) ?> / <?= money($contract['balance_payment']) ?></dd>
        <dt>착공예정</dt><dd><?= fmtdate($contract['start_date']) ?></dd>
        <dt>준공예정</dt><dd><?= fmtdate($contract['end_date']) ?></dd>
        <dt>하자보증기간</dt><dd><?= e($contract['warranty_period'] ?: '-') ?></dd>
        <?php if (!empty($contract['special_terms'])): ?>
          <dt>특약사항</dt><dd style="white-space:pre-wrap"><?= e($contract['special_terms']) ?></dd>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-title">입금 관리</div>
      <?php if (can('payment.manage')): ?>
        <button type="button" class="btn btn-sm btn-primary" id="btnAddPayment">+ 입금 등록</button>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (!$payments): ?>
        <div class="empty"><div class="empty-title">등록된 입금 내역이 없습니다.</div></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>구분</th><th class="num">금액</th><th>예정일</th><th>입금일</th><th>상태</th><?php if (can('payment.manage')): ?><th>관리</th><?php endif; ?></tr></thead>
            <tbody>
              <?php foreach ($payments as $pm): ?>
                <tr>
                  <td><?= e($payTypeLabels[$pm['pay_type']] ?? $pm['pay_type']) ?></td>
                  <td class="num"><?= money($pm['amount']) ?></td>
                  <td><?= fmtdate($pm['due_date']) ?></td>
                  <td><?= fmtdate($pm['paid_date']) ?></td>
                  <td><span class="badge <?= $pm['status'] === 'paid' ? 'badge-ok' : 'badge-warn' ?>"><?= e($payRowLabels[$pm['status']] ?? $pm['status']) ?></span></td>
                  <?php if (can('payment.manage')): ?>
                    <td>
                      <button type="button" class="btn btn-sm btn-outline btn-edit-pm"
                        data-id="<?= (int) $pm['id'] ?>" data-type="<?= e($pm['pay_type']) ?>" data-amount="<?= (int) $pm['amount'] ?>"
                        data-due="<?= e($pm['due_date'] ?? '') ?>" data-paid="<?= e($pm['paid_date'] ?? '') ?>"
                        data-status="<?= e($pm['status']) ?>" data-memo="<?= e($pm['memo'] ?? '') ?>">수정</button>
                      <button type="button" class="btn btn-sm btn-ghost btn-del-pm" data-id="<?= (int) $pm['id'] ?>">삭제</button>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (can('payment.manage')): ?>
<script>
(function () {
  var CONTRACT_ID = <?= (int) $contract['id'] ?>;
  var PAY_TYPE_LABELS = <?= json_encode($payTypeLabels, JSON_UNESCAPED_UNICODE) ?>;

  function paymentFormHtml(pm) {
    pm = pm || {};
    var opts = Object.keys(PAY_TYPE_LABELS).map(function (k) {
      return '<option value="' + k + '"' + (pm.type === k ? ' selected' : '') + '>' + PAY_TYPE_LABELS[k] + '</option>';
    }).join('');
    var statusOpts = ['pending', 'paid'].map(function (s) {
      var label = s === 'paid' ? '입금완료' : '대기';
      return '<option value="' + s + '"' + (pm.status === s ? ' selected' : '') + '>' + label + '</option>';
    }).join('');
    return '' +
      '<form data-ajax action-route="payments.save" data-reload class="form">' +
      '<input type="hidden" name="_csrf" value="' + (window.EDEN.CSRF || '') + '">' +
      '<input type="hidden" name="contract_id" value="' + CONTRACT_ID + '">' +
      '<input type="hidden" name="id" value="' + (pm.id || 0) + '">' +
      '<div class="form-grid">' +
      '<div class="field"><label class="field-label">구분</label><select name="pay_type" class="select">' + opts + '</select></div>' +
      '<div class="field"><label class="field-label">금액</label><input type="text" inputmode="decimal" name="amount" class="input" value="' + (pm.amount || '') + '"></div>' +
      '<div class="field"><label class="field-label">예정일</label><input type="date" name="due_date" class="input" value="' + (pm.due || '') + '"></div>' +
      '<div class="field"><label class="field-label">입금일</label><input type="date" name="paid_date" class="input" value="' + (pm.paid || '') + '"></div>' +
      '<div class="field"><label class="field-label">상태</label><select name="status" class="select">' + statusOpts + '</select></div>' +
      '<div class="field"><label class="field-label">메모</label><input type="text" name="memo" class="input" value="' + (pm.memo || '') + '"></div>' +
      '</div>' +
      '<div style="text-align:right;margin-top:6px"><button type="submit" class="btn btn-primary">저장</button></div>' +
      '</form>';
  }

  function openModal(pm) {
    EDEN.modal({ title: pm && pm.id ? '입금 수정' : '입금 등록', body: paymentFormHtml(pm || {}), footer: false });
  }

  document.getElementById('btnAddPayment')?.addEventListener('click', function () { openModal({}); });

  document.querySelectorAll('.btn-edit-pm').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal({
        id: btn.dataset.id, type: btn.dataset.type, amount: btn.dataset.amount,
        due: btn.dataset.due, paid: btn.dataset.paid, status: btn.dataset.status, memo: btn.dataset.memo,
      });
    });
  });

  document.querySelectorAll('.btn-del-pm').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var ok = await EDEN.confirm('이 입금 내역을 삭제하시겠습니까?', { danger: true, okLabel: '삭제' });
      if (!ok) return;
      try {
        await api('payments.delete', { id: btn.dataset.id });
        toast('삭제되었습니다.', 'success');
        location.reload();
      } catch (e) { toast(e.message, 'error'); }
    });
  });
})();
</script>
<?php endif; ?>

<?php if (!$project && can('project.manage')): ?>
<script>
(function () {
  var btn = document.getElementById('btnToProject');
  if (!btn) return;
  btn.addEventListener('click', async function () {
    var ok = await EDEN.confirm('이 계약을 프로젝트로 전환하시겠습니까?', { okLabel: '전환' });
    if (!ok) return;
    try {
      var data = await api('contracts.toproject', { id: <?= (int) $contract['id'] ?> });
      toast('프로젝트로 전환되었습니다.', 'success');
      location.href = EDEN.url('projects.show', { id: data.id });
    } catch (e) { toast(e.message, 'error'); }
  });
})();
</script>
<?php endif; ?>
