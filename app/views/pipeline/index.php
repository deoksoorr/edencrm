<?php
/**
 * 영업 파이프라인. 상단 그룹 탭(클라이언트 전환) + 서버측 필터/검색/빠른필터 + 우측 상세 슬라이드 패널.
 * @var array $columns,$filters,$groups,$tabs,$salesUsers,$workTypes
 * @var int $shown,$total @var bool $fullAccess,$canManage
 */
$f = $filters;
?>
<div class="page page-wide">
  <div class="page-head">
    <div>
      <div class="page-title">영업 파이프라인</div>
      <div class="page-sub"><?= $fullAccess ? '전체 영업기회' : '내 담당 영업기회' ?></div>
    </div>
    <div class="page-actions">
      <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" id="btnNewLead">+ 신규 영업기회</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="pl-toolbar">
    <!-- 그룹 탭(전체/신규·상담/현장·견적/계약/보류·종료) -->
    <div class="pl-tabs" id="plTabs">
      <?php foreach ($tabs as $key => $tab): ?>
        <button type="button" class="pl-tab<?= $f['tab'] === $key ? ' active' : '' ?>" data-tab="<?= e($key) ?>"
                data-groups="<?= e(implode(',', $tab['groups'])) ?>">
          <?= e($tab['label']) ?><span class="tcnt" data-tab-count="<?= e($key) ?>">0</span>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- 필터 -->
    <form class="pl-filterbar" id="pipelineFilter" autocomplete="off">
      <input type="hidden" name="tab" value="<?= e($f['tab']) ?>">
      <input type="text" name="q" class="input search" placeholder="고객·업체·연락처·주소·공사종류 검색" value="<?= e($f['q']) ?>">
      <?php if ($fullAccess): ?>
        <select name="sales_user_id" class="select">
          <option value="">담당영업 전체</option>
          <?php foreach ($salesUsers as $su): ?>
            <option value="<?= (int) $su['id'] ?>" <?= (int) $f['sales_user_id'] === (int) $su['id'] ? 'selected' : '' ?>><?= e($su['name']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select name="importance" class="select">
        <option value="">중요도 전체</option>
        <option value="high" <?= $f['importance'] === 'high' ? 'selected' : '' ?>>높음</option>
        <option value="mid" <?= $f['importance'] === 'mid' ? 'selected' : '' ?>>보통</option>
        <option value="low" <?= $f['importance'] === 'low' ? 'selected' : '' ?>>낮음</option>
      </select>
      <?php if ($workTypes): ?>
        <select name="work_type" class="select">
          <option value="">공사종류 전체</option>
          <?php foreach ($workTypes as $wt): ?>
            <option value="<?= e($wt) ?>" <?= $f['work_type'] === $wt ? 'selected' : '' ?>><?= e($wt) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <input type="hidden" name="quick" id="plQuick" value="<?= e($f['quick']) ?>">
      <button type="submit" class="btn btn-outline">검색</button>
      <span class="pl-count" id="plCount">표시 <b><?= (int) $shown ?></b> / 전체 <?= (int) $total ?>건</span>
    </form>

    <!-- 빠른 필터 -->
    <div class="pl-quick" id="plQuickChips">
      <button type="button" class="qf" data-quick="today">오늘 연락 필요</button>
      <button type="button" class="qf" data-quick="overdue">연락 지남</button>
      <button type="button" class="qf" data-quick="stale">3일+ 미접촉</button>
      <button type="button" class="qf" data-quick="highvalue">고액 견적</button>
      <button type="button" class="qf" data-quick="closing">계약 임박</button>
      <button type="button" class="qf" data-quick="longstay">장기 체류</button>
      <button type="button" class="qf" data-quick="unassigned">담당 미배정</button>
    </div>

    <!-- 적용된 필터 칩 -->
    <div class="pl-applied" id="plApplied"></div>
  </div>

  <div class="kanban" id="kanbanBoard">
    <?php View::partial('pipeline/board', ['columns' => $columns, 'canManage' => $canManage]); ?>
  </div>
</div>

<!-- 우측 상세 슬라이드 패널 -->
<div class="drawer-backdrop" id="leadDrawerBackdrop"></div>
<aside class="drawer" id="leadDrawer" aria-hidden="true"></aside>

<?php
$inlineScript = 'window.PIPELINE_CONFIG = ' . json_encode([
    'canManage' => $canManage,
    'fullAccess' => $fullAccess,
    'initialTab' => $f['tab'],
], JSON_UNESCAPED_UNICODE) . ';';
?>
