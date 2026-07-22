<?php /** @var array $stats @var array $me — 스펙 5-1 숫자카드 + 차트(월별매출 line/영업단계 doughnut/직원별매출 bar/목표달성 gauge) */ ?>
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
      <div class="stat-label">신규문의</div>
      <div class="stat-value"><?= number_format($stats['new_inquiry']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">상담중</div>
      <div class="stat-value"><?= number_format($stats['consulting']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">견적진행</div>
      <div class="stat-value"><?= number_format($stats['quoting']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">계약대기</div>
      <div class="stat-value"><?= number_format($stats['contract_pending']) ?><span class="stat-unit">건</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">계약완료</div>
      <div class="stat-value"><?= number_format($stats['contract_won']) ?><span class="stat-unit">건</span></div>
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
      <div class="stat-label">이번 달 확정매출</div>
      <div class="stat-value stat-money"><?= money($stats['revenue_month']) ?><span class="stat-unit">원</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">파이프라인 예정매출(가중)</div>
      <div class="stat-value stat-money"><?= money($stats['expected_revenue_pipeline']) ?><span class="stat-unit">원</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">이번 달 예상원가</div>
      <div class="stat-value stat-money"><?= money($stats['estimated_cost_month']) ?><span class="stat-unit">원</span></div>
    </div>
    <div class="stat-card <?= $stats['expected_profit_month'] < 0 ? 'stat-danger' : '' ?>">
      <div class="stat-label">이번 달 예상순이익</div>
      <div class="stat-value stat-money"><?= money($stats['expected_profit_month']) ?><span class="stat-unit">원</span></div>
    </div>
    <div class="stat-card <?= $stats['actual_profit_month'] < 0 ? 'stat-danger' : '' ?>">
      <div class="stat-label">이번 달 실제순이익</div>
      <div class="stat-value stat-money"><?= money($stats['actual_profit_month']) ?><span class="stat-unit">원</span></div>
      <div class="muted" style="font-size:11.5px;margin-top:4px">순이익률 <?= pct($stats['profit_rate_month']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">미수금</div>
      <div class="stat-value stat-money"><?= money($stats['receivable']) ?><span class="stat-unit">원</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">영업전환율</div>
      <div class="stat-value"><?= pct($stats['conversion_rate']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">평균 계약소요일</div>
      <div class="stat-value"><?= $stats['avg_contract_days'] !== null ? number_format($stats['avg_contract_days']) : '-' ?><span class="stat-unit">일</span></div>
    </div>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card-head"><div class="card-title">월별 매출·순이익 추이(최근 6개월)</div></div>
      <div class="card-body"><canvas id="chartMonthlyTrend" height="220"></canvas></div>
    </div>
    <div class="card">
      <div class="card-head"><div class="card-title">영업단계 분포</div></div>
      <div class="card-body"><canvas id="chartStageDist" height="220"></canvas></div>
    </div>
  </div>
  <div class="grid-2">
    <div class="card">
      <div class="card-head"><div class="card-title">직원별 매출(이번 달, TOP 10)</div></div>
      <div class="card-body"><canvas id="chartStaffRevenue" height="220"></canvas></div>
    </div>
    <div class="card">
      <div class="card-head"><div class="card-title">이번 달 매출 목표 달성률</div></div>
      <div class="card-body">
        <canvas id="chartGoalGauge" height="180"></canvas>
        <div class="gauge-value" id="goalGaugeLabel">-</div>
      </div>
    </div>
  </div>
</div>
