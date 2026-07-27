<?php
/**
 * 사장·회계 대시보드 (R3 재구성). '주의가 필요한 항목' 레일 제거 — 각 섹션이 항목을 흡수한다:
 *   지연→프로젝트·공정, 미수금→재무, 계약 대기·연락 경과→영업·계약, 직원 미배정·일지→직원 업무.
 * 구조: ① 핵심 KPI(이번 달 금액 4종 + 현재 건수 3종) ② 오늘 직원 일정·업무(부하)
 *      ③ 영업·계약 현황 ④ 프로젝트·공정 현황(보드 기준) ⑤ 재무 현황·추이 ⑥ 직원 성과 ⑦ 최근 활동
 * 모든 수치는 기준 기간·VAT 기준을 표기하고 근거 화면으로 링크한다.
 * @var array $me,$kpi,$attn,$funnel,$finance,$process,$board,$workstatus,$workload,$perf,$activity @var bool $wl
 * @var ?array $attend 이번 달 출근 요약(rows[days,late,absent]) — null=feature_attendance OFF
 *      (R4, feature_worklog 와 무관 · R6: 통계 3종만 — 출근 일수·지각·무단결근, 휴가·출근율·증감 제거)
 * @var array $cash 최근 입금·출금 리스트 ['in'=>paid 입금 행, 'out'=>확정 원가 행] (R4 T6)
 */
$wl = $wl ?? false;
$attend = $attend ?? null;
$cash = $cash ?? ['in' => [], 'out' => []];

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
$g = $finance['goal']; // 목표 진행바는 partials/goal 이 렌더
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">대시보드</div>
      <div class="page-sub"><?= e($me['name']) ?>님, <?= date('n월 j일') ?> 회사 현황입니다.</div>
    </div>
    <div class="page-actions">
      <a class="btn btn-outline" href="<?= e(url('reports.index')) ?>">리포트</a>
      <a class="btn btn-primary" href="<?= e(url('pipeline.index')) ?>">영업 파이프라인</a>
    </div>
  </div>

  <!-- ① 핵심 KPI: 이번 달 금액 4종(크게) + 현재 건수 3종(작게) -->
  <div class="section">
    <div class="section-head"><div class="st"><h2>핵심 현황</h2><span class="section-desc">금액은 이번 달(<?= date('n') ?>월) · 건수는 현재 기준 · 각 카드를 누르면 근거 화면으로 이동합니다</span></div></div>
    <div class="dash-hero">
      <?php
      $kpiCard(url('reports.index'), '이번 달 확정 매출(입금 기준)', moneyCell($kpi['revenue']['value']), 'brand', $kpi['revenue']['delta'], '전월 대비 · 입금일 기준 · 환불 차감',
        'R11 통일 산식: 실제 입금된 금액(순입금 = 입금 − 환불, 예외 프로젝트 직접 입금 포함)만 확정 매출로 인식 · 입금일 기준 · VAT 포함 — 미입금·대기·취소 금액은 포함되지 않습니다');
      $kpiCard(url('contracts.index'), '이번 달 입금 총액(VAT 포함)', moneyCell($kpi['paid']['value']), 'ok', $kpi['paid']['delta'], '전월 대비 · 입금일 기준 · 환불 차감',
        '이번 달 입금 완료된 금액 합(환불 차감) · 입금일 기준 · 현금 기준 · VAT 포함 — 확정 매출(입금 기준)과 동일 산식');
      $kpiCard(url('reports.index'), '이번 달 원가 총액', moneyCell($kpi['cost']['value']), 'warn', $kpi['cost']['delta'], '전월 대비 · 발생일 기준',
        '확정된 실제 비용(costs) 합 · 지출 발생일 기준 · 입력 금액 그대로(부가세 구분 없음)');
      $kpiCard(url('reports.index'), '이번 달 실제 순이익', moneyCell($kpi['profit']['value']), $kpi['profit']['value'] < 0 ? 'danger' : 'ok', null,
        $kpi['profit']['value'] < 0 ? '적자 주의 · 입금 − 지출' : '입금 − 지출 기준',
        '이번 달 확정 매출(입금 기준) − 이번 달 확정 지출(발생일 기준) — 프로젝트 완료 여부와 무관(R11)');
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

  <!-- ② 오늘 직원 일정·업무 현황 (직원 미배정·작업일지 흡수) -->
  <div class="section">
    <div class="section-head"><div class="st"><h2>직원 업무 현황</h2><span class="section-desc">오늘(<?= date('n월 j일') ?>) 일정 · 현재 업무 부하</span></div>
      <a class="section-link" href="<?= e(url('schedule.index')) ?>">전체 일정 →</a></div>
    <div class="card pad">
      <?php $ua = $attn['unassigned']; ?>
      <div class="chip-grid mb-14">
        <a class="chip-stat <?= $ua['n'] > 0 ? 'warn' : 'zero' ?>" href="<?= e(url($ua['route'], $ua['params'])) ?>" title="현재 진행·예정 공사 중 배정 직원이 없는 건수">
          <span class="cn"><?= number_format($ua['n']) ?></span><span>직원 미배정 공사 · 현재</span>
        </a>
        <?php if ($wl && isset($attn['worklog'])): $wlA = $attn['worklog']; ?>
        <a class="chip-stat <?= $wlA['n'] > 0 ? 'warn' : 'zero' ?>" href="<?= e(url($wlA['route'], $wlA['params'])) ?>" title="오늘 작업일지가 없는 진행 중 공사 건수">
          <span class="cn"><?= number_format($wlA['n']) ?></span><span>오늘 작업일지 미작성</span>
        </a>
        <?php endif; ?>
      </div>

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

      <?php if ($attend !== null): // R4 복구 — 출근 현황은 feature_attendance 게이트(작업일지 메뉴와 무관)
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

  <!-- ③ 영업·계약 현황 | ④ 프로젝트·공정 현황 (2단, 주의 항목 흡수) -->
  <div class="dash-duo">
    <div class="card pad">
      <div class="section-head"><div class="st"><h2>영업·계약 현황</h2><span class="section-desc">현재 파이프라인 · 이번 달 계약</span></div>
        <a class="section-link" href="<?= e(url('pipeline.index')) ?>">파이프라인 →</a></div>
      <div class="kv-row mb-14">
        <a class="kv" href="<?= e(url('pipeline.index')) ?>" title="미확정 리드의 예상금액(공급가액) × 성공확률 합 · 현재 · VAT 제외"><span class="kv-label">예상 매출(가중·공급가액)</span><span class="kv-value mono"><?= e(moneyShort($finance['pipeline'])) ?></span></a>
        <a class="kv" href="<?= e(url('contracts.index')) ?>" title="이번 달 계약일 기준 공급가액 합 · 취소/파기 제외 · VAT 제외"><span class="kv-label">이번 달 수주액(공급가액)</span><span class="kv-value mono"><?= e(moneyShort($finance['contracted'])) ?></span></a>
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
      <?php if (Settings::enabled('feature_dashboard_sales_alerts')): // r5-uifix: 주의 칩 4종(계약 대기·대기 지연·연락 경과·미접촉) — 기본 숨김, 설정 feature_dashboard_sales_alerts ON 시 노출
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
    </div>

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
  </div>

  <!-- ⑤ 재무 현황·추이 (미수금 흡수, 전체 폭) -->
  <div class="section">
    <div class="section-head"><div class="st"><h2>재무 현황·추이</h2><span class="section-desc">이번 달 · 손익·현금 모두 입금 기준(VAT 포함) — R11 통일</span></div>
      <a class="section-link" href="<?= e(url('reports.index')) ?>">상세 리포트 →</a></div>
    <div class="card pad">
      <div class="fin-flex">
        <div>
          <div class="kv-row mb-14">
            <a class="kv" href="<?= e(url('contracts.index')) ?>" title="이번 달 계약일 기준 공급가액 합 · 취소/파기 제외 · VAT 제외"><span class="kv-label">이번 달 수주액(공급가액)</span><span class="kv-value mono"><?= e(moneyShort($finance['contracted'])) ?></span></a>
            <a class="kv" href="<?= e(url('projects.index')) ?>" title="진행·착공 전 공사의 공급가액 합 · 현재 · VAT 제외"><span class="kv-label">예상 매출(공급가액)</span><span class="kv-value mono"><?= e(moneyShort($finance['expected_rev'])) ?></span></a>
            <a class="kv" href="<?= e(url('reports.index')) ?>" title="이번 달 확정 지출(costs) 합 · 지출 발생일 기준 — 상단 '이번 달 원가 총액'과 동일 산식(R11 통일)"><span class="kv-label">확정 지출(발생일)</span><span class="kv-value mono"><?= e(moneyShort($finance['actual_cost'])) ?></span></a>
            <a class="kv" href="<?= e(url('reports.index')) ?>" title="(확정 매출(입금 기준) − 확정 지출) ÷ 확정 매출 × 100 · 이번 달"><span class="kv-label">확정 순이익률</span><span class="kv-value mono"><?= e(pct($finance['profit_rate'])) ?></span></a>
            <a class="kv" href="<?= e(url('contracts.index')) ?>" title="Σ 계약별 max(0, 계약 총액 − 순입금) + Σ 예외 프로젝트 max(0, 예정 금액 − 직접 입금) · 현재(VAT 포함)"><span class="kv-label">미수금(VAT 포함)</span><span class="kv-value mono <?= $finance['receivable'] > 0 ? 'text-warn' : '' ?>" title="<?= e(number_format($finance['receivable']) . '원') ?>"><?= e(moneyShort($finance['receivable'])) ?><?php if ($finance['receivable_count'] > 0): ?> <span class="u"><?= number_format($finance['receivable_count']) ?>건</span><?php endif; ?></span></a>
          </div>
          <div class="section-desc mb-14">확정 매출은 <b>실제 입금된 금액(순입금 = 입금 − 환불, VAT 포함)</b>만 반영합니다(R11 통일 산식 — 예외 프로젝트 직접 입금 포함). 미입금·대기 금액은 제외되고, 환불·입금 취소는 즉시 차감됩니다.</div>
          <?php View::partial('partials/goal', ['g' => $g, 'title' => '이번 달 확정 매출(입금 기준) 목표 달성률']); ?>
        </div>
        <div>
          <div class="chart-box" data-chart="trend"><canvas id="chartTrend"></canvas></div>
          <div class="section-desc tc mt-8">최근 6개월 확정 매출(입금 기준·막대)·확정 순이익(선, 매출 − 지출) · VAT 포함</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ⑤-1 최근 입금·출금 (R4 T6) — 현금 흐름 실측 리스트. 입금=paid payment(환불·취소 제외), 출금=확정 원가(costs) -->
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

  <!-- ⑥ 직원 성과 (전체 폭) -->
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

  <!-- ⑦ 최근 활동 (영업·계약·프로젝트·공정 변화) -->
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
</div>
