<?php
/** @var array $users @var array $projects @var array $slots @var bool $canManage @var bool $canManageAll */
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">일정</div>
      <div class="page-sub">월 캘린더 · 직원별 슬롯 타임라인(오전·오후·야간)</div>
    </div>
    <div class="page-actions">
      <?php if ($canManage): ?>
        <button class="btn btn-primary" id="btnNewSchedule">+ 새 일정</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="tabs" id="viewTabs">
    <div class="tab active" data-view="month">월 캘린더</div>
    <div class="tab" data-view="timeline">직원별 타임라인</div>
  </div>

  <div class="toolbar">
    <?php if ($canManageAll): ?>
      <select class="select" id="fUser">
        <option value="">전체 직원</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select class="select" id="fProject">
      <option value="">전체 프로젝트</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= (int) $p['id'] ?>"><?= e($p['project_no']) ?> · <?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline btn-sm" type="button" id="btnPrev">‹ 이전</button>
    <button class="btn btn-outline btn-sm" type="button" id="btnToday">오늘</button>
    <button class="btn btn-outline btn-sm" type="button" id="btnNext">다음 ›</button>
    <span class="page-info" id="curRangeLabel"></span>
  </div>

  <div id="monthView" class="tab-panel active">
    <div class="calendar" id="calRoot"><div class="loading-row"><span class="spinner spinner-dark"></span> 불러오는 중…</div></div>
  </div>
  <div id="timelineView" class="tab-panel">
    <div class="sched" id="schedRoot"><div class="loading-row"><span class="spinner spinner-dark"></span> 불러오는 중…</div></div>
  </div>
</div>

<?php
$initData = [
    'canManage'    => $canManage,
    'canManageAll' => $canManageAll,
    'meId'         => (int) Auth::id(),
    'users'        => $users,
    'slots'        => $slots,
];
?>
<script>window.SCHED_INIT = <?= json_encode($initData, JSON_UNESCAPED_UNICODE) ?>;</script>
