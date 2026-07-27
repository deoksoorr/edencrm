<?php
/** @var array $staff @var array $projects @var int $projectCount @var bool $canViewPerf
 *  @var int $vy @var int $vh @var int[] $years @var array $siteRows @var array $bonuses */
$statusLabels = ['active' => '재직', 'inactive' => '비활성'];
$roleLabels = ['super_admin' => '슈퍼관리자', 'sales_manager' => '영업관리자', 'site_manager' => '현장관리자', 'staff' => '직원', 'accountant' => '회계'];
$projStatusLabels = ['preparing' => '준비중', 'in_progress' => '진행중', 'paused' => '중지', 'completed' => '완료', 'settled' => '정산', 'cancelled' => '취소', 'terminated' => '파기'];
$payStatusLabels = ['unpaid' => '미지급', 'partial' => '부분지급', 'paid' => '지급완료', 'cancelled' => '취소'];
$payStatusBadge  = ['unpaid' => 'badge-warn', 'partial' => 'badge-info', 'paid' => 'badge-ok', 'cancelled' => 'badge-danger'];
?>
<div class="page">
  <div class="page-head">
    <div>
      <a href="<?= e(url('staff.index')) ?>" class="muted fs-12">&larr; 직원 목록</a>
      <div class="detail-title" style="margin-top:4px"><?= e($staff['name']) ?></div>
      <div class="detail-meta">
        <?= e($roleLabels[$staff['role_key']] ?? $staff['role_key']) ?> · <?= e($staff['department_name'] ?? '부서 미지정') ?> · <?= e($staff['position'] ?? '-') ?>
        <span class="badge <?= $staff['status'] === 'active' ? 'badge-ok' : 'badge-danger' ?>" style="margin-left:8px"><?= e($statusLabels[$staff['status']] ?? $staff['status']) ?></span>
        <?php if ((int) $staff['must_change_password'] === 1): ?><span class="badge badge-warn" style="margin-left:4px">비밀번호 변경 필요</span><?php endif; ?>
      </div>
    </div>
    <div class="page-actions">
      <?php if ($canViewPerf): ?>
        <a href="<?= e(url('performance.user', ['id' => $staff['id']])) ?>" class="btn btn-outline">성과 보기</a>
      <?php endif; ?>
      <?php if (can('staff.manage')): ?>
        <a href="<?= e(url('staff.form', ['id' => $staff['id']])) ?>" class="btn btn-outline">정보 수정</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">기본 정보</div></div>
    <div class="card-body">
      <dl class="dl">
        <dt>아이디</dt><dd><?= e($staff['login_id']) ?></dd>
        <dt>이메일</dt><dd><?= e($staff['email']) ?></dd>
        <dt>연락처</dt><dd><?= e($staff['phone'] ?? '-') ?></dd>
        <dt>입사일</dt><dd><?= fmtdate($staff['hire_date'] ?? null) ?></dd>
        <dt>마지막 로그인</dt><dd><?= $staff['last_login_at'] ? e(fmtdate($staff['last_login_at'], 'Y-m-d H:i')) : '-' ?></dd>
        <dt>메모</dt><dd><?= nl2br(e($staff['memo'] ?? '-')) ?></dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-title">담당 프로젝트 요약</div>
      <div class="muted fs-12">총 <?= (int) $projectCount ?>건 (최근 10건 표시)</div>
    </div>
    <?php if (!$projects): ?>
      <div class="card-body"><div class="empty compact"><div class="empty-title">담당 중인 프로젝트가 없습니다.</div></div></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>프로젝트번호</th><th>이름</th><th>상태</th><th class="num">계약금액</th><th>진행률</th></tr></thead>
        <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td><?= e($p['project_no']) ?></td>
              <td><?= e($p['name']) ?></td>
              <td><span class="badge badge-info"><?= e($projStatusLabels[$p['status']] ?? $p['status']) ?></span></td>
              <td class="num mono"><?= money((float) $p['contract_amount']) ?></td>
              <td>
                <div class="progress"><div class="progress-bar <?= $p['progress'] >= 100 ? 'ok' : '' ?>" style="width:<?= (int) $p['progress'] ?>%"></div></div>
                <div class="progress-label"><?= (int) $p['progress'] ?>%</div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($canViewPerf): // 반기 실적 상세 패널 — 본인 또는 performance.view_all 만(R8-B) ?>
  <div class="card">
    <div class="card-head">
      <div class="card-title">반기 실적 — <?= e(Util::halfLabel($vy, $vh)) ?></div>
      <form method="get" class="staff-half-form" action="<?= e($GLOBALS['config']['BASE_URL']) ?>/index.php">
        <input type="hidden" name="r" value="staff.show">
        <input type="hidden" name="id" value="<?= (int) $staff['id'] ?>">
        <select name="year" class="select" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?= (int) $y ?>" <?= $y === $vy ? 'selected' : '' ?>><?= (int) $y ?>년</option>
          <?php endforeach; ?>
        </select>
        <select name="half" class="select" onchange="this.form.submit()">
          <option value="1" <?= $vh === 1 ? 'selected' : '' ?>>상반기</option>
          <option value="2" <?= $vh === 2 ? 'selected' : '' ?>>하반기</option>
        </select>
      </form>
    </div>
    <?php if (!$siteRows): ?>
      <div class="card-body"><div class="empty compact"><div class="empty-title">해당 반기에 배정·계약된 현장이 없습니다.</div></div></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data compact">
        <thead>
          <tr>
            <th>프로젝트</th><th>구분</th><th class="num">계약금액</th><th class="num" title="계약별 순입금(입금−환불, VAT 포함)">입금액</th>
            <th class="num">실제원가</th><th class="num" title="공급가액 − 실제원가">순이익</th><th class="num">기여율</th>
            <th class="num" title="순이익 × 기여율">기여 순이익</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($siteRows as $s): ?>
            <tr>
              <td><a href="<?= e(url('projects.show', ['id' => $s['id']])) ?>"><?= e($s['name']) ?></a> <span class="muted fs-12"><?= e($s['project_no']) ?></span></td>
              <td><span class="badge <?= $s['done'] ? 'badge-ok' : 'badge-info' ?>"><?= $s['done'] ? '완료' : e($projStatusLabels[$s['status']] ?? '진행') ?></span></td>
              <td class="num mono"><?= moneyCell((float) $s['contract']) ?></td>
              <td class="num mono"><?= moneyCell((float) $s['net_paid']) ?></td>
              <td class="num mono"><?= moneyCell((float) $s['actual_cost']) ?></td>
              <td class="num mono<?= $s['profit'] < 0 ? ' text-danger' : '' ?>"><?= moneyCell((float) $s['profit']) ?></td>
              <td class="num mono"><?= e(rtrim(rtrim(number_format($s['pct'], 2), '0'), '.')) ?>%</td>
              <td class="num mono<?= $s['my_profit'] < 0 ? ' text-danger' : '' ?>"><?= moneyCell((float) $s['my_profit']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-title">보너스 내역 — <?= e(Util::halfLabel($vy, $vh)) ?></div>
      <a href="<?= e(url('bonus.history', ['user_id' => $staff['id']])) ?>" class="btn btn-sm btn-outline">전체 이력 보기</a>
    </div>
    <?php if (!$bonuses): ?>
      <div class="card-body"><div class="empty compact"><div class="empty-title">해당 반기 보너스 내역이 없습니다.</div></div></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data compact">
        <thead>
          <tr><th>프로젝트</th><th class="num">산정 대상 금액</th><th>산정 기준</th><th class="num">산정액</th><th class="num">지급액</th><th>지급일</th><th>상태</th><th>메모</th></tr>
        </thead>
        <tbody>
          <?php foreach ($bonuses as $b): ?>
            <tr<?= $b['pay_status'] === 'cancelled' ? ' class="bonus-cancelled"' : '' ?>>
              <td><?= $b['project_id'] ? e($b['project_name'] ?? ('#' . $b['project_id'])) : '<span class="muted">-</span>' ?></td>
              <td class="num mono"><?= moneyCell((float) $b['base_amount']) ?></td>
              <td><?= e($b['calc_basis'] ?? '-') ?></td>
              <td class="num mono"><?= moneyCell((float) $b['calc_amount']) ?></td>
              <td class="num mono"><?= moneyCell((float) $b['paid_amount']) ?></td>
              <td><?= $b['pay_date'] ? e(fmtdate($b['pay_date'])) : '-' ?></td>
              <td><span class="badge <?= e($payStatusBadge[$b['pay_status']] ?? 'badge-info') ?>"><?= e($payStatusLabels[$b['pay_status']] ?? $b['pay_status']) ?></span></td>
              <td class="bonus-memo" title="<?= e($b['memo'] ?? '') ?>"><?= e(Util::truncate($b['memo'] ?? '-', 20)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
