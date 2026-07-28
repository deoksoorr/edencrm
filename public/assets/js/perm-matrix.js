/**
 * R16 — 직원 업무 권한 매트릭스.
 * 종속 규칙·일괄 선택·미저장 이탈 경고를 담당한다.
 * 서버(Perm::save)가 동일 규칙을 다시 검증하므로 이 스크립트는 편의 계층일 뿐이다.
 */
(function () {
  var block = document.getElementById('permBlock');
  if (!block) { return; }

  var form = block.closest('form');
  var dirty = false;

  function boxes(scope, act) {
    var sel = 'input[type=checkbox]' + (act ? '[data-perm-act="' + act + '"]' : '');
    return Array.prototype.slice.call((scope || block).querySelectorAll(sel));
  }
  function rowBoxes(key) {
    var tr = block.querySelector('[data-perm-row="' + CSS.escape(key) + '"]');
    return tr ? boxes(tr) : [];
  }
  function get(tr, act) { return tr.querySelector('input[data-perm-act="' + act + '"]'); }

  /**
   * 종속 규칙 — 서버 Perm::normalize() 와 동일하게 맞춘다.
   *  1) 쓰기/삭제 ON  → 읽기 자동 ON
   *  2) 읽기 OFF      → 쓰기·삭제 함께 OFF
   */
  function applyRules(tr) {
    var r = get(tr, 'read'), w = get(tr, 'write'), d = get(tr, 'delete');
    if (!r) { return; }
    if ((w && w.checked) || (d && d.checked)) { r.checked = true; }
    if (!r.checked) {
      if (w) { w.checked = false; }
      if (d) { d.checked = false; }
    }
  }
  function applyAllRules() {
    Array.prototype.forEach.call(block.querySelectorAll('[data-perm-row]'), applyRules);
  }

  function markDirty() { dirty = true; }

  // ── 체크 변경 ──
  block.addEventListener('change', function (e) {
    var cb = e.target;
    if (!cb || cb.type !== 'checkbox' || !cb.dataset.permAct) { return; }
    var tr = cb.closest('[data-perm-row]');
    if (tr) { applyRules(tr); }
    markDirty();
  });

  // ── 일괄 선택 버튼 ──
  block.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) { return; }
    var on, list;

    if (b.dataset.permAll !== undefined) {
      on = b.dataset.permAll === '1';
      boxes().forEach(function (c) { c.checked = on; });
      applyAllRules();
      markDirty();
      return;
    }
    if (b.dataset.permSectionAll) {
      var sec = block.querySelector('[data-perm-section="' + CSS.escape(b.dataset.permSectionAll) + '"]');
      if (!sec) { return; }
      list = boxes(sec);
      on = !list.every(function (c) { return c.checked; });   // 토글
      list.forEach(function (c) { c.checked = on; });
      applyAllRules();
      markDirty();
      return;
    }
    if (b.dataset.permRowAll) {
      list = rowBoxes(b.dataset.permRowAll);
      if (!list.length) { return; }
      on = !list.every(function (c) { return c.checked; });
      list.forEach(function (c) { c.checked = on; });
      applyAllRules();
      markDirty();
      return;
    }
    if (b.dataset.permCol) {
      var scope = block.querySelector('[data-perm-section="' + CSS.escape(b.dataset.permColSection) + '"]');
      list = boxes(scope, b.dataset.permCol);
      if (!list.length) { return; }
      on = !list.every(function (c) { return c.checked; });
      list.forEach(function (c) { c.checked = on; });
      applyAllRules();
      markDirty();
    }
  });

  // ── 미저장 이탈 경고 ──
  window.addEventListener('beforeunload', function (e) {
    if (!dirty) { return; }
    e.preventDefault();
    e.returnValue = '';
  });

  // 폼 제출 시에는 경고를 끈다(중복 제출 방지는 폼 스크립트가 담당)
  if (form) {
    form.addEventListener('submit', function () { dirty = false; });
    // 다른 필드 변경도 dirty 로 본다
    form.addEventListener('input', function (e) {
      if (e.target && e.target.closest('#permBlock')) { return; }
      markDirty();
    });
  }

  // 최초 렌더 시 저장된 값이 규칙에 어긋나면 즉시 정규화(표시 일관성)
  applyAllRules();
})();
