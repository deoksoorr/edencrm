/* EDEN CRM — 일정 스케줄러: 월 캘린더 + 직원별 슬롯 타임라인(오전/오후/야간)
   R3: 시간대 복수 선택(slots 배열, 최소 1개) · 다중 참여자 · 색상은 참여 직원 개인색 · 시각 입력 없음(날짜+슬롯) */
(function () {
  'use strict';

  const cfg = window.SCHED_INIT || { canManage: false, canManageAll: false, meId: 0, users: [], slots: {}, types: {} };
  const SLOTS = cfg.slots && Object.keys(cfg.slots).length ? cfg.slots : { morning: '오전', afternoon: '오후', night: '야간' };
  const SLOT_KEYS = ['morning', 'afternoon', 'night'];
  // 유형 목록 단일 출처(Stages::scheduleTypes 미러) — R6: vacation 제거(폼 옵션·표시 비노출)
  const TYPES = cfg.types && Object.keys(cfg.types).length ? cfg.types
    : { work: '작업', meeting: '회의', site_visit: '현장방문', other: '기타' };

  const calRoot = document.getElementById('calRoot');
  const schedRoot = document.getElementById('schedRoot');
  const rangeLabel = document.getElementById('curRangeLabel');
  const fUser = document.getElementById('fUser');
  const fProject = document.getElementById('fProject');
  const fSlot = document.getElementById('fSlot');
  if (!calRoot && !schedRoot) return;

  const state = { view: 'month', ref: new Date(), userId: '', projectId: '', slot: '', data: { schedules: [], holidays: [] } };

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
  function typeLabel(ev) { return ev.type_label || TYPES[ev.type] || ev.type; }
  /** 일정의 슬롯 배열(서버 slots 우선, 없으면 대표 slot 단일). */
  function evSlots(ev) {
    if (ev.slots && ev.slots.length) return ev.slots;
    return [ev.slot && SLOTS[ev.slot] ? ev.slot : 'morning'];
  }
  /** T5 기간 일정 헬퍼: 종료일(단일일이면 시작일), 기간 여부, "7/10~7/15" 라벨 */
  function evEnd(ev) { return ev.end_date && ev.end_date > ev.event_date ? ev.end_date : ev.event_date; }
  function isPeriod(ev) { return evEnd(ev) !== ev.event_date; }
  function periodLabel(ev) {
    if (!isPeriod(ev)) return '';
    const f = (s) => (+s.slice(5, 7)) + '/' + (+s.slice(8, 10));
    return f(ev.event_date) + '~' + f(evEnd(ev));
  }
  /** [event_date, end_date] 구간의 각 날짜에 콜백 실행(캘린더·타임라인 버킷팅 공용) */
  function eachEvDate(ev, fn) {
    let d = new Date(ev.event_date + 'T00:00:00');
    const stop = new Date(evEnd(ev) + 'T00:00:00');
    for (; d <= stop; d.setDate(d.getDate() + 1)) fn(toDateStr(d));
  }

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
    if (state.slot) params.slot = state.slot;
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
    (state.data.schedules || []).forEach((ev) => {
      eachEvDate(ev, (key) => { (byDay[key] = byDay[key] || []).push(ev); }); // T5 기간 일정: 각 날짜 칸에 표시
    });
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
          esc((isPeriod(ev) ? periodLabel(ev) + ' · ' : '') + ev.slot_label + ' · ' + typeLabel(ev) + ' · ' + schedLabel(ev)) + '"><b>[' + esc(partNames(ev)) + ']</b> ' +
          '<span class="ce-slots">' + esc(isPeriod(ev) ? periodLabel(ev) : ev.slot_label) + '</span> ' + esc(ev.title) + '</div>';
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
        const id = String(e.dataTransfer.getData('text/plain')).split(':')[0];
        const ev = (state.data.schedules || []).find((x) => String(x.id) === id);
        if (ev) moveSchedule(ev, cell.dataset.date, evSlots(ev)); // 날짜 변경, 슬롯 전체 유지
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

    // index events: user -> date -> slot -> [ev] (복수 슬롯 일정은 해당 슬롯 행마다 표시)
    const idx = {};
    (state.data.schedules || []).forEach((ev) => {
      (ev.participants || []).forEach((p) => {
        eachEvDate(ev, (ds) => { // T5 기간 일정: 기간 내 각 요일에 표시
          idx[p.user_id] = idx[p.user_id] || {};
          idx[p.user_id][ds] = idx[p.user_id][ds] || {};
          evSlots(ev).forEach((sk) => {
            (idx[p.user_id][ds][sk] = idx[p.user_id][ds][sk] || []).push(ev);
          });
        });
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
            chips += '<div class="sched-chip" draggable="true" data-id="' + ev.id + '" data-slot="' + sk + '" style="background:' + uc + '" title="' + esc(ev.slot_label + ' · ' + typeLabel(ev) + ' · ' + ev.title + ' · ' + partNames(ev)) + '">' + esc(ev.title) + '</div>';
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
      // 'id:출발슬롯' — 드롭 시 출발 슬롯만 대상 슬롯으로 치환(나머지 슬롯 유지)
      el.addEventListener('dragstart', (e) => e.dataTransfer.setData('text/plain', el.dataset.id + ':' + (el.dataset.slot || '')));
    });
    schedRoot.querySelectorAll('.sched-slot').forEach((slot) => {
      slot.addEventListener('dragover', (e) => { e.preventDefault(); slot.classList.add('drop-hover'); });
      slot.addEventListener('dragleave', () => slot.classList.remove('drop-hover'));
      slot.addEventListener('drop', (e) => {
        e.preventDefault(); slot.classList.remove('drop-hover');
        const parts = String(e.dataTransfer.getData('text/plain')).split(':');
        const ev = (state.data.schedules || []).find((x) => String(x.id) === parts[0]);
        if (!ev) return;
        const srcSlot = parts[1] || evSlots(ev)[0];
        const target = slot.dataset.slot;
        // 출발 슬롯 → 대상 슬롯 치환 후 중복 제거·표준 순서 정렬
        const next = evSlots(ev).map((s) => (s === srcSlot ? target : s));
        const set = SLOT_KEYS.filter((k) => next.indexOf(k) !== -1);
        moveSchedule(ev, slot.dataset.date, set);
      });
    });
  }

  // ── 이동/저장 ──
  async function moveSchedule(ev, newDate, newSlots) {
    const cur = evSlots(ev).join(',');
    const next = (newSlots || []).join(',');
    if (ev.event_date === newDate && cur === next) return;
    await submitMove({ id: ev.id, event_date: newDate, slots: next });
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
      '<dt>날짜</dt><dd>' + esc(ev.event_date) + (isPeriod(ev) ? ' ~ ' + esc(evEnd(ev)) : '') + '</dd>' +
      '<dt>시간대</dt><dd>' + esc(ev.slot_label) + '</dd>' +
      '<dt>프로젝트</dt><dd>' + (ev.project_name ? esc(ev.project_no + ' · ' + ev.project_name) : '-') + '</dd>' +
      '<dt>유형</dt><dd>' + esc(typeLabel(ev)) + '</dd>' +
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
    const typeOpts = Object.keys(TYPES) // 서버 목록(Stages::scheduleTypes) 그대로 — R6: vacation 없음
      .map((k) => '<option value="' + k + '"' + (isEdit && ev.type === k ? ' selected' : '') + '>' + esc(TYPES[k]) + '</option>').join('');
    const curSlots = isEdit ? evSlots(ev) : ['morning']; // 복수 선택(토글) — 최소 1개
    const slotTabs = SLOT_KEYS.map((k) => '<button type="button" class="slot-tab' + (curSlots.indexOf(k) !== -1 ? ' active' : '') + '" data-slot="' + k + '" aria-pressed="' + (curSlots.indexOf(k) !== -1) + '">' + esc(SLOTS[k]) + '</button>').join('');

    const body = document.createElement('div');
    body.innerHTML =
      '<div class="form">' +
      '<div class="field"><label class="field-label">제목 <span class="req">*</span></label><input class="input" id="sfTitle" value="' + esc(isEdit ? ev.title : '') + '"></div>' +
      '<div class="field"><label class="field-label">참여 직원 <span class="req">*</span></label><div class="part-picker" id="sfParts">' + partList + '</div></div>' +
      '<div class="form-grid">' +
      '<div class="field"><label class="field-label">시작일 <span class="req">*</span></label><input class="input" type="date" id="sfDate" value="' + (isEdit ? esc(ev.event_date) : toDateStr(state.ref)) + '"></div>' +
      '<div class="field"><label class="field-label">종료일 <span class="muted" style="font-weight:400">(기간 일정 — 미입력 시 하루)</span></label><input class="input" type="date" id="sfEndDate" value="' + (isEdit && isPeriod(ev) ? esc(evEnd(ev)) : '') + '"></div>' +
      '<div class="field"><label class="field-label">시간대 <span class="req">*</span> <span class="muted" style="font-weight:400">(복수 선택 가능)</span></label><div class="slot-tabs" id="sfSlots">' + slotTabs + '</div></div>' +
      '<div class="field"><label class="check"><input type="checkbox" id="sfAllDay"' + (isEdit && String(ev.all_day) === '1' ? ' checked' : '') + '> 종일(전 시간대)</label></div>' +
      '<div class="field"><label class="field-label">프로젝트</label><select class="select" id="sfProject">' + projOpts + '</select></div>' +
      '<div class="field"><label class="field-label">유형</label><select class="select" id="sfType">' + typeOpts + '</select></div>' +
      '</div>' +
      '<div class="field"><label class="field-label">메모</label><textarea class="input" id="sfMemo">' + esc(isEdit ? (ev.memo || '') : '') + '</textarea></div>' +
      '</div>';

    body.querySelector('#sfSlots').addEventListener('click', (e) => {
      const b = e.target.closest('.slot-tab'); if (!b) return;
      b.classList.toggle('active'); // 복수 선택 토글 (최소 1개는 저장 시 검증)
      b.setAttribute('aria-pressed', b.classList.contains('active') ? 'true' : 'false');
    });
    body.querySelector('#sfAllDay').addEventListener('change', (e) => {
      if (!e.target.checked) return; // 종일 체크 시 전 슬롯 활성화(해제는 슬롯 개별 조작)
      body.querySelectorAll('#sfSlots .slot-tab').forEach((b) => { b.classList.add('active'); b.setAttribute('aria-pressed', 'true'); });
    });

    EDEN.modal({
      title: isEdit ? '일정 수정' : '일정 등록', body,
      buttons: [
        { label: '취소', class: 'btn-outline', onClick: (close) => close() },
        {
          label: '저장', class: 'btn-primary', onClick: async (close, btn) => {
            const ids = Array.from(body.querySelectorAll('#sfParts input:checked')).map((c) => c.value);
            const slots = Array.from(body.querySelectorAll('#sfSlots .slot-tab.active')).map((b) => b.dataset.slot);
            const payload = {
              id: isEdit ? ev.id : undefined,
              title: body.querySelector('#sfTitle').value.trim(),
              participant_ids: ids.join(','),
              event_date: body.querySelector('#sfDate').value,
              end_date: body.querySelector('#sfEndDate').value || body.querySelector('#sfDate').value,
              all_day: body.querySelector('#sfAllDay').checked ? 1 : 0,
              slots: slots.join(','),
              project_id: body.querySelector('#sfProject').value,
              type: body.querySelector('#sfType').value,
              memo: body.querySelector('#sfMemo').value,
            };
            if (!slots.length) {
              toast('시간대(오전/오후/야간)를 1개 이상 선택하세요.', 'error'); return;
            }
            if (!payload.title || !ids.length || !payload.event_date) {
              toast('제목·참여 직원·날짜를 입력하세요.', 'error'); return;
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
  fSlot?.addEventListener('change', () => { state.slot = fSlot.value; loadData(); });
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
