<?php
/** @var array $project @var array $calc @var array $assignments @var array $history
 *  @var array $costs @var array $schedules @var array $workLogs @var array $photos @var array $docs
 *  @var array $statuses
 */
$p = $project;
$today = date('Y-m-d');
$isDelayed = !empty($p['end_date']) && $p['end_date'] < $today && $p['status'] !== 'completed';
$badgeClass = ['preparing' => 'badge-muted', 'in_progress' => 'badge-info', 'paused' => 'badge-warn', 'completed' => 'badge-ok'][$p['status']] ?? 'badge';
$progress = (int) $p['progress'];
$canManage = can('project.manage');
$canAssign = can('project.assign');
$canCost   = can('cost.manage');
?>
<div class="page">
  <div class="detail-head">
    <div>
      <div class="detail-title"><?= e($p['name']) ?> <span class="badge <?= $badgeClass ?>"><?= e($statuses[$p['status']] ?? $p['status']) ?></span></div>
      <div class="detail-meta">
        <?= e($p['project_no']) ?> · 고객 <?= e($p['customer_name']) ?> ·
        현장주소 <?= e($p['site_address'] ?: '-') ?>
        <?php if ($isDelayed): ?><span class="text-danger"> · 지연</span><?php endif; ?>
      </div>
    </div>
    <div class="page-actions">
      <a href="<?= e(url('projects.index')) ?>" class="btn btn-outline">목록</a>
      <?php if ($canManage): ?>
        <a href="<?= e(url('projects.form', ['id' => $p['id']])) ?>" class="btn btn-outline">수정</a>
        <form method="post" action="<?= e(url('projects.delete')) ?>" style="display:inline" onsubmit="return confirm('이 프로젝트를 삭제하시겠습니까?');">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button type="submit" class="btn btn-danger">삭제</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">기본 정보</div></div>
    <div class="card-body">
      <div class="kv-row" style="margin-bottom:14px">
        <div class="kv"><div class="kv-label">진행률</div><div class="kv-value"><?= $progress ?>%</div>
          <div class="progress" style="width:140px"><div class="progress-bar <?= $progress >= 100 ? 'ok' : ($isDelayed ? 'danger' : '') ?>" style="width:<?= $progress ?>%"></div></div>
        </div>
        <div class="kv"><div class="kv-label">계약금액</div><div class="kv-value"><?= money($calc['contract_amount']) ?></div></div>
        <div class="kv"><div class="kv-label">예상원가</div><div class="kv-value"><?= money($calc['estimated_cost']) ?></div></div>
        <div class="kv"><div class="kv-label">실제원가</div><div class="kv-value"><?= money($calc['actual_cost']) ?></div></div>
        <div class="kv"><div class="kv-label">예상순이익</div><div class="kv-value <?= $calc['estimated_profit'] < 0 ? 'text-danger' : '' ?>"><?= money($calc['estimated_profit']) ?></div></div>
        <div class="kv"><div class="kv-label">예상순이익률</div><div class="kv-value"><?= pct($calc['estimated_profit_rate']) ?></div></div>
        <div class="kv"><div class="kv-label">실제순이익</div><div class="kv-value <?= $calc['actual_profit'] < 0 ? 'text-danger' : '' ?>"><?= money($calc['actual_profit']) ?></div></div>
        <div class="kv"><div class="kv-label">실제순이익률</div><div class="kv-value"><?= pct($calc['actual_profit_rate']) ?></div></div>
      </div>
      <dl class="dl">
        <dt>공사유형</dt><dd><?= e($p['work_type'] ?: '-') ?></dd>
        <dt>공정 단계</dt><dd><?= e($p['process_stage_name'] ?? '-') ?></dd>
        <dt>영업담당</dt><dd><?= e($p['sales_user_name'] ?? '-') ?></dd>
        <dt>현장관리자</dt><dd><?= e($p['site_manager_name'] ?? '-') ?></dd>
        <dt>계약일</dt><dd><?= fmtdate($p['contract_date']) ?></dd>
        <dt>착공/준공예정</dt><dd><?= fmtdate($p['start_date']) ?> ~ <?= fmtdate($p['end_date']) ?></dd>
        <dt>실제착공/준공</dt><dd><?= fmtdate($p['actual_start_date']) ?> ~ <?= fmtdate($p['actual_end_date']) ?></dd>
        <dt>중요도</dt><dd><?= e(['low' => '낮음', 'mid' => '보통', 'high' => '높음'][$p['importance']] ?? ($p['importance'] ?: '-')) ?></dd>
        <dt>기여도 배분방식</dt><dd><?= e(['main' => '주담당 100%', 'ratio' => '비율 직접입력', 'role' => '역할별 기본배분'][$p['contribution_mode']] ?? $p['contribution_mode']) ?></dd>
        <dt>메모</dt><dd><?= nl2br(e($p['memo'] ?: '-')) ?></dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">배정 직원</div></div>
    <div class="card-body">
      <?php if (!$assignments): ?>
        <div class="empty"><div class="empty-title">배정된 직원이 없습니다.</div></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>이름</th><th>역할</th><th class="num">기여도</th><th>기간</th><th>상태</th><?php if ($canAssign): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
              <?php foreach ($assignments as $a): ?>
                <tr>
                  <td><?= e($a['user_name']) ?></td>
                  <td><?= e($a['role'] ?: '-') ?></td>
                  <td class="num"><?= pct((float) $a['contribution_pct']) ?></td>
                  <td class="nowrap"><?= fmtdate($a['start_date']) ?> ~ <?= fmtdate($a['end_date']) ?></td>
                  <td><span class="badge"><?= e($a['status']) ?></span></td>
                  <?php if ($canAssign): ?>
                  <td>
                    <form method="post" action="<?= e(url('assignments.delete')) ?>" onsubmit="return confirm('배정을 삭제하시겠습니까?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                      <button type="submit" class="btn btn-ghost btn-sm">삭제</button>
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
        <form method="post" action="<?= e(url('assignments.save')) ?>" class="form-grid" style="margin-top:14px">
          <?= csrf_field() ?>
          <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="status" value="active">
          <div class="field"><label class="field-label">직원 ID<span class="req">*</span></label>
            <input type="number" name="user_id" class="input" required placeholder="직원 ID"></div>
          <div class="field"><label class="field-label">역할</label>
            <input type="text" name="role" class="input" placeholder="예: 현장관리, 페인트공"></div>
          <div class="field"><label class="field-label">기여도(%)</label>
            <input type="number" name="contribution_pct" class="input" step="0.01" min="0" max="100" value="0"></div>
          <div class="field"><label class="field-label">시작일</label><input type="date" name="start_date" class="input"></div>
          <div class="field"><label class="field-label">종료일</label><input type="date" name="end_date" class="input"></div>
          <div class="field"><button type="submit" class="btn btn-primary">배정 추가</button></div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">공정 이력</div></div>
    <div class="card-body">
      <?php if (!$history): ?>
        <div class="empty"><div class="empty-title">공정 이동 이력이 없습니다.</div></div>
      <?php else: ?>
        <div class="timeline">
          <?php foreach ($history as $h): ?>
            <div class="timeline-item">
              <div class="timeline-time"><?= e($h['changed_at']) ?></div>
              <div class="timeline-body"><?= e($h['from_name'] ?? '(시작)') ?> → <?= e($h['to_name']) ?>
                <?php if ($h['reason']): ?><span class="muted"> · <?= e($h['reason']) ?></span><?php endif; ?>
              </div>
              <div class="timeline-tag"><?= e($h['changed_by_name'] ?? '-') ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">비용</div></div>
    <div class="card-body">
      <?php if (!$costs): ?>
        <div class="empty"><div class="empty-title">등록된 비용이 없습니다.</div></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>구분</th><th>항목</th><th class="num">금액</th><th>발생일</th><th>메모</th></tr></thead>
            <tbody>
              <?php foreach ($costs as $c): ?>
                <tr>
                  <td><span class="badge <?= $c['type'] === 'actual' ? 'badge-info' : 'badge-muted' ?>"><?= $c['type'] === 'actual' ? '실제' : '예상' ?></span></td>
                  <td><?= e($c['category']) ?></td>
                  <td class="num"><?= money((float) $c['amount']) ?></td>
                  <td class="nowrap"><?= fmtdate($c['spent_date']) ?></td>
                  <td class="wrap"><?= e($c['memo'] ?: '-') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($canCost): ?>
        <form method="post" action="<?= e(url('costs.save')) ?>" class="form-grid-3" style="margin-top:14px">
          <?= csrf_field() ?>
          <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
          <div class="field"><label class="field-label">구분</label>
            <select name="type" class="select"><option value="estimate">예상</option><option value="actual">실제</option></select></div>
          <div class="field"><label class="field-label">항목</label>
            <input type="text" name="category" class="input" placeholder="예: 자재비, 인건비"></div>
          <div class="field"><label class="field-label">금액</label>
            <input type="number" name="amount" class="input" min="0" value="0"></div>
          <div class="field"><label class="field-label">발생일</label><input type="date" name="spent_date" class="input"></div>
          <div class="field col-span-2"><label class="field-label">메모</label><input type="text" name="memo" class="input"></div>
          <div class="field"><button type="submit" class="btn btn-primary">비용 등록</button></div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-title">일정</div>
      <a href="<?= e(url('schedule.index', ['project_id' => $p['id']])) ?>" class="btn btn-outline btn-sm">일정 관리로 이동</a>
    </div>
    <div class="card-body">
      <?php if (!$schedules): ?>
        <div class="empty"><div class="empty-title">등록된 일정이 없습니다.</div></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>제목</th><th>시작</th><th>종료</th><th>유형</th><th>상태</th></tr></thead>
            <tbody>
              <?php foreach ($schedules as $s): ?>
                <tr>
                  <td class="wrap"><?= e($s['title']) ?></td>
                  <td class="nowrap"><?= e($s['start_datetime']) ?></td>
                  <td class="nowrap"><?= e($s['end_datetime']) ?></td>
                  <td><?= e($s['type']) ?></td>
                  <td><?= e($s['status']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-title">작업일지</div>
      <a href="<?= e(url('worklogs.index', ['project_id' => $p['id']])) ?>" class="btn btn-outline btn-sm">작업일지로 이동</a>
    </div>
    <div class="card-body">
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
                  <td class="num"><?= $w['progress'] !== null ? (int) $w['progress'] . '%' : '-' ?></td>
                  <td class="wrap"><?= e(Util::truncate($w['content'], 60)) ?></td>
                  <td><?= $w['confirmed_at'] ? '<span class="badge badge-ok">확인</span>' : '<span class="badge badge-muted">대기</span>' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">현장 사진</div></div>
    <div class="card-body">
      <?php if (!$photos): ?>
        <div class="empty"><div class="empty-title">등록된 현장 사진이 없습니다.</div></div>
      <?php else: ?>
        <div class="photo-grid" style="margin-bottom:14px">
          <?php foreach ($photos as $f): ?>
            <a class="photo-thumb" href="<?= e(url('files.download', ['id' => $f['id']])) ?>" target="_blank" title="<?= e($f['original_name']) ?>">
              <img src="<?= e(url('files.download', ['id' => $f['id']])) ?>" alt="<?= e($f['original_name']) ?>">
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('projects.upload')) ?>" enctype="multipart/form-data" class="flex items-center gap-8">
        <?= csrf_field() ?>
        <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
        <input type="hidden" name="category" value="photo">
        <input type="file" name="file" accept="image/*" required>
        <button type="submit" class="btn btn-outline btn-sm">사진 업로드</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">첨부 문서</div></div>
    <div class="card-body">
      <?php if (!$docs): ?>
        <div class="empty"><div class="empty-title">첨부된 문서가 없습니다.</div></div>
      <?php else: ?>
        <?php foreach ($docs as $f): ?>
          <div class="file-item">
            <span class="file-name"><?= e($f['original_name']) ?></span>
            <span class="muted nowrap"><?= number_format((int) $f['size'] / 1024, 0) ?> KB</span>
            <a href="<?= e(url('files.download', ['id' => $f['id']])) ?>" class="btn btn-outline btn-sm">다운로드</a>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <form method="post" action="<?= e(url('projects.upload')) ?>" enctype="multipart/form-data" class="flex items-center gap-8" style="margin-top:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
        <input type="hidden" name="category" value="doc">
        <input type="file" name="file" required>
        <button type="submit" class="btn btn-outline btn-sm">문서 업로드</button>
      </form>
    </div>
  </div>
</div>
