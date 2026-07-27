<?php
/**
 * 공정 보드 — '대기중' 그룹 첫 고정 + 그룹 섹션(줄바꿈 배치)으로 무한 가로 스크롤 제거.
 * 원천은 projects.process_stage_id 단일. 컬럼=공정 단계, 카드 뱃지=프로젝트 상태(두 축 구분).
 * @var array $stages,$byStage,$photos,$nextSchedules,$groups,$groupCols,$summary,$tabs,$statusLabels,$statusBadge
 * @var int $waitingId @var bool $canMove @var bool $canManage @var string $boardType
 */
$today = date('Y-m-d');
$totalCount = (int) $summary['total'];
$typeLabels = Stages::constructionTypes(); // R8-A: 도장/인테리어 최상위 탭
$boardTypeLabel = $typeLabels[$boardType] ?? '도장';
?>
<div class="page page-wide" data-board data-board-type="<?= e($boardType) ?>" data-can-move="<?= $canMove ? '1' : '0' ?>" data-can-manage="<?= !empty($canManage) ? '1' : '0' ?>">
  <div class="page-head">
    <div>
      <div class="page-title">공정 보드</div>
      <div class="page-sub"><?= e($boardTypeLabel) ?> 보드 <?= number_format($totalCount) ?>건(유형 미지정 포함) · 진행 예정·진행 중·일시 중단·하자보수 + 완료·정산 표시(이동 시 자동 재개) · 취소·파기 제외<?= $canMove ? '' : ' · 이동 권한이 없어 조회만 가능합니다.' ?></div>
    </div>
    <?php if (can('settings.manage')): ?>
      <div class="page-actions">
        <a href="<?= e(url('settings.stages', ['type' => $boardType])) ?>#process" class="btn btn-outline">공정 관리</a>
      </div>
    <?php endif; ?>
  </div>

  <?php /* R8-A 최상위 유형 탭 — 서버 렌더 링크(새로고침·북마크 시 상태 유지) */ ?>
  <div class="pb-type-tabs">
    <?php foreach ($typeLabels as $tk => $tl): ?>
      <a class="pb-type-tab<?= $boardType === $tk ? ' active' : '' ?>" href="<?= e(url('process.board', ['type' => $tk])) ?>"><?= e($tl) ?></a>
    <?php endforeach; ?>
    <span class="pb-type-hint muted">유형 미지정 프로젝트는 양쪽 탭에 표시됩니다</span>
  </div>

  <div class="kpi-grid pb-summary">
    <div class="kpi accent-brand" title="projects.status = 'in_progress' 기준(대시보드 '진행 중'과 동일 정의)">
      <div class="kpi-label">전체 진행 프로젝트</div>
      <div class="kpi-value"><span data-summary="active"><?= number_format($summary["active"]) ?></span><span class="u">건</span></div>
    </div>
    <div class="kpi accent-wait" title="'대기중' 공정 컬럼의 프로젝트 수(진행 예정 포함)">
      <div class="kpi-label">대기중</div>
      <div class="kpi-value"><span data-summary="waiting"><?= number_format($summary["waiting"]) ?></span><span class="u">건</span></div>
    </div>
    <div class="kpi" title="카드가 1건 이상 있는 실공정(1~<?= (int) $positions['total'] ?>단계) 수 — 공정 마스터 기준">
      <div class="kpi-label">진행 공정 수</div>
      <div class="kpi-value"><span data-summary="stages"><?= number_format($summary["stages"]) ?></span><span class="u">개</span></div>
    </div>
    <div class="kpi accent-danger" title="준공예정일 경과 + 준공 미처리 — 대시보드·프로젝트 목록 '지연'과 동일 정의">
      <div class="kpi-label">지연 프로젝트</div>
      <div class="kpi-value"><span data-summary="delayed"><?= number_format($summary["delayed"]) ?></span><span class="u">건</span></div>
    </div>
    <div class="kpi accent-ok" title="완료·정산 프로젝트 — 종결(전체완료) 컬럼에 노출되며, 다른 공정으로 이동하면 '진행 중'으로 자동 재개됩니다">
      <div class="kpi-label">완료·정산</div>
      <div class="kpi-value"><span data-summary="done"><?= number_format($summary["done"]) ?></span><span class="u">건</span></div>
    </div>
  </div>

  <div class="pl-toolbar">
    <div class="pl-tabs" id="pcTabs">
      <?php foreach ($tabs as $key => $tab): ?>
        <button type="button" class="pl-tab<?= $key === 'all' ? ' active' : '' ?>" data-tab="<?= e($key) ?>"
                data-groups="<?= e(implode(',', $tab['groups'])) ?>">
          <?= e($tab['label']) ?><span class="tcnt" data-tab-count="<?= e($key) ?>">0</span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$totalCount): ?>
    <div class="empty"><div class="empty-title">표시할 프로젝트가 없습니다.</div></div>
  <?php endif; ?>

  <div class="pb-groups" id="processBoard">
    <?php foreach ($groups as $gkey => $g): $cols = $groupCols[$gkey] ?? []; if (!$cols) { continue; } ?>
      <?php
        $groupCount = 0;
        foreach ($cols as $stage) { $groupCount += count($byStage[(int) $stage['id']] ?? []); }
        // R11: 그룹 범위 = 위치 번호(유형별 1..N, 공정 마스터 기준 동적) — sort_order 원값 표시 금지.
        //      그룹 내 위치가 비연속(사이에 다른 그룹 공정 존재)이면 범위 대신 개수로 표기.
        $gPos = [];
        foreach ($cols as $stage) {
            $gPos[] = (int) ($positions['pos'][(int) $stage['id']] ?? 0);
        }
        sort($gPos);
        $contiguous = !$gPos || ($gPos[count($gPos) - 1] - $gPos[0] === count($gPos) - 1);
        if ($gkey === 'waiting') {
            $rangeLabel = '착공 전 대기';
        } elseif (!$contiguous) {
            $rangeLabel = count($gPos) . '개 공정';
        } elseif (count($gPos) > 1) {
            $rangeLabel = $gPos[0] . '~' . $gPos[count($gPos) - 1] . '단계';
        } else {
            $rangeLabel = ($gPos[0] ?? 0) . '단계';
        }
      ?>
      <section class="pb-group" data-group="<?= e($gkey) ?>" style="--gc:<?= e($g['color']) ?>">
        <div class="pb-group-head">
          <span class="pb-group-name"><?= e($g['label']) ?></span>
          <span class="pb-group-range"><?= e($rangeLabel) ?></span>
          <span class="kanban-count" data-group-count><?= $groupCount ?></span>
        </div>
        <div class="pb-group-cols">
          <?php foreach ($cols as $stage): $list = $byStage[(int) $stage['id']] ?? []; ?>
          <div class="kanban-col" data-group="<?= e($stage['group']) ?>" style="--gc:<?= e($stage['group_color']) ?>">
            <div class="kanban-col-head">
              <div class="kanban-col-title">
                <span class="kanban-caret" title="접기/펼치기">
                  <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
                <span><?php $stagePos = (int) ($positions['pos'][(int) $stage['id']] ?? 0); ?><?= $stagePos > 0 ? $stagePos . '. ' : '' ?><?= e($stage['name']) ?></span>
                <span class="kanban-count"><?= count($list) ?></span>
              </div>
            </div>
            <div class="kanban-list" data-stage-id="<?= (int) $stage['id'] ?>">
              <?php if (!$list): ?><div class="kanban-empty">프로젝트 없음</div><?php endif; ?>
              <?php foreach ($list as $p):
                $pid = (int) $p['id'];
                $daysLeft = !empty($p['end_date']) ? (int) floor((strtotime($p['end_date']) - strtotime($today)) / 86400) : null;
                // 지연 = 준공예정 경과 + 준공 미처리(대시보드 delayedCond 와 동일 기준)
                $isDone = in_array($p['status'], ['completed', 'settled'], true); // R11: 완료·정산 카드도 이동 가능(자동 재개)
                $isDelayed = !$isDone && $daysLeft !== null && $daysLeft < 0 && empty($p['actual_end_date']);
                $isWarn = !$isDone && !$isDelayed && $daysLeft !== null && $daysLeft <= 7;
                $statusCls = $isDone ? 'st-won pb-done' : ($isDelayed ? 'st-delayed' : ($isWarn ? 'st-warn' : 'st-normal'));
                $ns = $nextSchedules[$pid] ?? null;
                $isWaitingCol = (int) $stage['id'] === (int) $waitingId;
              ?>
              <div class="kanban-card <?= $statusCls ?>" data-project-id="<?= $pid ?>" data-status="<?= e($p['status']) ?>"
                   data-href="<?= e(url('projects.show', ['id' => $pid])) ?>" title="클릭하면 프로젝트 상세로 이동">
                <?php if (isset($photos[$pid])): ?>
                  <div class="kanban-card-photo"><img src="<?= e(url('files.download', ['id' => $photos[$pid]])) ?>" alt="" loading="lazy"></div>
                <?php endif; ?>
                <div class="pc-top">
                  <div class="pc-title"><a href="<?= e(url('projects.show', ['id' => $pid])) ?>"><?= e(Util::truncate($p['name'], 24)) ?></a></div>
                  <?php if (empty($p['construction_type'])): /* R8-A: 미지정 배지 — project.manage 보유 시 클릭해 유형 지정 */ ?>
                    <?php if (!empty($canManage)): ?>
                      <button type="button" class="pc-ct-badge" data-settype="<?= $pid ?>" data-name="<?= e($p['name']) ?>"
                              title="공사 유형 미지정 — 양쪽 보드에 표시됩니다. 클릭해 도장/인테리어 지정">유형 미지정</button>
                    <?php else: ?>
                      <span class="pc-ct-badge" title="공사 유형 미지정 — 양쪽 보드에 표시됩니다">유형 미지정</span>
                    <?php endif; ?>
                  <?php endif; ?>
                  <span class="badge <?= e($statusBadge[$p['status']] ?? 'badge-muted') ?>" title="프로젝트 상태(공정 상태와 별개)"><?= e($statusLabels[$p['status']] ?? $p['status']) ?></span>
                </div>
                <div class="pc-sub">
                  <span><?= e($p['customer_name'] ?: '-') ?><?php if (!empty($p['is_exception'])): ?> <span class="badge badge-warn fs-11" title="예외 프로젝트(계약 미연결 수동 생성)">예외</span><?php endif; ?></span>
                  <span class="ellipsis" title="<?= e($p['site_address'] ?: '') ?>"><?= e($p['site_address'] ? Util::truncate($p['site_address'], 14) : '주소 미등록') ?></span>
                </div>
                <div class="pc-meta">
                  <span class="m" title="담당 영업">영업 <?= e($p['sales_name'] ?: '담당 미지정') ?></span>
                  <span class="m ellipsis" title="배정 직원<?= $p['assignee_names'] ? ': ' . e($p['assignee_names']) : '' ?>">배정 <?= $p['assignee_names'] ? e(Util::truncate($p['assignee_names'], 16)) . ' (' . (int) $p['assign_count'] . '명)' : '직원 미배정' ?></span>
                </div>
                <div class="pc-meta">
                  <span class="m" title="예상 착공일">착공 <?= e($p['start_date'] ?: '착공일 미정') ?></span>
                  <span class="m ellipsis" title="다음 일정<?= $ns ? ': ' . e($ns['title']) : '' ?>">일정 <?= $ns ? e($ns['event_date']) . ' ' . e(Stages::slotLabel($ns['slot'])) : '일정 미등록' ?></span>
                </div>
                <div class="progress"><div class="progress-bar <?= ((int) $p['progress']) >= 100 ? 'ok' : ($isDelayed ? 'danger' : '') ?>" style="width:<?= (int) $p['progress'] ?>%"></div></div>
                <div class="pc-foot">
                  <span title="생성일 · <?= $isWaitingCol ? '대기중' : '현재 공정' ?> 진입일">등록 <?= e(date('m.d', strtotime($p['created_at']))) ?> · 진입 <span data-entered-at><?= $p['process_entered_at'] ? e(date('m.d', strtotime($p['process_entered_at']))) : '-' ?></span></span>
                  <span class="<?= $isDelayed ? 'text-danger' : ($isWarn ? 'text-warn' : '') ?>">
                    <?php if ($daysLeft !== null): ?><?= $isDelayed ? abs($daysLeft) . '일 지연' : 'D-' . $daysLeft ?><?php else: ?><?= (int) $p['progress'] ?>%<?php endif; ?>
                  </span>
                </div>
                <div class="pc-actions">
                  <span class="muted pc-pct" data-progress-text><?= (int) $p['progress'] ?>%</span>
                  <button type="button" class="btn btn-ghost btn-sm history-btn" data-project-id="<?= $pid ?>">이력</button>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</div>
