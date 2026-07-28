<?php
/**
 * [직원·일정] 탭 — 배정 목록 + 배정 폼 파셜(schedstaff 제공) + 인라인 일정 등록·수정(R4 T8) + 작업일지.
 * projects/show.php 에서 include. 일정은 기존 schedule.data/save/delete AJAX 재사용(신규 API·테이블 없음)
 * — 캘린더(schedule.index)와 동일 schedules 테이블이라 등록·수정이 양방향 자동 반영된다.
 */
?>
<div class="card pad">
  <div class="section-head"><div class="st"><h2>배정 직원</h2><span class="section-desc">활성 <?= count($activeAssignments) ?>명 · 전체 이력 <?= count($assignments) ?>건</span></div></div>
  <?php if (!$assignments): ?>
    <div class="empty"><div class="empty-title">배정된 직원이 없습니다.</div></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>이름</th><th>역할</th><th class="num">기여도</th><th>기간</th><th>상태</th><?php if ($canAssign): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($assignments as $a): $aActive = $a['status'] === 'active'; ?>
            <tr>
              <td><?= e($a['user_name']) ?></td>
              <td><?= e($a['role'] ?: '-') ?></td>
              <td class="num mono">
                <?php if ($canAssign && $aActive): ?>
                  <!-- R10: 기여도 인라인 수정 — 서버가 입력값 그대로 저장(강제 100 제거). 나머지 필드는 기존 값 유지 전송 -->
                  <form method="post" action="<?= e(url('assignments.save')) ?>" class="af-row-edit" style="display:inline-flex;gap:4px;align-items:center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
                    <input type="hidden" name="role" value="<?= e($a['role']) ?>">
                    <input type="hidden" name="status" value="<?= e($a['status']) ?>">
                    <input type="hidden" name="start_date" value="<?= e($a['start_date'] ?? '') ?>">
                    <input type="hidden" name="end_date" value="<?= e($a['end_date'] ?? '') ?>">
                    <input type="hidden" name="planned_hours" value="<?= $a['planned_hours'] !== null ? e((string) $a['planned_hours']) : '' ?>">
                    <input type="hidden" name="memo" value="<?= e($a['memo'] ?? '') ?>">
                    <input type="number" name="contribution_pct" class="input af-pct-active" style="width:76px;height:28px"
                           step="0.01" min="0" max="100" value="<?= e(rtrim(rtrim((string) $a['contribution_pct'], '0'), '.')) ?>">
                    <button type="submit" class="btn btn-outline btn-sm">저장</button>
                  </form>
                <?php else: ?>
                  <?= pct((float) $a['contribution_pct']) ?>
                <?php endif; ?>
              </td>
              <td class="nowrap"><?= fmtdate($a['start_date']) ?> ~ <?= fmtdate($a['end_date']) ?></td>
              <td><span class="badge"><?= e($a['status']) ?></span></td>
              <?php if ($canAssign): ?>
              <td>
                <!-- R10-F: AJAX 삭제(토스트) — 비-AJAX 폴백은 서버가 상세로 리다이렉트(원시 JSON 노출 방지) -->
                <form method="post" action="<?= e(url('assignments.delete')) ?>" class="af-row-del">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="btn btn-ghost-danger btn-sm">삭제</button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($canAssign): ?>
    <script>
    // R10: 기여도 인라인 수정 제출 + 합계 경고(≠100% 확인, >100% 서버 차단 안내)
    (function () {
      'use strict';
      window.afSumCheck = function (excludeInput, addVal) {
        var total = 0;
        document.querySelectorAll('.af-pct-active').forEach(function (i) {
          if (i !== excludeInput) total += parseFloat(i.value) || 0;
        });
        total += addVal;
        total = Math.round(total * 100) / 100;
        if (total > 100.01) {
          alert('기여도 합계가 ' + total + '%로 100%를 초과합니다. 저장할 수 없습니다.\n(기여도 반영 매출이 총매출을 초과하게 됩니다)');
          return false;
        }
        if (Math.abs(total - 100) > 0.01) {
          return confirm('기여도 합계가 ' + total + '%입니다. (권장 100%)\n보너스 자동 산정은 총합 100%를 기준으로 합니다.\n이대로 저장하시겠습니까?');
        }
        return true;
      };
      // R10-F: 배정 삭제 AJAX — 성공 시 토스트 후 새로고침(탭은 sessionStorage 복원)
      document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('form.af-row-del')) return;
        e.preventDefault();
        if (!confirm('배정을 삭제하시겠습니까?')) return;
        var btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        fetch(form.action, { method: 'POST', body: new FormData(form),
          headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
          .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, j: j }; }); })
          .then(function (r) {
            if (!r.ok || r.j.ok === false) throw new Error(r.j.error || '삭제에 실패했습니다.');
            var sum = r.j.data && r.j.data.contribution_sum;
            if (window.EDEN && EDEN.toast) EDEN.toast('배정이 삭제되었습니다.' + (sum != null ? ' (기여도 합계 ' + sum + '%)' : ''), 'success');
            location.reload();
          })
          .catch(function (err) {
            if (window.EDEN && EDEN.toast) EDEN.toast(err.message, 'error'); else alert(err.message);
            btn.disabled = false;
          });
      });
      document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('form.af-row-edit')) return;
        e.preventDefault();
        var pctInput = form.querySelector('[name=contribution_pct]');
        if (!window.afSumCheck(null, 0)) return; // 편집 입력은 이미 .af-pct-active 에 포함
        var btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        fetch(form.action, { method: 'POST', body: new FormData(form),
          headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
          .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, j: j }; }); })
          .then(function (r) {
            if (!r.ok || r.j.ok === false) throw new Error(r.j.error || '저장에 실패했습니다.');
            if (window.EDEN && EDEN.toast) EDEN.toast('기여도가 저장되었습니다.', 'success');
            location.reload();
          })
          .catch(function (err) {
            if (window.EDEN && EDEN.toast) EDEN.toast(err.message, 'error'); else alert(err.message);
            btn.disabled = false;
          });
      });
    })();
    </script>
    <div class="section-head mt-14"><div class="st"><h3>배정 추가</h3><span class="section-desc">활성 직원 검색 선택 — 중복·비활성 배정은 서버에서 거부됩니다 · 기여도 합계 100% 권장</span></div></div>
    <?php
      // schedstaff 파셜 조립(r3-schedstaff-report §3) — 직원 ID 숫자 입력 폼 완전 대체
      $projectId = (int) $p['id'];
      $assignedUserIds = $activeAssignedIds;
      include APP_PATH . '/views/partials/assignment_form.php';
    ?>
  <?php endif; ?>
</div>

<?php
// ── R4 T8: 인라인 일정 등록·수정 — 기존 schedule.save/schedule.data AJAX 재사용(신규 API·테이블 없음).
//    캘린더(schedule.index)와 동일 테이블(schedules) 원천이므로 등록·수정은 양방향 자동 반영.
$canSchedule   = can('schedule.manage');                       // 폼·행 액션 게이트(라우터가 save/delete 재검증)
$schedViewAll  = can('schedule.view_all');                     // 없으면 schedule.data 가 '내 참여 일정'만 반환
$schedClosed   = in_array($p['status'], ['cancelled', 'terminated'], true); // 캘린더 프로젝트 선택 목록과 동일 기준
$schedSlots    = Stages::scheduleSlots();                      // morning/afternoon/night => 오전/오후/야간
$schedTypes    = Stages::scheduleTypes();                      // 유형 단일 출처(R6: vacation 제외) — 캘린더 폼과 동일
$schedStatuses = ['scheduled' => ['예정', 'badge'], 'completed' => ['완료', 'badge badge-ok'], 'cancelled' => ['취소', 'badge badge-muted']];
// 참여 직원 후보: 배정(active) 직원 우선 표시 + 전 직원 선택 가능 (staffOptions: id/name/position/color)
$schedUserGroups = [['배정 직원', []], ['전체 직원', []]];
foreach ($staffOptions as $u) {
    $schedUserGroups[in_array((int) $u['id'], $activeAssignedIds, true) ? 0 : 1][1][] = $u;
}
?>
<div class="card pad">
  <div class="section-head">
    <div class="st"><h2>일정</h2><span class="section-desc">최근 20건 — 캘린더(일정 관리)와 동일 데이터<?= $schedViewAll ? '' : ' · 내가 참여한 일정만 표시' ?></span></div>
    <div class="flex items-center gap-8">
      <?php if ($canSchedule && !$schedClosed): ?>
        <button type="button" class="btn btn-primary btn-sm" id="psNewBtn">+ 일정 등록</button>
      <?php endif; ?>
      <a href="<?= e(url('schedule.index', ['project_id' => $p['id']])) ?>" class="btn btn-outline btn-sm">일정 관리로 이동</a>
    </div>
  </div>
  <?php if ($canSchedule && $schedClosed): ?>
    <p class="muted mb-8">취소·파기된 프로젝트에는 새 일정을 등록할 수 없습니다(기존 일정은 보존).</p>
  <?php endif; ?>

  <?php if ($canSchedule && !$schedClosed): ?>
  <div class="ps-formbox" id="psFormBox" style="display:none">
    <div class="section-head">
      <div class="st"><h3 id="psFormTitle">일정 등록</h3>
        <span class="section-desc">이 프로젝트(<?= e($p['project_no']) ?>)에 자동 연결 · 캘린더와 동일 저장(schedule.save)</span></div>
    </div>
    <form class="form" id="psForm">
      <input type="hidden" id="psId" value="">
      <div class="field"><label class="field-label">작업 내용 <span class="req">*</span></label>
        <input class="input" id="psTitle" maxlength="150" placeholder="예: 옥상 방수층 상도 2차"></div>
      <div class="field">
        <label class="field-label">참여 직원 <span class="req">*</span> <span class="muted ps-hint">(배정 직원 우선 표시 · 전 직원 선택 가능)</span></label>
        <div class="part-picker" id="psParts">
          <?php foreach ($schedUserGroups as [$gLabel, $gUsers]): if (!$gUsers) { continue; } ?>
            <div class="ps-part-group"><?= e($gLabel) ?></div>
            <?php foreach ($gUsers as $u): ?>
              <label class="part-item"><input type="checkbox" value="<?= (int) $u['id'] ?>">
                <span class="user-color-dot" style="background:<?= e($u['color'] ?: '#6b7280') ?>"></span><?= e($u['name']) ?><?= $u['position'] ? ' <span class="muted">· ' . e($u['position']) . '</span>' : '' ?></label>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-grid">
        <div class="field"><label class="field-label">시작일 <span class="req">*</span></label>
          <input type="date" class="input" id="psDate"></div>
        <div class="field"><label class="field-label">종료일 <span class="muted ps-hint">(기간 일정 — 미입력 시 하루)</span></label>
          <input type="date" class="input" id="psEndDate"></div>
        <div class="field"><label class="field-label">시간대 <span class="req">*</span> <span class="muted ps-hint">(복수 선택 가능)</span></label>
          <div class="slot-tabs" id="psSlots">
            <?php foreach ($schedSlots as $sk => $sl): ?>
              <button type="button" class="slot-tab" data-slot="<?= e($sk) ?>" aria-pressed="false"><?= e($sl) ?></button>
            <?php endforeach; ?>
          </div></div>
        <div class="field"><label class="field-label">유형</label>
          <select class="select" id="psType">
            <?php foreach ($schedTypes as $tk => $tl): ?><option value="<?= e($tk) ?>"><?= e($tl) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label class="field-label">시작·종료 시각</label>
          <input class="input" id="psTimes" value="" disabled
                 title="선택한 시간대에서 자동 산출됩니다(오전 09~12시 · 오후 13~18시 · 야간 18~22시). 개별 시각 입력은 일정 규약(R3: 날짜+시간대)상 제공하지 않습니다."></div>
      </div>
      <div class="field"><label class="field-label">메모</label><textarea class="input" id="psMemo" rows="2"></textarea></div>
      <div class="af-error" id="psConflict" role="alert" style="display:none"></div>
      <div class="flex items-center gap-8">
        <button type="submit" class="btn btn-primary" id="psSaveBtn">저장</button>
        <button type="button" class="btn btn-outline" id="psCancelBtn">취소</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>날짜</th><th>시간대</th><th>참여 직원</th><th>작업 내용</th><th>유형</th><th>상태</th><?php if ($canSchedule): ?><th></th><?php endif; ?></tr></thead>
      <tbody id="psBody">
        <?php if (!$schedules): ?>
          <tr><td colspan="<?= $canSchedule ? 7 : 6 ?>" class="muted">등록된 일정이 없습니다.</td></tr>
        <?php else: foreach ($schedules as $s):
          // 슬롯: 관계 테이블(slot_keys) 우선, 없으면 legacy 미러(schedules.slot) — schedule.data 와 동일 규칙
          [$stLabel, $stClass] = $schedStatuses[$s['status']] ?? [$s['status'], 'badge'];
        ?>
          <tr>
            <td class="nowrap"><?= e((string) $s['event_date']) ?><?= !empty($s['end_date']) && $s['end_date'] > $s['event_date'] ? ' ~ ' . e((string) $s['end_date']) : '' ?></td>
            <td class="nowrap"><?= e($s['slot_keys'] !== null ? Stages::slotLabels(explode(',', $s['slot_keys'])) : Stages::slotLabel($s['slot'])) ?></td>
            <td class="wrap"><?= e($s['participant_names'] ?: '-') ?></td>
            <td class="wrap"><?= e($s['title']) ?></td>
            <td class="nowrap"><?= e($schedTypes[$s['type']] ?? $s['type']) ?></td>
            <td><span class="<?= $stClass ?>"><?= e($stLabel) ?></span></td>
            <?php if ($canSchedule): ?><td></td><?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// ── R4 T8: 인라인 일정 등록·수정 — schedule.data 로 목록 로드, schedule.save/delete 재사용 ──
(function () {
  'use strict';
  var cfg = <?= json_encode([
      'pid'       => (int) $p['id'],
      'canManage' => $canSchedule,
      'canOpen'   => $canSchedule && !$schedClosed,
      'slots'     => $schedSlots,
      'types'     => $schedTypes,
      'statuses'  => array_map(static fn($v) => $v[0], $schedStatuses),
      // T4: 신규 일정 자동 연동 — 제목 "[고객명] 프로젝트명" + 배정(active) 직원 자동 선택
      'autoTitle' => '[' . ($p['customer_name'] ?? '') . '] ' . $p['name'],
      'assigned'  => $activeAssignedIds,
  ], JSON_UNESCAPED_UNICODE) ?>;
  var body = document.getElementById('psBody');
  if (!body) return;
  // T4 버그 수정: window.api(app.js)는 레이아웃에서 본문 뒤에 로드된다 — api 를 쓰는 초기화는 DOM 준비 후로 미룬다.
  function onReady(fn) {
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); }
    else { fn(); }
  }
  var SLOT_KEYS = ['morning', 'afternoon', 'night'];
  var SLOT_TIMES = { morning: ['09:00', '12:00'], afternoon: ['13:00', '18:00'], night: ['18:00', '22:00'] }; // Stages::slotTimes 표시용 미러
  var rowsById = {};
  var editing = null; // 수정 중인 일정(기존 status 보존용)

  var formBox = document.getElementById('psFormBox');
  var form = document.getElementById('psForm');
  var conflictBox = document.getElementById('psConflict');

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function todayStr() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function evSlots(ev) { return (ev.slots && ev.slots.length) ? ev.slots : (ev.slot ? [ev.slot] : ['morning']); }

  // ── 목록: schedule.data(project_id 필터) — 캘린더와 동일 원천 ──
  async function loadList() {
    try {
      var data = await api('schedule.data', { from: '2000-01-01', to: '2099-12-31', project_id: cfg.pid });
      var list = (data.schedules || []).slice().sort(function (a, b) {
        return a.event_date === b.event_date ? b.id - a.id : (a.event_date < b.event_date ? 1 : -1);
      }).slice(0, 20);
      rowsById = {};
      list.forEach(function (ev) { rowsById[ev.id] = ev; });
      renderList(list);
    } catch (e) { toast(e.message, 'error'); }
  }
  function renderList(list) {
    var cols = cfg.canManage ? 7 : 6;
    if (!list.length) {
      body.innerHTML = '<tr><td colspan="' + cols + '" class="muted">등록된 일정이 없습니다.</td></tr>';
      return;
    }
    body.innerHTML = list.map(function (ev) {
      var parts = (ev.participants || []).map(function (pp) {
        return '<span class="part-chip"><span class="user-color-dot" style="background:' + esc(pp.color || '#6b7280') + '"></span>' + esc(pp.name) + '</span>';
      }).join(' ') || '-';
      var stKey = ev.status || 'scheduled';
      var stCls = stKey === 'completed' ? 'badge badge-ok' : (stKey === 'cancelled' ? 'badge badge-muted' : 'badge');
      var actions = '';
      if (cfg.canManage) {
        actions = '<td class="nowrap ta-r">' +
          (cfg.canOpen ? '<button type="button" class="btn btn-ghost btn-sm" data-ps-edit="' + ev.id + '">수정</button> ' : '') +
          '<button type="button" class="btn btn-ghost-danger btn-sm" data-ps-del="' + ev.id + '">삭제</button></td>';
      }
      return '<tr>' +
        '<td class="nowrap">' + esc(ev.event_date) + (ev.end_date && ev.end_date > ev.event_date ? ' ~ ' + esc(ev.end_date) : '') + '</td>' +
        '<td class="nowrap">' + esc(ev.slot_label || '') + '</td>' +
        '<td class="wrap"><span class="part-chosen">' + parts + '</span></td>' +
        '<td class="wrap"' + (ev.memo ? ' title="메모: ' + esc(ev.memo) + '"' : '') + '>' + esc(ev.title) + '</td>' +
        '<td class="nowrap">' + esc(cfg.types[ev.type] || ev.type) + '</td>' +
        '<td><span class="' + stCls + '">' + esc(cfg.statuses[stKey] || stKey) + '</span></td>' +
        actions + '</tr>';
    }).join('');
  }

  // ── 행 액션: 수정(폼 채우기) · 삭제(기존 schedule.delete 라우트) ──
  body.addEventListener('click', function (e) {
    var eb = e.target.closest('[data-ps-edit]');
    var db = e.target.closest('[data-ps-del]');
    if (eb) { var ev = rowsById[parseInt(eb.getAttribute('data-ps-edit'), 10)]; if (ev) openForm(ev); }
    if (db) { removeSchedule(parseInt(db.getAttribute('data-ps-del'), 10)); }
  });
  async function removeSchedule(id) {
    if (!rowsById[id]) return;
    if (!(await EDEN.confirm('이 일정을 삭제하시겠습니까? 캘린더에서도 함께 삭제됩니다.', { danger: true }))) return;
    try { await api('schedule.delete', { id: id }); toast('일정이 삭제되었습니다.', 'success'); if (editing && editing.id === id) closeForm(); await loadList(); }
    catch (e) { toast(e.message, 'error'); }
  }

  if (!form) { onReady(loadList); return; } // 등록 권한 없음(또는 취소·파기 프로젝트): 목록만

  // ── 폼 열기/닫기/시각 자동 산출 ──
  function setSlots(keys) {
    form.querySelectorAll('#psSlots .slot-tab').forEach(function (b) {
      var on = keys.indexOf(b.dataset.slot) !== -1;
      b.classList.toggle('active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    updateTimes();
  }
  function activeSlots() {
    return SLOT_KEYS.filter(function (k) {
      var b = form.querySelector('#psSlots .slot-tab[data-slot="' + k + '"]');
      return b && b.classList.contains('active');
    });
  }
  function updateTimes() {
    var s = activeSlots();
    document.getElementById('psTimes').value = s.length
      ? SLOT_TIMES[s[0]][0] + ' ~ ' + SLOT_TIMES[s[s.length - 1]][1] + ' (시간대에서 자동 산출)'
      : '시간대를 선택하세요';
  }
  function openForm(ev) {
    editing = ev || null;
    document.getElementById('psFormTitle').textContent = ev ? '일정 수정' : '일정 등록';
    document.getElementById('psSaveBtn').textContent = ev ? '수정 저장' : '저장';
    document.getElementById('psId').value = ev ? ev.id : '';
    document.getElementById('psTitle').value = ev ? ev.title : (cfg.autoTitle || '');
    document.getElementById('psDate').value = ev ? ev.event_date : todayStr();
    document.getElementById('psEndDate').value = ev && ev.end_date && ev.end_date > ev.event_date ? ev.end_date : '';
    document.getElementById('psType').value = ev ? ev.type : 'work';
    document.getElementById('psMemo').value = ev ? (ev.memo || '') : '';
    var chosen = {};
    if (ev) (ev.participants || []).forEach(function (pp) { chosen[pp.user_id] = true; });
    else (cfg.assigned || []).forEach(function (uid) { chosen[uid] = true; }); // 배정 직원 기본 선택(T4)
    form.querySelectorAll('#psParts input[type="checkbox"]').forEach(function (c) { c.checked = !!chosen[c.value]; });
    setSlots(ev ? evSlots(ev) : ['morning']);
    hideConflict();
    formBox.style.display = '';
    formBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    document.getElementById('psTitle').focus();
  }
  function closeForm() { editing = null; formBox.style.display = 'none'; hideConflict(); }
  document.getElementById('psNewBtn')?.addEventListener('click', function () { openForm(null); });
  document.getElementById('psCancelBtn').addEventListener('click', closeForm);
  document.getElementById('psSlots').addEventListener('click', function (e) {
    var b = e.target.closest('.slot-tab'); if (!b) return;
    b.classList.toggle('active'); // 복수 선택 토글(최소 1개는 저장 시 검증 — 캘린더 폼과 동일)
    b.setAttribute('aria-pressed', b.classList.contains('active') ? 'true' : 'false');
    updateTimes();
    hideConflict();
  });

  // ── 충돌 경고(인라인): schedule.save 의 conflict 응답 표시 — 저장 차단 여부는 서버 동작 그대로 ──
  function hideConflict() { conflictBox.style.display = 'none'; conflictBox.innerHTML = ''; }
  function showConflict(conflicts, payload) {
    conflictBox.innerHTML = '<b>일정 충돌 경고</b> — 같은 날짜·시간대에 이미 잡힌 일정이 있습니다.<div class="mt-8">' +
      (conflicts || []).map(function (c) { return '<span class="badge badge-warn">' + esc(c.user_name) + ' — ' + esc(c.title) + '</span>'; }).join(' ') +
      '</div><div class="ps-conflict-actions">' +
      '<button type="button" class="btn btn-danger btn-sm" data-ps-force>경고 무시하고 저장</button>' +
      '<button type="button" class="btn btn-outline btn-sm" data-ps-dismiss>닫기</button></div>';
    conflictBox.style.display = '';
    conflictBox.querySelector('[data-ps-force]').addEventListener('click', function () { submitSave(payload, true); });
    conflictBox.querySelector('[data-ps-dismiss]').addEventListener('click', hideConflict);
    conflictBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // ── 저장: 기존 schedule.save 재사용(프로젝트 자동 연결·수정 시 기존 status 보존) ──
  async function submitSave(payload, confirmed) {
    if (confirmed) payload.confirmed = 1;
    var btn = document.getElementById('psSaveBtn');
    btn.disabled = true;
    try {
      var res = await api('schedule.save', payload);
      if (res && res.conflict) { showConflict(res.conflicts, payload); return; }
      hideConflict();
      toast(payload.id ? '일정이 수정되었습니다.' : '일정이 등록되었습니다.', 'success');
      closeForm();
      await loadList();
    } catch (e) { toast(e.message, 'error'); }
    finally { btn.disabled = false; }
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var ids = Array.prototype.map.call(form.querySelectorAll('#psParts input:checked'), function (c) { return c.value; });
    var slots = activeSlots();
    var payload = {
      id: document.getElementById('psId').value || undefined,
      project_id: cfg.pid, // 이 프로젝트에 자동 연결
      title: document.getElementById('psTitle').value.trim(),
      participant_ids: ids.join(','),
      event_date: document.getElementById('psDate').value,
      end_date: document.getElementById('psEndDate').value || document.getElementById('psDate').value,
      slots: slots.join(','),
      type: document.getElementById('psType').value,
      memo: document.getElementById('psMemo').value,
      status: editing ? (editing.status || 'scheduled') : 'scheduled',
    };
    if (!slots.length) { toast('시간대(오전/오후/야간)를 1개 이상 선택하세요.', 'error'); return; }
    if (!payload.title || !ids.length || !payload.event_date) { toast('작업 내용·참여 직원·날짜를 입력하세요.', 'error'); return; }
    submitSave(payload, false);
  });

  onReady(loadList);
})();
</script>

<?php if ($wl): ?>
<div class="card pad">
  <div class="section-head">
    <div class="st"><h2>작업일지</h2></div>
    <a href="<?= e(url('worklogs.index', ['project_id' => $p['id']])) ?>" class="btn btn-outline btn-sm">작업일지로 이동</a>
  </div>
  <?php if (!$workLogs): ?>
    <div class="empty"><div class="empty-title">등록된 작업일지가 없습니다.</div></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>작업일</th><th>작성자</th><th class="num">진행률</th><th>내용</th><th>확인</th></tr></thead>
        <tbody>
          <?php foreach ($workLogs as $w): ?>
            <tr>
              <td class="nowrap"><?= fmtdate($w['work_date']) ?></td>
              <td><?= e($w['user_name']) ?></td>
              <td class="num mono"><?= $w['progress'] !== null ? (int) $w['progress'] . '%' : '-' ?></td>
              <td class="wrap"><?= e(Util::truncate($w['content'], 60)) ?></td>
              <td><?= $w['confirmed_at'] ? '<span class="badge badge-ok">확인</span>' : '<span class="badge badge-muted">대기</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
