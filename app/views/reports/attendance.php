<?php
/**
 * 직원 출근 분석 (R4 T4 · R6 최종 구조) — 리포트 하위 탭. 서버 렌더 + 차트·마킹만 JS.
 * 통계 3종만(AttendanceService — 대시보드 출근 요약과 동일 산식):
 *  출근 일수 = 작업 기록(work_logs) 고유 날짜 − 무단결근 마킹과 겹치는 날 제외
 *  지각 = late 마크 수(관리자 수동 등록 — 출근 일수에 포함) / 무단결근 = absent 마크 수.
 * 관리자(perm attendance.manage)는 마킹 캘린더에서 날짜 클릭 → 지각/무단결근 등록·변경·해제.
 * 권한 없으면 마킹 카드 자체 미노출(조회 전용) — API 는 라우터가 403.
 * @var array $f 필터(year,month,user_id,dept,status) @var array $d 데이터 @var array $depts,$allUsers
 * @var bool $canMark @var int $markUser @var ?array $markCal
 */
$mLabel = $f['year'] . '년 ' . $f['month'] . '월';
$dowKo = [1 => '월', 2 => '화', 3 => '수', 4 => '목', 5 => '금', 6 => '토', 7 => '일'];
$yearNow = (int) date('Y');
$markLabels = ['late' => '지각', 'absent' => '무단결근'];
$markShort = ['late' => '지', 'absent' => '결'];
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">리포트</div>
      <div class="page-sub"><?= e($mLabel) ?> 직원 출근 · 출근=작업 기록·업무 일정(작업·회의·현장방문) 고유 날짜(무단결근 마킹일 제외) · 지각·무단결근=관리자 수동 마킹</div>
    </div>
  </div>

  <div class="tabs">
    <a class="tab" href="<?= e(url('reports.index')) ?>">경영 리포트</a>
    <a class="tab active" href="<?= e(url('reports.attendance')) ?>">직원 출근</a>
  </div>

  <form class="toolbar" method="get" action="<?= e(url('reports.attendance')) ?>">
    <input type="hidden" name="r" value="reports.attendance">
    <?php if ($canMark && $markUser): ?><input type="hidden" name="mark_user" value="<?= (int) $markUser ?>"><?php endif; ?>
    <select name="year" class="select">
      <?php for ($y = $yearNow - 3; $y <= $yearNow; $y++): ?>
        <option value="<?= $y ?>"<?= $y === $f['year'] ? ' selected' : '' ?>><?= $y ?>년</option>
      <?php endfor; ?>
    </select>
    <select name="month" class="select">
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>"<?= $m === $f['month'] ? ' selected' : '' ?>><?= $m ?>월</option>
      <?php endfor; ?>
    </select>
    <select name="user_id" class="select">
      <option value="0">전체 직원</option>
      <?php foreach ($allUsers as $u): ?>
        <option value="<?= (int) $u['id'] ?>"<?= (int) $u['id'] === $f['user_id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="dept" class="select">
      <option value="0">전체 부서</option>
      <?php foreach ($depts as $dp): ?>
        <option value="<?= (int) $dp['id'] ?>"<?= (int) $dp['id'] === $f['dept'] ? ' selected' : '' ?>><?= e($dp['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="select">
      <option value="active"<?= $f['status'] === 'active' ? ' selected' : '' ?>>재직</option>
      <option value="inactive"<?= $f['status'] === 'inactive' ? ' selected' : '' ?>>비활성</option>
      <option value="all"<?= $f['status'] === 'all' ? ' selected' : '' ?>>전체</option>
    </select>
    <button type="submit" class="btn btn-primary">조회</button>
    <div class="toolbar-spacer"></div>
    <?php if (can('report.export')): ?>
      <a class="btn btn-outline" href="<?= e(url('reports.attendance_export', ['year' => $f['year'], 'month' => $f['month'], 'user_id' => $f['user_id'], 'dept' => $f['dept'], 'status' => $f['status']])) ?>">CSV 다운로드</a>
    <?php endif; ?>
  </form>

  <!-- 요약 KPI — 통계 3종(출근·지각·무단결근) + 출근 일수 파생(평균·최다·최소) -->
  <div class="kpi-grid mb-14">
    <div class="kpi">
      <div class="kpi-label">대상 인원</div>
      <div class="kpi-value"><?= number_format($d['kpi']['headcount']) ?><span class="u">명</span></div>
    </div>
    <div class="kpi accent-brand" title="선택 인원의 <?= e($mLabel) ?> 출근 일수 합(직원별 고유 날짜, 무단결근 마킹일 제외) · 전월 <?= number_format($d['kpi']['prev_total']) ?>일">
      <div class="kpi-label">총 출근</div>
      <div class="kpi-row"><div class="kpi-value"><?= number_format($d['kpi']['total']) ?><span class="u">일</span></div>
        <?php if ($d['kpi']['delta'] !== 0): ?><span class="delta <?= $d['kpi']['delta'] > 0 ? 'up' : 'down' ?>"><?= abs($d['kpi']['delta']) ?>일</span><?php endif; ?></div>
      <div class="kpi-note">전월 대비 <?= $d['kpi']['delta'] > 0 ? '+' : '' ?><?= number_format($d['kpi']['delta']) ?>일</div>
    </div>
    <div class="kpi" title="총 출근 ÷ 대상 인원">
      <div class="kpi-label">평균 출근</div>
      <div class="kpi-value"><?= $d['kpi']['avg'] !== null ? number_format($d['kpi']['avg'], 1) : '-' ?><span class="u">일</span></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">최다 출근</div>
      <div class="kpi-value"><?= $d['kpi']['max'] !== null ? number_format($d['kpi']['max']) : '-' ?><span class="u">일</span></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">최소 출근</div>
      <div class="kpi-value"><?= $d['kpi']['min'] !== null ? number_format($d['kpi']['min']) : '-' ?><span class="u">일</span></div>
    </div>
    <div class="kpi" title="관리자가 수동 등록한 지각 마킹 수 — 지각한 날도 출근 일수에는 포함">
      <div class="kpi-label">지각</div>
      <div class="kpi-value"><?= number_format($d['kpi']['late_total']) ?><span class="u">회</span></div>
    </div>
    <div class="kpi" title="관리자가 수동 등록한 무단결근 마킹 수 — 해당 날은 출근 일수에서 제외">
      <div class="kpi-label">무단결근</div>
      <div class="kpi-value"><?= number_format($d['kpi']['absent_total']) ?><span class="u">회</span></div>
    </div>
  </div>

  <?php if ($canMark && $markUser && $markCal !== null): ?>
  <!-- 관리자 마킹 캘린더 — 날짜 클릭 → 지각/무단결근 등록·변경·해제(attendance.manage) -->
  <?php
    $markUserName = '';
    foreach ($allUsers as $u) { if ((int) $u['id'] === $markUser) { $markUserName = $u['name']; break; } }
    // 월간 캘린더 주 단위(월요일 시작) 조립 — 첫 주 앞·마지막 주 뒤는 빈 칸
    $weeks = [];
    $week = array_fill(0, 7, null);
    foreach ($d['dates'] as $dt) {
        $idx = $dt['dow'] - 1;
        $week[$idx] = $dt;
        if ($idx === 6) { $weeks[] = $week; $week = array_fill(0, 7, null); }
    }
    if (array_filter($week, fn($c) => $c !== null)) { $weeks[] = $week; }
  ?>
  <div class="card mb-14">
    <div class="card-head">
      <div class="card-title">근태 마킹 <span class="badge badge-muted">관리자</span></div>
      <div class="muted fs-12">날짜 클릭 → 지각/무단결근 등록 · 상태 변경 · 해제(확인 후 삭제) — 같은 날 1상태만, 모든 변경은 감사 로그에 기록</div>
    </div>
    <div class="attmark-toolbar">
      <form method="get" action="<?= e(url('reports.attendance')) ?>" class="toolbar attmark-userform">
        <input type="hidden" name="r" value="reports.attendance">
        <input type="hidden" name="year" value="<?= (int) $f['year'] ?>">
        <input type="hidden" name="month" value="<?= (int) $f['month'] ?>">
        <input type="hidden" name="user_id" value="<?= (int) $f['user_id'] ?>">
        <input type="hidden" name="dept" value="<?= (int) $f['dept'] ?>">
        <input type="hidden" name="status" value="<?= e($f['status']) ?>">
        <label class="field-label attmark-userlabel" for="attMarkUser">대상 직원</label>
        <select name="mark_user" id="attMarkUser" class="select">
          <?php foreach ($allUsers as $u): ?>
            <option value="<?= (int) $u['id'] ?>"<?= (int) $u['id'] === $markUser ? ' selected' : '' ?>><?= e($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline">변경</button>
        <span class="muted fs-12"><?= e($markUserName) ?> · <?= e($mLabel) ?> · ●=출근 · <span class="attg-mark mk-late">지</span>=지각 · <span class="attg-mark mk-absent">결</span>=무단결근</span>
      </form>
    </div>
    <div class="attmark-cal" id="attMarkCal">
      <div class="attmark-grid attmark-head">
        <?php foreach ($dowKo as $n => $ko): ?>
          <div class="attmark-dow<?= $n >= 6 ? ' we' : '' ?>"><?= e($ko) ?></div>
        <?php endforeach; ?>
      </div>
      <?php foreach ($weeks as $week): ?>
        <div class="attmark-grid">
          <?php foreach ($week as $cell): ?>
            <?php if ($cell === null): ?>
              <div class="attmark-cell empty"></div>
            <?php else: ?>
              <?php
                $mk = $markCal['marks'][$cell['date']] ?? null;
                $att = isset($markCal['attended'][$cell['date']]);
                $cls = 'attmark-cell';
                if ($cell['weekend']) { $cls .= ' we'; }
                if ($cell['holiday'] !== null) { $cls .= ' hol'; }
                if ($cell['future']) { $cls .= ' future'; }
                $tip = $cell['date'] . ' (' . $dowKo[$cell['dow']] . ')'
                     . ($cell['holiday'] !== null ? ' · ' . $cell['holiday'] : '')
                     . ($att ? ' · 출근' : '')
                     . ($mk !== null ? ' · ' . $markLabels[$mk['type']] . ($mk['memo'] !== null && $mk['memo'] !== '' ? ' — ' . $mk['memo'] : '') : '');
              ?>
              <button type="button" class="<?= $cls ?>" data-date="<?= e($cell['date']) ?>"
                      <?= $cell['future'] ? 'disabled title="미래 날짜에는 등록할 수 없습니다"' : 'title="' . e($tip) . '"' ?>>
                <span class="attmark-day"><?= (int) $cell['day'] ?></span>
                <span class="attmark-ind">
                  <?php if ($att): ?><span class="attmark-dot" title="출근"></span><?php endif; ?>
                  <?php if ($mk !== null): ?><span class="attg-mark mk-<?= e($mk['type']) ?>"><?= e($markShort[$mk['type']]) ?></span><?php endif; ?>
                </span>
                <?php if ($mk !== null && $mk['memo'] !== null && $mk['memo'] !== ''): ?>
                  <span class="attmark-memo"><?= e(Util::truncate($mk['memo'], 12)) ?></span>
                <?php endif; ?>
              </button>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
    // 마킹 JS 데이터(등록·변경·해제 모달) — JSON_HEX_TAG: '<' 이스케이프로 </script> 조기 종료 방지
    $markJson = json_encode([
        'userId'   => $markUser,
        'userName' => $markUserName,
        'marks'    => (object) array_map(
            fn($m) => ['type' => $m['type'], 'memo' => (string) ($m['memo'] ?? '')],
            $markCal['marks']
        ),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
  ?>
  <script type="application/json" id="attMarkData"><?= $markJson ?></script>
  <?php endif; ?>

  <!-- 직원 × 일자 출근 그리드 -->
  <div class="card mb-14">
    <div class="card-head"><div class="card-title">일자별 출근 현황</div>
      <div class="muted fs-12"><?= e($mLabel) ?> · ●=출근(작업 기록) · <span class="attg-mark mk-late">지</span>=지각 마킹 · <span class="attg-mark mk-absent">결</span>=무단결근 마킹(출근 제외) · 회색=주말 · 주황=공휴일</div></div>
    <?php if (!$d['rows']): ?>
      <div class="empty"><div class="empty-title">조건에 맞는 직원이 없습니다.</div></div>
    <?php else: ?>
    <div class="table-wrap border-0">
      <table class="data attg">
        <thead>
          <tr>
            <th class="attg-name">직원</th>
            <?php foreach ($d['dates'] as $dt): ?>
              <th class="attg-d <?= $dt['holiday'] !== null ? 'hol' : ($dt['weekend'] ? 'we' : '') ?>"
                  title="<?= e($dt['date'] . ' (' . $dowKo[$dt['dow']] . ')' . ($dt['holiday'] !== null ? ' · ' . $dt['holiday'] : '')) ?>">
                <?= (int) $dt['day'] ?><span class="dw"><?= e($dowKo[$dt['dow']]) ?></span>
              </th>
            <?php endforeach; ?>
            <th class="num">출근</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($d['rows'] as $r): ?>
            <tr>
              <td class="attg-name"><span class="user-color-dot" style="background:<?= e($r['color']) ?>"></span><?= e($r['name']) ?>
                <?php if ($r['status'] !== 'active'): ?><span class="badge badge-muted"><?= e($r['status_label']) ?></span><?php endif; ?></td>
              <?php foreach ($d['dates'] as $dt): ?>
                <?php
                  // 마킹 오버레이: 무단결근=결(출근 ● 미표시 — 출근 일수 제외와 동일 규칙) / 지각=● + 지 병기
                  $att = isset($r['marked'][$dt['date']]);
                  $mk = $r['marks'][$dt['date']] ?? null;
                  $memoTip = $mk !== null && $mk['memo'] !== null && $mk['memo'] !== '' ? ' — ' . $mk['memo'] : '';
                ?>
                <td class="attg-d <?= $dt['holiday'] !== null ? 'hol' : ($dt['weekend'] ? 'we' : '') ?>">
                  <?php if ($mk !== null && $mk['type'] === 'absent'): ?>
                    <span class="attg-mark mk-absent" title="<?= e($r['name'] . ' · ' . $dt['date'] . ' 무단결근' . $memoTip) ?>">결</span>
                  <?php else: ?>
                    <?php if ($att): ?>
                      <span class="attg-on" style="background:<?= e($r['color']) ?>"
                            title="<?= e($r['name'] . ' · ' . $dt['date'] . ' 출근' . ($mk !== null ? ' · 지각' . $memoTip : '')) ?>"></span>
                    <?php endif; ?>
                    <?php if ($mk !== null): ?>
                      <span class="attg-mark mk-late" title="<?= e($r['name'] . ' · ' . $dt['date'] . ' 지각' . $memoTip) ?>">지</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td class="num mono"><b><?= (int) $r['days'] ?></b>일</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- 차트: 직원별 비교(가로 막대) + 최근 6개월 추이(꺾은선) -->
  <div class="grid-2 mb-14">
    <div class="card pad">
      <div class="section-head"><div class="st"><h2>직원별 출근 일수 비교</h2><span class="section-desc"><?= e($mLabel) ?></span></div></div>
      <div class="chart-box"><canvas id="chartAttBar"></canvas></div>
    </div>
    <div class="card pad">
      <div class="section-head"><div class="st"><h2>월별 출근 추이</h2><span class="section-desc">최근 6개월 · 선택 인원 총 출근 일수</span></div></div>
      <div class="chart-box"><canvas id="chartAttTrend"></canvas></div>
    </div>
  </div>

  <!-- 상세 표 — 통계 3종 + 전월 비교 -->
  <div class="card">
    <div class="card-head"><div class="card-title">직원별 상세</div>
      <div class="muted fs-12">출근=작업 기록·업무 일정(작업·회의·현장방문) 고유 날짜(무단결근 마킹일 제외) · 지각·무단결근=관리자 수동 마킹 횟수</div></div>
    <?php if (!$d['rows']): ?>
      <div class="empty"><div class="empty-title">조건에 맞는 직원이 없습니다.</div></div>
    <?php else: ?>
    <div class="table-wrap border-0">
      <table class="data">
        <thead>
          <tr>
            <th>직원</th>
            <th>부서</th>
            <th>재직</th>
            <th class="num" title="<?= e($mLabel) ?> 작업 기록 고유 날짜 수(같은 날 중복 기록은 1일) − 무단결근 마킹일">출근 일수</th>
            <th class="num" title="관리자가 등록한 지각 마킹 수 — 출근 일수에 포함">지각</th>
            <th class="num" title="관리자가 등록한 무단결근 마킹 수 — 출근 일수에서 제외">무단결근</th>
            <th class="num">전월 출근</th>
            <th class="num" title="전월 출근 일수 대비 증감">증감</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($d['rows'] as $r): ?>
            <tr>
              <td><span class="user-color-dot" style="background:<?= e($r['color']) ?>"></span><?= e($r['name']) ?> <span class="badge badge-muted"><?= e($r['role']) ?></span></td>
              <td><?= e($r['dept'] ?? '-') ?></td>
              <td><?= e($r['status_label']) ?></td>
              <td class="num mono"><b><?= (int) $r['days'] ?></b>일</td>
              <td class="num mono <?= $r['late'] > 0 ? 'text-warn' : '' ?>"><?= (int) $r['late'] ?>회</td>
              <td class="num mono <?= $r['absent'] > 0 ? 'text-danger' : '' ?>"><?= (int) $r['absent'] ?>회</td>
              <td class="num mono"><?= (int) $r['prev_days'] ?>일</td>
              <td class="num mono <?= $r['delta'] > 0 ? 'text-ok' : ($r['delta'] < 0 ? 'text-danger' : '') ?>"><?= $r['delta'] > 0 ? '+' : '' ?><?= (int) $r['delta'] ?>일</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php
// 차트 데이터(로컬 Chart.js — 외부 요청 없음). report_attendance.js 가 JSON.parse 로 소비.
// JSON_HEX_TAG: '<' 이스케이프로 </script> 조기 종료 방지. scheduled 는 막대 차트 축 최대값 참고용.
$attJson = json_encode([
    'label'     => $mLabel,
    'scheduled' => (int) $d['scheduled'],
    'bar'       => array_map(fn($r) => ['name' => $r['name'], 'days' => (int) $r['days'], 'color' => $r['color']], $d['rows']),
    'trend'     => array_map(fn($ym, $v) => ['ym' => $ym, 'days' => (int) $v], array_keys($d['trend']), array_values($d['trend'])),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
?>
<script type="application/json" id="attData"><?= $attJson ?></script>
