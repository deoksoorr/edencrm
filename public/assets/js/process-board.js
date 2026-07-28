/* 공정 보드 — R14: 상태 그룹 섹션 + 카드내 공정 게이지(슬라이더). 드래그 이동은 폐지(process.move 라우트 제거).
   서버가 상태의 소스 — 게이지 저장 응답(status/progress/current_stage/group)을 그대로 카드에 반영한다. */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var board = document.querySelector('[data-board]');
    if (!board) return;

    var canMove = board.dataset.canMove === '1';
    var boardEl = document.getElementById('processBoard') || board;

    // R10: 저장 응답의 서버 요약으로 상단 KPI 재동기화 — 하드코딩·수동 저장값 없음
    function updateSummary(summary) {
      if (!summary) return;
      Object.keys(summary).forEach(function (k) {
        var el = document.querySelector('[data-summary="' + k + '"]');
        if (el) el.textContent = Number(summary[k]).toLocaleString();
      });
    }

    // R14: 필터 탭(#pcTabs) — 축이 공정그룹에서 상태그룹(대기중/진행 중/하자보수/종결)으로 바뀌었을 뿐
    // 동작 방식은 기존과 동일: 섹션(.sg-group) 표시/숨김 + 탭별 카드 수 집계.
    var activeTab = 'all';
    function updateTabCounts() {
      var byGroup = {};
      boardEl.querySelectorAll('.sg-group').forEach(function (sec) {
        var g = sec.dataset.group;
        byGroup[g] = sec.querySelectorAll('.gauge-card').length;
      });
      document.querySelectorAll('#pcTabs .pl-tab').forEach(function (b) {
        var n = b.dataset.groups.split(',').reduce(function (s, g) { return s + (byGroup[g] || 0); }, 0);
        var c = b.querySelector('.tcnt'); if (c) c.textContent = n;
      });
    }
    function applyTab() {
      var btn = document.querySelector('#pcTabs .pl-tab[data-tab="' + activeTab + '"]');
      var groups = btn ? btn.dataset.groups.split(',') : [];
      var showAll = activeTab === 'all';
      boardEl.querySelectorAll('.sg-group').forEach(function (sec) {
        sec.style.display = (showAll || groups.indexOf(sec.dataset.group) >= 0) ? '' : 'none';
      });
      document.querySelectorAll('#pcTabs .pl-tab').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === activeTab); });
      updateTabCounts();
    }
    var pcTabs = document.getElementById('pcTabs');
    if (pcTabs) pcTabs.addEventListener('click', function (e) {
      var b = e.target.closest('.pl-tab'); if (!b) return;
      activeTab = b.dataset.tab; applyTab();
    });
    applyTab(); // 초기 탭 카운트·표시

    // ── R14: 게이지 저장(디바운스) + 즉시 반영 ──
    var timers = {};
    function queueSave(card, stageId, pct) {
      var key = card.dataset.projectId + ':' + stageId;
      clearTimeout(timers[key]);
      timers[key] = setTimeout(function () { saveGauge(card, stageId, pct); }, 400);
    }
    boardEl.querySelectorAll('.gc-slider').forEach(function (sl) {
      sl.addEventListener('input', function () {
        var row = sl.closest('.gc-row');
        var num = row.querySelector('.gc-num');
        if (num) num.value = sl.value;
        var card = sl.closest('.gauge-card');
        renderWorking(card);
        queueSave(card, sl.dataset.stageId, sl.value);
      });
    });
    // R14-3: 모바일 숫자 직접 입력 — 슬라이더와 양방향 동기, 동일 디바운스 저장
    boardEl.querySelectorAll('.gc-num').forEach(function (num) {
      num.addEventListener('input', function () {
        var v = Math.max(0, Math.min(100, parseInt(num.value, 10) || 0));
        var row = num.closest('.gc-row');
        var sl = row.querySelector('.gc-slider');
        if (sl) sl.value = v;
        var card = num.closest('.gauge-card');
        renderWorking(card);
        queueSave(card, num.dataset.stageId, v);
      });
      num.addEventListener('blur', function () { // 범위 밖 입력 정규화
        num.value = Math.max(0, Math.min(100, parseInt(num.value, 10) || 0));
      });
    });

    async function saveGauge(card, stageId, pct) {
      try {
        var data = await api('process.progress.set', {
          project_id: card.dataset.projectId, stage_id: stageId, pct: pct,
        });
        applyCardState(card, data);
        if (data.all_done && card.dataset.status !== 'completed' && card.dataset.status !== 'settled') {
          var ok = await EDEN.confirm('모든 공정이 100%입니다. 프로젝트를 완료 처리할까요? (공정 보드 종결)', { okLabel: '완료 처리' });
          if (ok) {
            var d2 = await api('process.complete.confirm', { project_id: card.dataset.projectId });
            applyCardState(card, d2);
          }
        }
      } catch (e) { toast(e.message, 'error'); }
    }

    // 응답 → 카드 즉시 반영(배지 텍스트/클래스·진행률·현재 공정·그룹 이동·KPI)
    function applyCardState(card, d) {
      if (d.progress !== undefined) {
        var bar = card.querySelector('[data-progress-bar]');
        if (bar) { bar.style.width = d.progress + '%'; bar.classList.toggle('ok', d.progress >= 100); }
        var pt = card.querySelector('[data-progress-text]');
        if (pt) pt.textContent = d.progress + '%';
      }
      renderWorking(card); // R14-3: 진행 중 공정 칩 갱신(단일 '현재' 칩 폐지)
      if (d.status) {
        card.dataset.status = d.status;
        var badge = card.querySelector('[data-status-badge]');
        if (badge) { badge.textContent = d.status_label; badge.className = 'badge ' + d.badge_class; }
        var target = document.querySelector('[data-group-cards="' + d.group + '"]');
        if (target && card.parentElement !== target) {
          var source = card.parentElement;
          target.insertBefore(card, target.firstElementChild);
          var targetEmpty = target.querySelector('.empty-mini');
          if (targetEmpty) targetEmpty.remove();
          if (source && !source.querySelector('.gauge-card')) {
            var em = document.createElement('div');
            em.className = 'empty-mini';
            em.textContent = '프로젝트 없음';
            source.appendChild(em);
          }
          document.querySelectorAll('[data-group-count]').forEach(function (el) {
            var grp = el.dataset.groupCount;
            var wrap = document.querySelector('[data-group-cards="' + grp + '"]');
            if (wrap) el.textContent = wrap.querySelectorAll('.gauge-card').length;
          });
          updateTabCounts();
        }
      }
      if (d.summary) updateSummary(d.summary);
    }

    // R14-3: 진행 중 공정(0<pct<100) 전체 칩 렌더 — 게이지 바 하단, 슬라이더/숫자 값 기준
    function renderWorking(card) {
      var box = card.querySelector('[data-work-chips]');
      if (!box) return;
      box.textContent = '';
      var working = 0, done = 0, total = 0;
      card.querySelectorAll('.gc-row .gc-slider').forEach(function (sl) {
        total++;
        var v = parseInt(sl.value, 10) || 0;
        if (v >= 100) done++;
        if (v > 0 && v < 100) {
          working++;
          var name = ((sl.closest('.gc-row').querySelector('.gc-name') || {}).textContent || '').replace(/^\s*\d+\.\s*/, '');
          var s = document.createElement('span');
          s.className = 'badge badge-stage';
          s.textContent = name + ' ' + v + '%';
          box.appendChild(s);
        }
      });
      if (!working) {
        var m = document.createElement('span');
        m.className = 'badge badge-muted';
        m.textContent = total && done === total ? '전 공정 완료' : '작업 전';
        box.appendChild(m);
      }
    }

    // ── 하자보수 버튼 ──
    boardEl.querySelectorAll('.gc-warranty-btn').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        var card = btn.closest('.gauge-card');
        var setOn = btn.dataset.action === 'set';
        var ok = await EDEN.confirm(setOn ? '이 프로젝트를 하자보수 상태로 전환할까요?' : '하자보수를 종료하고 완료로 복귀할까요?');
        if (!ok) return;
        try {
          await api('process.warranty.set', { project_id: card.dataset.projectId, action: btn.dataset.action });
          location.reload(); // 버튼 표시(전환/종료) 갱신 단순화 — 카드 DOM 갱신은 새로고침으로 대체(중간 반영 불필요)
        } catch (e) { toast(e.message, 'error'); }
      });
    });

    // ── 메모 레이어팝업 ──
    boardEl.querySelectorAll('.gc-memo-btn').forEach(function (btn) {
      btn.addEventListener('click', function () { openMemo(btn.closest('.gauge-card')); });
    });
    async function openMemo(card) {
      var pid = card.dataset.projectId;
      var d = await api('process.memo.list', { project_id: pid }, { method: 'GET' });
      var items = (d.memos || []).map(function (m) {
        return '<div class="memo-item"><div class="memo-date">' + escapeHtml(m.memo_date) +
          ' <span class="muted fs-12">' + escapeHtml(m.user_name || '') + '</span>' +
          (canMove ? ' <button type="button" class="btn btn-sm btn-ghost memo-del" data-id="' + m.id + '">삭제</button>' : '') + '</div>' +
          '<div class="memo-body">' + escapeHtml(m.content) + '</div></div>';
      }).join('') || '<div class="empty-mini">메모 없음</div>';
      var form = canMove
        ? '<form class="form memo-form">' +
          '<div class="memo-add"><input type="date" name="memo_date" class="input" value="' + today() + '">' +
          '<textarea name="content" class="input" rows="2" maxlength="1000" placeholder="오늘 작업 내용"></textarea>' +
          '<button type="submit" class="btn btn-sm btn-primary">등록</button></div></form>'
        : '';
      var body = form + '<div class="memo-list">' + items + '</div>';
      var m = EDEN.modal({ title: '작업 메모', body: body, footer: false });
      var memoForm = m.body.querySelector('.memo-form');
      if (memoForm) {
        memoForm.addEventListener('submit', async function (ev) {
          ev.preventDefault();
          try {
            await api('process.memo.save', { project_id: pid,
              memo_date: this.memo_date.value, content: this.content.value });
            m.close(); openMemo(card); toast('메모가 등록되었습니다.', 'success');
          } catch (e) { toast(e.message, 'error'); }
        });
      }
      m.body.querySelectorAll('.memo-del').forEach(function (db) {
        db.addEventListener('click', async function () {
          if (!(await EDEN.confirm('이 메모를 삭제할까요?', { danger: true }))) return;
          try { await api('process.memo.delete', { id: db.dataset.id }); m.close(); openMemo(card); }
          catch (e) { toast(e.message, 'error'); }
        });
      });
    }

    // 공정 이력 보기 + 사유 수정
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.history-btn');
      if (!btn) return;
      var projectId = btn.dataset.projectId;
      api('process.history', { project_id: projectId }, { method: 'GET' })
        .then(function (data) {
          var rows = (data && data.rows) || [];
          var html = rows.length
            ? '<div class="hist-list">' + rows.map(function (r) {
                return '<div class="hist-item" data-hid="' + r.id + '">' +
                  '<div class="hist-meta">' + escapeHtml(r.changed_at || '') + ' · ' + escapeHtml(r.changed_by_name || (Number(r.is_auto) === 1 ? '시스템' : '-')) + (Number(r.is_auto) === 1 ? ' · 자동' : '') + '</div>' +
                  '<div class="hist-move">' + escapeHtml(r.from_name || '(시작)') + ' → <b>' + escapeHtml(r.to_name) + '</b></div>' +
                  (canMove
                    ? '<div class="hist-edit"><input class="input hist-reason" value="' + escapeHtml(r.reason || '') + '" placeholder="변경 사유 입력"><button type="button" class="btn btn-sm btn-outline hist-save">저장</button></div>'
                    : (r.reason ? '<div class="muted">사유: ' + escapeHtml(r.reason) + '</div>' : '')) +
                  '</div>';
              }).join('') + '</div>'
            : '<div class="empty"><div class="empty-title">이력이 없습니다.</div></div>';
          var m = EDEN.modal({ title: '공정 이력', body: html });
          if (canMove) {
            m.body.addEventListener('click', function (ev) {
              var sb = ev.target.closest('.hist-save'); if (!sb) return;
              var item = sb.closest('.hist-item');
              sb.disabled = true;
              api('process.history.update', { history_id: item.dataset.hid, reason: item.querySelector('.hist-reason').value })
                .then(function () { toast('이력 사유가 수정되었습니다.', 'success'); })
                .catch(function (err) { toast((err && err.message) || '수정에 실패했습니다.', 'error'); })
                .finally(function () { sb.disabled = false; });
            });
          }
        })
        .catch(function (err) {
          toast((err && err.message) || '이력을 불러오지 못했습니다.', 'error');
        });
    });

    // ── R8-A: '유형 미지정' 배지 → 공사 유형(도장/인테리어) 지정 모달 (perm project.manage — 서버도 강제).
    //    R14: 게이지 보드로 이식 — 유형에 따라 카드 게이지 단계셋이 달라지므로 전체 새로고침으로 재렌더한다. ──
    document.addEventListener('click', function (e) {
      var badge = e.target.closest('button[data-settype]');
      if (!badge) return;
      var projectId = badge.dataset.settype;
      var name = badge.dataset.name || '';

      function doSet(type, typeLabel, close, btn) {
        btn.disabled = true;
        api('process.settype', { project_id: projectId, construction_type: type })
          .then(function (data) {
            var msg = '공사 유형이 \'' + typeLabel + '\'(으)로 지정되었습니다.';
            if (data && data.moved_to_waiting) msg += ' 공정이 \'대기중\'으로 재배치되었습니다.';
            toast(msg, 'success');
            close();
            location.reload(); // 지정 후 해당 유형 탭·게이지 단계셋으로 재렌더 — 서버 상태 기준
          })
          .catch(function (err) {
            toast((err && err.message) || '유형 지정에 실패했습니다.', 'error');
            btn.disabled = false;
          });
      }

      EDEN.modal({
        title: '공사 유형 지정',
        body: '<p style="margin:0;color:#4b5563">' + (name ? '<b>' + escapeHtml(name) + '</b><br>' : '')
          + '이 프로젝트의 공사 유형을 지정합니다. 지정하면 해당 유형 보드에만 표시되며,<br>'
          + '현재 공정이 다른 유형 전용 단계면 <b>\'대기중\'</b>으로 재배치됩니다(이력 기록).</p>',
        buttons: [
          { label: '취소', class: 'btn-outline', onClick: function (close) { close(); } },
          { label: '도장', class: 'btn-primary', onClick: function (close, btn) { doSet('painting', '도장', close, btn); } },
          { label: '인테리어', class: 'btn-primary', onClick: function (close, btn) { doSet('interior', '인테리어', close, btn); } },
        ],
      });
    });

    function escapeHtml(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }
    function today() {
      var d = new Date();
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
  });
})();
