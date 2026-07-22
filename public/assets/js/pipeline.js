/* EDEN CRM — 영업 파이프라인
   6그룹 탭(클라이언트 전환) · 서버측 필터/빠른필터 · 적용칩 · 드래그이동 · 우측 상세 슬라이드 패널 */
(function () {
  'use strict';

  var cfg = window.PIPELINE_CONFIG || { canManage: false, fullAccess: false, initialTab: 'all' };
  var state = { tab: cfg.initialTab || 'all', quick: (document.getElementById('plQuick') || {}).value || '' };
  var justDragged = false;

  var QUICK_LABEL = {
    today: '오늘 연락 필요', overdue: '연락 지남', stale: '3일+ 미접촉',
    highvalue: '고액 견적', closing: '계약 임박', longstay: '장기 체류', unassigned: '담당 미배정',
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function moneyShort(n) {
    if (n === null || n === undefined || n === '') return '-';
    n = Number(n); var s = n < 0 ? '-' : ''; var a = Math.abs(n);
    if (a >= 1e8) { var v = a / 1e8; return s + (v === Math.floor(v) ? v : v.toFixed(1)) + '억'; }
    if (a >= 1e4) return s + Math.round(a / 1e4).toLocaleString('ko-KR') + '만';
    return s + a.toLocaleString('ko-KR');
  }
  var won = function (n) { return (n == null || n === '') ? '-' : Number(n).toLocaleString('ko-KR') + '원'; };
  var board = document.getElementById('kanbanBoard');

  // ── 탭: 그룹별 컬럼 표시/숨김 + 카운트 ──
  function tabGroups(tabKey) {
    var btn = document.querySelector('.pl-tab[data-tab="' + tabKey + '"]');
    return btn ? btn.dataset.groups.split(',') : [];
  }
  function applyTab() {
    var groups = tabGroups(state.tab);
    var showAll = state.tab === 'all';
    board.querySelectorAll('.kanban-col').forEach(function (col) {
      col.style.display = (showAll || groups.indexOf(col.dataset.group) >= 0) ? '' : 'none';
    });
    document.querySelectorAll('.pl-tab').forEach(function (b) {
      b.classList.toggle('active', b.dataset.tab === state.tab);
    });
    updateTabCounts();
    updateVisibleCount();
  }
  function updateTabCounts() {
    // 그룹별 카드 수 집계
    var byGroup = {};
    board.querySelectorAll('.kanban-col').forEach(function (col) {
      var g = col.dataset.group;
      byGroup[g] = (byGroup[g] || 0) + col.querySelectorAll('.kanban-card').length;
    });
    document.querySelectorAll('.pl-tab').forEach(function (b) {
      var groups = b.dataset.groups.split(',');
      var n = groups.reduce(function (s, g) { return s + (byGroup[g] || 0); }, 0);
      var cnt = b.querySelector('.tcnt');
      if (cnt) cnt.textContent = n;
    });
  }
  function updateVisibleCount() {
    var vis = 0;
    board.querySelectorAll('.kanban-col').forEach(function (col) {
      if (col.style.display !== 'none') vis += col.querySelectorAll('.kanban-card').length;
    });
    var el = document.getElementById('plCount');
    if (el) { var b = el.querySelector('b'); if (b) b.textContent = vis; }
  }

  // ── 컬럼 헤더 재계산(드래그 후) ──
  function updateColumnStats() {
    board.querySelectorAll('.kanban-col').forEach(function (col) {
      var list = col.querySelector('.kanban-list');
      var cards = list.querySelectorAll('.kanban-card');
      var sum = 0;
      cards.forEach(function (c) { sum += parseInt(c.dataset.amount || '0', 10) || 0; });
      var countEl = col.querySelector('.kanban-count');
      var sumEl = col.querySelector('.kanban-col-sum');
      if (countEl) countEl.textContent = cards.length;
      if (sumEl) { sumEl.textContent = moneyShort(sum) + '원'; sumEl.title = won(sum); }
      var emptyEl = list.querySelector('.kanban-empty');
      if (cards.length === 0 && !emptyEl) {
        var d = document.createElement('div'); d.className = 'kanban-empty'; d.textContent = '카드 없음'; list.appendChild(d);
      } else if (cards.length > 0 && emptyEl) { emptyEl.remove(); }
    });
    updateTabCounts();
    updateVisibleCount();
  }

  function revertCard(card, fromList, oldIndex) {
    var ref = fromList.children[oldIndex];
    if (ref) fromList.insertBefore(card, ref); else fromList.appendChild(card);
    updateColumnStats();
  }
  async function onCardMoved(evt) {
    var card = evt.item, leadId = card.dataset.leadId, toStageId = evt.to.dataset.stageId;
    if (evt.from === evt.to) return;
    try {
      var data = await api('pipeline.move', { lead_id: leadId, to_stage_id: toStageId });
      toast('이동되었습니다.', 'success');
      if (data.hint) toast(data.hint, 'warn');
      // 상태선 갱신을 위해 카드 클래스 재설정은 새로고침으로 처리(간단·정확)
      updateColumnStats();
    } catch (err) { revertCard(card, evt.from, evt.oldIndex); toast(err.message, 'error'); }
  }
  function initSortable() {
    if (typeof Sortable === 'undefined' || !cfg.canManage) return;
    board.querySelectorAll('.kanban-list').forEach(function (list) {
      new Sortable(list, {
        group: 'pipeline', animation: 150, ghostClass: 'sortable-ghost', chosenClass: 'sortable-chosen', dragClass: 'dragging',
        onStart: function () { justDragged = true; },
        onEnd: function (evt) { onCardMoved(evt); setTimeout(function () { justDragged = false; }, 80); },
      });
    });
  }

  // ── 컬럼 접기 ──
  board.addEventListener('click', function (e) {
    var caret = e.target.closest('.kanban-caret');
    if (caret) { e.stopPropagation(); caret.closest('.kanban-col').classList.toggle('collapsed'); return; }
    if (justDragged) return;
    var card = e.target.closest('.kanban-card');
    if (card) openDrawer(card.dataset.leadId);
  });

  // ── 필터 ──
  function collectFilters() {
    var form = document.getElementById('pipelineFilter');
    var params = {};
    new FormData(form).forEach(function (v, k) { if (v) params[k] = v; });
    params.quick = state.quick;
    params.tab = state.tab;
    if (!params.quick) delete params.quick;
    return params;
  }
  async function refreshBoard() {
    try {
      var data = await api('pipeline.board', collectFilters(), { method: 'GET' });
      board.innerHTML = data.html;
      var el = document.getElementById('plCount');
      if (el) el.innerHTML = '표시 <b>' + data.shown + '</b> / 전체 ' + data.total + '건';
      initSortable();
      applyTab();
      renderApplied();
    } catch (err) { toast(err.message, 'error'); }
  }
  function renderApplied() {
    var wrap = document.getElementById('plApplied');
    if (!wrap) return;
    var form = document.getElementById('pipelineFilter');
    var chips = [];
    var q = form.q.value.trim();
    if (q) chips.push({ k: 'q', label: '검색: ' + q });
    if (form.sales_user_id && form.sales_user_id.value) {
      chips.push({ k: 'sales_user_id', label: '담당: ' + form.sales_user_id.options[form.sales_user_id.selectedIndex].text });
    }
    if (form.importance.value) chips.push({ k: 'importance', label: '중요도: ' + form.importance.options[form.importance.selectedIndex].text });
    if (form.work_type && form.work_type.value) chips.push({ k: 'work_type', label: '공사: ' + form.work_type.value });
    if (state.quick) chips.push({ k: 'quick', label: QUICK_LABEL[state.quick] || state.quick });
    wrap.innerHTML = chips.map(function (c) {
      return '<span class="fchip">' + esc(c.label) + '<button type="button" data-clear="' + c.k + '" aria-label="제거">&times;</button></span>';
    }).join('') + (chips.length ? '<button type="button" class="qf" id="plClearAll">전체 초기화</button>' : '');
    // 빠른필터 칩 active 표시
    document.querySelectorAll('.qf[data-quick]').forEach(function (b) {
      b.classList.toggle('active', b.dataset.quick === state.quick);
    });
  }

  var filterForm = document.getElementById('pipelineFilter');
  if (filterForm) filterForm.addEventListener('submit', function (e) { e.preventDefault(); refreshBoard(); });

  var tabs = document.getElementById('plTabs');
  if (tabs) tabs.addEventListener('click', function (e) {
    var b = e.target.closest('.pl-tab'); if (!b) return;
    state.tab = b.dataset.tab;
    var t = filterForm.querySelector('[name=tab]'); if (t) t.value = state.tab;
    applyTab();
  });

  var quickChips = document.getElementById('plQuickChips');
  if (quickChips) quickChips.addEventListener('click', function (e) {
    var b = e.target.closest('.qf[data-quick]'); if (!b) return;
    state.quick = (state.quick === b.dataset.quick) ? '' : b.dataset.quick;
    refreshBoard();
  });

  var applied = document.getElementById('plApplied');
  if (applied) applied.addEventListener('click', function (e) {
    if (e.target.id === 'plClearAll') {
      filterForm.reset(); state.quick = '';
      var t = filterForm.querySelector('[name=tab]'); if (t) t.value = state.tab;
      refreshBoard(); return;
    }
    var btn = e.target.closest('[data-clear]'); if (!btn) return;
    var k = btn.dataset.clear;
    if (k === 'quick') state.quick = '';
    else if (filterForm[k]) filterForm[k].value = '';
    refreshBoard();
  });

  // ══════════ 우측 상세 슬라이드 패널 ══════════
  var drawer = document.getElementById('leadDrawer');
  var backdrop = document.getElementById('leadDrawerBackdrop');
  function closeDrawer() { drawer.classList.remove('open'); backdrop.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true'); }
  if (backdrop) backdrop.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });

  async function openDrawer(id) {
    drawer.classList.add('open'); backdrop.classList.add('open'); drawer.setAttribute('aria-hidden', 'false');
    drawer.innerHTML = '<div class="drawer-body"><div class="loading-row"><span class="spinner spinner-dark"></span> 불러오는 중…</div></div>';
    var data;
    try { data = await api('pipeline.show', { id: id }, { method: 'GET' }); }
    catch (err) { drawer.innerHTML = '<div class="drawer-body"><div class="empty"><div class="empty-title">' + esc(err.message) + '</div></div></div>'; return; }
    renderDrawer(data);
  }

  function renderDrawer(data) {
    var l = data.lead, cm = data.canManage;
    var impCls = l.importance === 'high' ? 'imp-high' : (l.importance === 'low' ? 'imp-low' : 'imp-mid');
    var acts = data.activities || [];
    var actTypeLabel = { call: '통화', visit: '방문', sms: '문자', email: '이메일', note: '메모' };

    var quoteHtml = data.quote
      ? '<a href="' + EDEN.url('quotes.show', { id: data.quote.id }) + '">' + esc(data.quote.quote_no) + ' (' + esc(data.quote.status) + ')</a>'
      : '<span class="muted">견적 없음</span>';

    var stageOpts = (data.stages || []).map(function (s) {
      return '<option value="' + s.id + '"' + (s.id == l.stage_id ? ' selected' : '') + '>' + esc(s.name) + '</option>';
    }).join('');
    var salesOpts = '<option value="">미지정</option>' + (data.salesUsers || []).map(function (u) {
      return '<option value="' + u.id + '"' + (u.id == l.sales_user_id ? ' selected' : '') + '>' + esc(u.name) + '</option>';
    }).join('');

    var timeline = acts.length
      ? '<div class="timeline">' + acts.map(function (a) {
          return '<div class="timeline-item ' + esc(a.type) + '"><div class="timeline-time">' + esc((a.created_at || '').slice(0, 16)) +
            ' · ' + esc(actTypeLabel[a.type] || a.type) + (a.user_name ? ' · ' + esc(a.user_name) : '') + '</div>' +
            '<div class="timeline-body">' + esc(a.content || '') + '</div></div>';
        }).join('') + '</div>'
      : '<div class="muted">상담 기록이 없습니다.</div>';

    drawer.innerHTML =
      '<div class="drawer-head">' +
        '<div><div class="dh-title">' + esc(l.customer_name) + (l.company_name ? ' <span class="muted">(' + esc(l.company_name) + ')</span>' : '') + '</div>' +
          '<div class="dh-sub"><span class="stage-badge" style="background:' + esc(l.group_color) + '">' + esc(l.stage_name) + '</span> ' +
          '<span class="imp-chip ' + impCls + '">' + esc(l.importance_label) + '</span> · D+' + l.stay_days + '</div></div>' +
        '<button class="drawer-close" type="button" id="drawerClose">&times;</button>' +
      '</div>' +
      '<div class="drawer-body">' +
        '<div class="drawer-sec"><div class="ds-title">고객·현장</div><dl class="dl">' +
          '<dt>연락처</dt><dd>' + esc(l.customer_phone || '-') + '</dd>' +
          '<dt>현장주소</dt><dd>' + esc(l.site_address || l.customer_site_address || '-') + '</dd>' +
          '<dt>공사종류</dt><dd>' + esc(l.work_type || '-') + '</dd>' +
          '<dt>유입경로</dt><dd>' + esc(l.customer_source || '-') + '</dd>' +
          '<dt>고객상세</dt><dd><a href="' + EDEN.url('customers.show', { id: l.customer_id }) + '">고객 카드 열기 →</a></dd>' +
        '</dl></div>' +
        '<div class="drawer-sec"><div class="ds-title">영업·수익</div><dl class="dl">' +
          '<dt>예상 계약</dt><dd class="mono" title="' + won(l.expected_amount) + '">' + moneyShort(l.expected_amount) + '원</dd>' +
          '<dt>예상 원가</dt><dd class="mono" title="' + won(l.expected_cost) + '">' + moneyShort(l.expected_cost) + '원</dd>' +
          '<dt>예상 순이익</dt><dd class="mono">' + moneyShort(l.profit) + '원 (' + (l.profit_rate == null ? '-' : l.profit_rate + '%') + ')</dd>' +
          '<dt>성공확률</dt><dd>' + (l.win_probability == null ? '-' : l.win_probability + '%') + '</dd>' +
          '<dt>가중 예상매출</dt><dd class="mono">' + moneyShort(l.weighted_revenue) + '원</dd>' +
          '<dt>견적</dt><dd>' + quoteHtml + '</dd>' +
        '</dl></div>' +
        (cm ? (
        '<div class="drawer-sec"><div class="ds-title">빠른 수정</div>' +
          '<label class="field"><span class="field-label">단계 변경</span><select class="select" id="dwStage">' + stageOpts + '</select></label>' +
          '<label class="field" style="margin-top:8px"><span class="field-label">담당 영업</span><select class="select" id="dwSales">' + salesOpts + '</select></label>' +
          '<label class="field" style="margin-top:8px"><span class="field-label">다음 연락일</span><input type="date" class="input" id="dwNext" value="' + esc(l.next_contact_date || '') + '"></label>' +
        '</div>') : (
        '<div class="drawer-sec"><div class="ds-title">진행</div><dl class="dl">' +
          '<dt>다음 연락일</dt><dd>' + esc(l.next_contact_date || '-') + '</dd></dl></div>')) +
        '<div class="drawer-sec"><div class="ds-title">최근 상담 기록</div>' + timeline +
          '<div style="margin-top:8px"><a href="' + EDEN.url('customers.show', { id: l.customer_id }) + '">상담 기록 작성 →</a></div></div>' +
        (l.memo ? '<div class="drawer-sec"><div class="ds-title">메모</div><div style="white-space:pre-wrap;font-size:13px">' + esc(l.memo) + '</div></div>' : '') +
      '</div>' +
      (cm ? (
      '<div class="drawer-foot">' +
        '<button class="btn btn-primary btn-sm" id="dwSave">변경 저장</button>' +
        '<a class="btn btn-outline btn-sm" href="' + EDEN.url('quotes.form', { customer_id: l.customer_id }) + '">견적 생성</a>' +
        (l.is_won == 1 ? '<a class="btn btn-outline btn-sm" href="' + EDEN.url('contracts.index') + '">계약 등록</a>' : '') +
        '<button class="btn btn-ghost btn-sm" id="dwEdit">상세 수정</button>' +
        '<button class="btn btn-ghost btn-sm text-danger" id="dwDelete">삭제</button>' +
      '</div>') : '');

    var byId = function (i) { return drawer.querySelector('#' + i); };
    byId('drawerClose').addEventListener('click', closeDrawer);

    if (cm) {
      byId('dwSave').addEventListener('click', async function (btn) {
        var stageId = byId('dwStage').value, sales = byId('dwSales').value, next = byId('dwNext').value;
        this.disabled = true;
        try {
          if (String(stageId) !== String(l.stage_id)) await api('pipeline.move', { lead_id: l.id, to_stage_id: stageId });
          await api('pipeline.patch', { id: l.id, sales_user_id: sales, next_contact_date: next, importance: l.importance });
          toast('저장되었습니다.', 'success');
          closeDrawer(); refreshBoard();
        } catch (err) { toast(err.message, 'error'); this.disabled = false; }
      });
      byId('dwEdit').addEventListener('click', function () { closeDrawer(); openLeadForm(l.id, null); });
      byId('dwDelete').addEventListener('click', async function () {
        var ok = await EDEN.confirm('이 영업기회를 삭제하시겠습니까?', { danger: true, okLabel: '삭제' });
        if (!ok) return;
        try { await api('pipeline.delete', { id: l.id }); toast('삭제되었습니다.', 'success'); closeDrawer(); refreshBoard(); }
        catch (err) { toast(err.message, 'error'); }
      });
    }
  }

  // ── 등록/수정 모달 ──
  async function openLeadForm(id, customerId) {
    var params = {}; if (id) params.id = id; if (customerId) params.customer_id = customerId;
    var data;
    try { data = await api('pipeline.form', params, { method: 'GET' }); }
    catch (err) { toast(err.message, 'error'); return; }

    var l = data.lead || {};
    var stageOptions = data.stages.map(function (s) {
      return '<option value="' + s.id + '"' + ((l.stage_id || data.stages[0].id) == s.id ? ' selected' : '') + '>' + esc(s.name) + '</option>';
    }).join('');
    var salesOptions = '<option value="">미지정</option>' + data.salesUsers.map(function (u) {
      return '<option value="' + u.id + '"' + ((l.sales_user_id || '') == u.id ? ' selected' : '') + '>' + esc(u.name) + '</option>';
    }).join('');
    var custOptions = data.customers.map(function (c) {
      return '<option value="' + c.id + '"' + ((l.customer_id || data.defaultCustomerId || '') == c.id ? ' selected' : '') + '>' + esc(c.name) + (c.company_name ? ' (' + esc(c.company_name) + ')' : '') + '</option>';
    }).join('');
    var importance = l.importance || 'mid';

    var body = document.createElement('div');
    body.innerHTML =
      '<form class="form" id="leadForm"><div class="form-grid">' +
      '<label class="field"><span class="field-label">고객<span class="req">*</span></span><select name="customer_id" class="select" required>' + custOptions + '</select></label>' +
      '<label class="field"><span class="field-label">단계</span><select name="stage_id" class="select">' + stageOptions + '</select></label>' +
      '<label class="field"><span class="field-label">담당영업</span><select name="sales_user_id" class="select">' + salesOptions + '</select></label>' +
      '<label class="field"><span class="field-label">공사종류</span><input type="text" name="work_type" class="input" value="' + esc(l.work_type || '') + '"></label>' +
      '<label class="field col-span-2"><span class="field-label">현장주소</span><input type="text" name="site_address" class="input" value="' + esc(l.site_address || '') + '"></label>' +
      '<label class="field"><span class="field-label">예상계약금액</span><input type="number" name="expected_amount" id="leadAmount" class="input" min="0" value="' + (l.expected_amount || 0) + '"></label>' +
      '<label class="field"><span class="field-label">예상원가</span><input type="number" name="expected_cost" id="leadCost" class="input" min="0" value="' + (l.expected_cost || 0) + '"></label>' +
      '<label class="field"><span class="field-label">성공확률(%)</span><input type="number" name="win_probability" class="input" min="0" max="100" value="' + (l.win_probability != null ? l.win_probability : '') + '"></label>' +
      '<label class="field"><span class="field-label">중요도</span><select name="importance" class="select">' +
      '<option value="low"' + (importance === 'low' ? ' selected' : '') + '>낮음</option>' +
      '<option value="mid"' + (importance === 'mid' ? ' selected' : '') + '>보통</option>' +
      '<option value="high"' + (importance === 'high' ? ' selected' : '') + '>높음</option></select></label>' +
      '<label class="field"><span class="field-label">다음연락예정일</span><input type="date" name="next_contact_date" class="input" value="' + (l.next_contact_date || '') + '"></label>' +
      '<label class="field"><span class="field-label">태그</span><input type="text" name="tags" class="input" value="' + esc(l.tags || '') + '"></label>' +
      '</div>' +
      '<label class="field"><span class="field-label">메모</span><textarea name="memo" class="input" rows="2">' + esc(l.memo || '') + '</textarea></label>' +
      '<div class="kv-row" style="border-top:1px solid var(--line-2);padding-top:10px">' +
      '<div class="kv"><span class="kv-label">예상순이익(실시간)</span><span class="kv-value mono" id="calcProfit">-</span></div>' +
      '<div class="kv"><span class="kv-label">예상순이익률(실시간)</span><span class="kv-value mono" id="calcRate">-</span></div>' +
      '</div></form>';

    function recalc() {
      var amount = parseFloat(body.querySelector('#leadAmount').value) || 0;
      var cost = parseFloat(body.querySelector('#leadCost').value) || 0;
      var profit = amount - cost, rate = amount > 0 ? (profit / amount * 100) : null;
      body.querySelector('#calcProfit').textContent = won(profit);
      body.querySelector('#calcRate').textContent = rate === null ? '-' : rate.toFixed(1) + '%';
    }
    body.querySelector('#leadAmount').addEventListener('input', recalc);
    body.querySelector('#leadCost').addEventListener('input', recalc);
    recalc();

    EDEN.modal({
      title: id ? '영업기회 수정' : '신규 영업기회', wide: true, body: body,
      buttons: [
        { label: '취소', class: 'btn-outline', onClick: function (close) { close(); } },
        { label: '저장', class: 'btn-primary', onClick: async function (close, btn) {
            var form = body.querySelector('#leadForm');
            if (!form.reportValidity()) return;
            btn.disabled = true;
            try {
              var fd = new FormData(form); if (id) fd.append('id', id);
              await api('pipeline.save', fd);
              toast('저장되었습니다.', 'success'); close(); refreshBoard();
            } catch (err) { toast(err.message, 'error'); } finally { btn.disabled = false; }
          } },
      ],
    });
  }

  var btnNew = document.getElementById('btnNewLead');
  if (btnNew) btnNew.addEventListener('click', function () { openLeadForm(null, null); });

  // ── 초기화 ──
  if (board) { initSortable(); applyTab(); renderApplied(); }
})();
