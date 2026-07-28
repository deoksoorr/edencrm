<?php
/**
 * 보너스 변경 이력(bonus.history) — site_bonus_history 원장 열람.
 * 변경 전→후는 주요 필드 차이만 요약하고 원본 JSON 은 접기(details)로 제공한다.
 * @var array $rows @var array $pg @var int $year @var int $half @var int $bonusId @var int $userId
 * @var bool $canAll @var int[] $years @var array $users
 */
$actionLabels = ['create' => '등록', 'update' => '수정', 'pay' => '지급', 'cancel' => '취소', 'delete' => '삭제'];
$actionBadge  = ['create' => 'badge-ok', 'update' => 'badge-info', 'pay' => 'badge-ok', 'cancel' => 'badge-warn', 'delete' => 'badge-danger'];
$statusLabels = ['unpaid' => '미지급', 'partial' => '부분지급', 'paid' => '지급완료', 'cancelled' => '취소'];
$diffFields   = [
    'user_id' => '직원', 'project_id' => '프로젝트', 'year' => '연도', 'half' => '반기',
    'base_amount' => '산정 대상 금액', 'calc_basis' => '산정 기준',
    'contrib_revenue' => '기여도 반영 매출', 'contrib_profit' => '기여도 반영 순이익', 'calc_amount' => '산정액',
    'confirmed_bonus' => '확정 보너스', 'paid_amount' => '지급액(구)', 'pay_date' => '지급일', 'pay_status' => '상태',
    'paid_by' => '지급담당자', 'memo' => '메모',
];
$moneyFields = ['base_amount' => 1, 'contrib_revenue' => 1, 'contrib_profit' => 1, 'calc_amount' => 1, 'confirmed_bonus' => 1, 'paid_amount' => 1];
$fmtVal = static function (string $key, $v) use ($statusLabels, $moneyFields): string {
    if ($v === null || $v === '') {
        return '-';
    }
    if (isset($moneyFields[$key])) {
        return number_format((float) $v) . '원';
    }
    if ($key === 'pay_status') {
        return $statusLabels[$v] ?? (string) $v;
    }
    if ($key === 'half') {
        return (int) $v === 1 ? '상반기' : '하반기';
    }
    return (string) $v;
};
$baseParams = ['year' => $year ?: '', 'half' => $half ?: '', 'bonus_id' => $bonusId ?: ''];
if ($canAll) {
    $baseParams['user_id'] = $userId ?: '';
}
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">보너스 변경 이력</h1>
      <div class="page-sub">현장 보너스 원장 변경 기록<?= $bonusId ? ' · 보너스 #' . (int) $bonusId : '' ?><?= $canAll ? '' : ' · 본인 대상 건만 표시' ?></div>
    </div>
    <div class="page-actions">
      <a href="<?= e(url('bonus.index')) ?>" class="btn btn-outline">보너스 지급 현황</a>
    </div>
  </div>

  <form method="get" class="toolbar" action="<?= e($GLOBALS['config']['BASE_URL']) ?>/index.php">
    <input type="hidden" name="r" value="bonus.history">
    <?php if ($bonusId): ?><input type="hidden" name="bonus_id" value="<?= (int) $bonusId ?>"><?php endif; ?>
    <select name="year" class="select">
      <option value="">전체 연도</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?>년</option>
      <?php endforeach; ?>
    </select>
    <select name="half" class="select">
      <option value="">전체 반기</option>
      <option value="1" <?= $half === 1 ? 'selected' : '' ?>>상반기</option>
      <option value="2" <?= $half === 2 ? 'selected' : '' ?>>하반기</option>
    </select>
    <?php if ($canAll): ?>
      <select name="user_id" class="select">
        <option value="">전체 직원</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-outline">조회</button>
    <a href="<?= e(url('bonus.history', $bonusId ? ['bonus_id' => $bonusId] : [])) ?>" class="btn btn-ghost">초기화</a>
  </form>

  <?php if (!$rows): ?>
    <div class="empty"><div class="empty-title">변경 이력이 없습니다.</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data compact">
      <thead>
        <tr><th>변경일</th><th>변경자</th><th>액션</th><th>직원</th><th>프로젝트</th><th>반기</th><th>변경 내용</th><th>사유</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
            $before = $r['before_json'] ? json_decode($r['before_json'], true) : null;
            $after  = $r['after_json'] ? json_decode($r['after_json'], true) : null;
            // 주요 필드 차이 요약(등록=후값 요약, 삭제=문구, 그 외=변경 필드만 전→후)
            $changes = [];
            if ($r['action'] === 'create' && $after) {
                // 신규 행: 확정 보너스(신) 우선, 레거시 행은 지급액(구) 폴백
                $amtKey = array_key_exists('confirmed_bonus', $after) ? 'confirmed_bonus' : 'paid_amount';
                foreach (['calc_amount', $amtKey, 'pay_status'] as $k) {
                    $changes[] = $diffFields[$k] . ' ' . $fmtVal($k, $after[$k] ?? null);
                }
            } elseif ($r['action'] === 'delete') {
                $changes[] = '내역 삭제(소프트 — 원장 보존)';
            } elseif ($before && $after) {
                foreach ($diffFields as $k => $label) {
                    $b = $before[$k] ?? null;
                    $a = $after[$k] ?? null;
                    $bs = isset($moneyFields[$k]) ? (string) (float) ($b ?? 0) : (string) ($b ?? '');
                    $as = isset($moneyFields[$k]) ? (string) (float) ($a ?? 0) : (string) ($a ?? '');
                    if ($bs !== $as) {
                        $changes[] = $label . ' ' . $fmtVal($k, $b) . ' → ' . $fmtVal($k, $a);
                    }
                }
                if (!$changes) {
                    $changes[] = '변경 값 없음';
                }
            }
        ?>
          <tr>
            <td class="nowrap"><?= e(fmtdate($r['changed_at'], 'Y-m-d H:i')) ?></td>
            <td><?= e($r['changed_by_name'] ?? '-') ?></td>
            <td><span class="badge <?= e($actionBadge[$r['action']] ?? 'badge-info') ?>"><?= e($actionLabels[$r['action']] ?? $r['action']) ?></span></td>
            <td><?= e($r['target_name'] ?? '-') ?><?= $r['bonus_deleted_at'] ? ' <span class="badge badge-danger" title="삭제된 보너스 건">삭제됨</span>' : '' ?></td>
            <td><?= e($r['project_name'] ?? '-') ?></td>
            <td class="nowrap"><?= (int) $r['year'] ?>-<?= (int) $r['half'] === 1 ? '상' : '하' ?></td>
            <td class="bh-diff">
              <?php foreach ($changes as $c): ?><div><?= e($c) ?></div><?php endforeach; ?>
              <details class="bh-json">
                <summary>원본 JSON</summary>
                <?php if ($before): ?><div class="muted fs-12">변경 전</div><pre><?= e(json_encode($before, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre><?php endif; ?>
                <?php if ($after): ?><div class="muted fs-12">변경 후</div><pre><?= e(json_encode($after, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre><?php endif; ?>
              </details>
            </td>
            <td><?= e($r['reason'] ?? '-') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php View::partial('partials/pager', [
      'pg'  => $pg,
      'url' => fn (int $p): string => url('bonus.history', array_filter($baseParams, static fn ($v) => $v !== '') + ['page' => $p]),
  ]); ?>
  <?php endif; ?>
</div>
