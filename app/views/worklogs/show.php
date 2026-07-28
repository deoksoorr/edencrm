<?php
/** @var array $row @var array $photos @var bool $canConfirm @var bool $canUpload */
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">작업일지 상세</div>
      <div class="page-sub"><?= e($row['project_no']) ?> · <?= e($row['project_name']) ?></div>
    </div>
    <div class="page-actions">
      <a class="btn btn-outline" href="<?= e(url('worklogs.index')) ?>">목록으로</a>
      <?php if ($canConfirm && !$row['confirmed_by']): ?>
        <button class="btn btn-primary" id="btnConfirm" type="button">관리자 확인</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <div class="detail-head">
        <div>
          <div class="detail-title"><?= e(fmtdate($row['work_date'])) ?> 작업일지</div>
          <div class="detail-meta">작성자 <?= e($row['author_name']) ?> · 진행공정 <?= e($row['stage_name'] ?? '-') ?></div>
        </div>
        <div>
          <?php if ($row['confirmed_by']): ?>
            <span class="badge badge-ok">관리자확인 완료 (<?= e($row['confirmed_by_name'] ?? '') ?>, <?= e(fmtdate($row['confirmed_at'], 'Y-m-d H:i')) ?>)</span>
          <?php else: ?>
            <span class="badge badge-warn">관리자 미확인</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="kv-row mb-14">
        <div class="kv"><div class="kv-label">시작</div><div class="kv-value"><?= e($row['start_time'] ?? '-') ?></div></div>
        <div class="kv"><div class="kv-label">종료</div><div class="kv-value"><?= e($row['end_time'] ?? '-') ?></div></div>
        <div class="kv"><div class="kv-label">진행률</div><div class="kv-value"><?= $row['progress'] !== null ? pct((float) $row['progress']) : '-' ?></div></div>
        <div class="kv"><div class="kv-label">날씨</div><div class="kv-value"><?= e($row['weather'] ?? '-') ?></div></div>
      </div>

      <div class="section-title">작업 내용</div>
      <p class="prewrap"><?= e($row['content']) ?></p>

      <div class="grid-2">
        <div>
          <div class="section-title">자재</div>
          <p><?= e($row['materials'] ?? '-') ?><?= $row['material_qty'] ? ' (' . e($row['material_qty']) . ')' : '' ?></p>
          <div class="section-title">장비</div>
          <p><?= e($row['equipment'] ?? '-') ?></p>
        </div>
        <div>
          <div class="section-title">특이사항</div>
          <p><?= e($row['issues'] ?? '-') ?></p>
          <div class="section-title">지연 사유 / 다음 작업</div>
          <p><?= e($row['delay_reason'] ?? '-') ?> / <?= e($row['next_work'] ?? '-') ?></p>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">현장 사진</div></div>
    <div class="card-body">
      <?php if (!$photos): ?>
        <div class="empty"><div class="empty-title">등록된 사진이 없습니다</div></div>
      <?php else: ?>
        <div class="photo-grid">
          <?php foreach ($photos as $ph): ?>
            <a class="photo-thumb" href="<?= e(url('files.download', ['id' => $ph['file_id']])) ?>" target="_blank">
              <img src="<?= e(url('files.download', ['id' => $ph['file_id']])) ?>" alt="<?= e($ph['original_name']) ?>" loading="lazy">
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($canUpload): ?>
        <form class="form mt-16" data-ajax action-route="worklogs.photo" data-reload data-success="사진이 업로드되었습니다.">
          <input type="hidden" name="work_log_id" value="<?= (int) $row['id'] ?>">
          <div class="field">
            <label class="field-label">사진 추가</label>
            <input class="input" type="file" name="photo" accept="image/*" required>
          </div>
          <button class="btn btn-outline" type="submit">업로드</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
