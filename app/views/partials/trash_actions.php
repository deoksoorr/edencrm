<?php
/**
 * 공통 휴지통 행 액션 파셜 (T7) — 복원 + 완전삭제 버튼.
 *
 * 고객·영업기회·견적·계약·프로젝트 5개 목록의 휴지통 모드 액션 칸이 라우트/문구만 다르고
 * 마크업·권한 조건(복원=trash.manage 라우트, 완전삭제=super_admin)이 완전히 동일해 하나로 묶는다.
 * 완전삭제 2단계 확인은 public/assets/js/purge-confirm.js 가 [data-purge] 폼에 자동 배선한다.
 * 서버(Perm::requireSuperAdmin + CSRF)가 최종 권한을 판정하므로 이 표시는 오조작 방지용이다.
 *
 * 사용:
 *   View::partial('partials/trash_actions', [
 *       'id'      => (int) $r['id'],
 *       'restore' => 'quotes.restore',           // 복원 라우트 키
 *       'purge'   => 'quotes.purge',             // 완전삭제 라우트 키
 *       'kind'    => '견적',                      // 확인문구의 대상 종류
 *       'label'   => $r['quote_no'],             // 확인문구·재입력 대상 이름(빈 값이면 #id)
 *       'scope'   => '견적 버전·견적 항목',        // 함께 삭제되는 연관 데이터 안내
 *   ]);
 *
 * @var int    $id
 * @var string $restore
 * @var string $purge
 * @var string $kind
 * @var string $label
 * @var string $scope
 */
$taLabel = (string) ($label ?? '');
if ($taLabel === '') {
    $taLabel = '#' . (int) $id;
}
?>
<form method="post" action="<?= e(url($restore)) ?>" class="inline-form"><?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <button type="submit" class="btn btn-sm btn-outline">복원</button></form>
<?php if (is_role('super_admin')): ?>
<form method="post" action="<?= e(url($purge)) ?>" class="inline-form"
      data-purge data-purge-kind="<?= e((string) $kind) ?>"
      data-purge-label="<?= e($taLabel) ?>"
      data-purge-scope="<?= e((string) ($scope ?? '')) ?>"><?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <button type="submit" class="btn btn-sm btn-danger">완전삭제</button></form>
<?php endif; ?>
