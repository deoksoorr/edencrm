<?php
/**
 * 업무 권한 매트릭스 (직원 등록·수정 폼 내부) — 최고운영자 전용.
 * 표시명은 Perm::resources() 가 제공하고 체크박스 name 은 고정 리소스 키를 쓴다.
 * 메뉴명이 바뀌어도 저장된 권한이 깨지지 않는다.
 *
 * @var array      $permResources  resource_key => [section, label, order, read_only?]
 * @var array      $permSections   section => 표시명
 * @var array      $permCurrent    resource_key => [can_read, can_write, can_delete]
 * @var array|null $staff
 * @var bool       $permTargetIsSuper  대상이 최고운영자인가
 */
?>
<div class="perm-block" id="permBlock">
  <div class="perm-head">
    <h2 class="perm-title">업무 권한 설정</h2>
    <?php if (!$permTargetIsSuper): ?>
    <div class="perm-bulk">
      <button type="button" class="btn btn-sm btn-outline" data-perm-all="1">전체 선택</button>
      <button type="button" class="btn btn-sm btn-ghost" data-perm-all="0">전체 해제</button>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($permTargetIsSuper): ?>
    <div class="perm-notice">
      최고운영자는 모든 업무 기능·분석·관리 권한을 항상 보유합니다. 개별 권한 설정 대상이 아닙니다.
    </div>
  <?php else: ?>
    <p class="perm-hint">
      쓰기 또는 삭제를 선택하면 읽기가 자동으로 켜집니다. 읽기를 끄면 쓰기·삭제도 함께 꺼집니다.
      <strong>삭제 권한은 휴지통으로 옮기는 것까지만 허용</strong>하며, 휴지통의 복원·완전삭제는 최고운영자만 실행할 수 있습니다.
    </p>

    <?php foreach ($permSections as $sectionKey => $sectionLabel):
      $rows = array_filter($permResources, fn($r) => $r['section'] === $sectionKey);
      uasort($rows, fn($a, $b) => $a['order'] <=> $b['order']);
      if (!$rows) { continue; }
    ?>
    <section class="perm-section" data-perm-section="<?= e($sectionKey) ?>">
      <div class="perm-section-head">
        <h3 class="perm-section-title"><?= e($sectionLabel) ?></h3>
        <button type="button" class="btn btn-sm btn-ghost" data-perm-section-all="<?= e($sectionKey) ?>">영역 전체</button>
      </div>

      <table class="perm-table">
        <thead>
          <tr>
            <th scope="col" class="perm-col-name">기능명</th>
            <th scope="col">읽기</th>
            <th scope="col">쓰기</th>
            <th scope="col">삭제</th>
            <th scope="col" class="perm-col-row"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $key => $def):
          $cur      = $permCurrent[$key] ?? ['can_read' => 0, 'can_write' => 0, 'can_delete' => 0];
          $readOnly = !empty($def['read_only']);
        ?>
          <tr data-perm-row="<?= e($key) ?>">
            <th scope="row" class="perm-name">
              <?= e($def['label']) ?>
              <?php if (!empty($def['note'])): ?>
                <span class="perm-row-note"><?= e($def['note']) ?></span>
              <?php endif; ?>
            </th>
            <?php foreach (['read', 'write', 'delete'] as $act):
              $disabled = $readOnly && $act !== 'read';
              $checked  = !$disabled && !empty($cur['can_' . $act]);
            ?>
            <td data-label="<?= $act === 'read' ? '읽기' : ($act === 'write' ? '쓰기' : '삭제') ?>">
              <?php if ($disabled): ?>
                <span class="perm-na" title="이 기능은 조회 전용입니다">–</span>
              <?php else: ?>
                <label class="perm-check">
                  <input type="checkbox"
                         name="perms[<?= e($key) ?>][<?= $act ?>]"
                         value="1"
                         data-perm-act="<?= $act ?>"
                         <?= $checked ? 'checked' : '' ?>>
                  <span class="sr-only"><?= e($def['label']) ?> <?= $act === 'read' ? '읽기' : ($act === 'write' ? '쓰기' : '삭제') ?></span>
                </label>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td class="perm-col-row">
              <button type="button" class="btn btn-xs btn-ghost" data-perm-row-all="<?= e($key) ?>">행 전체</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th scope="row" class="perm-name perm-foot-label">열 전체</th>
            <?php foreach (['read', 'write', 'delete'] as $act): ?>
            <td><button type="button" class="btn btn-xs btn-ghost"
                        data-perm-col="<?= $act ?>" data-perm-col-section="<?= e($sectionKey) ?>">선택</button></td>
            <?php endforeach; ?>
            <td class="perm-col-row"></td>
          </tr>
        </tfoot>
      </table>
    </section>
    <?php endforeach; ?>

    <p class="perm-foot-note">
      분석·관리 영역(정산, 전 직원 성과, 출근 통계, 보너스 관리, 직원 관리, 시스템 설정, 감사 로그)과
      휴지통 복원·완전삭제는 최고운영자 전용이므로 이 화면에서 부여할 수 없습니다.
    </p>
  <?php endif; ?>
</div>
