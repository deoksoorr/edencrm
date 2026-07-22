/* 리포트 화면: 기간필터 → reports.data 호출 → 차트(Chart.js)/표 렌더 + CSV 내보내기 */
(function () {
  'use strict';

  const fmtMoney = (n) => (n === null || n === undefined || n === '') ? '-' : Number(n).toLocaleString('ko-KR');
  const fmtPct = (n) => (n === null || n === undefined || n === '') ? '-' : Number(n).toFixed(1) + '%';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const COLORS = ['#1a56db', '#0f9d58', '#e8710a', '#d93025', '#6b7280', '#7c3aed', '#0891b2', '#c026d3'];

  const charts = {};
  function renderChart(id, config) {
    const el = document.getElementById(id);
    if (!el || typeof Chart === 'undefined') return;
    if (charts[id]) charts[id].destroy();
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

    // 요약 카드
    document.getElementById('stNewCustomers').innerHTML = fmtMoney(data.new_customers.count) + '<span class="stat-unit">명</span>';
    document.getElementById('stQuoteRate').textContent = fmtPct(data.quote_conversion.rate);
    document.getElementById('stReceivable').innerHTML = fmtMoney(data.receivables.total) + '<span class="stat-unit">원</span>';
    document.getElementById('stRevenueRate').textContent = fmtPct(data.target_achievement.revenue_rate);
    document.getElementById('stProfitRate').textContent = fmtPct(data.target_achievement.profit_rate);

    // 차트: 월별 매출·순이익
    renderChart('chartMonthly', {
      type: 'bar',
      data: {
        labels: data.monthly_trend.map((r) => r.ym),
        datasets: [
          { type: 'bar', label: '매출', data: data.monthly_trend.map((r) => r.revenue), backgroundColor: COLORS[0] },
          { type: 'line', label: '순이익', data: data.monthly_trend.map((r) => r.profit), borderColor: COLORS[1], backgroundColor: COLORS[1], tension: 0.3 },
        ],
      },
      options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { ticks: { callback: (v) => fmtMoney(v) } } } },
    });

    // 차트: 유입경로별 고객
    renderChart('chartSource', {
      type: 'doughnut',
      data: {
        labels: data.by_source.map((r) => r.source),
        datasets: [{ data: data.by_source.map((r) => r.cnt), backgroundColor: COLORS }],
      },
      options: { responsive: true, plugins: { legend: { position: 'right' } } },
    });

    // 차트: 영업단계별 건수
    renderChart('chartStage', {
      type: 'bar',
      data: {
        labels: data.by_stage.map((r) => r.stage),
        datasets: [{ label: '건수', data: data.by_stage.map((r) => r.cnt), backgroundColor: COLORS[0] }],
      },
      options: { responsive: true, indexAxis: 'y', plugins: { legend: { display: false } } },
    });

    // 차트: 공사유형별 매출
    renderChart('chartWorkType', {
      type: 'bar',
      data: {
        labels: data.by_work_type.map((r) => r.work_type),
        datasets: [{ label: '매출', data: data.by_work_type.map((r) => r.revenue), backgroundColor: COLORS[2] }],
      },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: (v) => fmtMoney(v) } } } },
    });

    // 표: 영업직원별 계약률
    document.getElementById('tbSalesConversion').innerHTML = rowsHtml(data.sales_conversion, (r) => {
      const rate = r.total_leads > 0 ? (r.won_leads / r.total_leads * 100) : null;
      return '<tr><td>' + esc(r.name) + '</td><td class="num">' + fmtMoney(r.total_leads) + '</td><td class="num">' + fmtMoney(r.won_leads) + '</td><td class="num">' + fmtPct(rate) + '</td></tr>';
    }, 4);

    // 표: 프로젝트별 손익
    document.getElementById('tbProjectPl').innerHTML = rowsHtml(data.project_pl, (r) => {
      const profitClass = r.profit < 0 ? ' class="text-danger"' : '';
      return '<tr><td>' + esc(r.project_no) + ' ' + esc(r.name) + '</td><td><span class="badge badge-info">' + esc(statusLabel(r.status)) + '</span></td>'
        + '<td class="num">' + fmtMoney(r.revenue) + '</td><td class="num">' + fmtMoney(r.cost) + '</td>'
        + '<td class="num"' + profitClass + '>' + fmtMoney(r.profit) + '</td><td class="num">' + fmtPct(r.profit_rate) + '</td></tr>';
    }, 6);

    // 표: 직원별 성과
    document.getElementById('tbStaffPerf').innerHTML = rowsHtml(data.staff_performance, (r) => {
      const profitClass = r.profit < 0 ? ' class="text-danger"' : '';
      return '<tr><td>' + esc(r.name) + '</td><td class="num">' + fmtMoney(r.cnt) + '</td><td class="num">' + fmtMoney(r.revenue) + '</td>'
        + '<td class="num">' + fmtMoney(r.cost) + '</td><td class="num"' + profitClass + '>' + fmtMoney(r.profit) + '</td></tr>';
    }, 5);

    // 표: 지연 프로젝트
    document.getElementById('tbDelayed').innerHTML = rowsHtml(data.delayed_projects, (r) => {
      return '<tr><td>' + esc(r.project_no) + ' ' + esc(r.name) + '</td><td>' + esc(r.end_date) + '</td>'
        + '<td class="num text-danger">' + fmtMoney(r.days_over) + '일</td><td>' + esc(r.site_manager || '-') + '</td></tr>';
    }, 4);

    // 표: 원가초과 프로젝트
    document.getElementById('tbCostOverrun').innerHTML = rowsHtml(data.cost_overrun, (r) => {
      return '<tr><td>' + esc(r.project_no) + ' ' + esc(r.name) + '</td><td class="num">' + fmtMoney(r.estimated_cost) + '</td>'
        + '<td class="num">' + fmtMoney(r.actual_cost) + '</td><td class="num text-danger">' + fmtMoney(r.over_amount) + '</td><td class="num">' + fmtPct(r.over_rate) + '</td></tr>';
    }, 5);

    // 표: 미수금 현황
    document.getElementById('tbReceivables').innerHTML = rowsHtml(data.receivables.list, (r) => {
      return '<tr><td>' + esc(r.contract_no) + '</td><td>' + esc(r.customer_name) + '</td><td class="num">' + fmtMoney(r.contract_amount) + '</td>'
        + '<td class="num">' + fmtMoney(r.paid) + '</td><td class="num text-danger">' + fmtMoney(r.receivable) + '</td></tr>';
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
