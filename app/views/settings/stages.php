<?php
/** @var array $pipelineStages @var array $processStages */
?>
<style>
  .stage-row{display:flex;align-items:center;gap:10px;padding:9px 10px;border:1px solid var(--line);border-radius:6px;margin-bottom:7px;flex-wrap:wrap;background:#fff}
  .stage-row form{display:flex;align-items:center;gap:8px;margin:0}
  .stage-order{display:flex;flex-direction:column;gap:2px}
  .stage-order button{padding:1px 7px;line-height:1.4;font-size:11px}
  .stage-row .input{width:auto}
  .stage-name-input{width:150px !important}
  .stage-color-input{width:90px !important}
</style>
<div class="page">
  <div class="page-head">
    <h1 class="page-title">영업/공정 단계 관리</h1>
    <div class="page-actions">
      <a href="<?= e(url('settings.index')) ?>" class="btn btn-outline">시스템 설정으로</a>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">영업 단계 (pipeline_stages)</div></div>
    <div class="card-body">
      <?php foreach ($pipelineStages as $i => $s):
        $prev = $pipelineStages[$i - 1] ?? null;
        $next = $pipelineStages[$i + 1] ?? null;
      ?>
        <div class="stage-row">
          <div class="stage-order">
            <form method="post" action="<?= e(url('settings.stage.save')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="kind" value="pipeline">
              <input type="hidden" name="sort_only" value="1">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="hidden" name="sort_order" value="<?= $prev ? (int) $prev['sort_order'] : (int) $s['sort_order'] ?>">
              <?php if ($prev): ?>
                <input type="hidden" name="swap_id" value="<?= (int) $prev['id'] ?>">
                <input type="hidden" name="swap_sort_order" value="<?= (int) $s['sort_order'] ?>">
              <?php endif; ?>
              <button type="submit" class="btn btn-ghost btn-sm" <?= $prev ? '' : 'disabled' ?>>▲</button>
            </form>
            <form method="post" action="<?= e(url('settings.stage.save')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="kind" value="pipeline">
              <input type="hidden" name="sort_only" value="1">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="hidden" name="sort_order" value="<?= $next ? (int) $next['sort_order'] : (int) $s['sort_order'] ?>">
              <?php if ($next): ?>
                <input type="hidden" name="swap_id" value="<?= (int) $next['id'] ?>">
                <input type="hidden" name="swap_sort_order" value="<?= (int) $s['sort_order'] ?>">
              <?php endif; ?>
              <button type="submit" class="btn btn-ghost btn-sm" <?= $next ? '' : 'disabled' ?>>▼</button>
            </form>
          </div>
          <span class="muted nowrap">#<?= (int) $s['sort_order'] ?></span>

          <form method="post" action="<?= e(url('settings.stage.save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="pipeline">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <input type="hidden" name="sort_order" value="<?= (int) $s['sort_order'] ?>">
            <input type="text" name="name" class="input stage-name-input" value="<?= e($s['name']) ?>">
            <label class="flex items-center gap-8" style="font-size:12px"><input type="checkbox" name="is_won" value="1" <?= $s['is_won'] ? 'checked' : '' ?>> 성공[WON]</label>
            <label class="flex items-center gap-8" style="font-size:12px"><input type="checkbox" name="is_lost" value="1" <?= $s['is_lost'] ? 'checked' : '' ?>> 실주[LOST]</label>
            <input type="text" name="color" class="input stage-color-input" placeholder="#색상" value="<?= e($s['color'] ?? '') ?>">
            <button type="submit" class="btn btn-outline btn-sm">저장</button>
          </form>

          <form method="post" action="<?= e(url('settings.stage.delete')) ?>" onsubmit="return confirm('이 단계를 삭제하시겠습니까? 참조하는 영업기회가 있으면 삭제할 수 없습니다.');">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="pipeline">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm">삭제</button>
          </form>
        </div>
      <?php endforeach; ?>

      <div class="stage-row" style="background:var(--bg)">
        <form method="post" action="<?= e(url('settings.stage.save')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="pipeline">
          <input type="text" name="name" class="input stage-name-input" placeholder="새 영업 단계 이름" required>
          <input type="number" name="sort_order" class="input" style="width:70px" value="<?= count($pipelineStages) + 1 ?>" title="순서">
          <label class="flex items-center gap-8" style="font-size:12px"><input type="checkbox" name="is_won" value="1"> 성공[WON]</label>
          <label class="flex items-center gap-8" style="font-size:12px"><input type="checkbox" name="is_lost" value="1"> 실주[LOST]</label>
          <input type="text" name="color" class="input stage-color-input" placeholder="#색상">
          <button type="submit" class="btn btn-primary btn-sm">+ 추가</button>
        </form>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">공정 단계 (process_stages)</div></div>
    <div class="card-body">
      <?php foreach ($processStages as $i => $s):
        $prev = $processStages[$i - 1] ?? null;
        $next = $processStages[$i + 1] ?? null;
      ?>
        <div class="stage-row">
          <div class="stage-order">
            <form method="post" action="<?= e(url('settings.stage.save')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="kind" value="process">
              <input type="hidden" name="sort_only" value="1">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="hidden" name="sort_order" value="<?= $prev ? (int) $prev['sort_order'] : (int) $s['sort_order'] ?>">
              <?php if ($prev): ?>
                <input type="hidden" name="swap_id" value="<?= (int) $prev['id'] ?>">
                <input type="hidden" name="swap_sort_order" value="<?= (int) $s['sort_order'] ?>">
              <?php endif; ?>
              <button type="submit" class="btn btn-ghost btn-sm" <?= $prev ? '' : 'disabled' ?>>▲</button>
            </form>
            <form method="post" action="<?= e(url('settings.stage.save')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="kind" value="process">
              <input type="hidden" name="sort_only" value="1">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="hidden" name="sort_order" value="<?= $next ? (int) $next['sort_order'] : (int) $s['sort_order'] ?>">
              <?php if ($next): ?>
                <input type="hidden" name="swap_id" value="<?= (int) $next['id'] ?>">
                <input type="hidden" name="swap_sort_order" value="<?= (int) $s['sort_order'] ?>">
              <?php endif; ?>
              <button type="submit" class="btn btn-ghost btn-sm" <?= $next ? '' : 'disabled' ?>>▼</button>
            </form>
          </div>
          <span class="muted nowrap">#<?= (int) $s['sort_order'] ?></span>

          <form method="post" action="<?= e(url('settings.stage.save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="process">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <input type="hidden" name="sort_order" value="<?= (int) $s['sort_order'] ?>">
            <input type="text" name="name" class="input stage-name-input" value="<?= e($s['name']) ?>">
            <label class="flex items-center gap-8" style="font-size:12px"><input type="checkbox" name="requires_confirm" value="1" <?= $s['requires_confirm'] ? 'checked' : '' ?>> 이동 시 확인 필요</label>
            <input type="text" name="color" class="input stage-color-input" placeholder="#색상" value="<?= e($s['color'] ?? '') ?>">
            <button type="submit" class="btn btn-outline btn-sm">저장</button>
          </form>

          <form method="post" action="<?= e(url('settings.stage.delete')) ?>" onsubmit="return confirm('이 단계를 삭제하시겠습니까? 참조하는 프로젝트가 있으면 삭제할 수 없습니다.');">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="process">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm">삭제</button>
          </form>
        </div>
      <?php endforeach; ?>

      <div class="stage-row" style="background:var(--bg)">
        <form method="post" action="<?= e(url('settings.stage.save')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="process">
          <input type="text" name="name" class="input stage-name-input" placeholder="새 공정 단계 이름" required>
          <input type="number" name="sort_order" class="input" style="width:70px" value="<?= count($processStages) + 1 ?>" title="순서">
          <label class="flex items-center gap-8" style="font-size:12px"><input type="checkbox" name="requires_confirm" value="1"> 이동 시 확인 필요</label>
          <input type="text" name="color" class="input stage-color-input" placeholder="#색상">
          <button type="submit" class="btn btn-primary btn-sm">+ 추가</button>
        </form>
      </div>
    </div>
  </div>
</div>
