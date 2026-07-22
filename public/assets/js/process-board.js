/* 공정 보드 — SortableJS 기반 드래그 앤 드롭. 서버가 상태의 소스(새로고침 시 서버 값으로 재렌더). */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var board = document.querySelector('[data-board]');
    if (!board) return;

    var canMove = board.dataset.canMove === '1';
    var boardEl = document.getElementById('processBoard') || board;
    var lists = Array.prototype.slice.call(document.querySelectorAll('.kanban-list[data-stage-id]'));
    var activeTab = 'all';

    function updateTabCounts() {
      var byGroup = {};
      boardEl.querySelectorAll('.kanban-col').forEach(function (col) {
        var g = col.dataset.group;
        byGroup[g] = (byGroup[g] || 0) + col.querySelectorAll('.kanban-card').length;
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
      boardEl.querySelectorAll('.kanban-col').forEach(function (col) {
        col.style.display = (showAll || groups.indexOf(col.dataset.group) >= 0) ? '' : 'none';
      });
      document.querySelectorAll('#pcTabs .pl-tab').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === activeTab); });
      updateTabCounts();
    }

    function updateColumnCounts() {
      lists.forEach(function (list) {
        var col = list.closest('.kanban-col');
        var countEl = col && col.querySelector('.kanban-count');
        var cards = list.querySelectorAll('.kanban-card');
        if (countEl) countEl.textContent = cards.length;
        var emptyEl = list.querySelector('.kanban-empty');
        if (cards.length === 0 && !emptyEl) {
          var div = document.createElement('div');
          div.className = 'kanban-empty';
          div.textContent = '프로젝트 없음';
          list.appendChild(div);
        } else if (cards.length > 0 && emptyEl) {
          emptyEl.remove();
        }
      });
      updateTabCounts();
    }

    // 그룹 탭 전환 + 컬럼 접기
    var pcTabs = document.getElementById('pcTabs');
    if (pcTabs) pcTabs.addEventListener('click', function (e) {
      var b = e.target.closest('.pl-tab'); if (!b) return;
      activeTab = b.dataset.tab; applyTab();
    });
    boardEl.addEventListener('click', function (e) {
      var caret = e.target.closest('.kanban-caret');
      if (caret) { caret.closest('.kanban-col').classList.toggle('collapsed'); }
    });

    // 단계 이동 시 카드 진행률 자동 갱신(새로고침 없이)
    function updateCardProgress(card, pct) {
      pct = Math.max(0, Math.min(100, parseInt(pct, 10) || 0));
      var bar = card.querySelector('.progress-bar');
      if (bar) { bar.style.width = pct + '%'; bar.classList.toggle('ok', pct >= 100); }
      card.querySelectorAll('[data-progress-text]').forEach(function (el) { el.textContent = pct + '%'; });
    }

    function revertCard(item, fromList, oldIndex) {
      var siblings = fromList.querySelectorAll('.kanban-card');
      if (oldIndex >= siblings.length) {
        fromList.appendChild(item);
      } else {
        fromList.insertBefore(item, siblings[oldIndex]);
      }
      updateColumnCounts();
    }

    function handleDrop(evt) {
      var item = evt.item;
      var fromList = evt.from;
      var toList = evt.to;
      var oldIndex = evt.oldIndex;
      var fromStageId = fromList.dataset.stageId;
      var toStageId = toList.dataset.stageId;
      var projectId = item.dataset.projectId;

      // 같은 컬럼 안에서의 재정렬은 서버 상태에 영향 없음 — 그대로 둔다.
      if (fromStageId === toStageId) {
        updateColumnCounts();
        return;
      }

      var requiresConfirm = toList.dataset.requiresConfirm === '1';

      (async function () {
        try {
          if (requiresConfirm) {
            var ok = await EDEN.confirm('확인이 필요한 공정 단계입니다. 이동하시겠습니까?', { okLabel: '이동' });
            if (!ok) {
              revertCard(item, fromList, oldIndex);
              return;
            }
          }
          var data = await api('process.move', { project_id: projectId, to_stage_id: toStageId });
          if (data && data.skip_warn) {
            toast('공정 단계를 건너뛰었습니다. 필요 시 확인하세요.', 'warn');
          } else {
            toast('공정 단계가 변경되었습니다.', 'success');
          }
          if (data && typeof data.progress !== 'undefined') updateCardProgress(item, data.progress);
          updateColumnCounts();
        } catch (err) {
          toast((err && err.message) || '이동에 실패했습니다.', 'error');
          revertCard(item, fromList, oldIndex);
        }
      })();
    }

    if (canMove && window.Sortable) {
      lists.forEach(function (list) {
        new Sortable(list, {
          group: 'process-board',
          animation: 150,
          ghostClass: 'sortable-ghost',
          chosenClass: 'sortable-chosen',
          filter: '.locked',
          preventOnFilter: true,
          onEnd: handleDrop,
        });
      });
    }

    applyTab(); // 초기 탭 카운트·표시

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
                  '<div class="hist-meta">' + escapeHtml(r.changed_at || '') + ' · ' + escapeHtml(r.changed_by_name || '-') + '</div>' +
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

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }
  });
})();
