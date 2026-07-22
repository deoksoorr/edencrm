/* 공정 보드 — SortableJS 기반 드래그 앤 드롭. 서버가 상태의 소스(새로고침 시 서버 값으로 재렌더). */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var board = document.querySelector('[data-board]');
    if (!board) return;

    var canMove = board.dataset.canMove === '1';
    var lists = Array.prototype.slice.call(document.querySelectorAll('.kanban-list[data-stage-id]'));

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

    // 공정 이력 보기
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.history-btn');
      if (!btn) return;
      var projectId = btn.dataset.projectId;
      api('process.history', { project_id: projectId }, { method: 'GET' })
        .then(function (data) {
          var rows = (data && data.rows) || [];
          var html = rows.length
            ? '<div class="timeline">' + rows.map(function (r) {
                var reason = r.reason ? ' <span class="muted">(' + escapeHtml(r.reason) + ')</span>' : '';
                return '<div class="timeline-item"><div class="timeline-time">' + escapeHtml(r.changed_at || '') + '</div>' +
                  '<div class="timeline-body">' + escapeHtml(r.from_name || '(시작)') + ' → ' + escapeHtml(r.to_name) + reason + '</div>' +
                  '<div class="timeline-tag">' + escapeHtml(r.changed_by_name || '-') + '</div></div>';
              }).join('') + '</div>'
            : '<div class="empty"><div class="empty-title">이력이 없습니다.</div></div>';
          EDEN.modal({ title: '공정 이력', body: html });
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
