<?php
/**
 * [공정] 탭 — 현재 공정·진행 현황(단계 목록 내 현재 위치)·하자보수 관리·공정 이력 요약. projects/show.php 에서 include.
 * R14: 수동 공정 변경 드롭다운(구 process.move 라우트)은 폐지 — 공정 진행률은 공정 보드의 카드 게이지
 * (process.progress.set, ProcessService::setStageProgress 경유)에서만 조정한다. 이 탭에는 보드로 가는 링크만 남긴다.
 */
$curStageId = $p['process_stage_id'] !== null ? (int) $p['process_stage_id'] : null;
$curSort    = $p['process_stage_sort'] !== null ? (int) $p['process_stage_sort'] : null;
$stByKey = [];
foreach ($processStages as $st) {
    $stByKey[$st['stage_key']] = $st;
}
// R8-A: 공정 그룹은 프로젝트 공사 유형 기준(미지정 NULL → painting) — $processStages(컨트롤러)와 동일 집합
$ctType  = Stages::normalizeConstructionType($p['construction_type'] ?? null);
$ctLabel = Stages::constructionTypeLabel($p['construction_type'] ?? null);
?>
<div class="card pad">
  <div class="section-head">
    <div class="st"><h2>공정 진행 현황</h2></div>
    <a href="<?= e(url('process.board', ['type' => $ctType])) ?>" class="btn btn-outline btn-sm">공정 보드로 이동</a>
  </div>
  <div class="kv-row mb-14">
    <div class="kv"><div class="kv-label">공사 유형</div>
      <div class="kv-value"><span class="badge <?= empty($p['construction_type']) ? 'badge-warn' : 'badge-info' ?>" title="공정 보드 도장/인테리어 탭 분류<?= empty($p['construction_type']) ? ' — 미지정은 양쪽 보드에 표시(프로젝트 수정 또는 보드 배지에서 지정)' : '' ?>"><?= e($ctLabel) ?></span></div></div>
    <div class="kv"><div class="kv-label">현재 공정</div>
      <div class="kv-value"><?php if ($psName !== null): ?><span class="badge" style="background:<?= e($psColor) ?>1a;color:<?= e($psColor) ?>;border:1px solid <?= e($psColor) ?>4d"><?= e($psName) ?></span><?php else: ?><span class="badge badge-muted">공정 미배치</span><?php endif; ?></div></div>
    <div class="kv"><div class="kv-label">공정 진입일</div>
      <div class="kv-value" style="font-size:13.5px"><?= e($p['process_entered_at'] ?: '-') ?></div></div>
    <div class="kv" title="공정 단계 이동 시 실공정 순서 비율로 자동 산정"><div class="kv-label">진행률</div>
      <div class="kv-value"><?= $progress ?>%</div></div>
  </div>

  <div class="proc-flow">
    <?php foreach (Stages::processGroups($ctType) as $g): ?>
      <div class="proc-group">
        <div class="pg-label"><?= e($g['label']) ?></div>
        <div class="proc-steps">
          <?php foreach ($g['stages'] as $sk): if (!isset($stByKey[$sk])) { continue; } $st = $stByKey[$sk];
            $cls = $curStageId === (int) $st['id'] ? 'current'
                 : ($curSort !== null && (int) $st['sort_order'] < $curSort ? 'done' : ''); ?>
            <span class="proc-step <?= $cls ?>"><?= e($st['name']) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($curStageId === null): ?>
    <div class="muted mt-8">공정 미배치 상태입니다 — 공정 보드 진입 시 '대기중'으로 자동 배치됩니다.</div>
  <?php endif; ?>

  <div class="mt-14">
    <div class="muted mb-8">공정 진행률은 공정 보드의 카드 게이지에서 관리합니다.</div>
    <a href="<?= e(url('process.board', ['type' => $ctType])) ?>" class="btn btn-outline btn-sm">공정 보드로 이동</a>
  </div>
</div>

<?php
// ── 하자보수(R4 T3) — warranty_repairs CRUD. 사진은 project_files(entity_type='warranty_repair') 재사용 ──
$warrantyRepairs = $warrantyRepairs ?? [];
$warrantyPhotos  = $warrantyPhotos ?? [];
$wrStatuses = ['open' => '접수', 'in_progress' => '처리 중', 'done' => '완료'];
$wrBadge    = ['open' => 'badge-warn', 'in_progress' => 'badge-info', 'done' => 'badge-ok'];
$wrOpen     = count(array_filter($warrantyRepairs, static fn($w) => $w['status'] !== 'done'));
?>
<div class="card pad" id="warrantyCard">
  <div class="section-head">
    <div class="st"><h2>하자보수</h2>
      <span class="section-desc">전체 <?= count($warrantyRepairs) ?>건 · 미완료 <?= $wrOpen ?>건 — 미완료 하자가 있으면 '전체완료' 이동 시 경고됩니다</span></div>
    <?php if ($canManage): ?><button type="button" class="btn btn-outline btn-sm" id="wrNewBtn">하자 등록</button><?php endif; ?>
  </div>

  <?php if (!$warrantyRepairs): ?>
    <div class="empty"><div class="empty-title">등록된 하자보수 건이 없습니다.</div></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data wr-table">
        <thead><tr>
          <th>상태</th><th>내용</th><th>요청일</th><th>요청자</th><th>담당</th><th>예정일</th><th>완료일</th><th>사진</th><th>메모</th><?php if ($canManage): ?><th class="ta-r">관리</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($warrantyRepairs as $w): $ph = $warrantyPhotos[(int) $w['id']] ?? []; ?>
          <tr data-wr='<?= e(json_encode([
                'id' => (int) $w['id'], 'content' => $w['content'], 'requested_at' => $w['requested_at'],
                'requested_by' => $w['requested_by'] !== null ? (int) $w['requested_by'] : null,
                'assignee_id' => $w['assignee_id'] !== null ? (int) $w['assignee_id'] : null,
                'due_date' => $w['due_date'], 'completed_at' => $w['completed_at'],
                'memo' => $w['memo'], 'status' => $w['status'],
            ], JSON_UNESCAPED_UNICODE)) ?>'>
            <td><span class="badge <?= e($wrBadge[$w['status']] ?? 'badge-muted') ?>"><?= e($wrStatuses[$w['status']] ?? $w['status']) ?></span></td>
            <td class="wr-content"><?= e($w['content']) ?></td>
            <td><?= e($w['requested_at'] ?: '-') ?></td>
            <td><?= e($w['requested_by_name'] ?? '-') ?></td>
            <td><?= e($w['assignee_name'] ?? '담당 미지정') ?></td>
            <td><?= e($w['due_date'] ?: '-') ?></td>
            <td><?= e($w['completed_at'] ?: '-') ?></td>
            <td>
              <?php if ($ph): ?>
                <div class="wr-photos">
                  <?php foreach ($ph as $f): ?>
                    <a href="<?= e(url('files.download', ['id' => (int) $f['id']])) ?>" target="_blank" title="<?= e($f['original_name']) ?>"><img src="<?= e(url('files.download', ['id' => (int) $f['id']])) ?>" alt="" loading="lazy"></a>
                  <?php endforeach; ?>
                </div>
              <?php else: ?><span class="muted">-</span><?php endif; ?>
              <?php if ($canManage): ?>
                <label class="wr-photo-add" title="사진 추가(JPG/PNG 등 이미지)">＋사진<input type="file" accept="image/*" data-wr-photo="<?= (int) $w['id'] ?>" hidden></label>
              <?php endif; ?>
            </td>
            <td class="wr-memo"><?= e($w['memo'] ?: '-') ?></td>
            <?php if ($canManage): ?>
            <td class="ta-r">
              <button type="button" class="btn btn-ghost btn-sm wr-edit">수정</button>
              <button type="button" class="btn btn-ghost btn-sm text-danger wr-del" data-id="<?= (int) $w['id'] ?>">삭제</button>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($canManage): ?>
    <form class="form-grid-3 mt-14" id="wrForm" style="display:none">
      <?= csrf_field() ?>
      <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
      <input type="hidden" name="id" value="0">
      <div class="field" style="grid-column:1/-1"><label class="field-label">하자 내용 <span class="req">*</span></label>
        <input type="text" name="content" class="input" maxlength="500" placeholder="예: 남측 외벽 3층 도장 들뜸" required></div>
      <div class="field"><label class="field-label">요청일</label>
        <input type="date" name="requested_at" class="input" value="<?= e(date('Y-m-d')) ?>"></div>
      <div class="field"><label class="field-label">요청 접수자</label>
        <select name="requested_by" class="select"><option value="">선택 안 함</option>
          <?php foreach (($staffOptions ?? []) as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?><?= $u['position'] ? ' (' . e($u['position']) . ')' : '' ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label class="field-label">처리 담당</label>
        <select name="assignee_id" class="select"><option value="">담당 미지정</option>
          <?php foreach (($staffOptions ?? []) as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?><?= $u['position'] ? ' (' . e($u['position']) . ')' : '' ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label class="field-label">처리 예정일</label>
        <input type="date" name="due_date" class="input"></div>
      <div class="field"><label class="field-label">처리 완료일</label>
        <input type="date" name="completed_at" class="input"></div>
      <div class="field"><label class="field-label">상태</label>
        <select name="status" class="select">
          <?php foreach ($wrStatuses as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field" style="grid-column:1/-1"><label class="field-label">메모</label>
        <input type="text" name="memo" class="input" maxlength="500" placeholder="처리 경과·특이사항"></div>
      <div class="field wr-form-actions">
        <button type="submit" class="btn btn-primary" id="wrSubmit">하자 등록</button>
        <button type="button" class="btn btn-outline" id="wrCancel">취소</button>
      </div>
    </form>
    <script>
    (function () {
      'use strict';
      var form = document.getElementById('wrForm');
      var newBtn = document.getElementById('wrNewBtn');
      var card = document.getElementById('warrantyCard');
      if (!form || !card) return;
      function reloadToTab() { location.hash = '#process'; location.reload(); }
      function openForm(data) {
        form.style.display = '';
        form.querySelector('[name="id"]').value = data ? data.id : 0;
        form.querySelector('[name="content"]').value = data ? (data.content || '') : '';
        form.querySelector('[name="requested_at"]').value = data ? (data.requested_at || '') : '<?= e(date('Y-m-d')) ?>';
        form.querySelector('[name="requested_by"]').value = data && data.requested_by ? data.requested_by : '';
        form.querySelector('[name="assignee_id"]').value = data && data.assignee_id ? data.assignee_id : '';
        form.querySelector('[name="due_date"]').value = data ? (data.due_date || '') : '';
        form.querySelector('[name="completed_at"]').value = data ? (data.completed_at || '') : '';
        form.querySelector('[name="status"]').value = data ? data.status : 'open';
        form.querySelector('[name="memo"]').value = data ? (data.memo || '') : '';
        document.getElementById('wrSubmit').textContent = data ? '하자 수정' : '하자 등록';
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      if (newBtn) newBtn.addEventListener('click', function () { openForm(null); });
      document.getElementById('wrCancel').addEventListener('click', function () { form.style.display = 'none'; });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('wrSubmit');
        btn.disabled = true;
        api('process.warranty.save', new FormData(form))
          .then(function () { toast('하자보수 건이 저장되었습니다.', 'success'); reloadToTab(); })
          .catch(function (err) { toast((err && err.message) || '저장에 실패했습니다.', 'error'); btn.disabled = false; });
      });
      card.addEventListener('click', function (e) {
        var eb = e.target.closest('.wr-edit');
        if (eb) {
          var tr = eb.closest('tr');
          try { openForm(JSON.parse(tr.getAttribute('data-wr'))); } catch (x) { toast('데이터를 읽지 못했습니다.', 'error'); }
          return;
        }
        var db = e.target.closest('.wr-del');
        if (db) {
          EDEN.confirm('이 하자보수 건을 삭제하시겠습니까? (삭제 이력은 감사 로그에 보존됩니다)', { danger: true, okLabel: '삭제' })
            .then(function (ok) {
              if (!ok) return;
              api('process.warranty.delete', { id: db.dataset.id })
                .then(function () { toast('삭제되었습니다.', 'success'); reloadToTab(); })
                .catch(function (err) { toast((err && err.message) || '삭제에 실패했습니다.', 'error'); });
            });
        }
      });
      card.addEventListener('change', function (e) {
        var inp = e.target.closest('[data-wr-photo]');
        if (!inp || !inp.files || !inp.files[0]) return;
        var fd = new FormData();
        fd.append('id', inp.getAttribute('data-wr-photo'));
        fd.append('file', inp.files[0]);
        api('process.warranty.photo', fd)
          .then(function () { toast('사진이 업로드되었습니다.', 'success'); reloadToTab(); })
          .catch(function (err) { toast((err && err.message) || '업로드에 실패했습니다.', 'error'); inp.value = ''; });
      });
    })();
    </script>
  <?php endif; ?>
</div>

<div class="card pad">
  <div class="section-head"><div class="st"><h2>공정 이력</h2><span class="section-desc">최근 5건 — 전체는 '이력' 탭</span></div></div>
  <?php if (!$history): ?>
    <div class="empty"><div class="empty-title">공정 이동 이력이 없습니다.</div></div>
  <?php else: ?>
    <div class="timeline">
      <?php foreach (array_slice($history, 0, 5) as $h): ?>
        <div class="timeline-item">
          <div class="timeline-time"><?= e($h['changed_at']) ?><?= !empty($h['is_auto']) ? ' · 자동' : '' ?></div>
          <div class="timeline-body"><?= e($h['from_name'] ?? '(시작)') ?> → <?= e($h['to_name']) ?>
            <?php if ($h['reason']): ?><span class="muted"> · <?= e($h['reason']) ?></span><?php endif; ?>
          </div>
          <div class="timeline-tag"><?= e($h['changed_by_name'] ?? '-') ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
