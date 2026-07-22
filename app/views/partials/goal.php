<?php
/** 매출 목표 진행바(공통). 목표 미설정 시 진행바 대신 안내를 표시. @var array $g @var string $title */
$set = !empty($g['set']);
$rate = $g['rate'];
$gclass = $rate === null ? 'low' : ($rate >= 90 ? 'ok' : ($rate >= 50 ? 'mid' : 'low'));
?>
<div class="goal">
  <div class="goal-top">
    <span class="section-desc"><?= e($title) ?></span>
    <?php if ($set): ?>
      <span class="goal-rate <?= $gclass === 'low' ? 'low' : '' ?>"><?= $rate !== null ? e(number_format($rate, 1)) : '-' ?><span class="u">%</span></span>
    <?php else: ?>
      <span class="badge badge-warn">목표 미설정</span>
    <?php endif; ?>
  </div>
  <div class="goal-track"><div class="goal-fill <?= $set ? $gclass : '' ?>" style="width:<?= $set && $rate !== null ? min(100, (float) $rate) : 0 ?>%"></div></div>
  <?php if ($set): ?>
    <div class="goal-meta">
      <div class="kv"><span class="kv-label">목표</span><span class="kv-value mono"><?= e(moneyShort($g['target'])) ?></span></div>
      <div class="kv"><span class="kv-label">현재</span><span class="kv-value mono"><?= e(moneyShort($g['actual'])) ?></span></div>
      <div class="kv"><span class="kv-label">남은 금액</span><span class="kv-value mono"><?= e(moneyShort($g['remaining'])) ?></span></div>
    </div>
  <?php else: ?>
    <div class="muted goal-unset-hint">
      <?php if (can('settings.manage')): ?>
        매출 목표가 아직 설정되지 않았습니다. <a href="<?= e(url('targets.index')) ?>">목표 관리에서 설정 →</a>
      <?php else: ?>
        매출 목표가 아직 설정되지 않았습니다. 관리자에게 목표 설정을 요청하세요.
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
