<?php
/**
 * 목표(KPI) 관리 — R9.
 * ① 목표 원장(goals): 유형×대상×기간 목표 목록 + 달성률·상태 자동 계산 + 등록/수정/종료/이력/추이.
 * ② 회사 목표 그리드(company_targets): 기존 유지(canManage 전용, 대시보드·리포트 연동).
 * @var bool  $canManage settings.manage 보유 여부(미보유=공개 목표 열람 전용)
 * @var int   $year @var int[] $years
 * @var array $company [period_type][period_no] = row (canManage 시)
 * @var array $goals   progress·range_label 포함
 * @var array $f       {metric, period, subject, status, userId}
 * @var array $users   직원 옵션(canManage 시) @var array $depts 부서 옵션
 */
$cv = function (string $type, int $no, string $field) use ($company) {
    $r = $company[$type][$no] ?? null;
    return $r ? (int) $r[$field] : '';
};
$metricLabels  = GoalService::METRIC_LABELS;
$subjectLabels = GoalService::SUBJECT_LABELS;
$periodLabels  = GoalService::PERIOD_LABELS;
$statusLabels  = ['active' => '활성', 'ended' => '종료', 'cancelled' => '중단'];
$isCount = static fn (string $m): bool => in_array($m, GoalService::COUNT_METRICS, true);
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">목표 관리 <span class="badge badge-info">KPI</span></div>
      <div class="page-sub"><?= $canManage
          ? '목표 원장(유형·대상·기간)과 회사 월·분기·연간 목표를 관리합니다. 달성률은 실제 데이터(확정 매출·순이익 등)로 자동 계산됩니다.'
          : '본인에게 공개된 목표와 실적만 표시됩니다.' ?></div>
    </div>
  </div>

  <!-- ① 목표 원장 -->
  <div class="card mb-14">
    <div class="card-head">
      <div class="card-title">목표 원장</div>
      <div class="muted fs-12">실적 기준: 매출=확정 매출(입금 시점 공급가·VAT 제외) · 순이익=확정 매출−확정 지출 · 계약=계약일 · 입금=입금일(현금·VAT 포함) — 대시보드·리포트·반기 보너스 지급 현황과 동일 산식</div>
    </div>
    <div class="card-body">
      <form method="get" class="toolbar" action="<?= e($GLOBALS['config']['BASE_URL']) ?>/index.php">
        <input type="hidden" name="r" value="targets.index">
        <select name="year" class="select">
          <?php foreach ($years as $y): ?>
            <option value="<?= (int) $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= (int) $y ?>년</option>
          <?php endforeach; ?>
        </select>
        <select name="period_type" class="select">
          <option value="">전체 기간유형</option>
          <?php foreach ($periodLabels as $k => $l): ?>
            <option value="<?= e($k) ?>" <?= $f['period'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="metric" class="select">
          <option value="">전체 유형</option>
          <?php foreach ($metricLabels as $k => $l): ?>
            <option value="<?= e($k) ?>" <?= $f['metric'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="subject_type" class="select">
          <option value="">전체 대상</option>
          <?php foreach ($subjectLabels as $k => $l): ?>
            <option value="<?= e($k) ?>" <?= $f['subject'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status" class="select">
          <option value="">전체 상태</option>
          <?php foreach ($statusLabels as $k => $l): ?>
            <option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($canManage): ?>
          <select name="user_id" class="select">
            <option value="">전체 직원</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>" <?= $f['userId'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?><?= ($u['status'] ?? 'active') !== 'active' ? ' (비활성)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-outline">조회</button>
        <a href="<?= e(url('targets.index')) ?>" class="btn btn-ghost">초기화</a>
        <div class="toolbar-spacer"></div>
        <?php if ($canManage): ?>
          <button type="button" class="btn btn-primary" data-gact="new">+ 목표 등록</button>
        <?php endif; ?>
      </form>

      <?php if (!$goals): ?>
        <div class="empty compact"><div class="empty-title">조건에 맞는 목표가 없습니다.</div>
          <?php if ($canManage): ?><div class="empty-sub">'+ 목표 등록'으로 첫 목표를 만들어 보세요.</div><?php endif; ?>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="data compact">
          <thead>
            <tr>
              <th>목표명</th><th>유형</th><th>대상</th><th>기간</th>
              <th class="num">목표값</th><th class="num">현재 실적</th><th style="min-width:110px">달성률</th>
              <th class="num">남은 수치</th><th>상태</th><th>수정일</th>
              <?php if ($canManage): ?><th>관리</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($goals as $g):
                $p = $g['progress'];
                $unit = $isCount($g['metric']) ? '건' : '원';
                $subjectName = $g['subject_type'] === 'user'
                    ? ($g['user_name'] ?? ('#' . $g['user_id']))
                    : ($g['subject_type'] === 'department' ? ($g['dept_name'] ?? ('#' . $g['department_id'])) : '회사 전체');
                $rate = $p['rate'];
                $barPct = $rate !== null ? min(100, $rate) : 0;
                $barCls = $rate === null ? '' : ($rate >= 100 ? ' ok' : ($p['state'] === 'missed' ? ' danger' : ($p['expectOk'] === false ? ' warn' : '')));
                $json = json_encode([
                    'id' => (int) $g['id'], 'name' => (string) $g['name'], 'metric' => $g['metric'],
                    'subject_type' => $g['subject_type'],
                    'user_id' => $g['user_id'] !== null ? (int) $g['user_id'] : '',
                    'department_id' => $g['department_id'] !== null ? (int) $g['department_id'] : '',
                    'period_type' => $g['period_type'], 'year' => (int) $g['year'], 'period_no' => (int) $g['period_no'],
                    'start_date' => $g['start_date'], 'end_date' => $g['end_date'],
                    'target_value' => (int) $g['target_value'],
                    'owner_user_id' => $g['owner_user_id'] !== null ? (int) $g['owner_user_id'] : '',
                    'memo' => (string) ($g['memo'] ?? ''), 'is_public' => (int) $g['is_public'],
                    'status' => $g['status'], 'unit' => $unit,
                ], JSON_UNESCAPED_UNICODE);
            ?>
            <tr data-goal="<?= e($json) ?>">
              <td>
                <b><?= e($g['name']) ?></b>
                <?php if (!(int) $g['is_public']): ?><span class="badge" title="대상 직원에게 비공개">비공개</span><?php endif; ?>
                <?php if ($g['memo']): ?><div class="muted fs-12" title="<?= e($g['memo']) ?>"><?= e(Util::truncate($g['memo'], 30)) ?></div><?php endif; ?>
              </td>
              <td><?= e($metricLabels[$g['metric']] ?? $g['metric']) ?></td>
              <td><?= e($subjectName) ?><?php if ($g['owner_name']): ?><div class="muted fs-12">담당 <?= e($g['owner_name']) ?></div><?php endif; ?></td>
              <td class="nowrap"><?= e($g['range_label']) ?><div class="muted fs-12">경과 <?= e(number_format($p['elapsedPct'], 0)) ?>%</div></td>
              <td class="num mono"><?= $isCount($g['metric']) ? (int) $g['target_value'] . '<span class="u">건</span>' : moneyCell((float) $g['target_value']) ?></td>
              <td class="num mono"><?= $isCount($g['metric']) ? (int) $p['actual'] . '<span class="u">건</span>' : moneyCell((float) $p['actual']) ?></td>
              <td>
                <div class="progress" title="<?= $rate !== null ? e(number_format($rate, 1)) . '%' : '목표 미설정' ?>">
                  <div class="progress-bar<?= $barCls ?>" style="width:<?= e((string) $barPct) ?>%"></div>
                </div>
                <div class="progress-label"><?= $rate !== null ? e(number_format($rate, 1)) . '%' : '-' ?><?php
                    if ($p['state'] === 'ongoing' && $p['expectOk'] !== null): ?> · <?= $p['expectOk'] ? '<span class="text-ok">달성 예상</span>' : '<span class="text-danger">미달 예상</span>' ?><?php endif; ?></div>
              </td>
              <td class="num mono"><?= $isCount($g['metric']) ? (int) $p['remaining'] . '<span class="u">건</span>' : moneyCell((float) $p['remaining']) ?></td>
              <td>
                <span class="badge <?= e($p['stateBadge']) ?>"><?= e($p['stateLabel']) ?></span>
                <?php if ($g['status'] !== 'active' && $g['status_reason']): ?><div class="muted fs-12" title="<?= e($g['status_reason']) ?>"><?= e(Util::truncate($g['status_reason'], 16)) ?></div><?php endif; ?>
              </td>
              <td class="nowrap muted fs-12"><?= e(Util::date($g['updated_at'])) ?></td>
              <?php if ($canManage): ?>
              <td>
                <div class="btn-group goal-actions">
                  <button type="button" class="btn btn-sm btn-outline" data-gact="trend">추이</button>
                  <button type="button" class="btn btn-sm btn-outline" data-gact="hist">이력</button>
                  <button type="button" class="btn btn-sm btn-outline" data-gact="edit">수정</button>
                  <?php if ($g['status'] === 'active'): ?>
                    <button type="button" class="btn btn-sm btn-outline" data-gact="end">종료</button>
                    <button type="button" class="btn btn-sm btn-outline" data-gact="cancel">중단</button>
                  <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline" data-gact="reopen">재개</button>
                  <?php endif; ?>
                  <button type="button" class="btn btn-sm btn-danger" data-gact="del">삭제</button>
                </div>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <?php if (!$canManage && $goals): ?>
        <div class="muted fs-12 mt-8">상세 추이는 관리자에게 문의하세요. 표시된 목표는 관리자가 공개한 항목입니다.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canManage): ?>
  <!-- ② 회사 목표 그리드(company_targets — 대시보드·리포트 달성률 연동) -->
  <div class="card pad">
    <div class="section-head">
      <div class="st"><h2>회사 목표(월·분기·연간)</h2><span class="section-desc"><?= (int) $year ?>년 · 대시보드 달성률 연동</span></div>
      <form method="get" action="<?= e($GLOBALS['config']['BASE_URL']) ?>/index.php" class="btn-group">
        <input type="hidden" name="r" value="targets.index">
        <select name="year" class="select" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?= (int) $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= (int) $y ?>년</option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <form data-ajax data-route="targets.save" data-success="회사 목표가 저장되었습니다." data-reload="1">
      <?= csrf_field() ?>
      <input type="hidden" name="year" value="<?= (int) $year ?>">
      <div class="grid-2">
        <div class="table-wrap">
          <table class="data compact">
            <thead><tr><th>월</th><th class="num" title="확정 매출(공급가액·VAT 제외) 기준">매출 목표(공급가액·원)</th><th class="num" title="확정 순이익(공급가액 − 원가 총액) 기준">순이익 목표(원)</th></tr></thead>
            <tbody>
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <tr>
                  <td><?= $m ?>월</td>
                  <td class="num"><input type="number" name="m_rev_<?= $m ?>" class="input tnum-input" min="0" step="10000" value="<?= $cv('month', $m, 'target_revenue') ?>"></td>
                  <td class="num"><input type="number" name="m_pft_<?= $m ?>" class="input tnum-input" min="0" step="10000" value="<?= $cv('month', $m, 'target_profit') ?>"></td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
        <div>
          <div class="table-wrap mb-14">
            <table class="data compact">
              <thead><tr><th>분기</th><th class="num" title="확정 매출(공급가액·VAT 제외) 기준">매출 목표(공급가액·원)</th><th class="num" title="확정 순이익(공급가액 − 원가 총액) 기준">순이익 목표(원)</th></tr></thead>
              <tbody>
                <?php for ($q = 1; $q <= 4; $q++): ?>
                  <tr>
                    <td><?= $q ?>분기</td>
                    <td class="num"><input type="number" name="q_rev_<?= $q ?>" class="input tnum-input" min="0" step="100000" value="<?= $cv('quarter', $q, 'target_revenue') ?>"></td>
                    <td class="num"><input type="number" name="q_pft_<?= $q ?>" class="input tnum-input" min="0" step="100000" value="<?= $cv('quarter', $q, 'target_profit') ?>"></td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
          <div class="table-wrap">
            <table class="data compact">
              <thead><tr><th>연간</th><th class="num" title="확정 매출(공급가액·VAT 제외) 기준">매출 목표(공급가액·원)</th><th class="num" title="확정 순이익(공급가액 − 원가 총액) 기준">순이익 목표(원)</th></tr></thead>
              <tbody>
                <tr>
                  <td><?= (int) $year ?>년</td>
                  <td class="num"><input type="number" name="y_rev" class="input tnum-input" min="0" step="1000000" value="<?= $cv('year', 0, 'target_revenue') ?>"></td>
                  <td class="num"><input type="number" name="y_pft" class="input tnum-input" min="0" step="1000000" value="<?= $cv('year', 0, 'target_profit') ?>"></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="btn-group mt-14"><button type="submit" class="btn btn-primary">회사 목표 저장</button></div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
<script>
(function () {
  'use strict';
  var USERS = <?= json_encode(array_map(static fn ($u) => ['id' => (int) $u['id'], 'name' => $u['name'] . (($u['status'] ?? 'active') !== 'active' ? ' (비활성)' : '')], $users), JSON_UNESCAPED_UNICODE) ?>;
  var DEPTS = <?= json_encode(array_map(static fn ($d) => ['id' => (int) $d['id'], 'name' => $d['name']], $depts), JSON_UNESCAPED_UNICODE) ?>;
  var METRICS = <?= json_encode(GoalService::METRIC_LABELS, JSON_UNESCAPED_UNICODE) ?>;
  var PERIODS = <?= json_encode(GoalService::PERIOD_LABELS, JSON_UNESCAPED_UNICODE) ?>;
  var CUR_YEAR = <?= (int) date('Y') ?>;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function opts(map, selected) {
    var html = '';
    Object.keys(map).forEach(function (k) {
      html += '<option value="' + k + '"' + (k === selected ? ' selected' : '') + '>' + esc(map[k]) + '</option>';
    });
    return html;
  }
  function selOpts(list, selected, blank) {
    var html = blank ? '<option value="">' + blank + '</option>' : '';
    list.forEach(function (o) {
      html += '<option value="' + o.id + '"' + (String(o.id) === String(selected) ? ' selected' : '') + '>' + esc(o.name) + '</option>';
    });
    return html;
  }
  function numOpts(from, to, selected, suffix) {
    var html = '';
    for (var i = from; i <= to; i++) {
      html += '<option value="' + i + '"' + (i === selected ? ' selected' : '') + '>' + i + (suffix || '') + '</option>';
    }
    return html;
  }

  /** 기간 유형에 따른 하위 필드 갱신 */
  function periodFields(g) {
    var t = g.period_type || 'month';
    var y = g.year || CUR_YEAR;
    var no = g.period_no || 0;
    var html = '';
    if (t !== 'custom') {
      var years = '';
      for (var yy = CUR_YEAR + 2; yy >= 2020; yy--) {
        years += '<option value="' + yy + '"' + (yy === y ? ' selected' : '') + '>' + yy + '년</option>';
      }
      html += '<div class="field"><label class="field-label">연도</label><select name="year" class="select">' + years + '</select></div>';
    }
    if (t === 'month')   html += '<div class="field"><label class="field-label">월</label><select name="period_no" class="select">' + numOpts(1, 12, no || (new Date().getMonth() + 1), '월') + '</select></div>';
    if (t === 'quarter') html += '<div class="field"><label class="field-label">분기</label><select name="period_no" class="select">' + numOpts(1, 4, no || 1, '분기') + '</select></div>';
    if (t === 'half')    html += '<div class="field"><label class="field-label">반기</label><select name="period_no" class="select"><option value="1"' + (no === 1 ? ' selected' : '') + '>상반기</option><option value="2"' + (no === 2 ? ' selected' : '') + '>하반기</option></select></div>';
    if (t === 'custom') {
      html += '<div class="field"><label class="field-label">시작일 *</label><input type="date" name="start_date" class="input" value="' + esc(g.start_date || '') + '" required></div>' +
              '<div class="field"><label class="field-label">종료일 *</label><input type="date" name="end_date" class="input" value="' + esc(g.end_date || '') + '" required></div>';
    }
    return html;
  }
  function subjectFields(g) {
    var s = g.subject_type || 'company';
    if (s === 'user') {
      return '<div class="field"><label class="field-label">대상 직원 *</label><select name="user_id" class="select" required>' + selOpts(USERS, g.user_id, '선택') + '</select></div>';
    }
    if (s === 'department') {
      return '<div class="field"><label class="field-label">대상 부서(팀) *</label><select name="department_id" class="select" required>' + selOpts(DEPTS, g.department_id, '선택') + '</select></div>';
    }
    return '';
  }

  function formHtml(g) {
    g = g || {};
    return '' +
      '<form data-goal-form class="form">' +
      '<input type="hidden" name="id" value="' + (g.id || 0) + '">' +
      '<div class="form-grid">' +
      '<div class="field span2"><label class="field-label">목표명 *</label><input type="text" name="name" class="input" maxlength="100" placeholder="예: 2026 하반기 회사 매출 목표" value="' + esc(g.name) + '" required></div>' +
      '<div class="field"><label class="field-label">목표 유형 *</label><select name="metric" class="select">' + opts(METRICS, g.metric || 'revenue') + '</select></div>' +
      '<div class="field"><label class="field-label">대상 *</label><select name="subject_type" class="select" data-goal-subject>' +
        '<option value="company"' + ((g.subject_type || 'company') === 'company' ? ' selected' : '') + '>회사 전체</option>' +
        '<option value="department"' + (g.subject_type === 'department' ? ' selected' : '') + '>부서(팀)</option>' +
        '<option value="user"' + (g.subject_type === 'user' ? ' selected' : '') + '>직원 개인</option></select></div>' +
      '<div data-goal-subject-fields style="display:contents">' + subjectFields(g) + '</div>' +
      '<div class="field"><label class="field-label">기간 유형 *</label><select name="period_type" class="select" data-goal-period>' + opts(PERIODS, g.period_type || 'month') + '</select></div>' +
      '<div data-goal-period-fields style="display:contents">' + periodFields(g) + '</div>' +
      '<div class="field"><label class="field-label">목표값 * <span class="muted">(금액 원 / 건수)</span></label><input type="text" inputmode="numeric" name="target_value" class="input" value="' + (g.target_value || '') + '" required></div>' +
      '<div class="field"><label class="field-label">담당 직원</label><select name="owner_user_id" class="select">' + selOpts(USERS, g.owner_user_id, '(미지정)') + '</select></div>' +
      '<div class="field span2"><label class="field-label">메모</label><input type="text" name="memo" class="input" maxlength="500" value="' + esc(g.memo) + '"></div>' +
      '<div class="field"><label class="field-label">공개</label><label class="fs-13"><input type="checkbox" name="is_public" value="1"' + (g.is_public === 0 ? '' : ' checked') + '> 대상 직원 본인에게 공개</label></div>' +
      (g.id ? '<div class="field"><label class="field-label">수정 사유 <span class="muted">(이력에 기록)</span></label><input type="text" name="reason" class="input" maxlength="255"></div>' : '') +
      '</div>' +
      '<div class="muted fs-12 mt-8">같은 대상·유형의 기간이 겹치는 활성 목표가 있으면 경고가 표시되며, 확인 후 등록됩니다.</div>' +
      '<div class="ta-r mt-8"><button type="submit" class="btn btn-primary">저장</button></div>' +
      '</form>';
  }

  function reasonFormHtml(g, act) {
    var conf = {
      end:    { route: 'targets.goal.end',    to: 'ended',     btn: '종료 처리', cls: 'btn-primary', desc: '목표를 종료합니다. 이후 달성률 계산은 유지되며 상태만 "종료"로 표시됩니다.', req: false },
      cancel: { route: 'targets.goal.end',    to: 'cancelled', btn: '중단 처리', cls: 'btn-danger',  desc: '목표를 중단합니다. 사유가 이력에 기록됩니다.', req: true },
      reopen: { route: 'targets.goal.end',    to: 'active',    btn: '재개',      cls: 'btn-primary', desc: '종료/중단된 목표를 다시 활성화합니다.', req: false },
      del:    { route: 'targets.goal.delete', to: '',          btn: '삭제',      cls: 'btn-danger',  desc: '목표를 삭제합니다. 목록에서 제외되지만 변경 이력(원장)에는 보존됩니다.', req: false }
    }[act];
    return '' +
      '<form data-goal-form data-goal-route="' + conf.route + '" class="form">' +
      '<input type="hidden" name="id" value="' + g.id + '">' +
      (conf.to ? '<input type="hidden" name="to_status" value="' + conf.to + '">' : '') +
      '<p class="muted fs-13 mt-0">' + conf.desc + '</p>' +
      '<div class="field"><label class="field-label">사유' + (conf.req ? ' <b class="text-danger">*</b>' : ' <span class="muted">(선택)</span>') + '</label>' +
      '<input type="text" name="reason" class="input" maxlength="255"' + (conf.req ? ' required' : '') + '></div>' +
      '<div class="ta-r mt-8"><button type="submit" class="btn ' + conf.cls + '">' + conf.btn + '</button></div>' +
      '</form>';
  }

  var ACTION_LABELS = { create: '등록', update: '수정', end: '종료', cancel: '중단', delete: '삭제' };
  var FIELD_LABELS = { name: '목표명', metric: '유형', subject_type: '대상', user_id: '대상 직원', department_id: '대상 부서',
    period_type: '기간유형', year: '연도', period_no: '기간값', start_date: '시작일', end_date: '종료일',
    target_value: '목표값', owner_user_id: '담당', memo: '메모', is_public: '공개', status: '상태', status_reason: '상태 사유' };

  function diffSummary(beforeJson, afterJson) {
    var b = {}, a = {};
    try { b = beforeJson ? JSON.parse(beforeJson) : {}; } catch (e) {}
    try { a = afterJson ? JSON.parse(afterJson) : {}; } catch (e) {}
    var parts = [];
    Object.keys(FIELD_LABELS).forEach(function (k) {
      var bv = b[k] == null ? '' : String(b[k]);
      var av = a[k] == null ? '' : String(a[k]);
      if (bv !== av && (bv !== '' || av !== '')) {
        parts.push(esc(FIELD_LABELS[k]) + ': ' + (bv === '' ? '(없음)' : esc(bv)) + ' → ' + (av === '' ? '(없음)' : esc(av)));
      }
    });
    return parts.length ? parts.join('<br>') : '<span class="muted">-</span>';
  }

  async function showHistory(g) {
    try {
      var res = await api('targets.goal.history', { id: g.id }, { method: 'GET' });
      var rows = res.rows || [];
      var html = rows.length ? '<div class="table-wrap"><table class="data compact"><thead><tr><th>일시</th><th>작업</th><th>변경자</th><th>변경 내용</th><th>사유</th></tr></thead><tbody>' : '<div class="empty compact"><div class="empty-title">이력이 없습니다.</div></div>';
      rows.forEach(function (r) {
        html += '<tr><td class="nowrap">' + esc(r.changed_at) + '</td><td>' + esc(ACTION_LABELS[r.action] || r.action) + '</td>' +
          '<td>' + esc(r.changed_by_name || '-') + '</td><td class="fs-12">' + diffSummary(r.before_json, r.after_json) + '</td>' +
          '<td class="fs-12">' + (r.reason ? esc(r.reason) : '<span class="muted">-</span>') + '</td></tr>';
      });
      if (rows.length) html += '</tbody></table></div>';
      EDEN.modal({ title: '목표 변경 이력 — ' + esc(g.name), body: html, footer: false, wide: true });
    } catch (err) { toast(err.message, 'error'); }
  }

  async function showTrend(g) {
    try {
      var res = await api('targets.goal.progress', { id: g.id }, { method: 'GET' });
      var max = Math.max(res.target || 0, 1);
      (res.trend || []).forEach(function (t) { if (t.value > max) max = t.value; });
      var html = '<p class="muted fs-13 mt-0">목표 ' + Number(res.target).toLocaleString() + res.unit +
        ' · 실적 ' + Number(res.progress.actual).toLocaleString() + res.unit +
        (res.progress.rate != null ? ' · 달성률 ' + res.progress.rate.toFixed(1) + '%' : '') +
        (res.progress.projected != null ? ' · 페이스 환산 ' + Number(res.progress.projected).toLocaleString() + res.unit : '') + '</p>';
      html += '<div class="table-wrap"><table class="data compact"><thead><tr><th>월</th><th class="num">실적</th><th style="min-width:140px">추이</th></tr></thead><tbody>';
      (res.trend || []).forEach(function (t) {
        var pct = Math.min(100, Math.round(t.value / max * 100));
        html += '<tr><td class="nowrap">' + esc(t.label) + '</td><td class="num mono">' + Number(t.value).toLocaleString() + res.unit + '</td>' +
          '<td><div class="progress"><div class="progress-bar" style="width:' + pct + '%"></div></div></td></tr>';
      });
      html += '</tbody></table></div>';
      EDEN.modal({ title: '기간별 실적 추이 — ' + esc(g.name), body: html, footer: false, wide: true });
    } catch (err) { toast(err.message, 'error'); }
  }

  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-goal-subject]')) {
      var wrap = e.target.closest('form').querySelector('[data-goal-subject-fields]');
      if (wrap) wrap.innerHTML = subjectFields({ subject_type: e.target.value });
    }
    if (e.target.matches('[data-goal-period]')) {
      var pw = e.target.closest('form').querySelector('[data-goal-period-fields]');
      if (pw) pw.innerHTML = periodFields({ period_type: e.target.value });
    }
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-gact]');
    if (!btn) return;
    var act = btn.dataset.gact;
    if (act === 'new') {
      EDEN.modal({ title: '목표 등록', body: formHtml({}), footer: false, wide: true });
      return;
    }
    var row = btn.closest('tr[data-goal]');
    if (!row) return;
    var g = JSON.parse(row.dataset.goal);
    if (act === 'edit')   EDEN.modal({ title: '목표 수정', body: formHtml(g), footer: false, wide: true });
    if (act === 'end')    EDEN.modal({ title: '목표 종료', body: reasonFormHtml(g, 'end'), footer: false });
    if (act === 'cancel') EDEN.modal({ title: '목표 중단', body: reasonFormHtml(g, 'cancel'), footer: false });
    if (act === 'reopen') EDEN.modal({ title: '목표 재개', body: reasonFormHtml(g, 'reopen'), footer: false });
    if (act === 'del')    EDEN.modal({ title: '목표 삭제', body: reasonFormHtml(g, 'del'), footer: false });
    if (act === 'hist')   showHistory(g);
    if (act === 'trend')  showTrend(g);
  });

  document.addEventListener('submit', async function (e) {
    var form = e.target;
    if (!form.matches('form[data-goal-form]')) return;
    e.preventDefault();
    var route = form.dataset.goalRoute || 'targets.goal.save';
    var btn = form.querySelector('[type="submit"]');
    if (btn) btn.disabled = true;
    try {
      var fd = new FormData(form);
      var res = await api(route, fd);
      if (res && res.dup_warning) {
        if (btn) btn.disabled = false;
        if (window.confirm(res.message + '\n\n그래도 등록(수정)하시겠습니까?')) {
          fd.set('confirm_dup', '1');
          if (btn) btn.disabled = true;
          await api(route, fd);
        } else {
          return;
        }
      }
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
