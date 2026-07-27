<?php
/** @var array $rows @var array $pg @var string $filter @var int $unread */
$typeLabels = [
    'lead_contact_due'  => ['연락예정', 'badge-info'],
    'payment_due'       => ['입금예정', 'badge-info'],
    'payment_overdue'   => ['미수금', 'badge-danger'],
    'project_start_due' => ['착공예정', 'badge-info'],
    'project_end_due'   => ['준공예정', 'badge-warn'],
    'project_delayed'   => ['공정지연', 'badge-danger'],
    'worklog_missing'   => ['일지누락', 'badge-warn'],
    'password_reset'    => ['계정', 'badge-muted'],
];
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">알림</h1>
      <div class="page-sub">읽지 않은 알림 <?= (int) $unread ?>건</div>
    </div>
    <div class="page-actions">
      <button type="button" class="btn btn-outline" id="btnReadAll" <?= $unread > 0 ? '' : 'disabled' ?>>전체 읽음 처리</button>
    </div>
  </div>

  <div class="tabs">
    <a class="tab <?= $filter !== 'unread' ? 'active' : '' ?>" href="<?= e(url('notifications.index')) ?>">전체</a>
    <a class="tab <?= $filter === 'unread' ? 'active' : '' ?>" href="<?= e(url('notifications.index', ['filter' => 'unread'])) ?>">읽지 않음</a>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-icon">🔔</div>
      <div class="empty-title">알림이 없습니다.</div>
    </div>
  <?php else: ?>
  <div class="card">
    <?php foreach ($rows as $i => $n):
      $params = $n['link_params'] ? (json_decode($n['link_params'], true) ?: []) : [];
      unset($params['_eid']);
      $href = $n['link_route'] ? url($n['link_route'], $params) : '';
      $tl = $typeLabels[$n['type']] ?? [$n['type'], 'badge-muted'];
    ?>
      <div class="notif-row<?= (int) $n['is_read'] === 0 ? ' unread' : '' ?><?= $i > 0 ? ' notif-sep' : '' ?>"
           data-id="<?= (int) $n['id'] ?>" data-href="<?= e($href) ?>" data-unread="<?= (int) $n['is_read'] === 0 ? '1' : '0' ?>">
        <div class="notif-main">
          <span class="badge <?= e($tl[1]) ?>"><?= e($tl[0]) ?></span>
          <span class="notif-title"><?= e($n['title']) ?></span>
          <?php if ((int) $n['is_read'] === 0): ?><span class="dot" style="background:var(--brand)"></span><?php endif; ?>
        </div>
        <?php if ($n['message']): ?><div class="notif-msg"><?= e($n['message']) ?></div><?php endif; ?>
        <div class="notif-time"><?= e(fmtdate($n['created_at'], 'Y-m-d H:i')) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php View::partial('partials/pager', [
      'pg'  => $pg,
      'url' => fn (int $p): string => url('notifications.index', ['filter' => $filter, 'page' => $p]),
  ]); ?>
  <?php endif; ?>
</div>

<?php /* 화면 전용 <style> 은 app.css 의 r3-formscss 블록(.notif-*)으로 승격됨 */ ?>
<script>
document.querySelectorAll('.notif-row').forEach(function (row) {
  row.addEventListener('click', async function () {
    const id = row.dataset.id;
    const href = row.dataset.href;
    if (row.dataset.unread === '1') {
      try { await api('notifications.read', { id }); } catch (e) {}
    }
    if (href) location.href = href;
    else location.reload();
  });
});

const btnReadAll = document.getElementById('btnReadAll');
if (btnReadAll) {
  btnReadAll.addEventListener('click', async function () {
    btnReadAll.disabled = true;
    try {
      await api('notifications.readall', {});
      toast('전체 읽음 처리되었습니다.', 'success');
      location.reload();
    } catch (err) {
      toast(err.message, 'error');
      btnReadAll.disabled = false;
    }
  });
}
</script>
