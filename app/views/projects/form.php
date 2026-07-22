<?php
/** @var ?array $project @var array $customers @var array $processStages @var array $users
 *  @var array $statuses @var array $importance @var array $contribModes
 */
$p = $project ?? [];
$val = fn($k, $d = '') => e((string) ($p[$k] ?? $d));
?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title"><?= $project ? '프로젝트 수정' : '프로젝트 등록' ?></h1>
    <div class="page-actions">
      <a href="<?= e(url($project ? 'projects.show' : 'projects.index', $project ? ['id' => $project['id']] : [])) ?>" class="btn btn-outline">취소</a>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <form method="post" action="<?= e(url('projects.save')) ?>" class="form">
        <?= csrf_field() ?>
        <?php if ($project): ?><input type="hidden" name="id" value="<?= (int) $project['id'] ?>"><?php endif; ?>

        <div class="form-grid">
          <div class="field">
            <label class="field-label">프로젝트명<span class="req">*</span></label>
            <input type="text" name="name" class="input" required value="<?= $val('name') ?>">
          </div>
          <div class="field">
            <label class="field-label">고객<span class="req">*</span></label>
            <select name="customer_id" class="select" required>
              <option value="">선택</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (isset($p['customer_id']) && (int) $p['customer_id'] === (int) $c['id']) ? 'selected' : '' ?>>
                  <?= e($c['name']) ?><?= $c['company_name'] ? ' (' . e($c['company_name']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field col-span-2">
            <label class="field-label">현장 주소</label>
            <input type="text" name="site_address" class="input" value="<?= $val('site_address') ?>">
          </div>
          <div class="field">
            <label class="field-label">공사유형</label>
            <input type="text" name="work_type" class="input" value="<?= $val('work_type') ?>" placeholder="예: 아파트외벽, 옥상방수">
          </div>
          <div class="field">
            <label class="field-label">상태</label>
            <select name="status" class="select">
              <?php foreach ($statuses as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= ($p['status'] ?? 'preparing') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="field-label">공정 단계</label>
            <select name="process_stage_id" class="select">
              <option value="">미지정</option>
              <?php foreach ($processStages as $ps): ?>
                <option value="<?= (int) $ps['id'] ?>" <?= (isset($p['process_stage_id']) && (int) $p['process_stage_id'] === (int) $ps['id']) ? 'selected' : '' ?>><?= e($ps['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="field-label">중요도</label>
            <select name="importance" class="select">
              <?php foreach ($importance as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= ($p['importance'] ?? 'mid') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="field-label">영업담당</label>
            <select name="sales_user_id" class="select">
              <option value="">미지정</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= (isset($p['sales_user_id']) && (int) $p['sales_user_id'] === (int) $u['id']) ? 'selected' : '' ?>><?= e($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="field-label">현장관리자</label>
            <select name="site_manager_id" class="select">
              <option value="">미지정</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= (isset($p['site_manager_id']) && (int) $p['site_manager_id'] === (int) $u['id']) ? 'selected' : '' ?>><?= e($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="field-label">기여도 배분방식</label>
            <select name="contribution_mode" class="select">
              <?php foreach ($contribModes as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= ($p['contribution_mode'] ?? 'main') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="field-label">진행률(%)</label>
            <input type="number" name="progress" class="input" min="0" max="100" value="<?= $val('progress', '0') ?>">
          </div>

          <div class="field">
            <label class="field-label">계약금액</label>
            <input type="number" name="contract_amount" class="input" min="0" value="<?= $val('contract_amount', '0') ?>">
          </div>
          <div class="field">
            <label class="field-label">예상원가</label>
            <input type="number" name="estimated_cost" class="input" min="0" value="<?= $val('estimated_cost', '0') ?>">
          </div>
          <div class="field">
            <label class="field-label">계약일</label>
            <input type="date" name="contract_date" class="input" value="<?= $val('contract_date') ?>">
          </div>
          <div class="field">
            <label class="field-label">착공(예정)</label>
            <input type="date" name="start_date" class="input" value="<?= $val('start_date') ?>">
          </div>
          <div class="field">
            <label class="field-label">준공(예정)</label>
            <input type="date" name="end_date" class="input" value="<?= $val('end_date') ?>">
          </div>
          <div class="field">
            <label class="field-label">실제 착공일</label>
            <input type="date" name="actual_start_date" class="input" value="<?= $val('actual_start_date') ?>">
          </div>
          <div class="field">
            <label class="field-label">실제 준공일</label>
            <input type="date" name="actual_end_date" class="input" value="<?= $val('actual_end_date') ?>">
          </div>

          <div class="field col-span-2">
            <label class="field-label">메모</label>
            <textarea name="memo" class="input" rows="3"><?= $val('memo') ?></textarea>
          </div>
        </div>

        <div class="btn-group">
          <button type="submit" class="btn btn-primary">저장</button>
          <a href="<?= e(url($project ? 'projects.show' : 'projects.index', $project ? ['id' => $project['id']] : [])) ?>" class="btn btn-outline">취소</a>
        </div>
      </form>
    </div>
  </div>
</div>
