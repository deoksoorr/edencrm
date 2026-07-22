/* 대시보드 차트(boss·sales): 월별 매출·순이익 bar+line, 영업단계 6그룹 도넛.
   금액은 억/만 축약(축·툴팁), 게이지·직원막대는 폐기. .chart-box 높이에 맞춰 렌더. */
(function () {
  'use strict';
  if (typeof Chart === 'undefined') return;
  const trendEl = document.getElementById('chartTrend');
  const stageEl = document.getElementById('chartStage');
  if (!trendEl && !stageEl) return; // 차트 없는 화면(site/staff)

  Chart.defaults.font.family = "-apple-system,BlinkMacSystemFont,'Segoe UI','Apple SD Gothic Neo','Malgun Gothic',sans-serif";
  Chart.defaults.font.size = 12;
  Chart.defaults.color = '#6b7280';

  function moneyShort(n) {
    if (n === null || n === undefined) return '-';
    const s = n < 0 ? '-' : '';
    const a = Math.abs(n);
    if (a >= 1e8) { const v = a / 1e8; return s + (v === Math.floor(v) ? v : v.toFixed(1)) + '억'; }
    if (a >= 1e4) return s + Math.round(a / 1e4).toLocaleString('ko-KR') + '만';
    return s + a.toLocaleString('ko-KR');
  }
  const won = (n) => (n === null || n === undefined ? '-' : Number(n).toLocaleString('ko-KR') + '원');
  function showEmpty(canvas, msg) {
    const box = canvas.closest('.chart-box');
    if (box) {
      box.classList.add('is-empty'); // 고정 높이 해제 → 컴팩트 안내로 축소
      box.innerHTML = '<div class="chart-empty"><span class="ce-ico">▤</span><span>' + msg + '</span></div>';
    }
  }

  async function load() {
    let data;
    try { data = await api('dashboard.data', {}); }
    catch (err) { console.error(err); return; }

    const trendSum = (data.monthly_trend || []).reduce((s, r) => s + Math.abs(r.revenue) + Math.abs(r.profit), 0);
    if (trendEl && data.monthly_trend && trendSum === 0) {
      showEmpty(trendEl, '최근 6개월 계약 매출 데이터가 아직 없습니다.');
    } else if (trendEl && data.monthly_trend) {
      new Chart(trendEl.getContext('2d'), {
        data: {
          labels: data.monthly_trend.map((r) => r.ym),
          datasets: [
            { type: 'bar', label: '매출', data: data.monthly_trend.map((r) => r.revenue), backgroundColor: '#1a56db', borderRadius: 4, maxBarThickness: 42, order: 2 },
            { type: 'line', label: '순이익', data: data.monthly_trend.map((r) => r.profit), borderColor: '#0f9d58', backgroundColor: '#0f9d58', borderWidth: 2, tension: 0.35, pointRadius: 3, pointHoverRadius: 5, order: 1 },
          ],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, boxHeight: 12, padding: 14, usePointStyle: true } },
            tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + won(c.parsed.y) } },
          },
          scales: {
            x: { grid: { display: false } },
            y: { border: { display: false }, grid: { color: '#eef1f4' }, ticks: { callback: (v) => moneyShort(v) } },
          },
        },
      });
    }

    const g = data.stage_groups || [];
    const stageTotal = g.reduce((s, r) => s + Number(r.n), 0);
    if (stageEl && stageTotal === 0) {
      showEmpty(stageEl, '진행 중인 영업기회가 없습니다.');
      const lg0 = document.getElementById('stageLegend'); if (lg0) lg0.innerHTML = '';
    } else if (stageEl && data.stage_groups) {
      const total = stageTotal;
      new Chart(stageEl.getContext('2d'), {
        type: 'doughnut',
        data: { labels: g.map((r) => r.label), datasets: [{ data: g.map((r) => r.n), backgroundColor: g.map((r) => r.color), borderWidth: 2, borderColor: '#fff' }] },
        options: {
          responsive: true, maintainAspectRatio: false, cutout: '62%',
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (c) => c.label + ': ' + c.parsed + '건 (' + (total ? (c.parsed / total * 100).toFixed(0) : 0) + '%)' } },
          },
        },
      });
      const legend = document.getElementById('stageLegend');
      if (legend) {
        legend.innerHTML = g.map((r) =>
          `<span class="lg"><span class="sw" style="background:${r.color}"></span>${r.label} <b>${r.n}</b></span>`
        ).join('');
      }
    }
  }
  load();
})();
