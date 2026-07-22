<?php
/** @var array $contract @var array $customers @var array $users @var array $existingPayTypes @var array $statusLabels */
$isEdit = !empty($contract['id']);
?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title"><?= $isEdit ? '계약 수정 - ' . e($contract['contract_no']) : '계약 등록' ?></h1>
  </div>

  <form class="form" method="post" action="<?= e(url('contracts.save')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) ($contract['id'] ?? 0) ?>">
    <input type="hidden" name="quote_id" value="<?= (int) ($contract['quote_id'] ?? 0) ?>">

    <div class="card pad">
      <div class="form-grid">
        <div class="field">
          <label class="field-label">고객 <span class="req">*</span></label>
          <select name="customer_id" class="select" required>
            <option value="">선택하세요</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) ($contract['customer_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?><?= $c['phone'] ? ' (' . e($c['phone']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label">담당 영업</label>
          <select name="sales_user_id" class="select">
            <option value="">선택 안함</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>" <?= (int) ($contract['sales_user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label">계약일</label>
          <input type="date" name="contract_date" class="input" value="<?= e($contract['contract_date'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="field-label">상태</label>
          <select name="status" class="select">
            <?php foreach ($statusLabels as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= ($contract['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label">계약금액 <span class="req">*</span></label>
          <input type="text" inputmode="decimal" name="contract_amount" class="input money-input" value="<?= e((string) (int) ($contract['contract_amount'] ?? 0)) ?>" required>
        </div>
        <div class="field">
          <label class="field-label">하자보증기간</label>
          <input type="text" name="warranty_period" class="input" value="<?= e($contract['warranty_period'] ?? '') ?>" placeholder="예: 2년">
        </div>
        <div class="field">
          <label class="field-label">착공예정일</label>
          <input type="date" name="start_date" class="input" value="<?= e($contract['start_date'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="field-label">준공예정일</label>
          <input type="date" name="end_date" class="input" value="<?= e($contract['end_date'] ?? '') ?>">
        </div>
        <div class="field col-span-2">
          <label class="field-label">특약사항</label>
          <textarea name="special_terms" class="input" rows="2"><?= e($contract['special_terms'] ?? '') ?></textarea>
        </div>
        <div class="field col-span-2">
          <label class="field-label">계약서 파일 (선택)</label>
          <input type="file" name="contract_file" class="input">
          <div class="field-hint">PDF, 이미지, 오피스 문서 등 (최대 10MB)</div>
        </div>
      </div>
    </div>

    <div class="card pad">
      <div class="section-head"><div class="st"><h2>대금 지급 계획 (저장 시 입금 예정행 자동 생성)</h2></div></div>
      <div>
        <div class="form-grid-3">
          <?php foreach (['down' => '계약금', 'middle' => '중도금', 'balance' => '잔금'] as $type => $label): ?>
            <div class="field">
              <label class="field-label"><?= e($label) ?></label>
              <input type="text" inputmode="decimal" name="<?= $type ?>_payment" class="input money-input" value="<?= e((string) (int) ($contract[$type . '_payment'] ?? 0)) ?>">
              <?php if (in_array($type, $existingPayTypes, true)): ?>
                <div class="field-hint">이미 입금 항목이 등록되어 있습니다. 예정일 수정은 입금 관리에서 하세요.</div>
              <?php else: ?>
                <input type="date" name="<?= $type ?>_due_date" class="input" style="margin-top:6px" placeholder="예정일">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="page-actions">
      <a href="<?= e(url($isEdit ? 'contracts.show' : 'contracts.index', $isEdit ? ['id' => $contract['id']] : [])) ?>" class="btn btn-outline">취소</a>
      <button type="submit" class="btn btn-primary">저장</button>
    </div>
  </form>
</div>
