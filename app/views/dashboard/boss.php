<?php /** @var array $stats @var array $me — T3 골격, T8 에서 차트로 확장 */ ?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title">대시보드</h1>
    <div class="page-sub"><?= e($me['name']) ?>님, 회사 현황 요약입니다.</div>
  </div>

  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-label">전체 고객</div>
      <div class="stat-value"><?= number_format($stats['customers']) ?><span class="stat-unit">명</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">영업 기회</div>
      <div class="stat-value"><?= number_format($stats['new_leads']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">진행 중 공사</div>
      <div class="stat-value"><?= number_format($stats['projects_active']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card <?= $stats['projects_delayed'] > 0 ? 'stat-danger' : '' ?>">
      <div class="stat-label">지연 공사</div>
      <div class="stat-value"><?= number_format($stats['projects_delayed']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">이번 달 계약 매출</div>
      <div class="stat-value stat-money"><?= money($stats['revenue_month']) ?><span class="stat-unit">원</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">미수금</div>
      <div class="stat-value stat-money"><?= money($stats['receivable']) ?><span class="stat-unit">원</span></div>
    </div>
  </div>

  <div class="card">
    <div class="card-body muted">상세 차트·직원별 성과·최근 활동은 리포트/성과 메뉴에서 확인할 수 있습니다.</div>
  </div>
</div>
