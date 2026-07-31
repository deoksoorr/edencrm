<?php
/**
 * [지출] 탭(구 '원가' 탭 — 표기만 변경, 내부 키 costs 유지) — R2 원가 관리 섹션 이동(projects/show.php 에서 include, canFinance 게이트는 호출부).
 * 입력 폼·목록·필터·페이지네이션·CSV 는 CostsController/CostService 로직 그대로 사용(수정 금지).
 */
?>
<?php
  // ── 지출 관리 — 일자별 자재비·인건비 상세 입력·목록 (브리프 §3) ──
  $costCats = CostService::CATEGORIES;
  $costStatuses = CostService::STATUSES;
  $costStatusBadge = ['draft' => 'badge-muted', 'pending' => 'badge-warn', 'confirmed' => 'badge-ok', 'cancelled' => 'badge-danger'];
  $costBase = [
      'id' => $p['id'],
      'cost_cat' => $costFilters['cat'], 'cost_worker' => $costFilters['worker'],
      'cost_from' => $costFilters['from'] ?? '', 'cost_to' => $costFilters['to'] ?? '',
  ];
  $costFiltered = $costFilters['cat'] !== '' || $costFilters['worker'] !== '' || !empty($costFilters['from']) || !empty($costFilters['to']);
  /** 수량·일수 표시: 30.00 → 30, 2.50 → 2.5 */
  $fmtQty = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
?>
<div class="card pad" id="costs">
  <div class="section-head">
    <div class="st"><h2>지출 관리</h2></div>
    <a href="<?= e(url('costs.export', array_merge(['project_id' => $p['id']], array_slice($costBase, 1)))) ?>" class="btn btn-outline btn-sm">CSV 다운로드</a>
  </div>

  <form method="get" action="<?= e(url('projects.show')) ?>#costs" class="toolbar">
    <input type="hidden" name="r" value="projects.show">
    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
    <select name="cost_cat" class="select">
      <option value="">전체 구분</option>
      <?php foreach ($costCats as $ck => $cl): ?>
        <option value="<?= e($ck) ?>" <?= $costFilters['cat'] === $ck ? 'selected' : '' ?>><?= e($cl) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="cost_worker" class="select">
      <option value="">전체 작업자</option>
      <?php foreach ($costWorkers as $w): ?>
        <option value="<?= e($w['wkey']) ?>" <?= $costFilters['worker'] === (string) $w['wkey'] ? 'selected' : '' ?>><?= e($w['wname']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="cost_from" class="input" value="<?= e($costFilters['from'] ?? '') ?>" title="발생일 시작">
    <input type="date" name="cost_to" class="input" value="<?= e($costFilters['to'] ?? '') ?>" title="발생일 끝">
    <button type="submit" class="btn btn-outline btn-sm">필터</button>
    <?php if ($costFiltered): ?>
      <a href="<?= e(url('projects.show', ['id' => $p['id']])) ?>#costs" class="btn btn-ghost btn-sm">초기화</a>
    <?php endif; ?>
  </form>

  <?php if (!$costs): ?>
    <div class="empty">
      <div class="empty-title"><?= $costFiltered ? '조건에 맞는 비용이 없습니다.' : '등록된 비용이 없습니다 (지출 미입력).' ?></div>
    </div>
  <?php else: ?>
    <div class="table-wrap mt-8">
      <table class="data">
        <thead>
          <tr>
            <th>발생일</th><th>구분</th><th>내용/자재명</th><th class="num">수량×단가</th>
            <th class="num" title="지출 총액에는 확정 상태만 포함됩니다">금액</th>
            <th>작업자</th><th>공급처</th><th>상태</th><th>증빙</th><th>메모</th>
            <?php if ($canCost): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($costs as $c): ?>
            <?php
              $isCancelled = $c['cost_status'] === 'cancelled';
              // 수량×단가 요약: 자재 30말 × 95,000 · 인건 6일 × 280,000 · 시간 8h × 25,000
              $qtyDesc = '-';
              if ($c['category'] === 'labor') {
                  if ($c['work_days'] !== null && (float) $c['work_days'] > 0) {
                      $qtyDesc = $fmtQty($c['work_days']) . '일 × ' . money((float) $c['unit_price']);
                  } elseif ($c['work_hours'] !== null && (float) $c['work_hours'] > 0) {
                      $qtyDesc = $fmtQty($c['work_hours']) . 'h × ' . money((float) $c['unit_price']);
                  }
              } elseif ($c['qty'] !== null && (float) $c['qty'] > 0 && $c['unit_price'] !== null) {
                  $qtyDesc = $fmtQty($c['qty']) . ($c['unit'] ? e($c['unit']) : '') . ' × ' . money((float) $c['unit_price']);
              }
              $workerLabel = $c['worker_user_name'] ?? ($c['worker_name'] ?? '-');
              $editData = $canCost && !$isCancelled ? [
                  'id' => (int) $c['id'], 'category' => $c['category'], 'cost_status' => $c['cost_status'],
                  'item_name' => $c['item_name'], 'spec' => $c['spec'], 'qty' => $c['qty'], 'unit' => $c['unit'],
                  'unit_price' => $c['unit_price'], 'amount' => (int) $c['amount'],
                  'worker_id' => $c['worker_id'], 'worker_name' => $c['worker_name'],
                  'work_days' => $c['work_days'], 'work_hours' => $c['work_hours'],
                  'vendor' => $c['vendor'], 'adjust_reason' => $c['adjust_reason'],
                  'spent_date' => $c['spent_date'], 'memo' => $c['memo'],
              ] : null;
            ?>
            <tr <?= $isCancelled ? 'class="row-dim"' : '' ?>>
              <td class="nowrap"><?= fmtdate($c['spent_date']) ?></td>
              <td class="nowrap"><?= e(CostService::categoryLabel((string) $c['category'])) ?><?= $c['type'] === 'estimate' ? ' <span class="badge badge-muted">예상</span>' : '' ?></td>
              <td class="wrap"><?= e($c['item_name'] ?: '-') ?><?php if ($c['spec']): ?> <span class="muted"><?= e($c['spec']) ?></span><?php endif; ?></td>
              <td class="num mono nowrap"><?= $qtyDesc ?></td>
              <td class="num mono" <?= $c['adjust_reason'] ? 'title="조정 사유: ' . e($c['adjust_reason']) . '"' : '' ?>>
                <?= money((float) $c['amount']) ?><?= $c['adjust_reason'] ? ' <span class="muted" title="' . e($c['adjust_reason']) . '">✎</span>' : '' ?></td>
              <td class="nowrap"><?= e($workerLabel) ?></td>
              <td class="nowrap"><?= e($c['vendor'] ?: '-') ?></td>
              <td><span class="badge <?= $costStatusBadge[$c['cost_status']] ?? 'badge' ?>" title="지출 총액에는 확정만 포함"><?= e(CostService::statusLabel((string) $c['cost_status'])) ?></span></td>
              <td><?php if ($c['receipt_file_id']): ?><a href="<?= e(url('files.download', ['id' => $c['receipt_file_id']])) ?>" target="_blank" class="btn btn-ghost btn-sm">보기</a><?php else: ?><span class="muted">-</span><?php endif; ?></td>
              <td class="wrap"><?= e($c['memo'] ?: '-') ?></td>
              <?php if ($canCost): ?>
              <td class="nowrap">
                <?php if (!$isCancelled): ?>
                  <button type="button" class="btn btn-ghost btn-sm" data-cost-edit="<?= e(json_encode($editData, JSON_UNESCAPED_UNICODE)) ?>">수정</button>
                  <form method="post" action="<?= e(url('costs.cancel')) ?>" style="display:inline" onsubmit="return confirm('이 비용을 무효 처리하시겠습니까? 무효 처리하면 지출 총액에서 제외됩니다. (기록은 보존)');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">비용 무효</button>
                  </form>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($costPg['pages'] > 1): ?>
      <?php View::partial('partials/pager', [
          'pg'  => $costPg,
          'url' => fn (int $p): string => url('projects.show', array_merge($costBase, ['cost_page' => $p])) . '#costs',
      ]); ?>
    <?php endif; ?>
  <?php endif; ?>

  <div class="kv-row mt-14">
    <div class="kv" title="확정 상태 자재비 소계"><div class="kv-label">자재비 소계</div>
      <div class="kv-value mono"><?= $costSub['has_entries'] ? money((float) $costSub['material']) . '원' : '미입력' ?></div></div>
    <div class="kv" title="확정 상태 인건비 소계"><div class="kv-label">인건비 소계</div>
      <div class="kv-value mono"><?= $costSub['has_entries'] ? money((float) $costSub['labor']) . '원' : '미입력' ?></div></div>
    <div class="kv" title="외주비·장비비·운송비·식비·폐기물 처리비·기타 확정 소계"><div class="kv-label">기타 소계</div>
      <div class="kv-value mono"><?= $costSub['has_entries'] ? money((float) $costSub['other']) . '원' : '미입력' ?></div></div>
    <div class="kv" title="지출 총액 = 확정 상태 실제 비용 합계 (임시 저장·확인 대기·취소 제외) — 회계 지표의 '원가 총액'과 동일 값"><div class="kv-label">지출 총액</div>
      <div class="kv-value mono"><strong><?= e(CostService::totalLabel($costSub)) ?></strong></div></div>
  </div>

  <?php if ($canCost): ?>
    <div class="section-head mt-14"><div class="st"><h3 id="costFormTitle">비용 등록</h3></div>
      <button type="button" id="costFormReset" class="btn btn-ghost btn-sm" style="display:none">새 등록으로 전환</button></div>
    <form method="post" action="<?= e(url('costs.save')) ?>" enctype="multipart/form-data" class="form-grid-3" id="costForm">
      <?= csrf_field() ?>
      <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
      <input type="hidden" name="type" value="actual">
      <input type="hidden" name="id" value="">
      <div class="field"><label class="field-label">발생일<span class="req">*</span></label>
        <input type="date" name="spent_date" class="input" value="<?= e($today) ?>" required></div>
      <div class="field"><label class="field-label">비용 구분<span class="req">*</span></label>
        <select name="category" class="select" required>
          <?php foreach ($costCats as $ck => $cl): ?>
            <option value="<?= e($ck) ?>"><?= e($cl) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label class="field-label">상태</label>
        <select name="cost_status" class="select" title="지출 총액에는 확정만 포함됩니다">
          <option value="confirmed">확정</option>
          <option value="draft">임시 저장</option>
          <option value="pending">확인 대기</option>
        </select></div>
      <?php /* 구분을 고르면 무엇을 어떻게 넣는지 한 줄로 안내한다. 문구는 서버 오류 메시지와
              같은 출처(CostService::CATEGORY_GUIDE)를 써서 화면과 서버가 어긋나지 않게 한다.
              전체 폭(3칸)을 쓴다 — 2칸이면 첫 행 셋째 칸이 비고 안내가 상태와 나란히 놓여
              라벨 높이가 어긋난다. 행 하나를 통째로 쓰면 아래 입력 규칙의 머리말로 읽힌다. */ ?>
      <div class="col-span-3" id="catGuide" style="display:none">
        <div class="form-note">
          <b id="catGuideHint"></b> <span class="ex" id="catGuideExample"></span>
        </div></div>
      <div class="field"><label class="field-label">내용/자재명<span class="req">*</span></label>
        <input type="text" name="item_name" class="input" required
               placeholder="예: 수성 외부용 상도 페인트 / 현장 시공(도장)"
               title="무엇에 쓴 비용인지 — 목록·CSV·증빙 대조의 기준이 됩니다"></div>
      <div class="field"><label class="field-label">규격</label>
        <input type="text" name="spec" class="input" placeholder="예: KCC 숲으로 18L"></div>
      <div class="field"><label class="field-label">공급처 <span class="muted">(자재비)</span></label>
        <input type="text" name="vendor" class="input" placeholder="예: 페인트나라"></div>
      <div class="field"><label class="field-label">수량<span class="req" id="qtyStar">*</span> <span class="muted" id="qtyHint"></span></label>
        <input type="number" name="qty" class="input" step="0.01" min="0" placeholder="자재 수량"></div>
      <div class="field"><label class="field-label">단위</label>
        <input type="text" name="unit" class="input" placeholder="말/EA/㎡/식"></div>
      <div class="field"><label class="field-label">단가<span class="req">*</span> <span class="muted" id="unitPriceHint">(인건비는 일당/시급)</span></label>
        <input type="number" name="unit_price" class="input" min="0" placeholder="원" required></div>
      <div class="field"><label class="field-label">작업자(직원) <span class="muted">(인건비)</span></label>
        <select name="worker_id" class="select">
          <option value="">— 직원 선택 —</option>
          <?php foreach ($staffOptions as $u): ?>
            <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?><?= $u['position'] ? ' (' . e($u['position']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label class="field-label">작업자명(직접 입력) <span class="muted">(외부 인력)</span></label>
        <input type="text" name="worker_name" class="input" placeholder="직원 미선택 시 이름 입력"></div>
      <div class="field"><label class="field-label">일수 / 시간<span class="req" id="daysStar" style="display:none">*</span> <span class="muted">(인건비)</span></label>
        <div class="flex items-center gap-8">
          <input type="number" name="work_days" class="input" step="0.5" min="0" placeholder="일수" title="작업 일수 — 일수×일당 자동계산">
          <input type="number" name="work_hours" class="input" step="0.5" min="0" placeholder="시간" title="작업 시간 — 일수 없을 때 시간×시급 자동계산"></div></div>
      <?php /* 금액은 기본 잠금이다. costs.amount 는 projects.actual_cost 로 합산되어
              확정 순이익과 직원 보너스까지 이어지므로, 근거(수량×단가) 없이 임의로
              적히면 그 연쇄가 통째로 검증 불가능해진다. 영수증 실액이 계산과 다를 때만
              체크로 열고 사유를 남긴다. */ ?>
      <div class="field"><label class="field-label">금액 <span class="muted">(수량 × 단가 자동계산)</span></label>
        <input type="number" name="amount" class="input is-locked" min="0" readonly
               placeholder="자동 계산됩니다"
               title="수량 × 단가(인건비는 일수 × 일당)로 자동 계산됩니다. 영수증 금액이 다르면 아래를 체크하세요.">
        <label class="check mt-8">
          <input type="checkbox" id="amountManual"> 영수증 금액이 계산값과 다름 (직접 입력)
        </label></div>
      <div class="field" id="adjustReasonField" style="display:none"><label class="field-label">조정 사유<span class="req">*</span></label>
        <input type="text" name="adjust_reason" class="input" placeholder="예: 부가세 포함 / 현장 할인 / 배송비 별도"></div>
      <div class="field"><label class="field-label">증빙 첨부 <span class="muted">(영수증·세금계산서)</span></label>
        <input type="file" name="receipt" class="input" accept="image/*,.pdf,.xls,.xlsx,.hwp,.zip"></div>
      <div class="field col-span-2"><label class="field-label">메모</label>
        <input type="text" name="memo" class="input" placeholder="역할·기간 등 참고 사항"></div>
      <div class="field"><button type="submit" class="btn btn-primary">저장</button></div>
    </form>
    <script>
    (function () {
      var f = document.getElementById('costForm');
      if (!f) return;
      function $(n) { return f.querySelector('[name="' + n + '"]'); }
      var catEl = $('category'), qty = $('qty'), up = $('unit_price'),
          days = $('work_days'), hours = $('work_hours'), amt = $('amount'),
          reason = $('adjust_reason'), reasonField = document.getElementById('adjustReasonField');
      function autoAmount() { // 서버 CostService::autoAmount 와 동일 규칙
        var u = parseFloat(up.value);
        if (!u || u <= 0) return null;
        if (catEl.value === 'labor') {
          var d = parseFloat(days.value), h = parseFloat(hours.value);
          if (d > 0) return Math.round(d * u);
          if (h > 0) return Math.round(h * u);
          return null;
        }
        var q = parseFloat(qty.value);
        if (q > 0) return Math.round(q * u);
        return null;
      }
      // 구분별 입력 가이드 — 서버 CostService::CATEGORY_GUIDE 와 같은 출처를 쓴다.
      var GUIDE = <?= json_encode(\CostService::CATEGORY_GUIDE, JSON_UNESCAPED_UNICODE) ?>;
      var manualBox = document.getElementById('amountManual');
      var qtyStar = document.getElementById('qtyStar'), daysStar = document.getElementById('daysStar');
      var qtyHint = document.getElementById('qtyHint'), upHint = document.getElementById('unitPriceHint');
      var gWrap = document.getElementById('catGuide'),
          gHint = document.getElementById('catGuideHint'), gEx = document.getElementById('catGuideExample');

      /** 구분에 따라 안내·별표·placeholder 를 바꾼다. 인건비만 수량 대신 일수/시간을 쓴다. */
      function applyGuide() {
        var g = GUIDE[catEl.value] || GUIDE.etc;
        var isLabor = catEl.value === 'labor';
        gWrap.style.display = '';
        gHint.textContent = g.hint;
        gEx.textContent = ' · 예: ' + g.example;
        qtyStar.style.display = isLabor ? 'none' : '';
        daysStar.style.display = isLabor ? '' : 'none';
        qtyHint.textContent = isLabor ? '(인건비는 일수/시간 사용)' : '(' + g.qty_label + ')';
        upHint.textContent = '(' + g.unit_label + ')';
        qty.placeholder = isLabor ? '인건비는 아래 일수/시간 입력' : g.qty_label;
        qty.required = !isLabor;
      }
      function toggleReason() {
        var a = autoAmount();
        // 수동 입력을 켰고 자동계산값과 실제로 다를 때만 사유를 요구한다.
        var manual = manualBox.checked && a !== null && amt.value !== '' && Number(amt.value) !== a;
        reasonField.style.display = manual ? '' : 'none';
        reason.required = manual;
        if (!manual) reason.value = '';
      }
      /** 금액 잠금/해제. 잠글 때는 자동계산값으로 되돌려 임의 값이 남지 않게 한다. */
      function setLocked(locked) {
        amt.readOnly = locked;
        amt.classList.toggle('is-locked', locked);
      }
      function applyLock() {
        setLocked(!manualBox.checked);
        if (!manualBox.checked) {
          var a = autoAmount();
          if (a !== null) amt.value = a;
        }
        toggleReason();
      }
      function refresh() {
        var a = autoAmount();
        // 잠긴 상태에서는 원천 값이 바뀔 때마다 자동계산값을 그대로 반영한다.
        if (a !== null && !manualBox.checked) amt.value = a;
        applyGuide();
        toggleReason();
      }
      [catEl, qty, up, days, hours].forEach(function (el) {
        el.addEventListener('input', refresh);
        el.addEventListener('change', refresh);
      });
      amt.addEventListener('input', toggleReason);
      manualBox.addEventListener('change', applyLock);
      applyGuide();
      applyLock();
      // 목록 '수정' → 폼 채우기
      document.querySelectorAll('[data-cost-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var c = JSON.parse(btn.getAttribute('data-cost-edit'));
          ['id', 'category', 'cost_status', 'item_name', 'spec', 'qty', 'unit', 'unit_price', 'amount',
           'worker_id', 'worker_name', 'work_days', 'work_hours', 'vendor', 'adjust_reason', 'spent_date', 'memo']
            .forEach(function (k) { var el = $(k); if (el) el.value = (c[k] === null || c[k] === undefined) ? '' : c[k]; });
          document.getElementById('costFormTitle').textContent = '비용 수정 (#' + c.id + ')';
          document.getElementById('costFormReset').style.display = '';
          // 저장된 금액이 자동계산값과 다르면(수동으로 넣은 건) 잠금을 풀어 둔다.
          // 그러지 않으면 폼을 여는 순간 자동계산값으로 덮여 기존 금액이 조용히 바뀐다.
          applyGuide();
          var savedAmt = Number(c.amount), autoNow = autoAmount();
          manualBox.checked = (autoNow === null) || (savedAmt !== autoNow);
          setLocked(!manualBox.checked);
          amt.value = (c.amount === null || c.amount === undefined) ? '' : c.amount;
          toggleReason();
          f.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
      });
      var resetBtn = document.getElementById('costFormReset');
      resetBtn.addEventListener('click', function () {
        f.reset();
        $('id').value = '';
        document.getElementById('costFormTitle').textContent = '비용 등록';
        resetBtn.style.display = 'none';
        manualBox.checked = false;   // 등록 모드로 돌아오면 금액은 다시 잠근다
        applyGuide();
        applyLock();
      });
    })();
    </script>
  <?php endif; ?>
</div>
