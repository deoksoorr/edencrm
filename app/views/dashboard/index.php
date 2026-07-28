<?php
/**
 * 대시보드 — R16: 역할별 4변형(boss/sales/site/staff)을 폐지하고 **보유 권한 기반 위젯 조립** 단일 뷰로 통합.
 *
 * 모든 섹션은 isset() 가드다. 컨트롤러가 권한 없는 위젯의 키를 아예 만들지 않으므로
 * 여기서는 "키가 있으면 그린다"만 하면 된다 — 0 으로 표시되는 위젯은 존재하지 않는다.
 * super_admin 은 전 게이트를 통과해 기존 boss 대시보드와 동일한 섹션·순서를 받는다:
 *   ① 핵심 KPI ② 직원 업무 현황 ③ 영업·계약 | ④ 프로젝트·공정 ⑤ 재무·추이 ⑤-1 입금·출금 ⑥ 직원 성과 ⑦ 최근 활동
 * 전사 열람(report) 권한이 없는 사용자는 그 앞에 본인 범위 블록(오늘 할 일·내 일정·내 목표 등)을 받는다.
 *
 * @var array  $me,$can,$attn  @var string $title  @var bool $wl
 * @var array  $kpi,$finance,$cash            전사 재무(analytics.reports read)
 * @var array  $funnel,$saleskpi              영업(sales.leads read)
 * @var array  $board,$process,$pgroups       공정 보드(field.process_board read)
 * @var array  $sitekpi,$projects             프로젝트(field.projects read)
 * @var array  $workstatus                    현장 일정(field.schedules read)
 * @var array  $workload,$perf  @var ?array $attend   최고운영자 전용
 * @var array  $mykpi,$goal,$schedule         본인 범위(권한 무관)
 * @var array  $activity                      최근 활동(도메인별 읽기 권한이 있는 종류만)
 */
$wl   = $wl ?? false;
$attn = $attn ?? [];
$statusLabel = ['preparing' => '착공준비', 'in_progress' => '진행중', 'paused' => '중지', 'completed' => '완료', 'warranty' => '하자보수'];
$typeLabel   = ['work' => '작업', 'meeting' => '회의', 'inspection' => '검수'];

/** KPI 카드 partial. $help 는 금액 기준 툴팁(title 속성). */
$kpiCard = function (string $href, string $label, string $valueHtml, string $accent = '', ?array $delta = null, string $note = '', string $help = '') {
    $cls = 'kpi' . ($accent ? " accent-$accent" : '');
    echo '<a class="' . $cls . '" href="' . e($href) . '"' . ($help !== '' ? ' title="' . e($help) . '"' : '') . '>';
    echo '<div class="kpi-label">' . e($label) . '</div>';
    echo '<div class="kpi-row"><div class="kpi-value">' . $valueHtml . '</div>';
    if ($delta && $delta['pct'] !== null) {
        echo '<span class="delta ' . e($delta['dir']) . '">' . e(number_format(abs($delta['pct']), 1)) . '%</span>';
    }
    echo '</div>';
    if ($note !== '') { echo '<div class="kpi-note">' . $note . '</div>'; }
    echo '</a>';
};

// 상단 액션 — 열람 권한이 있는 화면으로만 링크한다(없는 권한의 버튼은 403 유발이라 노출하지 않는다).
$actions = [];
if (!empty($can['report']))   { $actions[] = ['리포트', url('reports.index'), 'btn-outline']; }
if (!empty($can['pipeline'])) { $actions[] = ['영업 파이프라인', url('pipeline.index'), 'btn-primary']; }
if (!$actions) {
    if (!empty($can['schedule'])) { $actions[] = ['일정', url('schedule.index'), 'btn-outline']; }
    if (!empty($can['process']))  { $actions[] = ['공정 보드', url('process.board'), 'btn-primary']; }
    if (!$actions && $wl && can('worklog.create')) { $actions[] = ['작업일지 등록', url('worklogs.form'), 'btn-primary']; }
}
// 업무 권한이 하나도 없는 계정 — 본인 범위만 남으므로 이유를 명시한다(빈 껍데기 방지).
$hasBiz = !empty($can['report']) || !empty($can['pipeline']) || !empty($can['project']) || !empty($can['process'])
       || !empty($can['schedule']) || !empty($can['contract']) || !empty($can['customer'])
       || !empty($can['quote']) || !empty($can['worklog']);
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title"><?= e($title) ?></div>
      <div class="page-sub"><?php if (!empty($can['report'])): ?><?= e($me['name']) ?>님, <?= date('n월 j일') ?> 회사 현황입니다.<?php else: ?><?= e($me['name']) ?>님, 오늘 업무 현황입니다.<?php endif; ?></div>
    </div>
    <?php if ($actions): ?>
    <div class="page-actions">
      <?php foreach ($actions as [$aLabel, $aHref, $aCls]): ?>
      <a class="btn <?= e($aCls) ?>" href="<?= e($aHref) ?>"><?= e($aLabel) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$hasBiz): ?>
  <div class="card pad">
    <div class="empty">
      <div class="empty-icon">▤</div>
      <div class="empty-title">표시할 업무 위젯이 없습니다.</div>
      <div class="section-desc">현재 계정에는 부여된 업무 권한이 없어 본인 일정·알림·목표만 표시됩니다.<br>고객·영업기회·견적·계약·프로젝트 등 업무 화면이 필요하면 최고운영자에게 권한 부여를 요청하세요.</div>
    </div>
  </div>
  <?php endif; ?>

  <?php /* ═══ 본인 범위 블록 — 전사 열람(report) 권한이 없는 사용자에게만. 권한 무관 항목은 항상 표시 ═══ */ ?>
  <?php if (isset($mykpi)): ?>
  <div class="section">
    <div class="section-head"><div class="st"><h2>오늘 할 일</h2></div></div>
    <div class="kpi-grid">
      <a class="kpi accent-brand" href="<?= e(url('schedule.index')) ?>"><div class="kpi-label">오늘 일정</div><div class="kpi-value"><?= number_format($mykpi['today']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi" href="<?= e(url('schedule.index')) ?>"><div class="kpi-label">이번 주 일정</div><div class="kpi-value"><?= number_format($mykpi['week']['value']) ?><span class="u">건</span></div></a>
      <?php if (isset($mykpi['projects'])): ?>
      <a class="kpi" href="<?= e(url('projects.index')) ?>"><div class="kpi-label">내 담당 프로젝트</div><div class="kpi-value"><?= number_format($mykpi['projects']['value']) ?><span class="u">건</span></div></a>
      <?php endif; ?>
      <?php if (isset($mykpi['worklog'])): ?>
      <a class="kpi <?= $mykpi['worklog']['value'] > 0 ? 'accent-warn' : '' ?>" href="<?= e(url('worklogs.index')) ?>"><div class="kpi-label">오늘 일지 미작성</div><div class="kpi-value"><?= number_format($mykpi['worklog']['value']) ?><span class="u">건</span></div></a>
      <?php endif; ?>
      <a class="kpi <?= $mykpi['unread']['value'] > 0 ? 'accent-warn' : '' ?>" href="<?= e(url('notifications.index')) ?>"><div class="kpi-label">안 읽은 알림</div><div class="kpi-value"><?= number_format($mykpi['unread']['value']) ?><span class="u">건</span></div></a>
    </div>
  </div>
  <?php endif; ?>

  <?php if (isset($saleskpi)): ?>
  <div class="section">
    <div class="section-head"><div class="st"><h2>내 영업 핵심</h2><span class="section-desc">내 담당 영업기회 기준</span></div></div>
    <div class="kpi-grid">
      <a class="kpi accent-brand" href="<?= e(url('pipeline.index')) ?>" title="내 미확정 리드의 예상금액 × 성공확률 합"><div class="kpi-label">예상 매출(가중)</div><div class="kpi-value"><?= moneyCell($saleskpi['pipeline']['value']) ?></div></a>
      <?php if (isset($saleskpi['revenue'])): ?>
      <a class="kpi accent-ok" href="<?= e(url('contracts.index')) ?>" title="이번 달 계약일 기준 담당 프로젝트 공급가액(VAT 제외) 합 · 취소/파기 제외"><div class="kpi-label">이번 달 수주액(공급가액)</div><div class="kpi-value"><?= moneyCell($saleskpi['revenue']['value']) ?></div></a>
      <a class="kpi" href="<?= e(url('pipeline.index')) ?>"><div class="kpi-label">계약 전환율</div><div class="kpi-value"><?= e(pct($saleskpi['conv']['value'])) ?></div></a>
      <?php endif; ?>
      <a class="kpi <?= $saleskpi['closing']['value'] > 0 ? 'accent-warn' : '' ?>" href="<?= e(url('pipeline.index', ['quick' => 'closing'])) ?>"><div class="kpi-label">계약 임박</div><div class="kpi-value"><?= number_format($saleskpi['closing']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi <?= $saleskpi['contact']['value'] > 0 ? 'accent-danger' : '' ?>" href="<?= e(url('pipeline.index', ['quick' => 'today'])) ?>"><div class="kpi-label">오늘 연락 필요</div><div class="kpi-value"><?= number_format($saleskpi['contact']['value']) ?><span class="u">건</span></div></a>
    </div>
  </div>
  <?php endif; ?>

  <?php if (isset($sitekpi)): ?>
  <div class="section">
    <div class="section-head"><div class="st"><h2>현장 핵심</h2><span class="section-desc">내 담당·배정 공사 기준</span></div></div>
    <div class="kpi-grid">
      <a class="kpi accent-brand" href="<?= e(url('projects.index', ['status' => 'in_progress'])) ?>"><div class="kpi-label">진행 중 공사</div><div class="kpi-value"><?= number_format($sitekpi['active']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi" href="<?= e(url('projects.index', ['status' => 'preparing'])) ?>"><div class="kpi-label">착공 예정</div><div class="kpi-value"><?= number_format($sitekpi['preparing']['value']) ?><span class="u">건</span></div></a>
      <a class="kpi <?= $sitekpi['delayed']['value'] > 0 ? 'accent-danger' : '' ?>" href="<?= e(url('projects.index', ['status' => 'delayed'])) ?>"><div class="kpi-label">지연 공사</div><div class="kpi-value"><?= number_format($sitekpi['delayed']['value']) ?><span class="u">건</span></div><?php if ($sitekpi['delayed']['value'] > 0): ?><div class="kpi-note">확인 필요</div><?php endif; ?></a>
      <a class="kpi <?= $sitekpi['unassigned']['value'] > 0 ? 'accent-warn' : '' ?>" href="<?= e(url('projects.index', ['assign' => 'none'])) ?>"><div class="kpi-label">직원 미배정</div><div class="kpi-value"><?= number_format($sitekpi['unassigned']['value']) ?><span class="u">건</span></div></a>
    </div>
  </div>
  <?php endif; ?>

  <?php if (isset($schedule) || isset($projects) || isset($goal) || isset($pgroups)): ?>
  <div class="split">
    <div class="col">
      <?php if (isset($schedule)): ?>
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>이번 주 일정</h2><span class="section-desc">내가 참여하는 일정</span></div>
          <a class="section-link" href="<?= e(url('schedule.index')) ?>">일정 →</a></div>
        <?php if (!$schedule): ?>
          <div class="empty"><div class="empty-title">예정된 일정이 없습니다.</div></div>
        <?php else: ?>
          <div class="attn-list">
            <?php foreach ($schedule as $s): ?>
              <div class="attn-item">
                <span class="attn-label"><?= e($s['title']) ?><?= $s['project_name'] ? ' · ' . e(Util::truncate($s['project_name'], 14)) : '' ?></span>
                <span class="attn-cnt nowrap"><?= e(Util::date($s['start_datetime'], 'n/j')) ?> <span class="badge badge-muted"><?= e($typeLabel[$s['type']] ?? $s['type']) ?></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if (isset($projects)): ?>
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>내 담당 프로젝트</h2></div>
          <a class="section-link" href="<?= e(url('projects.index')) ?>">전체 →</a></div>
        <?php if (!$projects): ?>
          <div class="empty"><div class="empty-title">담당 프로젝트가 없습니다.</div></div>
        <?php else: ?>
          <div class="attn-list">
            <?php foreach ($projects as $p):
              $overdue = $p['status'] !== 'completed' && !$p['actual_end_date'] && $p['end_date'] && $p['end_date'] < date('Y-m-d'); ?>
              <a class="attn-item <?= $overdue ? 'danger' : '' ?>" href="<?= e(url('projects.show', ['id' => $p['id']])) ?>">
                <span class="attn-label"><?= e($p['name']) ?></span>
                <span class="attn-cnt nowrap"><span class="badge <?= $overdue ? 'badge-danger' : 'badge-info' ?>"><?= e($p['stage_name'] ?: ($statusLabel[$p['status']] ?? $p['status'])) ?></span></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="col">
      <?php if (isset($goal)): ?>
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>내 이번 달 목표</h2></div></div>
        <?php View::partial('partials/goal', ['g' => $goal, 'title' => '매출 달성률']); ?>
      </div>
      <?php endif; ?>
      <?php if (isset($pgroups)): ?>
      <div class="card pad">
        <div class="section-head"><div class="st"><h2>작업할 공정</h2></div>
          <a class="section-link" href="<?= e(url('process.board')) ?>">공정 보드 →</a></div>
        <div class="chip-grid">
          <?php foreach ($pgroups as $pg): ?>
            <a class="chip-stat <?= $pg['n'] === 0 ? 'zero' : '' ?>" href="<?= e(url('process.board')) ?>">
              <span class="dot" style="background:<?= e($pg['color']) ?>"></span><span class="cn"><?= number_format($pg['n']) ?></span><span><?= e($pg['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php /* ① 핵심 KPI: 이번 달 금액 4종(크게) + 현재 건수 3종(작게) — analytics.reports read */ ?>
  <?php if (isset($kpi)): ?>
  <div class="section">
    <div class="section-head"><div class="st"><h2>핵심 현황</h2><span class="section-desc">금액은 이번 달(<?= date('n') ?>월) · 건수는 현재 기준 · 각 카드를 누르면 근거 화면으로 이동합니다</span></div></div>
    <div class="dash-hero">
      <?php
      $kpiCard(url('reports.index'), '이번 달 확정 매출(공급가액)', moneyCell($kpi['revenue']['value']), 'brand', $kpi['revenue']['delta'], '전월 대비 · 입금일 기준 · VAT 제외',
        'R12: 확정 매출 = 실제 입금된 금액의 공급가액(부가세 제외) — 입금 시점 인식(입금일 기준). 부가세(예수금)는 매출이 아니므로 제외. 미입금·취소 금액 제외, 환불 차감. 현금 축(부가세 포함)은 아래 입금 총액입니다');
      $kpiCard(url('contracts.index'), '이번 달 입금 총액(VAT 포함)', moneyCell($kpi['paid']['value']), 'ok', $kpi['paid']['delta'], '전월 대비 · 입금일 기준 · 환불 차감',
        '이번 달 실제 입금된 현금 합(환불 차감) · 입금일 기준 · VAT 포함 — 확정 매출(공급가액)은 여기서 부가세를 제외한 값입니다');
      $kpiCard(url('reports.index'), '이번 달 원가 총액', moneyCell($kpi['cost']['value']), 'warn', $kpi['cost']['delta'], '전월 대비 · 발생일 기준',
        '확정된 실제 비용(costs) 합 · 지출 발생일 기준 · 입력 금액 그대로(부가세 구분 없음)');
      $kpiCard(url('reports.index'), '이번 달 실제 순이익', moneyCell($kpi['profit']['value']), $kpi['profit']['value'] < 0 ? 'danger' : 'ok', null,
        $kpi['profit']['value'] < 0 ? '적자 주의 · 입금 − 지출' : '입금 − 지출 기준',
        '이번 달 확정 매출(공급가액·VAT 제외) − 이번 달 확정 지출(발생일 기준) — 프로젝트 완료 여부와 무관');
      ?>
    </div>
    <div class="dash-sub">
      <?php
      $kpiCard(url('contracts.index', ['status' => 'active']), '계약 진행', number_format($kpi['contracts']['value']) . '<span class="u">건</span>', '', null, '현재 · 진행 상태 계약');
      $kpiCard(url('projects.index', ['status' => 'in_progress']), '진행 중 프로젝트', number_format($kpi['active']['value']) . '<span class="u">건</span>', '', null, '현재');
      $kpiCard(url('projects.index', ['status' => 'delayed']), '지연 프로젝트', number_format($kpi['delayed']['value']) . '<span class="u">건</span>', $kpi['delayed']['value'] > 0 ? 'danger' : '', null, $kpi['delayed']['value'] > 0 ? '현재 · 기한 경과 — 확인 필요' : '현재 · 정상');
      ?>
    </div>
  </div>
  <?php endif; ?>

  <?php
  /* ② 오늘 직원 일정·업무 현황 (직원 미배정·작업일지 흡수) — 구성 요소별 권한이 다르므로 하나라도 있으면 섹션을 연다 */
  $hasUnassigned = isset($attn['unassigned']);
  $hasWlChip     = $wl && isset($attn['worklog']);
  $hasWorkSec    = isset($workstatus) || isset($workload) || !empty($attend) || $hasUnassigned || $hasWlChip;
  ?>
  <?php if ($hasWorkSec): ?>
  <div class="section">
    <div class="section-head"><div class="st"><h2>직원 업무 현황</h2><span class="section-desc">오늘(<?= date('n월 j일') ?>) 일정 · 현재 업무 부하</span></div>
      <?php if (!empty($can['schedule'])): ?><a class="section-link" href="<?= e(url('schedule.index')) ?>">전체 일정 →</a><?php endif; ?></div>
    <div class="card pad">
      <?php if ($hasUnassigned || $hasWlChip): ?>
      <div class="chip-grid mb-14">
        <?php if ($hasUnassigned): $ua = $attn['unassigned']; ?>
        <a class="chip-stat <?= $ua['n'] > 0 ? 'warn' : 'zero' ?>" href="<?= e(url($ua['route'], $ua['params'])) ?>" title="현재 진행·예정 공사 중 배정 직원이 없는 건수">
          <span class="cn"><?= number_format($ua['n']) ?></span><span>직원 미배정 공사 · 현재</span>
        </a>
        <?php endif; ?>
        <?php if ($hasWlChip): $wlA = $attn['worklog']; ?>
        <a class="chip-stat <?= $wlA['n'] > 0 ? 'warn' : 'zero' ?>" href="<?= e(url($wlA['route'], $wlA['params'])) ?>" title="오늘 작업일지가 없는 진행 중 공사 건수">
          <span class="cn"><?= number_format($wlA['n']) ?></span><span>오늘 작업일지 미작성</span>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if (isset($workstatus)): ?>
      <div class="section-title">오늘 일정 <span class="basis-note">오늘 작업 예정인 직원만 표시</span></div>
      <?php if (!$workstatus['today']): ?>
        <div class="empty compact"><div class="empty-title">오늘 예정된 직원 작업이 없습니다.</div></div>
      <?php else:
        $wsLabel = ['active' => ['진행중', 'badge-info'], 'planned' => ['예정', 'badge-muted'], 'done' => ['완료', 'badge-ok']]; ?>
      <div class="ws-grid">
        <?php foreach ($workstatus['today'] as $w): [$stLabel, $stCls] = $wsLabel[$w['status']] ?? ['예정', 'badge-muted']; ?>
          <a class="ws-card <?= e($w['status']) ?>" href="<?= e(url('schedule.index', ['user_id' => $w['id']])) ?>">
            <div class="ws-head">
              <span class="user-color-dot" style="background:<?= e($w['color']) ?>"></span>
              <span class="ws-name"><?= e($w['name']) ?></span>
              <span class="badge badge-muted"><?= e($w['role']) ?></span>
              <span class="ws-status badge <?= $stCls ?>"><?= $stLabel ?></span>
            </div>
            <div class="ws-sched"><?= e($w['sched']) ?><?= $w['more'] > 0 ? ' 외 ' . (int) $w['more'] . '건' : '' ?></div>
            <?php if ($w['project']): ?>
              <div class="ws-cur"><?= e(Util::truncate($w['project'], 24)) ?><?php if ($w['stage']): ?> · <span class="stage"><?= e($w['stage']) ?></span><?php endif; ?></div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <?php if (isset($workload)): ?>
      <div class="section-title mt-16">직원별 업무 부하 <span class="basis-note">현재 배정·담당 중인 진행/예정 공사 · 오늘 일정 건수</span></div>
      <?php if (!$workload): ?>
        <div class="empty compact"><div class="empty-title">표시할 직원이 없습니다.</div></div>
      <?php else: ?>
      <div class="load-list">
        <?php foreach ($workload as $l): ?>
          <a class="load-row" href="<?= e(url('performance.user', ['id' => $l['id']])) ?>">
            <span class="user-color-dot" style="background:<?= e($l['color']) ?>"></span>
            <span class="load-name"><?= e($l['name']) ?></span>
            <span class="badge badge-muted"><?= e($l['role']) ?></span>
            <span class="load-meta"><b><?= number_format($l['projects']) ?></b>건 공사 · 오늘 <b><?= number_format($l['today']) ?></b>건</span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <?php if (!empty($attend)): // R4 복구 — 출근 현황은 feature_attendance 게이트(작업일지 메뉴와 무관)
        // R6 최종 구조: 통계 3종만(출근 일수·지각·무단결근 — 관리자 수동 마킹). 휴가·출근율·증감 표기 제거.
      ?>
      <div class="section-title mt-16">이번 달 출근 현황
        <span class="basis-note"><?= date('n') ?>월 · 출근=작업 기록·업무 일정(작업·회의·현장방문) 고유 날짜(무단결근 마킹일 제외) · 지각·무단결근=관리자 마킹</span>
        <a class="section-link" href="<?= e(url('reports.attendance')) ?>">출근 분석 보기 →</a></div>
      <?php if (!$attend['rows']): ?>
        <div class="empty compact"><div class="empty-title">표시할 직원이 없습니다.</div></div>
      <?php else: ?>
      <div class="att-list">
        <?php $attMonthDays = (int) date('t'); // 게이지 분모 = 이번 달 전체 일수(트랙 100% 고정) ?>
        <?php foreach ($attend['rows'] as $a): ?>
          <?php $attPct = $attMonthDays > 0 ? min(100, $a['days'] / $attMonthDays * 100) : 0; ?>
          <div class="att-row">
            <span class="att-name"><span class="user-color-dot" style="background:<?= e($a['color']) ?>"></span><?= e($a['name']) ?> <span class="badge badge-muted"><?= e($a['role']) ?></span></span>
            <span class="att-bar" title="<?= date('n') ?>월 총 <?= $attMonthDays ?>일 중 <?= (int) $a['days'] ?>일 출근 (<?= round($attPct) ?>%)">
              <?php if ($a['days'] > 0): ?><span class="att-fill" style="width:<?= number_format($attPct, 1, '.', '') ?>%;background:<?= e($a['color']) ?>"></span><?php endif; ?>
            </span>
            <span class="att-meta">
              <?php if ($a['late'] > 0): ?><span class="att-flag late" title="이번 달 지각 마킹 횟수(출근 일수에 포함)">지각 <?= (int) $a['late'] ?></span><?php endif; ?>
              <?php if ($a['absent'] > 0): ?><span class="att-flag absent" title="이번 달 무단결근 마킹 횟수(출근 일수에서 제외)">무단결근 <?= (int) $a['absent'] ?></span><?php endif; ?>
              <span class="att-days">총 <?= $attMonthDays ?>일 중 <b><?= (int) $a['days'] ?></b>일 출근</span>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php
  /* ③ 영업·계약 현황 | ④ 프로젝트·공정 현황 — 둘 다 있을 때만 2단(dash-duo), 하나면 전체 폭 */
  $hasSalesCard   = isset($funnel);
  $hasProcessCard = isset($board) && isset($process);
  $duo = $hasSalesCard && $hasProcessCard;
  ?>
  <?php if ($hasSalesCard || $hasProcessCard): ?>
  <div class="<?= $duo ? 'dash-duo' : 'section' ?>">
    <?php if ($hasSalesCard): ?>
    <div class="card pad">
      <div class="section-head"><div class="st"><h2>영업·계약 현황</h2><span class="section-desc">현재 파이프라인 · 이번 달 계약</span></div>
        <a class="section-link" href="<?= e(url('pipeline.index')) ?>">파이프라인 →</a></div>
      <div class="kv-row mb-14">
        <?php if (isset($finance)): ?>
        <a class="kv" href="<?= e(url('pipeline.index')) ?>" title="미확정 리드의 예상금액(공급가액) × 성공확률 합 · 현재 · VAT 제외"><span class="kv-label">예상 매출(가중·공급가액)</span><span class="kv-value mono"><?= e(moneyShort($finance['pipeline'])) ?></span></a>
        <a class="kv" href="<?= e(url('contracts.index')) ?>" title="이번 달 계약일 기준 공급가액 합 · 취소/파기 제외 · VAT 제외"><span class="kv-label">이번 달 수주액(공급가액)</span><span class="kv-value mono"><?= e(moneyShort($finance['contracted'])) ?></span></a>
        <?php endif; ?>
        <a class="kv" href="<?= e(url('pipeline.index')) ?>" title="누적 리드 중 계약 완료 비율"><span class="kv-label">영업 전환율(누적)</span><span class="kv-value mono"><?= e(pct($funnel['conversion'])) ?></span></a>
        <a class="kv" href="<?= e(url('pipeline.index')) ?>" title="계약 완료 리드의 등록→계약 평균 소요일 · 누적"><span class="kv-label">평균 계약 소요일</span><span class="kv-value mono"><?= $funnel['avg_days'] !== null ? number_format($funnel['avg_days']) . '일' : '-' ?></span></a>
      </div>
      <div class="funnel">
        <?php foreach ($funnel['steps'] as $s): ?>
          <div class="funnel-step <?= !empty($s['won']) ? 'is-won' : '' ?>">
            <div class="fn"><?= number_format($s['n']) ?></div>
            <div class="fl"><?= e($s['label']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (isset($attn['contract_stale']) && Settings::enabled('feature_dashboard_sales_alerts')): // r5-uifix: 주의 칩 4종(계약 대기·대기 지연·연락 경과·미접촉) — 기본 숨김, 설정 feature_dashboard_sales_alerts ON 시 노출
      $pendingN = 0;
      foreach ($funnel['steps'] as $s) { if ($s['label'] === '계약대기') { $pendingN = (int) $s['n']; } }
      $absorb = [
          ['n' => $pendingN, 'label' => '계약 대기 · 현재', 'href' => url('pipeline.index', ['tab' => 'contract']), 'sev' => $pendingN > 0 ? 'warn' : ''],
      ];
      foreach (['contract_stale', 'contact_overdue', 'contact_none'] as $k) {
          $a = $attn[$k];
          $absorb[] = ['n' => $a['n'], 'label' => $a['label'], 'href' => url($a['route'], $a['params']), 'sev' => $a['sev']];
      }
      ?>
      <div class="chip-grid mt-14">
        <?php foreach ($absorb as $c): ?>
          <a class="chip-stat <?= ($c['n'] > 0 && $c['sev'] !== '') ? e($c['sev']) : ($c['n'] === 0 ? 'zero' : '') ?>" href="<?= e($c['href']) ?>">
            <span class="cn"><?= number_format($c['n']) ?></span><span><?= e($c['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if (isset($saleskpi)): // 영업단계 6그룹 도넛 — dashboard.data 의 stage_groups 와 동일 게이트 ?>
      <div class="chart-box sm mt-14" data-chart="stage"><canvas id="chartStage"></canvas></div>
      <div class="chart-legend" id="stageLegend"></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($hasProcessCard): ?>
    <div class="card pad">
      <div class="section-head"><div class="st"><h2>프로젝트·공정 현황</h2><span class="section-desc">현재 · 취소/파기 제외</span></div>
        <a class="section-link" href="<?= e(url('process.board')) ?>">공정 보드 →</a></div>
      <div class="section-title">공정 보드 <span class="basis-note">진행 중 공사 · 공정 단계(process_stage) 기준</span></div>
      <div class="chip-grid mb-14">
        <a class="chip-stat <?= $board['waiting'] > 0 ? 'warn' : 'zero' ?>" href="<?= e(url('process.board')) ?>" title="공정 보드 '대기중' 컬럼의 공사 수 · 진행 예정 포함 — 보드 요약과 동일 기준">
          <span class="cn"><?= number_format($board['waiting']) ?></span><span>대기중</span>
        </a>
        <a class="chip-stat <?= $board['doing'] === 0 ? 'zero' : '' ?>" href="<?= e(url('process.board')) ?>" title="진행 중 공사 중 대기중 외 공정 단계에 있는 공사 수">
          <span class="cn"><?= number_format($board['doing']) ?></span><span>공정 진행</span>
        </a>
      </div>
      <div class="section-title">프로젝트 상태 <span class="basis-note">현재 · 지연은 기한 경과·미준공</span></div>
      <div class="chip-grid">
        <?php foreach ($process as $c): ?>
          <a class="chip-stat <?= ($c['n'] > 0 && !empty($c['sev'])) ? e($c['sev']) : ($c['n'] === 0 ? 'zero' : '') ?>" href="<?= e(url($c['route'], $c['params'])) ?>">
            <span class="cn"><?= number_format($c['n']) ?></span><span><?= e($c['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* ⑤ 재무 현황·추이 (미수금 흡수, 전체 폭) — analytics.reports read */ ?>
  <?php if (isset($finance)): $g = $finance['goal']; // 목표 진행바는 partials/goal 이 렌더 ?>
  <div class="section">
    <div class="section-head"><div class="st"><h2>재무 현황·추이</h2><span class="section-desc">이번 달 · 확정 매출·순이익 = 공급가액(VAT 제외) · 입금 총액 = 현금(VAT 포함)</span></div>
      <a class="section-link" href="<?= e(url('reports.index')) ?>">상세 리포트 →</a></div>
    <div class="card pad">
      <div class="fin-flex">
        <div>
          <div class="kv-row mb-14">
            <a class="kv" href="<?= e(url('contracts.index')) ?>" title="이번 달 계약일 기준 공급가액 합 · 취소/파기 제외 · VAT 제외"><span class="kv-label">이번 달 수주액(공급가액)</span><span class="kv-value mono"><?= e(moneyShort($finance['contracted'])) ?></span></a>
            <a class="kv" href="<?= e(url('projects.index')) ?>" title="진행·착공 전 공사의 공급가액 합 · 현재 · VAT 제외"><span class="kv-label">예상 매출(공급가액)</span><span class="kv-value mono"><?= e(moneyShort($finance['expected_rev'])) ?></span></a>
            <a class="kv" href="<?= e(url('reports.index')) ?>" title="이번 달 확정 지출(costs) 합 · 지출 발생일 기준 — 상단 '이번 달 원가 총액'과 동일 산식(R11 통일)"><span class="kv-label">확정 지출(발생일)</span><span class="kv-value mono"><?= e(moneyShort($finance['actual_cost'])) ?></span></a>
            <a class="kv" href="<?= e(url('reports.index')) ?>" title="(확정 매출(공급가액) − 확정 지출) ÷ 확정 매출 × 100 · 이번 달"><span class="kv-label">확정 순이익률</span><span class="kv-value mono"><?= e(pct($finance['profit_rate'])) ?></span></a>
            <a class="kv" href="<?= e(url('contracts.index')) ?>" title="Σ 계약별 max(0, 계약 총액 − 순입금) + Σ 예외 프로젝트 max(0, 예정 금액 − 직접 입금) · 현재(VAT 포함)"><span class="kv-label">미수금(VAT 포함)</span><span class="kv-value mono <?= $finance['receivable'] > 0 ? 'text-warn' : '' ?>" title="<?= e(number_format($finance['receivable']) . '원') ?>"><?= e(moneyShort($finance['receivable'])) ?><?php if ($finance['receivable_count'] > 0): ?> <span class="u"><?= number_format($finance['receivable_count']) ?>건</span><?php endif; ?></span></a>
          </div>
          <div class="section-desc mb-14">확정 매출은 <b>실제 입금된 금액의 공급가액(부가세 제외)</b>입니다 — 입금 시점 인식(예외 프로젝트 직접 입금 포함). 입금 총액은 부가세 포함 현금이라 확정 매출보다 부가세만큼 큽니다. 미입금·대기 제외, 환불·취소 즉시 차감.</div>
          <?php View::partial('partials/goal', ['g' => $g, 'title' => '이번 달 확정 매출(공급가액) 목표 달성률']); ?>
        </div>
        <div>
          <div class="chart-box" data-chart="trend"><canvas id="chartTrend"></canvas></div>
          <div class="section-desc tc mt-8">최근 6개월 확정 매출(공급가액·막대)·확정 순이익(선, 매출 − 지출) · VAT 제외</div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php /* ⑤-1 최근 입금·출금 (R4 T6) — 현금 흐름 실측 리스트. 입금=paid payment(환불·취소 제외), 출금=확정 원가(costs) */ ?>
  <?php if (isset($cash)): ?>
  <div class="dash-duo">
    <div class="card pad">
      <div class="section-head"><div class="st"><h2>최근 입금</h2><span class="section-desc">입금 완료(paid) · 입금일 순 · 환불·취소 제외 · VAT 포함 — 환불 발생 계약은 배지 표기</span></div>
        <a class="section-link" href="<?= e(url('contracts.index')) ?>">전체 보기 →</a></div>
      <?php if (!$cash['in']): ?>
        <div class="empty compact"><div class="empty-title">입금 내역이 없습니다.</div></div>
      <?php else: ?>
      <div class="table-wrap border-0">
        <table class="data">
          <thead>
            <tr><th>입금일</th><th>고객</th><th>계약/프로젝트</th><th>구분</th><th class="num">금액</th><th>담당</th><th>상태</th></tr>
          </thead>
          <tbody>
            <?php foreach ($cash['in'] as $r): ?>
              <tr class="row-link" onclick="location.href='<?= e(url('contracts.show', ['id' => $r['contract_id']])) ?>'">
                <td class="mono"><?= fmtdate($r['paid_date']) ?></td>
                <td class="ellipsis"><?= e($r['customer_name']) ?></td>
                <td class="ellipsis" title="<?= e($r['contract_no']) ?>"><?= e($r['project_name'] !== null && $r['project_name'] !== '' ? Util::truncate($r['project_name'], 18) : $r['contract_no']) ?></td>
                <td><?= e($r['pay_type_label']) ?></td>
                <td class="num mono"><?= money((float) $r['amount']) ?></td>
                <td><?= e($r['sales_user_name'] ?? '-') ?></td>
                <td><span class="badge badge-ok">입금완료</span><?php if ((int) $r['contract_refund'] > 0): ?>
                  <span class="badge badge-danger" title="이 계약에서 환불 <?= number_format($r['contract_refund']) ?>원 발생 — 순입금·미수금에 차감 반영. 환불은 원가(costs)가 아니라 '최근 출금(원가 지출)' 리스트에는 없습니다">환불 −<?= e(moneyShort((float) $r['contract_refund'])) ?></span>
                <?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="card pad">
      <div class="section-head"><div class="st"><h2>최근 출금(원가 지출)</h2><span class="section-desc">확정 원가(costs) · 지출일 순 · 취소 제외 · 입력 금액 그대로 — 파기 환불은 입금 리스트 배지 참조</span></div>
        <a class="section-link" href="<?= e(url('projects.index')) ?>" title="원가(지출)는 각 프로젝트 상세의 지출 탭에서 관리합니다">전체 보기 →</a></div>
      <?php if (!$cash['out']): ?>
        <div class="empty compact"><div class="empty-title">출금(원가 지출) 내역이 없습니다.</div></div>
      <?php else: ?>
      <div class="table-wrap border-0">
        <table class="data">
          <thead>
            <tr><th>출금일</th><th>분류</th><th>프로젝트</th><th>공급처</th><th class="num">금액</th><th>등록자</th><th>증빙</th></tr>
          </thead>
          <tbody>
            <?php foreach ($cash['out'] as $r): ?>
              <tr class="row-link" onclick="location.href='<?= e(url('projects.show', ['id' => $r['project_id']])) ?>#costs'">
                <td class="mono" title="지출 발생일(spent_date) 기준<?= $r['spent_date'] === null ? ' — 미입력' : '' ?>"><?= fmtdate($r['spent_date']) ?></td>
                <td><?= e(CostService::categoryLabel($r['category'])) ?></td>
                <td class="ellipsis" title="<?= e($r['item_name'] ?? '') ?>"><?= e(Util::truncate($r['project_name'], 18)) ?></td>
                <td class="ellipsis"><?= e($r['vendor'] !== null && $r['vendor'] !== '' ? $r['vendor'] : '-') ?></td>
                <td class="num mono"><?= money((float) $r['amount']) ?></td>
                <td><?= e($r['created_by_name'] ?? '-') ?></td>
                <td><?= $r['receipt_file_id'] ? '<span class="badge badge-ok">있음</span>' : '<span class="badge badge-muted">없음</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php /* ⑥ 직원 성과 (전체 폭) — 최고운영자 전용 */ ?>
  <?php if (isset($perf)): ?>
  <div class="card pad">
    <div class="section-head"><div class="st"><h2>직원 성과</h2><span class="section-desc" title="기여율(배정) 기반 — 기여율 없으면 성과 미반영">기여율 기반 · 확정 항목은 완료 프로젝트만·공급가 기준</span></div>
      <a class="section-link" href="<?= e(url('performance.index')) ?>">전체 성과 →</a></div>
    <?php if (!$perf): ?>
      <div class="empty"><div class="empty-title">표시할 직원 성과가 없습니다.</div></div>
    <?php else: ?>
    <div class="table-wrap border-0">
      <table class="data">
        <thead>
          <tr>
            <th>직원</th>
            <th class="num" title="기여율>0 배정 프로젝트 수 · 취소/파기 제외">참여</th>
            <th class="num" title="완료(정산 포함) 프로젝트 수 · 기여율>0 배정 기준">완료</th>
            <th class="num" title="완료 프로젝트 공급가액 × 기여도 합 · VAT 제외">기여매출(확정)</th>
            <th class="num" title="완료 프로젝트 실제원가 × 기여도 합">기여원가(확정)</th>
            <th class="num" title="기여순이익 = 완료 프로젝트만·공급가 기준(공급가-실제원가)×기여도">기여순이익(확정)</th>
            <th class="num" title="계약 순입금(VAT 포함, 환불 차감) × 기여도 합">입금 기여</th>
            <th class="num" title="이번 달 준공(완료) 프로젝트의 기여순이익">이번 달 실적</th>
            <th class="num" title="귀속 확정순이익 ÷ 귀속 확정매출 × 100">순이익률(가중)</th>
            <th class="num" title="직원 순이익 기여 ÷ 회사 전체 확정순이익 × 100">회사기여율</th>
            <th class="num">일정준수</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($perf as $p): ?>
            <tr>
              <td><a href="<?= e(url('performance.user', ['id' => $p['user_id']])) ?>"><?= e($p['name']) ?></a> <span class="badge badge-muted"><?= e($p['role']) ?></span></td>
              <td class="num mono"><?= number_format($p['assigned']) ?></td>
              <td class="num mono"><?= number_format($p['done']) ?></td>
              <td class="num mono"><?= moneyCell($p['attr_rev']) ?></td>
              <td class="num mono"><?= moneyCell($p['attr_cost']) ?></td>
              <td class="num mono <?= $p['contrib'] < 0 ? 'text-danger' : '' ?>"><?= moneyCell($p['contrib']) ?></td>
              <td class="num mono"><?= moneyCell($p['paid_contrib']) ?></td>
              <td class="num mono"><?= moneyCell($p['month_contrib']) ?></td>
              <td class="num mono"><?= e(pct($p['margin'])) ?></td>
              <td class="num mono"><?= e(pct($p['company_rate'])) ?></td>
              <td class="num mono"><?= e(pct($p['ontime'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* ⑦ 최근 활동 — 읽기 권한이 있는 종류(계약·프로젝트·공정·영업)만 조회된다 */ ?>
  <?php if (isset($activity)): ?>
  <div class="card pad">
    <div class="section-head"><div class="st"><h2>최근 활동</h2><span class="section-desc">영업·계약·프로젝트·공정 변화 · 최근 <?= count($activity) ?>건</span></div>
      <?php if (can('audit.view')): ?><a class="section-link" href="<?= e(url('audit.index')) ?>">감사 로그 →</a><?php endif; ?></div>
    <?php if (!$activity): ?>
      <div class="empty"><div class="empty-title">표시할 활동이 없습니다.</div></div>
    <?php else: ?>
    <div class="act-list">
      <?php foreach ($activity as $a):
        $kindMeta = [
            'lead'     => ['영업',     'badge-info',  url('pipeline.show',  ['id' => $a['ref_id']])],
            'contract' => ['계약',     'badge-ok',    url('contracts.show', ['id' => $a['ref_id']])],
            'project'  => ['프로젝트', 'badge-muted', url('projects.show',  ['id' => $a['ref_id']])],
            'process'  => ['공정',     'badge-warn',  url('projects.show',  ['id' => $a['ref_id']])],
        ];
        [$kLabel, $kBadge, $href] = $kindMeta[$a['kind']] ?? ['활동', 'badge-muted', '#'];
        if ($a['kind'] === 'lead') {
            $text = '신규 문의 · ' . $a['title'] . ($a['t'] ? ' (' . $a['t'] . ')' : '');
        } elseif ($a['kind'] === 'contract') {
            // from_status 는 등록 이력에서 NULL — null 배열 오프셋(PHP 8.4 Deprecated) 방지
            $fl = $a['f'] !== null ? (StatusService::CONTRACT_LABELS[$a['f']] ?? $a['f']) : null;
            $tl = StatusService::CONTRACT_LABELS[$a['t']] ?? $a['t'];
            $text = $a['title'] . ' · ' . ($fl !== null ? $fl . ' → ' : '') . $tl;
        } elseif ($a['kind'] === 'project') {
            $fl = $a['f'] !== null ? (StatusService::PROJECT_LABELS[$a['f']] ?? $a['f']) : null;
            $tl = StatusService::PROJECT_LABELS[$a['t']] ?? $a['t'];
            $text = Util::truncate($a['title'], 22) . ' · ' . ($fl !== null ? $fl . ' → ' : '') . $tl;
        } else { // process
            $text = Util::truncate($a['title'], 22) . ' · 공정 ' . ($a['f'] !== null ? $a['f'] . ' → ' : '') . $a['t'];
        }
      ?>
        <a class="act-item" href="<?= e($href) ?>">
          <span class="act-time"><?= e(Util::date($a['at'], 'n/j H:i')) ?></span>
          <span class="badge <?= $kBadge ?>"><?= e($kLabel) ?></span>
          <span class="act-text"><?= e($text) ?></span>
          <?php if ($a['actor']): ?><span class="act-actor"><?= e($a['actor']) ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
