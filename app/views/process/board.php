<?php
/**
 * 공정 보드 — 18단계 열 유지(드래그 정밀도) + 5그룹 상단 탭(클라이언트 전환)·그룹색 액센트.
 * @var array $stages,$byStage,$photos,$groups,$tabs @var bool $canMove
 */
$today = date('Y-m-d');
$totalCount = 0;
foreach ($byStage as $list) { $totalCount += count($list); }
?>
<div class="page page-wide" data-board data-can-move="<?= $canMove ? '1' : '0' ?>">
  <div class="page-head">
    <div>
      <div class="page-title">공정 보드</div>
      <div class="page-sub">전체 <?= number_format($totalCount) ?>건 <?= $canMove ? '' : '· 이동 권한이 없어 조회만 가능합니다.' ?></div>
    </div>
  </div>

  <div class="pl-toolbar">
    <div class="pl-tabs" id="pcTabs">
      <?php foreach ($tabs as $key => $tab): ?>
        <button type="button" class="pl-tab<?= $key === 'all' ? ' active' : '' ?>" data-tab="<?= e($key) ?>"
                data-groups="<?= e(implode(',', $tab['groups'])) ?>">
          <?= e($tab['label']) ?><span class="tcnt" data-tab-count="<?= e($key) ?>">0</span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$totalCount): ?>
    <div class="empty"><div class="empty-title">표시할 프로젝트가 없습니다.</div></div>
  <?php endif; ?>

  <div class="kanban" id="processBoard">
    <?php foreach ($stages as $stage): $list = $byStage[(int) $stage['id']] ?? []; ?>
      <div class="kanban-col" data-group="<?= e($stage['group']) ?>" style="--gc:<?= e($stage['group_color']) ?>">
        <div class="kanban-col-head">
          <div class="kanban-col-title">
            <span class="kanban-caret" title="접기/펼치기">
              <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
            <span><?= e($stage['name']) ?></span>
            <?php if ($stage['requires_confirm']): ?><span title="이동 시 확인 필요" class="muted">🔒</span><?php endif; ?>
            <span class="kanban-count"><?= count($list) ?></span>
          </div>
        </div>
        <div class="kanban-list" data-stage-id="<?= (int) $stage['id'] ?>" data-requires-confirm="<?= (int) $stage['requires_confirm'] ?>">
          <?php if (!$list): ?><div class="kanban-empty">프로젝트 없음</div><?php endif; ?>
          <?php foreach ($list as $p):
            $daysLeft = !empty($p['end_date']) ? (int) floor((strtotime($p['end_date']) - strtotime($today)) / 86400) : null;
            $isDelayed = $daysLeft !== null && $daysLeft < 0 && $p['status'] !== 'completed';
            $isWarn = !$isDelayed && $daysLeft !== null && $daysLeft <= 7 && $p['status'] !== 'completed';
            $locked = in_array($p['status'], ['completed', 'cancelled'], true);
            $statusCls = $locked ? 'st-closed' : ($isDelayed ? 'st-delayed' : ($isWarn ? 'st-warn' : 'st-normal'));
          ?>
          <div class="kanban-card <?= $statusCls ?> <?= $locked ? 'locked' : '' ?>"
               data-project-id="<?= (int) $p['id'] ?>" data-status="<?= e($p['status']) ?>">
            <?php if (isset($photos[(int) $p['id']])): ?>
              <div class="kanban-card-photo"><img src="<?= e(url('files.download', ['id' => $photos[(int) $p['id']]])) ?>" alt="" loading="lazy"></div>
            <?php endif; ?>
            <div class="pc-title"><a href="<?= e(url('projects.show', ['id' => $p['id']])) ?>"><?= e(Util::truncate($p['name'], 24)) ?></a></div>
            <div class="pc-sub"><span><?= e($p['customer_name']) ?></span><span class="ellipsis" title="<?= e($p['work_type'] ?: '') ?>"><?= e(Util::truncate($p['work_type'] ?: '-', 8)) ?></span></div>
            <div class="progress"><div class="progress-bar <?= ((int) $p['progress']) >= 100 ? 'ok' : ($isDelayed ? 'danger' : '') ?>" style="width:<?= (int) $p['progress'] ?>%"></div></div>
            <div class="pc-foot">
              <span><?= e($p['site_manager_name'] ?? '미배정') ?> · <?= (int) $p['assign_count'] ?>명</span>
              <span class="<?= $isDelayed ? 'text-danger' : ($isWarn ? 'text-warn' : '') ?>">
                <?php if ($daysLeft !== null): ?><?= $isDelayed ? abs($daysLeft) . '일 지연' : 'D-' . $daysLeft ?><?php else: ?><?= (int) $p['progress'] ?>%<?php endif; ?>
              </span>
            </div>
            <div class="pc-actions">
              <?php if ($locked): ?><span class="badge badge-muted">완료</span><?php else: ?><span class="muted pc-pct" data-progress-text><?= (int) $p['progress'] ?>%</span><?php endif; ?>
              <button type="button" class="btn btn-ghost btn-sm history-btn" data-project-id="<?= (int) $p['id'] ?>">이력</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php $inlineScript = 'window.PROCESS_TABS = true;'; ?>
