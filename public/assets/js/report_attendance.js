/* 직원 출근 분석(R4 T4 · R6 최종 구조): 차트 2종 + 관리자 마킹 캘린더(지각·무단결근 등록·변경·해제).
   차트 데이터는 뷰의 <script type="application/json" id="attData"> JSON — reports.js 와 동일 규격.
   마킹은 #attMarkData JSON + EDEN.api(attendance.mark/unmark, CSRF 자동) — 권한 없으면 마크업 자체가 없다. */
(function () {
  'use strict';

  // ── 관리자 마킹 캘린더(#attMarkCal — perm attendance.manage 보유 시에만 렌더) ──
  var markEl = document.getElementById('attMarkData');
  var calEl = document.getElementById('attMarkCal');
  if (markEl && calEl && window.EDEN) {
    var md;
    try { md = JSON.parse(markEl.textContent); } catch (e) { md = null; }
    if (md) {
      var LABELS = { late: '지각', absent: '무단결근' };
      calEl.addEventListener('click', function (ev) {
        var cell = ev.target.closest('.attmark-cell[data-date]');
        if (!cell || cell.disabled) return;
        openMarkModal(cell.getAttribute('data-date'));
      });

      var openMarkModal = function (date) {
        var cur = md.marks[date] || null;
        var body =
          '<div class="attmark-form">' +
          '<p class="attmark-form-cur">' + esc(md.userName) + ' · ' + esc(date) +
          (cur ? ' — 현재 <b>' + LABELS[cur.type] + '</b> 등록됨' : ' — 등록된 상태 없음') + '</p>' +
          '<div class="field"><label class="field-label">상태</label>' +
          '<div class="attmark-types">' +
          '<label class="attmark-type"><input type="radio" name="amType" value="late"' + (cur && cur.type === 'late' ? ' checked' : '') + '> 지각 <span class="muted">(출근 일수에 포함)</span></label>' +
          '<label class="attmark-type"><input type="radio" name="amType" value="absent"' + (cur && cur.type === 'absent' ? ' checked' : '') + '> 무단결근 <span class="muted">(출근 일수에서 제외)</span></label>' +
          '</div></div>' +
          '<div class="field"><label class="field-label">메모</label>' +
          '<input class="input" id="amMemo" maxlength="255" placeholder="사유 등 (선택)" value="' + esc(cur ? cur.memo : '') + '"></div>' +
          '</div>';
        var buttons = [{ label: '취소', class: 'btn-outline', onClick: function (close) { close(); } }];
        if (cur) {
          buttons.push({
            label: '해제', class: 'btn-danger', onClick: function (close) {
              EDEN.confirm(esc(md.userName) + ' · ' + esc(date) + ' 의 <b>' + LABELS[cur.type] + '</b> 상태를 해제(삭제)할까요?<br>해제 내역은 감사 로그에 기록됩니다.', { danger: true, okLabel: '해제' })
                .then(function (ok) {
                  if (!ok) return;
                  EDEN.api('attendance.unmark', { user_id: md.userId, mark_date: date })
                    .then(function () { EDEN.toast('해제되었습니다.', 'success'); close(); location.reload(); })
                    .catch(function (err) { EDEN.toast(err.message, 'error'); });
                });
            },
          });
        }
        buttons.push({
          label: cur ? '상태 변경' : '등록', class: 'btn-primary', onClick: function (close, btn) {
            var m = btn.closest('.modal');
            var sel = m.querySelector('input[name="amType"]:checked');
            if (!sel) { EDEN.toast('상태(지각/무단결근)를 선택하세요.', 'warn'); return; }
            EDEN.api('attendance.mark', { user_id: md.userId, mark_date: date, mark_type: sel.value, memo: m.querySelector('#amMemo').value })
              .then(function (r) { EDEN.toast(r.mode === 'created' ? '등록되었습니다.' : '변경되었습니다.', 'success'); close(); location.reload(); })
              .catch(function (err) { EDEN.toast(err.message, 'error'); });
          },
        });
        EDEN.modal({ title: '근태 마킹 — ' + date, body: body, buttons: buttons });
      };
    }
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ── 차트 2종(로컬 Chart.js 재사용 — 외부 요청 없음) ──
  var el = document.getElementById('attData');
  if (!el || typeof Chart === 'undefined') return;
  var data;
  try { data = JSON.parse(el.textContent); } catch (e) { return; }

  Chart.defaults.font.family = "-apple-system,BlinkMacSystemFont,'Segoe UI','Apple SD Gothic Neo','Malgun Gothic',sans-serif";
  Chart.defaults.font.size = 12;
  Chart.defaults.color = '#6b7280';
  var BRAND = '#1a56db';
  var bottomLegend = { position: 'bottom', labels: { boxWidth: 12, boxHeight: 12, padding: 14, usePointStyle: true, font: { size: 11.5 } } };

  function draw(id, config, isEmpty) {
    var cv = document.getElementById(id);
    if (!cv) return;
    var box = cv.closest('.chart-box');
    if (isEmpty) {
      cv.style.display = 'none';
      if (box) box.insertAdjacentHTML('beforeend', '<div class="chart-empty"><span class="ce-ico">◔</span><span>표시할 데이터가 없습니다.</span></div>');
      return;
    }
    new Chart(cv.getContext('2d'), config);
  }

  // 직원별 출근 일수 가로 막대(개인색) — 축 최대는 해당 월 영업일 수(참고용 스케일)
  var bar = data.bar || [];
  draw('chartAttBar', {
    type: 'bar',
    data: {
      labels: bar.map(function (r) { return r.name; }),
      datasets: [{
        label: '출근 일수',
        data: bar.map(function (r) { return r.days; }),
        backgroundColor: bar.map(function (r) { return r.color || BRAND; }),
        borderRadius: 4,
        maxBarThickness: 26,
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: function (c) { return '출근 ' + c.parsed.x + '일'; } } },
      },
      scales: {
        x: { beginAtZero: true, suggestedMax: data.scheduled, border: { display: false }, grid: { color: '#eef1f4' }, ticks: { precision: 0 } },
        y: { grid: { display: false } },
      },
    },
  }, !bar.length);

  // 최근 6개월 추이 꺾은선(선택 인원 총 출근 일수)
  var trend = data.trend || [];
  var hasTrend = trend.some(function (r) { return r.days > 0; });
  draw('chartAttTrend', {
    type: 'line',
    data: {
      labels: trend.map(function (r) { return r.ym; }),
      datasets: [{
        label: '총 출근 일수',
        data: trend.map(function (r) { return r.days; }),
        borderColor: BRAND, backgroundColor: BRAND,
        borderWidth: 2, tension: 0.35, pointRadius: 3, pointHoverRadius: 5,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: bottomLegend,
        tooltip: { callbacks: { label: function (c) { return '총 출근 ' + c.parsed.y + '일'; } } },
      },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, border: { display: false }, grid: { color: '#eef1f4' }, ticks: { precision: 0 } },
      },
    },
  }, !trend.length || !hasTrend);
})();
