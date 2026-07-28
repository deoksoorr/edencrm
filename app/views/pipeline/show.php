<?php
/**
 * 영업기회 상세 페이지(R4 T7) — 보드 드로어 대체. 조회 전용 + 원본 관리(수정/삭제)는 별도 이동.
 * @var array $lead @var array $activities @var bool $canManage
 */
$l = $lead;
$actLabel = ['call' => '통화', 'visit' => '방문', 'sms' => '문자', 'email' => '이메일', 'note' => '메모'];
$est = !empty($l['link_contract_estimated']);
$overdue = !empty($l['next_contact_date']) && $l['next_contact_date'] < date('Y-m-d');
?>
<div class="page">
  <div class="detail-head">
    <div>
      <div class="detail-title">
        <?= e($l['customer_name']) ?><?= $l['company_name'] ? ' (' . e($l['company_name']) . ')' : '' ?>
        <span class="badge badge-stage" style="--sc:<?= e($l['derived_color']) ?>"><?= e($l['derived_label']) ?></span>
        <span class="badge badge-muted" title="원본 12단계(산정 입력·이력)">원 단계: <?= e($l['stage_name']) ?></span>
      </div>
      <div class="detail-meta">
        담당 <?= e($l['sales_user_name'] ?: '미지정') ?> · 등록일 <?= fmtdate($l['created_at']) ?> · 체류 D+<?= (int) $l['stay_days'] ?>
      </div>
      <div class="detail-meta muted" title="표시 단계는 저장값이 아닌 파생값입니다">자동 산정 근거: <?= e($l['derived_source']) ?></div>
    </div>
    <div class="page-actions">
      <?php if ($canManage): ?>
        <a class="btn btn-outline" href="<?= e(url('pipeline.form', ['id' => (int) $l['id']])) ?>">수정</a>
        <form method="post" action="<?= e(url('pipeline.delete')) ?>" style="display:inline"
              onsubmit="return confirm('이 영업기회를 삭제하시겠습니까?');">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
          <button type="submit" class="btn btn-ghost-danger">삭제</button>
        </form>
      <?php endif; ?>
      <a class="btn btn-outline" href="<?= e(url('pipeline.index')) ?>">목록으로</a>
    </div>
  </div>

  <!-- 바로가기(연결 문서 — 없으면 숨김) -->
  <div class="pl-shortcuts">
    <a class="btn btn-sm btn-outline" href="<?= e(url('customers.show', ['id' => (int) $l['customer_id']])) ?>">고객 보기</a>
    <?php if (!empty($l['link_quote'])): ?>
      <a class="btn btn-sm btn-outline" href="<?= e(url('quotes.show', ['id' => (int) $l['link_quote']['id']])) ?>">견적 보기 (<?= e($l['link_quote']['quote_no']) ?>)</a>
    <?php endif; ?>
    <?php if (!empty($l['link_contract'])): ?>
      <a class="btn btn-sm btn-outline" href="<?= e(url('contracts.show', ['id' => (int) $l['link_contract']['id']])) ?>"
         title="<?= $est ? '고객 단위 추정 계약 — 리드-계약 연결 미확정' : '연결 계약' ?>">계약 보기 (<?= e($l['link_contract']['contract_no']) ?><?= $est ? ' · 추정' : '' ?>)</a>
    <?php endif; ?>
    <?php if (!empty($l['link_project'])): ?>
      <a class="btn btn-sm btn-outline" href="<?= e(url('projects.show', ['id' => (int) $l['link_project']['id']])) ?>"
         title="<?= $est ? '추정 계약의 프로젝트' : '연결 프로젝트' ?>">프로젝트 보기 (<?= e($l['link_project']['name']) ?>)</a>
    <?php endif; ?>
  </div>

  <div class="grid-2">
    <div class="card pad">
      <div class="card-title">고객 · 현장</div>
      <dl class="dl">
        <dt>연락처</dt><dd><?= e($l['customer_phone'] ?: '-') ?></dd>
        <dt>현장주소</dt><dd><?= e($l['site_address'] ?: $l['customer_site_address'] ?: '-') ?></dd>
        <dt>공사종류</dt><dd><?= e($l['work_type'] ?: '-') ?></dd>
        <dt>유입경로</dt><dd><?= e($l['customer_source'] ?: '-') ?></dd>
        <dt>태그</dt><dd><?= e($l['tags'] ?: '-') ?></dd>
      </dl>
    </div>
    <div class="card pad">
      <div class="card-title">영업 · 수익(예상)</div>
      <dl class="dl">
        <dt>예상 계약</dt><dd class="mono"><?= money((float) $l['expected_amount']) ?>원</dd>
        <dt>예상 원가</dt><dd class="mono"><?= money((float) $l['expected_cost']) ?>원</dd>
        <dt>예상 순이익</dt><dd class="mono"><?= money((float) $l['profit']) ?>원 (<?= e(pct($l['profit_rate'] !== null ? (float) $l['profit_rate'] : null)) ?>)</dd>
        <dt>성공확률</dt><dd><?= $l['win_probability'] !== null ? e((string) round((float) $l['win_probability'])) . '%' : '-' ?></dd>
        <dt>가중 예상매출</dt><dd class="mono"><?= money((float) $l['weighted_revenue']) ?>원</dd>
      </dl>
    </div>
  </div>

  <div class="card pad">
    <div class="card-title">진행</div>
    <div class="kv-row">
      <div class="kv"><span class="kv-label">최근 연락일</span><span class="kv-value"><?= fmtdate($l['last_activity_date']) ?></span></div>
      <div class="kv"><span class="kv-label">다음 일정(연락 예정)</span>
        <span class="kv-value<?= $overdue ? ' text-danger' : '' ?>"><?= fmtdate($l['next_contact_date']) ?><?= $overdue ? ' (지연)' : '' ?></span></div>
      <div class="kv"><span class="kv-label">단계 진입일</span><span class="kv-value"><?= fmtdate($l['stage_entered_at']) ?></span></div>
    </div>
  </div>

  <div class="card pad">
    <div class="card-title">최근 상담 기록</div>
    <?php if ($activities): ?>
      <div class="timeline">
        <?php foreach ($activities as $a): ?>
          <div class="timeline-item <?= e($a['type']) ?>">
            <div class="timeline-time"><?= e(substr((string) $a['created_at'], 0, 16)) ?>
              · <?= e($actLabel[$a['type']] ?? $a['type']) ?><?= $a['user_name'] ? ' · ' . e($a['user_name']) : '' ?></div>
            <div class="timeline-body"><?= e($a['content'] ?: '') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="muted">상담 기록이 없습니다.</div>
    <?php endif; ?>
    <div class="mt-14"><a href="<?= e(url('customers.show', ['id' => (int) $l['customer_id']])) ?>">상담 기록 작성 →</a></div>
  </div>

  <?php if ($l['memo']): ?>
    <div class="card pad">
      <div class="card-title">메모</div>
      <div style="white-space:pre-wrap;font-size:13px"><?= e($l['memo']) ?></div>
    </div>
  <?php endif; ?>
</div>
