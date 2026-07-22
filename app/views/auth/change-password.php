<?php /** @var bool $forced */ ?>
<?php if ($forced): ?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="auth-title">비밀번호 변경</div>
      <div class="auth-desc">최초 로그인 시 새 비밀번호를 설정해야 합니다.</div>
    </div>
<?php else: ?>
<div class="page">
  <div class="page-head"><h1 class="page-title">비밀번호 변경</h1></div>
  <div class="card" style="max-width:480px">
<?php endif; ?>

    <form method="post" action="<?= e(url('password.update')) ?>" class="<?= $forced ? 'auth-form' : 'form' ?>">
      <?= csrf_field() ?>
      <?php if (!$forced): ?>
      <label class="field">
        <span class="field-label">현재 비밀번호</span>
        <input type="password" name="current_password" class="input" required>
      </label>
      <?php endif; ?>
      <label class="field">
        <span class="field-label">새 비밀번호</span>
        <input type="password" name="new_password" class="input" required minlength="8" placeholder="영문+숫자 8자 이상">
      </label>
      <label class="field">
        <span class="field-label">새 비밀번호 확인</span>
        <input type="password" name="confirm_password" class="input" required minlength="8">
      </label>
      <button type="submit" class="btn btn-primary<?= $forced ? ' btn-block' : '' ?>">변경하기</button>
    </form>

<?php if ($forced): ?>
  </div>
</div>
<?php else: ?>
  </div>
</div>
<?php endif; ?>
