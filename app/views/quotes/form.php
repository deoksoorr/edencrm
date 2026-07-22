<?php
/** @var array $quote @var array $items @var ?array $version @var array $customers @var array $leads @var float $vatRate */
$isEdit = !empty($quote['id']);
$discount = $version['discount'] ?? 0;
$versionNote = '';
?>
<div class="page">
  <div class="page-head">
    <h1 class="page-title"><?= $isEdit ? '견적 수정 - ' . e($quote['quote_no']) : '견적 등록' ?></h1>
  </div>

  <form class="form" method="post" action="<?= e(url('quotes.save')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) ($quote['id'] ?? 0) ?>">

    <div class="card pad">
      <div class="form-grid">
        <div class="field">
          <label class="field-label">고객 <span class="req">*</span></label>
          <select name="customer_id" class="select" required>
            <option value="">선택하세요</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) ($quote['customer_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?><?= $c['phone'] ? ' (' . e($c['phone']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label">연결 영업기회(리드)</label>
          <select name="lead_id" class="select">
            <option value="">선택 안함</option>
            <?php foreach ($leads as $l): ?>
              <option value="<?= (int) $l['id'] ?>" <?= (int) ($quote['lead_id'] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>>
                #<?= (int) $l['id'] ?> · <?= e($l['customer_name']) ?><?= $l['work_type'] ? ' · ' . e($l['work_type']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label">유효기간</label>
          <input type="date" name="valid_until" class="input" value="<?= e($quote['valid_until'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="field-label">상태</label>
          <select name="status" class="select">
            <?php foreach (['draft'=>'임시저장','sent'=>'발송됨','accepted'=>'수락됨','rejected'=>'거절됨','expired'=>'만료됨'] as $k=>$label): ?>
              <option value="<?= e($k) ?>" <?= ($quote['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field col-span-2">
          <label class="field-label">특이사항</label>
          <textarea name="memo" class="input" rows="2"><?= e($quote['memo'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="card pad">
      <div class="section-head">
        <div class="st"><h2>견적 항목</h2></div>
        <button type="button" class="btn btn-sm btn-outline" id="btnAddItem">+ 행 추가</button>
      </div>
      <div>
        <div class="table-wrap">
          <table class="data" id="itemsTable">
            <thead>
              <tr>
                <th style="min-width:160px">항목명</th>
                <th class="num" style="min-width:80px">면적(㎡)</th>
                <th class="num" style="min-width:70px">수량</th>
                <th class="num" style="min-width:100px">단가</th>
                <th class="num" style="min-width:100px">재료비</th>
                <th class="num" style="min-width:100px">인건비</th>
                <th class="num" style="min-width:100px">장비비</th>
                <th class="num" style="min-width:100px">외주비</th>
                <th class="num" style="min-width:100px">기타비용</th>
                <th class="num" style="min-width:110px">금액</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="itemsBody"></tbody>
          </table>
        </div>
        <div class="kv-row mt-16">
          <div class="kv"><div class="kv-label">공급가액</div><div class="kv-value" id="sumSubtotal">0</div></div>
          <div class="kv"><div class="kv-label">부가세(<?= e(rtrim(rtrim(number_format($vatRate, 1), '0'), '.')) ?>%)</div><div class="kv-value" id="sumVat">0</div></div>
          <div class="field" style="width:160px">
            <label class="field-label">할인</label>
            <input type="text" inputmode="numeric" name="discount" id="inputDiscount" class="input money-input" value="<?= e((string) (int) $discount) ?>">
          </div>
          <div class="kv"><div class="kv-label">총 금액</div><div class="kv-value" id="sumTotal" style="color:var(--brand);font-size:19px">0</div></div>
        </div>
      </div>
    </div>

    <div class="card pad">
      <div class="field">
        <label class="field-label">버전 메모 (수정 사유 등, 선택)</label>
        <input type="text" name="version_note" class="input" value="<?= e($versionNote) ?>" placeholder="예: 단가 조정, 항목 추가 등">
      </div>
    </div>

    <div class="page-actions">
      <a href="<?= e(url($isEdit ? 'quotes.show' : 'quotes.index', $isEdit ? ['id' => $quote['id']] : [])) ?>" class="btn btn-outline">취소</a>
      <button type="submit" class="btn btn-primary">저장</button>
    </div>
  </form>
</div>

<script>
window.__QUOTE_ITEMS__ = <?= json_encode(array_map(function ($it) {
    return [
        'name' => $it['name'], 'area' => $it['area'], 'qty' => $it['qty'], 'unit_price' => $it['unit_price'],
        'material_cost' => $it['material_cost'], 'labor_cost' => $it['labor_cost'],
        'equipment_cost' => $it['equipment_cost'], 'outsourcing_cost' => $it['outsourcing_cost'], 'etc_cost' => $it['etc_cost'],
    ];
}, $items), JSON_UNESCAPED_UNICODE) ?>;
window.__VAT_RATE__ = <?= json_encode($vatRate) ?>;
</script>
