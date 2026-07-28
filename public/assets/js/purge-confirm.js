/**
 * R16 — 완전삭제 2단계 확인.
 * 요청서 7-4: "정말 삭제하시겠습니까?" 같은 모호한 안내를 금지하고
 * 대상명·식별번호·연관 삭제 범위를 명시한 뒤, 대상명을 직접 입력해야 진행한다.
 *
 * 사용: <form data-purge data-purge-label="Q-2026-001" data-purge-kind="견적"
 *             data-purge-scope="견적 버전·항목">
 * 서버(Perm::requireSuperAdmin + CSRF)가 최종 권한을 판정하므로 이 확인은 오조작 방지용이다.
 */
(function () {
  function confirmPurge(form) {
    var kind  = form.dataset.purgeKind  || '데이터';
    var label = form.dataset.purgeLabel || '';
    var scope = form.dataset.purgeScope || '';

    var msg = '이 작업은 ' + kind + ' ' + label + (scope ? '과(와) ' + scope : '') +
              '을(를) 서버에서 완전히 삭제합니다.\n' +
              '완전삭제 후에는 복구할 수 없습니다.\n\n' +
              '계속하려면 [확인]을 누르세요.';
    if (!window.confirm(msg)) { return false; }

    // 2단계 — 대상명 직접 입력
    var typed = window.prompt(
      '최종 확인입니다.\n삭제하려면 아래 이름을 그대로 입력하세요.\n\n' + label, '');
    if (typed === null) { return false; }
    if (typed.trim() !== label.trim()) {
      window.alert('입력한 값이 대상과 일치하지 않아 완전삭제를 취소했습니다.');
      return false;
    }
    return true;
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.dataset.purge === undefined) { return; }
    if (form.dataset.purgeOk === '1') { return; }        // 이미 통과
    e.preventDefault();
    if (!confirmPurge(form)) { return; }
    form.dataset.purgeOk = '1';
    // 중복 제출 방지 — 버튼 비활성화 후 1회만 전송
    var btn = form.querySelector('button[type=submit]');
    if (btn) { btn.disabled = true; btn.textContent = '삭제 중…'; }
    form.submit();
  }, true);
})();
