<?php
/** @var array $customer @var array $activities @var array $contacts @var array $quotes
 *  @var array $contracts @var array $projects @var array $leads */
$typeLabel = ['individual' => '개인', 'company' => '법인'];
$statusBadge = ['active' => 'badge-ok', 'inactive' => 'badge-muted', 'blacklist' => 'badge-danger'];
$statusLabel = ['active' => '활성', 'inactive' => '비활성', 'blacklist' => '블랙리스트'];
$actLabel = ['call' => '전화', 'visit' => '방문', 'sms' => '문자', 'email' => '이메일', 'note' => '메모'];
$overdue = !empty($customer['next_contact_date']) && $customer['next_contact_date'] < date('Y-m-d');
?>
<div class="page">
  <div class="detail-head">
    <div>
      <div class="detail-title">
        <?= e($customer['name']) ?>
        <span class="badge badge-info"><?= e($typeLabel[$customer['type']] ?? $customer['type']) ?></span>
        <span class="badge <?= $statusBadge[$customer['status']] ?? '' ?>"><?= e($statusLabel[$customer['status']] ?? $customer['status']) ?></span>
      </div>
      <div class="detail-meta">
        <?= e($customer['company_name'] ?: '') ?>
        <?= $customer['company_name'] ? ' · ' : '' ?>담당영업 <?= e($customer['sales_user_name'] ?: '미지정') ?>
        · 등록일 <?= fmtdate($customer['created_at']) ?>
      </div>
    </div>
    <div class="page-actions">
      <?php if (can('customer.manage')): ?>
        <a class="btn btn-outline" href="<?= e(url('customers.form', ['id' => $customer['id']])) ?>">수정</a>
      <?php endif; ?>
      <?php if (can('customer.delete')): ?>
        <button type="button" class="btn btn-danger" id="btnDeleteCustomer">삭제</button>
      <?php endif; ?>
      <a class="btn btn-outline" href="<?= e(url('customers.index')) ?>">목록</a>
    </div>
  </div>

  <div class="row" style="margin-bottom:16px">
    <div class="col card" style="min-width:260px">
      <div class="card-body">
        <div class="kv-row">
          <div class="kv"><span class="kv-label">연락처</span><span class="kv-value" style="font-size:14px"><?= e($customer['phone'] ?: '-') ?></span></div>
          <div class="kv"><span class="kv-label">이메일</span><span class="kv-value" style="font-size:14px"><?= e($customer['email'] ?: '-') ?></span></div>
          <div class="kv"><span class="kv-label">담당자</span><span class="kv-value" style="font-size:14px"><?= e($customer['contact_name'] ?: '-') ?></span></div>
          <div class="kv"><span class="kv-label">최근상담일</span><span class="kv-value" style="font-size:14px"><?= fmtdate($customer['last_consult_date']) ?></span></div>
          <div class="kv">
            <span class="kv-label">다음연락예정일</span>
            <span class="kv-value" style="font-size:14px<?= $overdue ? ';color:var(--danger)' : '' ?>">
              <?= fmtdate($customer['next_contact_date']) ?><?= $overdue ? ' (지연)' : '' ?>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="tabs" id="custTabs">
    <div class="tab active" data-tab="info">기본정보</div>
    <div class="tab" data-tab="timeline">활동타임라인</div>
    <div class="tab" data-tab="leads">영업기회 (<?= count($leads) ?>)</div>
    <div class="tab" data-tab="quotes">견적 (<?= count($quotes) ?>)</div>
    <div class="tab" data-tab="contracts">계약 (<?= count($contracts) ?>)</div>
    <div class="tab" data-tab="projects">프로젝트 (<?= count($projects) ?>)</div>
    <div class="tab" data-tab="contacts">추가연락처 (<?= count($contacts) ?>)</div>
    <div class="tab" data-tab="memo">내부메모</div>
  </div>

  <div class="tab-panel active" data-panel="info">
    <div class="card"><div class="card-body">
      <dl class="dl">
        <dt>주소</dt><dd><?= e($customer['address'] ?: '-') ?></dd>
        <dt>현장주소</dt><dd><?= e($customer['site_address'] ?: '-') ?></dd>
        <dt>유입경로</dt><dd><?= e($customer['source'] ?: '-') ?></dd>
        <dt>관심공사</dt><dd><?= e($customer['interest_type'] ?: '-') ?></dd>
        <dt>예상규모</dt><dd><?= e($customer['expected_scale'] ?: '-') ?></dd>
        <dt>예상예산</dt><dd><?= money($customer['expected_budget'] !== null ? (float) $customer['expected_budget'] : null) ?>원</dd>
        <dt>상담희망일</dt><dd><?= fmtdate($customer['desired_consult_date']) ?></dd>
        <dt>태그</dt><dd><?= e($customer['tags'] ?: '-') ?></dd>
        <dt>개인정보동의</dt><dd><?= ((int) $customer['privacy_agreed'] === 1) ? '동의' : '미동의' ?></dd>
      </dl>
    </div></div>
  </div>

  <div class="tab-panel" data-panel="timeline">
    <div class="card"><div class="card-body">
      <?php if (can('customer.view')): ?>
      <form id="activityForm" class="form" style="margin-bottom:18px;border-bottom:1px solid var(--line-2);padding-bottom:16px">
        <div class="form-grid-3">
          <label class="field">
            <span class="field-label">활동유형</span>
            <select name="activity_type" class="select">
              <?php foreach ($actLabel as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="field">
            <span class="field-label">일시</span>
            <input type="datetime-local" name="activity_at" class="input" value="<?= date('Y-m-d\TH:i') ?>">
          </label>
          <label class="field" style="justify-content:flex-end">
            <button type="submit" class="btn btn-primary">활동 추가</button>
          </label>
        </div>
        <label class="field">
          <span class="field-label">내용</span>
          <textarea name="content" class="input" rows="2" placeholder="상담 내용을 입력하세요"></textarea>
        </label>
      </form>
      <?php endif; ?>
      <div class="timeline" id="activityTimeline">
        <?php if (!$activities): ?><div class="empty" style="padding:20px"><div class="empty-title">기록된 활동이 없습니다.</div></div><?php endif; ?>
        <?php foreach ($activities as $a): ?>
          <div class="timeline-item <?= e($a['activity_type']) ?>">
            <div class="timeline-time"><?= fmtdate($a['activity_at'], 'Y-m-d H:i') ?> · <?= e($a['user_name'] ?: '') ?></div>
            <div class="timeline-body"><span class="timeline-tag">[<?= e($actLabel[$a['activity_type']] ?? $a['activity_type']) ?>]</span> <?= e($a['content'] ?: '') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div></div>
  </div>

  <div class="tab-panel" data-panel="leads">
    <div class="card"><div class="card-body">
      <?php if (!$leads): ?><div class="empty"><div class="empty-title">연결된 영업기회가 없습니다.</div></div><?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>단계</th><th>공사종류</th><th class="num">예상금액</th><th>성공확률</th><th>다음연락예정일</th></tr></thead>
          <tbody><?php foreach ($leads as $l): ?>
            <tr>
              <td><span class="badge" style="background:<?= e($l['stage_color'] ?: '#e5e7eb') ?>22;color:<?= e($l['stage_color'] ?: '#374151') ?>"><?= e($l['stage_name']) ?></span></td>
              <td><?= e($l['work_type'] ?: '-') ?></td>
              <td class="num"><?= money($l['expected_amount'] !== null ? (float) $l['expected_amount'] : null) ?></td>
              <td><?= pct($l['win_probability'] !== null ? (float) $l['win_probability'] : null) ?></td>
              <td class="nowrap"><?= fmtdate($l['next_contact_date']) ?></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
      <?php if (can('pipeline.manage')): ?>
        <div style="margin-top:12px"><a class="btn btn-outline btn-sm" href="<?= e(url('pipeline.index')) ?>">파이프라인에서 신규 영업기회 등록</a></div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="tab-panel" data-panel="quotes">
    <div class="card"><div class="card-body">
      <?php if (!$quotes): ?><div class="empty"><div class="empty-title">연결된 견적이 없습니다.</div></div><?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>견적번호</th><th>상태</th><th>유효기한</th><th>등록일</th></tr></thead>
          <tbody><?php foreach ($quotes as $q): ?>
            <tr><td><?= e($q['quote_no']) ?></td><td><span class="badge"><?= e($q['status']) ?></span></td><td><?= fmtdate($q['valid_until']) ?></td><td><?= fmtdate($q['created_at']) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="tab-panel" data-panel="contracts">
    <div class="card"><div class="card-body">
      <?php if (!$contracts): ?><div class="empty"><div class="empty-title">연결된 계약이 없습니다.</div></div><?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>계약번호</th><th class="num">계약금액</th><th>상태</th><th>계약일</th></tr></thead>
          <tbody><?php foreach ($contracts as $c): ?>
            <tr><td><?= e($c['contract_no']) ?></td><td class="num"><?= money((float) $c['contract_amount']) ?></td><td><span class="badge"><?= e($c['status']) ?></span></td><td><?= fmtdate($c['contract_date']) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="tab-panel" data-panel="projects">
    <div class="card"><div class="card-body">
      <?php if (!$projects): ?><div class="empty"><div class="empty-title">연결된 프로젝트가 없습니다.</div></div><?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>프로젝트번호</th><th>이름</th><th>상태</th><th>진행률</th></tr></thead>
          <tbody><?php foreach ($projects as $p): ?>
            <tr><td><?= e($p['project_no']) ?></td><td><?= e($p['name']) ?></td><td><span class="badge"><?= e($p['status']) ?></span></td><td><?= (int) $p['progress'] ?>%</td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="tab-panel" data-panel="contacts">
    <div class="card"><div class="card-body">
      <?php if (!$contacts): ?><div class="empty"><div class="empty-title">추가 연락처가 없습니다.</div></div><?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>이름</th><th>직책</th><th>연락처</th><th>이메일</th><th>비고</th></tr></thead>
          <tbody><?php foreach ($contacts as $ct): ?>
            <tr>
              <td><?= e($ct['name']) ?><?= (int) $ct['is_primary'] === 1 ? ' <span class="badge badge-info">주담당</span>' : '' ?></td>
              <td><?= e($ct['position'] ?: '-') ?></td><td><?= e($ct['phone'] ?: '-') ?></td><td><?= e($ct['email'] ?: '-') ?></td><td><?= e($ct['memo'] ?: '-') ?></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="tab-panel" data-panel="memo">
    <div class="card"><div class="card-body">
      <div style="white-space:pre-wrap;font-size:13.5px"><?= e($customer['memo'] ?: '등록된 메모가 없습니다.') ?></div>
    </div></div>
  </div>
</div>
<?php
$inlineScript = <<<JS
(function(){
  var tabs = document.querySelectorAll('#custTabs .tab');
  tabs.forEach(function(t){
    t.addEventListener('click', function(){
      tabs.forEach(function(x){ x.classList.remove('active'); });
      document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
      t.classList.add('active');
      var panel = document.querySelector('.tab-panel[data-panel="' + t.dataset.tab + '"]');
      if (panel) panel.classList.add('active');
    });
  });

  var form = document.getElementById('activityForm');
  if (form) {
    form.addEventListener('submit', async function(e){
      e.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      try {
        var fd = new FormData(form);
        fd.append('customer_id', {$customer['id']});
        var data = await api('activities.save', fd);
        toast('활동이 기록되었습니다.', 'success');
        var actLabels = {call:'전화', visit:'방문', sms:'문자', email:'이메일', note:'메모'};
        var a = data.activity;
        var div = document.createElement('div');
        div.className = 'timeline-item ' + a.activity_type;
        div.innerHTML = '<div class="timeline-time">' + a.activity_at + ' · ' + (a.user_name || '') + '</div>' +
          '<div class="timeline-body"><span class="timeline-tag">[' + (actLabels[a.activity_type] || a.activity_type) + ']</span> ' +
          (a.content || '').replace(/</g,'&lt;') + '</div>';
        var tl = document.getElementById('activityTimeline');
        var empty = tl.querySelector('.empty');
        if (empty) empty.remove();
        tl.insertBefore(div, tl.firstChild);
        form.reset();
        form.querySelector('input[name="activity_at"]').value = new Date().toISOString().slice(0,16);
      } catch (err) {
        toast(err.message, 'error');
      } finally {
        btn.disabled = false;
      }
    });
  }

  var delBtn = document.getElementById('btnDeleteCustomer');
  if (delBtn) {
    delBtn.addEventListener('click', async function(){
      var ok = await EDEN.confirm('이 고객을 삭제하시겠습니까?', {danger:true, okLabel:'삭제'});
      if (!ok) return;
      try {
        await api('customers.delete', {id: {$customer['id']}});
        toast('삭제되었습니다.', 'success');
        location.href = EDEN.url('customers.index');
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }
})();
JS;
?>
