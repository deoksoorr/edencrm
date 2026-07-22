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
      '<td><input type="text" class="input" name="items[' + idx + '][name]" value="' + escapeAttr(data.name || '') + '"></td>' +
      '<td><input type="number" step="0.01" class="input num-input f-area" name="items[' + idx + '][area]" value="' + escapeAttr(data.area ?? '') + '"></td>' +
      '<td><input type="number" step="0.01" class="input num-input f-qty" name="items[' + idx + '][qty]" value="' + escapeAttr(data.qty ?? 1) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input f-price" name="items[' + idx + '][unit_price]" value="' + escapeAttr(data.unit_price ?? 0) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input" name="items[' + idx + '][material_cost]" value="' + escapeAttr(data.material_cost ?? 0) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input" name="items[' + idx + '][labor_cost]" value="' + escapeAttr(data.labor_cost ?? 0) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input" name="items[' + idx + '][equipment_cost]" value="' + escapeAttr(data.equipment_cost ?? 0) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input" name="items[' + idx + '][outsourcing_cost]" value="' + escapeAttr(data.outsourcing_cost ?? 0) + '"></td>' +
      '<td><input type="text" inputmode="decimal" class="input num-input money-input" name="items[' + idx + '][etc_cost]" value="' + escapeAttr(data.etc_cost ?? 0) + '"></td>' +
      '<td class="num row-amount">0</td>' +
      '<td><button type="button" class="btn btn-sm btn-ghost btn-remove-row" title="삭제">✕</button></td>';
    return tr;
  }

  function escapeAttr(v) {
    return String(v).replace(/"/g, '&quot;');
  }

  function recalcRow(tr) {
    const qty = numVal(tr.querySelector('.f-qty')?.value);
    const price = numVal(tr.querySelector('.f-price')?.value);
    const amount = Math.round(qty * price);
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
    if (e.target.matches('.f-qty, .f-price')) recalcAll();
  });
  document.getElementById('inputDiscount')?.addEventListener('input', recalcAll);
})();
