<?php
/** 칸반 컬럼 조각(초기 렌더 + AJAX 필터 새로고침 공용). @var array $columns @var bool $canManage */
$impBadge = ['high' => 'badge-danger', 'mid' => 'badge-warn', 'low' => 'badge-muted'];
foreach ($columns as $col):
    $stage = $col['stage'];
?>
  <div class="kanban-col" data-stage-id="<?= (int) $stage['id'] ?>">
    <div class="kanban-col-head">
      <div class="kanban-col-title">
        <span class="dot" style="background:<?= e($stage['color'] ?: '#94a3b8') ?>"></span><?= e($stage['name']) ?>
        <span class="kanban-count"><?= count($col['leads']) ?></span>
      </div>
      <div class="kanban-col-sum"><?= money((float) $col['sum']) ?>원</div>
    </div>
    <div class="kanban-list" data-stage-id="<?= (int) $stage['id'] ?>">
      <?php if (!$col['leads']): ?>
        <div class="kanban-empty">카드 없음</div>
      <?php endif; ?>
      <?php foreach ($col['leads'] as $l): ?>
        <div class="kanban-card<?= $l['delayed'] ? ' delayed' : ($l['warn'] ? ' warn' : '') ?>" data-lead-id="<?= (int) $l['id'] ?>">
          <div class="kanban-card-title">
            <?= e($l['customer_name']) ?><?= $l['company_name'] ? ' (' . e($l['company_name']) . ')' : '' ?>
          </div>
          <div class="kanban-card-row">
            <span class="ellipsis" title="<?= e($l['site_address'] ?: '') ?>"><?= e(Util::truncate($l['work_type'] ?: '-', 10)) ?> · <?= e(Util::truncate($l['site_address'], 18)) ?></span>
          </div>
          <div class="kanban-card-row">
            <span><?= e($l['sales_user_name'] ?: '담당 미지정') ?></span>
            <?php if ($l['importance']): ?><span class="badge <?= $impBadge[$l['importance']] ?? '' ?>"><?= e(strtoupper($l['importance'])) ?></span><?php endif; ?>
          </div>
          <div class="kanban-card-amount"><?= money((float) $l['expected_amount']) ?>원</div>
          <div class="kanban-card-row">
            <span>순이익 <?= money($l['profit']) ?> (<?= pct($l['profit_rate']) ?>)</span>
          </div>
          <div class="kanban-card-row">
            <span>성공률 <?= pct($l['win_probability'] !== null ? (float) $l['win_probability'] : null) ?></span>
            <span>가중 <?= money($l['weighted_revenue']) ?></span>
          </div>
          <div class="kanban-card-foot">
            <span>체류 D+<?= (int) $l['stay_days'] ?></span>
            <span class="<?= $l['delayed'] ? 'text-danger' : ($l['warn'] ? 'text-warn' : '') ?>">
              다음연락 <?= fmtdate($l['next_contact_date']) ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
