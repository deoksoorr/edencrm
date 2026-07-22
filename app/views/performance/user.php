<?php
/** @var array $staff @var array $summary @var int $year @var int $month
 *  @var array $contributionRows @var float $totalContribution @var float $companyProfit @var float $companyContributionRate @var bool $canAll */
$roleLabels = ['super_admin' => '슈퍼관리자', 'sales_manager' => '영업관리자', 'site_manager' => '현장관리자', 'staff' => '직원', 'accountant' => '회계'];
$projStatusLabels = ['preparing' => '준비중', 'in_progress' => '진행중', 'paused' => '중지', 'completed' => '완료'];
$s = $summary;
?>
<div class="page">
  <div class="page-head">
    <div>
      <a href="<?= e(url('performance.index')) ?>" class="muted" style="font-size:12.5px">&larr; 직원 성과 목록</a>
      <div class="detail-title" style="margin-top:4px"><?= e($staff['name']) ?> 성과</div>
      <div class="detail-meta">
        <?= e($roleLabels[$staff['role_key']] ?? $staff['role_key']) ?> · <?= e($staff['department_name'] ?? '-') ?> · <?= $year ?>년 <?= $month ?>월 기준
      </div>
    </div>
    <div class="page-actions">
      <?php if (can('staff.view')): ?><a href="<?= e(url('staff.show', ['id' => $staff['id']])) ?>" class="btn btn-outline">직원 상세</a><?php endif; ?>
    </div>
  </div>

  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-label">담당 / 완료 / 진행 / 지연</div>
      <div class="stat-value" style="font-size:18px"><?= (int) $s['total_projects'] ?> / <?= (int) $s['completed_projects'] ?> / <?= (int) $s['in_progress_projects'] ?> / <span class="<?= $s['delayed_projects'] > 0 ? 'text-danger' : '' ?>"><?= (int) $s['delayed_projects'] ?></span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">총매출 (완료 기준)</div>
      <div class="stat-value stat-money"><?= money($s['total_revenue']) ?><span class="stat-unit">원</span></div>
    </div>
    <div class="stat-card <?= $s['total_profit'] < 0 ? 'stat-danger' : '' ?>">
      <div class="stat-label">총순이익 (완료 기준)</div>
      <div class="stat-value stat-money"><?= money($s['total_profit']) ?><span class="stat-unit">원</span></div>
      <div class="muted" style="font-size:11.5px;margin-top:4px">평균순이익률 <?= pct($s['avg_profit_rate']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">이번달 매출 달성률</div>
      <div class="stat-value"><?= pct($s['revenue_achieve_rate']) ?></div>
      <div class="muted" style="font-size:11.5px;margin-top:4px"><?= money($s['month_revenue']) ?> / <?= money($s['target_revenue']) ?>원</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">이번달 순이익 달성률</div>
      <div class="stat-value"><?= pct($s['profit_achieve_rate']) ?></div>
      <div class="muted" style="font-size:11.5px;margin-top:4px"><?= money($s['month_profit']) ?> / <?= money($s['target_profit']) ?>원</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">계약전환율</div>
      <div class="stat-value"><?= pct($s['conversion_rate']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">작업일지 작성률</div>
      <div class="stat-value"><?= pct($s['worklog_rate']) ?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-title">수익 기여도</div>
      <div class="muted" style="font-size:12.5px">회사 전체 순이익 대비 기여 비율 <b class="<?= $companyContributionRate !== null && $companyContributionRate < 0 ? 'text-danger' : '' ?>"><?= pct($companyContributionRate) ?></b></div>
    </div>
    <div class="card-body muted" style="padding-top:0;padding-bottom:10px;font-size:12px">
      기여액 = 프로젝트 실제순이익 × 본인 배정 비율(contribution_pct). 동일 프로젝트의 순이익은 담당자별 배정 비율만큼만 나눠 반영되어 중복 합산되지 않습니다.
    </div>
    <?php if (!$contributionRows): ?>
      <div class="card-body"><div class="empty" style="padding:24px 0"><div class="empty-title">배정된 프로젝트가 없습니다.</div></div></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr><th>프로젝트</th><th>역할</th><th>상태</th><th class="num">배정비율</th><th class="num">프로젝트 실제순이익</th><th class="num">내 기여액</th></tr>
        </thead>
        <tbody>
          <?php foreach ($contributionRows as $c): ?>
            <tr>
              <td><a href="<?= e(url('projects.show', ['id' => $c['project_id']])) ?>"><?= e($c['project_no']) ?> <?= e($c['name']) ?></a></td>
              <td><?= e($c['assign_role']) ?></td>
              <td><span class="badge badge-info"><?= e($projStatusLabels[$c['status']] ?? $c['status']) ?></span></td>
              <td class="num"><?= pct($c['contribution_pct']) ?></td>
              <td class="num <?= $c['project_profit'] < 0 ? 'text-danger' : '' ?>"><?= money($c['project_profit']) ?></td>
              <td class="num <?= $c['my_contribution'] < 0 ? 'text-danger' : '' ?>"><?= money($c['my_contribution']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5" style="text-align:right;font-weight:700">본인 총 기여액</td>
            <td class="num <?= $totalContribution < 0 ? 'text-danger' : '' ?>" style="font-weight:700"><?= money($totalContribution) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
