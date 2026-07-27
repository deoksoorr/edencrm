<?php
/** @var array $pipelineStages @var array $processStages
 *  @var string $processType @var array $processTypeTabs @var array $processGroups @var array $groupMeta @var int $processNextSort
 *  R10: 도장/인테리어/공통이 독립 관리 페이지(상호 이동 버튼) + 페이지 내부 6그룹(대기/착공준비/시공/마무리/하자보수/종결) 필터 탭.
 *  공통 3단계는 유형 페이지에 잠금 표시로만 노출(편집은 공통 페이지 전용 — 유형 간 교차 영향 차단).
 */
$isCommonTab = $processType === 'common';
?>
<?php /* 화면 전용 <style> 은 app.css 의 r3-formscss 블록(.stage-*)으로 승격됨 */ ?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title"><?= e($title) ?></h1>
      <div class="page-sub"><?= $isCommonTab
          ? '대기중·하자보수·전체완료 — 양쪽 보드가 공유하는 단계입니다. 이름 변경은 도장·인테리어 모두에 적용됩니다.'
          : '이 페이지의 수정은 ' . e($processTypeTabs[$processType]) . ' 공정에만 적용됩니다. (인접 유형에 영향 없음)' ?></div>
    </div>
    <div class="page-actions">
      <?php foreach ($processTypeTabs as $tk => $tl): if ($tk === $processType) { continue; } ?>
        <a href="<?= e(url('settings.stages', ['type' => $tk])) ?>#process" class="btn btn-outline"><?= e($tl) ?><?= $tk === 'common' ? ' 단계' : ' 공정' ?> 관리</a>
      <?php endforeach; ?>
      <a href="<?= e(url('settings.index')) ?>" class="btn btn-ghost">시스템 설정으로</a>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">영업 단계 (pipeline_stages)</div></div>
    <div class="card-body">
      <?php foreach ($pipelineStages as $i => $s):
        $prev = $pipelineStages[$i - 1] ?? null;
        $next = $pipelineStages[$i + 1] ?? null;
      ?>
        <div class="stage-row">
          <div class="stage-order">
            <form method="post" action="<?= e(url('settings.stage.save')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="kind" value="pipeline">
              <input type="hidden" name="sort_only" value="1">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="hidden" name="sort_order" value="<?= $prev ? (int) $prev['sort_order'] : (int) $s['sort_order'] ?>">
              <?php if ($prev): ?>
                <input type="hidden" name="swap_id" value="<?= (int) $prev['id'] ?>">
                <input type="hidden" name="swap_sort_order" value="<?= (int) $s['sort_order'] ?>">
              <?php endif; ?>
              <button type="submit" class="btn btn-ghost btn-sm" <?= $prev ? '' : 'disabled' ?>>▲</button>
            </form>
            <form method="post" action="<?= e(url('settings.stage.save')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="kind" value="pipeline">
              <input type="hidden" name="sort_only" value="1">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="hidden" name="sort_order" value="<?= $next ? (int) $next['sort_order'] : (int) $s['sort_order'] ?>">
              <?php if ($next): ?>
                <input type="hidden" name="swap_id" value="<?= (int) $next['id'] ?>">
                <input type="hidden" name="swap_sort_order" value="<?= (int) $s['sort_order'] ?>">
              <?php endif; ?>
              <button type="submit" class="btn btn-ghost btn-sm" <?= $next ? '' : 'disabled' ?>>▼</button>
            </form>
          </div>
          <span class="muted nowrap">#<?= (int) $s['sort_order'] ?></span>

          <form method="post" action="<?= e(url('settings.stage.save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="pipeline">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <input type="hidden" name="sort_order" value="<?= (int) $s['sort_order'] ?>">
            <input type="text" name="name" class="input stage-name-input" value="<?= e($s['name']) ?>">
            <label class="check"><input type="checkbox" name="is_won" value="1" <?= $s['is_won'] ? 'checked' : '' ?>> 성공[WON]</label>
            <label class="check"><input type="checkbox" name="is_lost" value="1" <?= $s['is_lost'] ? 'checked' : '' ?>> 실주[LOST]</label>
            <input type="text" name="color" class="input stage-color-input" placeholder="#색상" value="<?= e($s['color'] ?? '') ?>">
            <button type="submit" class="btn btn-outline btn-sm">저장</button>
          </form>

          <form method="post" action="<?= e(url('settings.stage.delete')) ?>" onsubmit="return confirm('이 단계를 삭제하시겠습니까? 참조하는 영업기회가 있으면 삭제할 수 없습니다.');">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="pipeline">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button type="submit" class="btn btn-ghost-danger btn-sm">삭제</button>
          </form>
        </div>
      <?php endforeach; ?>

      <div class="stage-row stage-new">
        <form method="post" action="<?= e(url('settings.stage.save')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="pipeline">
          <input type="text" name="name" class="input stage-name-input" placeholder="새 영업 단계 이름" required>
          <input type="number" name="sort_order" class="input stage-sort-input" value="<?= count($pipelineStages) + 1 ?>" title="순서">
          <label class="check"><input type="checkbox" name="is_won" value="1"> 성공[WON]</label>
          <label class="check"><input type="checkbox" name="is_lost" value="1"> 실주[LOST]</label>
          <input type="text" name="color" class="input stage-color-input" placeholder="#색상">
          <button type="submit" class="btn btn-primary btn-sm">+ 추가</button>
        </form>
      </div>
    </div>
  </div>

  <div class="card" id="process">
    <div class="card-head">
      <div class="card-title">공정 단계 (process_stages)</div>
      <?php if (!$isCommonTab): ?>
        <button type="button" class="btn btn-outline btn-sm" id="procOrderSave" title="드래그로 바꾼 순서를 sort_order 1..N 으로 저장합니다">순서 저장</button>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (!$isCommonTab): ?>
      <?php /* R10: 6그룹 필터 탭 — 관리자 사용자단(보드) 단계 구분과 동일(대기/착공준비/시공/마무리/하자보수/종결) */ ?>
      <div class="stage-type-tabs" id="procGroupTabs">
        <a class="stage-type-tab active" href="#process" data-group="all">전체</a>
        <?php foreach ($groupMeta as $gk => $g): ?>
          <a class="stage-type-tab" href="#process" data-group="<?= e($gk) ?>" style="border-bottom-color:<?= e($g['color']) ?>"><?= e($g['label']) ?><span class="tcnt" data-group-cnt="<?= e($gk) ?>"></span></a>
        <?php endforeach; ?>
        <span class="muted stage-type-hint">드래그(⠿) 순서 변경은 \'전체\' 탭에서만 가능합니다(전체 흐름 순서 유지). 단계 이름을 바꿔도 공정 ID(stage_key)는 유지됩니다.</span>
      </div>
      <?php else: ?>
      <div class="stage-type-tabs">
        <span class="muted stage-type-hint">공통 예약 단계(대기중·하자보수·전체완료)는 양쪽 보드에서 공유되며 삭제·비활성·순서 변경이 불가합니다.</span>
      </div>
      <?php endif; ?>

      <div id="procStageList" data-type="<?= e($processType) ?>" data-sortable="<?= $isCommonTab ? '0' : '1' ?>">
      <?php
      // R10: ▲▼ 스왑은 같은 유형 행끼리만(공통 행이 목록에 섞여도 공통 sort 는 불변)
      $typeRows = array_values(array_filter($processStages, static fn ($x) => ($x['process_type'] ?? '') !== 'common'));
      $typeIndexById = [];
      foreach ($typeRows as $ti => $tr) { $typeIndexById[(int) $tr['id']] = $ti; }
      ?>
      <?php foreach ($processStages as $i => $s):
        $rowCommon = ($s['process_type'] ?? '') === 'common';
        $ti   = $rowCommon ? null : ($typeIndexById[(int) $s['id']] ?? null);
        $prev = $ti !== null ? ($typeRows[$ti - 1] ?? null) : null;
        $next = $ti !== null ? ($typeRows[$ti + 1] ?? null) : null;
        // R10: 유형 페이지의 공통 행은 표시 전용(잠금) — 편집은 공통 페이지에서만
        $rowLocked = $rowCommon && !$isCommonTab;
      ?>
        <div class="stage-row<?= $rowLocked ? ' stage-row-locked' : '' ?>" data-id="<?= (int) $s['id'] ?>" data-group="<?= e($s['stage_group'] ?? '') ?>" data-common="<?= $rowCommon ? '1' : '0' ?>">
          <?php if (!$rowCommon): ?>
            <span class="stage-drag" title="드래그로 순서 변경">⠿</span>
            <div class="stage-order">
              <form method="post" action="<?= e(url('settings.stage.save')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="process">
                <input type="hidden" name="rtype" value="<?= e($processType) ?>">
                <input type="hidden" name="sort_only" value="1">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <input type="hidden" name="sort_order" value="<?= $prev ? (int) $prev['sort_order'] : (int) $s['sort_order'] ?>">
                <?php if ($prev): ?>
                  <input type="hidden" name="swap_id" value="<?= (int) $prev['id'] ?>">
                  <input type="hidden" name="swap_sort_order" value="<?= (int) $s['sort_order'] ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn-ghost btn-sm" <?= $prev ? '' : 'disabled' ?>>▲</button>
              </form>
              <form method="post" action="<?= e(url('settings.stage.save')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="process">
                <input type="hidden" name="rtype" value="<?= e($processType) ?>">
                <input type="hidden" name="sort_only" value="1">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <input type="hidden" name="sort_order" value="<?= $next ? (int) $next['sort_order'] : (int) $s['sort_order'] ?>">
                <?php if ($next): ?>
                  <input type="hidden" name="swap_id" value="<?= (int) $next['id'] ?>">
                  <input type="hidden" name="swap_sort_order" value="<?= (int) $s['sort_order'] ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn-ghost btn-sm" <?= $next ? '' : 'disabled' ?>>▼</button>
              </form>
            </div>
          <?php else: ?>
            <span class="stage-badge-common" title="공통 예약 단계 — 순서(0/18/19)·유형·그룹·사용 여부 변경 불가">공통</span>
          <?php endif; ?>
          <span class="muted nowrap">#<?= (int) $s['sort_order'] ?></span>

          <?php if ($rowLocked): ?>
            <span class="stage-name-input"><b><?= e($s['name']) ?></b></span>
            <span class="muted nowrap stage-fixed-meta">공통 · <?= e($groupMeta[$s['stage_group'] ?? '']['label'] ?? '-') ?> · 양 유형 공유</span>
            <a href="<?= e(url('settings.stages', ['type' => 'common'])) ?>#process" class="btn btn-ghost btn-sm">공통 단계 관리에서 편집</a>
          <?php else: ?>
          <form method="post" action="<?= e(url('settings.stage.save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="process">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <input type="hidden" name="sort_order" value="<?= (int) $s['sort_order'] ?>">
            <input type="text" name="name" class="input stage-name-input" value="<?= e($s['name']) ?>">
            <?php if (!$rowCommon): ?>
              <select name="process_type" class="select stage-type-select" title="공사 유형">
                <option value="painting" <?= $s['process_type'] === 'painting' ? 'selected' : '' ?>>도장</option>
                <option value="interior" <?= $s['process_type'] === 'interior' ? 'selected' : '' ?>>인테리어</option>
              </select>
              <select name="stage_group" class="select stage-group-select" title="공정 그룹(보드 섹션)">
                <?php foreach ($processGroups as $gk => $gl): ?>
                  <option value="<?= e($gk) ?>" <?= ($s['stage_group'] ?? '') === $gk ? 'selected' : '' ?>><?= e($gl) ?></option>
                <?php endforeach; ?>
              </select>
              <label class="check" title="해제하면 보드·이동 대상에서 제외됩니다(이력·데이터는 보존)"><input type="checkbox" name="is_active" value="1" <?= !empty($s['is_active']) ? 'checked' : '' ?>> 사용</label>
            <?php else: ?>
              <span class="muted nowrap stage-fixed-meta" title="공통 예약 — 유형·그룹 변경 불가">공통 · <?= e(['waiting' => '대기중', 'defect' => '하자보수', 'complete' => '종결'][$s['stage_group'] ?? ''] ?? ($s['stage_group'] ?? '-')) ?></span>
            <?php endif; ?>
            <label class="check"><input type="checkbox" name="requires_confirm" value="1" <?= $s['requires_confirm'] ? 'checked' : '' ?>> 이동 시 확인 필요</label>
            <input type="text" name="color" class="input stage-color-input" placeholder="#색상" value="<?= e($s['color'] ?? '') ?>">
            <input type="text" name="description" class="input stage-desc-input" placeholder="설명(선택)" maxlength="255" value="<?= e($s['description'] ?? '') ?>">
            <button type="submit" class="btn btn-outline btn-sm">저장</button>
          </form>

          <?php if (!$rowCommon): ?>
            <form method="post" action="<?= e(url('settings.stage.delete')) ?>" onsubmit="return confirm('이 단계를 삭제하시겠습니까? 참조하는 프로젝트·공정 이력이 있으면 삭제할 수 없습니다(비활성화 사용).');">
              <?= csrf_field() ?>
              <input type="hidden" name="kind" value="process">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="btn btn-ghost-danger btn-sm">삭제</button>
            </form>
          <?php else: ?>
            <span class="muted nowrap stage-fixed-meta">삭제·비활성 불가</span>
          <?php endif; ?>
          <?php endif; // rowLocked — 유형 페이지의 공통 행은 표시 전용 ?>
        </div>
      <?php endforeach; ?>
      </div>

      <?php if (!$isCommonTab): ?>
      <div class="stage-row stage-new">
        <form method="post" action="<?= e(url('settings.stage.save')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="kind" value="process">
          <input type="hidden" name="process_type" value="<?= e($processType) ?>">
          <input type="text" name="name" class="input stage-name-input" placeholder="새 <?= e($processTypeTabs[$processType]) ?> 공정 단계 이름" required>
          <input type="number" name="sort_order" class="input stage-sort-input" value="<?= (int) $processNextSort ?>" title="순서(해당 유형 내 최대+1 기본)">
          <select name="stage_group" class="select stage-group-select" title="공정 그룹(보드 섹션)">
            <?php foreach ($processGroups as $gk => $gl): ?>
              <option value="<?= e($gk) ?>" <?= $gk === 'build' ? 'selected' : '' ?>><?= e($gl) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="check"><input type="checkbox" name="is_active" value="1" checked> 사용</label>
          <label class="check"><input type="checkbox" name="requires_confirm" value="1"> 이동 시 확인 필요</label>
          <input type="text" name="color" class="input stage-color-input" placeholder="#색상">
          <input type="text" name="description" class="input stage-desc-input" placeholder="설명(선택)" maxlength="255">
          <button type="submit" class="btn btn-primary btn-sm">+ 추가</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// R8-A: 공정 단계 드래그앤드롭 정렬 — Sortable(vendor)로 행 순서 변경 후 '순서 저장' 시
// settings.stage.save 에 sort_bulk=1 로 일괄 POST(같은 process_type 만 허용, 서버 재검증).
// app.js(body 끝)의 api()/toast 사용을 위해 DOMContentLoaded 로 지연(contracts/form.php 와 동일 패턴).
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var list = document.getElementById('procStageList');
  if (!list) return;
  var saveBtn = document.getElementById('procOrderSave');
  var dirty = false;

  // R10: 6그룹 필터 탭 — 행 data-group 기준 표시 필터. 그룹 필터 중에는 순서 변경 잠금(전체 흐름 순서 보호).
  var tabs = document.getElementById('procGroupTabs');
  if (tabs) {
    var rows = Array.prototype.slice.call(list.querySelectorAll('.stage-row[data-id]'));
    tabs.querySelectorAll('[data-group-cnt]').forEach(function (el) {
      var g = el.dataset.groupCnt;
      el.textContent = rows.filter(function (r) { return r.dataset.group === g; }).length;
    });
    tabs.addEventListener('click', function (e) {
      var a = e.target.closest('.stage-type-tab'); if (!a) return;
      e.preventDefault();
      var g = a.dataset.group;
      tabs.querySelectorAll('.stage-type-tab').forEach(function (t) { t.classList.toggle('active', t === a); });
      rows.forEach(function (r) { r.style.display = (g === 'all' || r.dataset.group === g) ? '' : 'none'; });
      var lockSort = g !== 'all';
      list.classList.toggle('stage-sort-locked', lockSort);
      if (saveBtn) { saveBtn.disabled = lockSort; saveBtn.title = lockSort ? "순서 변경은 '전체' 탭에서만 가능합니다" : ''; }
    });
  }

  var sortable = null;
  if (list.dataset.sortable === '1' && window.Sortable) {
    sortable = new Sortable(list, {
      animation: 150,
      handle: '.stage-drag',
      ghostClass: 'sortable-ghost',
      onEnd: function () {
        dirty = true;
        if (saveBtn) saveBtn.classList.add('btn-primary');
      },
    });
  }
  // 그룹 필터 중에는 드래그 자체를 비활성(부분 정렬 방지)
  if (tabs && sortable) {
    tabs.addEventListener('click', function () {
      sortable.option('disabled', list.classList.contains('stage-sort-locked'));
    });
  }

  if (saveBtn) saveBtn.addEventListener('click', function () {
    // R10: 공통 행(고정 sort)은 재부여 대상에서 제외 — 유형 행 순서만 전송
    var ids = Array.prototype.slice.call(list.querySelectorAll('.stage-row[data-id]'))
      .filter(function (r) { return r.dataset.common !== '1'; })
      .map(function (r) { return parseInt(r.dataset.id, 10); });
    if (!ids.length) { toast('저장할 단계가 없습니다.', 'warn'); return; }
    if (!dirty && !confirm('순서를 바꾸지 않았습니다. 현재 표시 순서대로 1..N 재부여할까요?')) return;
    saveBtn.disabled = true;
    api('settings.stage.save', { sort_bulk: 1, kind: 'process', order_json: JSON.stringify(ids) })
      .then(function (d) {
        toast('공정 순서가 저장되었습니다 (' + ((d && d.count) || ids.length) + '개 단계).', 'success');
        location.reload();
      })
      .catch(function (err) {
        toast((err && err.message) || '순서 저장에 실패했습니다.', 'error');
        saveBtn.disabled = false;
      });
  });
});
</script>
