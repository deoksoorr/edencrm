/* EDEN CRM — 일정 스케줄러: 월 캘린더 + 직원별 주간 타임라인 (T7) */
(function () {
  'use strict';

  const cfg = window.SCHED_INIT || { canManage: false, canManageAll: false, meId: 0, users: [] };

  const calRoot = document.getElementById('calRoot');
  const schedRoot = document.getElementById('schedRoot');
  const rangeLabel = document.getElementById('curRangeLabel');
  const fUser = document.getElementById('fUser');
  const fProject = document.getElementById('fProject');

  if (!calRoot && !schedRoot) return; // 이 페이지가 아니면 아무것도 하지 않음

  const state = {
    view: 'month',
    ref: new Date(),
    userId: '',
    projectId: '',
    data: { schedules: [], holidays: [] },
  };

  function pad(n) { return String(n).padStart(2, '0'); }
  function toDateStr(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parseDT(s) { return new Date(String(s).replace(' ', 'T')); }
  function dateOnly(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function fmtDT(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }
  function toLocalInput(s) { return s ? String(s).replace(' ', 'T').slice(0, 16) : ''; }
  function fromLocalInput(s) { return s ? s.replace('T', ' ') + ':00' : ''; }

  function monthRange(d) {
    const first = new Date(d.getFullYear(), d.getMonth(), 1);
    const last = new Date(d.getFullYear(), d.getMonth() + 1, 0);
    const gridStart = new Date(first); gridStart.setDate(first.getDate() - first.getDay());
    const gridEnd = new Date(last); gridEnd.setDate(last.getDate() + (6 - last.getDay()));
    return { first, last, gridStart, gridEnd };
  }

  function weekRange(d) {
    const day = d.getDay();
    const diffToMon = day === 0 ? -6 : 1 - day;
    const start = new Date(d); start.setDate(d.getDate() + diffToMon); start.setHours(0, 0, 0, 0);
    const end = new Date(start); end.setDate(start.getDate() + 6);
    return { start, end };
  }

  const PALETTE = ['#1a56db', '#0f9d58', '#e8710a', '#8e44ad', '#16a2b8', '#c2185b', '#5d4037', '#455a64'];
  function colorForProject(pid) {
    if (!pid) return '#6b7280';
    let h = 0; const s = String(pid);
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return PALETTE[h % PALETTE.length];
  }
  function evColor(ev) { return ev.color || colorForProject(ev.project_id); }

  async function loadData() {
    let from, to;
    if (state.view === 'month') {
      const { gridStart, gridEnd } = monthRange(state.ref);
      from = toDateStr(gridStart); to = toDateStr(gridEnd);
    } else {
      const { start, end } = weekRange(state.ref);
      from = toDateStr(start); to = toDateStr(end);
    }
    const params = { from, to };
    if (state.userId) params.user_id = state.userId;
    if (state.projectId) params.project_id = state.projectId;
    try {
      const data = await api('schedule.data', params);
      state.data = data || { schedules: [], holidays: [] };
      render();
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  function updateRangeLabel() {
    if (!rangeLabel) return;
    if (state.view === 'month') {
      rangeLabel.textContent = state.ref.getFullYear() + '년 ' + (state.ref.getMonth() + 1) + '월';
    } else {
      const { start, end } = weekRange(state.ref);
      rangeLabel.textContent = toDateStr(start) + ' ~ ' + toDateStr(end);
    }
  }

  function render() {
    updateRangeLabel();
    if (state.view === 'month') renderMonth(); else renderTimeline();
  }

  function holidaySet() {
    const set = {};
    (state.data.holidays || []).forEach((h) => { set[h.holiday_date] = h.name; });
    return set;
  }

  function renderMonth() {
    if (!calRoot) return;
    const { gridStart } = monthRange(state.ref);
    const todayStr = toDateStr(new Date());
    const hset = holidaySet();
    const byDay = {};
    (state.data.schedules || []).forEach((ev) => {
      const s = parseDT(ev.start_datetime), e = parseDT(ev.end_datetime);
      const cur = new Date(s.getFullYear(), s.getMonth(), s.getDate());
      const endDay = new Date(e.getFullYear(), e.getMonth(), e.getDate());
      while (cur <= endDay) {
        const key = toDateStr(cur);
        (byDay[key] = byDay[key] || []).push(ev);
        cur.setDate(cur.getDate() + 1);
      }
    });

    let html = '<div class="cal-head"><div>일</div><div>월</div><div>화</div><div>수</div><div>목</div><div>금</div><div>토</div></div><div class="cal-grid">';
    const cur = new Date(gridStart);
    for (let i = 0; i < 42; i++) {
      const key = toDateStr(cur);
      const inMonth = cur.getMonth() === state.ref.getMonth();
      const holName = hset[key];
      const classes = ['cal-cell'];
      if (!inMonth) classes.push('other');
      if (key === todayStr) classes.push('today');
      if (holName) classes.push('holiday');
      const evs = byDay[key] || [];
      const shown = evs.slice(0, 3);
      const more = evs.length - 3;
      html += '<div class="' + classes.join(' ') + '" data-date="' + key + '">';
      html += '<div class="cal-date">' + cur.getDate() + '</div>';
      shown.forEach((ev) => {
        html += '<div class="cal-ev" draggable="true" data-id="' + ev.id + '" style="background:' + evColor(ev) + ';color:#fff" title="' + esc(ev.title) + ' · ' + esc(ev.user_name) + '">' + esc(ev.title) + ' · ' + esc(ev.user_name) + '</div>';
      });
      if (more > 0) html += '<div class="cal-more">+' + more + '건 더보기</div>';
      html += '</div>';
      cur.setDate(cur.getDate() + 1);
    }
    html += '</div>';
    calRoot.innerHTML = html;
    bindCalEvents();
  }

  function bindCalEvents() {
    calRoot.querySelectorAll('.cal-ev').forEach((el) => {
      el.addEventListener('click', (e) => { e.stopPropagation(); openDetail(parseInt(el.dataset.id, 10)); });
      el.addEventListener('dragstart', (e) => { e.dataTransfer.setData('text/plain', el.dataset.id); });
    });
    calRoot.querySelectorAll('.cal-cell').forEach((cell) => {
      cell.addEventListener('dragover', (e) => e.preventDefault());
      cell.addEventListener('drop', async (e) => {
        e.preventDefault();
        const id = e.dataTransfer.getData('text/plain');
        if (!id) return;
        const ev = (state.data.schedules || []).find((x) => String(x.id) === id);
        if (!ev) return;
        await moveSchedule(ev, cell.dataset.date, null);
      });
    });
  }

  function renderTimeline() {
    if (!schedRoot) return;
    const { start } = weekRange(state.ref);
    const days = [];
    for (let i = 0; i < 7; i++) { const d = new Date(start); d.setDate(start.getDate() + i); days.push(d); }
    const dayNames = ['일', '월', '화', '수', '목', '금', '토'];

    let users = cfg.users || [];
    if (!cfg.canManageAll) users = users.filter((u) => u.id === cfg.meId);
    if (state.userId) users = users.filter((u) => String(u.id) === String(state.userId));

    let head = '<div class="sched-header"><div></div><div class="sched-days">';
    days.forEach((d) => { head += '<div>' + dayNames[d.getDay()] + ' ' + (d.getMonth() + 1) + '/' + d.getDate() + '</div>'; });
    head += '</div></div>';

    let rows = '';
    if (!users.length) {
      rows = '<div class="kanban-empty" style="margin:16px">표시할 직원이 없습니다.</div>';
    }
    users.forEach((u) => {
      const evs = (state.data.schedules || []).filter((e) => e.user_id === u.id);
      let bars = '';
      evs.forEach((ev) => {
        const s = parseDT(ev.start_datetime), e = parseDT(ev.end_datetime);
        let startOff = (dateOnly(s) - dateOnly(days[0])) / 86400000;
        let endOff = (dateOnly(e) - dateOnly(days[0])) / 86400000;
        if (endOff < 0 || startOff > 6) return;
        startOff = Math.max(0, startOff);
        endOff = Math.min(6, endOff);
        const span = Math.max(1, endOff - startOff + 1);
        const left = (startOff / 7 * 100);
        const width = (span / 7 * 100);
        bars += '<div class="sched-bar" draggable="true" data-id="' + ev.id + '" style="left:' + left + '%;width:calc(' + width + '% - 4px);background:' + evColor(ev) + '" title="' + esc(ev.title) + '">' + esc(ev.title) + '</div>';
      });
      let daycols = '<div class="sched-daycols">';
      for (let i = 0; i < 7; i++) daycols += '<div data-day="' + i + '"></div>';
      daycols += '</div>';
      rows += '<div class="sched-row"><div class="sched-name">' + esc(u.name) + '</div><div class="sched-lane" data-user="' + u.id + '">' + daycols + bars + '</div></div>';
    });

    schedRoot.innerHTML = head + rows;
    bindTimelineEvents(days);
  }

  function bindTimelineEvents(days) {
    schedRoot.querySelectorAll('.sched-bar').forEach((el) => {
      el.addEventListener('click', (e) => { e.stopPropagation(); openDetail(parseInt(el.dataset.id, 10)); });
      el.addEventListener('dragstart', (e) => { e.dataTransfer.setData('text/plain', el.dataset.id); });
    });
    schedRoot.querySelectorAll('.sched-lane').forEach((lane) => {
      lane.addEventListener('dragover', (e) => e.preventDefault());
      lane.addEventListener('drop', async (e) => {
        e.preventDefault();
        const id = e.dataTransfer.getData('text/plain');
        if (!id) return;
        const ev = (state.data.schedules || []).find((x) => String(x.id) === id);
        if (!ev) return;
        const rect = lane.getBoundingClientRect();
        const relX = (e.clientX - rect.left) / rect.width;
        const dayIdx = Math.min(6, Math.max(0, Math.floor(relX * 7)));
        const newUserId = parseInt(lane.dataset.user, 10);
        await moveSchedule(ev, toDateStr(days[dayIdx]), newUserId);
      });
    });
  }

  async function moveSchedule(ev, newDateStr, newUserId) {
    const s = parseDT(ev.start_datetime), e = parseDT(ev.end_datetime);
    const durMs = e - s;
    const [y, m, d] = newDateStr.split('-').map(Number);
    const newStart = new Date(y, m - 1, d, s.getHours(), s.getMinutes(), s.getSeconds());
    const newEnd = new Date(newStart.getTime() + durMs);
    const payload = { id: ev.id, start_datetime: fmtDT(newStart), end_datetime: fmtDT(newEnd) };
    if (newUserId) payload.user_id = newUserId;
    await submitMove(payload);
  }

  async function submitMove(payload, confirmed) {
    if (confirmed) payload.confirmed = 1;
    try {
      const res = await api('schedule.move', payload);
      if (res && res.conflict) {
        const ok = await showConflictModal(res.conflicts);
        if (ok) { await submitMove(payload, true); return; }
        toast('일정 이동이 취소되었습니다.', 'warn');
      } else {
        toast('일정이 이동되었습니다.', 'success');
      }
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      await loadData(); // 성공/실패/취소 모두 서버 상태로 다시 그려 원복을 보장
    }
  }

  function showConflictModal(conflicts) {
    return new Promise((resolve) => {
      let body = '<p style="margin-top:0">해당 직원의 다른 일정과 시간이 겹칩니다.</p>';
      (conflicts || []).forEach((c) => {
        body += '<div class="sched-bar conflict" style="position:static;display:block;margin-bottom:6px;background:#fff;color:var(--ink)">'
          + esc(c.title) + ' — ' + esc(c.user_name) + ' (' + esc(c.start_datetime) + ' ~ ' + esc(c.end_datetime) + ')</div>';
      });
      body += '<p style="margin-bottom:0">그래도 저장하시겠습니까?</p>';
      EDEN.modal({
        title: '일정 충돌 경고',
        body,
        buttons: [
          { label: '취소', class: 'btn-outline', onClick: (close) => { close(); resolve(false); } },
          { label: '승인하고 저장', class: 'btn-danger', onClick: (close) => { close(); resolve(true); } },
        ],
      });
    });
  }

  function openDetail(id) {
    const ev = (state.data.schedules || []).find((x) => x.id === id);
    if (!ev) return;
    const body = document.createElement('div');
    body.innerHTML =
      '<div class="dl">' +
      '<dt>제목</dt><dd>' + esc(ev.title) + '</dd>' +
      '<dt>직원</dt><dd>' + esc(ev.user_name) + '</dd>' +
      '<dt>프로젝트</dt><dd>' + (ev.project_name ? esc(ev.project_no + ' · ' + ev.project_name) : '-') + '</dd>' +
      '<dt>시작</dt><dd>' + esc(ev.start_datetime) + '</dd>' +
      '<dt>종료</dt><dd>' + esc(ev.end_datetime) + '</dd>' +
      '<dt>유형</dt><dd>' + esc(ev.type) + '</dd>' +
      '<dt>상태</dt><dd>' + esc(ev.status) + '</dd>' +
      '<dt>메모</dt><dd>' + esc(ev.memo || '-') + '</dd>' +
      '</div>';
    const buttons = [{ label: '닫기', class: 'btn-outline', onClick: (close) => close() }];
    if (cfg.canManage) {
      buttons.unshift({
        label: '삭제', class: 'btn-danger', onClick: async (close) => {
          close();
          const ok = await EDEN.confirm('이 일정을 삭제하시겠습니까?', { danger: true });
          if (!ok) return;
          try { await api('schedule.delete', { id: ev.id }); toast('삭제되었습니다.', 'success'); await loadData(); }
          catch (e) { toast(e.message, 'error'); }
        },
      });
      buttons.unshift({ label: '수정', class: 'btn-primary', onClick: (close) => { close(); openForm(ev); } });
    }
    EDEN.modal({ title: '일정 상세', body, buttons });
  }

  function openForm(ev) {
    const isEdit = !!ev;
    const usersOpts = (cfg.users || []).map((u) => '<option value="' + u.id + '"' + (isEdit && ev.user_id === u.id ? ' selected' : '') + '>' + esc(u.name) + '</option>').join('');
    const projOpts = '<option value="">없음</option>' + Array.from(fProject ? fProject.options : []).filter((o) => o.value).map((o) => '<option value="' + o.value + '"' + (isEdit && String(ev.project_id) === o.value ? ' selected' : '') + '>' + o.textContent + '</option>').join('');
    const typeOpts = ['work', 'meeting', 'vacation', 'site_visit', 'other'].map((t) => '<option value="' + t + '"' + (isEdit && ev.type === t ? ' selected' : '') + '>' + t + '</option>').join('');

    const body = document.createElement('div');
    body.innerHTML =
      '<div class="form">' +
      '<div class="field"><label class="field-label">제목 <span class="req">*</span></label><input class="input" id="sfTitle" value="' + esc(isEdit ? ev.title : '') + '"></div>' +
      '<div class="form-grid">' +
      '<div class="field"><label class="field-label">직원 <span class="req">*</span></label><select class="select" id="sfUser">' + usersOpts + '</select></div>' +
      '<div class="field"><label class="field-label">프로젝트</label><select class="select" id="sfProject">' + projOpts + '</select></div>' +
      '<div class="field"><label class="field-label">시작 <span class="req">*</span></label><input class="input" type="datetime-local" id="sfStart" value="' + (isEdit ? toLocalInput(ev.start_datetime) : '') + '"></div>' +
      '<div class="field"><label class="field-label">종료 <span class="req">*</span></label><input class="input" type="datetime-local" id="sfEnd" value="' + (isEdit ? toLocalInput(ev.end_datetime) : '') + '"></div>' +
      '<div class="field"><label class="field-label">유형</label><select class="select" id="sfType">' + typeOpts + '</select></div>' +
      '<div class="field"><label class="field-label">색상</label><input class="input" type="color" id="sfColor" value="' + (isEdit && ev.color ? ev.color : '#1a56db') + '"></div>' +
      '</div>' +
      '<div class="field"><label class="field-label"><input type="checkbox" id="sfAllDay"' + (isEdit && Number(ev.all_day) === 1 ? ' checked' : '') + '> 종일 일정</label></div>' +
      '<div class="field"><label class="field-label">메모</label><textarea class="input" id="sfMemo">' + esc(isEdit ? (ev.memo || '') : '') + '</textarea></div>' +
      '</div>';

    EDEN.modal({
      title: isEdit ? '일정 수정' : '새 일정',
      wide: false,
      body,
      buttons: [
        { label: '취소', class: 'btn-outline', onClick: (close) => close() },
        {
          label: '저장', class: 'btn-primary', onClick: async (close, btn) => {
            const payload = {
              id: isEdit ? ev.id : undefined,
              title: document.getElementById('sfTitle').value.trim(),
              user_id: document.getElementById('sfUser').value,
              project_id: document.getElementById('sfProject').value,
              start_datetime: fromLocalInput(document.getElementById('sfStart').value),
              end_datetime: fromLocalInput(document.getElementById('sfEnd').value),
              all_day: document.getElementById('sfAllDay').checked ? 1 : 0,
              type: document.getElementById('sfType').value,
              color: document.getElementById('sfColor').value,
              memo: document.getElementById('sfMemo').value,
            };
            if (!payload.title || !payload.user_id || !payload.start_datetime || !payload.end_datetime) {
              toast('필수 항목을 입력하세요.', 'error');
              return;
            }
            btn.disabled = true;
            try {
              await submitSave(payload);
              close();
            } finally {
              btn.disabled = false;
            }
          },
        },
      ],
    });
  }

  async function submitSave(payload, confirmed) {
    if (confirmed) payload.confirmed = 1;
    const res = await api('schedule.save', payload);
    if (res && res.conflict) {
      const ok = await showConflictModal(res.conflicts);
      if (ok) { await submitSave(payload, true); return; }
      toast('저장이 취소되었습니다.', 'warn');
      return;
    }
    toast('저장되었습니다.', 'success');
    await loadData();
  }

  // ── 툴바 바인딩 ──
  document.getElementById('viewTabs')?.addEventListener('click', (e) => {
    const tab = e.target.closest('.tab');
    if (!tab) return;
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
    if (state.view === 'month') {
      state.ref = new Date(state.ref.getFullYear(), state.ref.getMonth() + dir, 1);
    } else {
      const d = new Date(state.ref); d.setDate(d.getDate() + dir * 7); state.ref = d;
    }
  }

  loadData();
})();
