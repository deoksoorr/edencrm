<?php
/** @var array $rows @var array $pg @var string $q @var int $departmentId @var int $roleId @var string $status
 *  @var array $departments @var array $roles
 *  @var int $vy @var int $vh @var int[] $years
 *  @var array|null $halfPerf   uid ⇒ {revenue, contrib, cost, done} — performance.view_all 없으면 null(미조회)
 *  @var array|null $bonusPaid  uid ⇒ 보너스 지급액 합 — 동일 조건 null */
$statusLabels = ['active' => '재직', 'inactive' => '비활성'];
$roleLabels = ['super_admin' => '슈퍼관리자', 'sales_manager' => '영업관리자', 'site_manager' => '현장관리자', 'staff' => '직원', 'accountant' => '회계'];
$baseParams = ['q' => $q, 'department_id' => $departmentId ?: '', 'role_id' => $roleId ?: '', 'status' => $status, 'year' => $vy, 'half' => $vh];
$canAmount = $halfPerf !== null; // 금액 열람 가능(performance.view_all) — 없으면 데이터 미전달, '-' 표시
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">직원 관리</h1>
      <div class="page-sub">전체 <?= (int) $pg['total'] ?>명 · 실적 요약: <?= e(Util::halfLabel($vy, $vh)) ?></div>
    </div>
    <div class="page-actions">
      <a href="<?= e(url('halfyear.index', ['year' => $vy, 'half' => $vh])) ?>" class="btn btn-outline">반기 보너스 지급 현황</a>
      <a href="<?= e(url('bonus.index', ['year' => $vy, 'half' => $vh])) ?>" class="btn btn-outline">보너스 지급 현황</a>
    </div>
  </div>

  <form method="get" class="toolbar" action="<?= e($GLOBALS['config']['BASE_URL']) ?>/index.php">
    <input type="hidden" name="r" value="staff.index">
    <select name="year" class="select" title="실적 요약 연도">
      <?php foreach ($years as $y): ?>
        <option value="<?= (int) $y ?>" <?= $y === $vy ? 'selected' : '' ?>><?= (int) $y ?>년</option>
      <?php endforeach; ?>
    </select>
    <select name="half" class="select" title="실적 요약 반기">
      <option value="1" <?= $vh === 1 ? 'selected' : '' ?>>상반기</option>
      <option value="2" <?= $vh === 2 ? 'selected' : '' ?>>하반기</option>
    </select>
    <input type="text" name="q" class="input search" placeholder="이름·아이디·이메일 검색" value="<?= e($q) ?>">
    <select name="department_id" class="select">
      <option value="">전체 부서</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= (int) $d['id'] ?>" <?= $departmentId === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="role_id" class="select">
      <option value="">전체 역할</option>
      <?php foreach ($roles as $r): ?>
        <option value="<?= (int) $r['id'] ?>" <?= $roleId === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="select">
      <option value="">전체 상태</option>
      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>재직</option>
      <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>비활성</option>
    </select>
    <button type="submit" class="btn btn-outline">검색</button>
    <a href="<?= e(url('staff.index')) ?>" class="btn btn-ghost">초기화</a>
    <div class="toolbar-spacer"></div>
    <?php if (can('staff.manage')): ?>
      <a href="<?= e(url('staff.form')) ?>" class="btn btn-primary">+ 직원 등록</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-title">조건에 맞는 직원이 없습니다.</div>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data staff-table">
      <thead>
        <tr>
          <th>이름</th><th>아이디</th><th>부서</th><th>직급</th><th>역할</th><th>상태</th>
          <th class="num" title="<?= e(Util::halfLabel($vy, $vh)) ?> 완료·정산 프로젝트 공급가 × 기여율">현장 매출</th>
          <th class="num" title="<?= e(Util::halfLabel($vy, $vh)) ?> (공급가 − 실제원가) × 기여율">순이익</th>
          <th class="num" title="<?= e(Util::halfLabel($vy, $vh)) ?> 보너스 지급액 합(취소 제외)">보너스 지급</th>
          <?php if (can('staff.manage')): ?><th>관리</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): $uid = (int) $row['id']; ?>
          <tr data-staff-row="<?= $uid ?>">
            <td><a href="<?= e(url('staff.show', ['id' => $uid, 'year' => $vy, 'half' => $vh])) ?>"><?= e($row['name']) ?></a></td>
            <td><?= e($row['login_id']) ?></td>
            <td><?= e($row['department_name'] ?? '-') ?></td>
            <td><?= e($row['position'] ?? '-') ?></td>
            <td><?= e($roleLabels[$row['role_key']] ?? $row['role_key']) ?></td>
            <td>
              <span class="badge <?= $row['status'] === 'active' ? 'badge-ok' : 'badge-danger' ?>" data-status-badge>
                <?= e($statusLabels[$row['status']] ?? $row['status']) ?>
              </span>
            </td>
            <?php if ($canAmount): $hp = $halfPerf[$uid] ?? null; ?>
              <td class="num mono"><?= moneyCell((float) ($hp['revenue'] ?? 0)) ?></td>
              <td class="num mono<?= ($hp['contrib'] ?? 0) < 0 ? ' text-danger' : '' ?>"><?= moneyCell((float) ($hp['contrib'] ?? 0)) ?></td>
              <td class="num mono"><?= moneyCell((float) ($bonusPaid[$uid] ?? 0)) ?></td>
            <?php else: ?>
              <td class="num muted">-</td><td class="num muted">-</td><td class="num muted">-</td>
            <?php endif; ?>
            <?php if (can('staff.manage')): ?>
            <td>
              <div class="btn-group staff-actions">
                <button type="button" class="btn btn-sm btn-outline" data-act="resetpw" data-id="<?= $uid ?>">비번초기화</button>
                <button type="button" class="btn btn-sm <?= $row['status'] === 'active' ? 'btn-danger' : 'btn-outline' ?>" data-act="toggle" data-id="<?= $uid ?>" data-cur="<?= e($row['status']) ?>">
                  <?= $row['status'] === 'active' ? '비활성화' : '활성화' ?>
                </button>
              </div>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php View::partial('partials/pager', [
      'pg'  => $pg,
      'url' => fn (int $p): string => url('staff.index', $baseParams + ['page' => $p]),
  ]); ?>
  <?php endif; ?>
</div>

<script>
document.addEventListener('click', async function (e) {
  const btn = e.target.closest('[data-act]');
  if (!btn) return;
  const id = btn.dataset.id;
  const act = btn.dataset.act;

  if (act === 'resetpw') {
    const ok = await EDEN.confirm('이 직원의 비밀번호를 초기화하시겠습니까? 새 임시 비밀번호가 발급됩니다.');
    if (!ok) return;
    try {
      const data = await api('staff.resetpw', { id });
      EDEN.modal({
        title: '임시 비밀번호 발급',
        body: '<p>새 임시 비밀번호가 발급되었습니다. 직원에게 안전하게 전달하세요.</p>' +
              '<p class="temp-pw">' + data.temp_password + '</p>',
        buttons: [{ label: '확인', class: 'btn-primary', onClick: (close) => close() }],
      });
    } catch (err) { toast(err.message, 'error'); }
  }

  if (act === 'toggle') {
    const willDeactivate = btn.dataset.cur === 'active';
    const ok = await EDEN.confirm(willDeactivate ? '이 직원 계정을 비활성화하시겠습니까?' : '이 직원 계정을 다시 활성화하시겠습니까?', { danger: willDeactivate });
    if (!ok) return;
    try {
      const data = await api('staff.toggle', { id });
      toast('상태가 변경되었습니다.', 'success');
      location.reload();
    } catch (err) { toast(err.message, 'error'); }
  }
});
</script>
