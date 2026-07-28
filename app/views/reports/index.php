<?php /** 화면 뼈대만 렌더 — reports.js 가 reports.data 를 호출해 차트/표를 채운다. */ ?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title">리포트</h1>
    <div class="page-sub" id="periodLabel">기간을 선택하세요.</div>
  </div>

  <?php if (Settings::enabled('feature_attendance')): // R4 T4 — 직원 출근 분석 하위 탭(기능 OFF 시 탭 자체 미노출) ?>
  <div class="tabs">
    <a class="tab active" href="<?= e(url('reports.index')) ?>">경영 리포트</a>
    <a class="tab" href="<?= e(url('reports.attendance')) ?>">직원 출근</a>
  </div>
  <?php endif; ?>

  <form id="periodForm" class="toolbar" onsubmit="return false;">
    <select id="fPeriod" class="select">
      <option value="today">오늘</option>
      <option value="week">이번 주</option>
      <option value="month" selected>이번 달</option>
      <option value="quarter">이번 분기</option>
      <option value="year">올해</option>
      <option value="custom">직접 선택</option>
    </select>
    <input type="date" id="fFrom" class="input hidden">
    <span id="fSep" class="hidden muted">~</span>
    <input type="date" id="fTo" class="input hidden">
    <button type="button" id="btnApply" class="btn btn-primary">검색</button>
    <div class="toolbar-spacer"></div>
    <?php if (can('report.export')): ?>
    <select id="exportType" class="select">
      <option value="monthly_trend">월별 확정 매출(공급가액)·확정 순이익</option>
      <option value="by_source">유입경로별 고객</option>
      <option value="by_stage">영업단계별 건수</option>
      <option value="sales_conversion">영업직원별 계약률</option>
      <option value="quote_conversion">견적→계약 전환율</option>
      <option value="project_pl">프로젝트별 손익(공급가)</option>
      <option value="by_work_type">공사유형별 매출(공급가액)</option>
      <option value="staff_performance">직원별 성과</option>
      <option value="delayed_projects">지연 프로젝트</option>
      <option value="receivables">미수금 현황</option>
      <option value="cost_overrun">원가초과 프로젝트</option>
      <option value="target_achievement">목표대비 달성률</option>
    </select>
    <button type="button" id="btnExport" class="btn btn-outline">CSV 다운로드</button>
    <?php endif; ?>
  </form>

  <div class="kpi-grid mb-14">
    <div class="kpi">
      <div class="kpi-label">신규 고객</div>
      <div class="kpi-value" id="stNewCustomers">-<span class="u">명</span></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">견적→계약 전환율</div>
      <div class="kpi-value" id="stQuoteRate">-</div>
    </div>
    <div class="kpi accent-warn" title="Σ 계약별 max(0, 계약 총액 − 순입금) + Σ 예외 프로젝트 max(0, 예정 금액 − 직접 입금) · 현재 스냅샷(VAT 포함)">
      <div class="kpi-label">미수금</div>
      <div class="kpi-value" id="stReceivable">-</div>
    </div>
    <div class="kpi" title="확정 매출 = 순입금의 공급가액(부가세 제외) · 입금 시점 인식 · 예외 프로젝트 직접 입금 포함(R12)">
      <div class="kpi-label">확정 매출(공급가액) 목표달성률</div>
      <div class="kpi-value" id="stRevenueRate">-</div>
    </div>
    <div class="kpi" title="확정 순이익 = 확정 매출(공급가액·VAT 제외) − 확정 지출(발생일 기준)">
      <div class="kpi-label">확정 순이익 목표달성률</div>
      <div class="kpi-value" id="stProfitRate">-</div>
    </div>
  </div>

  <div class="grid-2 mb-14">
    <div class="card pad">
      <div class="section-head"><div class="st"><h2>월별 확정 매출(공급가액)·확정 순이익 추이</h2><span class="section-desc" title="확정 매출=순입금의 공급가액(입금월 귀속, 예외 포함) · 확정 순이익=매출 − 확정 지출(발생월) · VAT 제외(R12)">최근 6개월 · 입금월 기준</span></div></div>
      <div class="chart-box"><canvas id="chartMonthly"></canvas></div>
    </div>
    <div class="card pad">
      <div class="section-head"><div class="st"><h2>유입경로별 고객</h2></div></div>
      <div class="chart-box"><canvas id="chartSource"></canvas></div>
    </div>
  </div>
  <div class="grid-2 mb-14">
    <div class="card pad">
      <div class="section-head"><div class="st"><h2>영업단계별 건수</h2><span class="section-desc">현재</span></div></div>
      <div class="chart-box"><canvas id="chartStage"></canvas></div>
    </div>
    <div class="card pad">
      <div class="section-head"><div class="st"><h2>공사유형별 매출(공급가액)</h2><span class="section-desc" title="기간 내 계약일 기준 프로젝트 공급가액(VAT 제외) 합">공급가액(VAT 제외)</span></div></div>
      <div class="chart-box"><canvas id="chartWorkType"></canvas></div>
    </div>
  </div>

  <div class="card mb-14">
    <div class="card-head"><div class="card-title">영업직원별 계약률</div></div>
    <div class="table-wrap border-0">
      <table class="data">
        <thead><tr><th>담당자</th><th class="num">전체 리드</th><th class="num">계약 건수</th><th class="num">계약률</th></tr></thead>
        <tbody id="tbSalesConversion"><tr><td colspan="4" class="loading-row">불러오는 중...</td></tr></tbody>
      </table>
    </div>
  </div>

  <div class="card mb-14">
    <div class="card-head"><div class="card-title">프로젝트별 손익(공급가)</div><div class="muted fs-12">기간 내 계약</div></div>
    <div class="table-wrap border-0">
      <table class="data">
        <thead><tr><th>프로젝트</th><th>상태</th><th class="num" title="VAT 제외 · 확정 매출 집계의 기준 금액">공급가액(VAT 제외)</th><th class="num" title="실제원가 합">원가 총액</th><th class="num" title="공급가액 − 원가 총액">순이익</th><th class="num">순이익률</th></tr></thead>
        <tbody id="tbProjectPl"><tr><td colspan="6" class="loading-row">불러오는 중...</td></tr></tbody>
      </table>
    </div>
  </div>

  <div class="card mb-14">
    <div class="card-head"><div class="card-title">직원별 성과</div><div class="muted fs-12">기간 내 계약</div></div>
    <div class="table-wrap border-0">
      <table class="data">
        <thead><tr><th>직원</th><th class="num">프로젝트수</th><th class="num" title="기간 내 계약일 기준 담당 프로젝트 공급가액(VAT 제외) 합">공급가액(VAT 제외)</th><th class="num" title="실제원가 합">원가 총액</th><th class="num">순이익</th></tr></thead>
        <tbody id="tbStaffPerf"><tr><td colspan="5" class="loading-row">불러오는 중...</td></tr></tbody>
      </table>
    </div>
  </div>

  <div class="grid-2 mb-14">
    <div class="card">
      <div class="card-head"><div class="card-title">지연 프로젝트</div><div class="muted fs-12">현재</div></div>
      <div class="table-wrap border-0">
        <table class="data">
          <thead><tr><th>프로젝트</th><th>준공예정일</th><th class="num">지연일수</th><th>현장책임자</th></tr></thead>
          <tbody id="tbDelayed"><tr><td colspan="4" class="loading-row">불러오는 중...</td></tr></tbody>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><div class="card-title">원가초과 프로젝트</div><div class="muted fs-12">현재</div></div>
      <div class="table-wrap border-0">
        <table class="data">
          <thead><tr><th>프로젝트</th><th class="num">예상원가</th><th class="num">실제원가</th><th class="num">초과금액</th><th class="num">초과율</th></tr></thead>
          <tbody id="tbCostOverrun"><tr><td colspan="5" class="loading-row">불러오는 중...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">미수금 현황</div><div class="muted fs-12">현재</div></div>
    <div class="table-wrap border-0">
      <table class="data">
        <thead><tr><th>계약번호</th><th>고객명</th><th class="num" title="VAT 포함">계약 총액(VAT 포함)</th><th class="num" title="입금 완료 합 · VAT 포함">입금 총액(VAT 포함)</th><th class="num" title="max(0, 계약 총액 − 입금 총액)">미수금</th></tr></thead>
        <tbody id="tbReceivables"><tr><td colspan="5" class="loading-row">불러오는 중...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>
