/* 리포트 화면: 기간필터 → reports.data 호출 → 차트(Chart.js)/표 렌더 + CSV 내보내기.
   차트는 대시보드와 동일한 절제된 팔레트·억/만 축약 축·하단 소형 범례 규격을 따른다. */
(function () {
  'use strict';

  const fmtMoney = (n) => (n === null || n === undefined || n === '') ? '-' : Number(n).toLocaleString('ko-KR');
  const fmtPct = (n) => (n === null || n === undefined || n === '') ? '-' : Number(n).toFixed(1) + '%';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // 억/만 축약(축·툴팁) — dashboard.js 와 동일 규칙
  function moneyShort(n) {
    if (n === null || n === undefined) return '-';
    const s = n < 0 ? '-' : '';
    const a = Math.abs(Number(n));
    if (a >= 1e8) { const v = a / 1e8; return s + (v === Math.floor(v) ? v : v.toFixed(1)) + '억'; }
    if (a >= 1e4) return s + Math.round(a / 1e4).toLocaleString('ko-KR') + '만';
    return s + a.toLocaleString('ko-KR');
  }
  const won = (n) => (n === null || n === undefined ? '-' : Number(n).toLocaleString('ko-KR') + '원');

  // 포인트 1색 + 상태색 + 중립 2색 (무지개 금지)
  const COLORS = ['#1a56db', '#0f9d58', '#e8710a', '#d93025', '#6b7280', '#94a3b8'];
  const BRAND = COLORS[0], OK = COLORS[1];

  if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "-apple-system,BlinkMacSystemFont,'Segoe UI','Apple SD Gothic Neo','Malgun Gothic',sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#6b7280';
  }
  const bottomLegend = { position: 'bottom', labels: { boxWidth: 12, boxHeight: 12, padding: 14, usePointStyle: true, font: { size: 11.5 } } };

  const charts = {};
  // 데이터가 있으면 차트, 없으면 .chart-empty 안내문(빈 캔버스 방지). 캔버스 요소는 유지해 재조회 시 재사용.
  function renderChart(id, config, isEmpty) {
    const el = document.getElementById(id);
    if (!el || typeof Chart === 'undefined') return;
    const box = el.closest('.chart-box');
    if (box) { const old = box.querySelector('.chart-empty'); if (old) old.remove(); }
    if (charts[id]) { charts[id].destroy(); delete charts[id]; }
    if (isEmpty) {
      el.style.display = 'none';
      if (box) box.insertAdjacentHTML('beforeend', '<div class="chart-empty"><span class="ce-ico">◔</span><span>표시할 데이터가 없습니다.</span></div>');
      return;
    }
    el.style.display = '';
    charts[id] = new Chart(el.getContext('2d'), config);
  }

  function statusLabel(s) {
    return { preparing: '준비중', in_progress: '진행중', paused: '중지', completed: '완료' }[s] || s;
  }

  function periodParams() {
    const period = document.getElementById('fPeriod').value;
    const params = { period };
    if (period === 'custom') {
      params.from = document.getElementById('fFrom').value;
      params.to = document.getElementById('fTo').value;
    }
    return params;
  }

  function toggleCustomFields() {
    const isCustom = document.getElementById('fPeriod').value === 'custom';
    document.getElementById('fFrom').classList.toggle('hidden', !isCustom);
    document.getElementById('fTo').classList.toggle('hidden', !isCustom);
    document.getElementById('fSep').classList.toggle('hidden', !isCustom);
  }

  function rowsHtml(list, mapFn, colCount) {
    if (!list || !list.length) return '<tr><td colspan="' + colCount + '" class="loading-row">데이터가 없습니다.</td></tr>';
    return list.map(mapFn).join('');
  }

  async function loadReport() {
    const params = periodParams();
    let data;
    try {
      data = await api('reports.data', params);
    } catch (err) {
      toast(err.message, 'error');
      return;
    }

    document.getElementById('periodLabel').textContent = data.period.label + ' (' + data.period.from + ' ~ ' + data.period.to + ') 기준';

    // 요약 KPI — 큰 금액은 억/만 축약(정확값 title)
    document.getElementById('stNewCustomers').innerHTML = fmtMoney(data.new_customers.count) + '<span class="u">명</span>';
    document.getElementById('stQuoteRate').textContent = fmtPct(data.quote_conversion.rate);
    const rcv = document.getElementById('stReceivable');
    rcv.innerHTML = '<span class="mono">' + esc(moneyShort(data.receivables.total)) + '</span><span class="u">원</span>';
    rcv.title = won(data.receivables.total);
    document.getElementById('stRevenueRate').textContent = fmtPct(data.target_achievement.revenue_rate);
    document.getElementById('stProfitRate').textContent = fmtPct(data.target_achievement.profit_rate);

    // 차트: 월별 매출(막대)·순이익(선)
    const mt = data.monthly_trend || [];
    renderChart('chartMonthly', {
      data: {
        labels: mt.map((r) => r.ym),
        datasets: [
          { type: 'bar', label: '매출', data: mt.map((r) => r.revenue), backgroundColor: BRAND, borderRadius: 4, maxBarThickness: 42, order: 2 },
          { type: 'line', label: '순이익', data: mt.map((r) => r.profit), borderColor: OK, backgroundColor: OK, borderWidth: 2, tension: 0.35, pointRadius: 3, pointHoverRadius: 5, order: 1 },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: bottomLegend,
          tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + won(c.parsed.y) } },
        },
        scales: {
          x: { grid: { display: false } },
          y: { border: { display: false }, grid: { color: '#eef1f4' }, ticks: { callback: (v) => moneyShort(v) } },
        },
      },
    }, !mt.length);

    // 차트: 유입경로별 고객(도넛)
    const src = data.by_source || [];
    const srcTotal = src.reduce((s, r) => s + Number(r.cnt), 0);
    renderChart('chartSource', {
      type: 'doughnut',
      data: {
        labels: src.map((r) => r.source),
        datasets: [{ data: src.map((r) => r.cnt), backgroundColor: src.map((_, i) => COLORS[i % COLORS.length]), borderWidth: 2, borderColor: '#fff' }],
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '58%',
        plugins: {
          legend: bottomLegend,
          tooltip: { callbacks: { label: (c) => c.label + ': ' + c.parsed + '명 (' + (srcTotal ? (c.parsed / srcTotal * 100).toFixed(0) : 0) + '%)' } },
        },
      },
    }, !src.length);

    // 차트: 영업단계별 건수(가로 막대)
    const stg = data.by_stage || [];
    renderChart('chartStage', {
      type: 'bar',
      data: {
        labels: stg.map((r) => r.stage),
        datasets: [{ label: '건수', data: stg.map((r) => r.cnt), backgroundColor: BRAND, borderRadius: 4, maxBarThickness: 22 }],
      },
      options: {
        responsive: true, maintainAspectRatio: false, indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (c) => c.parsed.x + '건' } },
        },
        scales: {
          x: { border: { display: false }, grid: { color: '#eef1f4' }, ticks: { precision: 0 } },
          y: { grid: { display: false } },
        },
      },
    }, !stg.length);

    // 차트: 공사유형별 매출(막대)
    const wt = data.by_work_type || [];
    renderChart('chartWorkType', {
      type: 'bar',
      data: {
        labels: wt.map((r) => r.work_type),
        datasets: [{ label: '매출', data: wt.map((r) => r.revenue), backgroundColor: BRAND, borderRadius: 4, maxBarThickness: 42 }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (c) => won(c.parsed.y) } },
        },
        scales: {
          x: { grid: { display: false } },
          y: { border: { display: false }, grid: { color: '#eef1f4' }, ticks: { callback: (v) => moneyShort(v) } },
        },
      },
    }, !wt.length);

    // 표: 영업직원별 계약률
    document.getElementById('tbSalesConversion').innerHTML = rowsHtml(data.sales_conversion, (r) => {
      const rate = r.total_leads > 0 ? (r.won_leads / r.total_leads * 100) : null;
      return '<tr><td>' + esc(r.name) + '</td><td class="num mono">' + fmtMoney(r.total_leads) + '</td><td class="num mono">' + fmtMoney(r.won_leads) + '</td><td class="num mono">' + fmtPct(rate) + '</td></tr>';
    }, 4);

    // 표: 프로젝트별 손익 (정밀 금액 — number_format 유지)
    document.getElementById('tbProjectPl').innerHTML = rowsHtml(data.project_pl, (r) => {
      const profitClass = r.profit < 0 ? ' num mono text-danger' : ' num mono';
      return '<tr><td>' + esc(r.project_no) + ' ' + esc(r.name) + '</td><td><span class="badge badge-info">' + esc(statusLabel(r.status)) + '</span></td>'
        + '<td class="num mono">' + fmtMoney(r.revenue) + '</td><td class="num mono">' + fmtMoney(r.cost) + '</td>'
        + '<td class="' + profitClass.trim() + '">' + fmtMoney(r.profit) + '</td><td class="num mono">' + fmtPct(r.profit_rate) + '</td></tr>';
    }, 6);

    // 표: 직원별 성과
    document.getElementById('tbStaffPerf').innerHTML = rowsHtml(data.staff_performance, (r) => {
      const profitClass = r.profit < 0 ? 'num mono text-danger' : 'num mono';
      return '<tr><td>' + esc(r.name) + '</td><td class="num mono">' + fmtMoney(r.cnt) + '</td><td class="num mono">' + fmtMoney(r.revenue) + '</td>'
        + '<td class="num mono">' + fmtMoney(r.cost) + '</td><td class="' + profitClass + '">' + fmtMoney(r.profit) + '</td></tr>';
    }, 5);

    // 표: 지연 프로젝트
    document.getElementById('tbDelayed').innerHTML = rowsHtml(data.delayed_projects, (r) => {
      return '<tr><td>' + esc(r.project_no) + ' ' + esc(r.name) + '</td><td>' + esc(r.end_date) + '</td>'
        + '<td class="num mono text-danger">' + fmtMoney(r.days_over) + '일</td><td>' + esc(r.site_manager || '-') + '</td></tr>';
    }, 4);

    // 표: 원가초과 프로젝트
    document.getElementById('tbCostOverrun').innerHTML = rowsHtml(data.cost_overrun, (r) => {
      return '<tr><td>' + esc(r.project_no) + ' ' + esc(r.name) + '</td><td class="num mono">' + fmtMoney(r.estimated_cost) + '</td>'
        + '<td class="num mono">' + fmtMoney(r.actual_cost) + '</td><td class="num mono text-danger">' + fmtMoney(r.over_amount) + '</td><td class="num mono">' + fmtPct(r.over_rate) + '</td></tr>';
    }, 5);

    // 표: 미수금 현황
    document.getElementById('tbReceivables').innerHTML = rowsHtml(data.receivables.list, (r) => {
      return '<tr><td>' + esc(r.contract_no) + '</td><td>' + esc(r.customer_name) + '</td><td class="num mono">' + fmtMoney(r.contract_amount) + '</td>'
        + '<td class="num mono">' + fmtMoney(r.paid) + '</td><td class="num mono text-danger">' + fmtMoney(r.receivable) + '</td></tr>';
    }, 5);
  }

  document.getElementById('fPeriod').addEventListener('change', toggleCustomFields);
  document.getElementById('btnApply').addEventListener('click', loadReport);

  const btnExport = document.getElementById('btnExport');
  if (btnExport) {
    btnExport.addEventListener('click', function () {
      const params = Object.assign({ type: document.getElementById('exportType').value }, periodParams());
      window.location.href = EDEN.url('reports.export', params);
    });
  }

  toggleCustomFields();
  loadReport();
})();
