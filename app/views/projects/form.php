<?php
/** @var ?array $project @var array $customers @var array $users
 *  @var array $statuses @var array $contribModes
 */
$p = $project ?? [];
$val = fn($k, $d = '') => e((string) ($p[$k] ?? $d));
?>
<div class="page page-narrow">
  <div class="page-head">
    <h1 class="page-title"><?= $project ? '프로젝트 수정' : '예외 프로젝트 생성' ?></h1>
    <div class="page-actions">
      <a href="<?= e(url($project ? 'projects.show' : 'projects.index', $project ? ['id' => $project['id']] : [])) ?>" class="btn btn-outline">취소</a>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <?php if (!$project): ?>
        <div class="field-hint mb-14">
          프로젝트는 계약 <b>'진행(계약 진행)'</b> 전환 시 자동 생성됩니다. 이 화면은 <b>계약 연결 없는 예외 프로젝트</b>
          (하자보수·내부 작업 등) 전용이며, 생성 사유가 감사 로그에 기록됩니다.
        </div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('projects.save')) ?>" class="form">
        <?= csrf_field() ?>
        <?php if ($project): ?><input type="hidden" name="id" value="<?= (int) $project['id'] ?>"><?php endif; ?>

        <div class="form-grid">
          <?php if (!$project): ?>
          <div class="field col-span-2">
            <label class="field-label">생성 사유<span class="req">*</span></label>
            <input type="text" name="create_reason" class="input" required maxlength="500"
                   placeholder="예: 한빛아파트 하자보수 — 계약 없이 내부 작업으로 진행">
          </div>
          <?php endif; ?>
          <div class="field">
            <label class="field-label">프로젝트명<span class="req">*</span></label>
            <input type="text" name="name" class="input" required maxlength="150" value="<?= $val('name') ?>">
          </div>
          <?php // 예외 모드(신규=예외 생성, 수정=is_exception 프로젝트): 고객 연결 선택 사항 + 고객명 직접 입력 허용
            $exMode = !$project || !empty($p['is_exception']); ?>
          <div class="field">
            <label class="field-label">고객<?= $exMode ? '' : '<span class="req">*</span>' ?></label>
            <select name="customer_id" class="select" id="projCustomerSel" <?= $exMode ? '' : 'required' ?>>
              <option value=""><?= $exMode ? '선택 안 함 (고객명 직접 입력)' : '선택' ?></option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (isset($p['customer_id']) && (int) $p['customer_id'] === (int) $c['id']) ? 'selected' : '' ?>>
                  <?= e($c['name']) ?><?= $c['company_name'] ? ' (' . e($c['company_name']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if ($exMode): ?>
              <div class="field-hint">기존 고객 연결 또는 아래 <b>고객명 직접 입력</b> 중 하나는 필수입니다.</div>
            <?php endif; ?>
          </div>
          <?php if ($exMode): ?>
          <div class="field">
            <label class="field-label">고객명 직접 입력<span class="req">*</span> <span class="field-hint">(고객 미선택 시 필수)</span></label>
            <input type="text" name="customer_name_snapshot" class="input" maxlength="150"
                   value="<?= $val('customer_name_snapshot') ?>" placeholder="예: 한빛아파트 입주자대표회의">
            <div class="field-hint">기존 고객을 연결하면 저장 시점 고객명이 자동 기록됩니다.</div>
          </div>
          <?php if ($project && Rbac::isRole('super_admin')): ?>
          <div class="field col-span-2">
            <label class="check">
              <input type="checkbox" name="convert_to_normal" value="1" id="convertToNormal"> 일반 프로젝트로 전환
            </label>
            <div class="field-hint">전환 시 <b>기존 고객 연결이 필수</b>이며, 감사 로그(예외→일반 전환)에 기록됩니다.</div>
          </div>
          <?php endif; ?>
          <?php endif; ?>
          <div class="field col-span-2">
            <label class="field-label">현장 주소</label>
            <input type="text" name="site_address" class="input" maxlength="255" value="<?= $val('site_address') ?>">
          </div>
          <div class="field">
            <label class="field-label">공사유형</label>
            <input type="text" name="work_type" class="input" maxlength="50" value="<?= $val('work_type') ?>" placeholder="예: 아파트외벽, 옥상방수">
          </div>
          <div class="field">
            <?php // R8-A: 공사유형(구분) — 공정 보드 도장/인테리어 탭 분류. 레거시 미지정 프로젝트만 '미지정' 옵션 노출.
              $ctSel = $p['construction_type'] ?? ($project ? '' : 'painting');
              $ctLegacyNull = $project && ($ctSel === '' || $ctSel === null); ?>
            <label class="field-label">공사유형(구분)<?= $ctLegacyNull ? '' : '<span class="req">*</span>' ?></label>
            <select name="construction_type" class="select" <?= $ctLegacyNull ? '' : 'required' ?>>
              <?php if ($ctLegacyNull): ?><option value="" selected>미지정 (양쪽 보드 표시)</option><?php endif; ?>
              <option value="painting" <?= $ctSel === 'painting' ? 'selected' : '' ?>>도장</option>
              <option value="interior" <?= $ctSel === 'interior' ? 'selected' : '' ?>>인테리어</option>
            </select>
            <div class="field-hint">공정 보드 탭 분류 기준 — 변경 시 현재 공정이 다른 유형 전용 단계면 '대기중'으로 재배치됩니다.</div>
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
            <label class="field-label">영업담당</label>
            <select name="sales_user_id" class="select" <?= Rbac::isRole('super_admin') ? '' : 'disabled' ?>>
              <option value="">미지정</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= (isset($p['sales_user_id']) && (int) $p['sales_user_id'] === (int) $u['id']) ? 'selected' : '' ?>><?= e($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!Rbac::isRole('super_admin')): ?>
              <div class="field-hint">영업담당은 계약에서 자동 승계되며 <b>관리자만 변경</b>할 수 있습니다.</div>
            <?php endif; ?>
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
                <option value="<?= e($k) ?>" <?= ($p['contribution_mode'] ?? 'ratio') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
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
<?php if ($project && !empty($p['is_exception']) && Rbac::isRole('super_admin')): ?>
<script>
// 예외→일반 전환 체크 시 기존 고객 선택 필수화(서버측 검증과 동일 기준 — UI 선반영)
document.getElementById('convertToNormal')?.addEventListener('change', function () {
  var sel = document.getElementById('projCustomerSel');
  if (sel) { sel.required = this.checked; }
});
</script>
<?php endif; ?>
