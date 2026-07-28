<?php
/** @var ?array $customer @var array $salesUsers @var ?array $licenseFile */
$c = $customer ?? [];
$isEdit = !empty($c);
$licenseFile = $licenseFile ?? null;
$isBiz = (int) ($c['is_business'] ?? 0) === 1;
$val = fn(string $k, $default = '') => e((string) ($c[$k] ?? $default));
$sourceOptions = ['전화문의', '홈페이지문의', '지인소개', '제휴사소개', '재계약', '네이버플레이스', '블로그', '인스타그램', '현수막'];
$interestOptions = ['신축도장', '리모델링도장', '공장도장', '아파트외벽', '아파트내부', '옥상방수', '방수공사', '상가인테리어'];
?>
<div class="page page-narrow">
  <div class="page-head">
    <div class="page-title"><?= $isEdit ? '고객 정보 수정' : '고객 등록' ?></div>
    <div class="page-actions">
      <a class="btn btn-outline" href="<?= e($isEdit ? url('customers.show', ['id' => $c['id']]) : url('customers.index')) ?>">취소</a>
    </div>
  </div>

  <form class="form card pad" id="customerForm" method="post" action="<?= e(url('customers.save')) ?>" enctype="multipart/form-data">
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
    <div id="dupWarning" class="flash flash-warning hidden"></div>

    <!-- ── 사업자 정보 (R4 T2) ── -->
    <label class="check">
      <input type="checkbox" name="is_business" id="isBusiness" value="1" <?= $isBiz ? 'checked' : '' ?>>
      <span class="field-label">사업자 여부 (사업자등록증 보유 고객)</span>
    </label>
    <div id="bizFields" class="<?= $isBiz ? '' : 'hidden' ?>">
      <div class="form-grid">
        <label class="field">
          <span class="field-label">사업자등록번호</span>
          <input type="text" name="biz_reg_no" id="fBizRegNo" class="input" placeholder="000-00-00000"
                 inputmode="numeric" maxlength="12" value="<?= $val('biz_reg_no') ?>">
          <span class="field-hint text-danger hidden" id="bizNoMsg">사업자등록번호가 올바르지 않습니다. (숫자 10자리·검증번호 확인)</span>
        </label>
        <label class="field">
          <span class="field-label">상호(법인명)</span>
          <input type="text" name="biz_name" class="input" value="<?= $val('biz_name') ?>">
        </label>
        <label class="field">
          <span class="field-label">대표자명</span>
          <input type="text" name="biz_ceo" class="input" value="<?= $val('biz_ceo') ?>">
        </label>
        <label class="field">
          <span class="field-label">사업장 소재지</span>
          <input type="text" name="biz_address" class="input" value="<?= $val('biz_address') ?>">
        </label>
        <label class="field">
          <span class="field-label">업태</span>
          <input type="text" name="biz_type" class="input" placeholder="예: 건설업" value="<?= $val('biz_type') ?>">
        </label>
        <label class="field">
          <span class="field-label">종목</span>
          <input type="text" name="biz_item" class="input" placeholder="예: 도장공사" value="<?= $val('biz_item') ?>">
        </label>
      </div>
      <div id="bizDupWarning" class="flash flash-warning hidden"></div>
      <label class="field">
        <span class="field-label">사업자등록증 파일 (PDF·JPG·JPEG·PNG, 최대 10MB)</span>
        <input type="file" name="biz_license" class="input" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        <?php if ($licenseFile): ?>
          <span class="field-hint">현재 파일: <?= e($licenseFile['original_name']) ?> (<?= fmtdate($licenseFile['created_at']) ?> 업로드) — 새 파일 선택 시 교체됩니다.</span>
        <?php endif; ?>
      </label>
    </div>

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

    <label class="check">
      <input type="checkbox" name="privacy_agreed" value="1" <?= (int) ($c['privacy_agreed'] ?? 0) === 1 ? 'checked' : '' ?> required>
      <span class="field-label">개인정보 수집·이용에 동의합니다.<span class="req">*</span></span>
    </label>

    <div class="btn-group">
      <button type="submit" class="btn btn-primary">저장</button>
      <a class="btn btn-outline" href="<?= e($isEdit ? url('customers.show', ['id' => $c['id']]) : url('customers.index')) ?>">취소</a>
    </div>
  </form>
</div>
<?php
$custId = (int) ($c['id'] ?? 0);
// r4-refactor(T10): 뷰 내 $inlineScript 대입은 View::capture 스코프에 갇혀 레이아웃에 전달되지 않아
// 이 스크립트가 전혀 실행되지 않던 기존 버그 수정 — 뷰 콘텐츠에 직접 출력(DOMContentLoaded 로 app.js 로드 후 실행 보장).
$viewScript = <<<JS
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

  // ── 사업자 정보 (R4 T2): 토글·형식+국세청 체크섬 검증·중복 의심 경고(차단 아님) ──
  var bizToggle = document.getElementById('isBusiness');
  var bizFields = document.getElementById('bizFields');
  var bizNo = document.getElementById('fBizRegNo');
  var bizNoMsg = document.getElementById('bizNoMsg');
  var bizDupBox = document.getElementById('bizDupWarning');

  function toggleBizFields(){
    bizFields.classList.toggle('hidden', !bizToggle.checked);
  }
  bizToggle.addEventListener('change', toggleBizFields);

  function bizDigits(){ return bizNo.value.replace(/[^0-9]/g, ''); }
  function bizChecksumOk(d){
    if (!/^[0-9]{10}$/.test(d)) return false;
    var w = [1,3,7,1,3,7,1,3,5], s = 0;
    for (var i = 0; i < 9; i++) s += parseInt(d.charAt(i), 10) * w[i];
    s += Math.floor(parseInt(d.charAt(8), 10) * 5 / 10);
    return ((10 - (s % 10)) % 10) === parseInt(d.charAt(9), 10);
  }
  function validateBizNo(){
    var d = bizDigits();
    if (d === '') { bizNoMsg.classList.add('hidden'); return true; }
    var ok = bizChecksumOk(d);
    bizNoMsg.classList.toggle('hidden', ok);
    if (ok) bizNo.value = d.slice(0,3) + '-' + d.slice(3,5) + '-' + d.slice(5);
    return ok;
  }
  async function checkBizDup(){
    var d = bizDigits();
    if (d.length !== 10) { bizDupBox.classList.add('hidden'); return; }
    try {
      var data = await api('customers.dupcheck', {biz_reg_no: d, id: {$custId}}, {method:'GET'});
      var hits = (data.candidates || []).filter(function(c){
        return (c.biz_reg_no || '').replace(/[^0-9]/g, '') === d;
      });
      if (hits.length) {
        bizDupBox.textContent = '동일 사업자등록번호 고객이 이미 있습니다: ' + hits.map(function(c){
          return (c.name || '') + (c.company_name ? '(' + c.company_name + ')' : '');
        }).join(', ') + ' — 저장은 가능하지만 중복 등록인지 확인하세요.';
        bizDupBox.classList.remove('hidden');
      } else {
        bizDupBox.classList.add('hidden');
      }
    } catch (e) { /* 조용히 무시 */ }
  }
  bizNo.addEventListener('blur', function(){ if (validateBizNo()) checkBizDup(); });

  document.getElementById('customerForm').addEventListener('submit', function(ev){
    if (bizToggle.checked && !validateBizNo()) {
      ev.preventDefault();
      bizNo.focus();
      if (window.toast) toast('사업자등록번호를 확인하세요.', 'error');
    }
  });
})();
JS;
?>
<script>document.addEventListener('DOMContentLoaded', function () {
<?= $viewScript ?>
});</script>
