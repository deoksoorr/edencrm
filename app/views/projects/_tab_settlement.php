<?php
/**
 * [입금·정산] 탭(R11) — 예정 금액·누적 입금·미수금·입금 상태·정산 상태 + 입금 원장.
 * 예외 프로젝트: 프로젝트 직접 입금 CRUD(등록/수정/취소/환불) + 예정 금액 수정 + 정산 상태 컨트롤.
 * 일반 프로젝트: 연결 계약 입금 내역 연동 표시(관리는 계약 화면) + 정산 상태 컨트롤.
 * projects/show.php 에서 include (변수 스코프 공유: $p, $paySummary, $projectPayments,
 * $isExceptionLedger, $settleAudit, $payMethods, $payTypeLabels, $canPayment, $contract).
 */
$ps = $paySummary;
$payStatusLabel = AccountingService::PAY_STATUS_LABELS[$ps['pay_status']] ?? $ps['pay_status'];
$payStatusBadge = AccountingService::PAY_STATUS_BADGE[$ps['pay_status']] ?? 'badge-muted';
$settleStatus = (string) ($p['settlement_status'] ?? 'unsettled');
$settleLabel = StatusService::SETTLEMENT_LABELS[$settleStatus] ?? $settleStatus;
$settleBadge = StatusService::SETTLEMENT_BADGE[$settleStatus] ?? 'badge-muted';
$payRowLabels = ['pending' => '대기', 'paid' => '입금완료', 'cancelled' => '취소'];
?>
<div class="card pad">
  <div class="section-head">
    <div class="st"><h2>입금·정산 요약</h2>
      <span class="section-desc">확정 매출은 실제 입금액(순입금)만 반영 — 미입금 제외·환불/취소 차감</span></div>
  </div>
  <div class="kv-row">
    <div class="kv" title="<?= $isExceptionLedger ? '계약 총액 — 예외 프로젝트 직접 입력(수정 이력 보존)·전액 입금 판정 기준' : '연결 계약 총액(VAT 포함)' ?>">
      <div class="kv-label">계약 총액</div>
      <div class="kv-value">
        <?php if ($ps['expected_set'] || !$isExceptionLedger): ?><?= moneyCell($ps['expected']) ?>
        <?php else: ?><span class="badge badge-warn" title="예정 금액이 입력되지 않아 미수금·완납 판정을 할 수 없습니다">미설정</span><?php endif; ?>
        <?php if ($isExceptionLedger && $canPayment): ?>
          <button type="button" class="btn btn-sm btn-ghost" id="btnEditExpected" title="수정 전·후 금액과 수정자가 이력으로 남습니다">수정</button>
        <?php endif; ?>
      </div></div>
    <div class="kv" title="확정(paid) 입금 − 환불 (취소·대기 제외)"><div class="kv-label">누적 입금액(순)</div>
      <div class="kv-value"><?= moneyCell($ps['paid']) ?></div></div>
    <div class="kv" title="예정 금액 − 누적 입금액 (0 미만은 0)"><div class="kv-label">미수금</div>
      <div class="kv-value<?= $ps['outstanding'] > 0 ? ' text-danger' : '' ?>"><?= moneyCell($ps['outstanding']) ?></div></div>
    <div class="kv"><div class="kv-label">입금 상태</div>
      <div class="kv-value"><span class="badge <?= e($payStatusBadge) ?>"><?= e($payStatusLabel) ?></span>
        <?php if ($ps['refund'] > 0): ?><span class="badge badge-danger" title="환불 합계 <?= number_format($ps['refund']) ?>원 — 확정 매출에서 차감됨">환불 발생</span><?php endif; ?>
        <?php if ($ps['pendingCnt'] > 0): ?><span class="badge badge-warn" title="대기(pending) 상태 입금 예정 건 — 집계 미포함"><?= (int) $ps['pendingCnt'] ?>건 대기</span><?php endif; ?>
      </div></div>
    <div class="kv" title="공정 상태와 분리된 정산 축 — 종결 처리해도 미수금이 남으면 정산 완료가 되지 않습니다">
      <div class="kv-label">정산 상태</div>
      <div class="kv-value"><span class="badge <?= e($settleBadge) ?>"><?= e($settleLabel) ?></span></div></div>
  </div>

  <?php if ($canPayment): ?>
  <div class="ta-r mt-8" id="settleActions">
    <?php /* R13: 전액 입금 시 '전액 입금 완료' 자동 처리 — 수동 버튼 제거 */ ?>
    <?php if ($settleStatus !== 'hold'): ?>
      <button type="button" class="btn btn-sm btn-outline" data-settle-action="hold">정산 보류</button>
    <?php endif; ?>
    <?php if ($settleStatus !== 'refunding'): ?>
      <button type="button" class="btn btn-sm btn-outline" data-settle-action="refunding">환불 진행</button>
    <?php endif; ?>
    <?php if (in_array($settleStatus, ['settled', 'hold', 'refunding'], true)): ?>
      <button type="button" class="btn btn-sm btn-ghost" data-settle-action="release" title="수동 정산 상태를 해제하고 입금 기준 자동 계산(미정산/일부 정산)으로 되돌립니다">자동 계산 복귀</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card pad">
  <div class="section-head">
    <div class="st"><h2>입금 내역</h2>
      <?php if (!$isExceptionLedger && $contract): ?>
        <span class="section-desc">연결 계약의 입금 내역 — 등록·수정·취소는 계약 화면에서</span>
      <?php endif; ?>
    </div>
    <?php if ($isExceptionLedger && $canPayment): ?>
      <div>
        <button type="button" class="btn btn-sm btn-primary" id="btnAddProjPayment">+ 입금 등록</button>
        <button type="button" class="btn btn-sm btn-danger" id="btnAddProjRefund" <?= $ps['paid'] > 0 ? '' : 'disabled title="누적 입금이 있어야 환불을 등록할 수 있습니다"' ?>>환불 등록</button>
      </div>
    <?php elseif (!$isExceptionLedger && $contract): ?>
      <a href="<?= e(url('contracts.show', ['id' => $contract['id']])) ?>" class="btn btn-sm btn-outline">계약 보기 (<?= e($contract['contract_no']) ?>)</a>
    <?php endif; ?>
  </div>

  <?php if (!$projectPayments): ?>
    <div class="empty"><div class="empty-title">등록된 입금 내역이 없습니다.</div>
      <?php if ($isExceptionLedger): ?><div class="muted fs-13">예외 프로젝트는 계약 없이도 이 탭에서 직접 입금을 등록할 수 있습니다.</div><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th>구분</th><th class="num">금액</th><th>방식</th><th>예정일</th><th>입금일</th>
          <th>상태</th><th>메모</th><th>등록자</th>
          <?php if ($isExceptionLedger && $canPayment): ?><th>관리</th><?php endif; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($projectPayments as $pm):
            $isRefund = ($pm['kind'] ?? 'payment') === 'refund';
            $isCancelled = $pm['status'] === 'cancelled'; ?>
            <tr<?= $isCancelled ? ' class="row-dim"' : '' ?>>
              <td><?php if ($isRefund): ?><span class="badge badge-danger" title="환불 — 순입금·확정 매출에서 차감">환불</span>
                  <?php else: ?><?= e($payTypeLabels[$pm['pay_type']] ?? $pm['pay_type']) ?><?php endif; ?></td>
              <td class="num mono<?= $isRefund ? ' text-danger' : '' ?>"><?= $isRefund ? '−' : '' ?><?= $isCancelled ? '<s>' . money($pm['amount']) . '</s>' : money($pm['amount']) ?></td>
              <td><?= e($payMethods[$pm['method'] ?? ''] ?? '-') ?></td>
              <td class="nowrap"><?= fmtdate($pm['due_date']) ?></td>
              <td class="nowrap"><?= fmtdate($pm['paid_date']) ?></td>
              <td><span class="badge <?= $pm['status'] === 'paid' ? 'badge-ok' : ($isCancelled ? 'badge-muted' : 'badge-warn') ?>"<?= $isCancelled ? ' title="취소된 내역 — 집계 제외(기록 보존)"' : '' ?>><?= e($payRowLabels[$pm['status']] ?? $pm['status']) ?></span></td>
              <td class="wrap fs-13"><?= e($pm['memo'] ?: '-') ?></td>
              <td class="fs-13"><?= e($pm['created_by_name'] ?? '-') ?></td>
              <?php if ($isExceptionLedger && $canPayment): ?>
                <td class="nowrap">
                  <?php if (!$isCancelled): ?>
                    <button type="button" class="btn btn-sm btn-outline btn-edit-ppm"
                      data-id="<?= (int) $pm['id'] ?>" data-kind="<?= e($pm['kind']) ?>" data-amount="<?= (int) $pm['amount'] ?>"
                      data-method="<?= e($pm['method'] ?? '') ?>" data-paytype="<?= e($pm['pay_type'] ?? '') ?>"
                      data-due="<?= e($pm['due_date'] ?? '') ?>" data-paid="<?= e($pm['paid_date'] ?? '') ?>"
                      data-status="<?= e($pm['status']) ?>" data-memo="<?= e($pm['memo'] ?? '') ?>">입금내역 갱신</button>
                    <button type="button" class="btn btn-sm btn-ghost btn-del-ppm" data-id="<?= (int) $pm['id'] ?>"
                      title="물리 삭제 대신 취소 상태로 전환 — 내역·감사 추적 보존">취소</button>
                  <?php else: ?><span class="muted fs-12">취소됨</span><?php endif; ?>
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
  <div class="section-head"><div class="st"><h2>변경 이력</h2>
    <span class="section-desc">입금 등록·수정·취소, 예정 금액·정산 상태 변경 이력(최근 30건)</span></div></div>
  <?php if (!$settleAudit): ?>
    <div class="empty"><div class="empty-title">변경 이력이 없습니다.</div></div>
  <?php else: ?>
    <div class="timeline">
      <?php foreach ($settleAudit as $a):
        $before = $a['before_json'] ? json_decode($a['before_json'], true) : null;
        $after  = $a['after_json'] ? json_decode($a['after_json'], true) : null;
        $text = $a['action'];
        switch ($a['action']) {
            case 'payment_create':
                $text = (($after['kind'] ?? '') === 'refund' ? '환불 등록: −' : '입금 등록: ')
                      . number_format((float) ($after['amount'] ?? 0)) . '원'
                      . (($after['status'] ?? '') === 'pending' ? ' (대기)' : '');
                break;
            case 'payment_update':
                $text = '입금 수정: ' . number_format((float) ($before['amount'] ?? 0)) . '원 → '
                      . number_format((float) ($after['amount'] ?? 0)) . '원'
                      . (($before['status'] ?? '') !== ($after['status'] ?? '') ? ' · 상태 ' . ($payRowLabels[$before['status'] ?? ''] ?? '-') . '→' . ($payRowLabels[$after['status'] ?? ''] ?? '-') : '');
                break;
            case 'payment_cancel':
                $text = '입금 취소: ' . number_format((float) ($before['amount'] ?? 0)) . '원'
                      . (($before['kind'] ?? '') === 'refund' ? ' (환불 행)' : '');
                break;
            case 'project_expected_amount_change':
                $text = '예정 금액 변경: '
                      . (isset($before['expected_amount']) && $before['expected_amount'] !== null ? number_format((float) $before['expected_amount']) . '원' : '미설정')
                      . ' → '
                      . (isset($after['expected_amount']) && $after['expected_amount'] !== null ? number_format((float) $after['expected_amount']) . '원' : '미설정');
                break;
            case 'project_settlement_change':
                $text = '정산 상태 변경: ' . (StatusService::SETTLEMENT_LABELS[$before['settlement_status'] ?? ''] ?? '-')
                      . ' → ' . (StatusService::SETTLEMENT_LABELS[$after['settlement_status'] ?? ''] ?? '-')
                      . (!empty($after['reason']) ? ' · ' . $after['reason'] : '');
                break;
        }
      ?>
        <div class="timeline-item">
          <div class="timeline-time"><?= e($a['created_at']) ?></div>
          <div class="timeline-body"><?= e($text) ?></div>
          <div class="timeline-tag"><?= e($a['user_name'] ?? '시스템') ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($canPayment): ?>
<script>
(function () {
  'use strict';
  var PROJECT_ID = <?= (int) $p['id'] ?>;
  var IS_EXCEPTION = <?= $isExceptionLedger ? 'true' : 'false' ?>;
  var METHODS = <?= json_encode($payMethods, JSON_UNESCAPED_UNICODE) ?>;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ── 예외 프로젝트: 입금/환불 등록·수정 모달 ──
  function payFormHtml(pm, kind) {
    pm = pm || {};
    var isRefund = kind === 'refund';
    var methodOpts = '<option value="">선택 안 함</option>' + Object.keys(METHODS).map(function (k) {
      return '<option value="' + k + '"' + (pm.method === k ? ' selected' : '') + '>' + METHODS[k] + '</option>';
    }).join('');
    var statusOpts = ['paid', 'pending'].map(function (s) {
      var label = s === 'paid' ? '입금완료' : '대기';
      return '<option value="' + s + '"' + (pm.status === s ? ' selected' : '') + '>' + label + '</option>';
    }).join('');
    return '' +
      '<form data-ajax action-route="projects.payment.save" data-reload class="form">' +
      '<input type="hidden" name="_csrf" value="' + (window.EDEN.CSRF || '') + '">' +
      '<input type="hidden" name="project_id" value="' + PROJECT_ID + '">' +
      '<input type="hidden" name="id" value="' + (pm.id || 0) + '">' +
      '<input type="hidden" name="kind" value="' + (isRefund ? 'refund' : 'payment') + '">' +
      '<div class="form-grid">' +
      '<div class="field"><label class="field-label">금액 <span class="req">*</span></label><input type="text" inputmode="decimal" name="amount" class="input" value="' + esc(pm.amount || '') + '" required></div>' +
      '<div class="field"><label class="field-label">입금 방식</label><select name="method" class="select">' + methodOpts + '</select></div>' +
      (isRefund
        ? '<div class="field"><label class="field-label">유형</label><input type="text" class="input" value="환불" readonly><input type="hidden" name="pay_type" value="refund"></div>'
        : '<div class="field"><label class="field-label">유형 <span class="req">*</span></label><select name="pay_type" class="select" required>' +
            ((pm.pay_type && ['down', 'middle', 'balance'].indexOf(pm.pay_type) === -1)
              ? '<option value="' + esc(pm.pay_type) + '" selected>' + (pm.pay_type === 'etc' ? '기타' : esc(pm.pay_type)) + '</option>'
              : '') +
            ['down', 'middle', 'balance'].map(function (k) {
              var lbl = { down: '계약금', middle: '중도금', balance: '잔금' }[k];
              return '<option value="' + k + '"' + (pm.pay_type === k ? ' selected' : '') + '>' + lbl + '</option>';
            }).join('') + '</select></div>') +
      (isRefund
        ? '<div class="field"><label class="field-label">환불일</label><input type="date" name="paid_date" class="input" value="' + esc(pm.paid || '') + '"></div>'
        : '<div class="field"><label class="field-label">상태</label><select name="status" class="select">' + statusOpts + '</select></div>' +
          '<div class="field"><label class="field-label">예정일</label><input type="date" name="due_date" class="input" value="' + esc(pm.due || '') + '"></div>' +
          '<div class="field"><label class="field-label">입금일</label><input type="date" name="paid_date" class="input" value="' + esc(pm.paid || '') + '" title="상태가 입금완료인데 비워두면 오늘로 기록"></div>') +
      '<div class="field col-span-2"><label class="field-label">증빙·메모</label><input type="text" name="memo" class="input" value="' + esc(pm.memo || '') + '" maxlength="255" placeholder="예: OO은행 이체, 세금계산서 발행"></div>' +
      '</div>' +
      (isRefund ? '<div class="muted fs-13 mt-8">환불은 확정 매출·누적 입금에서 즉시 차감되며, 이력이 보존됩니다.</div>' : '') +
      '<div class="ta-r mt-8"><button type="submit" class="btn btn-primary">저장</button></div>' +
      '</form>';
  }

  var addBtn = document.getElementById('btnAddProjPayment');
  if (addBtn) addBtn.addEventListener('click', function () {
    EDEN.modal({ title: '입금 등록', body: payFormHtml({}, 'payment'), footer: false });
  });
  var refundBtn = document.getElementById('btnAddProjRefund');
  if (refundBtn) refundBtn.addEventListener('click', function () {
    EDEN.modal({ title: '환불 등록', body: payFormHtml({}, 'refund'), footer: false });
  });
  document.querySelectorAll('.btn-edit-ppm').forEach(function (btn) {
    btn.addEventListener('click', function () {
      EDEN.modal({
        title: btn.dataset.kind === 'refund' ? '환불 수정' : '입금 수정',
        body: payFormHtml({
          id: btn.dataset.id, amount: btn.dataset.amount, method: btn.dataset.method, pay_type: btn.dataset.paytype,
          due: btn.dataset.due, paid: btn.dataset.paid, status: btn.dataset.status, memo: btn.dataset.memo,
        }, btn.dataset.kind), footer: false,
      });
    });
  });
  document.querySelectorAll('.btn-del-ppm').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var ok = await EDEN.confirm('이 입금 내역을 취소 처리하시겠습니까? 내역은 삭제되지 않고 \'취소\' 상태로 보존되며, 누적 입금·확정 매출 집계에서 제외됩니다.', { danger: true, okLabel: '취소 처리' });
      if (!ok) return;
      try {
        await api('projects.payment.cancel', { id: btn.dataset.id });
        toast('입금 내역이 취소 처리되었습니다.', 'success');
        location.reload();
      } catch (e) { toast(e.message, 'error'); }
    });
  });

  // ── 예정 금액 수정(예외 전용) ──
  var expBtn = document.getElementById('btnEditExpected');
  if (expBtn) expBtn.addEventListener('click', function () {
    EDEN.modal({
      title: '계약 총액 수정',
      body: '<form data-ajax action-route="projects.expected.save" data-reload class="form">' +
        '<input type="hidden" name="_csrf" value="' + (window.EDEN.CSRF || '') + '">' +
        '<input type="hidden" name="project_id" value="' + PROJECT_ID + '">' +
        '<div class="field"><label class="field-label">계약 총액(원) <span class="req">*</span></label>' +
        '<input type="text" inputmode="decimal" name="expected_amount" class="input" required value="<?= $p['expected_amount'] !== null ? (int) $p['expected_amount'] : '' ?>" placeholder="전액 입금 판정 기준 금액"></div>' +
        '<div class="muted fs-13 mt-8">수정 전·후 금액과 수정자가 변경 이력에 남습니다.</div>' +
        '<div class="ta-r mt-8"><button type="submit" class="btn btn-primary">저장</button></div></form>',
      footer: false,
    });
  });

  // ── 정산 상태 컨트롤 ──
  var LABELS = { hold: '정산 보류', refunding: '환불 진행', release: '자동 계산 복귀' };
  var CONFIRMS = {
    hold: '정산 보류로 전환하시겠습니까? 자동 정산 계산이 중지됩니다.',
    refunding: '환불 진행 상태로 전환하시겠습니까?',
    release: '수동 정산 상태를 해제하고 입금 기준 자동 계산으로 되돌리시겠습니까?',
  };
  document.querySelectorAll('[data-settle-action]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var action = btn.dataset.settleAction;
      var ok = await EDEN.confirm(CONFIRMS[action], { okLabel: LABELS[action] });
      if (!ok) return;
      var reason = '';
      if (action === 'hold' || action === 'refunding') {
        reason = window.prompt('사유를 입력하세요. (변경 이력에 기록됩니다)', '') || '';
      }
      try {
        await api('projects.settlement.update', { project_id: PROJECT_ID, action: action, reason: reason });
        toast('정산 상태가 변경되었습니다.', 'success');
        location.reload();
      } catch (e) { toast(e.message, 'error'); }
    });
  });
})();
</script>
<?php endif; ?>
