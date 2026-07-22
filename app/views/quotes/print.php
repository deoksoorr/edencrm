<?php /** @var array $quote @var array $items @var ?array $version @var string $companyName */ ?>
<style>
  .print-wrap{max-width:820px;margin:24px auto;background:#fff;padding:40px 44px;border:1px solid var(--line, #e5e7eb)}
  .print-toolbar{max-width:820px;margin:0 auto 12px;text-align:right}
  .print-title{text-align:center;font-size:24px;font-weight:800;letter-spacing:4px;margin-bottom:26px}
  .print-meta{display:flex;justify-content:space-between;margin-bottom:18px;font-size:13px}
  .print-meta div{line-height:1.7}
  table.print-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-bottom:20px}
  table.print-table th,table.print-table td{border:1px solid #d1d5db;padding:8px 9px}
  table.print-table th{background:#f4f6f8}
  table.print-table td.num,table.print-table th.num{text-align:right}
  .print-total{width:280px;margin-left:auto;font-size:13.5px}
  .print-total div{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #eee}
  .print-total .grand{font-weight:800;font-size:16px;border-bottom:none;border-top:2px solid #111;margin-top:4px;padding-top:8px}
  @media print{ .print-toolbar{display:none} body{background:#fff} .print-wrap{border:none;margin:0;padding:0} }
</style>

<div class="print-toolbar">
  <button type="button" class="btn btn-primary" onclick="window.print()">인쇄 / PDF 저장</button>
</div>

<div class="print-wrap">
  <div class="print-title"><?= e($companyName) ?> 견적서</div>
  <div class="print-meta">
    <div>
      <strong>견적번호</strong> <?= e($quote['quote_no']) ?><br>
      <strong>고객명</strong> <?= e($quote['customer_name']) ?><br>
      <?php if (!empty($quote['customer_phone'])): ?><strong>연락처</strong> <?= e($quote['customer_phone']) ?><br><?php endif; ?>
      <?php if (!empty($quote['customer_site_address'] ?? $quote['customer_address'])): ?><strong>현장주소</strong> <?= e($quote['customer_site_address'] ?? $quote['customer_address']) ?><br><?php endif; ?>
    </div>
    <div style="text-align:right">
      <strong>작성일</strong> <?= fmtdate($quote['created_at']) ?><br>
      <strong>유효기간</strong> <?= fmtdate($quote['valid_until']) ?><br>
    </div>
  </div>

  <table class="print-table">
    <thead>
      <tr><th>항목명</th><th class="num">면적(㎡)</th><th class="num">수량</th><th class="num">단가</th><th class="num">금액</th></tr>
    </thead>
    <tbody>
      <?php if (!$items): ?>
        <tr><td colspan="5" style="text-align:center;color:#999">등록된 항목이 없습니다.</td></tr>
      <?php endif; ?>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['name']) ?></td>
          <td class="num"><?= $it['area'] !== null ? number_format((float) $it['area'], 2) : '-' ?></td>
          <td class="num"><?= number_format((float) $it['qty'], 2) ?></td>
          <td class="num"><?= money($it['unit_price']) ?></td>
          <td class="num"><?= money($it['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($version): ?>
    <div class="print-total">
      <div><span>공급가액</span><span><?= money($version['subtotal']) ?> 원</span></div>
      <div><span>부가세</span><span><?= money($version['vat']) ?> 원</span></div>
      <div><span>할인</span><span><?= money($version['discount']) ?> 원</span></div>
      <div class="grand"><span>합계</span><span><?= money($version['total_amount']) ?> 원</span></div>
    </div>
  <?php endif; ?>

  <?php if (!empty($quote['memo'])): ?>
    <div style="margin-top:26px;font-size:12.5px"><strong>특이사항</strong><br><span style="white-space:pre-wrap"><?= e($quote['memo']) ?></span></div>
  <?php endif; ?>
</div>
