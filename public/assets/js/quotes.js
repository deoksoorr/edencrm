/* 견적 항목 동적 추가·삭제 + 실시간 합계 계산 (quotes/form.php 전용) */
(function () {
  'use strict';

  const body = document.getElementById('itemsBody');
  if (!body) return;

  const vatRate = Number(window.__VAT_RATE__ || 10);
  let rowSeq = 0;

  function numVal(v) {
    if (v === null || v === undefined || v === '') return 0;
    const n = parseFloat(String(v).replace(/,/g, ''));
    return isNaN(n) ? 0 : n;
  }

  function fmt(n) {
    return Math.round(n).toLocaleString('ko-KR');
  }

  function makeRow(data) {
    data = data || {};
    const idx = rowSeq++;
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML =
      '<td><input type="text" class="input" name="items[' + idx + '][name]" maxlength="100" value="' + escapeAttr(data.name || '') + '"></td>' +
      '<td><input type="number" step="0.01" class="input num-input f-area" name="items[' + idx + '][area]" value="' + escapeAttr(data.area ?? '') + '"></td>' +
      '<td><input type="number" step="0.01" class="input num-input f-qty" name="items[' + idx + '][qty]" value="' + escapeAttr(data.qty ?? 1) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input f-price" name="items[' + idx + '][unit_price]" value="' + escapeAttr(data.unit_price ?? 0) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input f-cost" name="items[' + idx + '][material_cost]" value="' + escapeAttr(data.material_cost ?? 0) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input f-cost" name="items[' + idx + '][labor_cost]" value="' + escapeAttr(data.labor_cost ?? 0) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input f-cost" name="items[' + idx + '][equipment_cost]" value="' + escapeAttr(data.equipment_cost ?? 0) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input f-cost" name="items[' + idx + '][outsourcing_cost]" value="' + escapeAttr(data.outsourcing_cost ?? 0) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input f-cost" name="items[' + idx + '][etc_cost]" value="' + escapeAttr(data.etc_cost ?? 0) + '"></td>' +
      '<td class="num row-amount">0</td>' +
      '<td><button type="button" class="btn btn-sm btn-ghost btn-remove-row" title="삭제">✕</button></td>';
    return tr;
  }

  function escapeAttr(v) {
    return String(v).replace(/"/g, '&quot;');
  }

  function recalcRow(tr) {
    const area = numVal(tr.querySelector('.f-area')?.value);
    const qty = numVal(tr.querySelector('.f-qty')?.value);
    const price = numVal(tr.querySelector('.f-price')?.value);
    // 기본금액 = 단가(원/㎡) × 면적 × 수량. 면적 미입력(개수 기준)이면 단가 × 수량.
    let amount = (area > 0 ? area : 1) * qty * price;
    // R5 확정 산식: 금액 = 기본금액 + 재료비+인건비+장비비+외주비+기타비 (표시용 — 서버 parseItems 와 동일 규칙, 서버가 최종 권위)
    tr.querySelectorAll('.f-cost').forEach((el) => { amount += numVal(el.value); });
    amount = Math.round(amount);
    tr.querySelector('.row-amount').textContent = fmt(amount);
    return amount;
  }

  function recalcAll() {
    let subtotal = 0;
    body.querySelectorAll('.item-row').forEach((tr) => { subtotal += recalcRow(tr); });
    subtotal = Math.round(subtotal);
    const vat = Math.round(subtotal * vatRate / 100);
    const discount = numVal(document.getElementById('inputDiscount')?.value);
    const total = subtotal + vat - discount;
    document.getElementById('sumSubtotal').textContent = fmt(subtotal);
    document.getElementById('sumVat').textContent = fmt(vat);
    document.getElementById('sumTotal').textContent = fmt(total);
  }

  function addRow(data) {
    const tr = makeRow(data);
    body.appendChild(tr);
    recalcAll();
  }

  // 초기 항목 로드(수정 시) 또는 빈 행 1개
  const initial = Array.isArray(window.__QUOTE_ITEMS__) ? window.__QUOTE_ITEMS__ : [];
  if (initial.length) {
    initial.forEach(addRow);
  } else {
    addRow({});
  }

  document.getElementById('btnAddItem')?.addEventListener('click', () => addRow({}));

  body.addEventListener('click', (e) => {
    if (e.target.closest('.btn-remove-row')) {
      const rows = body.querySelectorAll('.item-row');
      if (rows.length <= 1) {
        toast('최소 1개의 항목이 필요합니다.', 'warn');
        return;
      }
      e.target.closest('tr').remove();
      recalcAll();
    }
  });

  body.addEventListener('input', (e) => {
    if (e.target.matches('.f-area, .f-qty, .f-price, .f-cost')) recalcAll();
  });
  document.getElementById('inputDiscount')?.addEventListener('input', recalcAll);
})();

/* 고객 선택 → 영업기회 서버 재조회(quotes.leads) — 서버 쿼리 단일 출처(프론트 필터링 금지) */
(function () {
  'use strict';
  const customerSel = document.getElementById('customerSelect');
  const leadSel = document.getElementById('leadSelect');
  if (!customerSel || !leadSel) return;

  const hint = document.getElementById('leadHint');

  function resetLeads(disabled) {
    leadSel.innerHTML = '<option value="">선택 안함</option>'; // 고객 변경 시 영업기회 선택 초기화
    leadSel.value = '';
    leadSel.disabled = !!disabled;
  }

  customerSel.addEventListener('change', async function () {
    const cid = this.value;
    resetLeads(true);
    if (!cid) {
      if (hint) hint.textContent = '고객을 먼저 선택하면 해당 고객의 영업기회만 표시됩니다.';
      return;
    }
    try {
      const d = await api('quotes.leads', { customer_id: cid }, { method: 'GET' });
      (d.leads || []).forEach((l) => {
        const op = document.createElement('option');
        op.value = l.id;
        op.textContent = '#' + l.id + (l.work_type ? ' · ' + l.work_type : '') + (l.stage_name ? ' · ' + l.stage_name : '');
        leadSel.appendChild(op);
      });
      leadSel.disabled = false;
      if (hint) hint.textContent = '선택한 고객의 영업기회만 표시됩니다(실주·취소 제외).';
    } catch (e) {
      toast(e.message, 'error');
      leadSel.disabled = false;
    }
  });
})();
