<?php
/**
 * 보너스 등록/수정 모달 파셜(R15) — bonus/index.php, halfyear/index.php 공용 include.
 * 등록(new)·수정(edit)·지급처리(pay)·취소(cancel)·삭제(del) 모달 + document 위임 핸들러 일체.
 * row 액션(edit/pay/cancel/del)은 tr[data-bonus] 존재 시에만 동작 — halfyear 화면처럼
 * 해당 tr이 없는 페이지에서는 자연히 no-op(등록만 사용).
 * @var bool  $canManage false면 아무 것도 렌더링하지 않음(호출부에서 include 전 보장 불요)
 * @var array $formUsers 등록/수정 대상 직원 옵션(활성 사용자, id/name)
 * @var array $projects  프로젝트 옵션(id/name)
 * @var array $f         {year, half, ...} — 신규 등록 기본 반기(CUR)
 */
?>
<?php if ($canManage): ?>
<script>
(function () {
  'use strict';
  var USERS = <?= json_encode(array_map(static fn ($u) => ['id' => (int) $u['id'], 'name' => $u['name']], $formUsers), JSON_UNESCAPED_UNICODE) ?>;
  var PROJECTS = <?= json_encode(array_map(static fn ($p) => ['id' => (int) $p['id'], 'name' => $p['name']], $projects), JSON_UNESCAPED_UNICODE) ?>;
  var CUR = { year: <?= (int) $f['year'] ?>, half: <?= (int) $f['half'] ?> };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function selOpts(list, selected, blank) {
    var html = blank ? '<option value="">' + blank + '</option>' : '';
    list.forEach(function (o) {
      html += '<option value="' + o.id + '"' + (String(o.id) === String(selected) ? ' selected' : '') + '>' + esc(o.name) + '</option>';
    });
    return html;
  }
  function reasonField(closed) {
    if (!closed) return '';
    return '<div class="field span2"><label class="field-label">수정 사유 <b class="text-danger">*</b> <span class="muted">(마감 반기)</span></label>' +
      '<input type="text" name="reason" class="input" maxlength="255" placeholder="마감된 반기 데이터를 수정하는 사유" required></div>';
  }

  /**
   * 등록/수정 전체 폼 (R10)
   *  - 신규: 프로젝트 선택 → 배정 직원만 대상 노출(서버 calcInfo) + 일괄 등록 지원
   *  - 수정: 프로젝트·대상 직원 고정(원장 정체성 — 변경 시 삭제 후 재등록)
   *  - 연동 모드(프로젝트 선택)에서는 산정 3필드가 서버 산식 미리보기(읽기 전용),
   *    저장 시 서버가 최신 입금·기여도로 재계산해 저장한다(프론트 값 불신).
   */
  function formHtml(b) {
    b = b || {};
    var isEdit = !!b.id;
    var years = '';
    for (var y = CUR.year + 1; y >= 2020; y--) {
      years += '<option value="' + y + '"' + (y === (b.year || CUR.year) ? ' selected' : '') + '>' + y + '년</option>';
    }
    var projField = isEdit
      ? '<div class="field"><label class="field-label">프로젝트</label><input type="text" class="input" value="' + esc(b.project_name || '(현장 미지정)') + '" disabled>' +
        '<input type="hidden" name="project_id" value="' + (b.project_id || '') + '"></div>'
      : '<div class="field"><label class="field-label">프로젝트 <span class="muted">(먼저 선택)</span></label><select name="project_id" class="select">' + selOpts(PROJECTS, b.project_id, '(현장 미지정 — 수동 입력)') + '</select></div>';
    var userField = isEdit
      ? '<div class="field"><label class="field-label">대상 직원</label><input type="text" class="input" value="' + esc(b.user_name || '') + '" disabled>' +
        '<input type="hidden" name="user_id" value="' + (b.user_id || '') + '"></div>'
      : '<div class="field"><label class="field-label">대상 직원 *</label><select name="user_id" class="select" data-bonus-user required>' + selOpts(USERS, b.user_id, '선택') + '</select>' +
        '<label class="fs-12 muted" style="display:none" data-bonus-bulk-wrap><input type="checkbox" name="all_assignees" value="1"> 전체 배정 직원 일괄 등록(초안)</label></div>';
    return '' +
      '<form data-bonus-form class="form">' +
      '<input type="hidden" name="id" value="' + (b.id || 0) + '">' +
      '<div class="form-grid">' +
      projField + userField +
      '<div class="field span2 muted fs-12" data-bonus-info>' + (isEdit ? '수정 저장 시 서버가 최신 입금·기여도 기준으로 재계산합니다. (지급처리는 재계산하지 않음)' : '프로젝트를 선택하면 계약 금액·누적 입금·총매출·배정 직원이 자동 조회됩니다.') + '</div>' +
      '<div class="field"><label class="field-label">연도</label><select name="year" class="select">' + years + '</select></div>' +
      '<div class="field"><label class="field-label">반기</label><select name="half" class="select">' +
        '<option value="1"' + ((b.half || CUR.half) === 1 ? ' selected' : '') + '>상반기</option>' +
        '<option value="2"' + ((b.half || CUR.half) === 2 ? ' selected' : '') + '>하반기</option></select></div>' +
      '<div class="field"><label class="field-label">총매출(원) <span class="muted">(확정 매출·공급가·VAT 제외)</span></label><input type="text" inputmode="numeric" name="base_amount" class="input" data-bonus-base value="' + (b.base_amount || '') + '"></div>' +
      '<div class="field"><label class="field-label">기여도 반영 매출(원) <span class="muted">(매출 × 기여율/100)</span></label><input type="text" inputmode="numeric" name="contrib_revenue" class="input" data-bonus-contrib value="' + (b.contrib_revenue === 0 || b.contrib_revenue ? b.contrib_revenue : '') + '"></div>' +
      '<div class="field"><label class="field-label">기여도 반영 순이익(원) <span class="muted">(참고 · (매출−지출)×기여율)</span></label><input type="text" inputmode="numeric" name="contrib_profit" class="input input-muted" data-bonus-profit value="' + (b.contrib_profit === 0 || b.contrib_profit ? b.contrib_profit : '') + '" readonly></div>' +
      '<div class="field"><label class="field-label">보너스율(%) <span class="muted">(산정액 = 기여도 반영 매출 × 율/100)</span></label><input type="text" inputmode="decimal" name="bonus_rate" class="input" data-bonus-rate value="' + (b.bonus_rate === 0 || b.bonus_rate ? b.bonus_rate : '') + '" placeholder="예: 5"></div>' +
      '<div class="field"><label class="field-label">산정액(원) <span class="muted">(참고 계산값)</span></label><input type="text" inputmode="numeric" name="calc_amount" class="input input-muted" data-bonus-calc value="' + (b.calc_amount || '') + '" readonly></div>' +
      '<div class="field"><label class="field-label">확정 보너스(원) <b class="text-danger">*</b> <span class="muted">(실제 지급 금액 — 이 금액만 지급)</span></label><input type="text" inputmode="numeric" name="confirmed_bonus" class="input" data-bonus-confirmed value="' + (b.confirmed_bonus === 0 || b.confirmed_bonus ? b.confirmed_bonus : '') + '" placeholder="기본값=산정액"></div>' +
      '<div class="field span2"><label class="field-label">메모</label><input type="text" name="memo" class="input" maxlength="500" value="' + esc(b.memo) + '"></div>' +
      reasonField(b.id && b.closed) +
      '</div>' +
      '<div class="muted fs-12 mt-8" data-bonus-calc-hint></div>' +
      '<div class="muted fs-12 mt-8">산정액은 참고 계산값이며, <b>확정 보너스</b>가 실제 지급 대상 금액입니다. 지급 여부는 목록의 <b>지급처리</b>로 관리합니다(등록 시 미지급).</div>' +
      '<div class="ta-r mt-8"><button type="submit" class="btn btn-primary" data-bonus-submit>저장</button></div>' +
      '</form>';
  }

  /** 지급 처리 폼(부분 전송 — 서버가 나머지 필드 기존 값 유지). 확정 보너스를 '지급완료'로 전환. */
  function payFormHtml(b) {
    var today = new Date().toISOString().slice(0, 10);
    return '' +
      '<form data-bonus-form class="form">' +
      '<input type="hidden" name="id" value="' + b.id + '">' +
      '<input type="hidden" name="pay_status" value="paid">' +
      '<p class="muted fs-13 mt-0">산정액(참고) ' + Number(b.calc_amount || 0).toLocaleString() + '원 · 이 확정 보너스를 <b>지급완료</b>로 처리합니다. 저장된 확정 보너스만 지급됩니다.</p>' +
      '<div class="form-grid">' +
      '<div class="field"><label class="field-label">확정 보너스(원) *</label><input type="text" inputmode="numeric" name="confirmed_bonus" class="input" value="' + (b.confirmed_bonus || b.calc_amount || 0) + '" required></div>' +
      '<div class="field"><label class="field-label">지급일</label><input type="date" name="pay_date" class="input" value="' + esc(b.pay_date || today) + '"></div>' +
      '<div class="field"><label class="field-label">지급 담당자</label><select name="paid_by" class="select">' + selOpts(USERS, b.paid_by, '(미지정)') + '</select></div>' +
      reasonField(b.closed) +
      '</div>' +
      '<div class="ta-r mt-8"><button type="submit" class="btn btn-primary">지급완료 처리</button></div>' +
      '</form>';
  }

  /** 취소/삭제 사유 폼 */
  function reasonFormHtml(b, act) {
    var isDel = act === 'del';
    return '' +
      '<form data-bonus-form data-bonus-act="' + act + '" class="form">' +
      '<input type="hidden" name="id" value="' + b.id + '">' +
      (isDel ? '' : '<input type="hidden" name="pay_status" value="cancelled">') +
      '<p class="muted fs-13 mt-0">' + (isDel
        ? '이 보너스 내역을 삭제합니다. 목록에서 제외되지만 변경 이력(원장)에는 보존됩니다.'
        : '이 보너스를 취소 처리합니다. 합계 집계에서 제외되며 내역은 보존됩니다.') + '</p>' +
      '<div class="field"><label class="field-label">사유' + (b.closed ? ' <b class="text-danger">*</b> <span class="muted">(마감 반기 — 필수)</span>' : ' <span class="muted">(선택)</span>') + '</label>' +
      '<input type="text" name="reason" class="input" maxlength="255"' + (b.closed ? ' required' : '') + '></div>' +
      '<div class="ta-r mt-8"><button type="submit" class="btn btn-danger">' + (isDel ? '삭제' : '취소 처리') + '</button></div>' +
      '</form>';
  }

  /** R10 연동 — 프로젝트 선택 시 서버(bonus.calc)에서 계약금액·누적입금·총매출·배정 직원을 조회해
   *  대상 직원 목록을 배정 직원으로 제한하고 산정 필드를 채운다. 저장 시 서버가 동일 산식으로 재계산. */
  function won(n) { return Number(n || 0).toLocaleString(); }
  function setLinked(form, linked) {
    ['[data-bonus-base]', '[data-bonus-contrib]'].forEach(function (sel) {
      var el = form.querySelector(sel);
      if (el) { el.readOnly = linked; el.classList.toggle('input-muted', linked); }
    });
  }
  function recalcPreview(form) {
    var contribEl = form.querySelector('[data-bonus-contrib]');
    var rateEl = form.querySelector('[data-bonus-rate]');
    var calcEl = form.querySelector('[data-bonus-calc]');
    var confEl = form.querySelector('[data-bonus-confirmed]');
    if (!contribEl || !rateEl || !calcEl) return;
    var rate = parseFloat(String(rateEl.value).replace(/,/g, ''));
    var contrib = parseFloat(String(contribEl.value).replace(/,/g, ''));
    if (!isNaN(rate) && !isNaN(contrib)) {
      var calc = Math.round(contrib * rate / 100); // 서버와 동일: 원 단위 반올림
      calcEl.value = calc;
      // 확정 보너스가 비어있거나 자동값이면 산정액을 기본 제안(관리자가 수정하면 고정)
      if (confEl && (confEl.value === '' || confEl.dataset.auto === '1')) {
        confEl.value = calc;
        confEl.dataset.auto = '1';
      }
    }
  }
  function applyAssignee(form) {
    var d = form._calcData;
    var uidEl = form.querySelector('[data-bonus-user]');
    var hint = form.querySelector('[data-bonus-calc-hint]');
    if (!d || !uidEl) return;
    var uid = uidEl.value;
    var a = (d.assignees || []).filter(function (x) { return String(x.user_id) === String(uid); })[0];
    var contribEl = form.querySelector('[data-bonus-contrib]');
    var profitEl = form.querySelector('[data-bonus-profit]');
    if (a) {
      contribEl.value = a.contrib_revenue;
      if (profitEl) profitEl.value = a.contrib_profit;
      if (hint) hint.textContent = '자동 산정: ' + a.name + ' 기여율 ' + a.pct + '% → 기여도 반영 매출 ' + won(a.contrib_revenue) + '원 · 기여도 반영 순이익 ' + won(a.contrib_profit) + '원. 저장 시 서버가 최신 데이터로 재계산합니다.';
    } else if (uid) {
      contribEl.value = '';
      if (profitEl) profitEl.value = '';
      if (hint) hint.textContent = '이 직원은 배정 목록에 없습니다.';
    }
    recalcPreview(form);
  }
  async function autofillCalc(form) {
    var pidEl = form.querySelector('[name=project_id]');
    var uidEl = form.querySelector('[data-bonus-user]');
    var hint = form.querySelector('[data-bonus-calc-hint]');
    var info = form.querySelector('[data-bonus-info]');
    var bulkWrap = form.querySelector('[data-bonus-bulk-wrap]');
    var submitBtn = form.querySelector('[data-bonus-submit]');
    if (!pidEl || pidEl.tagName !== 'SELECT') return; // 수정·지급처리·사유 폼에는 미적용
    // 프로젝트 변경 → 기존 선택·계산 초기화(§3)
    form._calcData = null;
    ['[data-bonus-base]', '[data-bonus-contrib]', '[data-bonus-calc]'].forEach(function (s) {
      var el = form.querySelector(s); if (el) { el.value = ''; el.readOnly = false; el.classList.remove('input-muted'); }
    });
    if (submitBtn) submitBtn.disabled = false;
    if (!pidEl.value) {
      // 현장 미지정 — 전 직원 수동 입력 모드
      if (uidEl) { uidEl.innerHTML = '<option value="">선택</option>'; USERS.forEach(function (u) { uidEl.innerHTML += '<option value="' + u.id + '">' + esc(u.name) + '</option>'; }); }
      if (info) info.textContent = '현장 미지정 — 산정 값을 직접 입력합니다. (프로젝트 연동 자동 산정을 권장)';
      if (hint) hint.textContent = '';
      if (bulkWrap) bulkWrap.style.display = 'none';
      return;
    }
    try {
      var d = await api('bonus.calc', { project_id: pidEl.value }, { method: 'GET' });
      form._calcData = d;
      if (info) {
        info.innerHTML = '총매출(확정 매출·공급가) <b>' + won(d.base) + '원</b> · 누적 입금(현금) ' + won(d.net_paid) + '원 · 순이익 ' + won(d.profit_base) + '원 · ' +
          '배정 ' + (d.assignees || []).length + '명(기여도 합 ' + d.pct_sum + '%)' +
          (d.is_exception ? ' <span class="muted">— 예외 프로젝트(직접 입금 기준)</span>' : (!d.has_contract ? ' <span class="text-danger">— 계약 미연결: 확정 매출 0원</span>' : '')) +
          (Math.abs(d.pct_sum - 100) > 0.01 ? ' <span class="text-danger">— 기여도 합계가 100%가 아닙니다(기여도 반영 매출 합계 ≠ 총매출)</span>' : '');
      }
      form.querySelector('[data-bonus-base]').value = d.base;
      setLinked(form, true);
      if (uidEl) {
        uidEl.innerHTML = '<option value="">선택</option>';
        (d.assignees || []).forEach(function (a) {
          uidEl.innerHTML += '<option value="' + a.user_id + '"' + (a.user_status !== 'active' ? ' disabled' : '') + '>' +
            esc(a.name) + ' (기여도 ' + a.pct + '%' + (a.user_status !== 'active' ? ' · 비활성' : '') + ')</option>';
        });
        if (!(d.assignees || []).length) {
          if (hint) hint.textContent = '배정 직원이 없는 프로젝트입니다 — 보너스를 등록할 수 없습니다. 프로젝트 상세에서 직원을 먼저 배정하세요.';
          if (submitBtn) submitBtn.disabled = true;
          if (bulkWrap) bulkWrap.style.display = 'none';
          return;
        }
      }
      if (bulkWrap) bulkWrap.style.display = '';
      if (hint) hint.textContent = '대상 직원을 선택하거나 "전체 배정 직원 일괄 등록"을 체크하세요.';
    } catch (err) {
      if (hint) hint.textContent = '연동 산정 조회 실패: ' + err.message;
    }
  }
  document.addEventListener('change', function (e) {
    var form = e.target.closest('form[data-bonus-form]');
    if (!form) return;
    if (e.target.matches('[name=project_id]')) autofillCalc(form);
    if (e.target.matches('[data-bonus-user]')) applyAssignee(form);
    if (e.target.matches('[name=all_assignees]')) {
      var uidEl = form.querySelector('[data-bonus-user]');
      var on = e.target.checked;
      if (uidEl) { uidEl.disabled = on; uidEl.required = !on; }
      var hint = form.querySelector('[data-bonus-calc-hint]');
      var d = form._calcData;
      if (on && d && hint) {
        var lines = (d.assignees || []).filter(function (a) { return a.pct > 0 && a.user_status === 'active'; })
          .map(function (a) { return a.name + ' ' + a.pct + '% → ' + won(a.contrib_revenue) + '원'; });
        hint.textContent = '일괄 등록 대상(기여도>0·활성): ' + (lines.join(' · ') || '없음') + ' — 산정 금액은 보너스율 적용 후 서버가 계산합니다.';
      } else if (hint) { hint.textContent = ''; }
    }
  });
  document.addEventListener('input', function (e) {
    var form = e.target.closest('form[data-bonus-form]');
    if (!form) return;
    if (e.target.matches('[data-bonus-rate],[data-bonus-contrib]')) recalcPreview(form);
    // 확정 보너스를 직접 수정하면 자동 제안 해제(이후 산정액 변경이 덮어쓰지 않음)
    if (e.target.matches('[data-bonus-confirmed]')) e.target.dataset.auto = '0';
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-bact]');
    if (!btn) return;
    var act = btn.dataset.bact;
    if (act === 'new') {
      EDEN.modal({ title: '보너스 등록', body: formHtml({ year: CUR.year, half: CUR.half }), footer: false, wide: true });
      return;
    }
    var row = btn.closest('tr[data-bonus]');
    if (!row) return;
    var b = JSON.parse(row.dataset.bonus);
    if (act === 'edit') EDEN.modal({ title: '보너스 수정', body: formHtml(b), footer: false, wide: true });
    if (act === 'pay') EDEN.modal({ title: '지급 처리', body: payFormHtml(b), footer: false });
    if (act === 'cancel') EDEN.modal({ title: '보너스 취소', body: reasonFormHtml(b, 'cancel'), footer: false });
    if (act === 'del') EDEN.modal({ title: '보너스 삭제', body: reasonFormHtml(b, 'del'), footer: false });
  });

  document.addEventListener('submit', async function (e) {
    var form = e.target;
    if (!form.matches('form[data-bonus-form]')) return;
    e.preventDefault();
    var route = form.dataset.bonusAct === 'del' ? 'bonus.delete' : 'bonus.save';
    var btn = form.querySelector('[type="submit"]');
    if (btn) btn.disabled = true;
    try {
      var fd = new FormData(form);
      var res = await api(route, fd);
      // R10: 중복 산정 경고 — 관리자 확인 후 재제출(confirm_dup)
      if (res && res.dup_warning) {
        if (btn) btn.disabled = false;
        if (!window.confirm(res.message + '\n\n그래도 등록하시겠습니까?')) return;
        fd.set('confirm_dup', '1');
        if (btn) btn.disabled = true;
        res = await api(route, fd);
      }
      toast(res && res.count ? res.count + '건 일괄 등록되었습니다.' : '저장되었습니다.', 'success');
      location.reload();
    } catch (err) {
      toast(err.message, 'error');
      if (btn) btn.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>
