<?php
/** @var array $project @var array $calc @var array $assignments @var array $history
 *  @var array $costs @var array $costSub @var array $costPg @var array $costFilters @var array $costWorkers @var array $staffOptions
 *  @var array $schedules @var array $workLogs @var array $photos @var array $docs
 *  @var array $statuses @var array $statusBadge @var array $statusHistory @var array $allowedTransitions @var bool $wl
 *  @var array|null $contract @var array|null $nextSchedule @var array $processStages
 *  @var array|null $auditRows @var bool $canProcessMove
 *
 * R3 projdetail: 상단 요약(항상 표시) + 하단 6탭(개요/직원·일정/공정/지출/사진·문서/이력).
 * 탭 콘텐츠는 projects/_tab_*.php 파셜로 분리(변수 스코프 공유 include), 탭 전환은 순수 JS + URL hash 복원.
 */
$wl = $wl ?? false;
$p = $project;
$today = date('Y-m-d');
// 지연 = 종결(완료/정산/취소/파기) 아닌 프로젝트가 준공예정 경과 + 준공 미처리 — 대시보드 delayedCond 와 동일 기준
$isDelayed = !empty($p['end_date']) && $p['end_date'] < $today && empty($p['actual_end_date'])
    && !in_array($p['status'], ['completed', 'settled', 'cancelled', 'terminated'], true);
$badgeClass = $statusBadge[$p['status']] ?? 'badge';
$progress = (int) $p['progress'];
// 상태 전환 버튼 라벨(from>to 특수 라벨 우선)
$transitionLabels = [
    'in_progress' => '진행 시작', 'paused' => '중단', 'completed' => '완료', 'cancelled' => '취소',
    'terminated' => '파기', 'warranty' => '하자보수 전환', 'settled' => '정산 완료', 'preparing' => '복구(진행 예정)',
];
$fromToLabels = [
    'paused>in_progress' => '재개', 'completed>in_progress' => '재개(완료 취소)',
    'terminated>in_progress' => '복구(재개)', 'warranty>completed' => '하자보수 종료(완료)',
];
$canManage = can('project.manage');
$canAssign = can('project.assign');
$canCost   = can('cost.manage');
$canFinance = can('finance.view') || can('cost.manage'); // 지출·순이익 등 재무 정보 열람 권한

// ── R3 탭 재설계 파생 변수 ──
$contract      = $contract ?? null;
$nextSchedule  = $nextSchedule ?? null;
$processStages = $processStages ?? [];
$auditRows     = $auditRows ?? null;
// 공정 이동 UI: process.move 권한 + 종결 상태 아님(ProcessController::move 서버 검증과 동일 기준)
$canMoveStage = ($canProcessMove ?? false)
    && !in_array($p['status'], ['completed', 'settled', 'cancelled', 'terminated'], true);
// 현재 공정 뱃지(색상은 process_stages.color — 형식 검증 후 사용, 아니면 기본색)
$psName  = $p['process_stage_name'] ?? null;
$psColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($p['process_stage_color'] ?? '')) ? $p['process_stage_color'] : '#64748b';
// 활성 배정(개요 칩 + 배정 파셜 $assignedUserIds)
$activeAssignments = array_values(array_filter($assignments, static fn($a) => $a['status'] === 'active'));
$activeAssignedIds = array_map(static fn($a) => (int) $a['user_id'], $activeAssignments);
// 요약 착공일: 실제 착공일 우선, 없으면 예정일(예정 표기)
$summaryStart = $p['actual_start_date']
    ? fmtdate($p['actual_start_date'])
    : ($p['start_date'] ? fmtdate($p['start_date']) . ' (예정)' : '-');
// 지출 미입력(비용 행 0건)과 0원 구분
$costEntered = !empty($costSub['has_entries']);
// 최근 활동 5건(개요 탭) — 상태 변경·공정 이동·파일 업로드 이벤트 병합(이미 로드된 데이터 재사용)
$recentEvents = [];
foreach ($statusHistory as $h) {
    $recentEvents[] = [
        'at'   => (string) $h['changed_at'],
        'text' => '상태 변경: ' . ($h['from_status'] !== null ? ($statuses[$h['from_status']] ?? $h['from_status']) : '(등록)')
                . ' → ' . ($statuses[$h['to_status']] ?? $h['to_status']) . ($h['reason'] ? ' · ' . $h['reason'] : ''),
        'who'  => $h['changed_by_name'] ?? '-',
    ];
}
foreach ($history as $h) {
    $recentEvents[] = [
        'at'   => (string) $h['changed_at'],
        'text' => '공정 이동: ' . ($h['from_name'] ?? '(시작)') . ' → ' . $h['to_name'],
        'who'  => $h['changed_by_name'] ?? '-',
    ];
}
foreach (array_merge($photos, $docs) as $f) {
    $recentEvents[] = [
        'at'   => (string) $f['created_at'],
        'text' => '파일 업로드: ' . $f['original_name'],
        'who'  => $f['uploaded_by_name'] ?? '-',
    ];
}
usort($recentEvents, static fn($a, $b) => strcmp($b['at'], $a['at']));
$recentEvents = array_slice($recentEvents, 0, 5);
?>
<div class="page proj-detail">
  <div class="detail-head">
    <div>
      <div class="detail-title"><?= e($p['name']) ?>
        <span class="badge <?= $badgeClass ?>" title="상태 정의 — 취소: 착공 전 철회 · 파기: 진행 중 계약관계 종료 · 일시 중단: 재개 가능 일시 정지 · 정산 완료: 완료 후 대금 정산 종료"><?= e($statuses[$p['status']] ?? $p['status']) ?></span>
        <?php if (!empty($p['is_exception'])): ?>
          <span class="badge badge-warn" title="예외 프로젝트 — 계약 연결 없이 수동 생성됨(최고 관리자)">예외</span>
        <?php endif; ?>
        <?php if ($psName !== null): ?>
          <span class="badge badge-stage" title="현재 공정(process_stage) — 이동은 '공정' 탭·공정 보드에서" style="--sc:<?= e($psColor) ?>"><?= e($psName) ?></span>
        <?php endif; ?>
      </div>
      <div class="detail-meta">
        <?= e($p['project_no']) ?> · 고객 <?= e($p['customer_name'] ?: '-') ?> ·
        현장주소 <?= e($p['site_address'] ?: '-') ?>
        <?php if ($isDelayed): ?><span class="text-danger"> · 지연</span><?php endif; ?>
      </div>
    </div>
    <div class="page-actions">
      <a href="<?= e(url('projects.index')) ?>" class="btn btn-outline">목록</a>
      <?php if ($canManage): ?>
        <?php foreach ($allowedTransitions as $to):
          $tLabel = $fromToLabels[$p['status'] . '>' . $to] ?? $transitionLabels[$to] ?? ($statuses[$to] ?? $to);
          $tClass = in_array($to, ['cancelled', 'terminated'], true) ? 'btn-danger'
                  : (in_array($to, ['completed', 'settled', 'in_progress'], true) ? 'btn-primary' : 'btn-outline');
        ?>
          <button type="button" class="btn <?= $tClass ?> btn-transition" data-to="<?= e($to) ?>" data-label="<?= e($tLabel) ?>"><?= e($tLabel) ?></button>
        <?php endforeach; ?>
        <a href="<?= e(url('projects.form', ['id' => $p['id']])) ?>" class="btn btn-outline">수정</a>
        <form method="post" action="<?= e(url('projects.delete')) ?>" onsubmit="return confirm('이 프로젝트를 삭제하시겠습니까?');">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button type="submit" class="btn btn-danger">삭제</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── 프로젝트 요약(항상 표시) — 정보 그룹 + 금액 그룹(재무 권한) 2블록. 프로젝트명은 상단 detail-title 과 중복이라 제외 ── -->
  <div class="card pad">
    <div class="section-head"><div class="st"><h2>프로젝트 요약</h2><span class="section-desc">금액은 재무 열람 권한자에게만 표시됩니다</span></div></div>
    <div class="proj-summary ps-grouped">
      <div class="ps-info">
        <div class="kv" data-sum="customer"><div class="kv-label">고객</div>
          <div class="kv-value"><?php if (!empty($p['customer_id'])): ?><a href="<?= e(url('customers.show', ['id' => $p['customer_id']])) ?>"><?= e($p['customer_name']) ?></a><?php else: ?><?= e($p['customer_name'] ?: '-') ?><?php endif; // 예외 프로젝트: 고객 미연결 시 스냅샷명 텍스트 ?></div></div>
        <div class="kv" data-sum="address"><div class="kv-label">현장 주소</div><div class="kv-value"><?= e($p['site_address'] ?: '-') ?></div></div>
        <div class="kv" data-sum="status"><div class="kv-label">프로젝트 상태</div>
          <div class="kv-value"><span class="badge <?= $badgeClass ?>"><?= e($statuses[$p['status']] ?? $p['status']) ?></span></div></div>
        <div class="kv" data-sum="stage" title="현재 공정(projects.process_stage_id 기준)"><div class="kv-label">현재 공정</div>
          <div class="kv-value"><?php if ($psName !== null): ?><span class="badge badge-stage" style="--sc:<?= e($psColor) ?>"><?= e($psName) ?></span><?php else: ?><span class="badge badge-muted">공정 미배치</span><?php endif; ?></div></div>
        <div class="kv" data-sum="manager"><div class="kv-label">담당 관리자</div><div class="kv-value"><?= e($p['site_manager_name'] ?? '-') ?></div></div>
        <div class="kv" data-sum="start"><div class="kv-label">착공일</div><div class="kv-value"><?= e($summaryStart) ?></div></div>
        <div class="kv" data-sum="end"><div class="kv-label">준공 예정일</div>
          <div class="kv-value<?= $isDelayed ? ' text-danger' : '' ?>"><?= fmtdate($p['end_date']) ?><?= $isDelayed ? ' (지연)' : '' ?></div></div>
      </div>
      <?php if ($canFinance): ?>
      <div class="ps-money">
        <div class="kv" data-sum="amount" title="부가세를 포함한 계약 금액 — 현금(입금) 기준 축">
          <div class="kv-label">계약 총액(VAT 포함)</div><div class="kv-value"><?= moneyCell($calc['contract_amount']) ?></div></div>
        <?php // R12: 확정 매출 = 공급가액(VAT 제외), 입금 시점 인식. 순이익 = 확정 매출 − 지출.
          $projConfirmedRev = AccountingService::projectConfirmedRevenue($p);
          $realProfit = $projConfirmedRev - (int) $calc['actual_cost']; ?>
        <div class="kv" data-sum="supply" title="확정 매출(공급가액·VAT 제외) = 누적 순입금의 공급가 부분 — 실제 입금된 금액을 부가세 제외로 인식(R12). 부가세 포함 현금은 '입금·정산' 탭의 누적 입금액">
          <div class="kv-label">확정 매출(공급가액)</div><div class="kv-value"><?= moneyCell($projConfirmedRev) ?></div></div>
        <div class="kv" data-sum="cost" title="지출 총액 = 확정 상태 실제 비용 합계 (임시 저장·확인 대기·취소 제외) — 회계 지표의 '원가 총액'과 동일 값">
          <div class="kv-label">지출 총액</div><div class="kv-value"><?= $costEntered ? moneyCell($calc['actual_cost']) : '<span class="muted">미입력</span>' ?></div></div>
        <div class="kv" data-sum="profit" title="실제 순이익 = 확정 매출(공급가액) − 지출 총액<?= $costEntered ? '' : ' — 지출 미입력 시 계산하지 않음' ?>">
          <div class="kv-label">실제 순이익</div>
          <div class="kv-value <?= $costEntered && $realProfit < 0 ? 'text-danger' : '' ?>"><?= $costEntered ? moneyCell($realProfit) : '<span class="muted">-</span>' ?></div></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── 하단 6탭 (순수 JS + URL hash 복원) ── -->
  <div class="tabs" id="projTabs">
    <div class="tab active" data-tab="overview">개요</div>
    <div class="tab" data-tab="staff">직원·일정</div>
    <div class="tab" data-tab="process">공정</div>
    <?php if ($canFinance): ?><div class="tab" data-tab="costs">지출</div><?php endif; ?>
    <?php if ($canFinance): ?><div class="tab" data-tab="settlement">입금·정산</div><?php endif; ?>
    <div class="tab" data-tab="files">사진·문서</div>
    <div class="tab" data-tab="history">이력</div>
  </div>

  <div class="proj-panels" id="projPanels">
    <div class="tab-panel active" data-panel="overview">
      <?php include __DIR__ . '/_tab_overview.php'; ?>
    </div>
    <div class="tab-panel" data-panel="staff">
      <?php include __DIR__ . '/_tab_staff.php'; ?>
    </div>
    <div class="tab-panel" data-panel="process">
      <?php include __DIR__ . '/_tab_process.php'; ?>
    </div>

  <?php if ($canFinance): ?>
    <div class="tab-panel" data-panel="costs">
      <?php include __DIR__ . '/_tab_costs.php'; ?>
    </div>
    <div class="tab-panel" data-panel="settlement">
      <?php include __DIR__ . '/_tab_settlement.php'; ?>
    </div>
  <?php endif; ?>

    <div class="tab-panel" data-panel="files">
      <?php include __DIR__ . '/_tab_files.php'; ?>
    </div>
    <div class="tab-panel" data-panel="history">
      <?php include __DIR__ . '/_tab_history.php'; ?>
    </div>
  </div>
</div>

<?php if ($canManage && $allowedTransitions): ?>
<!-- 상태 전환 모달 폼 — 서버(projects.transition)가 전이 규칙·사유 필수를 재검증한다 -->
<div id="transitionFormWrap" style="display:none">
  <form method="post" action="<?= e(url('projects.transition')) ?>" class="form" id="transitionForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
    <input type="hidden" name="to_status" value="">
    <div class="form-grid">
      <div class="field"><label class="field-label">처리일 <span class="req">*</span></label>
        <input type="date" name="effective_date" class="input" value="<?= e(date('Y-m-d')) ?>" required></div>
      <div class="field"><label class="field-label">처리자</label>
        <input type="text" class="input" value="<?= e(auth_user()['name'] ?? '') ?>" disabled title="로그인 사용자로 자동 기록"></div>
      <div class="field col-span-2"><label class="field-label">처리 사유 <span class="tr-reason-req req" style="display:none">*</span></label>
        <input type="text" name="reason" class="input" placeholder="예: 발주처 요청으로 중단"></div>
      <div class="tr-extra" style="display:none">
        <div class="form-grid">
          <div class="field"><label class="field-label">진행된 공정</label>
            <input type="text" class="input" value="<?= e($p['process_stage_name'] ?? '-') ?> · 진행률 <?= $progress ?>%" disabled></div>
          <div class="field"><label class="field-label">발생 지출(표시)</label>
            <input type="text" class="input" value="<?= e(number_format((float) $calc['actual_cost'])) ?>원" disabled title="지출 총액 — 확정 실제 비용 합계"></div>
          <div class="field"><label class="field-label">청구 금액</label>
            <input type="text" inputmode="decimal" name="billed_amount" class="input" value="0" title="진행분 기성 청구 금액(기록용)"></div>
          <div class="field"><label class="field-label">환불 금액</label>
            <input type="text" inputmode="decimal" name="refund_amount" class="input" value="0" title="연결 계약이 있으면 입금 내역에 환불 행으로 기록"></div>
          <div class="field"><label class="field-label">정산 여부</label>
            <label class="check"><input type="checkbox" name="is_settled" value="1"> 정산 완료됨</label></div>
          <div class="field"><label class="field-label">후속 조치</label>
            <input type="text" name="followup" class="input" placeholder="예: 잔여 자재 회수, 세금계산서 수정 발행"></div>
          <div class="field col-span-2"><label class="field-label">메모</label>
            <textarea name="memo" class="input" rows="2"></textarea></div>
        </div>
      </div>
    </div>
    <div class="ta-r mt-8"><button type="submit" class="btn btn-primary" id="transitionSubmit">처리</button></div>
  </form>
</div>
<script>
(function () {
  var wrap = document.getElementById('transitionFormWrap');
  var form = document.getElementById('transitionForm');
  if (!wrap || !form) return;
  var REASON_REQUIRED = <?= json_encode(array_values(array_filter($allowedTransitions, fn($t) => StatusService::reasonRequired($p['status'], $t)))) ?>;
  var EXTRA = ['cancelled', 'terminated'];
  document.querySelectorAll('.btn-transition').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var to = btn.dataset.to;
      form.querySelector('[name="to_status"]').value = to;
      var extra = wrap.querySelector('.tr-extra');
      extra.style.display = EXTRA.indexOf(to) >= 0 ? '' : 'none';
      var reqMark = wrap.querySelector('.tr-reason-req');
      var reasonInput = form.querySelector('[name="reason"]');
      var required = REASON_REQUIRED.indexOf(to) >= 0;
      reqMark.style.display = required ? '' : 'none';
      reasonInput.required = required;
      document.getElementById('transitionSubmit').textContent = btn.dataset.label + ' 처리';
      EDEN.modal({ title: '상태 전환 — ' + btn.dataset.label, body: wrap, footer: false });
      wrap.style.display = '';
    });
  });
})();
</script>
<?php endif; ?>

<script>
// ── R3 projdetail: 탭 전환(순수 JS) + URL hash 유지·새로고침 복원 + 이력 '더보기' ──
(function () {
  'use strict';
  var tabs = document.querySelectorAll('#projTabs .tab');
  var panels = document.querySelectorAll('#projPanels > .tab-panel');
  if (!tabs.length || !panels.length) return;
  var KEY = 'projTab:<?= (int) $p['id'] ?>'; // 지출 저장·업로드 등 hash 없는 리다이렉트 복귀용
  var valid = {};
  tabs.forEach(function (t) { valid[t.getAttribute('data-tab')] = true; });

  function activate(key, updateHash) {
    if (!valid[key]) return;
    tabs.forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-tab') === key); });
    panels.forEach(function (pn) { pn.classList.toggle('active', pn.getAttribute('data-panel') === key); });
    try { sessionStorage.setItem(KEY, key); } catch (e) { /* 프라이빗 모드 등 */ }
    if (updateHash !== false) { history.replaceState(null, '', '#' + key); }
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function () { activate(t.getAttribute('data-tab')); });
  });

  function initialTab() {
    var h = (location.hash || '').replace('#', '');
    if (valid[h]) return h;
    if (h === 'cost' || h === 'costs') return 'costs'; // 레거시 #costs 앵커(지출 필터·페이지네이션) — 내부 키는 costs 유지
    // 지출 필터 GET 파라미터가 있으면 지출 탭으로 복귀
    var qs = new URLSearchParams(location.search);
    var costKeys = ['cost_cat', 'cost_worker', 'cost_from', 'cost_to', 'cost_page'];
    for (var i = 0; i < costKeys.length; i++) {
      if (qs.get(costKeys[i])) return 'costs';
    }
    try {
      var saved = sessionStorage.getItem(KEY);
      if (saved && valid[saved]) return saved;
    } catch (e) { /* noop */ }
    return 'overview';
  }
  activate(initialTab(), false); // 첫 진입 시 URL 은 건드리지 않음
  window.addEventListener('hashchange', function () {
    var h = location.hash.replace('#', '');
    if (valid[h] || h === 'costs') activate(valid[h] ? h : 'costs', false);
  });

  // 이력 탭 '더보기' — 긴 이력은 기본 최근 5건만, 클릭 시 전체 펼침
  document.querySelectorAll('.hist-more').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var box = document.getElementById(btn.getAttribute('data-target'));
      if (!box) return;
      box.querySelectorAll('.hist-hidden').forEach(function (el) { el.classList.remove('hist-hidden'); });
      btn.style.display = 'none';
    });
  });
})();
</script>
