<?php
/** @var array $processStages @var string $processType @var array $processTypeTabs
 *  @var array $processGroups @var array $groupMeta
 *  R12: 영업 단계 편집 제거(자동 산정이라 무의미) · 공정 단계만 관리 · 순서는 드래그 자동 저장 · 가독성 개편.
 */
$isCommonTab = $processType === 'common';
?>
<style>
/* R12 공정 단계 관리 — 표형 그리드(가독성) */
.stage-grid { display:flex; flex-direction:column; gap:6px; }
.stage-thead, .stage-r2 { display:grid; align-items:center; gap:10px;
  grid-template-columns:26px minmax(160px,1fr) 130px 96px 60px 150px; }
.stage-thead.common, .stage-r2.common { grid-template-columns:26px minmax(160px,1fr) 120px 220px; }
.stage-thead { padding:6px 12px; font-size:12px; color:var(--muted,#6b7280); font-weight:600;
  border-bottom:1px solid var(--line,#e5e7eb); }
.stage-r2 { padding:8px 12px; border:1px solid var(--line,#e5e7eb); border-radius:8px; background:#fff; }
.stage-r2.inactive { opacity:.55; background:#fafafa; }
.stage-r2.locked { background:#f8fafc; border-style:dashed; }
.stage-r2.dragging { box-shadow:0 4px 14px rgba(0,0,0,.12); }
.stage-r2 form.s2-inline { display:contents; }
.s2-drag { cursor:grab; color:#9ca3af; text-align:center; user-select:none; font-size:15px; }
.s2-drag.static { cursor:default; }
.s2-name input { width:100%; }
.s2-act { display:flex; gap:6px; justify-content:flex-end; align-items:center; }
.stage-color-chip { display:inline-block; width:16px; height:16px; border-radius:4px; border:1px solid rgba(0,0,0,.12); vertical-align:middle; }
.stage-newrow { border-style:dashed; background:#fbfdff; }
/* 사용 토글 */
.sw { position:relative; display:inline-block; width:40px; height:22px; }
.sw input { display:none; }
.sw .sw-t { position:absolute; inset:0; background:#cbd5e1; border-radius:22px; transition:.15s; }
.sw .sw-t::before { content:""; position:absolute; width:16px; height:16px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.15s; }
.sw input:checked + .sw-t { background:#16a34a; }
.sw input:checked + .sw-t::before { transform:translateX(18px); }
.stage-sort-locked .s2-drag { cursor:not-allowed; opacity:.4; }
</style>

<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title"><?= e($title) ?></h1>
      <div class="page-sub"><?= $isCommonTab
          ? '대기중·하자보수·전체완료 — 도장·인테리어 보드가 함께 쓰는 공통 단계입니다. 이름 변경은 양쪽에 적용됩니다.'
          : '드래그(⠿)로 순서를 바꾸면 자동 저장됩니다. 이름을 바꿔도 공정 ID는 유지되어 이력이 보존됩니다.' ?></div>
    </div>
    <div class="page-actions">
      <?php foreach ($processTypeTabs as $tk => $tl): if ($tk === $processType) { continue; } ?>
        <a href="<?= e(url('settings.stages', ['type' => $tk])) ?>" class="btn btn-outline"><?= e($tl) ?><?= $tk === 'common' ? ' 단계' : ' 공정' ?></a>
      <?php endforeach; ?>
      <a href="<?= e(url('settings.index')) ?>" class="btn btn-ghost">시스템 설정으로</a>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-title">공정 단계</div>
      <span class="muted fs-12"><?= $isCommonTab
        ? '공통 예약 단계 — 순서·유형·그룹·사용 여부는 변경할 수 없습니다(이름·색만).'
        : '순서는 드래그(⠿)로 변경 → 자동 저장 · 그룹 탭에서는 조회만 가능' ?></span>
    </div>
    <div class="card-body">
      <?php if (!$isCommonTab): ?>
      <div class="stage-type-tabs" id="procGroupTabs">
        <a class="stage-type-tab active" href="#" data-group="all">전체</a>
        <?php foreach ($groupMeta as $gk => $g): ?>
          <a class="stage-type-tab" href="#" data-group="<?= e($gk) ?>" style="border-bottom-color:<?= e($g['color']) ?>"><?= e($g['label']) ?><span class="tcnt" data-group-cnt="<?= e($gk) ?>"></span></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="stage-thead<?= $isCommonTab ? ' common' : '' ?>">
        <span></span><span>단계명</span>
        <?php if (!$isCommonTab): ?><span>그룹</span><?php endif; ?>
        <span>색상</span>
        <?php if (!$isCommonTab): ?><span class="ta-c">사용</span><?php endif; ?>
        <span></span>
      </div>

      <div class="stage-grid" id="procStageList" data-type="<?= e($processType) ?>" data-sortable="<?= $isCommonTab ? '0' : '1' ?>">
      <?php foreach ($processStages as $s):
        $rowCommon = ($s['process_type'] ?? '') === 'common';
        $rowLocked = $rowCommon && !$isCommonTab;   // 유형 페이지의 공통 행은 표시 전용
        $inactive  = !$rowCommon && empty($s['is_active']);
      ?>
        <div class="stage-r2<?= $isCommonTab ? ' common' : '' ?><?= $rowLocked ? ' locked' : '' ?><?= $inactive ? ' inactive' : '' ?>"
             data-id="<?= (int) $s['id'] ?>" data-group="<?= e($s['stage_group'] ?? '') ?>" data-common="<?= $rowCommon ? '1' : '0' ?>">
          <?php if ($rowLocked): ?>
            <span class="s2-drag static">·</span>
            <span class="s2-name"><b><?= e($s['name']) ?></b> <span class="badge badge-muted fs-11">공통</span></span>
            <span><?= e($groupMeta[$s['stage_group'] ?? '']['label'] ?? '-') ?></span>
            <span><span class="stage-color-chip" style="background:<?= e($s['color'] ?: '#cbd5e1') ?>"></span></span>
            <span class="ta-c muted">-</span>
            <span class="s2-act"><a href="<?= e(url('settings.stages', ['type' => 'common'])) ?>" class="btn btn-ghost btn-sm">공통에서 편집</a></span>
          <?php elseif ($rowCommon): /* 공통 페이지: 이름·색만 편집 */ ?>
            <form class="s2-inline" method="post" action="<?= e(url('settings.stage.save')) ?>">
              <?= csrf_field() ?><input type="hidden" name="kind" value="process"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <span class="s2-drag static">·</span>
              <span class="s2-name"><input type="text" name="name" class="input" value="<?= e($s['name']) ?>"></span>
              <span><span class="badge badge-muted fs-11"><?= e(['waiting' => '대기중', 'defect' => '하자보수', 'complete' => '종결'][$s['stage_group'] ?? ''] ?? '-') ?></span></span>
              <span class="s2-act"><input type="text" name="color" class="input stage-color-input" placeholder="#색상" value="<?= e($s['color'] ?? '') ?>" style="width:90px"> <button class="btn btn-outline btn-sm">저장</button></span>
            </form>
          <?php else: /* 유형 전용 편집 행 */ ?>
            <form class="s2-inline" method="post" action="<?= e(url('settings.stage.save')) ?>">
              <?= csrf_field() ?><input type="hidden" name="kind" value="process"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <span class="s2-drag" title="드래그로 순서 변경">⠿</span>
              <span class="s2-name"><input type="text" name="name" class="input" value="<?= e($s['name']) ?>"></span>
              <span><select name="stage_group" class="select">
                <?php foreach ($processGroups as $gk => $gl): ?><option value="<?= e($gk) ?>" <?= ($s['stage_group'] ?? '') === $gk ? 'selected' : '' ?>><?= e($gl) ?></option><?php endforeach; ?>
              </select></span>
              <span><input type="text" name="color" class="input stage-color-input" placeholder="#색상" value="<?= e($s['color'] ?? '') ?>"></span>
              <span class="ta-c"><label class="sw" title="해제하면 보드에서 숨겨집니다(이력 보존)"><input type="checkbox" name="is_active" value="1" <?= !empty($s['is_active']) ? 'checked' : '' ?>><span class="sw-t"></span></label></span>
              <span class="s2-act">
                <button class="btn btn-outline btn-sm">저장</button>
                <button type="submit" class="btn btn-ghost-danger btn-sm" formaction="<?= e(url('settings.stage.delete')) ?>"
                        onclick="return confirm('이 단계를 삭제하시겠습니까? 참조하는 프로젝트·이력이 있으면 삭제할 수 없습니다(비활성화 권장).');">삭제</button>
              </span>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>

      <?php if (!$isCommonTab): ?>
      <form class="stage-r2 stage-newrow mt-8" method="post" action="<?= e(url('settings.stage.save')) ?>">
        <?= csrf_field() ?><input type="hidden" name="kind" value="process"><input type="hidden" name="process_type" value="<?= e($processType) ?>">
        <span class="s2-drag static">＋</span>
        <span class="s2-name"><input type="text" name="name" class="input" placeholder="새 <?= e($processTypeTabs[$processType]) ?> 공정 단계 이름 (맨 끝에 추가됩니다)" required></span>
        <span><select name="stage_group" class="select">
          <?php foreach ($processGroups as $gk => $gl): ?><option value="<?= e($gk) ?>" <?= $gk === 'build' ? 'selected' : '' ?>><?= e($gl) ?></option><?php endforeach; ?>
        </select></span>
        <span><input type="text" name="color" class="input stage-color-input" placeholder="#색상"></span>
        <span></span>
        <span class="s2-act"><button class="btn btn-primary btn-sm">+ 추가</button></span>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// R12: 공정 단계 드래그 정렬 — 드롭 시 자동 저장(sort_bulk, 같은 유형만). 그룹 탭에서는 조회만(정렬 잠금).
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var list = document.getElementById('procStageList');
  if (!list) return;

  // 그룹 필터 탭 — 행 data-group 기준 표시. 그룹 필터 중에는 드래그 잠금(전체 흐름 순서 보호).
  var tabs = document.getElementById('procGroupTabs');
  var rows = Array.prototype.slice.call(list.querySelectorAll('.stage-r2[data-id]'));
  if (tabs) {
    tabs.querySelectorAll('[data-group-cnt]').forEach(function (el) {
      var g = el.dataset.groupCnt;
      el.textContent = ' ' + rows.filter(function (r) { return r.dataset.group === g; }).length;
    });
    tabs.addEventListener('click', function (e) {
      var a = e.target.closest('.stage-type-tab'); if (!a) return;
      e.preventDefault();
      var g = a.dataset.group;
      tabs.querySelectorAll('.stage-type-tab').forEach(function (t) { t.classList.toggle('active', t === a); });
      rows.forEach(function (r) { r.style.display = (g === 'all' || r.dataset.group === g) ? '' : 'none'; });
      var lock = g !== 'all';
      list.classList.toggle('stage-sort-locked', lock);
      if (sortable) sortable.option('disabled', lock);
    });
  }

  var sortable = null;
  if (list.dataset.sortable === '1' && window.Sortable) {
    sortable = new Sortable(list, {
      animation: 150,
      handle: '.s2-drag',
      draggable: '.stage-r2[data-common="0"]', // 공통 행은 드래그 대상 제외(유형 페이지엔 없음)
      ghostClass: 'sortable-ghost',
      dragClass: 'dragging',
      onEnd: saveOrder,
    });
  }

  function saveOrder() {
    var ids = Array.prototype.slice.call(list.querySelectorAll('.stage-r2[data-id]'))
      .filter(function (r) { return r.dataset.common !== '1'; })
      .map(function (r) { return parseInt(r.dataset.id, 10); });
    if (!ids.length) return;
    api('settings.stage.save', { sort_bulk: 1, kind: 'process', order_json: JSON.stringify(ids) })
      .then(function (d) { toast('순서가 저장되었습니다 (' + ((d && d.count) || ids.length) + '개 단계).', 'success'); })
      .catch(function (err) {
        toast((err && err.message) || '순서 저장에 실패했습니다.', 'error');
        setTimeout(function () { location.reload(); }, 800); // 실패 시 서버 순서로 복원
      });
  }
});
</script>
