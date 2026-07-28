<?php
/** 일반직원 대시보드. @var array $me,$kpi,$goal,$pgroups,$schedule,$projects @var bool $wl */
$wl = $wl ?? false;
$g = $goal; // 목표 진행바는 partials/goal 이 렌더
$statusLabel = ['preparing' => '착공준비', 'in_progress' => '진행중', 'paused' => '중지', 'completed' => '완료', 'warranty' => '하자보수'];
$typeLabel = ['work' => '작업', 'meeting' => '회의', 'inspection' => '검수'];
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">내 대시보드</div>
      <div class="page-sub"><?= e($me['name']) ?>님, 오늘도 안전 작업하세요.</div>
    </div>
    <div class="page-actions">
      <?php if ($wl): ?><a class="btn btn-primary" href="<?= e(url('worklogs.form')) ?>">작업일지 등록</a><?php endif; ?>
    </div>
  </div>

  <div class="section">
    <div class="section-head"><div class="st"><h2>오늘 할 일</h2></div></div>
    <div class="kpi-grid">
      <a class="kpi accent-brand" href="<?= e(url('schedule.index')) ?>"><div class="kpi-label">오늘 일정</div><div class="kpi-value"><?= number_format($kpi['today']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi" href="<?= e(url('schedule.index')) ?>"><div class="kpi-label">이번 주 일정</div><div class="kpi-value"><?= number_format($kpi['week']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi" href="<?= e(url('projects.index')) ?>"><div class="kpi-label">내 담당 프로젝트</div><div class="kpi-value"><?= number_format($kpi['projects']['value']) ?><span class="u">건</span></div></a>
      <?php if ($wl): ?>
      <a class="kpi <?= $kpi['worklog']['value'] > 0 ? 'accent-warn' : '' ?>" href="<?= e(url('worklogs.index')) ?>"><div class="kpi-label">오늘 일지 미작성</div><div class="kpi-value"><?= number_format($kpi['worklog']['value']) ?><span class="u">건</span></div></a>
      <?php endif; ?>
      <a class="kpi <?= $kpi['unread']['value'] > 0 ? 'accent-warn' : '' ?>" href="<?= e(url('notifications.index')) ?>"><div class="kpi-label">안 읽은 알림</div><div class="kpi-value"><?= number_format($kpi['unread']['value']) ?><span class="u">건</span></div></a>
    </div>
  </div>

  <div class="split">
    <div class="col">
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>이번 주 일정</h2></div>
          <a class="section-link" href="<?= e(url('schedule.index')) ?>">일정 →</a></div>
        <?php if (!$schedule): ?>
          <div class="empty"><div class="empty-title">예정된 일정이 없습니다.</div></div>
        <?php else: ?>
          <div class="attn-list">
            <?php foreach ($schedule as $s): ?>
              <div class="attn-item">
                <span class="attn-label"><?= e($s['title']) ?><?= $s['project_name'] ? ' · ' . e(Util::truncate($s['project_name'], 14)) : '' ?></span>
                <span class="attn-cnt nowrap"><?= e(Util::date($s['start_datetime'], 'n/j')) ?> <span class="badge badge-muted"><?= e($typeLabel[$s['type']] ?? $s['type']) ?></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>내 담당 프로젝트</h2></div>
          <a class="section-link" href="<?= e(url('projects.index')) ?>">전체 →</a></div>
        <?php if (!$projects): ?>
          <div class="empty"><div class="empty-title">담당 프로젝트가 없습니다.</div></div>
        <?php else: ?>
          <div class="attn-list">
            <?php foreach ($projects as $p):
              $overdue = $p['status'] !== 'completed' && !$p['actual_end_date'] && $p['end_date'] && $p['end_date'] < date('Y-m-d'); ?>
              <a class="attn-item <?= $overdue ? 'danger' : '' ?>" href="<?= e(url('projects.show', ['id' => $p['id']])) ?>">
                <span class="attn-label"><?= e($p['name']) ?></span>
                <span class="attn-cnt nowrap"><span class="badge <?= $overdue ? 'badge-danger' : 'badge-info' ?>"><?= e($p['stage_name'] ?: ($statusLabel[$p['status']] ?? $p['status'])) ?></span></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col">
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>내 이번 달 목표</h2></div></div>
        <?php View::partial('partials/goal', ['g' => $g, 'title' => '매출 달성률']); ?>
      </div>
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>작업할 공정</h2></div>
          <a class="section-link" href="<?= e(url('process.board')) ?>">공정 보드 →</a></div>
        <div class="chip-grid">
          <?php foreach ($pgroups as $p): ?>
            <a class="chip-stat <?= $p['n'] === 0 ? 'zero' : '' ?>" href="<?= e(url('process.board')) ?>">
              <span class="dot" style="background:<?= e($p['color']) ?>"></span><span class="cn"><?= number_format($p['n']) ?></span><span><?= e($p['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
