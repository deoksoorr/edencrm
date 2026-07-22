<?php /** @var array|null $staff @var array $departments @var array $roles */ ?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title"><?= $staff ? '직원 정보 수정' : '직원 등록' ?></h1>
    <div class="page-actions"><a href="<?= e(url('staff.index')) ?>" class="btn btn-outline">목록으로</a></div>
  </div>

  <div class="card">
    <div class="card-body">
      <form id="staffForm" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <?php if ($staff): ?><input type="hidden" name="id" value="<?= (int) $staff['id'] ?>"><?php endif; ?>
        <div class="form-grid">
          <div class="field">
            <label class="field-label">아이디<span class="req">*</span></label>
            <input type="text" name="login_id" class="input" required value="<?= e($staff['login_id'] ?? '') ?>" placeholder="로그인 아이디">
          </div>
          <div class="field">
            <label class="field-label">이메일<span class="req">*</span></label>
            <input type="email" name="email" class="input" required value="<?= e($staff['email'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="field-label">이름<span class="req">*</span></label>
            <input type="text" name="name" class="input" required value="<?= e($staff['name'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="field-label">연락처</label>
            <input type="text" name="phone" class="input" value="<?= e($staff['phone'] ?? '') ?>" placeholder="010-0000-0000">
          </div>
          <div class="field">
            <label class="field-label">부서</label>
            <select name="department_id" class="select">
              <option value="">미지정</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (isset($staff['department_id']) && (int) $staff['department_id'] === (int) $d['id']) ? 'selected' : '' ?>><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="field-label">직급</label>
            <input type="text" name="position" class="input" value="<?= e($staff['position'] ?? '') ?>" placeholder="예: 대리, 팀장">
          </div>
          <div class="field">
            <label class="field-label">역할<span class="req">*</span></label>
            <select name="role_id" class="select" required>
              <option value="">선택</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['id'] ?>" <?= (isset($staff['role_id']) && (int) $staff['role_id'] === (int) $r['id']) ? 'selected' : '' ?>><?= e($r['name']) ?> (<?= e($r['role_key']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="field-label">입사일</label>
            <input type="date" name="hire_date" class="input" value="<?= e($staff['hire_date'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="field-label">재직 상태</label>
            <select name="status" class="select" <?= ($staff && $staff['role_key'] === 'super_admin') ? 'disabled' : '' ?>>
              <option value="active" <?= (!$staff || $staff['status'] === 'active') ? 'selected' : '' ?>>재직</option>
              <option value="inactive" <?= ($staff && $staff['status'] === 'inactive') ? 'selected' : '' ?>>비활성</option>
            </select>
            <?php if ($staff && $staff['role_key'] === 'super_admin'): ?><div class="field-hint">슈퍼관리자 계정은 비활성화할 수 없습니다.</div><?php endif; ?>
          </div>
          <?php $curColor = $staff['color'] ?? Stages::staffColors()[0]; ?>
          <div class="field col-span-2">
            <label class="field-label">개인 색</label>
            <input type="hidden" name="color" id="staffColor" value="<?= e($curColor) ?>">
            <div class="swatch-row" id="swatchRow">
              <?php foreach (Stages::staffColors() as $c): ?>
                <button type="button" class="swatch<?= strtolower($curColor) === strtolower($c) ? ' active' : '' ?>" data-color="<?= e($c) ?>" style="background:<?= e($c) ?>" aria-label="<?= e($c) ?>"></button>
              <?php endforeach; ?>
            </div>
            <div class="field-hint">일정(캘린더·타임라인)에 이 직원이 이 색으로 표시됩니다. 고정 팔레트에서 선택하세요.</div>
          </div>
          <?php if (!$staff): ?>
          <div class="field col-span-2">
            <label class="field-label">초기 비밀번호 (비우면 자동 발급)</label>
            <input type="text" name="initial_password" class="input" placeholder="비워두면 임시 비밀번호가 자동 발급됩니다">
            <div class="field-hint">최초 로그인 시 비밀번호 변경이 강제됩니다(must_change_password).</div>
          </div>
          <?php endif; ?>
        </div>
        <div class="btn-group">
          <button type="submit" class="btn btn-primary">저장</button>
          <a href="<?= e(url('staff.index')) ?>" class="btn btn-outline">취소</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// 색상 스와치 선택(고정 팔레트)
(function () {
  var row = document.getElementById('swatchRow');
  if (!row) return;
  row.addEventListener('click', function (e) {
    var b = e.target.closest('.swatch'); if (!b) return;
    row.querySelectorAll('.swatch').forEach(function (s) { s.classList.remove('active'); });
    b.classList.add('active');
    document.getElementById('staffColor').value = b.dataset.color;
  });
})();
document.getElementById('staffForm').addEventListener('submit', async function (e) {
  e.preventDefault();
  const form = e.target;
  const btn = form.querySelector('button[type="submit"]');
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> 저장 중';
  try {
    const data = await api('staff.save', new FormData(form));
    if (data.temp_password) {
      EDEN.modal({
        title: '직원 등록 완료',
        body: '<p>초기 비밀번호가 발급되었습니다. 직원에게 안전하게 전달하세요.</p>' +
              '<p style="font-size:20px;font-weight:700;letter-spacing:1px;background:var(--line-2);padding:10px 14px;border-radius:6px;text-align:center">' + data.temp_password + '</p>',
        buttons: [{ label: '확인', class: 'btn-primary', onClick: (close) => { close(); location.href = EDEN.url('staff.index'); } }],
      });
    } else {
      toast('저장되었습니다.', 'success');
      location.href = EDEN.url('staff.index');
    }
  } catch (err) {
    toast(err.message, 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = orig;
  }
});
</script>
