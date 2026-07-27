<?php
/** @var array $rows @var array $pg @var string $q @var string $status @var int $managerId
 *  @var string $workType @var bool $delayed @var string $regType @var string $payFilter @var string $sort @var string $dir
 *  @var array $managers @var array $workTypes @var array $statuses @var bool $canFinance
 */
$today = date('Y-m-d');
$base = [
    'q' => $q, 'status' => $status, 'manager_id' => $managerId ?: '', 'work_type' => $workType,
    'delayed' => $delayed ? '1' : '', 'reg_type' => $regType !== 'all' ? $regType : '',
    'pay' => $payFilter, 'sort' => $sort, 'dir' => $dir,
];
// R11 입금·정산 필터 라벨(컨트롤러 화이트리스트와 동일 키)
$payFilterLabels = [
    'none' => '미입금', 'partial' => '일부 입금', 'paid' => '완납',
    'outstanding' => '미수금 있음', 'settled' => '정산 완료', 'hold' => '정산 보류',
];
function projSortUrl(string $key, string $sort, string $dir, array $base): string
{
    $dirNext = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    return url('projects.index', array_merge($base, ['sort' => $key, 'dir' => $dirNext, 'page' => 1]));
}
function projSortArrow(string $key, string $sort, string $dir): string
{
    if ($sort !== $key) return '';
    return $dir === 'asc' ? ' ▲' : ' ▼';
}
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">프로젝트</h1>
      <div class="page-sub">전체 <?= number_format($pg['total']) ?>건</div>
    </div>
    <div class="page-actions">
      <?php /* r3-contractflow: 프로젝트는 계약 '진행' 전환 시 자동 생성 — 예외 생성은 최고 관리자 전용(라우트도 서버측 차단) */ ?>
      <?php if (is_role('super_admin')): ?>
        <a href="<?= e(url('projects.form')) ?>" class="btn btn-outline"
           title="프로젝트는 계약 '진행' 전환 시 자동 생성됩니다 — 계약 연결 없는 예외 프로젝트(하자보수·내부 작업)만 직접 생성">+ 예외 프로젝트 생성</a>
      <?php endif; ?>
    </div>
  </div>

  <form class="toolbar" method="get" action="<?= e(url('projects.index')) ?>">
    <input type="hidden" name="r" value="projects.index">
    <input type="text" name="q" class="input search" placeholder="프로젝트명/고객/현장주소 검색" value="<?= e($q) ?>">
    <select name="status" class="select">
      <option value="">전체 상태</option>
      <?php foreach ($statuses as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="manager_id" class="select">
      <option value="">전체 현장관리자</option>
      <?php foreach ($managers as $m): ?>
        <option value="<?= (int) $m['id'] ?>" <?= $managerId === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="work_type" class="select">
      <option value="">전체 공사유형</option>
      <?php foreach ($workTypes as $wt): ?>
        <option value="<?= e($wt) ?>" <?= $workType === $wt ? 'selected' : '' ?>><?= e($wt) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="reg_type" class="select" title="등록 유형 — 일반: 계약 '진행' 전환 시 자동 생성 · 예외: 최고 관리자 수동 생성">
      <option value="">전체 등록유형</option>
      <option value="normal" <?= $regType === 'normal' ? 'selected' : '' ?>>일반</option>
      <option value="exception" <?= $regType === 'exception' ? 'selected' : '' ?>>예외</option>
    </select>
    <?php if ($canFinance): ?>
    <select name="pay" class="select" title="입금·정산 상태 필터 — 예정 금액(예외=직접 입력, 일반=계약 총액) 대비 순입금 기준">
      <option value="">전체 입금·정산</option>
      <?php foreach ($payFilterLabels as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $payFilter === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label class="check">
      <input type="checkbox" name="delayed" value="1" <?= $delayed ? 'checked' : '' ?>> 지연만 보기
    </label>
    <button type="submit" class="btn btn-outline">검색</button>
    <?php if ($q || $status || $managerId || $workType || $delayed || $regType !== 'all' || $payFilter !== ''): ?>
      <a href="<?= e(url('projects.index')) ?>" class="btn btn-ghost">초기화</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-icon">▤</div>
      <div class="empty-title">조건에 맞는 프로젝트가 없습니다.</div>
      <div class="muted fs-13">프로젝트는 계약을 '진행(계약 진행)' 상태로 전환하면 자동 생성됩니다.</div>
      <?php if (is_role('super_admin')): ?>
        <a href="<?= e(url('projects.form')) ?>" class="btn btn-outline">예외 프로젝트 생성</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th><a href="<?= e(projSortUrl('project_no', $sort, $dir, $base)) ?>">번호<?= projSortArrow('project_no', $sort, $dir) ?></a></th>
            <th><a href="<?= e(projSortUrl('name', $sort, $dir, $base)) ?>">프로젝트명<?= projSortArrow('name', $sort, $dir) ?></a></th>
            <th>고객</th>
            <th>상태</th>
            <th>진행률</th>
            <th>현장관리자</th>
            <th><a href="<?= e(projSortUrl('start_date', $sort, $dir, $base)) ?>">착공<?= projSortArrow('start_date', $sort, $dir) ?></a></th>
            <th><a href="<?= e(projSortUrl('end_date', $sort, $dir, $base)) ?>">준공예정<?= projSortArrow('end_date', $sort, $dir) ?></a></th>
            <?php if ($canFinance): ?>
            <th class="num" title="예외 프로젝트 = 정산 예정 금액(직접 입력) · 일반 = 계약 총액(VAT 포함)">예정 금액</th>
            <th class="num" title="확정(paid) 입금 − 환불">누적 입금</th>
            <th class="num" title="예정 금액 − 누적 입금 (0 미만은 0)">미수금</th>
            <th title="입금 상태(예정 대비 순입금) · 정산 상태(공정과 분리된 정산 축)">입금·정산</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $p): ?>
            <?php
              // 대시보드 delayedCond 와 동일 기준(종결 상태·준공 처리 제외)
              $isDelayed = !empty($p['end_date']) && $p['end_date'] < $today && empty($p['actual_end_date'])
                  && !in_array($p['status'], ['completed', 'settled', 'cancelled', 'terminated'], true);
              $badgeClass = StatusService::PROJECT_BADGE[$p['status']] ?? 'badge';
              $progress = (int) $p['progress'];
              $barClass = $progress >= 100 ? 'ok' : ($isDelayed ? 'danger' : '');
            ?>
            <tr>
              <td class="nowrap"><a href="<?= e(url('projects.show', ['id' => $p['id']])) ?>"><?= e($p['project_no']) ?></a></td>
              <td class="wrap"><a href="<?= e(url('projects.show', ['id' => $p['id']])) ?>" title="<?= e($p['name']) ?>"><?= e(Util::truncate($p['name'], 30)) ?></a><?php if (!empty($p['is_exception'])): ?> <span class="badge badge-warn fs-11" title="예외 프로젝트 — 계약 연결 없이 수동 생성">예외</span><?php endif; ?></td>
              <td><?= e($p['customer_name'] ?: '-') ?></td>
              <td><span class="badge <?= $badgeClass ?>"><?= e($statuses[$p['status']] ?? $p['status']) ?></span></td>
              <td>
                <div class="progress"><div class="progress-bar <?= $barClass ?>" style="width:<?= $progress ?>%"></div></div>
                <div class="progress-label"><?= $progress ?>%</div>
              </td>
              <td><?= e($p['site_manager_name'] ?? '-') ?></td>
              <td class="nowrap"><?= fmtdate($p['start_date']) ?></td>
              <td class="nowrap <?= $isDelayed ? 'text-danger' : '' ?>"><?= fmtdate($p['end_date']) ?><?= $isDelayed ? ' (지연)' : '' ?></td>
              <?php if ($canFinance):
                // R11: 입금 상태(파생 — projectPaySummary 와 동일 규칙) + 정산 상태 배지
                $exp = (int) $p['pay_expected'];
                $net = (int) $p['pay_net'];
                $outstanding = max(0, $exp - $net);
                $isExcLedger = !empty($p['is_exception']) && empty($p['contract_id']);
                if ($exp > 0) {
                    $pstat = $net <= 0 ? 'none' : ($net < $exp ? 'partial' : ($net === $exp ? 'paid' : 'over'));
                } else {
                    $pstat = $net > 0 ? 'partial' : 'none';
                }
                $sstat = (string) ($p['settlement_status'] ?? 'unsettled');
              ?>
              <td class="num"><?php if ($exp > 0 || !$isExcLedger): ?><?= money((float) $exp) ?><?php else: ?><span class="muted" title="정산 예정 금액 미입력(예외 프로젝트)">미설정</span><?php endif; ?></td>
              <td class="num"><?= money((float) $net) ?></td>
              <td class="num<?= $outstanding > 0 ? ' text-danger' : '' ?>"><?= money((float) $outstanding) ?></td>
              <td class="nowrap">
                <span class="badge <?= e(AccountingService::PAY_STATUS_BADGE[$pstat]) ?> fs-11"><?= e(AccountingService::PAY_STATUS_LABELS[$pstat]) ?></span>
                <span class="badge <?= e(StatusService::SETTLEMENT_BADGE[$sstat] ?? 'badge-muted') ?> fs-11"><?= e(StatusService::SETTLEMENT_LABELS[$sstat] ?? $sstat) ?></span>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php View::partial('partials/pager', [
        'pg'  => $pg,
        'url' => fn (int $p): string => url('projects.index', array_merge($base, ['page' => $p])),
    ]); ?>
  <?php endif; ?>
</div>
