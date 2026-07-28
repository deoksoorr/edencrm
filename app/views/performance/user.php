<?php
/** @var array $staff @var array $summary @var int $year @var int $month
 *  @var array $contributionRows @var float $totalContribution @var float $companyProfit @var float $companyContributionRate @var bool $canAll */
$roleLabels = ['super_admin' => '슈퍼관리자', 'sales_manager' => '영업관리자', 'site_manager' => '현장관리자', 'staff' => '직원', 'accountant' => '회계'];
$projStatusLabels = StatusService::PROJECT_LABELS; // 상태 라벨 단일 출처(취소·파기·하자보수·정산 포함)
$s = $summary;

$revRate = $s['revenue_achieve_rate'];
$revCls = $revRate === null ? 'low' : ($revRate >= 90 ? 'ok' : ($revRate >= 50 ? 'mid' : 'low'));
$revRemain = max(0.0, (float) $s['target_revenue'] - (float) $s['month_revenue']);
$proRate = $s['profit_achieve_rate'];
$proCls = $proRate === null ? 'low' : ($proRate >= 90 ? 'ok' : ($proRate >= 50 ? 'mid' : 'low'));
$proRemain = max(0.0, (float) $s['target_profit'] - (float) $s['month_profit']);
?>
<div class="page">
  <div class="page-head">
    <div>
      <a href="<?= e(url('performance.index')) ?>" class="muted fs-12">&larr; 목록으로</a>
      <div class="detail-title mt-8"><?= e($staff['name']) ?> 성과</div>
      <div class="detail-meta">
        <?= e($roleLabels[$staff['role_key']] ?? $staff['role_key']) ?> · <?= e($staff['department_name'] ?? '-') ?> · <?= $year ?>년 <?= $month ?>월 기준
      </div>
    </div>
    <div class="page-actions">
      <?php if (can('staff.view')): ?><a href="<?= e(url('staff.show', ['id' => $staff['id']])) ?>" class="btn btn-outline">직원 상세</a><?php endif; ?>
    </div>
  </div>

  <div class="kpi-grid mb-14">
    <div class="kpi">
      <div class="kpi-label">담당 / 완료 / 진행 / 지연</div>
      <div class="kpi-value xl"><?= (int) $s['total_projects'] ?> / <?= (int) $s['completed_projects'] ?> / <?= (int) $s['in_progress_projects'] ?> / <span class="<?= $s['delayed_projects'] > 0 ? 'text-danger' : '' ?>"><?= (int) $s['delayed_projects'] ?></span></div>
    </div>
    <div class="kpi" title="완료(정산 포함) 프로젝트 공급가액(VAT 제외) × 본인 기여도 합">
      <div class="kpi-label">확정 매출(공급가액)</div>
      <div class="kpi-value"><?= moneyCell($s['total_revenue']) ?></div>
    </div>
    <div class="kpi <?= $s['total_profit'] < 0 ? 'accent-danger' : '' ?>" title="완료 프로젝트 (공급가액 − 실제원가) × 본인 기여도 합">
      <div class="kpi-label">확정 순이익</div>
      <div class="kpi-value"><?= moneyCell($s['total_profit']) ?></div>
      <div class="kpi-note">순이익률(가중) <?= pct($s['avg_profit_rate']) ?></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">계약전환율</div>
      <div class="kpi-value"><?= pct($s['conversion_rate']) ?></div>
    </div>
    <?php if ($s['worklog_rate'] !== null): ?>
    <div class="kpi">
      <div class="kpi-label">작업일지 작성률</div>
      <div class="kpi-value"><?= pct($s['worklog_rate']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="card pad mb-14">
    <div class="section-head"><div class="st"><h2>이번달 목표 달성률</h2><span class="section-desc"><?= $year ?>년 <?= $month ?>월</span></div></div>
    <div class="grid-2">
      <div class="goal">
        <div class="goal-top">
          <span class="section-desc" title="이번 달 수주(공급가액·계약일 기준) ÷ 목표 매출(공급가액)">매출 달성률(수주·공급가액)</span>
          <?php if ($s['target_revenue'] > 0): ?>
            <span class="goal-rate <?= $revCls === 'low' ? 'low' : '' ?>"><?= $revRate !== null ? e(number_format($revRate, 1)) : '-' ?><span class="u">%</span></span>
          <?php else: ?>
            <span class="badge badge-warn" title="이번 달 개인 목표가 설정되지 않아 달성률을 계산하지 않습니다(0%와 다름)">목표 미설정</span>
          <?php endif; ?>
        </div>
        <div class="goal-track"><div class="goal-fill <?= $revCls ?>" style="width:<?= $revRate !== null ? min(100, (float) $revRate) : 0 ?>%"></div></div>
        <div class="goal-meta">
          <div class="kv"><span class="kv-label">실적</span><span class="kv-value mono" title="<?= e(money($s['month_revenue']) . '원') ?>"><?= e(moneyShort($s['month_revenue'])) ?></span></div>
          <div class="kv"><span class="kv-label">목표</span><span class="kv-value mono"<?= $s['target_revenue'] > 0 ? ' title="' . e(money($s['target_revenue']) . '원') . '"' : '' ?>><?= $s['target_revenue'] > 0 ? e(moneyShort($s['target_revenue'])) : '미설정' ?></span></div>
          <div class="kv"><span class="kv-label">남은 금액</span><span class="kv-value mono"><?= $s['target_revenue'] > 0 ? e(moneyShort($revRemain)) : '-' ?></span></div>
        </div>
      </div>
      <div class="goal">
        <div class="goal-top">
          <span class="section-desc">순이익 달성률</span>
          <?php if ($s['target_profit'] > 0): ?>
            <span class="goal-rate <?= $proCls === 'low' ? 'low' : '' ?>"><?= $proRate !== null ? e(number_format($proRate, 1)) : '-' ?><span class="u">%</span></span>
          <?php else: ?>
            <span class="badge badge-warn" title="이번 달 개인 목표가 설정되지 않아 달성률을 계산하지 않습니다(0%와 다름)">목표 미설정</span>
          <?php endif; ?>
        </div>
        <div class="goal-track"><div class="goal-fill <?= $proCls ?>" style="width:<?= $proRate !== null ? min(100, (float) $proRate) : 0 ?>%"></div></div>
        <div class="goal-meta">
          <div class="kv"><span class="kv-label">실적</span><span class="kv-value mono" title="<?= e(money($s['month_profit']) . '원') ?>"><?= e(moneyShort($s['month_profit'])) ?></span></div>
          <div class="kv"><span class="kv-label">목표</span><span class="kv-value mono"<?= $s['target_profit'] > 0 ? ' title="' . e(money($s['target_profit']) . '원') . '"' : '' ?>><?= $s['target_profit'] > 0 ? e(moneyShort($s['target_profit'])) : '미설정' ?></span></div>
          <div class="kv"><span class="kv-label">남은 금액</span><span class="kv-value mono"><?= $s['target_profit'] > 0 ? e(moneyShort($proRemain)) : '-' ?></span></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card pad">
    <div class="section-head">
      <div class="st"><h2>수익 기여도</h2><span class="section-desc">회사 전체 순이익 대비 <b class="<?= $companyContributionRate !== null && $companyContributionRate < 0 ? 'text-danger' : '' ?>"><?= pct($companyContributionRate) ?></b></span></div>
    </div>
    <p class="muted mb-14 fs-12">기여액 = 프로젝트 순이익(공급가−실제원가) × 본인 배정 비율(contribution_pct). 완료된 프로젝트만 <b>확정</b>으로 합산되고, 진행 중 프로젝트는 <b>예상</b>으로만 표시되어 본인 총 기여액(확정)에 포함되지 않습니다. 동일 프로젝트의 순이익은 담당자별 배정 비율만큼만 나눠 반영되어 중복 합산되지 않습니다.</p>
    <?php if (!$contributionRows): ?>
      <div class="empty"><div class="empty-title">배정된 프로젝트가 없습니다.</div></div>
    <?php else: ?>
    <div class="table-wrap border-0">
      <table class="data">
        <thead>
          <tr><th>프로젝트</th><th>역할</th><th>상태</th><th class="num">배정비율</th><th class="num">프로젝트 순이익</th><th class="num">확정 기여액</th><th class="num">예상 기여액</th></tr>
        </thead>
        <tbody>
          <?php foreach ($contributionRows as $c): ?>
            <tr>
              <td><a href="<?= e(url('projects.show', ['id' => $c['project_id']])) ?>"><?= e($c['project_no']) ?> <?= e($c['name']) ?></a></td>
              <td><?= e($c['assign_role']) ?></td>
              <td>
                <span class="badge badge-info"><?= e($projStatusLabels[$c['status']] ?? $c['status']) ?></span>
                <?php if (!empty($c['excluded'])): ?>
                  <span class="badge badge-muted" title="취소·파기 프로젝트 — 확정·예상 기여 모두 집계 제외(브리프 §2)">집계 제외</span>
                <?php else: ?>
                  <span class="badge <?= $c['confirmed'] ? 'badge-ok' : 'badge-muted' ?>"><?= $c['confirmed'] ? '확정' : '예상' ?></span>
                <?php endif; ?>
              </td>
              <td class="num mono"><?= pct($c['contribution_pct']) ?></td>
              <td class="num mono<?= $c['project_profit'] < 0 ? ' text-danger' : '' ?>"><?= moneyCell($c['project_profit']) ?></td>
              <td class="num mono<?= $c['my_contribution'] < 0 ? ' text-danger' : '' ?>"><?= $c['confirmed'] ? moneyCell($c['my_contribution']) : '<span class="mono muted">-</span>' ?></td>
              <td class="num mono<?= $c['my_expected'] < 0 ? ' text-danger' : '' ?>"><?= (!$c['confirmed'] && empty($c['excluded'])) ? moneyCell($c['my_expected']) : '<span class="mono muted">-</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5" class="num"><b>본인 총 기여액(확정)</b></td>
            <td class="num mono<?= $totalContribution < 0 ? ' text-danger' : '' ?>" colspan="2"><b><?= moneyCell($totalContribution) ?></b></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
