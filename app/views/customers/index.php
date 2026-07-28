<?php
/** @var array $rows @var array $pg @var array $salesUsers @var array $sources
 *  @var string $q @var string $status @var string $source @var ?int $salesUserId @var string $sort */
$typeLabel = ['individual' => '개인', 'company' => '법인'];
$statusBadge = ['active' => 'badge-ok', 'inactive' => 'badge-muted', 'blacklist' => 'badge-danger'];
$statusLabel = ['active' => '활성', 'inactive' => '비활성', 'blacklist' => '블랙리스트'];

// 현재 필터(페이지 제외) — 페이지네이션·CSV 링크에 재사용
$filterParams = array_filter([
    'q' => $q, 'status' => $status, 'source' => $source,
    'sales_user_id' => $salesUserId, 'sort' => $sort,
], fn($v) => $v !== null && $v !== '');
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">고객 CRM</div>
      <div class="page-sub">총 <?= (int) $pg['total'] ?>명</div>
    </div>
    <div class="page-actions">
      <?php if (can('customer.export')): ?>
        <a class="btn btn-outline" href="<?= e(url('customers.export', $filterParams)) ?>">CSV 다운로드</a>
      <?php endif; ?>
      <?php if (can('customer.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('customers.form')) ?>">+ 고객 등록</a>
      <?php endif; ?>
    </div>
  </div>

  <form class="toolbar" method="get" action="<?= e(url('customers.index')) ?>">
    <input type="hidden" name="r" value="customers.index">
    <input type="text" name="q" class="input search" placeholder="고객명·업체명·담당자·연락처·현장주소 검색" value="<?= e($q) ?>">
    <select name="status" class="select">
      <option value="">전체 상태</option>
      <?php foreach ($statusLabel as $k => $lbl): ?>
        <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="source" class="select">
      <option value="">전체 유입경로</option>
      <?php foreach ($sources as $s): ?>
        <option value="<?= e($s) ?>" <?= $source === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sales_user_id" class="select">
      <option value="">전체 담당영업</option>
      <?php foreach ($salesUsers as $su): ?>
        <option value="<?= (int) $su['id'] ?>" <?= $salesUserId === (int) $su['id'] ? 'selected' : '' ?>><?= e($su['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort" class="select">
      <option value="created_at" <?= $sort === 'created_at' ? 'selected' : '' ?>>등록일순</option>
      <option value="last_consult" <?= $sort === 'last_consult' ? 'selected' : '' ?>>최근상담일순</option>
      <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>이름순</option>
    </select>
    <button type="submit" class="btn btn-outline">검색</button>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-icon">○</div>
      <div class="empty-title">조건에 맞는 고객이 없습니다.</div>
      <?php if (can('customer.manage')): ?><a class="btn btn-primary" href="<?= e(url('customers.form')) ?>">고객 등록</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>고객명</th><th>구분</th><th>연락처</th><th>담당영업</th><th>상태</th>
            <th>관심공사</th><th>최근상담일</th><th>다음연락예정일</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <a href="<?= e(url('customers.show', ['id' => $r['id']])) ?>"><?= e($r['name']) ?></a>
                <?php if ($r['company_name']): ?><div class="muted fs-11"><?= e($r['company_name']) ?></div><?php endif; ?>
              </td>
              <td>
                <span class="badge badge-info"><?= e($typeLabel[$r['type']] ?? $r['type']) ?></span>
                <?php if ((int) ($r['is_business'] ?? 0) === 1): ?>
                  <span class="badge <?= !empty($r['biz_license_file_id']) ? 'badge-ok' : 'badge-muted' ?>" title="사업자등록증 <?= !empty($r['biz_license_file_id']) ? '보유' : '미등록' ?>">등록증<?= !empty($r['biz_license_file_id']) ? '' : ' 없음' ?></span>
                <?php endif; ?>
              </td>
              <td><?= e($r['phone'] ?: '-') ?></td>
              <td><?= e($r['sales_user_name'] ?: '-') ?></td>
              <td><span class="badge <?= $statusBadge[$r['status']] ?? '' ?>"><?= e($statusLabel[$r['status']] ?? $r['status']) ?></span></td>
              <td class="wrap"><?= e(Util::truncate($r['interest_type'], 20)) ?></td>
              <td class="nowrap"><?= fmtdate($r['last_consult_date']) ?></td>
              <td class="nowrap"><?= fmtdate($r['next_contact_date']) ?></td>
              <td><a class="btn btn-sm btn-outline" href="<?= e(url('customers.show', ['id' => $r['id']])) ?>">상세</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php View::partial('partials/pager', [
        'pg'  => $pg,
        'url' => fn (int $p): string => url('customers.index', $filterParams + ['page' => $p]),
    ]); ?>
  <?php endif; ?>
</div>
