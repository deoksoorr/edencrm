<?php
/** @var array $contract @var array $payments @var float $paidSum @var float $receivable @var int $supplyAmount @var int $vatAmount @var ?array $project
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
      <?php if (can('contract.manage') && $contract['status'] !== 'terminated'): ?>
        <a href="<?= e(url('contracts.form', ['id' => $contract['id']])) ?>" class="btn btn-outline">수정</a>
      <?php endif; ?>
      <?php if ($project): ?>
        <a href="<?= e(url('projects.show', ['id' => $project['id']])) ?>" class="btn btn-outline">프로젝트 보기 (<?= e($project['project_no']) ?>)</a>
      <?php elseif (can('project.manage') && !in_array($contract['status'], ['terminated', 'cancelled'], true)): ?>
        <button type="button" class="btn btn-primary" id="btnToProject">프로젝트로 전환</button>
      <?php endif; ?>
      <?php if (can('contract.manage') && !in_array($contract['status'], ['terminated', 'cancelled'], true)): ?>
        <button type="button" class="btn btn-danger" id="btnTerminate">계약 파기</button>
      <?php endif; ?>
      <?php if (can('contract.manage')): ?>
        <form method="post" action="<?= e(url('contracts.delete')) ?>" style="display:inline"
              onsubmit="return confirm('이 계약을 휴지통으로 이동합니다.\n연결된 입금 내역이 매출·미수금 집계에서 제외됩니다(휴지통에서 복원하면 그대로 돌아옵니다).\n진행할까요?');"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $contract['id'] ?>">
          <button type="submit" class="btn btn-danger">삭제</button></form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card pad">
    <div class="section-head"><div class="st"><h2>계약 요약</h2></div></div>
    <div class="kv-row">
      <div class="kv" title="공급가액(VAT 제외) + 부가세 · VAT 포함"><div class="kv-label">계약 총액(VAT 포함)</div><div class="kv-value"><?= moneyCell($contract['contract_amount']) ?></div></div>
      <div class="kv" title="확정 매출(공급가액) 집계의 기준 금액 · VAT 제외"><div class="kv-label">공급가액(VAT 제외)</div><div class="kv-value"><?= moneyCell($supplyAmount) ?></div></div>
      <div class="kv" title="계약 총액 − 공급가액"><div class="kv-label">부가세</div><div class="kv-value"><?= moneyCell($vatAmount) ?></div></div>
      <div class="kv" title="입금 완료된 금액 합 · 현금 기준 · VAT 포함<?= $refundSum > 0 ? ' · 환불 차감 전 정상 입금' : '' ?>"><div class="kv-label">입금 총액(VAT 포함)</div><div class="kv-value text-ok"><?= moneyCell($paidSum) ?></div></div>
      <?php if ($refundSum > 0): ?>
        <div class="kv" title="환불(kind=refund) 합 — 별도 축 · 순입금 = 입금 총액 − 환불"><div class="kv-label">환불 총액</div><div class="kv-value text-danger">−<?= moneyCell($refundSum) ?></div></div>
        <div class="kv" title="정상 입금 − 환불 · 현금 기준 · VAT 포함"><div class="kv-label">순입금</div><div class="kv-value"><?= moneyCell($netPaid) ?></div></div>
      <?php endif; ?>
      <?php if (in_array($contract['status'], ['terminated', 'cancelled'], true)): ?>
        <div class="kv" title="파기/취소 계약은 일반 미수금 집계에서 제외 — 위약금·정산은 파기 정보의 별도 축으로 관리"><div class="kv-label">미수금</div><div class="kv-value muted">제외(파기/취소)</div></div>
      <?php elseif ($contract['status'] === 'draft'): ?>
        <div class="kv" title="작성중(체결 전) 계약은 미수금 집계에서 제외 — 계약 진행 전환 후 집계됩니다"><div class="kv-label">미수금</div><div class="kv-value muted">제외(작성중)</div></div>
      <?php else: ?>
        <div class="kv" title="max(0, 계약 총액(VAT 포함) − 순입금(VAT 포함))"><div class="kv-label">미수금</div><div class="kv-value<?= $receivable > 0 ? ' text-danger' : '' ?>"><?= moneyCell($receivable) ?></div></div>
      <?php endif; ?>
    </div>
    <div class="muted mt-8 fs-12">확정 매출(공급가액)은 완납(순입금 ≥ 계약 총액) 시 공급가액(VAT 제외) 기준으로 인식됩니다 — 입금 총액(VAT 포함)과는 기준이 다릅니다.</div>
    <div class="dl mt-16">
      <dt>계약일</dt><dd><?= fmtdate($contract['contract_date']) ?></dd>
      <dt>계약금/중도금/잔금</dt>
      <dd title="분할 지급 계산 기준: 계약 총액(VAT 포함) · 반올림 보정은 잔금 귀속">
        <?php $pctTag = fn($k) => $contract[$k] !== null ? ' (' . (0 + (float) $contract[$k]) . '%)' : ''; ?>
        <?= moneyCell($contract['down_payment']) ?><?= $pctTag('down_pct') ?> /
        <?= moneyCell($contract['middle_payment']) ?><?= $pctTag('middle_pct') ?> /
        <?= moneyCell($contract['balance_payment']) ?><?= $pctTag('balance_pct') ?>
      </dd>
      <?php if (!empty($contract['work_name'])): ?><dt>공사명</dt><dd><?= e($contract['work_name']) ?></dd><?php endif; ?>
      <?php if (!empty($contract['work_type'])): ?><dt>공사 유형</dt><dd><?= e($contract['work_type']) ?></dd><?php endif; ?>
      <?php if (!empty($contract['site_address'])): ?><dt>현장 주소</dt><dd><?= e($contract['site_address']) ?></dd><?php endif; ?>
      <dt>착공예정</dt><dd><?= fmtdate($contract['start_date']) ?></dd>
      <dt>준공예정</dt><dd><?= fmtdate($contract['end_date']) ?></dd>
      <dt>하자보증기간</dt><dd><?= e($contract['warranty_period'] ?: '-') ?></dd>
      <?php if (!empty($contract['special_terms'])): ?>
        <dt>특약사항</dt><dd class="prewrap"><?= e($contract['special_terms']) ?></dd>
      <?php endif; ?>
      <?php if (!empty($contract['memo'])): ?>
        <dt>메모</dt><dd class="prewrap"><?= e($contract['memo']) ?></dd>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($contract['original_quote_amount'] !== null): ?>
  <div class="card pad">
    <div class="section-head"><div class="st"><h2>견적 전환 정보 (원본 견적 불변)</h2></div></div>
    <?php $adj = (int) $contract['adjust_amount']; ?>
    <div class="kv-row">
      <div class="kv" title="전환 시점 견적 총액(VAT 포함) — 이후 견적이 수정되어도 이 값은 보존됩니다">
        <div class="kv-label">원본 견적액<?= $contract['quote_version_no'] ? ' (v' . (int) $contract['quote_version_no'] . ')' : '' ?></div>
        <div class="kv-value"><?= moneyCell($contract['original_quote_amount']) ?></div>
      </div>
      <div class="kv" title="최종 계약액 − 원본 견적액 (할인 음수 / 증액 양수)">
        <div class="kv-label"><?= $adj < 0 ? '할인' : ($adj > 0 ? '증액' : '조정 없음') ?></div>
        <div class="kv-value<?= $adj < 0 ? ' text-danger' : ($adj > 0 ? ' text-ok' : '') ?>">
          <?= $adj === 0 ? '0원' : ($adj < 0 ? '−' : '+') . number_format(abs($adj)) . '원' ?>
        </div>
      </div>
      <div class="kv" title="계약 총액(VAT 포함)"><div class="kv-label">최종 계약액</div><div class="kv-value"><?= moneyCell($contract['contract_amount']) ?></div></div>
    </div>
    <dl class="dl mt-16">
      <?php if (!empty($contract['adjust_reason'])): ?>
        <dt>할인·증액 사유</dt><dd><?= e($contract['adjust_reason']) ?></dd>
      <?php elseif ($adj !== 0): ?>
        <dt>할인·증액 사유</dt><dd class="muted">미입력</dd>
      <?php endif; ?>
      <dt>전환 일시</dt><dd><?= e($contract['converted_at'] ?? '-') ?><?= !empty($contract['converted_by_name']) ? ' · ' . e($contract['converted_by_name']) : '' ?></dd>
    </dl>
  </div>
  <?php endif; ?>

  <?php if ($termination): ?>
  <div class="card pad">
    <div class="section-head"><div class="st"><h2>계약 파기 정보</h2></div></div>
    <div class="kv-row">
      <div class="kv" title="파기 시 환불된 금액 — 입금 내역에 환불 행으로 기록"><div class="kv-label">환불 금액</div><div class="kv-value"><?= moneyCell($termination['refund_amount']) ?></div></div>
      <div class="kv" title="위약금 수입 — 별도 축(확정 매출(공급가액) 아님)"><div class="kv-label">위약금</div><div class="kv-value"><?= moneyCell($termination['penalty_amount']) ?></div></div>
      <div class="kv" title="파기 시점 정산 금액 — 별도 축"><div class="kv-label">정산 금액</div><div class="kv-value"><?= moneyCell($termination['settlement_amount']) ?></div></div>
    </div>
    <div class="muted mt-8 fs-12">파기 계약은 계약 완료 수치·일반 미수금에서 제외되며, 환불·위약금·정산 금액은 별도 축으로 관리됩니다.</div>
    <dl class="dl mt-16">
      <dt>파기일</dt><dd><?= fmtdate($termination['terminated_date']) ?></dd>
      <dt>파기 사유</dt><dd class="prewrap"><?= e($termination['reason']) ?></dd>
      <dt>처리자</dt><dd><?= e($termination['processed_by_name'] ?? '-') ?></dd>
      <?php if ($termination['project_action']): ?>
        <dt>프로젝트 처리</dt><dd><?= e(['cancel' => '프로젝트 취소', 'terminate' => '프로젝트 파기(별도 정산)', 'pause' => '프로젝트 중단', 'keep' => '프로젝트 유지'][$termination['project_action']] ?? $termination['project_action']) ?></dd>
      <?php endif; ?>
      <?php if (!empty($termination['memo'])): ?>
        <dt>메모</dt><dd class="prewrap"><?= e($termination['memo']) ?></dd>
      <?php endif; ?>
      <?php if ($terminationFiles): ?>
        <dt>첨부 파일</dt>
        <dd>
          <?php foreach ($terminationFiles as $tf): ?>
            <a href="<?= e(url('files.download', ['id' => $tf['id']])) ?>"><?= e($tf['original_name']) ?></a>
            <span class="muted">(<?= number_format((int) $tf['size'] / 1024) ?> KB)</span><br>
          <?php endforeach; ?>
        </dd>
      <?php endif; ?>
    </dl>
  </div>
  <?php endif; ?>

  <div class="card pad">
    <div class="section-head">
      <div class="st"><h2>입금 관리</h2></div>
      <?php if (can('payment.manage')): ?>
        <button type="button" class="btn btn-sm btn-primary" id="btnAddPayment">+ 입금 등록</button>
      <?php endif; ?>
    </div>
    <?php if (!$payments): ?>
      <div class="empty"><div class="empty-title">등록된 입금 내역이 없습니다.</div></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>구분</th><th class="num">금액</th><th>예정일</th><th>입금일</th><th>상태</th><?php if (can('payment.manage')): ?><th>관리</th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach ($payments as $pm): $isRefund = ($pm['kind'] ?? 'payment') === 'refund'; $isCancelled = $pm['status'] === 'cancelled'; ?>
              <tr<?= $isCancelled ? ' class="row-dim"' : '' ?>>
                <td><?php if ($isRefund): ?><span class="badge badge-danger" title="환불 — 순입금·미수금 계산에서 차감">환불</span><?php else: ?><?= e($payTypeLabels[$pm['pay_type']] ?? $pm['pay_type']) ?><?php endif; ?></td>
                <td class="num mono<?= $isRefund ? ' text-danger' : '' ?>"><?= $isRefund ? '−' : '' ?><?= $isCancelled ? '<s>' . money($pm['amount']) . '</s>' : money($pm['amount']) ?></td>
                <td class="nowrap"><?= fmtdate($pm['due_date']) ?></td>
                <td class="nowrap"><?= fmtdate($pm['paid_date']) ?></td>
                <td><span class="badge <?= $pm['status'] === 'paid' ? 'badge-ok' : ($isCancelled ? 'badge-muted' : 'badge-warn') ?>"<?= $isCancelled ? ' title="취소된 내역 — 입금 총액·순입금·미수금 집계에서 제외(기록 보존)"' : '' ?>><?= e($payRowLabels[$pm['status']] ?? $pm['status']) ?></span></td>
                <?php if (can('payment.manage')): ?>
                  <td>
                    <?php if (!$isCancelled): ?>
                    <button type="button" class="btn btn-sm btn-outline btn-edit-pm"
                      data-id="<?= (int) $pm['id'] ?>" data-type="<?= e($pm['pay_type']) ?>" data-amount="<?= (int) $pm['amount'] ?>"
                      data-due="<?= e($pm['due_date'] ?? '') ?>" data-paid="<?= e($pm['paid_date'] ?? '') ?>"
                      data-status="<?= e($pm['status']) ?>" data-memo="<?= e($pm['memo'] ?? '') ?>">입금내역 갱신</button>
                    <button type="button" class="btn btn-sm btn-ghost btn-del-pm" data-id="<?= (int) $pm['id'] ?>" title="물리 삭제 대신 취소 상태로 전환 — 내역·감사 추적 보존">취소</button>
                    <?php else: ?>
                    <span class="muted fs-12">취소됨</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card pad">
    <div class="section-head"><div class="st"><h2>상태 이력</h2></div></div>
    <?php if (!$statusHistory): ?>
      <div class="empty"><div class="empty-title">상태 변경 이력이 없습니다.</div></div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($statusHistory as $h): ?>
          <div class="timeline-item">
            <div class="timeline-time"><?= e($h['changed_at']) ?></div>
            <div class="timeline-body">
              <?= e($h['from_status'] !== null ? ($statusLabels[$h['from_status']] ?? $h['from_status']) : '(등록)') ?>
              → <span class="badge <?= e($statusBadge[$h['to_status']] ?? 'badge-muted') ?>"><?= e($statusLabels[$h['to_status']] ?? $h['to_status']) ?></span>
              <?php if ($h['reason']): ?><span class="muted"> · <?= e($h['reason']) ?></span><?php endif; ?>
            </div>
            <div class="timeline-tag"><?= e($h['changed_by_name'] ?? '-') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('contract.manage') && !in_array($contract['status'], ['terminated', 'cancelled'], true)): ?>
<!-- 계약 파기 폼(숨김) — 파기일/사유/처리자(자동)/환불·위약금·정산/메모/첨부 + 프로젝트 처리 선택 -->
<div id="terminateFormWrap" style="display:none">
  <form method="post" action="<?= e(url('contracts.terminate')) ?>" enctype="multipart/form-data" class="form"
        onsubmit="return confirm('이 계약을 파기 처리하시겠습니까? 파기 후에는 되돌릴 수 없으며 입금·이력·파일은 보존됩니다.');">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $contract['id'] ?>">
    <div class="form-grid">
      <div class="field"><label class="field-label">파기일 <span class="req">*</span></label>
        <input type="date" name="terminated_date" class="input" value="<?= e(date('Y-m-d')) ?>" required></div>
      <div class="field"><label class="field-label">처리자</label>
        <input type="text" class="input" value="<?= e(auth_user()['name'] ?? '') ?>" disabled title="로그인 사용자로 자동 기록"></div>
      <div class="field col-span-2"><label class="field-label">파기 사유 <span class="req">*</span></label>
        <input type="text" name="reason" class="input" required placeholder="예: 발주처 사정으로 공사 취소"></div>
      <div class="field"><label class="field-label">환불 금액</label>
        <input type="text" inputmode="decimal" name="refund_amount" class="input" value="0" title="순입금(<?= number_format($netPaid) ?>원) 한도 — 입금 내역에 환불 행으로 기록"></div>
      <div class="field"><label class="field-label">위약금</label>
        <input type="text" inputmode="decimal" name="penalty_amount" class="input" value="0" title="별도 축 — 확정 매출(공급가액)에 포함되지 않음"></div>
      <div class="field"><label class="field-label">정산 금액</label>
        <input type="text" inputmode="decimal" name="settlement_amount" class="input" value="0" title="파기 시점 정산 금액 — 별도 축"></div>
      <div class="field"><label class="field-label">첨부 파일</label>
        <input type="file" name="termination_file" class="input"></div>
      <div class="field col-span-2"><label class="field-label">메모</label>
        <textarea name="memo" class="input" rows="2"></textarea></div>
      <?php if ($project): ?>
        <?php
          // 현재 프로젝트 상태에서 허용되는 처리만 선택 가능(전이 규칙: 취소=착공 전, 파기/중단=진행 중)
          $paDone = in_array($project['status'], ['cancelled', 'terminated'], true);
          $paAllowed = [
            'cancel'    => !$paDone && StatusService::projectTransitionAllowed($project['status'], 'cancelled'),
            'terminate' => !$paDone && StatusService::projectTransitionAllowed($project['status'], 'terminated'),
            'pause'     => !$paDone && StatusService::projectTransitionAllowed($project['status'], 'paused'),
            'keep'      => true,
          ];
        ?>
        <div class="field col-span-2">
          <label class="field-label">연결 프로젝트 처리 <span class="req">*</span> — <?= e($project['project_no']) ?> (현재: <?= e($projectStatuses[$project['status']] ?? $project['status']) ?>)</label>
          <div class="flex" style="flex-direction:column;gap:6px;font-size:13px">
            <label><input type="radio" name="project_action" value="cancel" required <?= $paAllowed['cancel'] ? '' : 'disabled' ?>> 프로젝트 취소 — 착공 전 철회<?= $paAllowed['cancel'] ? '' : ' <span class="muted">(현재 상태에서 불가)</span>' ?></label>
            <label><input type="radio" name="project_action" value="terminate" <?= $paAllowed['terminate'] ? '' : 'disabled' ?>> 프로젝트 파기(별도 정산) — 진행 중 계약관계 종료<?= $paAllowed['terminate'] ? '' : ' <span class="muted">(현재 상태에서 불가)</span>' ?></label>
            <label><input type="radio" name="project_action" value="pause" <?= $paAllowed['pause'] ? '' : 'disabled' ?>> 프로젝트 중단 — 재개 가능 일시 정지<?= $paAllowed['pause'] ? '' : ' <span class="muted">(현재 상태에서 불가)</span>' ?></label>
            <label><input type="radio" name="project_action" value="keep" required> 프로젝트 유지 — 상태 변경 없이 별도 진행</label>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <div class="ta-r mt-8"><button type="submit" class="btn btn-danger">계약 파기 처리</button></div>
  </form>
</div>
<script>
(function () {
  var btn = document.getElementById('btnTerminate');
  var wrap = document.getElementById('terminateFormWrap');
  if (!btn || !wrap) return;
  btn.addEventListener('click', function () {
    EDEN.modal({ title: '계약 파기 — <?= e($contract['contract_no']) ?>', body: wrap, footer: false });
    wrap.style.display = '';
  });
})();
</script>
<?php endif; ?>

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
      '<div class="ta-r mt-8"><button type="submit" class="btn btn-primary">저장</button></div>' +
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
      var ok = await EDEN.confirm('이 입금 내역을 취소 처리하시겠습니까? 내역은 삭제되지 않고 \'취소\' 상태로 보존되며, 입금 총액·미수금 집계에서 제외됩니다.', { danger: true, okLabel: '취소 처리' });
      if (!ok) return;
      try {
        await api('payments.delete', { id: btn.dataset.id });
        toast('입금 내역이 취소 처리되었습니다.', 'success');
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
