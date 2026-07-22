<?php /** @var array $stats @var array $me — T3 골격, T8 에서 확장 */ ?>
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
    <div class="stat-card">
      <div class="stat-label">오늘 일정</div>
      <div class="stat-value"><?= number_format($stats['today_schedules']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">이번 주 일정</div>
      <div class="stat-value"><?= number_format($stats['week_schedules']) ?><span class="stat-unit">건</span></div>
    </div>
  </div>
  <div class="card">
    <div class="card-body">
      <a href="<?= e(url('projects.index')) ?>" class="btn btn-primary">내 프로젝트 보기</a>
      <a href="<?= e(url('schedule.index')) ?>" class="btn btn-outline">내 일정</a>
      <a href="<?= e(url('worklogs.form')) ?>" class="btn btn-outline">작업일지 작성</a>
    </div>
  </div>
</div>
