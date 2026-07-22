<?php
/** @var array $rows @var array $pg @var array $q @var bool $viewAll */
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">작업일지</div>
      <div class="page-sub"><?= $viewAll ? '전체 작업일지' : '내 작성분 · 내 배정 프로젝트' ?></div>
    </div>
    <div class="page-actions">
      <?php if (can('worklog.create')): ?>
        <a href="<?= e(url('worklogs.form')) ?>" class="btn btn-primary">+ 작업일지 작성</a>
      <?php endif; ?>
    </div>
  </div>

  <form class="toolbar" method="get">
    <input type="hidden" name="r" value="worklogs.index">
    <input class="input search" type="text" name="project" placeholder="프로젝트 검색" value="<?= e($q['project']) ?>">
    <input class="input" type="text" name="author" placeholder="작성자 검색" value="<?= e($q['author']) ?>">
    <input class="input" type="date" name="date_from" value="<?= e($q['date_from']) ?>">
    <input class="input" type="date" name="date_to" value="<?= e($q['date_to']) ?>">
    <button class="btn btn-outline" type="submit">검색</button>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty-icon">📋</div>
      <div class="empty-title">작업일지가 없습니다</div>
      <?php if (can('worklog.create')): ?><a class="btn btn-primary" href="<?= e(url('worklogs.form')) ?>">작업일지 작성</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>작업일</th><th>프로젝트</th><th>작성자</th><th>진행공정</th><th class="num">진행률</th><th>관리자확인</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr onclick="location.href='<?= e(url('worklogs.show', ['id' => $r['id']])) ?>'" style="cursor:pointer">
            <td><?= e(fmtdate($r['work_date'])) ?></td>
            <td class="wrap"><?= e($r['project_no']) ?> · <?= e($r['project_name']) ?></td>
            <td><?= e($r['author_name']) ?></td>
            <td><?= e($r['stage_name'] ?? '-') ?></td>
            <td class="num"><?= $r['progress'] !== null ? pct((float) $r['progress']) : '-' ?></td>
            <td>
              <?php if ($r['confirmed_by']): ?>
                <span class="badge badge-ok">확인완료</span>
              <?php else: ?>
                <span class="badge badge-warn">미확인</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="pagination">
      <span class="page-info"><?= (int) $pg['from'] ?>-<?= (int) $pg['to'] ?> / 총 <?= (int) $pg['total'] ?>건</span>
      <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
        <a class="<?= $i === $pg['page'] ? 'cur' : '' ?>" href="<?= e(url('worklogs.index', array_merge($q, ['page' => $i]))) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
