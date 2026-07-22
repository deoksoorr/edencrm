<?php
/** @var ?array $customer @var array $salesUsers */
$c = $customer ?? [];
$isEdit = !empty($c);
$val = fn(string $k, $default = '') => e((string) ($c[$k] ?? $default));
$sourceOptions = ['전화문의', '홈페이지문의', '지인소개', '제휴사소개', '재계약', '네이버플레이스', '블로그', '인스타그램', '현수막'];
$interestOptions = ['신축도장', '리모델링도장', '공장도장', '아파트외벽', '아파트내부', '옥상방수', '방수공사', '상가인테리어'];
?>
<div class="page">
  <div class="page-head">
    <div class="page-title"><?= $isEdit ? '고객 정보 수정' : '신규 고객 등록' ?></div>
    <div class="page-actions">
      <a class="btn btn-outline" href="<?= e($isEdit ? url('customers.show', ['id' => $c['id']]) : url('customers.index')) ?>">취소</a>
    </div>
  </div>

  <form class="form card pad" id="customerForm" method="post" action="<?= e(url('customers.save')) ?>">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><?php endif; ?>

    <div class="form-grid">
      <label class="field">
        <span class="field-label">구분<span class="req">*</span></span>
        <select name="type" id="custType" class="select">
          <option value="individual" <?= ($c['type'] ?? 'individual') === 'individual' ? 'selected' : '' ?>>개인</option>
          <option value="company" <?= ($c['type'] ?? '') === 'company' ? 'selected' : '' ?>>법인</option>
        </select>
      </label>
      <label class="field">
        <span class="field-label">고객명<span class="req">*</span></span>
        <input type="text" name="name" class="input" required value="<?= $val('name') ?>">
      </label>
      <label class="field company-field <?= ($c['type'] ?? '') === 'company' ? '' : 'hidden' ?>">
        <span class="field-label">업체명</span>
        <input type="text" name="company_name" class="input" value="<?= $val('company_name') ?>">
      </label>
      <label class="field company-field <?= ($c['type'] ?? '') === 'company' ? '' : 'hidden' ?>">
        <span class="field-label">담당자</span>
        <input type="text" name="contact_name" class="input" value="<?= $val('contact_name') ?>">
      </label>
      <label class="field">
        <span class="field-label">연락처</span>
        <input type="text" name="phone" id="fPhone" class="input" placeholder="010-0000-0000" value="<?= $val('phone') ?>">
      </label>
      <label class="field">
        <span class="field-label">이메일</span>
        <input type="email" name="email" id="fEmail" class="input" value="<?= $val('email') ?>">
      </label>
    </div>
    <div id="dupWarning" class="flash flash-warning hidden" style="margin:0"></div>

    <div class="form-grid">
      <label class="field">
        <span class="field-label">주소</span>
        <input type="text" name="address" class="input" value="<?= $val('address') ?>">
      </label>
      <label class="field">
        <span class="field-label">현장주소</span>
        <input type="text" name="site_address" class="input" value="<?= $val('site_address') ?>">
      </label>
      <label class="field">
        <span class="field-label">유입경로</span>
        <input type="text" name="source" list="sourceList" class="input" value="<?= $val('source') ?>">
        <datalist id="sourceList"><?php foreach ($sourceOptions as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?></datalist>
      </label>
      <label class="field">
        <span class="field-label">관심공사</span>
        <input type="text" name="interest_type" list="interestList" class="input" value="<?= $val('interest_type') ?>">
        <datalist id="interestList"><?php foreach ($interestOptions as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?></datalist>
      </label>
      <label class="field">
        <span class="field-label">예상규모</span>
        <select name="expected_scale" class="select">
          <option value="">선택 안 함</option>
          <?php foreach (['소형(~50평)', '중형(50-200평)', '대형(200평 이상)'] as $o): ?>
            <option value="<?= e($o) ?>" <?= ($c['expected_scale'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">예상예산(원)</span>
        <input type="number" name="expected_budget" class="input" min="0" step="1" value="<?= $val('expected_budget') ?>">
      </label>
      <label class="field">
        <span class="field-label">상담희망일</span>
        <input type="date" name="desired_consult_date" class="input" value="<?= $val('desired_consult_date') ?>">
      </label>
      <label class="field">
        <span class="field-label">담당영업</span>
        <select name="sales_user_id" class="select">
          <option value="">미지정</option>
          <?php foreach ($salesUsers as $su): ?>
            <option value="<?= (int) $su['id'] ?>" <?= (int) ($c['sales_user_id'] ?? 0) === (int) $su['id'] ? 'selected' : '' ?>><?= e($su['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">상태</span>
        <select name="status" class="select">
          <?php foreach (['active' => '활성', 'inactive' => '비활성', 'blacklist' => '블랙리스트'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= ($c['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">다음연락예정일</span>
        <input type="date" name="next_contact_date" class="input" value="<?= $val('next_contact_date') ?>">
      </label>
      <label class="field">
        <span class="field-label">태그</span>
        <input type="text" name="tags" class="input" placeholder="쉼표로 구분" value="<?= $val('tags') ?>">
      </label>
    </div>

    <label class="field">
      <span class="field-label">내부메모</span>
      <textarea name="memo" class="input" rows="3"><?= $val('memo') ?></textarea>
    </label>

    <label class="field" style="flex-direction:row;align-items:center;gap:8px">
      <input type="checkbox" name="privacy_agreed" value="1" <?= (int) ($c['privacy_agreed'] ?? 0) === 1 ? 'checked' : '' ?> required style="width:auto">
      <span class="field-label" style="margin:0">개인정보 수집·이용에 동의합니다.<span class="req">*</span></span>
    </label>

    <div class="btn-group">
      <button type="submit" class="btn btn-primary">저장</button>
      <a class="btn btn-outline" href="<?= e($isEdit ? url('customers.show', ['id' => $c['id']]) : url('customers.index')) ?>">취소</a>
    </div>
  </form>
</div>
<?php
$custId = (int) ($c['id'] ?? 0);
$inlineScript = <<<JS
(function(){
  var typeSel = document.getElementById('custType');
  function toggleCompanyFields(){
    var show = typeSel.value === 'company';
    document.querySelectorAll('.company-field').forEach(function(f){ f.classList.toggle('hidden', !show); });
  }
  typeSel.addEventListener('change', toggleCompanyFields);

  var phone = document.getElementById('fPhone');
  var email = document.getElementById('fEmail');
  var warnBox = document.getElementById('dupWarning');
  async function checkDup(){
    var p = phone.value.trim();
    var em = email.value.trim();
    if (!p && !em) { warnBox.classList.add('hidden'); return; }
    try {
      var data = await api('customers.dupcheck', {phone: p, email: em, id: {$custId}}, {method:'GET'});
      if (data.candidates && data.candidates.length) {
        warnBox.innerHTML = '중복 의심 고객: ' + data.candidates.map(function(c){
          return (c.name || '') + (c.company_name ? '('+c.company_name+')' : '') + ' ' + (c.phone || c.email || '');
        }).join(', ');
        warnBox.classList.remove('hidden');
      } else {
        warnBox.classList.add('hidden');
      }
    } catch (e) { /* 조용히 무시 */ }
  }
  phone.addEventListener('blur', checkDup);
  email.addEventListener('blur', checkDup);
})();
JS;
?>
