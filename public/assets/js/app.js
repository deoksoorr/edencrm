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
      else if (form.dataset.reload) location.reload();
      document.dispatchEvent(new CustomEvent('eden:saved', { detail: { form, data } }));
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset._t; }
    }
  });

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
})();
