/* 공정 보드 — SortableJS 기반 드래그 앤 드롭. 서버가 상태의 소스(새로고침 시 서버 값으로 재렌더). */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var board = document.querySelector('[data-board]');
    if (!board) return;

    var canMove = board.dataset.canMove === '1';
    var boardType = board.dataset.boardType || 'painting';
    var boardEl = document.getElementById('processBoard') || board;

    // R10: 이동 응답의 서버 요약으로 상단 KPI 재동기화 — 하드코딩·수동 저장값 없음
    function updateSummary(summary) {
      if (!summary) return;
      Object.keys(summary).forEach(function (k) {
        var el = document.querySelector('[data-summary="' + k + '"]');
        if (el) el.textContent = Number(summary[k]).toLocaleString();
      });
    }
    var lists = Array.prototype.slice.call(document.querySelectorAll('.kanban-list[data-stage-id]'));
    var activeTab = 'all';

    function updateTabCounts() {
      var byGroup = {};
      boardEl.querySelectorAll('.pb-group').forEach(function (sec) {
        var g = sec.dataset.group;
        var n = sec.querySelectorAll('.kanban-card').length;
        byGroup[g] = (byGroup[g] || 0) + n;
        var gc = sec.querySelector('[data-group-count]');
        if (gc) gc.textContent = n;
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
      boardEl.querySelectorAll('.pb-group').forEach(function (sec) {
        sec.style.display = (showAll || groups.indexOf(sec.dataset.group) >= 0) ? '' : 'none';
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

    // 그룹 탭 전환 + 컬럼 접기 + 카드 클릭 시 프로젝트 상세 이동(드래그 직후 클릭은 무시)
    var lastDragAt = 0;
    var pcTabs = document.getElementById('pcTabs');
    if (pcTabs) pcTabs.addEventListener('click', function (e) {
      var b = e.target.closest('.pl-tab'); if (!b) return;
      activeTab = b.dataset.tab; applyTab();
    });
    boardEl.addEventListener('click', function (e) {
      var caret = e.target.closest('.kanban-caret');
      if (caret) { caret.closest('.kanban-col').classList.toggle('collapsed'); return; }
      if (e.target.closest('a, button')) return;
      var card = e.target.closest('.kanban-card');
      if (card && card.dataset.href && Date.now() - lastDragAt > 300) {
        window.location.href = card.dataset.href;
      }
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
      lastDragAt = Date.now();

      // 같은 컬럼 안에서의 재정렬은 서버 상태에 영향 없음 — 그대로 둔다.
      if (fromStageId === toStageId) {
        updateColumnCounts();
        return;
      }

      (async function () {
        try {
          // R11: 잠금·확인·전체완료 게이트 제거 — 권한(process.move)만 있으면 자유 이동
          var data = await api('process.move', { project_id: projectId, to_stage_id: toStageId, board_type: boardType });
          if (data && data.skip_warn) {
            toast('공정 단계를 건너뛰었습니다. 필요 시 확인하세요.', 'warn');
          } else {
            toast('공정 단계가 변경되었습니다.', 'success');
          }
          // 진입일(process_entered_at) 갱신 — 재진입 카드는 대상 컬럼 최상단으로(서버 정렬과 일치)
          toList.insertBefore(item, toList.firstElementChild);
          var enteredEl = item.querySelector('[data-entered-at]');
          if (enteredEl && data && data.entered_at) {
            var d = new Date(data.entered_at.replace(' ', 'T'));
            enteredEl.textContent = ('0' + (d.getMonth() + 1)).slice(-2) + '.' + ('0' + d.getDate()).slice(-2);
          }
          if (data && typeof data.progress !== 'undefined') updateCardProgress(item, data.progress);
          // R11: 카드 상태 동기화 — 전체완료 자동 완료 시 완료 스타일 적용, 재개 시 일반 스타일 복귀(잠금 없음)
          if (data && data.status) {
            item.dataset.status = data.status;
            if (data.is_done) {
              item.classList.remove('st-delayed', 'st-warn', 'st-normal');
              item.classList.add('st-won', 'pb-done');
            } else {
              item.classList.remove('st-won', 'locked', 'pb-done');
              if (!item.classList.contains('st-delayed') && !item.classList.contains('st-warn')) {
                item.classList.add('st-normal');
              }
            }
          }
          if (data && data.summary) updateSummary(data.summary);
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
          onStart: function () { lastDragAt = Date.now(); },
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

    // ── R8-A: '유형 미지정' 배지 → 공사 유형(도장/인테리어) 지정 모달 (perm project.manage — 서버도 강제) ──
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
            location.reload(); // 지정 후 해당 유형 탭에만 노출 — 서버 상태로 재렌더
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
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }
  });
})();
