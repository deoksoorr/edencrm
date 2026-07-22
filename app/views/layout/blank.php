<?php /** @var string $__content @var string $__title */ ?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($__title) ?> · <?= e($GLOBALS['config']['APP_NAME']) ?></title>
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<link rel="stylesheet" href="<?= e($GLOBALS['config']['BASE_URL']) ?>/assets/css/app.css">
</head>
<body class="blank-body">
<?php
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
if ($flash): ?>
  <div class="flash flash-<?= e($flash['type']) ?> flash-float" id="serverFlash"><?= e($flash['msg']) ?></div>
<?php endif; ?>
<?= $__content ?>
<script src="<?= e($GLOBALS['config']['BASE_URL']) ?>/assets/js/app.js"></script>
</body>
</html>
