<?php
/**
 * 반기 현황(halfyear.index) — 매출/순이익/현장 보너스 3개 섹션 + 직원별 표(view_all).
 * @var array $f            필터 {year, half, userId, projectId, payStatus, canAll}
 * @var int[] $years        연도 선택지
 * @var array $users        직원 필터 옵션
 * @var array $projects     프로젝트 필터 옵션
 * @var array $revenueKpi   {contracted, paid, revenue, receivable, projectCount}
 * @var array $profitKpi    {revenue, costReg, costDirect, profit, profitRate}
 * @var array $bonuses      필터 적용 보너스 목록
 * @var array $bonusTotals  {calc, paid, unpaid} — cancelled 제외
 * @var array $staffRows    직원별 표(view_all 시)
 * @var bool  $isClosed     마감 반기 여부
 */
$statusLabels = ['unpaid' => '미지급', 'partial' => '부분지급', 'paid' => '지급완료', 'cancelled' => '취소'];
$statusBadge  = ['unpaid' => 'badge-warn', 'partial' => 'badge-info', 'paid' => 'badge-ok', 'cancelled' => 'badge-danger'];
$scoped = $f['userId'] > 0; // 특정 직원 스코프(귀속치) 여부
$selUserName = '';
foreach ($users as $u) {
    if ((int) $u['id'] === $f['userId']) { $selUserName = $u['name']; break; }
}
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">반기 현황
        <?php if ($isClosed): ?><span class="badge badge-warn" title="반기 종료 — 보너스 수정·삭제 시 사유 필수">마감</span><?php endif; ?>
      </h1>
      <div class="page-sub"><?= e(Util::halfLabel($f['year'], $f['half'])) ?><?= $scoped ? ' · ' . e($selUserName) . ' 귀속 기준' : ' · 전사 기준' ?></div>
    </div>
    <div class="page-actions">
      <a href="<?= e(url('bonus.index', ['year' => $f['year'], 'half' => $f['half']])) ?>" class="btn btn-outline">보너스 지급 현황</a>
    </div>
  </div>

  <form method="get" class="toolbar" action="<?= e($GLOBALS['config']['BASE_URL']) ?>/index.php">
    <input type="hidden" name="r" value="halfyear.index">
    <select name="year" class="select">
      <?php foreach ($years as $y): ?>
        <option value="<?= (int) $y ?>" <?= $y === $f['year'] ? 'selected' : '' ?>><?= (int) $y ?>년</option>
      <?php endforeach; ?>
    </select>
    <select name="half" class="select">
      <option value="1" <?= $f['half'] === 1 ? 'selected' : '' ?>>상반기</option>
      <option value="2" <?= $f['half'] === 2 ? 'selected' : '' ?>>하반기</option>
    </select>
    <?php if ($f['canAll']): ?>
      <select name="user_id" class="select">
        <option value="">전체 직원</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= $f['userId'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?><?= ($u['status'] ?? 'active') !== 'active' ? ' (비활성)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <span class="badge badge-info" title="전체 열람 권한이 없어 본인 데이터만 표시됩니다">본인 데이터</span>
    <?php endif; ?>
    <select name="project_id" class="select">
      <option value="">전체 프로젝트</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= $f['projectId'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="pay_status" class="select">
      <option value="">전체 지급상태</option>
      <?php foreach ($statusLabels as $k => $l): ?>
        <option value="<?= e($k) ?>" <?= $f['payStatus'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline">조회</button>
    <a href="<?= e(url('halfyear.index')) ?>" class="btn btn-ghost">초기화</a>
  </form>

  <!-- (a) 매출 현황 -->
  <div class="card pad mb-14">
    <div class="section-head"><div class="st"><h2>매출 현황</h2><span class="section-desc"><?= e(Util::halfLabel($f['year'], $f['half'])) ?><?= $scoped ? ' · 선택 직원 귀속' : '' ?></span></div></div>
    <div class="kpi-grid">
      <div class="kpi" title="취소·파기 제외 프로젝트 공급가액 합 · 계약일 기준<?= $scoped ? ' · 담당 영업 기준' : '' ?>">
        <div class="kpi-label">계약금액(공급가)</div>
        <div class="kpi-value"><?= moneyCell($revenueKpi['contracted']) ?></div>
      </div>
      <div class="kpi" title="<?= $scoped ? '계약 순입금 × 기여율(VAT 포함) · 입금일 기준' : '순입금(입금−환불, VAT 포함) · 입금일 기준' ?>">
        <div class="kpi-label">기간 입금액</div>
        <div class="kpi-value"><?= moneyCell($revenueKpi['paid']) ?></div>
      </div>
      <div class="kpi accent-brand" title="<?= $scoped ? '완료·정산 프로젝트 공급가 × 기여율' : '완납(순입금 ≥ 계약총액) 계약 공급가액 합 · 완납일 기준' ?>">
        <div class="kpi-label">확정매출(공급가)</div>
        <div class="kpi-value"><?= moneyCell($revenueKpi['revenue']) ?></div>
      </div>
      <div class="kpi accent-warn" title="Σ 계약별 max(0, 계약총액 − 순입금) · 체결 이후 계약만">
        <div class="kpi-label">미수금</div>
        <div class="kpi-value"><?= moneyCell($revenueKpi['receivable']) ?></div>
        <div class="kpi-note">현재 시점 스냅샷(기간 무관)</div>
      </div>
      <div class="kpi" title="기간 내 계약일(없으면 등록일) 기준 · 취소·파기 제외">
        <div class="kpi-label">프로젝트 수</div>
        <div class="kpi-value"><?= (int) $revenueKpi['projectCount'] ?><span class="u">건</span></div>
      </div>
    </div>
  </div>

  <!-- (b) 순이익 현황 -->
  <div class="card pad mb-14">
    <div class="section-head"><div class="st"><h2>순이익 현황</h2><span class="section-desc">등록 지출·직접 원가는 전사 기준</span></div></div>
    <div class="kpi-grid">
      <div class="kpi">
        <div class="kpi-label">확정매출(공급가)</div>
        <div class="kpi-value"><?= moneyCell($profitKpi['revenue']) ?></div>
      </div>
      <div class="kpi" title="확정 실지출(costs actual·confirmed) · 지출일 기준 · 전사">
        <div class="kpi-label">등록 지출(전사)</div>
        <div class="kpi-value"><?= moneyCell($profitKpi['costReg']) ?></div>
      </div>
      <div class="kpi" title="완료·정산 프로젝트 실제원가 합 · 준공일 기준 · 전사">
        <div class="kpi-label">직접 원가(전사)</div>
        <div class="kpi-value"><?= moneyCell($profitKpi['costDirect']) ?></div>
      </div>
      <div class="kpi <?= $profitKpi['profit'] < 0 ? 'accent-danger' : 'accent-ok' ?>" title="<?= $scoped ? '(공급가 − 실제원가) × 기여율 · 완료·정산 기준' : '완료·정산 프로젝트 (공급가 − 실제원가) 합' ?>">
        <div class="kpi-label">순이익</div>
        <div class="kpi-value"><?= moneyCell($profitKpi['profit']) ?></div>
      </div>
      <div class="kpi" title="순이익 ÷ 확정매출 × 100 (확정매출 0이면 계산 불가)">
        <div class="kpi-label">순이익률</div>
        <div class="kpi-value"><?= $profitKpi['profitRate'] !== null ? e(number_format($profitKpi['profitRate'], 1)) . '<span class="u">%</span>' : '-' ?></div>
      </div>
    </div>
  </div>

  <?php if ($f['canAll'] && $staffRows): ?>
  <!-- 직원별 표 (전체 열람 권한) -->
  <div class="card mb-14">
    <div class="card-head"><div class="card-title">직원별 반기 실적</div>
      <div class="muted fs-12">계약금액=담당 영업 수주 · 입금=기여율 귀속 · 확정매출/순이익=완료·정산 귀속 · 보너스=지급액(취소 제외)</div>
    </div>
    <div class="table-wrap">
      <table class="data compact">
        <thead><tr><th>직원</th><th class="num">계약금액</th><th class="num">입금</th><th class="num">확정매출</th><th class="num">순이익</th><th class="num">보너스 지급</th></tr></thead>
        <tbody>
          <?php foreach ($staffRows as $s): ?>
            <tr>
              <td><a href="<?= e(url('staff.show', ['id' => $s['user_id'], 'year' => $f['year'], 'half' => $f['half']])) ?>"><?= e($s['name']) ?></a></td>
              <td class="num mono"><?= moneyCell($s['contracted']) ?></td>
              <td class="num mono"><?= moneyCell($s['paid']) ?></td>
              <td class="num mono"><?= moneyCell($s['revenue']) ?></td>
              <td class="num mono<?= $s['profit'] < 0 ? ' text-danger' : '' ?>"><?= moneyCell($s['profit']) ?></td>
              <td class="num mono"><?= moneyCell($s['bonus_paid']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- (c) 현장 보너스 -->
  <div class="card">
    <div class="card-head">
      <div class="card-title">현장 보너스</div>
      <div class="muted fs-12">
        산정액 <b class="mono"><?= money($bonusTotals['calc']) ?></b>원 ·
        지급액 <b class="mono"><?= money($bonusTotals['paid']) ?></b>원 ·
        미지급 <b class="mono<?= $bonusTotals['unpaid'] > 0 ? ' text-danger' : '' ?>"><?= money($bonusTotals['unpaid']) ?></b>원
        <span class="muted">(취소 건 제외)</span>
      </div>
    </div>
    <?php if (!$bonuses): ?>
      <div class="card-body"><div class="empty compact"><div class="empty-title">조건에 맞는 보너스 내역이 없습니다.</div></div></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data compact">
        <thead>
          <tr><th>직원</th><th>프로젝트</th><th class="num">산정 대상 금액</th><th>산정 기준</th><th class="num">산정액</th><th class="num">지급액</th><th class="num">미지급액</th><th>지급일</th><th>상태</th></tr>
        </thead>
        <tbody>
          <?php foreach ($bonuses as $b): $cancelled = $b['pay_status'] === 'cancelled'; ?>
            <tr<?= $cancelled ? ' class="bonus-cancelled"' : '' ?>>
              <td><?= e($b['user_name']) ?></td>
              <td><?= $b['project_id'] ? e($b['project_name'] ?? ('#' . $b['project_id'])) : '<span class="muted">-</span>' ?></td>
              <td class="num mono"><?= moneyCell((float) $b['base_amount']) ?></td>
              <td><?= e($b['calc_basis'] ?? '-') ?></td>
              <td class="num mono"><?= moneyCell((float) $b['calc_amount']) ?></td>
              <td class="num mono"><?= moneyCell((float) $b['paid_amount']) ?></td>
              <td class="num mono"><?= $cancelled ? '-' : moneyCell((float) $b['calc_amount'] - (float) $b['paid_amount']) ?></td>
              <td><?= $b['pay_date'] ? e(fmtdate($b['pay_date'])) : '-' ?></td>
              <td><span class="badge <?= e($statusBadge[$b['pay_status']] ?? 'badge-info') ?>"><?= e($statusLabels[$b['pay_status']] ?? $b['pay_status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
