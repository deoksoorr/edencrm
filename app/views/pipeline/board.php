<?php
/**
 * 조회 전용 칸반 컬럼 조각 — 파생 7그룹 컬럼(R4 T7, 12단계 컬럼 대체).
 * 2행 배치(1행 진행 4그룹 / 2행 결과 3그룹 — 페이지 가로 스크롤 없음, r3 배치 계승).
 * 카드 전체가 상세 페이지 링크(pipeline.show) — 드래그·인라인 수정 없음.
 * @var array $columns PipelineStageService::GROUPS 순서의 [key,label,color,leads,sum]
 */
$rowDefs = [
    ['label' => '진행 — 신규 문의 · 상담 중 · 현장 확인 · 견적 진행', 'groups' => ['new_inquiry', 'consulting', 'site_check', 'quoting']],
    ['label' => '결과 — 계약 · 보류 · 종료', 'groups' => ['contracted', 'on_hold', 'closed']],
];
foreach ($rowDefs as $ri => $rowDef):
?>
<section class="kanban-row" data-row="<?= $ri + 1 ?>">
  <div class="kanban-row-label"><?= e($rowDef['label']) ?></div>
  <div class="kanban-row-cols">
<?php foreach ($rowDef['groups'] as $gkey):
    $col = $columns[$gkey] ?? null;
    if (!$col) { continue; }
?>
  <div class="kanban-col" data-group="<?= e($gkey) ?>" style="--gc:<?= e($col['color']) ?>">
    <div class="kanban-col-head">
      <div class="kanban-col-title">
        <span class="kanban-caret" title="접기/펼치기" role="button" tabindex="0">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </span>
        <span><?= e($col['label']) ?></span>
        <span class="kanban-count"><?= count($col['leads']) ?></span>
      </div>
      <div class="kanban-col-sum" title="<?= e(number_format($col['sum']) . '원') ?>"><?= e(moneyShort((float) $col['sum'])) ?></div>
    </div>
    <div class="kanban-list">
      <?php if (!$col['leads']): ?>
        <div class="kanban-empty">카드 없음</div>
      <?php endif; ?>
      <?php foreach ($col['leads'] as $l):
        // 상태선: 계약 초록 / 종료 회색 / 연락 지연 빨강 / 연락 임박 주황 / 정상 파랑
        $statusCls = $gkey === 'contracted' ? 'st-won'
            : ($gkey === 'closed' ? 'st-closed' : ($l['delayed'] ? 'st-delayed' : ($l['warn'] ? 'st-warn' : 'st-normal')));
        $nextCls = $l['delayed'] ? 'danger' : ($l['warn'] ? 'warn' : '');
        $est = !empty($l['link_contract_estimated']);
      ?>
        <a class="kanban-card kc-link <?= $statusCls ?>" href="<?= e(url('pipeline.show', ['id' => (int) $l['id']])) ?>">
          <div class="kc-top">
            <div class="kc-name"><?= e($l['customer_name']) ?><?= $l['company_name'] ? ' (' . e(Util::truncate($l['company_name'], 12)) . ')' : '' ?></div>
            <span class="badge badge-stage" style="--sc:<?= e($l['derived_color']) ?>" title="자동 산정: <?= e($l['derived_source']) ?>"><?= e($l['derived_label']) ?></span>
          </div>
          <div class="kc-type"><?= e(Util::truncate($l['work_type'] ?: '공사유형 미지정', 22)) ?></div>
          <div class="kc-amount"><?= e(moneyShort((float) $l['expected_amount'])) ?>원</div>
          <div class="kc-foot">
            <span class="kc-owner"><?= e($l['sales_user_name'] ?: '담당 미지정') ?></span>
            <?php if ($l['next_contact_date']): ?>
              <span class="kc-next <?= $nextCls ?>" title="다음 일정(다음 연락 예정일)">다음 <?= e(Util::date($l['next_contact_date'], 'n/j')) ?></span>
            <?php elseif (!in_array($gkey, ['contracted', 'closed'], true)): ?>
              <span class="kc-next warn">연락일 미정</span>
            <?php endif; ?>
          </div>
          <div class="kc-meta">
            <span class="m" title="최근 연락일(최근 활동일)">최근 <?= $l['last_activity_date'] ? e(Util::date($l['last_activity_date'], 'n/j')) : '-' ?></span>
            <span class="m" title="현재 단계 체류일">체류 D+<?= (int) $l['stay_days'] ?></span>
            <?php if (!empty($l['link_quote'])): ?>
              <span class="m kc-doc" title="연결 견적">견적 <?= e($l['link_quote']['quote_no']) ?></span>
            <?php endif; ?>
            <?php if (!empty($l['link_contract'])): ?>
              <span class="m kc-doc" title="<?= $est ? '고객 단위 추정 계약(연결 미확정)' : '연결 계약' ?>">계약 <?= e($l['link_contract']['contract_no']) ?><?= $est ? '·추정' : '' ?></span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; // 열 ?>
  </div>
</section>
<?php endforeach; // 행 ?>
