<?php
/** 현장관리자 대시보드. @var array $me,$kpi,$attn,$process,$pgroups,$schedule */
$pick = ['delayed', 'unassigned', 'worklog', 'inspect'];
$typeLabel = ['work' => '작업', 'meeting' => '회의', 'inspection' => '검수'];
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">현장 대시보드</div>
      <div class="page-sub"><?= e($me['name']) ?>님, 현장 공정 현황입니다.</div>
    </div>
    <div class="page-actions">
      <a class="btn btn-outline" href="<?= e(url('schedule.index')) ?>">일정</a>
      <a class="btn btn-primary" href="<?= e(url('process.board')) ?>">공정 보드</a>
    </div>
  </div>

  <div class="section">
    <div class="section-head"><div class="st"><h2>현장 핵심</h2></div></div>
    <div class="kpi-grid k6">
      <a class="kpi accent-brand" href="<?= e(url('projects.index', ['status' => 'in_progress'])) ?>"><div class="kpi-label">진행 중 공사</div><div class="kpi-value"><?= number_format($kpi['active']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi" href="<?= e(url('projects.index', ['status' => 'preparing'])) ?>"><div class="kpi-label">착공 예정</div><div class="kpi-value"><?= number_format($kpi['preparing']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi <?= $kpi['delayed']['value'] > 0 ? 'accent-danger' : '' ?>" href="<?= e(url('projects.index', ['status' => 'delayed'])) ?>"><div class="kpi-label">지연 공사</div><div class="kpi-value"><?= number_format($kpi['delayed']['value']) ?><span class="u">건</span></div><?php if ($kpi['delayed']['value'] > 0): ?><div class="kpi-note">확인 필요</div><?php endif; ?></a>
      <a class="kpi <?= $kpi['inspect']['value'] > 0 ? 'accent-warn' : '' ?>" href="<?= e(url('process.board')) ?>"><div class="kpi-label">검수 대기</div><div class="kpi-value"><?= number_format($kpi['inspect']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi <?= $kpi['unassigned']['value'] > 0 ? 'accent-warn' : '' ?>" href="<?= e(url('projects.index', ['assign' => 'none'])) ?>"><div class="kpi-label">직원 미배정</div><div class="kpi-value"><?= number_format($kpi['unassigned']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi <?= $kpi['worklog']['value'] > 0 ? 'accent-warn' : '' ?>" href="<?= e(url('worklogs.index')) ?>"><div class="kpi-label">오늘 일지 미작성</div><div class="kpi-value"><?= number_format($kpi['worklog']['value']) ?><span class="u">건</span></div></a>
    </div>
  </div>

  <div class="split">
    <div class="col">
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>공정 상태</h2></div>
          <a class="section-link" href="<?= e(url('process.board')) ?>">공정 보드 →</a></div>
        <div class="chip-grid">
          <?php foreach ($process as $c): ?>
            <a class="chip-stat <?= ($c['n'] > 0 && !empty($c['sev'])) ? e($c['sev']) : ($c['n'] === 0 ? 'zero' : '') ?>" href="<?= e(url($c['route'], $c['params'])) ?>">
              <span class="cn"><?= number_format($c['n']) ?></span><span><?= e($c['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="section-head mt-16"><div class="st"><h2>공정 그룹별 진행</h2></div></div>
        <div class="chip-grid">
          <?php foreach ($pgroups as $p): ?>
            <a class="chip-stat <?= $p['n'] === 0 ? 'zero' : '' ?>" href="<?= e(url('process.board')) ?>">
              <span class="dot" style="background:<?= e($p['color']) ?>"></span><span class="cn"><?= number_format($p['n']) ?></span><span><?= e($p['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col">
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>주의가 필요한 항목</h2></div></div>
        <div class="attn-list">
          <?php foreach ($pick as $k): $a = $attn[$k]; ?>
            <a class="attn-item <?= $a['n'] > 0 ? e($a['sev']) : 'zero' ?>" href="<?= e(url($a['route'], $a['params'])) ?>">
              <span class="attn-label"><?= e($a['label']) ?></span><span class="attn-cnt"><?= number_format($a['n']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>이번 주 일정</h2></div>
          <a class="section-link" href="<?= e(url('schedule.index')) ?>">일정 →</a></div>
        <?php if (!$schedule): ?>
          <div class="empty"><div class="empty-title">예정된 일정이 없습니다.</div></div>
        <?php else: ?>
          <div class="attn-list">
            <?php foreach ($schedule as $s): ?>
              <div class="attn-item">
                <span class="attn-label"><?= e($s['title']) ?><?= $s['project_name'] ? ' · ' . e(Util::truncate($s['project_name'], 12)) : '' ?></span>
                <span class="attn-cnt nowrap"><?= e(Util::date($s['start_datetime'], 'n/j')) ?> <span class="badge badge-muted"><?= e($typeLabel[$s['type']] ?? $s['type']) ?></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
