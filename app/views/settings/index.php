<?php
/** @var array $groups  ($group => [rows...]) */
$groupLabels = ['general' => '일반', 'security' => '보안', 'upload' => '업로드', 'finance' => '재무'];
?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title">시스템 설정</h1>
    <div class="page-actions">
      <a href="<?= e(url('settings.stages')) ?>" class="btn btn-outline">영업/공정 단계 관리</a>
    </div>
  </div>

  <form method="post" action="<?= e(url('settings.save')) ?>">
    <?= csrf_field() ?>
    <?php foreach ($groups as $groupKey => $rows): ?>
      <div class="card">
        <div class="card-head"><div class="card-title"><?= e($groupLabels[$groupKey] ?? $groupKey) ?></div></div>
        <div class="card-body form-grid">
          <?php foreach ($rows as $r): ?>
            <div class="field">
              <label class="field-label"><?= e($r['label'] ?: $r['setting_key']) ?></label>
              <input type="text" name="<?= e($r['setting_key']) ?>" class="input" value="<?= e($r['value'] ?? '') ?>">
              <div class="field-hint"><?= e($r['setting_key']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="btn-group">
      <button type="submit" class="btn btn-primary">저장</button>
    </div>
  </form>
</div>
