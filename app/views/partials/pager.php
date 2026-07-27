<?php
/**
 * 공통 페이지네이션 파셜 (r4-designsystem T5).
 * 화면마다 달랐던 표기(건수 유무·'총' 접두·이전/다음 유무·현재 페이지 강조)를 단일 형식으로 통일:
 *   "from-to / total건" + 이전 · 페이지 번호(현재=.cur) · 다음
 *
 * 사용:
 *   View::partial('partials/pager', [
 *       'pg'  => $pg,                       // ['page'=>int,'pages'=>int,'from'=>int,'to'=>int,'total'=>int]
 *       'url' => fn (int $p): string => url('xxx.index', $params + ['page' => $p]),
 *   ]);
 * pages<=1 이면 아무것도 출력하지 않는다(각 화면의 기존 동작과 동일).
 *
 * @var array    $pg
 * @var callable $url
 */
$pgPage  = (int) ($pg['page'] ?? 1);
$pgPages = (int) ($pg['pages'] ?? 1);
if ($pgPages <= 1) {
    return;
}
?>
<div class="pagination">
  <span class="page-info"><?= number_format((int) $pg['from']) ?>-<?= number_format((int) $pg['to']) ?> / <?= number_format((int) $pg['total']) ?>건</span>
  <?php if ($pgPage > 1): ?>
    <a href="<?= e($url($pgPage - 1)) ?>">이전</a>
  <?php else: ?>
    <span class="disabled">이전</span>
  <?php endif; ?>
  <?php for ($i = 1; $i <= $pgPages; $i++): ?>
    <a class="<?= $i === $pgPage ? 'cur' : '' ?>" href="<?= e($url($i)) ?>"><?= $i ?></a>
  <?php endfor; ?>
  <?php if ($pgPage < $pgPages): ?>
    <a href="<?= e($url($pgPage + 1)) ?>">다음</a>
  <?php else: ?>
    <span class="disabled">다음</span>
  <?php endif; ?>
</div>
