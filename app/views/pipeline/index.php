<?php
/**
 * 영업 파이프라인 — 조회 전용(R4 T7). 파생 7그룹 컬럼 + 상단 요약 + 공통 기간 필터(GET 제출).
 * 표시 단계는 PipelineStageService 자동 산정(원본 12단계는 수정 폼에서만 관리).
 * @var array $columns,$summary,$filters,$range,$basisOptions,$salesUsers
 * @var int $total @var bool $fullAccess,$canManage,$quickAlertsOn
 */
$f = $filters;
$filterOn = $f['q'] !== '' || $f['sales_user_id'] || $f['period'] !== '' || $f['quick'] !== '';
$quickLabels = [
    'today' => '오늘 연락 필요', 'overdue' => '연락 지남', 'stale' => '3일+ 미접촉', 'closing' => '계약 임박',
    'highvalue' => '고액 견적', 'longstay' => '장기 체류', 'unassigned' => '담당 미배정',
];
// 필터 유지용 파라미터(빈 값 제외, 내부 키 제외)
$keep = array_filter(
    ['q' => $f['q'], 'sales_user_id' => $f['sales_user_id'], 'period' => $f['period'],
     'date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'basis' => $f['basis'] !== 'created' ? $f['basis'] : ''],
    static fn($v) => $v !== '' && $v !== null
);
?>
<div class="page page-wide">
  <div class="page-head">
    <div>
      <div class="page-title">영업 파이프라인</div>
      <div class="page-sub"><?= $fullAccess ? '전체 영업기회' : '내 담당 영업기회' ?>
        · <?= $filterOn ? '조회' : '전체' ?> <b><?= (int) $summary['total'] ?></b><?= $filterOn ? ' / ' . (int) $total : '' ?>건
        · 단계는 원본 데이터(계약·견적·리드 단계) 기준 자동 산정 — 조회 전용</div>
    </div>
    <div class="page-actions">
      <?php if ($canManage): ?>
        <a href="<?= e(url('pipeline.form')) ?>" class="btn btn-primary">+ 신규 영업기회</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- 상단 요약(필터 연동): 진행 예상·견적 진행·계약 전환·보류/종료 -->
  <div class="kpi-grid pb-summary">
    <div class="kpi accent-brand" title="종료·보류·계약 제외, 진행 그룹(신규 문의~견적 진행) 예상 계약 금액 합">
      <div class="kpi-label">진행 예상 금액</div>
      <div class="kpi-value"><?= e(moneyShort((float) $summary['open_amount'])) ?><span class="u">원 · <?= (int) $summary['open_count'] ?>건</span></div>
    </div>
    <div class="kpi" title="파생 단계 '견적 진행' 그룹의 예상 계약 금액 합">
      <div class="kpi-label">견적 진행 금액</div>
      <div class="kpi-value"><?= e(moneyShort((float) $summary['quoting_amount'])) ?><span class="u">원 · <?= (int) $summary['quoting_count'] ?>건</span></div>
    </div>
    <div class="kpi accent-ok" title="파생 단계 '계약' 그룹(연결 계약·계약완료 리드) 건수·예상 금액">
      <div class="kpi-label">계약 전환</div>
      <div class="kpi-value"><?= (int) $summary['contracted_count'] ?><span class="u">건 · <?= e(moneyShort((float) $summary['contracted_amount'])) ?>원</span></div>
    </div>
    <div class="kpi" title="파생 단계 보류·종료 건수(종료 리드의 예상 금액은 진행 예상에서 제외됨)">
      <div class="kpi-label">보류 / 종료</div>
      <div class="kpi-value"><?= (int) $summary['on_hold_count'] ?><span class="u">건</span> / <?= (int) $summary['closed_count'] ?><span class="u">건</span></div>
    </div>
  </div>

  <!-- 필터: 검색·담당 + 공통 기간(기준 select 포함) — GET 제출로 카드·요약 동시 갱신 -->
  <form class="toolbar" method="get" action="<?= e(url('pipeline.index')) ?>">
    <input type="hidden" name="r" value="pipeline.index">
    <?php if ($f['quick'] !== ''): ?><input type="hidden" name="quick" value="<?= e($f['quick']) ?>"><?php endif; ?>
    <input type="text" name="q" class="input search" placeholder="고객·업체·연락처·주소·공사종류 검색" value="<?= e($f['q']) ?>">
    <?php if ($fullAccess): ?>
      <select name="sales_user_id" class="select">
        <option value="">담당영업 전체</option>
        <?php foreach ($salesUsers as $su): ?>
          <option value="<?= (int) $su['id'] ?>" <?= (int) $f['sales_user_id'] === (int) $su['id'] ? 'selected' : '' ?>><?= e($su['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <?php View::partial('partials/period_filter', [
        'action'       => 'pipeline.index',
        'filters'      => array_filter(['q' => $f['q'], 'sales_user_id' => $f['sales_user_id'], 'quick' => $f['quick']], static fn($v) => $v !== '' && $v !== null)
                          + ['period' => $f['period'], 'date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'basis' => $f['basis']],
        'range'        => $range,
        'basisOptions' => $basisOptions, // 등록일(기본)/최근 연락일 — 최근 연락일 기준은 연락 기록 없는 리드가 제외됨
        'basisParam'   => 'basis',
    ]); ?>
    <button type="submit" class="btn btn-outline">검색</button>
    <?php if ($filterOn): ?>
      <a href="<?= e(url('pipeline.index')) ?>" class="btn btn-ghost">초기화</a>
    <?php endif; ?>
  </form>

  <?php if ($quickAlertsOn): ?>
    <!-- 빠른 필터 칩 — 기능 플래그(feature_pipeline_quick_alerts) ON 시에만 노출(r3 유지·확대) -->
    <div class="pl-quick pl-quick-bar">
      <?php foreach ($quickLabels as $qk => $ql): $on = $f['quick'] === $qk; ?>
        <a class="qf<?= $on ? ' active' : '' ?>"
           href="<?= e(url('pipeline.index', $keep + ($on ? [] : ['quick' => $qk]))) ?>"><?= e($ql) ?></a>
      <?php endforeach; ?>
    </div>
  <?php elseif ($f['quick'] !== ''): ?>
    <!-- 칩 미노출 상태에서 빠른 필터 URL(대시보드 링크)로 진입한 경우: 적용 표시+해제 제공 -->
    <div class="pl-quick pl-quick-bar">
      <span class="qf active"><?= e($quickLabels[$f['quick']] ?? $f['quick']) ?>
        <a href="<?= e(url('pipeline.index', $keep)) ?>" class="qf-x" aria-label="빠른 필터 해제">&times;</a></span>
    </div>
  <?php endif; ?>

  <div class="kanban" id="kanbanBoard">
    <?php View::partial('pipeline/board', ['columns' => $columns]); ?>
  </div>
</div>
