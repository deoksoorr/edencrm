<?php
/** @var array $stages @var array $byStage @var array $photos @var bool $canMove */
$today = date('Y-m-d');
$totalCount = 0;
foreach ($byStage as $list) { $totalCount += count($list); }
?>
<style>
  .kanban-card-photo{width:100%;height:72px;border-radius:4px;overflow:hidden;margin-bottom:7px;background:var(--line-2)}
  .kanban-card-photo img{width:100%;height:100%;object-fit:cover;display:block}
  .kanban-card.locked{cursor:default;opacity:.9}
  .kanban-card:not(.locked){cursor:grab}
</style>
<div class="page" data-board data-can-move="<?= $canMove ? '1' : '0' ?>">
  <div class="page-head">
    <div>
      <h1 class="page-title">공정 보드</h1>
      <div class="page-sub">전체 <?= number_format($totalCount) ?>건 <?= $canMove ? '' : '· 이동 권한이 없어 조회만 가능합니다.' ?></div>
    </div>
  </div>

  <?php if (!$totalCount): ?>
    <div class="empty">
      <div class="empty-icon">🧱</div>
      <div class="empty-title">표시할 프로젝트가 없습니다.</div>
    </div>
  <?php endif; ?>

  <div class="kanban">
    <?php foreach ($stages as $stage): $list = $byStage[(int) $stage['id']] ?? []; ?>
      <div class="kanban-col">
        <div class="kanban-col-head">
          <div class="kanban-col-title">
            <?php if ($stage['color']): ?><span class="dot" style="background:<?= e($stage['color']) ?>"></span><?php endif; ?>
            <?= e($stage['name']) ?>
            <?php if ($stage['requires_confirm']): ?><span title="이동 시 확인 필요">🔒</span><?php endif; ?>
          </div>
          <span class="kanban-count"><?= count($list) ?></span>
        </div>
        <div class="kanban-list" data-stage-id="<?= (int) $stage['id'] ?>" data-requires-confirm="<?= (int) $stage['requires_confirm'] ?>">
          <?php if (!$list): ?><div class="kanban-empty">프로젝트 없음</div><?php endif; ?>
          <?php foreach ($list as $p):
            $daysLeft = null;
            if (!empty($p['end_date'])) {
                $daysLeft = (int) floor((strtotime($p['end_date']) - strtotime($today)) / 86400);
            }
            $isDelayed = $daysLeft !== null && $daysLeft < 0 && $p['status'] !== 'completed';
            $isWarn = !$isDelayed && $daysLeft !== null && $daysLeft <= 7 && $p['status'] !== 'completed';
            $locked = in_array($p['status'], ['completed', 'cancelled'], true);
          ?>
          <div class="kanban-card <?= $isDelayed ? 'delayed' : '' ?> <?= $isWarn ? 'warn' : '' ?> <?= $locked ? 'locked' : '' ?>"
               data-project-id="<?= (int) $p['id'] ?>" data-status="<?= e($p['status']) ?>">
            <?php if (isset($photos[(int) $p['id']])): ?>
              <div class="kanban-card-photo"><img src="<?= e(url('files.download', ['id' => $photos[(int) $p['id']]])) ?>" alt="" loading="lazy"></div>
            <?php endif; ?>
            <div class="kanban-card-title"><a href="<?= e(url('projects.show', ['id' => $p['id']])) ?>"><?= e(Util::truncate($p['name'], 22)) ?></a></div>
            <div class="kanban-card-row"><span><?= e($p['customer_name']) ?></span><span><?= e($p['work_type'] ?: '-') ?></span></div>
            <div class="kanban-card-row"><span class="ellipsis" title="<?= e($p['site_address'] ?: '') ?>"><?= e(Util::truncate($p['site_address'], 22)) ?></span></div>
            <div class="progress"><div class="progress-bar <?= ((int) $p['progress']) >= 100 ? 'ok' : ($isDelayed ? 'danger' : '') ?>" style="width:<?= (int) $p['progress'] ?>%"></div></div>
            <div class="progress-label"><?= (int) $p['progress'] ?>%</div>
            <div class="kanban-card-foot">
              <span><?= e($p['site_manager_name'] ?? '미배정') ?> · 배정 <?= (int) $p['assign_count'] ?>명</span>
              <span class="<?= $isDelayed ? 'text-danger' : ($isWarn ? 'text-warn' : '') ?>">
                <?= $p['start_date'] ? fmtdate($p['start_date'], 'm/d') : '-' ?>~<?= $p['end_date'] ? fmtdate($p['end_date'], 'm/d') : '-' ?>
                <?php if ($daysLeft !== null): ?>
                  (<?= $isDelayed ? abs($daysLeft) . '일 지연' : $daysLeft . '일 남음' ?>)
                <?php endif; ?>
              </span>
            </div>
            <div class="flex items-center" style="justify-content:space-between;margin-top:6px">
              <?php if ($locked): ?><span class="badge badge-muted">이동 제한(완료)</span><?php else: ?><span></span><?php endif; ?>
              <button type="button" class="btn btn-ghost btn-sm history-btn" data-project-id="<?= (int) $p['id'] ?>" style="padding:2px 8px">이력</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
