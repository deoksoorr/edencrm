<?php
/**
 * 영업기회 등록·수정 폼 페이지(R4 T7) — 보드 인라인 모달 대체(원본 데이터 관리 전용).
 * 12단계 stage_id 는 여기서만 변경(보드 표시 단계는 자동 산정 파생값).
 * @var ?array $lead @var array $stages,$salesUsers,$customers @var ?int $defaultCustomerId
 */
$l = $lead ?? [];
$isEdit = !empty($l);
$val = fn(string $k, $default = '') => e((string) ($l[$k] ?? $default));
$selCustomer = (int) ($l['customer_id'] ?? $defaultCustomerId ?? 0);
$selStage = (int) ($l['stage_id'] ?? ($stages[0]['id'] ?? 0));
?>
<div class="page page-narrow">
  <div class="page-head">
    <div>
      <div class="page-title"><?= $isEdit ? '영업기회 수정' : '영업기회 등록' ?></div>
      <div class="page-sub">단계(12단계)는 산정 입력·이력용 원본값 — 보드 표시는 원본 데이터 기준 자동 산정</div>
    </div>
    <div class="page-actions">
      <a class="btn btn-outline" href="<?= e($isEdit ? url('pipeline.show', ['id' => (int) $l['id']]) : url('pipeline.index')) ?>">취소</a>
    </div>
  </div>

  <form class="form card pad" id="leadForm" method="post" action="<?= e(url('pipeline.save')) ?>">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><?php endif; ?>

    <div class="form-grid">
      <label class="field">
        <span class="field-label">고객<span class="req">*</span></span>
        <select name="customer_id" class="select" required>
          <option value="">선택</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $selCustomer === (int) $c['id'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?><?= $c['company_name'] ? ' (' . e($c['company_name']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">단계(원본)</span>
        <select name="stage_id" class="select">
          <?php foreach ($stages as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $selStage === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">담당영업</span>
        <select name="sales_user_id" class="select">
          <option value="">미지정</option>
          <?php foreach ($salesUsers as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (int) ($l['sales_user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">공사종류</span>
        <input type="text" name="work_type" class="input" value="<?= $val('work_type') ?>">
      </label>
      <label class="field col-span-2">
        <span class="field-label">현장주소</span>
        <input type="text" name="site_address" class="input" value="<?= $val('site_address') ?>">
      </label>
      <label class="field">
        <span class="field-label">예상계약금액</span>
        <input type="number" name="expected_amount" id="leadAmount" class="input" min="0" value="<?= e((string) (int) ($l['expected_amount'] ?? 0)) ?>">
      </label>
      <label class="field">
        <span class="field-label">예상원가</span>
        <input type="number" name="expected_cost" id="leadCost" class="input" min="0" value="<?= e((string) (int) ($l['expected_cost'] ?? 0)) ?>">
      </label>
      <label class="field">
        <span class="field-label">성공확률(%)</span>
        <input type="number" name="win_probability" class="input" min="0" max="100" value="<?= ($l['win_probability'] ?? null) !== null ? e((string) round((float) $l['win_probability'])) : '' ?>">
      </label>
      <label class="field">
        <span class="field-label">다음연락예정일</span>
        <input type="date" name="next_contact_date" class="input" value="<?= $val('next_contact_date') ?>">
      </label>
      <label class="field">
        <span class="field-label">태그</span>
        <input type="text" name="tags" class="input" value="<?= $val('tags') ?>">
      </label>
    </div>

    <label class="field">
      <span class="field-label">메모</span>
      <textarea name="memo" class="input" rows="3"><?= $val('memo') ?></textarea>
    </label>

    <div class="kv-row" style="border-top:1px solid var(--line-2);padding-top:10px">
      <div class="kv"><span class="kv-label">예상순이익(실시간)</span><span class="kv-value mono" id="calcProfit">-</span></div>
      <div class="kv"><span class="kv-label">예상순이익률(실시간)</span><span class="kv-value mono" id="calcRate">-</span></div>
    </div>

    <div class="btn-group">
      <button type="submit" class="btn btn-primary">저장</button>
      <a class="btn btn-outline" href="<?= e($isEdit ? url('pipeline.show', ['id' => (int) $l['id']]) : url('pipeline.index')) ?>">취소</a>
    </div>
  </form>
</div>

<script>
// 예상 순이익 실시간 계산(폼 페이지 전용 — 파이프라인 보드 JS 와 무관)
(function () {
  'use strict';
  var a = document.getElementById('leadAmount'), c = document.getElementById('leadCost');
  var p = document.getElementById('calcProfit'), r = document.getElementById('calcRate');
  function recalc() {
    var amount = parseFloat(a.value) || 0, cost = parseFloat(c.value) || 0;
    var profit = amount - cost, rate = amount > 0 ? (profit / amount * 100) : null;
    p.textContent = profit.toLocaleString('ko-KR') + '원';
    r.textContent = rate === null ? '-' : rate.toFixed(1) + '%';
  }
  a.addEventListener('input', recalc);
  c.addEventListener('input', recalc);
  recalc();
})();
</script>
