<?php
/**
 * 보너스 지급 현황(bonus.index) — 목록 + 합계행, bonus.manage 보유 시 등록/수정/지급/취소/삭제 모달.
 * @var array $f @var int[] $years @var array $users @var array $projects
 * @var array $bonuses @var array $bonusTotals @var bool $canManage @var array $formUsers
 */
$statusLabels = ['unpaid' => '미지급', 'paid' => '지급완료', 'cancelled' => '취소'];
$statusBadge  = ['unpaid' => 'badge-warn', 'paid' => 'badge-ok', 'cancelled' => 'badge-danger'];
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">보너스 지급 현황</h1>
      <div class="page-sub"><?= e(Util::halfLabel($f['year'], $f['half'])) ?> 현장 보너스 원장<?= $f['canAll'] ? '' : ' · 본인 내역만 표시' ?></div>
    </div>
    <div class="page-actions">
      <a href="<?= e(url('halfyear.index', ['year' => $f['year'], 'half' => $f['half']])) ?>" class="btn btn-outline">반기 보너스 지급 현황</a>
      <a href="<?= e(url('bonus.history', ['year' => $f['year'], 'half' => $f['half']])) ?>" class="btn btn-outline">변경 이력</a>
    </div>
  </div>

  <form method="get" class="toolbar" action="<?= e($GLOBALS['config']['BASE_URL']) ?>/index.php">
    <input type="hidden" name="r" value="bonus.index">
    <select name="year" class="select">
      <?php foreach ($years as $y): ?>
        <option value="<?= (int) $y ?>" <?= $y === $f['year'] ? 'selected' : '' ?>><?= (int) $y ?>년</option>
      <?php endforeach; ?>
    </select>
    <select name="half" class="select">
      <option value="1" <?= $f['half'] === 1 ? 'selected' : '' ?>>상반기</option>
      <option value="2" <?= $f['half'] === 2 ? 'selected' : '' ?>>하반기</option>
    </select>
    <?php if ($f['canAll']): ?>
      <select name="user_id" class="select">
        <option value="">전체 직원</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= $f['userId'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?><?= ($u['status'] ?? 'active') !== 'active' ? ' (비활성)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="project_id" class="select">
      <option value="">전체 프로젝트</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= $f['projectId'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="pay_status" class="select">
      <option value="">전체 지급상태</option>
      <?php foreach ($statusLabels as $k => $l): ?>
        <option value="<?= e($k) ?>" <?= $f['payStatus'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline">조회</button>
    <a href="<?= e(url('bonus.index')) ?>" class="btn btn-ghost">초기화</a>
    <div class="toolbar-spacer"></div>
    <?php if ($canManage): ?>
      <button type="button" class="btn btn-primary" data-bact="new">+ 보너스 등록</button>
    <?php endif; ?>
  </form>

  <?php if (!$bonuses): ?>
    <div class="empty"><div class="empty-title">조건에 맞는 보너스 내역이 없습니다.</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data compact">
      <thead>
        <tr>
          <th>직원</th><th>프로젝트</th><th>반기</th>
          <th class="num" title="확정 매출(공급가액·VAT 제외)">총매출</th>
          <th class="num" title="총매출 × 기여율">기여도 반영 매출</th>
          <th class="num" title="(확정 매출 − 지출) × 기여율 — 참고">기여도 반영 순이익</th>
          <th class="num" title="기여도 반영 매출 × 보너스율 — 참고 계산값">산정액</th>
          <th class="num" title="관리자가 확정한 실제 지급 금액 — 지급완료 시 이 금액만 지급">확정 보너스</th>
          <th>지급일</th><th>상태</th><th>지급담당자</th><th>메모</th>
          <?php if ($canManage): ?><th>관리</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bonuses as $b):
            $cancelled = $b['pay_status'] === 'cancelled';
            $closed = Util::isHalfClosed((int) $b['year'], (int) $b['half']);
            // 모달 프리필용 데이터(JSON) — 뷰 출력은 e(), JS 는 dataset 로만 사용
            $json = json_encode([
                'id' => (int) $b['id'], 'user_id' => (int) $b['user_id'],
                'project_id' => $b['project_id'] !== null ? (int) $b['project_id'] : '',
                'year' => (int) $b['year'], 'half' => (int) $b['half'],
                'base_amount' => (int) $b['base_amount'], 'calc_basis' => (string) ($b['calc_basis'] ?? ''),
                'contrib_revenue' => $b['contrib_revenue'] !== null ? (int) $b['contrib_revenue'] : '',
                'contrib_profit' => $b['contrib_profit'] !== null ? (int) $b['contrib_profit'] : '',
                'bonus_rate' => $b['bonus_rate'] !== null ? (float) $b['bonus_rate'] : '',
                'project_name' => (string) ($b['project_name'] ?? ''),
                'user_name' => (string) ($b['user_name'] ?? ''),
                'calc_amount' => (int) $b['calc_amount'], 'confirmed_bonus' => (int) $b['confirmed_bonus'],
                'pay_date' => (string) ($b['pay_date'] ?? ''), 'pay_status' => $b['pay_status'],
                'paid_by' => $b['paid_by'] !== null ? (int) $b['paid_by'] : '',
                'memo' => (string) ($b['memo'] ?? ''), 'closed' => $closed,
            ], JSON_UNESCAPED_UNICODE);
        ?>
          <tr<?= $cancelled ? ' class="bonus-cancelled"' : '' ?> data-bonus="<?= e($json) ?>">
            <td><?= e($b['user_name']) ?></td>
            <td><?= $b['project_id'] ? e($b['project_name'] ?? ('#' . $b['project_id'])) : '<span class="muted">-</span>' ?></td>
            <td class="nowrap"><?= (int) $b['year'] ?>-<?= (int) $b['half'] === 1 ? '상' : '하' ?><?= $closed ? ' <span class="badge badge-warn" title="마감 반기 — 수정·삭제 시 사유 필수">마감</span>' : '' ?></td>
            <td class="num mono"><?= moneyCell((float) $b['base_amount']) ?></td>
            <td class="num mono"><?= $b['contrib_revenue'] !== null ? moneyCell((float) $b['contrib_revenue'])
                : ($b['calc_basis'] !== null && $b['calc_basis'] !== '' ? e($b['calc_basis']) : '<span class="muted">-</span>') ?></td>
            <td class="num mono<?= $b['contrib_profit'] !== null && (int) $b['contrib_profit'] < 0 ? ' text-danger' : '' ?>"><?= $b['contrib_profit'] !== null ? moneyCell((float) $b['contrib_profit']) : '<span class="muted">-</span>' ?></td>
            <td class="num mono muted"><?= moneyCell((float) $b['calc_amount']) ?></td>
            <td class="num mono"><b><?= moneyCell((float) $b['confirmed_bonus']) ?></b></td>
            <td><?= $b['pay_date'] ? e(fmtdate($b['pay_date'])) : '-' ?></td>
            <td><span class="badge <?= e($statusBadge[$b['pay_status']] ?? 'badge-info') ?>"><?= e($statusLabels[$b['pay_status']] ?? $b['pay_status']) ?></span></td>
            <td><?= e($b['paid_by_name'] ?? '-') ?></td>
            <td class="bonus-memo" title="<?= e($b['memo'] ?? '') ?>"><?= e(Util::truncate($b['memo'] ?? '-', 20)) ?></td>
            <?php if ($canManage): ?>
            <td>
              <div class="btn-group bonus-actions">
                <button type="button" class="btn btn-sm btn-outline" data-bact="edit">수정</button>
                <?php if (!$cancelled): ?>
                  <button type="button" class="btn btn-sm btn-outline" data-bact="pay">지급처리</button>
                  <button type="button" class="btn btn-sm btn-outline" data-bact="cancel">취소</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-danger" data-bact="del">삭제</button>
              </div>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="bonus-sum">
          <td colspan="6">합계 (취소 건 제외)</td>
          <td class="num mono muted" title="산정액 합계(참고)"><?= money($bonusTotals['calc']) ?></td>
          <td class="num mono"><b><?= money($bonusTotals['confirmed']) ?></b></td>
          <td colspan="<?= $canManage ? 5 : 4 ?>">지급완료 <b><?= money($bonusTotals['paid']) ?></b>원 · <span class="<?= $bonusTotals['unpaid'] > 0 ? 'text-danger' : 'muted' ?>">미지급 <b><?= money($bonusTotals['unpaid']) ?></b>원</span></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_form_modal.php'; ?>
