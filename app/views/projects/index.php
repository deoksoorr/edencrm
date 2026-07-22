<?php
/** @var array $rows @var array $pg @var string $q @var string $status @var int $managerId
 *  @var string $workType @var bool $delayed @var string $sort @var string $dir
 *  @var array $managers @var array $workTypes @var array $statuses
 */
$today = date('Y-m-d');
$base = [
    'q' => $q, 'status' => $status, 'manager_id' => $managerId ?: '', 'work_type' => $workType,
    'delayed' => $delayed ? '1' : '', 'sort' => $sort, 'dir' => $dir,
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
      <?php if (can('project.manage')): ?>
        <a href="<?= e(url('projects.form')) ?>" class="btn btn-primary">+ 새 프로젝트</a>
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
    <label class="flex items-center gap-8" style="font-size:13px;color:var(--ink-2)">
      <input type="checkbox" name="delayed" value="1" <?= $delayed ? 'checked' : '' ?>> 지연만 보기
    </label>
    <button type="submit" class="btn btn-outline">검색</button>
    <?php if ($q || $status || $managerId || $workType || $delayed): ?>
      <a href="<?= e(url('projects.index')) ?>" class="btn btn-ghost">초기화</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-icon">📁</div>
      <div class="empty-title">조건에 맞는 프로젝트가 없습니다.</div>
      <?php if (can('project.manage')): ?>
        <a href="<?= e(url('projects.form')) ?>" class="btn btn-primary">새 프로젝트 등록</a>
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
            <th class="num"><a href="<?= e(projSortUrl('contract_amount', $sort, $dir, $base)) ?>">계약금액<?= projSortArrow('contract_amount', $sort, $dir) ?></a></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $p): ?>
            <?php
              $isDelayed = !empty($p['end_date']) && $p['end_date'] < $today && $p['status'] !== 'completed';
              $badgeClass = ['preparing' => 'badge-muted', 'in_progress' => 'badge-info', 'paused' => 'badge-warn', 'completed' => 'badge-ok'][$p['status']] ?? 'badge';
              $progress = (int) $p['progress'];
              $barClass = $progress >= 100 ? 'ok' : ($isDelayed ? 'danger' : '');
            ?>
            <tr>
              <td class="nowrap"><a href="<?= e(url('projects.show', ['id' => $p['id']])) ?>"><?= e($p['project_no']) ?></a></td>
              <td class="wrap"><a href="<?= e(url('projects.show', ['id' => $p['id']])) ?>" title="<?= e($p['name']) ?>"><?= e(Util::truncate($p['name'], 30)) ?></a></td>
              <td><?= e($p['customer_name']) ?></td>
              <td><span class="badge <?= $badgeClass ?>"><?= e($statuses[$p['status']] ?? $p['status']) ?></span></td>
              <td>
                <div class="progress"><div class="progress-bar <?= $barClass ?>" style="width:<?= $progress ?>%"></div></div>
                <div class="progress-label"><?= $progress ?>%</div>
              </td>
              <td><?= e($p['site_manager_name'] ?? '-') ?></td>
              <td class="nowrap"><?= fmtdate($p['start_date']) ?></td>
              <td class="nowrap <?= $isDelayed ? 'text-danger' : '' ?>"><?= fmtdate($p['end_date']) ?><?= $isDelayed ? ' (지연)' : '' ?></td>
              <td class="num"><?= money((float) $p['contract_amount']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <span class="page-info"><?= $pg['from'] ?>-<?= $pg['to'] ?> / <?= number_format($pg['total']) ?>건</span>
      <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
        <a href="<?= e(url('projects.index', array_merge($base, ['page' => $i]))) ?>" class="<?= $i === $pg['page'] ? 'cur' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
