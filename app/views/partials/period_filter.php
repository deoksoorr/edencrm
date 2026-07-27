<?php
/**
 * 공통 기간 필터 파셜 (R4 T1) — 반드시 GET .toolbar <form> "내부"에서 include 한다.
 * URL 파라미터 규약: period(프리셋 키) / date_from,date_to(custom 시) / $basisParam(기준 컬럼, 옵션).
 * 프리셋 버튼은 submit(name="period")이라 같은 폼의 검색어·상태 등과 함께 제출된다.
 * 서버는 Util::periodRange($period, $from, $to) 로 경계를 계산한다(비 custom 프리셋이면 날짜 입력 무시).
 *
 * @var string     $action       라우트 키 (기간 초기화 링크 생성용) — 필수
 * @var array      $filters      현재 필터 값(다른 파라미터 포함 — 초기화 링크에 유지). 'period' 키 필수.
 * @var array      $range        Util::periodRange() 결과 — 프리셋 선택 시 date input 에 계산된 경계 표시(옵션)
 * @var array|null $basisOptions 기준 컬럼 select [key => label] (없으면 select 미표시)
 * @var string     $basisParam   기준 컬럼 파라미터명 (기본 'basis')
 */
$period       = (string) ($filters['period'] ?? '');
$range        = $range ?? ['from' => null, 'to' => null];
$basisOptions = $basisOptions ?? null;
$basisParam   = $basisParam ?? 'basis';
$basisValue   = (string) ($filters[$basisParam] ?? '');
$isCustom     = $period === 'custom';
$isPreset     = $period !== '' && !$isCustom && isset(Util::PERIOD_PRESETS[$period]);
// date input 표시값: custom = 입력값, 프리셋 = 계산된 경계(계산 기준 화면 명시), 미선택 = 빈칸
$dfVal = $isCustom ? (string) ($filters['date_from'] ?? '') : ($isPreset ? (string) ($range['from'] ?? '') : '');
$dtVal = $isCustom ? (string) ($filters['date_to'] ?? '') : ($isPreset ? (string) ($range['to'] ?? '') : '');
// 기간 초기화 링크: period/date_from/date_to/page 만 제거, 나머지 필터 유지
$pfKeep = array_filter(
    array_diff_key($filters, array_flip(['period', 'date_from', 'date_to', 'page'])),
    static fn($v) => $v !== '' && $v !== null
);
?>
<div class="pf">
  <?php /* Enter 제출 기본 버튼 가로채기 방지 — 폼의 첫 submit 을 무명 버튼으로(시각 숨김) */ ?>
  <button type="submit" class="pf-enter" tabindex="-1" aria-hidden="true"></button>
  <?php if ($basisOptions): ?>
    <select name="<?= e($basisParam) ?>" class="select pf-basis" title="조회 기준 컬럼">
      <?php foreach ($basisOptions as $bk => $bl): ?>
        <option value="<?= e((string) $bk) ?>" <?= $basisValue === (string) $bk ? 'selected' : '' ?>><?= e($bl) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
  <input type="hidden" name="period" id="pfPeriod" value="<?= e($period) ?>">
  <div class="pf-presets" role="group" aria-label="기간 프리셋">
    <?php foreach (Util::PERIOD_PRESETS as $pk => $pl): if ($pk === 'custom') { continue; } ?>
      <button type="submit" name="period" value="<?= e($pk) ?>"
              class="btn btn-sm btn-outline pf-preset<?= $period === $pk ? ' on' : '' ?>"
              <?= $period === $pk ? 'aria-pressed="true"' : '' ?>><?= e($pl) ?></button>
    <?php endforeach; ?>
  </div>
  <span class="pf-dates<?= $isCustom ? ' on' : '' ?>">
    <input type="date" name="date_from" class="input" value="<?= e($dfVal) ?>" aria-label="시작일"
           onchange="document.getElementById('pfPeriod').value='custom'">
    <span class="muted">~</span>
    <input type="date" name="date_to" class="input" value="<?= e($dtVal) ?>" aria-label="종료일"
           onchange="document.getElementById('pfPeriod').value='custom'">
    <button type="submit" name="period" value="custom" class="btn btn-sm btn-outline pf-apply<?= $isCustom ? ' on' : '' ?>">직접 지정</button>
  </span>
  <?php if ($period !== ''): ?>
    <a href="<?= e(url($action, $pfKeep)) ?>" class="btn btn-sm btn-ghost">기간 초기화</a>
  <?php endif; ?>
</div>
