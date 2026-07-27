<?php
/**
 * [개요] 탭 — 기본 정보 + 계약 정보(연결 계약 링크·견적 전환) + 현재 상태·공정
 * + 배정 직원 요약 + 다음 일정 + 지출·손익 요약 + 최근 활동 5건.
 * projects/show.php 에서 include (변수 스코프 공유).
 */
?>
<div class="ov-grid">
  <div class="card pad">
    <div class="section-head"><div class="st"><h2>기본 정보</h2></div></div>
    <dl class="dl">
      <dt>프로젝트 번호</dt><dd><?= e($p['project_no']) ?></dd>
      <dt>고객</dt><dd><?php if (!empty($p['customer_id'])): ?><a href="<?= e(url('customers.show', ['id' => $p['customer_id']])) ?>"><?= e($p['customer_name']) ?></a><?php else: ?><?= e($p['customer_name'] ?: '-') ?><?php endif; // 예외 프로젝트: 고객 미연결 시 스냅샷명 텍스트 ?><?= $p['customer_phone'] ? ' · ' . e($p['customer_phone']) : '' ?></dd>
      <dt>현장주소</dt><dd><?= e($p['site_address'] ?: '-') ?></dd>
      <dt>공사유형</dt><dd><?= e($p['work_type'] ?: '-') ?></dd>
      <dt>영업담당</dt><dd><?= e($p['sales_user_name'] ?? '-') ?></dd>
      <dt>현장관리자</dt><dd><?= e($p['site_manager_name'] ?? '-') ?></dd>
      <dt>계약일</dt><dd><?= fmtdate($p['contract_date']) ?></dd>
      <dt>착공/준공예정</dt><dd><?= fmtdate($p['start_date']) ?> ~ <?= fmtdate($p['end_date']) ?><?php if ($isDelayed): ?> <span class="text-danger">· 지연</span><?php endif; ?></dd>
      <dt>실제착공/준공</dt><dd><?= fmtdate($p['actual_start_date']) ?> ~ <?= fmtdate($p['actual_end_date']) ?></dd>
      <dt>진행률</dt><dd><?= $progress ?>%
        <div class="progress mt-8" style="width:140px"><div class="progress-bar <?= $progress >= 100 ? 'ok' : ($isDelayed ? 'danger' : '') ?>" style="width:<?= $progress ?>%"></div></div></dd>
      <dt>기여도 배분방식</dt><dd><?= e(['main' => '주담당 100%', 'ratio' => '비율 직접입력', 'role' => '역할별 기본배분'][$p['contribution_mode']] ?? $p['contribution_mode']) ?></dd>
      <dt>메모</dt><dd><?= nl2br(e($p['memo'] ?: '-')) ?></dd>
    </dl>
  </div>

  <div class="card pad">
    <div class="section-head"><div class="st"><h2>계약 정보</h2></div>
      <?php if ($contract): ?>
        <a href="<?= e(url('contracts.show', ['id' => $contract['id']])) ?>" class="btn btn-outline btn-sm">계약 보기 (<?= e($contract['contract_no']) ?>)</a>
      <?php endif; ?>
    </div>
    <?php if ($contract): ?>
      <dl class="dl">
        <dt>계약번호</dt><dd><a href="<?= e(url('contracts.show', ['id' => $contract['id']])) ?>"><?= e($contract['contract_no']) ?></a></dd>
        <dt>계약 상태</dt><dd><span class="badge <?= e(StatusService::CONTRACT_BADGE[$contract['status']] ?? 'badge-muted') ?>"><?= e(StatusService::CONTRACT_LABELS[$contract['status']] ?? $contract['status']) ?></span></dd>
        <dt>계약일</dt><dd><?= fmtdate($contract['contract_date']) ?></dd>
        <?php if ($canFinance): ?>
          <dt>계약 총액(VAT 포함)</dt><dd title="부가세를 포함한 계약 금액 — 현금(입금) 기준 축"><?= moneyCell((float) $contract['contract_amount']) ?></dd>
        <?php endif; ?>
        <?php if ($contract['quote_id'] || $contract['original_quote_amount'] !== null): ?>
          <dt>견적 전환</dt>
          <dd>
            <?php if ($contract['quote_id']): ?>원본견적 <a href="<?= e(url('quotes.show', ['id' => $contract['quote_id']])) ?>"><?= e($contract['quote_no'] ?? ('#' . (int) $contract['quote_id'])) ?></a><?php endif; ?>
            <?php if ($canFinance && $contract['original_quote_amount'] !== null): ?> · 원본 견적액 <span class="mono" title="전환 시점 견적 총액(VAT 포함) — 원본 견적 불변"><?= money((float) $contract['original_quote_amount']) ?>원</span><?php endif; ?>
            <?php if ($contract['converted_at']): ?> · 전환 <?= e($contract['converted_at']) ?><?= !empty($contract['converted_by_name']) ? ' (' . e($contract['converted_by_name']) . ')' : '' ?><?php endif; ?>
          </dd>
        <?php endif; ?>
      </dl>
    <?php else: ?>
      <div class="muted">계약 없음(예외 생성) — 계약 연결 없이 생성된 프로젝트입니다.</div>
    <?php endif; ?>

    <div class="section-head mt-14"><div class="st"><h2>현재 상태·공정</h2></div></div>
    <div class="kv-row">
      <div class="kv"><div class="kv-label">프로젝트 상태</div>
        <div class="kv-value"><span class="badge <?= $badgeClass ?>"><?= e($statuses[$p['status']] ?? $p['status']) ?></span></div></div>
      <div class="kv"><div class="kv-label">현재 공정</div>
        <div class="kv-value"><?php if ($psName !== null): ?><span class="badge badge-stage" style="--sc:<?= e($psColor) ?>"><?= e($psName) ?></span><?php else: ?><span class="badge badge-muted">공정 미배치</span><?php endif; ?></div></div>
      <div class="kv"><div class="kv-label">공정 진입일</div>
        <div class="kv-value sm"><?= e($p['process_entered_at'] ?: '-') ?></div></div>
    </div>

    <div class="section-head mt-14"><div class="st"><h2>배정 직원</h2><span class="section-desc">활성 배정 <?= count($activeAssignments) ?>명</span></div></div>
    <?php if (!$activeAssignments): ?>
      <div class="muted">직원 미배정</div>
    <?php else: ?>
      <div class="assignee-chips">
        <?php foreach ($activeAssignments as $a): ?>
          <span class="assignee-chip"><?= e($a['user_name']) ?><span class="role"><?= e($a['role'] ?: '역할 미지정') ?></span></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="section-head mt-14"><div class="st"><h2>다음 일정</h2></div>
      <a href="<?= e(url('schedule.index', ['project_id' => $p['id']])) ?>" class="section-link">일정 관리</a></div>
    <?php if ($nextSchedule): ?>
      <div><strong><?= e($nextSchedule['title']) ?></strong>
        <span class="muted nowrap"> · <?= e($nextSchedule['start_datetime']) ?><?= $nextSchedule['end_datetime'] ? ' ~ ' . e($nextSchedule['end_datetime']) : '' ?></span></div>
    <?php else: ?>
      <div class="muted">일정 미등록</div>
    <?php endif; ?>

    <?php if ($canFinance): ?>
      <?php $ovCostEntered = !empty($costSub['has_entries']); ?>
      <div class="section-head mt-14"><div class="st"><h2>지출·손익 요약</h2><span class="section-desc">상세는 '지출' 탭</span></div></div>
      <div class="kv-row">
        <div class="kv" title="지출 총액 = 확정 상태 실제 비용 합계 (임시 저장·확인 대기·취소 제외) — 회계 지표의 '원가 총액'과 동일 값">
          <div class="kv-label">지출 총액</div>
          <div class="kv-value"><?= $ovCostEntered ? moneyCell($calc['actual_cost']) : '<span class="muted">미입력</span>' ?></div></div>
        <div class="kv" title="실제 순이익 = 확정 매출(공급가액) − 지출 총액<?= $ovCostEntered ? '' : ' — 지출 미입력 시 계산하지 않음' ?>">
          <div class="kv-label">실제 순이익</div>
          <div class="kv-value <?= $ovCostEntered && $calc['actual_profit'] < 0 ? 'text-danger' : '' ?>"><?= $ovCostEntered ? moneyCell($calc['actual_profit']) : '<span class="muted">-</span>' ?></div></div>
        <div class="kv" title="실제 순이익률 = 실제 순이익 ÷ 확정 매출(공급가액) × 100">
          <div class="kv-label">실제 순이익률</div>
          <div class="kv-value"><?= $ovCostEntered ? pct($calc['actual_profit_rate']) : '-' ?></div></div>
        <?php if ($calc['estimated_cost'] > 0): ?>
          <div class="kv" title="계획 지출(선택) — 견적 단계에서 입력한 참고용 계획 값. 지출 총액과는 별개입니다.">
            <div class="kv-label muted">계획 지출(선택)</div><div class="kv-value muted"><?= moneyCell($calc['estimated_cost']) ?></div></div>
          <div class="kv" title="계획 순이익(참고) = 확정 매출(공급가액) − 계획 지출">
            <div class="kv-label muted">계획 순이익(참고)</div><div class="kv-value muted"><?= moneyCell($calc['estimated_profit']) ?> · <?= pct($calc['estimated_profit_rate']) ?></div></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card pad">
  <div class="section-head"><div class="st"><h2>최근 활동</h2><span class="section-desc">상태·공정·파일 이벤트 최근 5건 — 전체는 '이력' 탭</span></div></div>
  <?php if (!$recentEvents): ?>
    <div class="empty"><div class="empty-title">기록된 활동이 없습니다.</div></div>
  <?php else: ?>
    <div class="timeline">
      <?php foreach ($recentEvents as $ev): ?>
        <div class="timeline-item">
          <div class="timeline-time"><?= e($ev['at']) ?></div>
          <div class="timeline-body"><?= e($ev['text']) ?></div>
          <div class="timeline-tag"><?= e($ev['who']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
