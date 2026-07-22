<?php
/**
 * 칸반 컬럼 조각(초기 렌더 + AJAX 필터 새로고침 공용).
 * 12 DB단계 열 유지(드래그 정밀도) + 6그룹 상단 액센트(--gc). 간소화 카드(핵심 정보만).
 * @var array $columns @var bool $canManage
 */
foreach ($columns as $col):
    $stage = $col['stage'];
    $isClosed = (int) $stage['is_lost'] === 1;
    $isWon = (int) $stage['is_won'] === 1;
?>
  <div class="kanban-col" data-stage-id="<?= (int) $stage['id'] ?>" data-group="<?= e($col['group']) ?>" style="--gc:<?= e($col['group_color']) ?>">
    <div class="kanban-col-head">
      <div class="kanban-col-title">
        <span class="kanban-caret" title="접기/펼치기">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </span>
        <span><?= e($stage['name']) ?></span>
        <span class="kanban-count"><?= count($col['leads']) ?></span>
      </div>
      <div class="kanban-col-sum" title="<?= e(number_format($col['sum']) . '원') ?>"><?= e(moneyShort((float) $col['sum'])) ?></div>
    </div>
    <div class="kanban-list" data-stage-id="<?= (int) $stage['id'] ?>">
      <?php if (!$col['leads']): ?>
        <div class="kanban-empty">카드 없음</div>
      <?php endif; ?>
      <?php foreach ($col['leads'] as $l):
        // 단일 상태선 색: 계약완료 초록 / 종료 회색 / 지연 빨강 / 연락임박 주황 / 정상 파랑
        $statusCls = $isWon ? 'st-won' : ($isClosed ? 'st-closed' : ($l['delayed'] ? 'st-delayed' : ($l['warn'] ? 'st-warn' : 'st-normal')));
        $nextCls = $l['delayed'] ? 'danger' : ($l['warn'] ? 'warn' : '');
      ?>
        <div class="kanban-card <?= $statusCls ?>" data-lead-id="<?= (int) $l['id'] ?>" data-amount="<?= (int) $l['expected_amount'] ?>">
          <div class="kc-top">
            <div class="kc-name"><?= e($l['customer_name']) ?><?= $l['company_name'] ? ' (' . e(Util::truncate($l['company_name'], 12)) . ')' : '' ?></div>
            <?php if ($l['importance'] === 'high'): ?><span class="imp-chip imp-high">높음</span><?php endif; ?>
          </div>
          <div class="kc-type"><?= e(Util::truncate($l['work_type'] ?: '공사유형 미지정', 22)) ?></div>
          <div class="kc-amount"><?= e(moneyShort((float) $l['expected_amount'])) ?>원</div>
          <div class="kc-foot">
            <span class="kc-owner"><?= e($l['sales_user_name'] ?: '담당 미지정') ?></span>
            <?php if ($l['next_contact_date']): ?>
              <span class="kc-next <?= $nextCls ?>"><?= e(Util::date($l['next_contact_date'], 'n/j')) ?></span>
            <?php else: ?>
              <span class="kc-next warn">연락일 미정</span>
            <?php endif; ?>
          </div>
          <div class="kc-meta">
            <span class="m" title="예상 순이익률">순익 <?= e(pct($l['profit_rate'])) ?></span>
            <span class="m" title="현재 단계 체류일">체류 D+<?= (int) $l['stay_days'] ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
