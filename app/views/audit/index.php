<?php
/** @var array $rows @var array $pg @var int $userId @var string $action @var string $from @var string $to
 *  @var array $users @var array $actions
 */
$base = ['user_id' => $userId ?: '', 'action' => $action, 'from' => $from, 'to' => $to];
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">감사 로그</h1>
      <div class="page-sub">전체 <?= number_format($pg['total']) ?>건</div>
    </div>
  </div>

  <form class="toolbar" method="get" action="<?= e(url('audit.index')) ?>">
    <input type="hidden" name="r" value="audit.index">
    <select name="user_id" class="select">
      <option value="">전체 사용자</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['login_id']) ?>)</option>
      <?php endforeach; ?>
    </select>
    <select name="action" class="select">
      <option value="">전체 액션</option>
      <?php foreach ($actions as $a): ?>
        <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="from" class="input" value="<?= e($from) ?>" title="시작일">
    <input type="date" name="to" class="input" value="<?= e($to) ?>" title="종료일">
    <button type="submit" class="btn btn-outline">검색</button>
    <?php if ($userId || $action || $from || $to): ?>
      <a href="<?= e(url('audit.index')) ?>" class="btn btn-ghost">초기화</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-icon">🛡️</div>
      <div class="empty-title">조건에 맞는 감사 로그가 없습니다.</div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>시간</th>
            <th>사용자</th>
            <th>액션</th>
            <th>대상</th>
            <th>IP</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="nowrap"><?= e($r['created_at']) ?></td>
              <td><?= $r['user_name'] ? e($r['user_name']) . ' (' . e($r['user_login_id']) . ')' : '<span class="muted">시스템</span>' ?></td>
              <td><span class="badge badge-info"><?= e($r['action']) ?></span></td>
              <td><?= e($r['entity']) ?><?= $r['entity_id'] !== null ? ' #' . (int) $r['entity_id'] : '' ?></td>
              <td class="nowrap"><?= e($r['ip'] ?: '-') ?></td>
              <td>
                <?php if ($r['before_json'] || $r['after_json']): ?>
                  <details>
                    <summary style="cursor:pointer;color:var(--brand);font-size:12px">상세</summary>
                    <?php if ($r['before_json']): ?>
                      <div class="field-hint" style="margin-top:6px">변경 전</div>
                      <pre style="white-space:pre-wrap;font-size:11.5px;background:var(--line-2);padding:8px;border-radius:4px;max-width:360px;overflow-x:auto"><?= e($r['before_json']) ?></pre>
                    <?php endif; ?>
                    <?php if ($r['after_json']): ?>
                      <div class="field-hint">변경 후</div>
                      <pre style="white-space:pre-wrap;font-size:11.5px;background:var(--line-2);padding:8px;border-radius:4px;max-width:360px;overflow-x:auto"><?= e($r['after_json']) ?></pre>
                    <?php endif; ?>
                  </details>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <span class="page-info"><?= $pg['from'] ?>-<?= $pg['to'] ?> / <?= number_format($pg['total']) ?>건</span>
      <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
        <a href="<?= e(url('audit.index', array_merge($base, ['page' => $i]))) ?>" class="<?= $i === $pg['page'] ? 'cur' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
