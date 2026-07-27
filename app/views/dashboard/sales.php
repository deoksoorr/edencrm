<?php
/** 영업관리자 대시보드. '주의' 레일 제거(R3) — 연락·계약대기 항목은 파이프라인 카드가 흡수. @var array $me,$kpi,$attn,$funnel,$goal */
$g = $goal; // 목표 진행바는 partials/goal 이 렌더
$pick = ['contact_overdue', 'contact_none', 'contract_stale'];
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">영업 대시보드</div>
      <div class="page-sub"><?= e($me['name']) ?>님, 내 영업 현황입니다.</div>
    </div>
    <div class="page-actions">
      <a class="btn btn-primary" href="<?= e(url('pipeline.index')) ?>">영업 파이프라인</a>
    </div>
  </div>

  <div class="section">
    <div class="section-head"><div class="st"><h2>내 영업 핵심</h2></div></div>
    <div class="kpi-grid">
      <a class="kpi accent-brand" href="<?= e(url('pipeline.index')) ?>" title="내 미확정 리드의 예상금액 × 성공확률 합"><div class="kpi-label">예상 매출(가중)</div><div class="kpi-value"><?= moneyCell($kpi['pipeline']['value']) ?></div></a>
      <a class="kpi accent-ok" href="<?= e(url('contracts.index')) ?>" title="이번 달 계약일 기준 담당 프로젝트 공급가액(VAT 제외) 합 · 취소/파기 제외"><div class="kpi-label">이번 달 수주액(공급가액)</div><div class="kpi-value"><?= moneyCell($kpi['revenue']['value']) ?></div></a>
      <a class="kpi" href="<?= e(url('pipeline.index')) ?>"><div class="kpi-label">계약 전환율</div><div class="kpi-value"><?= e(pct($kpi['conv']['value'])) ?></div></a>
      <a class="kpi <?= $kpi['closing']['value'] > 0 ? 'accent-warn' : '' ?>" href="<?= e(url('pipeline.index', ['quick' => 'closing'])) ?>"><div class="kpi-label">계약 임박</div><div class="kpi-value"><?= number_format($kpi['closing']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi <?= $kpi['contact']['value'] > 0 ? 'accent-danger' : '' ?>" href="<?= e(url('pipeline.index', ['quick' => 'today'])) ?>"><div class="kpi-label">오늘 연락 필요</div><div class="kpi-value"><?= number_format($kpi['contact']['value']) ?><span class="u">건</span></div></a>
    </div>
  </div>

  <div class="card pad">
    <div class="section-head"><div class="st"><h2>이번 달 목표</h2></div></div>
    <?php View::partial('partials/goal', ['g' => $g, 'title' => '이번 달 수주(공급가액) 목표 달성률']); ?>
  </div>

  <div class="card pad">
    <div class="section-head"><div class="st"><h2>내 파이프라인</h2><span class="section-desc">현재 · 연락·계약 대기 관리 포함</span></div>
      <a class="section-link" href="<?= e(url('pipeline.index')) ?>">파이프라인 →</a></div>
    <div class="funnel">
      <?php foreach ($funnel['steps'] as $s): ?>
        <div class="funnel-step <?= !empty($s['won']) ? 'is-won' : '' ?>">
          <div class="fn"><?= number_format($s['n']) ?></div><div class="fl"><?= e($s['label']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="row mt-14 gap-24">
      <div class="kv"><span class="kv-label">전환율</span><span class="kv-value mono"><?= e(pct($funnel['conversion'])) ?></span></div>
      <div class="kv"><span class="kv-label">평균 계약 소요일</span><span class="kv-value mono"><?= $funnel['avg_days'] !== null ? number_format($funnel['avg_days']) . '일' : '-' ?></span></div>
    </div>
    <div class="chip-grid mt-14">
      <?php foreach ($pick as $k): $a = $attn[$k]; ?>
        <a class="chip-stat <?= $a['n'] > 0 ? e($a['sev']) : 'zero' ?>" href="<?= e(url($a['route'], $a['params'])) ?>">
          <span class="cn"><?= number_format($a['n']) ?></span><span><?= e($a['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="chart-box sm mt-14" data-chart="stage"><canvas id="chartStage"></canvas></div>
    <div class="chart-legend" id="stageLegend"></div>
  </div>
</div>
