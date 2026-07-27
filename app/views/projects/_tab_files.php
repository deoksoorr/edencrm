<?php
/**
 * [사진·문서] 탭 — project_files 기반 현장 사진(photo)·첨부 문서(doc: 견적서·계약서·영수증 등)
 * + 기존 업로드 기능 재배치. projects/show.php 에서 include.
 */
?>
<div class="card pad">
  <div class="section-head"><div class="st"><h2>현장 사진</h2><span class="section-desc"><?= count($photos) ?>장</span></div></div>
  <?php if (!$photos): ?>
    <div class="empty"><div class="empty-title">등록된 현장 사진이 없습니다.</div></div>
  <?php else: ?>
    <div class="photo-grid mb-14">
      <?php foreach ($photos as $f): ?>
        <a class="photo-thumb" href="<?= e(url('files.download', ['id' => $f['id']])) ?>" target="_blank" title="<?= e($f['original_name']) ?>">
          <img src="<?= e(url('files.download', ['id' => $f['id']])) ?>" alt="<?= e($f['original_name']) ?>">
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('projects.upload')) ?>" enctype="multipart/form-data" class="flex items-center gap-8">
    <?= csrf_field() ?>
    <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
    <input type="hidden" name="category" value="photo">
    <input type="file" name="file" class="input" accept="image/*" required>
    <button type="submit" class="btn btn-outline btn-sm">사진 업로드</button>
  </form>
</div>

<div class="card pad">
  <div class="section-head"><div class="st"><h2>첨부 문서</h2><span class="section-desc">견적서·계약서·영수증 등 <?= count($docs) ?>건</span></div></div>
  <?php if (!$docs): ?>
    <div class="empty"><div class="empty-title">첨부된 문서가 없습니다.</div></div>
  <?php else: ?>
    <?php foreach ($docs as $f): ?>
      <div class="file-item">
        <span class="file-name"><?= e($f['original_name']) ?></span>
        <span class="muted nowrap"><?= number_format((int) $f['size'] / 1024, 0) ?> KB</span>
        <a href="<?= e(url('files.download', ['id' => $f['id']])) ?>" class="btn btn-outline btn-sm">다운로드</a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <form method="post" action="<?= e(url('projects.upload')) ?>" enctype="multipart/form-data" class="flex items-center gap-8 mt-8">
    <?= csrf_field() ?>
    <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
    <input type="hidden" name="category" value="doc">
    <input type="file" name="file" class="input" required>
    <button type="submit" class="btn btn-outline btn-sm">문서 업로드</button>
  </form>
</div>
