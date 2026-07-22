<?php
/** @var array $rows @var bool $canAll @var int $year @var int $month */
$roleLabels = ['super_admin' => '슈퍼관리자', 'sales_manager' => '영업관리자', 'site_manager' => '현장관리자', 'staff' => '직원', 'accountant' => '회계'];
?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title">직원 성과</h1>
    <div class="page-sub">
      <?= $canAll ? '전 직원 성과 집계' : '본인 성과만 열람 가능합니다(전체 열람은 performance.view_all 권한 필요)' ?>
      · <?= $year ?>년 <?= $month ?>월 목표 기준
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty"><div class="empty-title">표시할 성과 데이터가 없습니다.</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>이름</th><th>부서</th><th>역할</th>
          <th class="num">담당</th><th class="num">완료</th><th class="num">진행</th><th class="num">지연</th>
          <th class="num">총계약</th><th class="num">확정매출</th><th class="num">확정원가</th><th class="num">확정순이익</th><th class="num">순이익률(가중)</th>
          <th class="num">목표매출</th><th class="num">매출달성률</th>
          <th class="num">목표순이익</th><th class="num">순이익달성률</th>
          <th class="num">계약전환율</th><th class="num">일지작성률</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td><?= e($r['department']) ?></td>
            <td><?= e($roleLabels[$r['role_key']] ?? $r['role_key']) ?></td>
            <td class="num mono"><?= number_format($r['total_projects']) ?></td>
            <td class="num mono"><?= number_format($r['completed_projects']) ?></td>
            <td class="num mono"><?= number_format($r['in_progress_projects']) ?></td>
            <td class="num mono<?= $r['delayed_projects'] > 0 ? ' text-danger' : '' ?>"><?= number_format($r['delayed_projects']) ?></td>
            <td class="num mono"><?= moneyCell($r['total_contract_amount']) ?></td>
            <td class="num mono"><?= moneyCell($r['total_revenue']) ?></td>
            <td class="num mono"><?= moneyCell($r['total_cost']) ?></td>
            <td class="num mono<?= $r['total_profit'] < 0 ? ' text-danger' : '' ?>"><?= moneyCell($r['total_profit']) ?></td>
            <td class="num mono"><?= pct($r['avg_profit_rate']) ?></td>
            <td class="num mono"><?= moneyCell($r['target_revenue']) ?></td>
            <td class="num mono"><?= pct($r['revenue_achieve_rate']) ?></td>
            <td class="num mono"><?= moneyCell($r['target_profit']) ?></td>
            <td class="num mono"><?= pct($r['profit_achieve_rate']) ?></td>
            <td class="num mono"><?= pct($r['conversion_rate']) ?></td>
            <td class="num mono"><?= pct($r['worklog_rate']) ?></td>
            <td><a href="<?= e(url('performance.user', ['id' => $r['user_id']])) ?>" class="btn btn-sm btn-outline">상세</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
