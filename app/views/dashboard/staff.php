<?php /** @var array $stats @var array $me — 스펙 5-2: 오늘/이번주 일정, 담당 프로젝트, 공정별 건수, 지연 프로젝트, 목표매출/달성률(게이지), 예상·실제 수익 */ ?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title">내 대시보드</h1>
    <div class="page-sub"><?= e($me['name']) ?>님, 오늘도 안전 작업하세요.</div>
  </div>

  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-label">내 담당 프로젝트</div>
      <div class="stat-value"><?= number_format($stats['my_projects']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card <?= $stats['delayed_projects'] > 0 ? 'stat-danger' : '' ?>">
      <div class="stat-label">지연 프로젝트</div>
      <div class="stat-value"><?= number_format($stats['delayed_projects']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">오늘 일정</div>
      <div class="stat-value"><?= number_format($stats['today_schedules']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">이번 주 일정</div>
      <div class="stat-value"><?= number_format($stats['week_schedules']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">이번 달 목표매출</div>
      <div class="stat-value stat-money"><?= money($stats['target_revenue']) ?><span class="stat-unit">원</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">이번 달 달성매출</div>
      <div class="stat-value stat-money"><?= money($stats['achieved_revenue']) ?><span class="stat-unit">원</span></div>
      <div class="muted" style="font-size:11.5px;margin-top:4px">달성률 <?= pct($stats['achieve_rate']) ?></div>
    </div>
    <div class="stat-card <?= $stats['expected_profit'] < 0 ? 'stat-danger' : '' ?>">
      <div class="stat-label">담당 프로젝트 예상수익</div>
      <div class="stat-value stat-money"><?= money($stats['expected_profit']) ?><span class="stat-unit">원</span></div>
    </div>
    <div class="stat-card <?= $stats['actual_profit'] < 0 ? 'stat-danger' : '' ?>">
      <div class="stat-label">담당 프로젝트 실제수익</div>
      <div class="stat-value stat-money"><?= money($stats['actual_profit']) ?><span class="stat-unit">원</span></div>
    </div>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card-head"><div class="card-title">담당 프로젝트 공정별 건수(진행 중)</div></div>
      <div class="card-body"><canvas id="chartProcessBreakdown" height="220"></canvas></div>
    </div>
    <div class="card">
      <div class="card-head"><div class="card-title">이번 달 매출 목표 달성률</div></div>
      <div class="card-body">
        <canvas id="chartGoalGauge" height="180"></canvas>
        <div class="gauge-value" id="goalGaugeLabel">-</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <div class="btn-group">
        <a href="<?= e(url('projects.index')) ?>" class="btn btn-primary">내 프로젝트 보기</a>
        <a href="<?= e(url('schedule.index')) ?>" class="btn btn-outline">내 일정</a>
        <a href="<?= e(url('worklogs.form')) ?>" class="btn btn-outline">작업일지 작성</a>
      </div>
    </div>
  </div>
</div>
