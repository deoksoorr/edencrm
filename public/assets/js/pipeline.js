/* EDEN CRM — 영업 파이프라인 (R4 T7 조회 전용)
   DnD·인라인 수정·드로어 제거 — 남은 동작은 컬럼 접기뿐.
   카드 이동은 없음(표시 단계는 서버에서 자동 산정), 필터는 GET 폼 제출로 서버 렌더. */
(function () {
  'use strict';

  var board = document.getElementById('kanbanBoard');
  if (!board) return;

  function toggleCol(caret) {
    var col = caret.closest('.kanban-col');
    if (col) col.classList.toggle('collapsed');
  }

  board.addEventListener('click', function (e) {
    var caret = e.target.closest('.kanban-caret');
    if (caret) { e.preventDefault(); e.stopPropagation(); toggleCol(caret); }
  });
  board.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var caret = e.target.closest('.kanban-caret');
    if (caret) { e.preventDefault(); toggleCol(caret); }
  });
})();
