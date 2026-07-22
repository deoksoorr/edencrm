/* EDEN CRM — 일정 스케줄러: 월 캘린더 + 직원별 슬롯 타임라인(오전/오후/야간)
   다중 참여자 · 색상은 참여 직원 개인색 · 시각 입력 없음(날짜+슬롯) */
(function () {
  'use strict';

  const cfg = window.SCHED_INIT || { canManage: false, canManageAll: false, meId: 0, users: [], slots: {} };
  const SLOTS = cfg.slots && Object.keys(cfg.slots).length ? cfg.slots : { am: '오전', pm: '오후', night: '야간' };
  const SLOT_KEYS = ['am', 'pm', 'night'];

  const calRoot = document.getElementById('calRoot');
  const schedRoot = document.getElementById('schedRoot');
  const rangeLabel = document.getElementById('curRangeLabel');
  const fUser = document.getElementById('fUser');
  const fProject = document.getElementById('fProject');
  if (!calRoot && !schedRoot) return;

  const state = { view: 'month', ref: new Date(), userId: '', projectId: '', data: { schedules: [], holidays: [] } };

  const userColor = {};
  (cfg.users || []).forEach((u) => { userColor[u.id] = u.color || '#6b7280'; });

  function pad(n) { return String(n).padStart(2, '0'); }
  function toDateStr(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function primaryColor(ev) { return (ev.participants && ev.participants[0] && ev.participants[0].color) || '#6b7280'; }
  function partNames(ev) {
    const p = ev.participants || [];
    if (!p.length) return '미지정';
    return p.length === 1 ? p[0].name : p[0].name + ' 외 ' + (p.length - 1);
  }
  /** 공통 일정 라벨: [작업자] 작업내용 (작업자명이 항상 앞) */
  function schedLabel(ev) { return '[' + partNames(ev) + '] ' + ev.title; }

  function monthRange(d) {
    const first = new Date(d.getFullYear(), d.getMonth(), 1);
    const last = new Date(d.getFullYear(), d.getMonth() + 1, 0);
    const gridStart = new Date(first); gridStart.setDate(first.getDate() - first.getDay());
    const gridEnd = new Date(last); gridEnd.setDate(last.getDate() + (6 - last.getDay()));
    return { first, last, gridStart, gridEnd };
  }
  function weekRange(d) {
    const day = d.getDay();
    const start = new Date(d); start.setDate(d.getDate() + (day === 0 ? -6 : 1 - day)); start.setHours(0, 0, 0, 0);
    const end = new Date(start); end.setDate(start.getDate() + 6);
    return { start, end };
  }

  async function loadData() {
    let from, to;
    if (state.view === 'month') { const r = monthRange(state.ref); from = toDateStr(r.gridStart); to = toDateStr(r.gridEnd); }
    else { const r = weekRange(state.ref); from = toDateStr(r.start); to = toDateStr(r.end); }
    const params = { from, to };
    if (state.userId) params.user_id = state.userId;
    if (state.projectId) params.project_id = state.projectId;
    try { state.data = (await api('schedule.data', params)) || { schedules: [], holidays: [] }; render(); }
    catch (e) { toast(e.message, 'error'); }
  }

  function updateRangeLabel() {
    if (!rangeLabel) return;
    if (state.view === 'month') rangeLabel.textContent = state.ref.getFullYear() + '년 ' + (state.ref.getMonth() + 1) + '월';
    else { const { start, end } = weekRange(state.ref); rangeLabel.textContent = toDateStr(start) + ' ~ ' + toDateStr(end); }
  }
  function render() { updateRangeLabel(); if (state.view === 'month') renderMonth(); else renderTimeline(); }
  function holidaySet() { const s = {}; (state.data.holidays || []).forEach((h) => { s[h.holiday_date] = h.name; }); return s; }
  function slotRank(s) { return Math.max(0, SLOT_KEYS.indexOf(s)); }

  // ── 월 캘린더 ──
  function renderMonth() {
    if (!calRoot) return;
    const { gridStart } = monthRange(state.ref);
    const todayStr = toDateStr(new Date());
    const hset = holidaySet();
    const byDay = {};
    (state.data.schedules || []).forEach((ev) => { (byDay[ev.event_date] = byDay[ev.event_date] || []).push(ev); });
    Object.values(byDay).forEach((arr) => arr.sort((a, b) => slotRank(a.slot) - slotRank(b.slot)));

    let html = '<div class="cal-head"><div>일</div><div>월</div><div>화</div><div>수</div><div>목</div><div>금</div><div>토</div></div><div class="cal-grid">';
    const cur = new Date(gridStart);
    for (let i = 0; i < 42; i++) {
      const key = toDateStr(cur);
      const classes = ['cal-cell'];
      if (cur.getMonth() !== state.ref.getMonth()) classes.push('other');
      if (key === todayStr) classes.push('today');
      if (hset[key]) classes.push('holiday');
      const evs = byDay[key] || [];
      html += '<div class="' + classes.join(' ') + '" data-date="' + key + '"><div class="cal-date">' + cur.getDate() + '</div>';
      evs.slice(0, 3).forEach((ev) => {
        html += '<div class="cal-ev" draggable="true" data-id="' + ev.id + '" style="background:' + primaryColor(ev) + ';color:#fff" title="' +
          esc(ev.slot_label + ' · ' + schedLabel(ev)) + '"><b>[' + esc(partNames(ev)) + ']</b> ' + esc(ev.title) + '</div>';
      });
      if (evs.length > 3) html += '<div class="cal-more">+' + (evs.length - 3) + '건</div>';
      html += '</div>';
      cur.setDate(cur.getDate() + 1);
    }
    calRoot.innerHTML = html + '</div>';
    bindCalEvents();
  }
  function bindCalEvents() {
    calRoot.querySelectorAll('.cal-ev').forEach((el) => {
      el.addEventListener('click', (e) => { e.stopPropagation(); openDetail(+el.dataset.id); });
      el.addEventListener('dragstart', (e) => e.dataTransfer.setData('text/plain', el.dataset.id));
    });
    calRoot.querySelectorAll('.cal-cell').forEach((cell) => {
      cell.addEventListener('dragover', (e) => e.preventDefault());
      cell.addEventListener('drop', (e) => {
        e.preventDefault();
        const ev = (state.data.schedules || []).find((x) => String(x.id) === e.dataTransfer.getData('text/plain'));
        if (ev) moveSchedule(ev, cell.dataset.date, ev.slot); // 날짜 변경, 슬롯 유지
      });
    });
  }

  // ── 슬롯 타임라인(직원 × 날짜 × 3슬롯) ──
  function renderTimeline() {
    if (!schedRoot) return;
    const { start } = weekRange(state.ref);
    const days = []; for (let i = 0; i < 7; i++) { const d = new Date(start); d.setDate(start.getDate() + i); days.push(d); }
    const dayNames = ['일', '월', '화', '수', '목', '금', '토'];
    const todayStr = toDateStr(new Date());
    const hset = holidaySet();

    let users = cfg.users || [];
    if (!cfg.canManageAll) users = users.filter((u) => u.id === cfg.meId);
    if (state.userId) users = users.filter((u) => String(u.id) === String(state.userId));

    let head = '<div class="sched-head2"><div>직원</div><div></div>';
    days.forEach((d) => { head += '<div>' + dayNames[d.getDay()] + ' ' + (d.getMonth() + 1) + '/' + d.getDate() + '</div>'; });
    head += '</div>';

    if (!users.length) { schedRoot.innerHTML = head + '<div class="sched-empty2">표시할 직원이 없습니다.</div>'; return; }

    // index events: user -> date -> slot -> [ev]
    const idx = {};
    (state.data.schedules || []).forEach((ev) => {
      (ev.participants || []).forEach((p) => {
        idx[p.user_id] = idx[p.user_id] || {};
        idx[p.user_id][ev.event_date] = idx[p.user_id][ev.event_date] || {};
        (idx[p.user_id][ev.event_date][ev.slot] = idx[p.user_id][ev.event_date][ev.slot] || []).push(ev);
      });
    });

    let rows = '';
    users.forEach((u) => {
      const uc = userColor[u.id] || '#6b7280';
      let row = '<div class="sched-row2"><div class="sched-name2"><span class="user-color-dot" style="background:' + uc + '"></span>' + esc(u.name) + '</div>' +
        '<div class="sched-slotlabels"><div>오전</div><div>오후</div><div>야간</div></div>';
      days.forEach((d) => {
        const ds = toDateStr(d);
        const cls = 'sched-daycell' + (ds === todayStr ? ' today' : '') + (hset[ds] ? ' holiday' : '');
        row += '<div class="' + cls + '" data-date="' + ds + '">';
        SLOT_KEYS.forEach((sk) => {
          const evs = ((idx[u.id] || {})[ds] || {})[sk] || [];
          let chips = '';
          evs.forEach((ev) => {
            chips += '<div class="sched-chip" draggable="true" data-id="' + ev.id + '" style="background:' + uc + '" title="' + esc(ev.title + ' · ' + partNames(ev)) + '">' + esc(ev.title) + '</div>';
          });
          row += '<div class="sched-slot ' + sk + '" data-date="' + ds + '" data-slot="' + sk + '">' + chips + '</div>';
        });
        row += '</div>';
      });
      rows += row + '</div>';
    });

    schedRoot.innerHTML = head + rows;
    bindTimelineEvents();
  }
  function bindTimelineEvents() {
    schedRoot.querySelectorAll('.sched-chip').forEach((el) => {
      el.addEventListener('click', (e) => { e.stopPropagation(); openDetail(+el.dataset.id); });
      el.addEventListener('dragstart', (e) => e.dataTransfer.setData('text/plain', el.dataset.id));
    });
    schedRoot.querySelectorAll('.sched-slot').forEach((slot) => {
      slot.addEventListener('dragover', (e) => { e.preventDefault(); slot.classList.add('drop-hover'); });
      slot.addEventListener('dragleave', () => slot.classList.remove('drop-hover'));
      slot.addEventListener('drop', (e) => {
        e.preventDefault(); slot.classList.remove('drop-hover');
        const ev = (state.data.schedules || []).find((x) => String(x.id) === e.dataTransfer.getData('text/plain'));
        if (ev) moveSchedule(ev, slot.dataset.date, slot.dataset.slot);
      });
    });
  }

  // ── 이동/저장 ──
  async function moveSchedule(ev, newDate, newSlot) {
    if (ev.event_date === newDate && ev.slot === newSlot) return;
    await submitMove({ id: ev.id, event_date: newDate, slot: newSlot });
  }
  async function submitMove(payload, confirmed) {
    if (confirmed) payload.confirmed = 1;
    try {
      const res = await api('schedule.move', payload);
      if (res && res.conflict) {
        if (await showConflictModal(res.conflicts)) { await submitMove(payload, true); return; }
        toast('일정 이동이 취소되었습니다.', 'warn');
      } else { toast('일정이 이동되었습니다.', 'success'); }
    } catch (e) { toast(e.message, 'error'); }
    finally { await loadData(); }
  }
  async function submitSave(payload, confirmed) {
    if (confirmed) payload.confirmed = 1;
    const res = await api('schedule.save', payload);
    if (res && res.conflict) {
      if (await showConflictModal(res.conflicts)) { await submitSave(payload, true); return true; }
      toast('저장이 취소되었습니다.', 'warn'); return false;
    }
    toast('저장되었습니다.', 'success'); await loadData(); return true;
  }
  function showConflictModal(conflicts) {
    return new Promise((resolve) => {
      let body = '<p style="margin-top:0">같은 날짜·시간대에 이미 잡힌 일정이 있습니다.</p>';
      (conflicts || []).forEach((c) => {
        body += '<div class="badge badge-warn" style="display:block;margin-bottom:6px;text-align:left">' + esc(c.user_name) + ' — ' + esc(c.title) + '</div>';
      });
      body += '<p style="margin-bottom:0">그래도 저장하시겠습니까?</p>';
      EDEN.modal({
        title: '일정 충돌 경고', body,
        buttons: [
          { label: '취소', class: 'btn-outline', onClick: (close) => { close(); resolve(false); } },
          { label: '승인하고 저장', class: 'btn-danger', onClick: (close) => { close(); resolve(true); } },
        ],
      });
    });
  }

  // ── 상세 ──
  function openDetail(id) {
    const ev = (state.data.schedules || []).find((x) => x.id === id);
    if (!ev) return;
    const parts = (ev.participants || []).map((p) =>
      '<span class="part-chip"><span class="user-color-dot" style="background:' + (p.color || '#6b7280') + '"></span>' + esc(p.name) + '</span>').join(' ') || '-';
    const body = document.createElement('div');
    body.innerHTML =
      '<div class="dl">' +
      '<dt>제목</dt><dd>' + esc(ev.title) + '</dd>' +
      '<dt>참여 직원</dt><dd><div class="part-chosen">' + parts + '</div></dd>' +
      '<dt>날짜</dt><dd>' + esc(ev.event_date) + '</dd>' +
      '<dt>시간대</dt><dd>' + esc(ev.slot_label) + '</dd>' +
      '<dt>프로젝트</dt><dd>' + (ev.project_name ? esc(ev.project_no + ' · ' + ev.project_name) : '-') + '</dd>' +
      '<dt>유형</dt><dd>' + esc(ev.type) + '</dd>' +
      '<dt>메모</dt><dd>' + esc(ev.memo || '-') + '</dd>' +
      '</div>';
    const buttons = [{ label: '닫기', class: 'btn-outline', onClick: (close) => close() }];
    if (cfg.canManage) {
      buttons.unshift({
        label: '삭제', class: 'btn-danger', onClick: async (close) => {
          close();
          if (!(await EDEN.confirm('이 일정을 삭제하시겠습니까?', { danger: true }))) return;
          try { await api('schedule.delete', { id: ev.id }); toast('삭제되었습니다.', 'success'); await loadData(); }
          catch (e) { toast(e.message, 'error'); }
        },
      });
      buttons.unshift({ label: '수정', class: 'btn-primary', onClick: (close) => { close(); openForm(ev); } });
    }
    EDEN.modal({ title: '일정 상세', body, buttons });
  }

  // ── 등록/수정 폼 ──
  function openForm(ev) {
    const isEdit = !!ev;
    const chosen = {};
    if (isEdit) (ev.participants || []).forEach((p) => { chosen[p.user_id] = true; });
    else if (!cfg.canManageAll) chosen[cfg.meId] = true;

    const partList = (cfg.users || []).map((u) =>
      '<label class="part-item"><input type="checkbox" value="' + u.id + '"' + (chosen[u.id] ? ' checked' : '') + '>' +
      '<span class="user-color-dot" style="background:' + (u.color || '#6b7280') + '"></span>' + esc(u.name) + '</label>').join('');
    const projOpts = '<option value="">없음</option>' + Array.from(fProject ? fProject.options : []).filter((o) => o.value)
      .map((o) => '<option value="' + o.value + '"' + (isEdit && String(ev.project_id) === o.value ? ' selected' : '') + '>' + esc(o.textContent) + '</option>').join('');
    const typeOpts = [['work', '작업'], ['meeting', '회의'], ['site_visit', '현장방문'], ['vacation', '휴무'], ['other', '기타']]
      .map((t) => '<option value="' + t[0] + '"' + (isEdit && ev.type === t[0] ? ' selected' : '') + '>' + t[1] + '</option>').join('');
    const curSlot = isEdit ? ev.slot : 'am';
    const slotTabs = SLOT_KEYS.map((k) => '<button type="button" class="slot-tab' + (k === curSlot ? ' active' : '') + '" data-slot="' + k + '">' + esc(SLOTS[k]) + '</button>').join('');

    const body = document.createElement('div');
    body.innerHTML =
      '<div class="form">' +
      '<div class="field"><label class="field-label">제목 <span class="req">*</span></label><input class="input" id="sfTitle" value="' + esc(isEdit ? ev.title : '') + '"></div>' +
      '<div class="field"><label class="field-label">참여 직원 <span class="req">*</span></label><div class="part-picker" id="sfParts">' + partList + '</div></div>' +
      '<div class="form-grid">' +
      '<div class="field"><label class="field-label">날짜 <span class="req">*</span></label><input class="input" type="date" id="sfDate" value="' + (isEdit ? esc(ev.event_date) : toDateStr(state.ref)) + '"></div>' +
      '<div class="field"><label class="field-label">시간대 <span class="req">*</span></label><div class="slot-tabs" id="sfSlots">' + slotTabs + '</div><input type="hidden" id="sfSlot" value="' + curSlot + '"></div>' +
      '<div class="field"><label class="field-label">프로젝트</label><select class="select" id="sfProject">' + projOpts + '</select></div>' +
      '<div class="field"><label class="field-label">유형</label><select class="select" id="sfType">' + typeOpts + '</select></div>' +
      '</div>' +
      '<div class="field"><label class="field-label">메모</label><textarea class="input" id="sfMemo">' + esc(isEdit ? (ev.memo || '') : '') + '</textarea></div>' +
      '</div>';

    body.querySelector('#sfSlots').addEventListener('click', (e) => {
      const b = e.target.closest('.slot-tab'); if (!b) return;
      body.querySelectorAll('.slot-tab').forEach((s) => s.classList.remove('active'));
      b.classList.add('active'); body.querySelector('#sfSlot').value = b.dataset.slot;
    });

    EDEN.modal({
      title: isEdit ? '일정 수정' : '새 일정', body,
      buttons: [
        { label: '취소', class: 'btn-outline', onClick: (close) => close() },
        {
          label: '저장', class: 'btn-primary', onClick: async (close, btn) => {
            const ids = Array.from(body.querySelectorAll('#sfParts input:checked')).map((c) => c.value);
            const payload = {
              id: isEdit ? ev.id : undefined,
              title: body.querySelector('#sfTitle').value.trim(),
              participant_ids: ids.join(','),
              event_date: body.querySelector('#sfDate').value,
              slot: body.querySelector('#sfSlot').value,
              project_id: body.querySelector('#sfProject').value,
              type: body.querySelector('#sfType').value,
              memo: body.querySelector('#sfMemo').value,
            };
            if (!payload.title || !ids.length || !payload.event_date || !payload.slot) {
              toast('제목·참여 직원·날짜·시간대를 입력하세요.', 'error'); return;
            }
            btn.disabled = true;
            try { if (await submitSave(payload)) close(); }
            catch (e) { toast(e.message, 'error'); }
            finally { btn.disabled = false; }
          },
        },
      ],
    });
  }

  // ── 툴바 ──
  document.getElementById('viewTabs')?.addEventListener('click', (e) => {
    const tab = e.target.closest('.tab'); if (!tab) return;
    document.querySelectorAll('#viewTabs .tab').forEach((t) => t.classList.remove('active'));
    tab.classList.add('active');
    state.view = tab.dataset.view;
    document.getElementById('monthView')?.classList.toggle('active', state.view === 'month');
    document.getElementById('timelineView')?.classList.toggle('active', state.view === 'timeline');
    loadData();
  });
  fUser?.addEventListener('change', () => { state.userId = fUser.value; loadData(); });
  fProject?.addEventListener('change', () => { state.projectId = fProject.value; loadData(); });
  document.getElementById('btnPrev')?.addEventListener('click', () => { shiftRef(-1); loadData(); });
  document.getElementById('btnNext')?.addEventListener('click', () => { shiftRef(1); loadData(); });
  document.getElementById('btnToday')?.addEventListener('click', () => { state.ref = new Date(); loadData(); });
  document.getElementById('btnNewSchedule')?.addEventListener('click', () => openForm(null));
  function shiftRef(dir) {
    if (state.view === 'month') state.ref = new Date(state.ref.getFullYear(), state.ref.getMonth() + dir, 1);
    else { const d = new Date(state.ref); d.setDate(d.getDate() + dir * 7); state.ref = d; }
  }

  loadData();
})();
