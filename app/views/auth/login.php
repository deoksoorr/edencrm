<?php /** 로그인 화면 */ ?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="auth-logo">EDEN</div>
      <div class="auth-title">도장 CRM 업무 시스템</div>
      <div class="auth-desc">직원 계정으로 로그인하세요</div>
    </div>
    <form method="post" action="<?= e(url('login.submit')) ?>" class="auth-form" autocomplete="off">
      <?= csrf_field() ?>
      <label class="field">
        <span class="field-label">아이디</span>
        <input type="text" name="login_id" class="input" required autofocus placeholder="아이디 또는 이메일">
      </label>
      <label class="field">
        <span class="field-label">비밀번호</span>
        <input type="password" name="password" class="input" required placeholder="비밀번호">
      </label>
      <button type="submit" class="btn btn-primary btn-block">로그인</button>
    </form>
    <div class="auth-foot">
      <span>테스트 계정: <b>admin / password123!</b></span>
    </div>
  </div>
</div>
