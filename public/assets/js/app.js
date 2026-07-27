/* EDEN CRM 공통 스크립트: fetch 래퍼, 토스트, 모달, 사이드바 */
(function () {
  'use strict';

  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const BASE = (function () {
    // index.php 기준 경로
    const path = window.location.pathname.replace(/[^/]*$/, '');
    return path;
  })();

  window.EDEN = window.EDEN || {};

  /** 라우트 URL 생성 */
  function routeUrl(route, params) {
    const qs = new URLSearchParams(Object.assign({ r: route }, params || {}));
    return BASE + 'index.php?' + qs.toString();
  }
  window.EDEN.url = routeUrl;

  /**
   * API 호출. GET 은 params, POST 는 body(FormData/object).
   * 반환: 해석된 data (ok:true) / 예외 throw (ok:false 또는 네트워크 오류)
   */
  async function api(route, data, opts) {
    opts = opts || {};
    const method = opts.method || (data ? 'POST' : 'GET');
    const url = routeUrl(route, method === 'GET' ? data : opts.params);
    const init = { method, headers: { 'X-Requested-With': 'XMLHttpRequest' } };

    if (method !== 'GET') {
      let body;
      if (data instanceof FormData) {
        body = data;
        body.append('_csrf', CSRF);
      } else {
        body = new URLSearchParams();
        Object.entries(data || {}).forEach(([k, v]) => {
          if (v !== undefined && v !== null) body.append(k, v);
        });
        body.append('_csrf', CSRF);
      }
      init.body = body;
      init.headers['X-CSRF-Token'] = CSRF;
    }

    const res = await fetch(url, init);
    let json;
    try { json = await res.json(); }
    catch (e) { throw new Error('서버 응답을 해석할 수 없습니다. (' + res.status + ')'); }

    if (!res.ok || json.ok === false) {
      const err = new Error(json.error || ('오류가 발생했습니다. (' + res.status + ')'));
      err.status = res.status;
      err.payload = json;
      throw err;
    }
    return json.data;
  }
  window.EDEN.api = api;
  window.api = api;

  /** 토스트 */
  function toast(msg, type) {
    let host = document.getElementById('toastHost');
    if (!host) {
      host = document.createElement('div');
      host.id = 'toastHost';
      document.body.appendChild(host);
    }
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'err' : type === 'warn' ? 'warn' : type === 'success' ? 'ok' : '');
    el.textContent = msg;
    host.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; }, 2600);
    setTimeout(() => el.remove(), 3000);
  }
  window.EDEN.toast = toast;
  window.toast = toast;

  /** 서버 플래시를 잠시 후 사라지게 */
  const sf = document.getElementById('serverFlash');
  if (sf && sf.classList.contains('flash-float')) {
    setTimeout(() => { sf.style.opacity = '0'; sf.style.transition = 'opacity .4s'; }, 3000);
    setTimeout(() => sf.remove(), 3400);
  }

  /** 모달 */
  function modal(opts) {
    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.innerHTML =
      '<div class="modal ' + (opts.wide ? 'wide' : '') + '">' +
        '<div class="modal-head"><div class="modal-title"></div>' +
        '<button class="modal-close" type="button">&times;</button></div>' +
        '<div class="modal-body"></div>' +
        (opts.footer === false ? '' : '<div class="modal-foot"></div>') +
      '</div>';
    backdrop.querySelector('.modal-title').textContent = opts.title || '';
    const body = backdrop.querySelector('.modal-body');
    if (typeof opts.body === 'string') body.innerHTML = opts.body;
    else if (opts.body) body.appendChild(opts.body);

    function close() { backdrop.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    backdrop.querySelector('.modal-close').addEventListener('click', close);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
    document.addEventListener('keydown', onKey);

    const foot = backdrop.querySelector('.modal-foot');
    if (foot && opts.buttons) {
      opts.buttons.forEach((b) => {
        const btn = document.createElement('button');
        btn.className = 'btn ' + (b.class || 'btn-outline');
        btn.textContent = b.label;
        btn.addEventListener('click', () => b.onClick && b.onClick(close, btn));
        foot.appendChild(btn);
      });
    }
    document.body.appendChild(backdrop);
    return { close, el: backdrop, body };
  }
  window.EDEN.modal = modal;

  /** 확인 대화상자 (Promise<boolean>) */
  function confirmDialog(message, opts) {
    opts = opts || {};
    return new Promise((resolve) => {
      const m = modal({
        title: opts.title || '확인',
        body: '<p style="margin:0;color:#4b5563">' + message + '</p>',
        buttons: [
          { label: opts.cancelLabel || '취소', class: 'btn-outline', onClick: (close) => { close(); resolve(false); } },
          { label: opts.okLabel || '확인', class: opts.danger ? 'btn-danger' : 'btn-primary', onClick: (close) => { close(); resolve(true); } },
        ],
      });
    });
  }
  window.EDEN.confirm = confirmDialog;

  /** 폼 AJAX 제출 헬퍼: data-ajax 폼 자동 처리 */
  document.addEventListener('submit', async function (e) {
    const form = e.target;
    if (!form.matches('form[data-ajax]')) return;
    e.preventDefault();
    const route = form.getAttribute('action-route') || form.dataset.route;
    const btn = form.querySelector('[type="submit"]');
    if (btn) { btn.disabled = true; btn.dataset._t = btn.innerHTML; btn.innerHTML = '<span class="spinner"></span> 저장 중'; }
    try {
      const data = await api(route, new FormData(form));
      toast(form.dataset.success || '저장되었습니다.', 'success');
      if (form.dataset.redirect) location.href = routeUrl(form.dataset.redirect, data && data.id ? { id: data.id } : {});
      else if (form.dataset.reload !== undefined) location.reload();
      document.dispatchEvent(new CustomEvent('eden:saved', { detail: { form, data } }));
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset._t; }
    }
  });

  /**
   * 저장 완료된 폼 페이지의 뒤로가기 복원 무효화 + 중복 제출 방지.
   * 브라우저는 back/forward 시 세션 히스토리(크롬·파이어폭스) 또는 bfcache(iOS 사파리)로
   * 제출했던 입력값을 그대로 되살린다 → "저장 후 이전 입력값 잔존"·재제출 중복 등록·
   * stale 페이지의 IME 오동작(iOS)으로 이어진다. 저장이 "성공"(성공 플래시 페이지 도착)한
   * 제출원 폼 URL 만 마킹해 두고, 그 URL 로 back/forward 복귀 시 서버 재렌더한다.
   * 검증 실패(성공 플래시 없음) 후 뒤로가기는 마킹하지 않아 재작성 입력값을 보존한다.
   */
  const PEND_KEY = 'eden:formPending';
  const DONE_KEY = 'eden:formDone:';
  // 히스토리 엔트리별 고유 ID — 같은 URL 을 새로 열어도(새 엔트리) 이전 제출 엔트리와 구분된다.
  // back/forward 재실행·bfcache 복원 시에는 history.state 가 보존되어 기존 ID 를 유지한다.
  let entrySid = null;
  try {
    entrySid = history.state && history.state.edenSid;
    if (!entrySid) {
      entrySid = Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
      history.replaceState(Object.assign({}, history.state, { edenSid: entrySid }), '');
    }
  } catch (err) { /* 무시 */ }
  document.addEventListener('submit', function (e) {
    const form = e.target;
    if (e.defaultPrevented || !form.matches || form.matches('form[data-ajax]')) return;
    if ((form.method || '').toLowerCase() !== 'post') return;
    try { if (entrySid) sessionStorage.setItem(PEND_KEY, entrySid); } catch (err) { /* 프라이빗 모드 등 */ }
    // 중복 제출 방지 — 제출 확정(defaultPrevented 아님) 후 버튼 잠금. disable 은 폼 직렬화
    // 이후에 적용되도록 지연하고, 뒤로가기로 문서가 되살아나면 pageshow 에서 해제한다.
    const btn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (btn && !btn.dataset.busy) {
      btn.dataset.busy = '1';
      setTimeout(() => { btn.disabled = true; }, 0);
    }
  });
  window.addEventListener('pageshow', function (e) {
    document.querySelectorAll('[data-busy]').forEach((b) => { b.disabled = false; delete b.dataset.busy; });
    const nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    if (!e.persisted && (!nav || nav.type !== 'back_forward')) return;
    try {
      const sid = history.state && history.state.edenSid;
      if (sid && sessionStorage.getItem(DONE_KEY + sid) === '1') {
        sessionStorage.removeItem(DONE_KEY + sid);
        location.reload();
      }
    } catch (err) { /* 무시 */ }
  });
  try {
    const pending = sessionStorage.getItem(PEND_KEY);
    if (pending) {
      sessionStorage.removeItem(PEND_KEY);
      // 저장 성공(성공 플래시 도착) 시에만 제출원 엔트리를 무효화 — 검증 실패 후 재작성 입력은 보존
      if (sf && sf.classList.contains('flash-success') && pending !== entrySid) {
        sessionStorage.setItem(DONE_KEY + pending, '1');
      }
    }
  } catch (err) { /* 무시 */ }

  /** 사이드바(모바일) */
  const hamburger = document.getElementById('hamburger');
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  function toggleSidebar(open) {
    if (!sidebar) return;
    sidebar.classList.toggle('open', open);
    if (overlay) overlay.classList.toggle('show', open);
  }
  if (hamburger) hamburger.addEventListener('click', () => toggleSidebar(!sidebar.classList.contains('open')));
  if (overlay) overlay.addEventListener('click', () => toggleSidebar(false));

  /** 숫자 입력 천단위 콤마 표시 헬퍼 */
  window.EDEN.formatMoney = function (n) {
    if (n === null || n === undefined || n === '') return '-';
    return Number(n).toLocaleString('ko-KR');
  };
  window.EDEN.CSRF = CSRF;

  /**
   * 전역 이미지 fallback — 깨진 썸네일(엑박) 방지.
   * error 이벤트는 버블링하지 않으므로 캡처 단계로 문서 전체를 감지한다.
   * AJAX 로 삽입된 이미지에도 자동 적용된다(별도 배선 불필요).
   */
  var IMG_PLACEHOLDER = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">' +
    '<rect width="120" height="120" fill="#eef1f4"/>' +
    '<g fill="none" stroke="#b6bfc9" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">' +
    '<rect x="30" y="34" width="60" height="46" rx="4"/>' +
    '<circle cx="47" cy="50" r="6"/>' +
    '<path d="M32 74l18-16 12 10 12-12 14 14"/></g>' +
    '<text x="60" y="98" font-family="-apple-system,sans-serif" font-size="11" fill="#9aa5b1" text-anchor="middle">이미지 없음</text></svg>'
  );
  window.EDEN.IMG_PLACEHOLDER = IMG_PLACEHOLDER;
  document.addEventListener('error', function (e) {
    var img = e.target;
    if (!img || img.tagName !== 'IMG') return;
    if (img.dataset.fbDone || img.src === IMG_PLACEHOLDER) return;
    img.dataset.fbDone = '1';
    img.src = IMG_PLACEHOLDER;
    img.classList.add('img-fallback');
  }, true);

  /**
   * 칸반 보드(파이프라인·공정) 빈 영역을 마우스로 잡아 좌우 스크롤.
   * 카드·입력·버튼·링크·캐럿 위에서는 동작하지 않아 드래그이동/클릭과 충돌하지 않는다.
   */
  document.addEventListener('mousedown', function (e) {
    if (e.button !== 0) return;
    var board = e.target.closest('.kanban');
    if (!board) return;
    if (e.target.closest('.kanban-card, input, textarea, select, button, a, .kanban-caret')) return;
    var startX = e.pageX;
    var startScroll = board.scrollLeft;
    board.classList.add('drag-scrolling');
    function onMove(ev) {
      board.scrollLeft = startScroll - (ev.pageX - startX);
      ev.preventDefault();
    }
    function onUp() {
      board.classList.remove('drag-scrolling');
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
    }
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });
})();
