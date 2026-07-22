<?php
/** @var array|null $row @var array $projects @var array $stages */
?>
<div class="page">
  <div class="page-head">
    <div class="page-title"><?= $row ? '작업일지 수정' : '작업일지 작성' ?></div>
  </div>

  <form class="card" data-ajax action-route="worklogs.save" data-redirect="worklogs.index" method="post">
    <div class="card-body form">
      <?= csrf_field() ?>
      <?php if ($row): ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><?php endif; ?>

      <div class="form-grid">
        <div class="field">
          <label class="field-label">프로젝트 <span class="req">*</span></label>
          <select class="select" name="project_id" required <?= $projects ? '' : 'disabled' ?>>
            <option value="">선택</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= ($row && (int) $row['project_id'] === (int) $p['id']) ? 'selected' : '' ?>>
                <?= e($p['project_no']) ?> · <?= e($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$projects): ?><div class="field-hint">배정된 프로젝트가 없어 작업일지를 작성할 수 없습니다.</div><?php endif; ?>
        </div>
        <div class="field">
          <label class="field-label">작업일 <span class="req">*</span></label>
          <input class="input" type="date" name="work_date" required value="<?= e($row['work_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="field">
          <label class="field-label">시작 시각</label>
          <input class="input" type="time" name="start_time" value="<?= e($row['start_time'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="field-label">종료 시각</label>
          <input class="input" type="time" name="end_time" value="<?= e($row['end_time'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="field-label">진행 공정</label>
          <select class="select" name="process_stage_id">
            <option value="">선택 안함</option>
            <?php foreach ($stages as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= ($row && (int) ($row['process_stage_id'] ?? 0) === (int) $s['id']) ? 'selected' : '' ?>>
                <?= e($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label">진행률(%)</label>
          <input class="input" type="number" min="0" max="100" name="progress" value="<?= e((string) ($row['progress'] ?? '')) ?>">
        </div>
      </div>

      <div class="field">
        <label class="field-label">작업 내용 <span class="req">*</span></label>
        <textarea class="input" name="content" required><?= e($row['content'] ?? '') ?></textarea>
      </div>

      <div class="form-grid">
        <div class="field"><label class="field-label">자재</label><input class="input" name="materials" value="<?= e($row['materials'] ?? '') ?>"></div>
        <div class="field"><label class="field-label">자재 수량</label><input class="input" name="material_qty" value="<?= e($row['material_qty'] ?? '') ?>"></div>
        <div class="field"><label class="field-label">장비</label><input class="input" name="equipment" value="<?= e($row['equipment'] ?? '') ?>"></div>
        <div class="field">
          <label class="field-label">날씨</label>
          <select class="select" name="weather">
            <?php $wopts = ['' => '선택 안함', '맑음' => '맑음', '흐림' => '흐림', '비' => '비', '눈' => '눈', '강풍' => '강풍']; ?>
            <?php foreach ($wopts as $wv => $wl): ?>
              <option value="<?= e($wv) ?>" <?= (($row['weather'] ?? '') === $wv) ? 'selected' : '' ?>><?= e($wl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="field">
        <label class="field-label">특이사항 / 이슈</label>
        <textarea class="input" name="issues"><?= e($row['issues'] ?? '') ?></textarea>
      </div>
      <div class="form-grid">
        <div class="field"><label class="field-label">지연 사유</label><input class="input" name="delay_reason" value="<?= e($row['delay_reason'] ?? '') ?>"></div>
        <div class="field"><label class="field-label">다음 작업 예정</label><input class="input" name="next_work" value="<?= e($row['next_work'] ?? '') ?>"></div>
      </div>
    </div>
    <div style="border-top:1px solid var(--line-2);padding:14px 16px;display:flex;justify-content:flex-end;gap:8px">
      <a class="btn btn-outline" href="<?= e(url('worklogs.index')) ?>">취소</a>
      <button class="btn btn-primary" type="submit" <?= $projects ? '' : 'disabled' ?>>저장</button>
    </div>
  </form>
</div>
