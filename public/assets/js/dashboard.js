/* 대시보드 차트: dashboard.data 호출 → Chart.js 렌더 (사장: line/doughnut/bar/gauge, 직원: bar/gauge) */
(function () {
  'use strict';

  const fmtMoney = (n) => (n === null || n === undefined) ? '-' : Number(n).toLocaleString('ko-KR');
  const fmtPct = (n) => (n === null || n === undefined) ? '-' : Number(n).toFixed(1) + '%';
  const COLORS = ['#1a56db', '#0f9d58', '#e8710a', '#d93025', '#6b7280', '#7c3aed', '#0891b2', '#c026d3', '#0284c7', '#65a30d', '#f59e0b', '#059669'];

  function gaugeColor(rate) {
    if (rate === null || rate === undefined) return '#9ca3af';
    if (rate >= 100) return '#0f9d58';
    if (rate >= 70) return '#1a56db';
    if (rate >= 40) return '#e8710a';
    return '#d93025';
  }

  function renderGauge(canvasId, labelId, rate) {
    const el = document.getElementById(canvasId);
    if (!el || typeof Chart === 'undefined') return;
    const value = (rate === null || rate === undefined) ? 0 : Math.max(0, Math.min(100, rate));
    const color = gaugeColor(rate);
    new Chart(el.getContext('2d'), {
      type: 'doughnut',
      data: { datasets: [{ data: [value, 100 - value], backgroundColor: [color, '#eef1f4'], borderWidth: 0 }] },
      options: {
        responsive: true,
        circumference: 180,
        rotation: 270,
        cutout: '75%',
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
      },
    });
    const label = document.getElementById(labelId);
    if (label) {
      label.textContent = fmtPct(rate);
      label.style.color = color;
    }
  }

  async function load() {
    let data;
    try {
      data = await api('dashboard.data', {});
    } catch (err) {
      console.error(err);
      return;
    }

    // ── 사장 계열 대시보드 ──
    if (document.getElementById('chartMonthlyTrend') && data.monthly_trend) {
      new Chart(document.getElementById('chartMonthlyTrend').getContext('2d'), {
        data: {
          labels: data.monthly_trend.map((r) => r.ym),
          datasets: [
            { type: 'bar', label: '매출', data: data.monthly_trend.map((r) => r.revenue), backgroundColor: COLORS[0] },
            { type: 'line', label: '순이익', data: data.monthly_trend.map((r) => r.profit), borderColor: COLORS[1], backgroundColor: COLORS[1], tension: 0.3 },
          ],
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { ticks: { callback: (v) => fmtMoney(v) } } } },
      });
    }

    if (document.getElementById('chartStageDist') && data.stage_distribution) {
      new Chart(document.getElementById('chartStageDist').getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: data.stage_distribution.map((r) => r.stage),
          datasets: [{ data: data.stage_distribution.map((r) => r.cnt), backgroundColor: COLORS }],
        },
        options: { responsive: true, plugins: { legend: { position: 'right' } } },
      });
    }

    if (document.getElementById('chartStaffRevenue') && data.staff_revenue) {
      new Chart(document.getElementById('chartStaffRevenue').getContext('2d'), {
        type: 'bar',
        data: {
          labels: data.staff_revenue.map((r) => r.name),
          datasets: [{ label: '매출', data: data.staff_revenue.map((r) => r.revenue), backgroundColor: COLORS[0] }],
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: (v) => fmtMoney(v) } } } },
      });
    }

    // ── 직원 대시보드 ──
    if (document.getElementById('chartProcessBreakdown') && data.process_breakdown) {
      new Chart(document.getElementById('chartProcessBreakdown').getContext('2d'), {
        type: 'bar',
        data: {
          labels: data.process_breakdown.map((r) => r.stage || '미지정'),
          datasets: [{ label: '건수', data: data.process_breakdown.map((r) => r.cnt), backgroundColor: COLORS[2] }],
        },
        options: { responsive: true, indexAxis: 'y', plugins: { legend: { display: false } } },
      });
    }

    // ── 공통: 목표 달성 게이지 ──
    if (document.getElementById('chartGoalGauge') && data.goal_gauge) {
      renderGauge('chartGoalGauge', 'goalGaugeLabel', data.goal_gauge.rate);
    }
  }

  load();
})();
