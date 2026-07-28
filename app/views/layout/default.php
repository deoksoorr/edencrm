<?php
/** @var string $__content @var string $__title */
$me = Auth::user();
$current = $_GET['r'] ?? 'home';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$unread = 0;
try {
    $unread = (int) Db::val("SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0", [':u' => Auth::id()]);
} catch (\Throwable $e) {}
$roleLabels = ['super_admin' => '슈퍼관리자', 'sales_manager' => '영업관리자', 'site_manager' => '현장관리자', 'staff' => '직원', 'accountant' => '회계'];
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($__title) ?> · <?= e($GLOBALS['config']['APP_NAME']) ?></title>
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<?php /* 정적 자원 캐시버스팅: 파일 수정시각을 버전으로 — 배포 즉시 새 CSS/JS 반영(모바일 캐시 잔존 방지) */
$__assetVer = static fn(string $rel): string => (string) (@filemtime(__DIR__ . '/../../../public/assets/' . $rel) ?: 1); ?>
<link rel="stylesheet" href="<?= e($GLOBALS['config']['BASE_URL']) ?>/assets/css/app.css?v=<?= $__assetVer('css/app.css') ?>">
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <span class="brand-mark">EDEN</span>
      <span class="brand-sub">도장 CRM</span>
    </div>
    <nav class="nav">
      <?php foreach (Nav::items() as $section => $items): ?>
        <?php
          $visible = array_filter($items, fn($it) => empty($it[2]) || Rbac::can($it[2]));
          if (!$visible) continue;
        ?>
        <?php if ($section !== ''): ?><div class="nav-section"><?= e($section) ?></div><?php endif; ?>
        <?php foreach ($visible as $it): ?>
          <a href="<?= e(url($it[0])) ?>" class="nav-item<?= Nav::isActive($it[0], $current) ? ' active' : '' ?>">
            <span class="nav-icon"><?= Nav::icon($it[3] ?? 'grid') ?></span>
            <span class="nav-label"><?= e($it[1]) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="main">
    <header class="topbar">
      <button class="hamburger" id="hamburger" aria-label="메뉴">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-title"><?= e($__title) ?></div>
      <div class="topbar-right">
        <a href="<?= e(url('notifications.index')) ?>" class="topbar-bell" title="알림">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <?php if ($unread > 0): ?><span class="badge-count"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?>
        </a>
        <div class="topbar-user">
          <div class="user-avatar"><?= e(mb_substr($me['name'], 0, 1)) ?></div>
          <div class="user-meta">
            <div class="user-name"><?= e($me['name']) ?></div>
            <div class="user-role"><?= e($roleLabels[$me['role']] ?? $me['role']) ?></div>
          </div>
          <div class="user-menu">
            <a href="<?= e(url('password.change')) ?>">비밀번호 변경</a>
            <a href="<?= e(url('logout')) ?>">로그아웃</a>
          </div>
        </div>
      </div>
    </header>

    <?php if ($flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>" id="serverFlash"><?= e($flash['msg']) ?><?php /* r3-contractflow: 플래시에 선택적 이동 링크 */ if (!empty($flash['link']['url'])): ?> <a href="<?= e($flash['link']['url']) ?>"><?= e($flash['link']['label'] ?? '바로 가기 →') ?></a><?php endif; ?></div>
    <?php endif; ?>

    <main class="content">
      <?= $__content ?>
    </main>
  </div>
</div>
<script src="<?= e($GLOBALS['config']['BASE_URL']) ?>/assets/js/app.js?v=<?= $__assetVer('js/app.js') ?>"></script>
<?php /* R16: 완전삭제 2단계 확인 — [data-purge] 폼에만 반응하므로 전역 로드해도 무해 */ ?>
<script src="<?= e($GLOBALS['config']['BASE_URL']) ?>/assets/js/purge-confirm.js?v=<?= $__assetVer('js/purge-confirm.js') ?>"></script>
<?php foreach (($scripts ?? []) as $s): ?>
<script src="<?= e($GLOBALS['config']['BASE_URL']) ?>/assets/<?= e($s) ?>?v=<?= $__assetVer($s) ?>"></script>
<?php endforeach; ?>
<?php if (!empty($inlineScript)): ?><script><?= $inlineScript ?></script><?php endif; ?>
</body>
</html>
