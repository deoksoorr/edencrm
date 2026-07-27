<?php
/**
 * [이력] 탭 — 프로젝트 상태 이력 + 공정 변경 이력 + 감사 로그 발췌.
 * 긴 이력은 기본 최근 5건만 출력하고 '더보기'로 펼친다(.hist-hidden + .hist-more).
 * projects/show.php 에서 include.
 */
$histLimit = 5;
$auditActionLabels = [
    'project_update'           => '프로젝트 수정',
    'project_exception_create' => '예외 프로젝트 생성',
    'project_exception_convert' => '예외→일반 전환',
    'project_auto_create'      => '프로젝트 자동 생성',
    'project_auto_create_failed' => '프로젝트 자동 생성 실패',
    'project_status_change'    => '상태 변경',
    'process_move'             => '공정 이동',
    'project_delete'           => '프로젝트 삭제',
    'access_denied'            => '접근 거부',
];
?>
<div class="card pad">
  <div class="section-head">
    <div class="st"><h2>상태 이력</h2><span class="section-desc">총 <?= count($statusHistory) ?>건</span></div>
    <?php if (count($statusHistory) > $histLimit): ?>
      <button type="button" class="btn btn-ghost btn-sm hist-more" data-target="histStatusList">더보기 (+<?= count($statusHistory) - $histLimit ?>건)</button>
    <?php endif; ?>
  </div>
  <div class="muted mb-14 fs-12">취소 = 착공 전 철회 · 파기 = 진행 중 계약관계 종료 · 일시 중단 = 재개 가능 일시 정지 · 정산 완료 = 완료 후 대금 정산 종료. 취소·파기 시에도 일정·배정·지출·입금·파일·이력은 보존됩니다.</div>
  <?php if (empty($statusHistory)): ?>
    <div class="empty"><div class="empty-title">상태 변경 이력이 없습니다.</div></div>
  <?php else: ?>
    <div class="timeline" id="histStatusList">
      <?php foreach ($statusHistory as $i => $h): $hd = $h['detail_json'] ? (json_decode((string) $h['detail_json'], true) ?: []) : []; ?>
        <div class="timeline-item<?= $i >= $histLimit ? ' hist-hidden' : '' ?>">
          <div class="timeline-time"><?= e($h['changed_at']) ?></div>
          <div class="timeline-body">
            <?= e($h['from_status'] !== null ? ($statuses[$h['from_status']] ?? $h['from_status']) : '(등록)') ?>
            → <span class="badge <?= e($statusBadge[$h['to_status']] ?? 'badge-muted') ?>"><?= e($statuses[$h['to_status']] ?? $h['to_status']) ?></span>
            <?php if ($h['reason']): ?><span class="muted"> · <?= e($h['reason']) ?></span><?php endif; ?>
            <?php if ($hd): ?>
              <div class="muted" style="font-size:12px;margin-top:2px">
                <?php if (isset($hd['effective_date'])): ?>처리일 <?= e($hd['effective_date']) ?><?php endif; ?>
                <?php if (!empty($hd['billed_amount'])): ?> · 청구 <?= number_format((int) $hd['billed_amount']) ?>원<?php endif; ?>
                <?php if (!empty($hd['refund_amount'])): ?> · 환불 <?= number_format((int) $hd['refund_amount']) ?>원<?php endif; ?>
                <?php if (array_key_exists('is_settled', $hd)): ?> · 정산 <?= $hd['is_settled'] ? '완료' : '미정산' ?><?php endif; ?>
                <?php if (!empty($hd['followup'])): ?> · 후속 조치: <?= e((string) $hd['followup']) ?><?php endif; ?>
                <?php if (!empty($hd['memo'])): ?> · 메모: <?= e((string) $hd['memo']) ?><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="timeline-tag"><?= e($h['changed_by_name'] ?? '-') ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card pad">
  <div class="section-head">
    <div class="st"><h2>공정 변경 이력</h2><span class="section-desc">총 <?= count($history) ?>건</span></div>
    <?php if (count($history) > $histLimit): ?>
      <button type="button" class="btn btn-ghost btn-sm hist-more" data-target="histProcessList">더보기 (+<?= count($history) - $histLimit ?>건)</button>
    <?php endif; ?>
  </div>
  <?php if (!$history): ?>
    <div class="empty"><div class="empty-title">공정 이동 이력이 없습니다.</div></div>
  <?php else: ?>
    <div class="timeline" id="histProcessList">
      <?php foreach ($history as $i => $h): ?>
        <div class="timeline-item<?= $i >= $histLimit ? ' hist-hidden' : '' ?>">
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

<?php if ($auditRows !== null): ?>
<div class="card pad">
  <div class="section-head">
    <div class="st"><h2>감사 로그 발췌</h2><span class="section-desc">이 프로젝트 관련 최근 <?= count($auditRows) ?>건</span></div>
    <div class="flex items-center gap-8">
      <?php if (count($auditRows) > $histLimit): ?>
        <button type="button" class="btn btn-ghost btn-sm hist-more" data-target="histAuditList">더보기 (+<?= count($auditRows) - $histLimit ?>건)</button>
      <?php endif; ?>
      <a href="<?= e(url('audit.index')) ?>" class="btn btn-outline btn-sm">감사 로그 전체</a>
    </div>
  </div>
  <?php if (!$auditRows): ?>
    <div class="empty"><div class="empty-title">감사 로그가 없습니다.</div></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data compact">
        <thead><tr><th>일시</th><th>동작</th><th>처리자</th></tr></thead>
        <tbody id="histAuditList">
          <?php foreach ($auditRows as $i => $a): ?>
            <tr class="<?= $i >= $histLimit ? 'hist-hidden' : '' ?>">
              <td class="nowrap"><?= e($a['created_at']) ?></td>
              <td><?= e($auditActionLabels[$a['action']] ?? $a['action']) ?></td>
              <td><?= e($a['user_name'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
