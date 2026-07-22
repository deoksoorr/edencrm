<?php
/** 사장·회계 대시보드. @var array $me,$kpi,$attn,$funnel,$finance,$process,$perf */

/** KPI 카드 partial. */
$kpiCard = function (string $href, string $label, string $valueHtml, string $accent = '', ?array $delta = null, string $note = '') {
    $cls = 'kpi' . ($accent ? " accent-$accent" : '');
    echo '<a class="' . $cls . '" href="' . e($href) . '">';
    echo '<div class="kpi-label">' . e($label) . '</div>';
    echo '<div class="kpi-row"><div class="kpi-value">' . $valueHtml . '</div>';
    if ($delta && $delta['pct'] !== null) {
        echo '<span class="delta ' . e($delta['dir']) . '">' . e(number_format(abs($delta['pct']), 1)) . '%</span>';
    }
    echo '</div>';
    if ($note !== '') { echo '<div class="kpi-note">' . $note . '</div>'; }
    echo '</a>';
};
$g = $finance['goal']; // 목표 진행바는 partials/goal 이 렌더
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">대시보드</div>
      <div class="page-sub"><?= e($me['name']) ?>님, <?= date('n월 j일') ?> 회사 현황입니다.</div>
    </div>
    <div class="page-actions">
      <a class="btn btn-outline" href="<?= e(url('reports.index')) ?>">리포트</a>
      <a class="btn btn-primary" href="<?= e(url('pipeline.index')) ?>">영업 파이프라인</a>
    </div>
  </div>

  <!-- A. 오늘의 핵심 현황 -->
  <div class="section">
    <div class="section-head"><div class="st"><h2>오늘의 핵심 현황</h2></div></div>
    <div class="kpi-grid k6">
      <?php
      $kpiCard(url('reports.index'), '이번 달 확정매출', moneyCell($kpi['revenue']['value']), 'brand', $kpi['revenue']['delta'], '전월 대비');
      $kpiCard(url('reports.index'), '이번 달 예상순이익', moneyCell($kpi['profit']['value']), $kpi['profit']['value'] < 0 ? 'danger' : 'ok', null, $kpi['profit']['value'] < 0 ? '적자 주의' : '');
      $kpiCard(url('projects.index', ['status' => 'in_progress']), '진행 중 공사', number_format($kpi['active']['value']) . '<span class="u">건</span>');
      $kpiCard(url('projects.index', ['status' => 'delayed']), '지연 공사', number_format($kpi['delayed']['value']) . '<span class="u">건</span>', $kpi['delayed']['value'] > 0 ? 'danger' : '', null, $kpi['delayed']['value'] > 0 ? '확인 필요' : '정상');
      $kpiCard(url('pipeline.index', ['tab' => 'contract']), '계약 대기', number_format($kpi['pending']['value']) . '<span class="u">건</span>', $kpi['pending']['value'] > 0 ? 'warn' : '');
      $kpiCard(url('contracts.index'), '미수금', moneyCell($kpi['recv']['value']), $kpi['recv']['value'] > 0 ? 'warn' : '');
      ?>
    </div>
  </div>

  <!-- B. 오늘의 직원 업무 현황 (오늘 일정이 있는 직원만) -->
  <div class="section">
    <div class="section-head"><div class="st"><h2>오늘의 직원 업무 현황</h2><span class="section-desc">오늘 작업 예정인 직원</span></div>
      <a class="section-link" href="<?= e(url('schedule.index')) ?>">전체 일정 →</a></div>
    <?php if (!$workstatus['today']): ?>
      <div class="card pad"><div class="empty"><div class="empty-title">오늘 예정된 직원 작업이 없습니다.</div></div></div>
    <?php else:
      $wsLabel = ['active' => ['진행중', 'badge-info'], 'planned' => ['예정', 'badge-muted'], 'done' => ['완료', 'badge-ok']]; ?>
    <div class="ws-grid">
      <?php foreach ($workstatus['today'] as $w): [$stLabel, $stCls] = $wsLabel[$w['status']] ?? ['예정', 'badge-muted']; ?>
        <a class="ws-card <?= e($w['status']) ?>" href="<?= e(url('schedule.index', ['user_id' => $w['id']])) ?>">
          <div class="ws-head">
            <span class="user-color-dot" style="background:<?= e($w['color']) ?>"></span>
            <span class="ws-name"><?= e($w['name']) ?></span>
            <span class="badge badge-muted"><?= e($w['role']) ?></span>
            <span class="ws-status badge <?= $stCls ?>"><?= $stLabel ?></span>
          </div>
          <div class="ws-sched"><?= e($w['sched']) ?><?= $w['more'] > 0 ? ' 외 ' . (int) $w['more'] . '건' : '' ?></div>
          <?php if ($w['project']): ?>
            <div class="ws-cur"><?= e(Util::truncate($w['project'], 24)) ?><?php if ($w['stage']): ?> · <span class="stage"><?= e($w['stage']) ?></span><?php endif; ?></div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- 이번 달 출근 현황(작업일수) -->
  <div class="section">
    <div class="section-head"><div class="st"><h2>이번 달 출근 현황</h2><span class="section-desc"><?= date('n') ?>월 작업일수(작업일지 기준)</span></div>
      <a class="section-link" href="<?= e(url('worklogs.index')) ?>">작업일지 →</a></div>
    <div class="card pad">
      <div class="att-list">
        <?php foreach ($workstatus['attendance'] as $a): ?>
          <div class="att-row">
            <span class="att-name"><span class="user-color-dot" style="background:<?= e($a['color']) ?>"></span><?= e($a['name']) ?> <span class="badge badge-muted"><?= e($a['role']) ?></span></span>
            <div class="att-bar"><div class="att-fill" style="width:<?= (int) $a['pct'] ?>%;background:<?= e($a['color']) ?>"></div></div>
            <span class="att-days"><b><?= (int) $a['days'] ?></b>일</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="split">
    <div class="col">
      <!-- D. 재무 현황 -->
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>재무 현황</h2><span class="section-desc">이번 달</span></div>
          <a class="section-link" href="<?= e(url('reports.index')) ?>">상세 리포트 →</a></div>
        <div class="kv-row mb-14">
          <div class="kv"><span class="kv-label">확정 매출</span><span class="kv-value mono"><?= e(moneyShort($finance['revenue'])) ?></span></div>
          <div class="kv"><span class="kv-label">예상 매출(가중)</span><span class="kv-value mono"><?= e(moneyShort($finance['pipeline'])) ?></span></div>
          <div class="kv"><span class="kv-label">실제 원가</span><span class="kv-value mono"><?= e(moneyShort($finance['actual_cost'])) ?></span></div>
          <div class="kv"><span class="kv-label">예상 순이익</span><span class="kv-value mono <?= $finance['expected_profit'] < 0 ? 'text-danger' : '' ?>"><?= e(moneyShort($finance['expected_profit'])) ?></span></div>
          <div class="kv"><span class="kv-label">실제 순이익률</span><span class="kv-value mono"><?= e(pct($finance['profit_rate'])) ?></span></div>
          <div class="kv"><span class="kv-label">미수금</span><span class="kv-value mono <?= $finance['receivable'] > 0 ? 'text-warn' : '' ?>"><?= e(moneyShort($finance['receivable'])) ?></span></div>
        </div>
        <?php View::partial('partials/goal', ['g' => $g, 'title' => '이번 달 매출 목표 달성률']); ?>
        <div class="chart-box mt-16" data-chart="trend"><canvas id="chartTrend"></canvas></div>
        <div class="section-desc tc mt-8">최근 6개월 매출(막대)·순이익(선)</div>
      </div>

      <!-- B. 영업 현황 -->
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>영업 현황</h2></div>
          <a class="section-link" href="<?= e(url('pipeline.index')) ?>">파이프라인 →</a></div>
        <div class="funnel">
          <?php foreach ($funnel['steps'] as $s): ?>
            <div class="funnel-step <?= !empty($s['won']) ? 'is-won' : '' ?>">
              <div class="fn"><?= number_format($s['n']) ?></div>
              <div class="fl"><?= e($s['label']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="row mt-14 gap-24">
          <div class="kv"><span class="kv-label">영업 전환율</span><span class="kv-value mono"><?= e(pct($funnel['conversion'])) ?></span></div>
          <div class="kv"><span class="kv-label">평균 계약 소요일</span><span class="kv-value mono"><?= $funnel['avg_days'] !== null ? number_format($funnel['avg_days']) . '일' : '-' ?></span></div>
        </div>
        <div class="chart-box sm mt-14" data-chart="stage"><canvas id="chartStage"></canvas></div>
        <div class="chart-legend" id="stageLegend"></div>
      </div>

      <!-- C. 공정 현황 -->
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>공정 현황</h2></div>
          <a class="section-link" href="<?= e(url('process.board')) ?>">공정 보드 →</a></div>
        <div class="chip-grid">
          <?php foreach ($process as $c): ?>
            <a class="chip-stat <?= ($c['n'] > 0 && !empty($c['sev'])) ? e($c['sev']) : ($c['n'] === 0 ? 'zero' : '') ?>" href="<?= e(url($c['route'], $c['params'])) ?>">
              <span class="cn"><?= number_format($c['n']) ?></span><span><?= e($c['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

    </div>

    <!-- F. 주의가 필요한 항목 (우측 레일, 스크롤 고정) -->
    <div class="col">
      <div class="card pad rail-sticky">
        <div class="section-head"><div class="st"><h2>주의가 필요한 항목</h2></div></div>
        <div class="attn-list">
          <?php foreach ($attn as $a): ?>
            <a class="attn-item <?= $a['n'] > 0 ? e($a['sev']) : 'zero' ?>" href="<?= e(url($a['route'], $a['params'])) ?>">
              <span class="attn-label"><?= e($a['label']) ?></span>
              <span class="attn-cnt"><?= number_format($a['n']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- E. 직원 성과 (전체 폭) -->
  <div class="card pad">
    <div class="section-head"><div class="st"><h2>직원 성과</h2><span class="section-desc">이번 달</span></div>
      <a class="section-link" href="<?= e(url('performance.index')) ?>">전체 성과 →</a></div>
    <?php if (!$perf): ?>
      <div class="empty"><div class="empty-title">표시할 직원 성과가 없습니다.</div></div>
    <?php else: ?>
    <div class="table-wrap border-0">
      <table class="data">
        <thead><tr><th>직원</th><th class="num">담당</th><th class="num">이번달 매출</th><th class="num">순이익 기여</th><th class="num">목표 달성</th><th class="num">일정 준수</th></tr></thead>
        <tbody>
          <?php foreach ($perf as $p): ?>
            <tr>
              <td><?= e($p['name']) ?> <span class="badge badge-muted"><?= e($p['role']) ?></span></td>
              <td class="num mono"><?= number_format($p['projects']) ?></td>
              <td class="num mono" title="<?= e(number_format($p['revenue']) . '원') ?>"><?= e(moneyShort($p['revenue'])) ?></td>
              <td class="num mono <?= $p['contrib'] < 0 ? 'text-danger' : '' ?>" title="<?= e(number_format($p['contrib']) . '원') ?>"><?= e(moneyShort($p['contrib'])) ?></td>
              <td class="num mono"><?= e(pct($p['achieve'])) ?></td>
              <td class="num mono"><?= e(pct($p['ontime'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
