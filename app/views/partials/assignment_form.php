<?php
/**
 * 직원 배정 폼 파셜 (R3 schedstaff 제공 — 조립은 projects/show.php 소유자 projdetail 담당)
 *
 * 사용법:
 *   $projectId       = (int) $p['id'];                 // 필수: 대상 프로젝트 ID
 *   $assignedUserIds = [2, 3];                          // 선택: 이미 active 배정된 직원 ID 배열
 *                                                       //       (미지정 시 DB에서 자동 조회)
 *   include APP_PATH . '/views/partials/assignment_form.php';
 *
 * 기능:
 *  - 직원 ID 직접 입력 제거 → 활성 직원만(이름 — 부서 · 직책) 검색 가능한 select
 *    (외부 라이브러리 없이 텍스트 입력으로 옵션 필터링, 이미 배정된 직원은 disabled)
 *  - 역할은 AssignmentsController::ROLES 허용 목록 select (자유 입력 422 방지)
 *  - 기여도·시작/종료일 입력 유지, AJAX 제출(성공 시 새로고침, 422 메시지 인라인 표시)
 *  - 서버 검증(assignments.save): 중복 active 배정·비활성 직원 배정 422 거부
 */
$projectId = (int) ($projectId ?? 0);
if (!isset($assignedUserIds) || !is_array($assignedUserIds)) {
    $assignedUserIds = $projectId > 0 ? array_map('intval', array_column(Db::all(
        "SELECT user_id FROM project_assignments WHERE project_id = :pid AND status = 'active'",
        [':pid' => $projectId]
    ), 'user_id')) : [];
}
$afUsers = Db::all(
    "SELECT u.id, u.name, u.position, d.name AS department_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.status = 'active' AND u.deleted_at IS NULL
     ORDER BY u.name"
);
// R10: 최초 배정 제안값 = 남은 기여도(100 − 활성 배정 합, 0~100 클램프) — 강제가 아닌 제안(수정 가능)
$afActiveSum = (float) ($projectId > 0 ? Db::val(
    "SELECT COALESCE(SUM(contribution_pct),0) FROM project_assignments WHERE project_id = :pid AND status = 'active'",
    [':pid' => $projectId]
) : 0);
$afSuggestPct = max(0, min(100, round(100 - $afActiveSum, 2)));
require_once APP_PATH . '/controllers/AssignmentsController.php';
$afRoles = AssignmentsController::ROLES;
?>
<form method="post" action="<?= e(url('assignments.save')) ?>" class="form-grid mt-14 af-form" id="afForm-<?= $projectId ?>" data-project-id="<?= $projectId ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="project_id" value="<?= $projectId ?>">
  <input type="hidden" name="status" value="active">
  <div class="field">
    <label class="field-label">직원 <span class="req">*</span></label>
    <input type="text" class="input af-search" placeholder="이름·부서·직책 검색" autocomplete="off" aria-label="직원 검색">
    <select name="user_id" class="select af-user" required>
      <option value="">직원 선택</option>
      <?php foreach ($afUsers as $u):
          $assigned = in_array((int) $u['id'], $assignedUserIds, true);
          $meta = trim(($u['department_name'] ?: '') . ($u['position'] ? ' · ' . $u['position'] : ''), ' ·'); ?>
        <option value="<?= (int) $u['id'] ?>"
                data-search="<?= e(mb_strtolower($u['name'] . ' ' . ($u['department_name'] ?: '') . ' ' . ($u['position'] ?: ''))) ?>"
                <?= $assigned ? 'disabled' : '' ?>>
          <?= e($u['name']) ?><?= $meta !== '' ? ' — ' . e($meta) : '' ?><?= $assigned ? ' (배정됨)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label class="field-label">역할 <span class="req">*</span></label>
    <select name="role" class="select" required>
      <?php foreach ($afRoles as $r): ?>
        <option value="<?= e($r) ?>"><?= e($r) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label class="field-label">기여도(%) <span class="muted">(남은 <?= e((string) $afSuggestPct) ?>% 제안)</span></label>
    <input type="number" name="contribution_pct" class="input" step="0.01" min="0" max="100" value="<?= e((string) $afSuggestPct) ?>"></div>
  <div class="field"><label class="field-label">시작일</label><input type="date" name="start_date" class="input"></div>
  <div class="field"><label class="field-label">종료일</label><input type="date" name="end_date" class="input"></div>
  <div class="field field-action"><button type="submit" class="btn btn-primary">배정 추가</button></div>
  <div class="af-error" role="alert" style="display:none"></div>
</form>
<script>
(function () {
  'use strict';
  var form = document.getElementById('afForm-<?= $projectId ?>');
  if (!form) return;
  var search = form.querySelector('.af-search');
  var select = form.querySelector('.af-user');
  var errBox = form.querySelector('.af-error');
  // 전체 옵션 사본(placeholder 제외) — 필터 시 재구성(사파리 option hidden 미지원 대응)
  var master = Array.prototype.slice.call(select.options, 1);

  search.addEventListener('input', function () {
    var q = search.value.trim().toLowerCase();
    var cur = select.value;
    while (select.options.length > 1) select.remove(1);
    master.forEach(function (opt) {
      if (!q || (opt.getAttribute('data-search') || '').indexOf(q) !== -1) select.add(opt);
    });
    // 필터 후에도 기존 선택이 목록에 있으면 유지
    if (cur && Array.prototype.some.call(select.options, function (o) { return o.value === cur && !o.disabled; })) {
      select.value = cur;
    }
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    errBox.style.display = 'none';
    if (!select.value) { showErr('직원을 선택하세요.'); return; }
    // R10: 합계 경고 — 새 배정 값 포함 총합이 100% 아니면 확인, 초과면 차단 안내
    var pctVal = parseFloat(form.querySelector('[name="contribution_pct"]').value) || 0;
    if (window.afSumCheck && !window.afSumCheck(null, pctVal)) return;
    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    var fd = new FormData(form);
    fetch(form.action, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    }).then(function (res) { return res.json().then(function (j) { return { ok: res.ok, j: j }; }); })
      .then(function (r) {
        if (!r.ok || r.j.ok === false) { throw new Error(r.j.error || '저장에 실패했습니다.'); }
        if (window.EDEN && EDEN.toast) EDEN.toast('배정되었습니다.', 'success');
        location.reload();
      })
      .catch(function (err) { showErr(err.message); btn.disabled = false; });
  });

  function showErr(msg) {
    errBox.textContent = msg;
    errBox.style.display = 'block';
    if (window.EDEN && EDEN.toast) EDEN.toast(msg, 'error');
  }
})();
</script>
