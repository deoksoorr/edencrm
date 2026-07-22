<?php
/** @var array $columns @var string $q @var ?int $salesUserId @var array $salesUsers @var bool $fullAccess @var bool $canManage */
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">영업 파이프라인</div>
      <div class="page-sub"><?= $fullAccess ? '전체 영업기회' : '내 담당 영업기회' ?></div>
    </div>
    <div class="page-actions">
      <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" id="btnNewLead">신규 영업기회</button>
      <?php endif; ?>
    </div>
  </div>

  <form class="toolbar" id="pipelineFilter">
    <input type="text" name="q" class="input search" placeholder="고객명·공사종류·현장주소 검색" value="<?= e($q) ?>">
    <?php if ($fullAccess): ?>
      <select name="sales_user_id" class="select">
        <option value="">전체 담당영업</option>
        <?php foreach ($salesUsers as $su): ?>
          <option value="<?= (int) $su['id'] ?>" <?= $salesUserId === (int) $su['id'] ? 'selected' : '' ?>><?= e($su['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-outline">검색</button>
    <span class="toolbar-spacer"></span>
    <span class="muted" id="boardUpdatedHint"></span>
  </form>

  <div class="kanban" id="kanbanBoard">
    <?php View::partial('pipeline/board', ['columns' => $columns, 'canManage' => $canManage]); ?>
  </div>
</div>
<?php
$inlineScript = 'window.PIPELINE_CONFIG = ' . json_encode([
    'canManage' => $canManage,
    'fullAccess' => $fullAccess,
], JSON_UNESCAPED_UNICODE) . ';';
?>
