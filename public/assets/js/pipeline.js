/* EDEN CRM — 영업 파이프라인 칸반 (SortableJS DnD + 상세/등록 모달) */
(function () {
  'use strict';

  var cfg = window.PIPELINE_CONFIG || { canManage: false, fullAccess: false };
  var justDragged = false;

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ── 컬럼 헤더(건수/합계) DOM 재계산 ──
  function updateColumnStats() {
    document.querySelectorAll('#kanbanBoard .kanban-col').forEach(function (col) {
      var list = col.querySelector('.kanban-list');
      var cards = list.querySelectorAll('.kanban-card');
      var sum = 0;
      cards.forEach(function (c) {
        var amtEl = c.querySelector('.kanban-card-amount');
        if (amtEl) {
          var n = parseInt(amtEl.textContent.replace(/[^0-9-]/g, ''), 10);
          if (!isNaN(n)) sum += n;
        }
      });
      var countEl = col.querySelector('.kanban-count');
      var sumEl = col.querySelector('.kanban-col-sum');
      if (countEl) countEl.textContent = cards.length;
      if (sumEl) sumEl.textContent = EDEN.formatMoney(sum) + '원';
      var emptyEl = list.querySelector('.kanban-empty');
      if (cards.length === 0 && !emptyEl) {
        var div = document.createElement('div');
        div.className = 'kanban-empty';
        div.textContent = '카드 없음';
        list.appendChild(div);
      } else if (cards.length > 0 && emptyEl) {
        emptyEl.remove();
      }
    });
  }

  function revertCard(card, fromList, oldIndex) {
    var ref = fromList.children[oldIndex];
    if (ref) fromList.insertBefore(card, ref);
    else fromList.appendChild(card);
    updateColumnStats();
  }

  async function onCardMoved(evt) {
    var card = evt.item;
    var leadId = card.dataset.leadId;
    var toStageId = evt.to.dataset.stageId;
    if (evt.from === evt.to) {
      return; // 같은 컬럼 내 순서 변경은 서버 반영 불필요
    }
    try {
      var data = await api('pipeline.move', { lead_id: leadId, to_stage_id: toStageId });
      toast('이동되었습니다.', 'success');
      if (data.hint) toast(data.hint, 'warn');
      updateColumnStats();
    } catch (err) {
      revertCard(card, evt.from, evt.oldIndex);
      toast(err.message, 'error');
    }
  }

  function initSortable() {
    document.querySelectorAll('#kanbanBoard .kanban-list').forEach(function (list) {
      new Sortable(list, {
        group: 'pipeline',
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        dragClass: 'dragging',
        onStart: function () { justDragged = true; },
        onEnd: function (evt) {
          onCardMoved(evt);
          setTimeout(function () { justDragged = false; }, 80);
        },
      });
    });
  }

  // ── 필터 새로고침 ──
  async function refreshBoard() {
    var form = document.getElementById('pipelineFilter');
    var params = {};
    new FormData(form).forEach(function (v, k) { if (v) params[k] = v; });
    try {
      var data = await api('pipeline.board', params, { method: 'GET' });
      var board = document.getElementById('kanbanBoard');
      board.innerHTML = data.html;
      initSortable();
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  var filterForm = document.getElementById('pipelineFilter');
  if (filterForm) {
    filterForm.addEventListener('submit', function (e) {
      e.preventDefault();
      refreshBoard();
    });
  }

  // ── 카드 상세 모달 ──
  async function openLeadDetail(id) {
    var data;
    try {
      data = await api('pipeline.show', { id: id }, { method: 'GET' });
    } catch (err) {
      toast(err.message, 'error');
      return;
    }
    var l = data.lead;
    var body = document.createElement('div');
    body.innerHTML =
      '<dl class="dl">' +
      '<dt>고객</dt><dd><a href="' + EDEN.url('customers.show', { id: l.customer_id }) + '">' +
        escapeHtml(l.customer_name) + (l.company_name ? ' (' + escapeHtml(l.company_name) + ')' : '') + '</a></dd>' +
      '<dt>단계</dt><dd>' + escapeHtml(l.stage_name) + '</dd>' +
      '<dt>공사종류</dt><dd>' + escapeHtml(l.work_type || '-') + '</dd>' +
      '<dt>현장주소</dt><dd>' + escapeHtml(l.site_address || '-') + '</dd>' +
      '<dt>담당영업</dt><dd>' + escapeHtml(l.sales_user_name || '-') + '</dd>' +
      '<dt>예상계약금액</dt><dd>' + EDEN.formatMoney(l.expected_amount) + '원</dd>' +
      '<dt>예상원가</dt><dd>' + EDEN.formatMoney(l.expected_cost) + '원</dd>' +
      '<dt>예상순이익</dt><dd>' + EDEN.formatMoney(l.profit) + '원 (' + (l.profit_rate == null ? '-' : l.profit_rate + '%') + ')</dd>' +
      '<dt>성공확률</dt><dd>' + (l.win_probability == null ? '-' : l.win_probability + '%') + '</dd>' +
      '<dt>가중예상매출</dt><dd>' + EDEN.formatMoney(l.weighted_revenue) + '원</dd>' +
      '<dt>다음연락예정일</dt><dd>' + (l.next_contact_date || '-') + '</dd>' +
      '<dt>체류일수</dt><dd>D+' + l.stay_days + '</dd>' +
      '<dt>중요도</dt><dd>' + escapeHtml(l.importance || '-') + '</dd>' +
      '<dt>태그</dt><dd>' + escapeHtml(l.tags || '-') + '</dd>' +
      '<dt>메모</dt><dd style="white-space:pre-wrap">' + escapeHtml(l.memo || '-') + '</dd>' +
      '</dl>';

    var buttons = [{ label: '닫기', class: 'btn-outline', onClick: function (close) { close(); } }];
    if (cfg.canManage) {
      buttons.unshift({
        label: '삭제', class: 'btn-danger', onClick: async function (close) {
          var ok = await EDEN.confirm('이 영업기회를 삭제하시겠습니까?', { danger: true, okLabel: '삭제' });
          if (!ok) return;
          try {
            await api('pipeline.delete', { id: id });
            close();
            toast('삭제되었습니다.', 'success');
            refreshBoard();
          } catch (err) { toast(err.message, 'error'); }
        },
      });
      buttons.unshift({
        label: '수정', class: 'btn-primary', onClick: function (close) {
          close();
          openLeadForm(id, null);
        },
      });
    }
    EDEN.modal({ title: '영업기회 상세', wide: true, body: body, buttons: buttons });
  }

  // ── 카드 등록/수정 모달 ──
  async function openLeadForm(id, customerId) {
    var params = {};
    if (id) params.id = id;
    if (customerId) params.customer_id = customerId;
    var data;
    try {
      data = await api('pipeline.form', params, { method: 'GET' });
    } catch (err) {
      toast(err.message, 'error');
      return;
    }

    var l = data.lead || {};
    var stageOptions = data.stages.map(function (s) {
      var sel = (l.stage_id || data.stages[0].id) == s.id ? 'selected' : '';
      return '<option value="' + s.id + '" ' + sel + '>' + escapeHtml(s.name) + '</option>';
    }).join('');
    var salesOptions = '<option value="">미지정</option>' + data.salesUsers.map(function (u) {
      var sel = (l.sales_user_id || '') == u.id ? 'selected' : '';
      return '<option value="' + u.id + '" ' + sel + '>' + escapeHtml(u.name) + '</option>';
    }).join('');
    var custOptions = data.customers.map(function (c) {
      var sel = (l.customer_id || data.defaultCustomerId || '') == c.id ? 'selected' : '';
      return '<option value="' + c.id + '" ' + sel + '>' + escapeHtml(c.name) + (c.company_name ? ' (' + escapeHtml(c.company_name) + ')' : '') + '</option>';
    }).join('');
    var importance = l.importance || 'mid';

    var body = document.createElement('div');
    body.innerHTML =
      '<form class="form" id="leadForm">' +
      '<div class="form-grid">' +
      '<label class="field"><span class="field-label">고객<span class="req">*</span></span><select name="customer_id" class="select" required>' + custOptions + '</select></label>' +
      '<label class="field"><span class="field-label">단계</span><select name="stage_id" class="select">' + stageOptions + '</select></label>' +
      '<label class="field"><span class="field-label">담당영업</span><select name="sales_user_id" class="select">' + salesOptions + '</select></label>' +
      '<label class="field"><span class="field-label">공사종류</span><input type="text" name="work_type" class="input" value="' + escapeHtml(l.work_type || '') + '"></label>' +
      '<label class="field col-span-2"><span class="field-label">현장주소</span><input type="text" name="site_address" class="input" value="' + escapeHtml(l.site_address || '') + '"></label>' +
      '<label class="field"><span class="field-label">예상계약금액</span><input type="number" name="expected_amount" id="leadAmount" class="input" min="0" value="' + (l.expected_amount || 0) + '"></label>' +
      '<label class="field"><span class="field-label">예상원가</span><input type="number" name="expected_cost" id="leadCost" class="input" min="0" value="' + (l.expected_cost || 0) + '"></label>' +
      '<label class="field"><span class="field-label">성공확률(%)</span><input type="number" name="win_probability" class="input" min="0" max="100" value="' + (l.win_probability != null ? l.win_probability : '') + '"></label>' +
      '<label class="field"><span class="field-label">중요도</span><select name="importance" class="select">' +
      '<option value="low"' + (importance === 'low' ? ' selected' : '') + '>낮음</option>' +
      '<option value="mid"' + (importance === 'mid' ? ' selected' : '') + '>보통</option>' +
      '<option value="high"' + (importance === 'high' ? ' selected' : '') + '>높음</option>' +
      '</select></label>' +
      '<label class="field"><span class="field-label">다음연락예정일</span><input type="date" name="next_contact_date" class="input" value="' + (l.next_contact_date || '') + '"></label>' +
      '<label class="field"><span class="field-label">태그</span><input type="text" name="tags" class="input" value="' + escapeHtml(l.tags || '') + '"></label>' +
      '</div>' +
      '<label class="field"><span class="field-label">메모</span><textarea name="memo" class="input" rows="2">' + escapeHtml(l.memo || '') + '</textarea></label>' +
      '<div class="kv-row" style="border-top:1px solid var(--line-2);padding-top:10px">' +
      '<div class="kv"><span class="kv-label">예상순이익(실시간)</span><span class="kv-value" id="calcProfit">-</span></div>' +
      '<div class="kv"><span class="kv-label">예상순이익률(실시간)</span><span class="kv-value" id="calcRate">-</span></div>' +
      '</div>' +
      (!id ? '<div style="margin-top:8px"><a href="' + EDEN.url('customers.form') + '" target="_blank" class="muted">목록에 없는 고객이라면 새 고객 등록 →</a></div>' : '') +
      '</form>';

    function recalc() {
      var amount = parseFloat(body.querySelector('#leadAmount').value) || 0;
      var cost = parseFloat(body.querySelector('#leadCost').value) || 0;
      var profit = amount - cost;
      var rate = amount > 0 ? (profit / amount * 100) : null;
      body.querySelector('#calcProfit').textContent = EDEN.formatMoney(profit) + '원';
      body.querySelector('#calcRate').textContent = rate === null ? '-' : rate.toFixed(1) + '%';
    }
    body.querySelector('#leadAmount').addEventListener('input', recalc);
    body.querySelector('#leadCost').addEventListener('input', recalc);
    recalc();

    EDEN.modal({
      title: id ? '영업기회 수정' : '신규 영업기회',
      wide: true,
      body: body,
      buttons: [
        { label: '취소', class: 'btn-outline', onClick: function (close) { close(); } },
        {
          label: '저장', class: 'btn-primary', onClick: async function (close, btn) {
            var form = body.querySelector('#leadForm');
            if (!form.reportValidity()) return;
            btn.disabled = true;
            try {
              var fd = new FormData(form);
              if (id) fd.append('id', id);
              await api('pipeline.save', fd);
              toast('저장되었습니다.', 'success');
              close();
              refreshBoard();
            } catch (err) {
              toast(err.message, 'error');
            } finally {
              btn.disabled = false;
            }
          },
        },
      ],
    });
  }

  var board = document.getElementById('kanbanBoard');
  if (board) {
    board.addEventListener('click', function (e) {
      if (justDragged) return;
      var card = e.target.closest('.kanban-card');
      if (!card) return;
      openLeadDetail(card.dataset.leadId);
    });
  }

  var btnNew = document.getElementById('btnNewLead');
  if (btnNew) {
    btnNew.addEventListener('click', function () { openLeadForm(null, null); });
  }

  if (board) initSortable();
})();
