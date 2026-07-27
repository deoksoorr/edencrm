<?php
/**
 * 보너스 지급 현황(bonus.index) — 목록 + 합계행, bonus.manage 보유 시 등록/수정/지급/취소/삭제 모달.
 * @var array $f @var int[] $years @var array $users @var array $projects
 * @var array $bonuses @var array $bonusTotals @var bool $canManage @var array $formUsers
 */
$statusLabels = ['unpaid' => '미지급', 'partial' => '부분지급', 'paid' => '지급완료', 'cancelled' => '취소'];
$statusBadge  = ['unpaid' => 'badge-warn', 'partial' => 'badge-info', 'paid' => 'badge-ok', 'cancelled' => 'badge-danger'];
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">보너스 지급 현황</h1>
      <div class="page-sub"><?= e(Util::halfLabel($f['year'], $f['half'])) ?> 현장 보너스 원장<?= $f['canAll'] ? '' : ' · 본인 내역만 표시' ?></div>
    </div>
    <div class="page-actions">
      <a href="<?= e(url('halfyear.index', ['year' => $f['year'], 'half' => $f['half']])) ?>" class="btn btn-outline">반기 현황</a>
      <a href="<?= e(url('bonus.history', ['year' => $f['year'], 'half' => $f['half']])) ?>" class="btn btn-outline">변경 이력</a>
    </div>
  </div>

  <form method="get" class="toolbar" action="<?= e($GLOBALS['config']['BASE_URL']) ?>/index.php">
    <input type="hidden" name="r" value="bonus.index">
    <select name="year" class="select">
      <?php foreach ($years as $y): ?>
        <option value="<?= (int) $y ?>" <?= $y === $f['year'] ? 'selected' : '' ?>><?= (int) $y ?>년</option>
      <?php endforeach; ?>
    </select>
    <select name="half" class="select">
      <option value="1" <?= $f['half'] === 1 ? 'selected' : '' ?>>상반기</option>
      <option value="2" <?= $f['half'] === 2 ? 'selected' : '' ?>>하반기</option>
    </select>
    <?php if ($f['canAll']): ?>
      <select name="user_id" class="select">
        <option value="">전체 직원</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= $f['userId'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?><?= ($u['status'] ?? 'active') !== 'active' ? ' (비활성)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="project_id" class="select">
      <option value="">전체 프로젝트</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= $f['projectId'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="pay_status" class="select">
      <option value="">전체 지급상태</option>
      <?php foreach ($statusLabels as $k => $l): ?>
        <option value="<?= e($k) ?>" <?= $f['payStatus'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline">조회</button>
    <a href="<?= e(url('bonus.index')) ?>" class="btn btn-ghost">초기화</a>
    <div class="toolbar-spacer"></div>
    <?php if ($canManage): ?>
      <button type="button" class="btn btn-primary" data-bact="new">+ 보너스 등록</button>
    <?php endif; ?>
  </form>

  <?php if (!$bonuses): ?>
    <div class="empty"><div class="empty-title">조건에 맞는 보너스 내역이 없습니다.</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data compact">
      <thead>
        <tr>
          <th>직원</th><th>프로젝트</th><th>반기</th><th class="num">산정 대상 금액</th><th>산정 기준</th>
          <th class="num">산정액</th><th class="num">지급액</th><th class="num">미지급액</th>
          <th>지급일</th><th>상태</th><th>지급담당자</th><th>메모</th>
          <?php if ($canManage): ?><th>관리</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bonuses as $b):
            $cancelled = $b['pay_status'] === 'cancelled';
            $closed = Util::isHalfClosed((int) $b['year'], (int) $b['half']);
            // 모달 프리필용 데이터(JSON) — 뷰 출력은 e(), JS 는 dataset 로만 사용
            $json = json_encode([
                'id' => (int) $b['id'], 'user_id' => (int) $b['user_id'],
                'project_id' => $b['project_id'] !== null ? (int) $b['project_id'] : '',
                'year' => (int) $b['year'], 'half' => (int) $b['half'],
                'base_amount' => (int) $b['base_amount'], 'calc_basis' => (string) ($b['calc_basis'] ?? ''),
                'calc_amount' => (int) $b['calc_amount'], 'paid_amount' => (int) $b['paid_amount'],
                'pay_date' => (string) ($b['pay_date'] ?? ''), 'pay_status' => $b['pay_status'],
                'paid_by' => $b['paid_by'] !== null ? (int) $b['paid_by'] : '',
                'memo' => (string) ($b['memo'] ?? ''), 'closed' => $closed,
            ], JSON_UNESCAPED_UNICODE);
        ?>
          <tr<?= $cancelled ? ' class="bonus-cancelled"' : '' ?> data-bonus="<?= e($json) ?>">
            <td><?= e($b['user_name']) ?></td>
            <td><?= $b['project_id'] ? e($b['project_name'] ?? ('#' . $b['project_id'])) : '<span class="muted">-</span>' ?></td>
            <td class="nowrap"><?= (int) $b['year'] ?>-<?= (int) $b['half'] === 1 ? '상' : '하' ?><?= $closed ? ' <span class="badge badge-warn" title="마감 반기 — 수정·삭제 시 사유 필수">마감</span>' : '' ?></td>
            <td class="num mono"><?= moneyCell((float) $b['base_amount']) ?></td>
            <td><?= e($b['calc_basis'] ?? '-') ?></td>
            <td class="num mono"><?= moneyCell((float) $b['calc_amount']) ?></td>
            <td class="num mono"><?= moneyCell((float) $b['paid_amount']) ?></td>
            <td class="num mono"><?= $cancelled ? '-' : moneyCell((float) $b['calc_amount'] - (float) $b['paid_amount']) ?></td>
            <td><?= $b['pay_date'] ? e(fmtdate($b['pay_date'])) : '-' ?></td>
            <td><span class="badge <?= e($statusBadge[$b['pay_status']] ?? 'badge-info') ?>"><?= e($statusLabels[$b['pay_status']] ?? $b['pay_status']) ?></span></td>
            <td><?= e($b['paid_by_name'] ?? '-') ?></td>
            <td class="bonus-memo" title="<?= e($b['memo'] ?? '') ?>"><?= e(Util::truncate($b['memo'] ?? '-', 20)) ?></td>
            <?php if ($canManage): ?>
            <td>
              <div class="btn-group bonus-actions">
                <button type="button" class="btn btn-sm btn-outline" data-bact="edit">수정</button>
                <?php if (!$cancelled): ?>
                  <button type="button" class="btn btn-sm btn-outline" data-bact="pay">지급처리</button>
                  <button type="button" class="btn btn-sm btn-outline" data-bact="cancel">취소</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-danger" data-bact="del">삭제</button>
              </div>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="bonus-sum">
          <td colspan="5">합계 (취소 건 제외)</td>
          <td class="num mono"><?= money($bonusTotals['calc']) ?></td>
          <td class="num mono"><?= money($bonusTotals['paid']) ?></td>
          <td class="num mono<?= $bonusTotals['unpaid'] > 0 ? ' text-danger' : '' ?>"><?= money($bonusTotals['unpaid']) ?></td>
          <td colspan="<?= $canManage ? 5 : 4 ?>"></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
<script>
(function () {
  'use strict';
  var USERS = <?= json_encode(array_map(static fn ($u) => ['id' => (int) $u['id'], 'name' => $u['name']], $formUsers), JSON_UNESCAPED_UNICODE) ?>;
  var PROJECTS = <?= json_encode(array_map(static fn ($p) => ['id' => (int) $p['id'], 'name' => $p['name']], $projects), JSON_UNESCAPED_UNICODE) ?>;
  var CUR = { year: <?= (int) $f['year'] ?>, half: <?= (int) $f['half'] ?> };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function selOpts(list, selected, blank) {
    var html = blank ? '<option value="">' + blank + '</option>' : '';
    list.forEach(function (o) {
      html += '<option value="' + o.id + '"' + (String(o.id) === String(selected) ? ' selected' : '') + '>' + esc(o.name) + '</option>';
    });
    return html;
  }
  function reasonField(closed) {
    if (!closed) return '';
    return '<div class="field span2"><label class="field-label">수정 사유 <b class="text-danger">*</b> <span class="muted">(마감 반기)</span></label>' +
      '<input type="text" name="reason" class="input" maxlength="255" placeholder="마감된 반기 데이터를 수정하는 사유" required></div>';
  }

  /** 등록/수정 전체 폼 */
  function formHtml(b) {
    b = b || {};
    var years = '';
    for (var y = CUR.year + 1; y >= 2020; y--) {
      years += '<option value="' + y + '"' + (y === (b.year || CUR.year) ? ' selected' : '') + '>' + y + '년</option>';
    }
    return '' +
      '<form data-bonus-form class="form">' +
      '<input type="hidden" name="id" value="' + (b.id || 0) + '">' +
      '<div class="form-grid">' +
      '<div class="field"><label class="field-label">대상 직원 *</label><select name="user_id" class="select" required>' + selOpts(USERS, b.user_id, '선택') + '</select></div>' +
      '<div class="field"><label class="field-label">프로젝트</label><select name="project_id" class="select">' + selOpts(PROJECTS, b.project_id, '(현장 미지정)') + '</select></div>' +
      '<div class="field"><label class="field-label">연도</label><select name="year" class="select">' + years + '</select></div>' +
      '<div class="field"><label class="field-label">반기</label><select name="half" class="select">' +
        '<option value="1"' + ((b.half || CUR.half) === 1 ? ' selected' : '') + '>상반기</option>' +
        '<option value="2"' + ((b.half || CUR.half) === 2 ? ' selected' : '') + '>하반기</option></select></div>' +
      '<div class="field"><label class="field-label">산정 대상 금액(원)</label><input type="text" inputmode="numeric" name="base_amount" class="input" value="' + (b.base_amount || '') + '"></div>' +
      '<div class="field"><label class="field-label">산정 기준</label><input type="text" name="calc_basis" class="input" maxlength="100" placeholder="예: 순이익의 5%" value="' + esc(b.calc_basis) + '"></div>' +
      '<div class="field"><label class="field-label">산정 금액(원)</label><input type="text" inputmode="numeric" name="calc_amount" class="input" value="' + (b.calc_amount || '') + '"></div>' +
      '<div class="field"><label class="field-label">지급 금액(원)</label><input type="text" inputmode="numeric" name="paid_amount" class="input" value="' + (b.paid_amount || 0) + '"></div>' +
      '<div class="field"><label class="field-label">지급일</label><input type="date" name="pay_date" class="input" value="' + esc(b.pay_date) + '"></div>' +
      '<div class="field"><label class="field-label">지급 담당자</label><select name="paid_by" class="select">' + selOpts(USERS, b.paid_by, '(미지정)') + '</select></div>' +
      '<div class="field span2"><label class="field-label">메모</label><input type="text" name="memo" class="input" maxlength="500" value="' + esc(b.memo) + '"></div>' +
      reasonField(b.id && b.closed) +
      '</div>' +
      '<div class="muted fs-12 mt-8">지급 상태는 지급액에 따라 자동 산정됩니다. (0=미지급, 산정액 미만=부분지급, 이상=지급완료)</div>' +
      '<div class="ta-r mt-8"><button type="submit" class="btn btn-primary">저장</button></div>' +
      '</form>';
  }

  /** 지급 처리 폼(부분 전송 — 서버가 나머지 필드 기존 값 유지) */
  function payFormHtml(b) {
    var today = new Date().toISOString().slice(0, 10);
    return '' +
      '<form data-bonus-form class="form">' +
      '<input type="hidden" name="id" value="' + b.id + '">' +
      '<p class="muted fs-13 mt-0">산정액 ' + Number(b.calc_amount || 0).toLocaleString() + '원 · 현재 지급액 ' + Number(b.paid_amount || 0).toLocaleString() + '원</p>' +
      '<div class="form-grid">' +
      '<div class="field"><label class="field-label">지급 금액(원) *</label><input type="text" inputmode="numeric" name="paid_amount" class="input" value="' + (b.paid_amount || b.calc_amount || 0) + '" required></div>' +
      '<div class="field"><label class="field-label">지급일</label><input type="date" name="pay_date" class="input" value="' + esc(b.pay_date || today) + '"></div>' +
      '<div class="field"><label class="field-label">지급 담당자</label><select name="paid_by" class="select">' + selOpts(USERS, b.paid_by, '(미지정)') + '</select></div>' +
      reasonField(b.closed) +
      '</div>' +
      '<div class="ta-r mt-8"><button type="submit" class="btn btn-primary">지급 처리</button></div>' +
      '</form>';
  }

  /** 취소/삭제 사유 폼 */
  function reasonFormHtml(b, act) {
    var isDel = act === 'del';
    return '' +
      '<form data-bonus-form data-bonus-act="' + act + '" class="form">' +
      '<input type="hidden" name="id" value="' + b.id + '">' +
      (isDel ? '' : '<input type="hidden" name="pay_status" value="cancelled">') +
      '<p class="muted fs-13 mt-0">' + (isDel
        ? '이 보너스 내역을 삭제합니다. 목록에서 제외되지만 변경 이력(원장)에는 보존됩니다.'
        : '이 보너스를 취소 처리합니다. 합계 집계에서 제외되며 내역은 보존됩니다.') + '</p>' +
      '<div class="field"><label class="field-label">사유' + (b.closed ? ' <b class="text-danger">*</b> <span class="muted">(마감 반기 — 필수)</span>' : ' <span class="muted">(선택)</span>') + '</label>' +
      '<input type="text" name="reason" class="input" maxlength="255"' + (b.closed ? ' required' : '') + '></div>' +
      '<div class="ta-r mt-8"><button type="submit" class="btn btn-danger">' + (isDel ? '삭제' : '취소 처리') + '</button></div>' +
      '</form>';
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-bact]');
    if (!btn) return;
    var act = btn.dataset.bact;
    if (act === 'new') {
      EDEN.modal({ title: '보너스 등록', body: formHtml({ year: CUR.year, half: CUR.half }), footer: false, wide: true });
      return;
    }
    var row = btn.closest('tr[data-bonus]');
    if (!row) return;
    var b = JSON.parse(row.dataset.bonus);
    if (act === 'edit') EDEN.modal({ title: '보너스 수정', body: formHtml(b), footer: false, wide: true });
    if (act === 'pay') EDEN.modal({ title: '지급 처리', body: payFormHtml(b), footer: false });
    if (act === 'cancel') EDEN.modal({ title: '보너스 취소', body: reasonFormHtml(b, 'cancel'), footer: false });
    if (act === 'del') EDEN.modal({ title: '보너스 삭제', body: reasonFormHtml(b, 'del'), footer: false });
  });

  document.addEventListener('submit', async function (e) {
    var form = e.target;
    if (!form.matches('form[data-bonus-form]')) return;
    e.preventDefault();
    var route = form.dataset.bonusAct === 'del' ? 'bonus.delete' : 'bonus.save';
    var btn = form.querySelector('[type="submit"]');
    if (btn) btn.disabled = true;
    try {
      await api(route, new FormData(form));
      toast('저장되었습니다.', 'success');
      location.reload();
    } catch (err) {
      toast(err.message, 'error');
      if (btn) btn.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>
