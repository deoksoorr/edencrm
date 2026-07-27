<?php
/** @var array $contract @var array $customers @var array $users @var array $quotes @var array $existingPayTypes @var array $statusLabels @var bool $canEditSales */
$isEdit = !empty($contract['id']);
// 비율 표시값: 30.00 → 30, 40.04 → 40.04 (빈값이면 '')
$pctVal = function ($k) use ($contract) {
    $v = $contract[$k] ?? null;
    return ($v === null || $v === '') ? '' : (string) (0 + (float) $v);
};
?>
<div class="page page-narrow">
  <div class="page-head">
    <h1 class="page-title"><?= $isEdit ? '계약 수정 - ' . e($contract['contract_no']) : '계약 등록' ?></h1>
  </div>

  <form class="form" id="contractForm" method="post" action="<?= e(url('contracts.save')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) ($contract['id'] ?? 0) ?>">

    <div class="card pad">
      <div class="section-head"><div class="st"><h2>연결 견적</h2></div></div>
      <div class="form-grid">
        <div class="field col-span-2">
          <label class="field-label">연결 견적 선택 (선택)</label>
          <input type="text" id="quoteSearch" class="input mb-8" placeholder="견적번호·고객명으로 검색">
          <select name="quote_id" id="quoteSelect" class="select">
            <option value="">견적 없이 직접 등록</option>
            <?php foreach (($quotes ?? []) as $q):
              $linkedOther = $q['linked_contract_id'] && (int) $q['linked_contract_id'] !== (int) ($contract['id'] ?? 0);
              $label = $q['quote_no'] . ' · ' . $q['customer_name']
                     . ($q['total_amount'] !== null ? ' · ' . number_format((float) $q['total_amount']) . '원' : '')
                     . ($q['version_no'] ? ' (v' . (int) $q['version_no'] . ')' : '')
                     . ($linkedOther ? ' — 다른 계약에 연결됨' : '');
            ?>
              <option value="<?= (int) $q['id'] ?>" <?= (int) ($contract['quote_id'] ?? 0) === (int) $q['id'] ? 'selected' : '' ?> <?= $linkedOther ? 'disabled' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="field-hint">견적 선택 시 고객·공사명·현장 주소·공사 유형·금액(공급가액/부가세/총액)·특이사항·담당 영업이 자동 입력됩니다.
            불러온 값은 <b>기본값일 뿐 수정 가능</b>하며, 원본 견적은 절대 변경되지 않습니다. 전환 정보(원본 견적액·버전·조정액)는 저장 시 자동 보존됩니다.</div>
        </div>
        <div class="field col-span-2" id="quoteInfoBox" style="display:none"></div>
        <div class="field col-span-2" id="adjustWrap" style="display:none">
          <label class="field-label">할인·증액 사유 (원본 견적액과 계약 총액이 다를 때)</label>
          <input type="text" name="adjust_reason" class="input" value="<?= e($contract['adjust_reason'] ?? '') ?>" placeholder="예: 자재 사양 변경으로 100만원 할인" maxlength="255">
          <div class="field-hint" id="adjustHint"></div>
        </div>
      </div>
    </div>

    <div class="card pad">
      <div class="form-grid">
        <div class="field">
          <label class="field-label">고객 <span class="req">*</span></label>
          <select name="customer_id" class="select" required>
            <option value="">선택하세요</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) ($contract['customer_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?><?= $c['phone'] ? ' (' . e($c['phone']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label">담당 영업</label>
          <select name="sales_user_id" class="select" <?= empty($canEditSales) ? 'disabled' : '' ?>>
            <option value="">선택 안함</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>" <?= (int) ($contract['sales_user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($canEditSales)): ?>
            <div class="field-hint">담당 영업은 견적(리드 담당 → 고객 담당 순)·기존 계약에서 자동 승계되며 <b>관리자만 변경</b>할 수 있습니다.</div>
          <?php endif; ?>
        </div>
        <div class="field">
          <label class="field-label">계약일</label>
          <input type="date" name="contract_date" class="input" value="<?= e($contract['contract_date'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="field-label">상태</label>
          <select name="status" class="select">
            <?php foreach ($statusLabels as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= ($contract['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label">공사명</label>
          <input type="text" name="work_name" class="input" value="<?= e($contract['work_name'] ?? '') ?>" placeholder="예: 대명건설 사옥 외벽 도장" maxlength="150">
          <div class="field-hint">계약 진행 전환 시 자동 생성되는 프로젝트명으로 사용됩니다.</div>
        </div>
        <div class="field">
          <label class="field-label">공사 유형</label>
          <input type="text" name="work_type" class="input" value="<?= e($contract['work_type'] ?? '') ?>" placeholder="예: 아파트외벽, 옥상방수" maxlength="50">
        </div>
        <div class="field col-span-2">
          <label class="field-label">현장 주소</label>
          <input type="text" name="site_address" class="input" value="<?= e($contract['site_address'] ?? '') ?>" maxlength="255">
        </div>
        <div class="field">
          <label class="field-label">계약 총액(VAT 포함) <span class="req">*</span></label>
          <input type="text" inputmode="decimal" name="contract_amount" id="contractAmount" class="input money-input" value="<?= e((string) (int) ($contract['contract_amount'] ?? 0)) ?>" required title="공급가액(VAT 제외) + 부가세">
        </div>
        <div class="field">
          <label class="field-label">하자보증기간</label>
          <input type="text" name="warranty_period" class="input" value="<?= e($contract['warranty_period'] ?? '') ?>" placeholder="예: 2년">
        </div>
        <div class="field">
          <label class="field-label">착공예정일</label>
          <input type="date" name="start_date" class="input" value="<?= e($contract['start_date'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="field-label">준공예정일</label>
          <input type="date" name="end_date" class="input" value="<?= e($contract['end_date'] ?? '') ?>">
        </div>
        <div class="field col-span-2">
          <label class="field-label">특약사항</label>
          <textarea name="special_terms" class="input" rows="2"><?= e($contract['special_terms'] ?? '') ?></textarea>
        </div>
        <div class="field col-span-2">
          <label class="field-label">메모</label>
          <textarea name="memo" class="input" rows="2"><?= e($contract['memo'] ?? '') ?></textarea>
        </div>
        <div class="field col-span-2">
          <label class="field-label">계약서 파일 (선택)</label>
          <input type="file" name="contract_file" class="input">
          <div class="field-hint">PDF, 이미지, 오피스 문서 등 (최대 10MB)</div>
        </div>
      </div>
    </div>

    <div class="card pad">
      <div class="section-head"><div class="st"><h2>대금 지급 계획 — 계약금·중도금·잔금 (저장 시 입금 예정행 자동 동기화)</h2></div></div>
      <div class="field-hint mb-8">
        분할 지급 계산 기준: <b>계약 총액(VAT 포함)</b> · 비율 합계는 정확히 100%여야 합니다.
        금액 = 총액 × 비율 반올림이며, 반올림 보정은 잔금에 귀속됩니다(세 금액 합 = 계약 총액).
      </div>
      <div class="form-grid-3">
        <?php foreach (['down' => '계약금', 'middle' => '중도금', 'balance' => '잔금'] as $type => $label): ?>
          <div class="field">
            <label class="field-label"><?= e($label) ?> 비율(%)</label>
            <input type="number" name="<?= $type ?>_pct" id="pct_<?= $type ?>" class="input" min="0" max="100" step="0.01" value="<?= e($pctVal($type . '_pct')) ?>">
            <div class="field-hint split-preview" id="preview_<?= $type ?>">-</div>
            <label class="field-label mt-8"><?= e($label) ?> 금액(원)</label>
            <input type="text" inputmode="decimal" name="<?= $type ?>_payment" id="amt_<?= $type ?>" class="input money-input"
                   value="<?= e((string) (int) ($contract[$type . '_payment'] ?? 0)) ?>" <?= $type === 'balance' ? 'readonly title="잔금 = 계약 총액 − 계약금 − 중도금 (반올림 보정 귀속, 자동 계산)"' : '' ?>>
            <?php if (in_array($type, $existingPayTypes, true)): ?>
              <div class="field-hint">입금 항목이 이미 있습니다 — 저장 시 <b>대기(pending) 예정행만</b> 새 금액으로 동기화되고 입금완료·환불 내역은 변경되지 않습니다.</div>
            <?php else: ?>
              <input type="date" name="<?= $type ?>_due_date" class="input mt-8" placeholder="예정일">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div id="splitError" class="field-hint text-danger mt-8" style="display:none"></div>
    </div>

    <div class="page-actions">
      <a href="<?= e(url($isEdit ? 'contracts.show' : 'contracts.index', $isEdit ? ['id' => $contract['id']] : [])) ?>" class="btn btn-outline">취소</a>
      <button type="submit" class="btn btn-primary">저장</button>
    </div>
  </form>
</div>

<script>
// 이 스크립트는 본문 중간에 출력되어 app.js(body 끝)보다 먼저 실행된다 — init 의 api() 즉시 호출이
// ReferenceError 가 되지 않도록 DOMContentLoaded 로 지연(customers/form.php 와 동일 패턴).
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var totalEl = document.getElementById('contractAmount');
  var pct = { down: document.getElementById('pct_down'), middle: document.getElementById('pct_middle'), balance: document.getElementById('pct_balance') };
  var amt = { down: document.getElementById('amt_down'), middle: document.getElementById('amt_middle'), balance: document.getElementById('amt_balance') };
  var LABELS = { down: '계약금', middle: '중도금', balance: '잔금' };
  var KEYS = ['down', 'middle', 'balance'];

  function num(v) { var n = parseFloat(String(v).replace(/,/g, '')); return isFinite(n) ? n : 0; }
  function fmt(n) { return Number(n).toLocaleString('ko-KR'); }
  function total() { return Math.round(num(totalEl.value)); }
  function pcts() { return { down: num(pct.down.value), middle: num(pct.middle.value), balance: num(pct.balance.value) }; }

  // 공통 산식(서버 AccountingService::splitPayments 와 동일): 반올림 보정은 잔금 귀속(세 금액 합 = 총액)
  function computeSplit(t, p) {
    var down = Math.round(t * p.down / 100);
    var middle = Math.round(t * p.middle / 100);
    return { down: down, middle: middle, balance: t - down - middle };
  }

  function recalc(updateAmounts) {
    var t = total(), p = pcts();
    var sum = Math.round((p.down + p.middle + p.balance) * 100) / 100;
    var err = '';
    if (KEYS.some(function (k) { return p[k] < 0 || p[k] > 100; })) {
      err = '분할 비율은 0~100 사이여야 합니다.';
    } else if (Math.abs(sum - 100) > 0.001) {
      err = '비율 합계가 100%가 아닙니다 (현재 ' + sum + '%). 저장이 차단됩니다.';
    }
    var box = document.getElementById('splitError');
    box.style.display = err ? '' : 'none';
    box.textContent = err;
    if (!err) {
      var s = computeSplit(t, p);
      KEYS.forEach(function (k) {
        if (updateAmounts !== false) amt[k].value = s[k];
        document.getElementById('preview_' + k).textContent = LABELS[k] + ' ' + p[k] + '% → ' + fmt(s[k]) + '원';
      });
    } else {
      KEYS.forEach(function (k) {
        document.getElementById('preview_' + k).textContent = LABELS[k] + ' ' + (p[k] || 0) + '% → -';
      });
    }
    return !err;
  }

  KEYS.forEach(function (k) { pct[k].addEventListener('input', function () { recalc(true); }); });
  totalEl.addEventListener('input', function () { recalc(true); updateAdjust(); });

  // 금액 직접 수정(계약금·중도금) → 비율 재동기화(소수 2자리) → 재계산(잔금 보정 귀속). 불일치는 서버에서도 차단.
  ['down', 'middle'].forEach(function (k) {
    amt[k].addEventListener('change', function () {
      var t = total();
      if (t <= 0) return;
      var p = Math.round(num(amt[k].value) / t * 10000) / 100;
      pct[k].value = Math.max(0, Math.min(100, p));
      var rest = Math.round((100 - num(pct.down.value) - num(pct.middle.value)) * 100) / 100;
      if (rest >= 0 && rest <= 100) pct.balance.value = rest;
      recalc(true);
    });
  });

  document.getElementById('contractForm').addEventListener('submit', function (e) {
    if (!recalc(true)) {
      e.preventDefault();
      toast('분할 지급 비율 합계가 정확히 100%여야 저장할 수 있습니다.', 'error');
    }
  });

  // ── 연결 견적 선택(검색 + 자동 입력) ──
  var quoteSelect = document.getElementById('quoteSelect');
  var quoteSearch = document.getElementById('quoteSearch');
  var infoBox = document.getElementById('quoteInfoBox');
  var adjustWrap = document.getElementById('adjustWrap');
  // 수정 화면은 저장된 '원본 견적액(전환 시점 보존)'을 기준으로 조정액을 표시한다
  var originalQuoteAmount = <?= isset($contract['original_quote_amount']) && $contract['original_quote_amount'] !== null ? (int) $contract['original_quote_amount'] : 'null' ?>;

  quoteSearch.addEventListener('input', function () {
    var kw = this.value.trim().toLowerCase();
    Array.prototype.forEach.call(quoteSelect.options, function (op) {
      if (!op.value) return;
      op.hidden = kw !== '' && op.textContent.toLowerCase().indexOf(kw) === -1;
    });
  });

  function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  function renderQuoteInfo(d) {
    var html = '<div class="field-hint">원본 견적액 <b>' + fmt(d.total_amount) + '원</b>'
      + (d.version_no ? ' · 견적 버전 v' + d.version_no : '')
      + ' · 공급가액(VAT 제외) ' + fmt(d.supply_amount) + '원 · 부가세 ' + fmt(d.vat_amount) + '원';
    if (d.attachments && d.attachments.length) {
      html += '<br>견적 첨부 참조: ' + d.attachments.map(function (f) {
        return '<a href="' + EDEN.url('files.download', { id: f.id }) + '" target="_blank">' + escapeHtml(f.original_name) + '</a>';
      }).join(' · ');
    }
    html += '<br>원본 견적은 변경되지 않습니다 — 아래 값은 수정 가능한 기본값입니다.</div>';
    infoBox.innerHTML = html;
    infoBox.style.display = '';
  }

  function updateAdjust() {
    if (originalQuoteAmount === null) { adjustWrap.style.display = 'none'; return; }
    adjustWrap.style.display = '';
    var diff = total() - originalQuoteAmount;
    document.getElementById('adjustHint').textContent =
      diff === 0 ? '원본 견적액과 동일합니다 (조정 없음).'
        : (diff < 0 ? '할인 ' + fmt(-diff) + '원' : '증액 ' + fmt(diff) + '원') + ' — 저장 시 자동 계산·보존됩니다.';
  }

  function setVal(name, v) {
    var el = document.querySelector('[name="' + name + '"]');
    if (el && v !== undefined && v !== null) el.value = v;
  }

  quoteSelect.addEventListener('change', async function () {
    if (!this.value) {
      infoBox.style.display = 'none';
      originalQuoteAmount = null;
      updateAdjust();
      return;
    }
    try {
      var d = await api('contracts.quotedata', { id: this.value });
      setVal('customer_id', d.customer_id);
      setVal('work_name', d.work_name);
      setVal('site_address', d.site_address || '');
      setVal('work_type', d.work_type || '');
      if (d.special_terms) setVal('special_terms', d.special_terms);
      if (d.memo) setVal('memo', d.memo);
      if (d.sales_user_id) setVal('sales_user_id', d.sales_user_id);
      totalEl.value = d.total_amount;
      renderQuoteInfo(d);
      originalQuoteAmount = d.total_amount;
      updateAdjust();
      recalc(true);
      toast('견적 정보를 불러왔습니다. 값은 수정 가능하며 원본 견적은 변경되지 않습니다.', 'success');
    } catch (e) { toast(e.message, 'error'); }
  });

  // ── 초기화 ──
  (function init() {
    var t = total();
    var noPct = pct.down.value === '' && pct.middle.value === '' && pct.balance.value === '';
    if (noPct) {
      var d = num(amt.down.value), m = num(amt.middle.value), b = num(amt.balance.value);
      if (t > 0 && d + m + b > 0) {
        // 저장된 금액에서 비율 역산(마이그레이션 이전 데이터 호환) — 보정은 잔금 귀속
        var dp = Math.round(d / t * 10000) / 100;
        var mp = Math.round(m / t * 10000) / 100;
        pct.down.value = dp;
        pct.middle.value = mp;
        pct.balance.value = Math.round((100 - dp - mp) * 100) / 100;
      } else {
        pct.down.value = 0;
        pct.middle.value = 0;
        pct.balance.value = 100;
      }
    }
    recalc(true);
    // 수정 화면: 연결 견적의 참조 정보만 표시(필드 자동 덮어쓰기는 선택 변경 시에만)
    if (quoteSelect.value) {
      api('contracts.quotedata', { id: quoteSelect.value }).then(renderQuoteInfo).catch(function () {});
    }
    updateAdjust();
  })();
});
</script>
