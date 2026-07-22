<?php
/** @var array $rows @var array $pg @var string $q @var int $departmentId @var int $roleId @var string $status
 *  @var array $departments @var array $roles */
$statusLabels = ['active' => '재직', 'inactive' => '비활성'];
$roleLabels = ['super_admin' => '슈퍼관리자', 'sales_manager' => '영업관리자', 'site_manager' => '현장관리자', 'staff' => '직원', 'accountant' => '회계'];
$baseParams = ['q' => $q, 'department_id' => $departmentId ?: '', 'role_id' => $roleId ?: '', 'status' => $status];
?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title">직원 관리</h1>
    <div class="page-sub">전체 <?= (int) $pg['total'] ?>명</div>
  </div>

  <form method="get" class="toolbar" action="<?= e($GLOBALS['config']['BASE_URL']) ?>/index.php">
    <input type="hidden" name="r" value="staff.index">
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
    <div class="toolbar-spacer"></div>
    <?php if (can('staff.manage')): ?>
      <a href="<?= e(url('staff.form')) ?>" class="btn btn-primary">+ 직원 등록</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-icon">👤</div>
      <div class="empty-title">조건에 맞는 직원이 없습니다.</div>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>이름</th><th>아이디</th><th>부서</th><th>직급</th><th>역할</th><th>상태</th><th>마지막 로그인</th>
          <?php if (can('staff.manage')): ?><th>관리</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr data-staff-row="<?= (int) $row['id'] ?>">
            <td><a href="<?= e(url('staff.show', ['id' => $row['id']])) ?>"><?= e($row['name']) ?></a></td>
            <td><?= e($row['login_id']) ?></td>
            <td><?= e($row['department_name'] ?? '-') ?></td>
            <td><?= e($row['position'] ?? '-') ?></td>
            <td><?= e($roleLabels[$row['role_key']] ?? $row['role_key']) ?></td>
            <td>
              <span class="badge <?= $row['status'] === 'active' ? 'badge-ok' : 'badge-danger' ?>" data-status-badge>
                <?= e($statusLabels[$row['status']] ?? $row['status']) ?>
              </span>
            </td>
            <td><?= $row['last_login_at'] ? e(fmtdate($row['last_login_at'], 'Y-m-d H:i')) : '-' ?></td>
            <?php if (can('staff.manage')): ?>
            <td>
              <div class="btn-group">
                <button type="button" class="btn btn-sm btn-outline" data-act="resetpw" data-id="<?= (int) $row['id'] ?>">비번초기화</button>
                <button type="button" class="btn btn-sm <?= $row['status'] === 'active' ? 'btn-danger' : 'btn-outline' ?>" data-act="toggle" data-id="<?= (int) $row['id'] ?>" data-cur="<?= e($row['status']) ?>">
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

  <div class="pagination">
    <span class="page-info"><?= (int) $pg['from'] ?>-<?= (int) $pg['to'] ?> / <?= (int) $pg['total'] ?>건</span>
    <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
      <a class="<?= $i === $pg['page'] ? 'cur' : '' ?>" href="<?= e(url('staff.index', $baseParams + ['page' => $i])) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
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
              '<p style="font-size:20px;font-weight:700;letter-spacing:1px;background:var(--line-2);padding:10px 14px;border-radius:6px;text-align:center">' + data.temp_password + '</p>',
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
