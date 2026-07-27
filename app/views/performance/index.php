<?php
/** @var array $rows @var bool $canAll @var int $year @var int $month */
$roleLabels = ['super_admin' => '슈퍼관리자', 'sales_manager' => '영업관리자', 'site_manager' => '현장관리자', 'staff' => '직원', 'accountant' => '회계'];
$wl = Settings::enabled('feature_worklog');
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
          <th class="num" title="담당 프로젝트 계약 총액(VAT 포함) × 기여도 합 · 상태 무관 누적 · 기여율 없으면 미반영(T9)">계약 총액(VAT 포함)</th><th class="num" title="프로젝트 순입금 × 기여도 합(예외 직접 입금 포함) · 입금일 기준 · VAT 포함(R11)">확정 매출(입금 기준)</th><th class="num" title="확정 지출 × 기여도 합 · 발생일 기준">원가 총액(확정)</th><th class="num" title="귀속 확정 매출(입금) − 귀속 확정 지출">확정 순이익</th><th class="num" title="확정 순이익 ÷ 확정 매출(입금 기준) × 100">순이익률(가중)</th>
          <th class="num" title="이번 달 개인 목표(공급가액 기준)">목표 매출(공급가액)</th><th class="num" title="이번 달 수주(공급가액) ÷ 목표 매출 × 100">매출 달성률</th>
          <th class="num">목표순이익</th><th class="num">순이익달성률</th>
          <th class="num">계약전환율</th><?php if ($wl): ?><th class="num">일지작성률</th><?php endif; ?>
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
            <?php /* 목표 0/미등록은 '0원·0%'가 아니라 '목표 미설정'으로 구분 표기(달성률 null → '-') */ ?>
            <td class="num mono"><?= $r['target_revenue'] > 0 ? moneyCell($r['target_revenue']) : '<span class="muted" title="이번 달 개인 목표가 설정되지 않았습니다">목표 미설정</span>' ?></td>
            <td class="num mono"><?= $r['target_revenue'] > 0 ? pct($r['revenue_achieve_rate']) : '<span class="muted">-</span>' ?></td>
            <td class="num mono"><?= $r['target_profit'] > 0 ? moneyCell($r['target_profit']) : '<span class="muted" title="이번 달 개인 목표가 설정되지 않았습니다">목표 미설정</span>' ?></td>
            <td class="num mono"><?= $r['target_profit'] > 0 ? pct($r['profit_achieve_rate']) : '<span class="muted">-</span>' ?></td>
            <td class="num mono"><?= pct($r['conversion_rate']) ?></td>
            <?php if ($wl): ?><td class="num mono"><?= pct($r['worklog_rate']) ?></td><?php endif; ?>
            <td><a href="<?= e(url('performance.user', ['id' => $r['user_id']])) ?>" class="btn btn-sm btn-outline">상세</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
