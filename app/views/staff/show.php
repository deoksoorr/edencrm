<?php
/** @var array $staff @var array $projects @var int $projectCount @var bool $canViewPerf */
$statusLabels = ['active' => '재직', 'inactive' => '비활성'];
$roleLabels = ['super_admin' => '슈퍼관리자', 'sales_manager' => '영업관리자', 'site_manager' => '현장관리자', 'staff' => '직원', 'accountant' => '회계'];
$projStatusLabels = ['preparing' => '준비중', 'in_progress' => '진행중', 'paused' => '중지', 'completed' => '완료'];
?>
<div class="page">
  <div class="page-head">
    <div>
      <a href="<?= e(url('staff.index')) ?>" class="muted" style="font-size:12.5px">&larr; 직원 목록</a>
      <div class="detail-title" style="margin-top:4px"><?= e($staff['name']) ?></div>
      <div class="detail-meta">
        <?= e($roleLabels[$staff['role_key']] ?? $staff['role_key']) ?> · <?= e($staff['department_name'] ?? '부서 미지정') ?> · <?= e($staff['position'] ?? '-') ?>
        <span class="badge <?= $staff['status'] === 'active' ? 'badge-ok' : 'badge-danger' ?>" style="margin-left:8px"><?= e($statusLabels[$staff['status']] ?? $staff['status']) ?></span>
        <?php if ((int) $staff['must_change_password'] === 1): ?><span class="badge badge-warn" style="margin-left:4px">비밀번호 변경 필요</span><?php endif; ?>
      </div>
    </div>
    <div class="page-actions">
      <?php if ($canViewPerf): ?>
        <a href="<?= e(url('performance.user', ['id' => $staff['id']])) ?>" class="btn btn-outline">성과 보기</a>
      <?php endif; ?>
      <?php if (can('staff.manage')): ?>
        <a href="<?= e(url('staff.form', ['id' => $staff['id']])) ?>" class="btn btn-primary">정보 수정</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div class="card-title">기본 정보</div></div>
    <div class="card-body">
      <dl class="dl">
        <dt>아이디</dt><dd><?= e($staff['login_id']) ?></dd>
        <dt>이메일</dt><dd><?= e($staff['email']) ?></dd>
        <dt>연락처</dt><dd><?= e($staff['phone'] ?? '-') ?></dd>
        <dt>입사일</dt><dd><?= fmtdate($staff['hire_date'] ?? null) ?></dd>
        <dt>마지막 로그인</dt><dd><?= $staff['last_login_at'] ? e(fmtdate($staff['last_login_at'], 'Y-m-d H:i')) : '-' ?></dd>
        <dt>메모</dt><dd><?= nl2br(e($staff['memo'] ?? '-')) ?></dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-title">담당 프로젝트 요약</div>
      <div class="muted" style="font-size:12.5px">총 <?= (int) $projectCount ?>건 (최근 10건 표시)</div>
    </div>
    <?php if (!$projects): ?>
      <div class="card-body"><div class="empty" style="padding:24px 0"><div class="empty-title">담당 중인 프로젝트가 없습니다.</div></div></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>프로젝트번호</th><th>이름</th><th>상태</th><th class="num">계약금액</th><th>진행률</th></tr></thead>
        <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td><?= e($p['project_no']) ?></td>
              <td><?= e($p['name']) ?></td>
              <td><span class="badge badge-info"><?= e($projStatusLabels[$p['status']] ?? $p['status']) ?></span></td>
              <td class="num"><?= money((float) $p['contract_amount']) ?></td>
              <td>
                <div class="progress"><div class="progress-bar <?= $p['progress'] >= 100 ? 'ok' : '' ?>" style="width:<?= (int) $p['progress'] ?>%"></div></div>
                <div class="progress-label"><?= (int) $p['progress'] ?>%</div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
