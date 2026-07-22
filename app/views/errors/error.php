<?php /** @var int $code @var string $title @var string $message */ ?>
<div class="error-page">
  <div class="error-code"><?= e((string) $code) ?></div>
  <div class="error-title"><?= e($title) ?></div>
  <div class="error-message"><?= nl2br(e($message)) ?></div>
  <div class="error-actions">
    <a href="<?= e(url(Auth::check() ? 'home' : 'login')) ?>" class="btn btn-primary"><?= Auth::check() ? '대시보드로' : '로그인으로' ?></a>
  </div>
</div>
