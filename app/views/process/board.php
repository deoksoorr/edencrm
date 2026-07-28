<?php
/**
 * 공정 보드 — 상태 그룹(대기중/진행 중/하자보수/종결) 섹션 + 카드내 공정 게이지(슬라이더)로 재구성(R14).
 * 원천은 projects.process_stage_id 단일. 섹션 = 프로젝트 상태(ProcessController::statusGroup),
 * 카드 안의 게이지 행 = 실공정(ProcessService::gaugeStages) 단계별 진행률(project_stage_progress).
 * 드래그 이동(구 process.move)은 R14 에서 폐지 — 게이지 저장(process.progress.set)이 보드 위치를
 * 파생 이동시키고, 완료 확정(process.complete.confirm)·하자보수 전환(process.warranty.set)이
 * 상태 축을 갱신한다. 필터 탭(#pcTabs)도 기존 공정그룹 축 대신 이 상태 그룹 축으로 재정의했다(R14).
 * @var array $positions,$photos,$nextSchedules,$groups,$s2g,$gaugeStages,$pctByProject,$memoCounts,$statusGroups
 * @var array $summary,$statusLabels,$statusBadge
 * @var bool $canMove @var bool $canManage @var string $boardType
 */
$today = date('Y-m-d');
$totalCount = (int) $summary['total'];
$typeLabels = Stages::constructionTypes(); // R8-A: 도장/인테리어 최상위 탭
$boardTypeLabel = $typeLabels[$boardType] ?? '도장';

// R14: 상태 그룹 섹션 정의(라벨·색) — 카드 게이지 보드의 단일 출처. 필터 탭(#pcTabs)도 이 4개 키를 그대로 쓴다
// (구 공정그룹 축(대기중/착공 준비/시공/마무리·하자)은 보드 컬럼 자체가 폐지되어 더 이상 의미가 없다).
$sgDefs = [
    'waiting'  => ['name' => '대기중',   'color' => '#f59e0b'],
    'active'   => ['name' => '진행 중',  'color' => '#3b82f6'],
    'warranty' => ['name' => '하자보수', 'color' => '#ef4444'],
    'done'     => ['name' => '종결',     'color' => '#0d9488'],
];
$tabs = ['all' => ['label' => '전체', 'groups' => array_keys($sgDefs)]];
foreach ($sgDefs as $gkey => $g) {
    $tabs[$gkey] = ['label' => $g['name'], 'groups' => [$gkey]];
}
?>
<div class="page page-wide" data-board data-board-type="<?= e($boardType) ?>" data-can-move="<?= $canMove ? '1' : '0' ?>" data-can-manage="<?= !empty($canManage) ? '1' : '0' ?>">
  <div class="page-head">
    <div>
      <div class="page-title">공정 보드</div>
      <div class="page-sub"><?= e($boardTypeLabel) ?> 보드 <?= number_format($totalCount) ?>건(유형 미지정 포함) · 진행 예정·진행 중·일시 중단·하자보수 + 완료·정산 표시 · 취소·파기 제외<?= $canMove ? '' : ' · 게이지 조정 권한이 없어 조회만 가능합니다.' ?></div>
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
    <div class="kpi accent-wait" title="'대기중' 공정의 프로젝트 수(진행 예정 포함)">
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
    <div class="kpi accent-ok" title="완료·정산 프로젝트 — 종결 섹션에 노출되며, 게이지를 다시 내리면 '진행 중'으로 자동 재개됩니다">
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

  <?php
    // R14: 카드내 게이지의 공정그룹(착공준비/시공/마무리) 묶음 — 모든 카드가 동일 구조를 공유(값만 카드별로 다름)
    $gaugeByGroup = [];
    foreach ($gaugeStages as $i => $st) {
        $gk = $s2g[$st['stage_key']] ?? 'build';
        $st['pos'] = $i + 1;
        $gaugeByGroup[$gk][] = $st;
    }
  ?>
  <div class="sg-board" id="processBoard">
    <?php foreach ($sgDefs as $gkey => $g): $list = $statusGroups[$gkey] ?? []; ?>
    <section class="sg-group" data-group="<?= e($gkey) ?>" style="--gc:<?= e($g['color']) ?>">
      <div class="sg-head"><span class="sg-dot"></span><b><?= e($g['name']) ?></b>
        <span class="badge badge-muted sg-count" data-group-count="<?= e($gkey) ?>"><?= count($list) ?></span></div>
      <div class="sg-cards" data-group-cards="<?= e($gkey) ?>">
        <?php foreach ($list as $p): $pid = (int) $p['id'];
          $pmap = $pctByProject[$pid] ?? [];
          $isDone = in_array($p['status'], ['completed', 'settled'], true);
          $mc = $memoCounts[$pid] ?? 0;
          $ns = $nextSchedules[$pid] ?? null;
        ?>
        <div class="gauge-card" data-project-id="<?= $pid ?>" data-status="<?= e($p['status']) ?>">
          <?php if (isset($photos[$pid])): ?>
            <div class="kanban-card-photo"><img src="<?= e(url('files.download', ['id' => $photos[$pid]])) ?>" alt="" loading="lazy"></div>
          <?php endif; ?>
          <div class="gc-top">
            <div class="pc-title"><a href="<?= e(url('projects.show', ['id' => $pid])) ?>"><?= e(Util::truncate($p['name'], 24)) ?></a></div>
            <?php if (($p['construction_type'] ?? null) === null): /* R8-A: 미지정 배지 — project.manage 보유 시 클릭해 유형 지정. R14: 유형에 따라 게이지 단계셋이 갈리므로 게이지 보드에서도 필수 */ ?>
              <?php if (!empty($canManage)): ?>
                <button type="button" class="pc-ct-badge" data-settype="<?= $pid ?>" data-name="<?= e($p['name']) ?>"
                        title="공사 유형 미지정 — 양쪽 보드에 표시됩니다. 클릭해 도장/인테리어 지정">유형 미지정</button>
              <?php else: ?>
                <span class="pc-ct-badge" title="공사 유형 미지정 — 양쪽 보드에 표시됩니다">유형 미지정</span>
              <?php endif; ?>
            <?php endif; ?>
            <span class="badge <?= e($statusBadge[$p['status']] ?? 'badge') ?>" data-status-badge><?= e($statusLabels[$p['status']] ?? $p['status']) ?></span>
          </div>
          <div class="pc-sub">
            <span><?= e($p['customer_name'] ?: '-') ?><?php if ((int) ($p['is_exception'] ?? 0) === 1): ?> <span class="badge badge-warn fs-11" title="예외 프로젝트(계약 미연결 수동 생성)">예외</span><?php endif; ?></span>
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
          <div class="gc-progress">
            <div class="progress"><div class="progress-bar<?= (int) $p['progress'] >= 100 ? ' ok' : '' ?>" data-progress-bar style="width:<?= (int) $p['progress'] ?>%"></div></div>
          </div>
          <?php
          // R14-3: 동시 진행 공정 전체 표시(0<pct<100) — 게이지 바 아래 배치(사장 지시)
          $workingChips = [];
          $doneCnt = 0;
          foreach ($gaugeStages as $wst) {
              $wv = $pmap[(int) $wst['id']] ?? 0;
              if ($wv >= 100) { $doneCnt++; }
              if ($wv > 0 && $wv < 100) { $workingChips[] = ['name' => $wst['name'], 'pct' => $wv]; }
          }
          ?>
          <div class="gc-working">
            <span class="gc-pct" data-progress-text><?= (int) $p['progress'] ?>%</span>
            <span class="gc-work-chips" data-work-chips>
              <?php if ($workingChips): foreach ($workingChips as $wc): ?>
                <span class="badge badge-stage"><?= e($wc['name']) ?> <?= (int) $wc['pct'] ?>%</span>
              <?php endforeach; else: ?>
                <span class="badge badge-muted"><?= count($gaugeStages) && $doneCnt === count($gaugeStages) ? '전 공정 완료' : '작업 전' ?></span>
              <?php endif; ?>
            </span>
          </div>
          <div class="gc-gauges">
            <?php foreach ($gaugeByGroup as $ggk => $sts): $gdef = $groups[$ggk] ?? null; ?>
            <details class="gc-ggroup" <?= $ggk === 'build' ? 'open' : '' ?>>
              <summary><?= e($gdef['label'] ?? $ggk) ?> <span class="muted fs-12 gc-gsum" data-ggroup-sum="<?= e($ggk) ?>"></span></summary>
              <?php foreach ($sts as $st): $v = $pmap[(int) $st['id']] ?? 0; ?>
              <div class="gc-row" data-stage-row="<?= (int) $st['id'] ?>">
                <span class="gc-name"><?= (int) $st['pos'] ?>. <?= e($st['name']) ?></span>
                <input type="range" class="gc-slider" min="0" max="100" step="5" value="<?= $v ?>"
                       data-stage-id="<?= (int) $st['id'] ?>" <?= $canMove ? '' : 'disabled' ?>>
                <input type="number" class="gc-num" data-stage-val min="0" max="100" step="5" value="<?= $v ?>"
                       inputmode="numeric" data-stage-id="<?= (int) $st['id'] ?>" <?= $canMove ? '' : 'disabled' ?>><span class="gc-unit">%</span>
              </div>
              <?php endforeach; ?>
            </details>
            <?php endforeach; ?>
          </div>
          <div class="gc-actions">
            <button type="button" class="btn btn-sm btn-outline gc-memo-btn">메모<?= $mc ? ' <span class="badge badge-muted">' . $mc . '</span>' : '' ?></button>
            <?php if ($canMove && $p['status'] === 'warranty'): ?>
              <button type="button" class="btn btn-sm btn-outline gc-warranty-btn" data-action="clear">하자보수 종료</button>
            <?php elseif ($canMove && $isDone): ?>
              <button type="button" class="btn btn-sm btn-ghost gc-warranty-btn" data-action="set">하자보수 전환</button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-ghost history-btn" data-project-id="<?= $pid ?>">이력</button>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$list): ?><div class="empty-mini">프로젝트 없음</div><?php endif; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
</div>
