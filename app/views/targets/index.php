<?php
/** 목표(KPI) 관리 — 회사 목표(월·분기·연간). @var int $year @var array $years,$company */
$cv = function (string $type, int $no, string $field) use ($company) {
    $r = $company[$type][$no] ?? null;
    return $r ? (int) $r[$field] : '';
};
?>
<div class="page">
  <div class="page-head">
    <div>
      <div class="page-title">목표 관리 <span class="badge badge-info">KPI</span></div>
      <div class="page-sub">회사 월·분기·연간 매출·순이익 목표를 설정합니다. 대시보드 달성률은 이 데이터로 자동 계산됩니다.</div>
    </div>
    <form class="page-actions" method="get" action="<?= e(url('targets.index')) ?>">
      <input type="hidden" name="r" value="targets.index">
      <label class="field-label self-center">연도</label>
      <select name="year" class="select" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int) $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= (int) $y ?>년</option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- 회사 목표 -->
  <div class="card pad">
    <div class="section-head"><div class="st"><h2>회사 목표</h2><span class="section-desc"><?= (int) $year ?>년</span></div></div>
    <form data-ajax data-route="targets.save" data-success="회사 목표가 저장되었습니다." data-reload="1">
      <?= csrf_field() ?>
      <input type="hidden" name="year" value="<?= (int) $year ?>">
      <div class="grid-2">
        <div class="table-wrap">
          <table class="data compact">
            <thead><tr><th>월</th><th class="num">매출 목표(원)</th><th class="num">순이익 목표(원)</th></tr></thead>
            <tbody>
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <tr>
                  <td><?= $m ?>월</td>
                  <td class="num"><input type="number" name="m_rev_<?= $m ?>" class="input tnum-input" min="0" step="10000" value="<?= $cv('month', $m, 'target_revenue') ?>"></td>
                  <td class="num"><input type="number" name="m_pft_<?= $m ?>" class="input tnum-input" min="0" step="10000" value="<?= $cv('month', $m, 'target_profit') ?>"></td>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
        <div>
          <div class="table-wrap mb-14">
            <table class="data compact">
              <thead><tr><th>분기</th><th class="num">매출 목표(원)</th><th class="num">순이익 목표(원)</th></tr></thead>
              <tbody>
                <?php for ($q = 1; $q <= 4; $q++): ?>
                  <tr>
                    <td><?= $q ?>분기</td>
                    <td class="num"><input type="number" name="q_rev_<?= $q ?>" class="input tnum-input" min="0" step="100000" value="<?= $cv('quarter', $q, 'target_revenue') ?>"></td>
                    <td class="num"><input type="number" name="q_pft_<?= $q ?>" class="input tnum-input" min="0" step="100000" value="<?= $cv('quarter', $q, 'target_profit') ?>"></td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
          <div class="table-wrap">
            <table class="data compact">
              <thead><tr><th>연간</th><th class="num">매출 목표(원)</th><th class="num">순이익 목표(원)</th></tr></thead>
              <tbody>
                <tr>
                  <td><?= (int) $year ?>년</td>
                  <td class="num"><input type="number" name="y_rev" class="input tnum-input" min="0" step="1000000" value="<?= $cv('year', 0, 'target_revenue') ?>"></td>
                  <td class="num"><input type="number" name="y_pft" class="input tnum-input" min="0" step="1000000" value="<?= $cv('year', 0, 'target_profit') ?>"></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="btn-group mt-14"><button type="submit" class="btn btn-primary">회사 목표 저장</button></div>
    </form>
  </div>

</div>
