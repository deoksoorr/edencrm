/**
 * DR 테스트 T8 — 복구본 핵심 업무 흐름 + 휴지통 수명주기.
 *
 * 두 가지를 분리해서 본다.
 *  (1) 복구된 **운영 데이터**는 읽기만 한다 — 조회가 되고 관계가 살아있는지.
 *  (2) 생성·수정·삭제는 전부 QARESTORE- 표식 데이터로만 한다 — 복구된 실데이터를
 *      건드리지 않고도 쓰기 경로를 검증할 수 있다.
 *
 * 휴지통은 "삭제되더라"가 아니라 수명주기 전체(소프트삭제 → 목록 노출 → 권한 차단
 * → 복원 → 재삭제 → 완전삭제 → 감사로그)를 한 바퀴 돌린다.
 */
const L = require('./lib_dr.js');
const {
  Client, chk, pass, fail, warn, info, section, saveResults,
  pageErrors, errorDetails, flashOf, sql, sql1, sqlRows, strip, P, QA,
} = L;

const CRED = {
  admin: ['admin', 'QArestore!2026admin'],
  staff: ['hghg', 'QArestore!2026staff'],
};
const created = { customers: [], leads: [], quotes: [], contracts: [], projects: [], payments: [], costs: [], schedules: [] };

/**
 * 복구본 실데이터를 건드리지 않았는지 확인하기 위한 스냅샷.
 * 계약번호·프로젝트명은 서버가 채번/승계하므로 QA 접두어로 걸러지지 않는다 —
 * 이 테스트가 만든 id 를 명시적으로 제외해야 "실데이터 불변"을 정확히 잴 수 있다.
 */
function notIn(ids) { return ids.length ? `id NOT IN (${ids.join(',')})` : '1=1'; }
function realDataSnapshot() {
  return {
    customers: sql1(`SELECT COUNT(*) FROM \`${P}customers\` WHERE ${notIn(created.customers)}`),
    contracts: sql1(`SELECT COUNT(*) FROM \`${P}contracts\` WHERE ${notIn(created.contracts)}`),
    projects: sql1(`SELECT COUNT(*) FROM \`${P}projects\` WHERE ${notIn(created.projects)}`),
    payments: sql1(`SELECT COALESCE(SUM(CASE WHEN kind='refund' THEN -amount ELSE amount END),0)
                      FROM \`${P}payments\` WHERE status='paid' AND ${notIn(created.payments)}`),
    audit: sql1(`SELECT COUNT(*) FROM \`${P}audit_logs\``),
  };
}

(async () => {
  const admin = new Client('admin');
  await admin.login(...CRED.admin);
  const staff = new Client('staff');
  await staff.login(...CRED.staff);

  const before = realDataSnapshot();
  info('시작 시점 실데이터', JSON.stringify(before));

  // ══ 1. 복구된 운영 데이터 조회 (읽기 전용) ═══════════════════════════════
  section('복구 데이터 조회');
  const READ_PAGES = [
    ['고객 목록', 'customers.index', {}],
    ['영업기회 목록', 'pipeline.index', {}],
    ['견적 목록', 'quotes.index', {}],
    ['계약 목록', 'contracts.index', {}],
    ['프로젝트 목록', 'projects.index', {}],
    ['공정 보드', 'process.board', {}],
    ['일정', 'schedule.index', {}],
    ['대시보드', 'dashboard', {}],
    ['분석', 'reports.index', {}],
    ['성과', 'performance.index', {}],
    ['감사로그', 'audit.index', {}],
  ];
  for (const [label, route, params] of READ_PAGES) {
    const r = await admin.get(route, params);
    const e = pageErrors(r.body);
    chk(`${label} 조회`, r.status === 200 && e.fatal.length === 0,
      `HTTP ${r.status}${e.fatal.length ? ' 치명:' + e.fatal.join(',') : ''}`);
    if (e.soft.length) {
      warn(`${label} 경고`, errorDetails(r.body).map((x) => `${x.file}:${x.line} ${x.msg}`).join(' | '));
    }
  }

  // 상세 화면 — 관계(고객→견적→계약→프로젝트)가 살아있는지
  section('데이터 관계');
  const cust = sqlRows(`SELECT id, name FROM \`${P}customers\` WHERE deleted_at IS NULL ORDER BY id LIMIT 1`)[0];
  if (cust) {
    const r = await admin.get('customers.show', { id: cust.id });
    chk('고객 상세 조회', r.status === 200 && pageErrors(r.body).fatal.length === 0, `id=${cust.id} HTTP ${r.status}`);
    chk('고객명 렌더링', strip(r.body).includes(cust.name), `"${cust.name}" 표시`);
  }
  const ctr = sqlRows(`SELECT id, contract_no, customer_id, quote_id FROM \`${P}contracts\` WHERE deleted_at IS NULL ORDER BY id LIMIT 1`)[0];
  if (ctr) {
    const r = await admin.get('contracts.show', { id: ctr.id });
    chk('계약 상세 조회', r.status === 200 && pageErrors(r.body).fatal.length === 0, `${ctr.contract_no} HTTP ${r.status}`);
    chk('계약→고객 연결 유지', String(ctr.customer_id) !== '', `customer_id=${ctr.customer_id}`);
  }
  const prj = sqlRows(`SELECT id, name, contract_id FROM \`${P}projects\` WHERE deleted_at IS NULL ORDER BY id LIMIT 1`)[0];
  if (prj) {
    const r = await admin.get('projects.show', { id: prj.id });
    chk('프로젝트 상세 조회', r.status === 200 && pageErrors(r.body).fatal.length === 0, `id=${prj.id} HTTP ${r.status}`);
  }

  // ══ 2. 대시보드·분석 집계가 DB 원본과 일치하는가 ═══════════════════════
  section('집계 정합성');
  const dashData = await admin.getJson('dashboard.data');
  if (dashData.json) {
    info('대시보드 API', `키: ${Object.keys(dashData.json).slice(0, 8).join(',')}`);
    chk('대시보드 데이터 API 200', dashData.status === 200, `HTTP ${dashData.status}`);
  } else {
    const r = await admin.get('dashboard');
    chk('대시보드 렌더', r.status === 200, `HTTP ${r.status} (JSON API 미제공)`);
  }
  // 순입금은 회계 규칙(ACCOUNTING_RULES §2)대로 paid 만, 환불 차감
  const netPaid = sql1(`SELECT COALESCE(SUM(CASE WHEN kind='refund' THEN -amount ELSE amount END),0)
                          FROM \`${P}payments\` WHERE status='paid'`);
  info('DB 순입금(paid)', Number(netPaid).toLocaleString());
  const rep = await admin.get('reports.index');
  chk('분석 페이지 집계 렌더', rep.status === 200 && pageErrors(rep.body).fatal.length === 0, `HTTP ${rep.status}`);

  // 삭제 데이터가 활성 목록에 새지 않는가 — 복구본에서 가장 놓치기 쉬운 지점
  const trashedCust = sqlRows(`SELECT id, name FROM \`${P}customers\` WHERE deleted_at IS NOT NULL LIMIT 3`);
  if (trashedCust.length) {
    const list = await admin.get('customers.index');
    const leaked = trashedCust.filter((c) => strip(list.body).includes(c.name));
    chk('삭제 고객이 활성 목록에 미노출', leaked.length === 0,
      leaked.length ? `누출: ${leaked.map((c) => c.name).join(',')}` : `휴지통 ${trashedCust.length}건 모두 비노출`);
  }

  // ══ 3. QA 데이터로 쓰기 경로 검증 ═══════════════════════════════════════
  // 필드명은 복구본 컨트롤러의 실제 검증 코드에서 확인한 것만 쓴다(추측 금지).
  section('쓰기: 고객');
  const custName = `${QA}고객A`;
  let r = await admin.post('customers.save', {
    name: custName, type: 'individual', phone: '010-0000-0001',
    status: 'active', privacy_agreed: '1',        // 개인정보 동의 없으면 422
  });
  const newCustId = sql1(`SELECT id FROM \`${P}customers\` WHERE name='${custName}' ORDER BY id DESC LIMIT 1`);
  chk('QA 고객 등록', !!newCustId, `id=${newCustId} (HTTP ${r.status})`);
  if (newCustId) created.customers.push(newCustId);

  if (newCustId) {
    r = await admin.post('customers.save', {
      id: newCustId, name: `${QA}고객A-수정`, type: 'individual',
      phone: '010-0000-0002', status: 'active', privacy_agreed: '1',
    });
    const nm = sql1(`SELECT name FROM \`${P}customers\` WHERE id=${newCustId}`);
    chk('QA 고객 수정', nm === `${QA}고객A-수정`, `name=${nm}`);
    const search = await admin.get('customers.index', { q: QA });
    chk('고객 검색 반영', strip(search.body).includes(`${QA}고객A-수정`), '검색 결과에 노출');
  }

  // ── 영업기회 ── (leads 에는 title 컬럼이 없다 — 고객·단계·금액으로 식별)
  section('쓰기: 영업기회');
  let leadId = null;
  if (newCustId) {
    r = await admin.post('pipeline.save', {
      customer_id: newCustId, stage_id: '1', expected_amount: '5000000',
      work_type: 'painting', site_address: `${QA}현장`, memo: `${QA}영업기회A`,
    });
    leadId = sql1(`SELECT id FROM \`${P}leads\` WHERE customer_id=${newCustId} ORDER BY id DESC LIMIT 1`);
    chk('QA 영업기회 생성', !!leadId, `id=${leadId} (HTTP ${r.status})`);
    if (leadId) {
      created.leads.push(leadId);
      const row = sqlRows(`SELECT customer_id, stage_id FROM \`${P}leads\` WHERE id=${leadId}`)[0];
      chk('영업기회→고객 연결', String(row.customer_id) === String(newCustId), `customer_id=${row.customer_id}`);
      // 상태(단계) 변경
      r = await admin.post('pipeline.save', {
        id: leadId, customer_id: newCustId, stage_id: '5', expected_amount: '5000000',
        work_type: 'painting', memo: `${QA}영업기회A`,
      });
      const st = sql1(`SELECT stage_id FROM \`${P}leads\` WHERE id=${leadId}`);
      chk('영업기회 단계 변경', String(st) === '5', `stage_id 1 → ${st}`);
    }
  }

  // ── 견적 ── (items 는 배열로 POST, 항목명이 비면 무시된다)
  section('쓰기: 견적');
  let quoteId = null;
  if (newCustId) {
    r = await admin.post('quotes.save', {
      customer_id: newCustId, lead_id: leadId || '', status: 'draft',
      valid_until: '2026-08-31', memo: `${QA}견적A`, discount: '0',
      'items[0][name]': `${QA}항목1`, 'items[0][qty]': '2', 'items[0][unit_price]': '1000000',
      'items[1][name]': `${QA}항목2`, 'items[1][qty]': '1', 'items[1][unit_price]': '3000000',
    });
    quoteId = sql1(`SELECT id FROM \`${P}quotes\` WHERE customer_id=${newCustId} ORDER BY id DESC LIMIT 1`);
    chk('QA 견적 생성', !!quoteId, `id=${quoteId} (HTTP ${r.status})`);
    if (quoteId) {
      created.quotes.push(quoteId);
      const items = sqlRows(`SELECT qi.name, qi.amount FROM \`${P}quote_items\` qi
                               JOIN \`${P}quote_versions\` qv ON qv.id=qi.quote_version_id
                              WHERE qv.quote_id=${quoteId}`);
      chk('견적 항목 저장', items.length === 2, `${items.length}건: ${items.map((i) => i.name).join(',')}`);
      // 총액 = 2×1,000,000 + 1×3,000,000 = 5,000,000
      const total = items.reduce((a, i) => a + Number(i.amount), 0);
      chk('견적 항목 합계 계산', total === 5000000, `합계 ${total.toLocaleString()}`);
      const show = await admin.get('quotes.show', { id: quoteId });
      chk('견적 상세 조회', show.status === 200 && pageErrors(show.body).fatal.length === 0, `HTTP ${show.status}`);
    }
  }

  // ── 계약 ── (contract_no 는 서버가 채번한다)
  section('쓰기: 계약');
  let contractId = null;
  if (newCustId) {
    r = await admin.post('contracts.save', {
      customer_id: newCustId, quote_id: quoteId || '',
      contract_date: '2026-07-29', contract_amount: '11000000',
      // 분할은 비율(합계 100)과 금액을 **둘 다** 보내야 하며 서로 일치해야 한다.
      // 앱이 "분할 금액이 비율 계산 결과와 일치하지 않습니다"로 교차검증한다.
      // 11,000,000 × (10 / 0 / 90)% = 1,100,000 / 0 / 9,900,000
      down_pct: '10', middle_pct: '0', balance_pct: '90',
      down_payment: '1100000', middle_payment: '0', balance_payment: '9900000',
      status: 'active', work_name: `${QA}공사A`, work_type: 'painting',
      construction_type: 'painting', site_address: `${QA}현장`, memo: `${QA}계약A`,
    });
    contractId = sql1(`SELECT id FROM \`${P}contracts\` WHERE customer_id=${newCustId} ORDER BY id DESC LIMIT 1`);
    if (!contractId) {
      // 302 만 보고 원인을 추측하지 않는다 — 앱이 남긴 플래시를 그대로 읽는다.
      const back = await admin.get('contracts.form');
      const f = flashOf(back.body);
      fail('QA 계약 생성', `HTTP ${r.status} · 앱 메시지: ${f ? f.msg : '(플래시 없음)'}`);
    } else {
      pass('QA 계약 생성', `id=${contractId} (HTTP ${r.status})`);
    }
    if (contractId) {
      created.contracts.push(contractId);
      const row = sqlRows(`SELECT contract_no, contract_amount, supply_amount, vat_amount, payment_status, quote_id
                             FROM \`${P}contracts\` WHERE id=${contractId}`)[0];
      info('채번된 계약번호', row.contract_no);
      // 회계: 확정매출은 공급가(VAT 제외) 기준 — 저장 시 공급가·부가세가 분해돼야 한다
      const supply = Number(row.supply_amount || 0);
      const vat = Number(row.vat_amount || 0);
      chk('계약 공급가·VAT 분해', supply > 0 && supply + vat === 11000000,
        `총액 11,000,000 = 공급가 ${supply.toLocaleString()} + VAT ${vat.toLocaleString()}`);
      // 분할 합계가 총액과 정확히 일치해야 한다(반올림 보정 포함)
      const sp = sqlRows(`SELECT down_payment, middle_payment, balance_payment FROM \`${P}contracts\` WHERE id=${contractId}`)[0];
      const splitSum = Number(sp.down_payment) + Number(sp.middle_payment) + Number(sp.balance_payment);
      chk('분할 지급 합계 = 계약총액', splitSum === 11000000,
        `${Number(sp.down_payment).toLocaleString()} + ${Number(sp.middle_payment).toLocaleString()} + ${Number(sp.balance_payment).toLocaleString()} = ${splitSum.toLocaleString()}`);
      chk('계약 초기 입금상태', row.payment_status === 'unpaid', `payment_status=${row.payment_status}`);
      if (quoteId) chk('계약→견적 연결', String(row.quote_id) === String(quoteId), `quote_id=${row.quote_id}`);
    }
  }

  // ── 입금 ──
  section('쓰기: 입금');
  if (contractId) {
    const netOf = () => Number(sql1(`SELECT COALESCE(SUM(CASE WHEN kind='refund' THEN -amount ELSE amount END),0)
                                       FROM \`${P}payments\` WHERE status='paid' AND contract_id=${contractId}`));
    const beforeNet = netOf();
    r = await admin.post('payments.save', {
      contract_id: contractId, pay_type: 'down', amount: '1100000',
      // status 를 안 보내면 기본값이 pending 이라 순입금(paid 기준)에 잡히지 않는다.
      status: 'paid', paid_date: '2026-07-29',
      method: 'transfer', payer_name: `${QA}입금자`, memo: `${QA}입금1`,
    });
    const afterNet = netOf();
    chk('QA 입금 등록', afterNet === beforeNet + 1100000,
      `순입금 ${beforeNet.toLocaleString()} → ${afterNet.toLocaleString()} (HTTP ${r.status})`);
    created.payments.push(...sqlRows(`SELECT id FROM \`${P}payments\` WHERE contract_id=${contractId}`).map((x) => x.id));

    // 계약 입금상태가 집계에 반영되는가
    const ps = sql1(`SELECT payment_status FROM \`${P}contracts\` WHERE id=${contractId}`);
    chk('입금 후 계약 상태 갱신', ps === 'partial' || ps === 'paid', `payment_status=${ps}`);

    // 미수금 = 계약총액 − 순입금 (ACCOUNTING_RULES §2)
    const recv = 11000000 - afterNet;
    chk('미수금 산출', recv === 9900000, `11,000,000 − ${afterNet.toLocaleString()} = ${recv.toLocaleString()}`);
  }

  // ── 프로젝트 ──
  section('쓰기: 프로젝트');
  let projectId = null;
  if (contractId) {
    r = await admin.post('contracts.toproject', { id: contractId }, { json: true });
    projectId = sql1(`SELECT id FROM \`${P}projects\` WHERE contract_id=${contractId} ORDER BY id DESC LIMIT 1`);
    chk('계약→프로젝트 전환', !!projectId, `project id=${projectId} (HTTP ${r.status})`);
    if (projectId) {
      created.projects.push(projectId);
      const pr = sqlRows(`SELECT name, contract_amount, status, process_stage_id FROM \`${P}projects\` WHERE id=${projectId}`)[0];
      chk('프로젝트 계약총액 승계', String(pr.contract_amount) === '11000000', `contract_amount=${pr.contract_amount}`);
      info('프로젝트 초기 상태', `name=${pr.name} · status=${pr.status} · stage=${pr.process_stage_id}`);
      const show = await admin.get('projects.show', { id: projectId });
      chk('프로젝트 상세 조회', show.status === 200 && pageErrors(show.body).fatal.length === 0, `HTTP ${show.status}`);
    }
  }

  // ── 지출 ──
  section('쓰기: 지출');
  if (projectId) {
    r = await admin.post('costs.save', {
      project_id: projectId, type: 'actual', cost_status: 'confirmed',
      category: 'material', item_name: `${QA}자재1`, amount: '500000', spent_date: '2026-07-29',
    });
    const costId = sql1(`SELECT id FROM \`${P}costs\` WHERE project_id=${projectId} ORDER BY id DESC LIMIT 1`);
    chk('QA 지출 등록', !!costId, `id=${costId} (HTTP ${r.status})`);
    if (costId) {
      created.costs.push(costId);
      const sum = sql1(`SELECT COALESCE(SUM(amount),0) FROM \`${P}costs\` WHERE project_id=${projectId} AND cost_status='confirmed'`);
      chk('확정 지출 집계 반영', String(sum) === '500000', `확정지출 합 ${Number(sum).toLocaleString()}`);
    }
  }

  // ── 공정 보드 ──
  section('공정 보드');
  const board = await admin.get('process.board');
  chk('공정 보드 조회', board.status === 200 && pageErrors(board.body).fatal.length === 0, `HTTP ${board.status}`);
  const stages = sqlRows(`SELECT id, name, sort_order, process_type, is_active FROM \`${P}process_stages\` ORDER BY process_type, sort_order`);
  const painting = stages.filter((s) => s.process_type === 'painting');
  info('복구된 공정 단계', `총 ${stages.length}개 (도장 ${painting.length}개): ${painting.map((s) => s.name).slice(0, 6).join(' › ')}…`);
  chk('공정 단계 데이터 복구', stages.length > 0, `${stages.length}단계`);
  if (projectId && painting.length > 1) {
    const target = painting[1];
    r = await admin.post('process.progress.set', { project_id: projectId, stage_id: target.id, pct: '50' }, { json: true });
    const pct = sql1(`SELECT pct FROM \`${P}project_stage_progress\` WHERE project_id=${projectId} AND stage_id=${target.id}`);
    chk('공정 진행률 변경', String(pct) === '50', `${target.name} = ${pct}% (HTTP ${r.status})`);
    const hist = sql1(`SELECT COUNT(*) FROM \`${P}project_process_history\` WHERE project_id=${projectId}`);
    info('공정 이력 기록', `${hist}건`);
  }

  // ── 일정 ──
  section('일정');
  if (projectId) {
    r = await admin.post('schedule.save', {
      project_id: projectId, title: `${QA}일정A`,
      event_date: '2026-07-30', end_date: '2026-07-30',
      slots: 'am', type: 'work', status: 'scheduled',
      participant_ids: '1', memo: `${QA}일정`,
    });
    const schId = sql1(`SELECT id FROM \`${P}schedules\` WHERE title='${QA}일정A' ORDER BY id DESC LIMIT 1`);
    chk('QA 일정 등록', !!schId, `id=${schId} (HTTP ${r.status})`);
    if (schId) {
      created.schedules.push(schId);
      const parts = sql1(`SELECT COUNT(*) FROM \`${P}schedule_participants\` WHERE schedule_id=${schId}`);
      chk('일정 참여자 연결', Number(parts) >= 1, `참여자 ${parts}명`);
      const slots = sql1(`SELECT COUNT(*) FROM \`${P}schedule_time_slots\` WHERE schedule_id=${schId}`);
      info('일정 시간대', `${slots}건`);
    }
  }
  const schList = await admin.get('schedule.index');
  chk('일정 목록 조회', schList.status === 200 && pageErrors(schList.body).fatal.length === 0, `HTTP ${schList.status}`);

  // ══ 4. 휴지통 수명주기 ═══════════════════════════════════════════════════
  // 고객·견적·계약·프로젝트 각각에 대해 삭제 → 권한차단 → 복원 → 재삭제 → 완전삭제.
  section('휴지통 수명주기');
  const auditBefore = Number(sql1(`SELECT COUNT(*) FROM \`${P}audit_logs\``));

  /** 엔티티 하나의 수명주기를 한 바퀴 돈다. */
  async function trashCycle(label, entity, id, table, nameCol) {
    if (!id) { warn(`${label} 수명주기`, 'QA 데이터 없음 — 건너뜀'); return; }

    // 1) 일반 삭제 = 소프트 삭제여야 한다
    let res = await admin.post(`${entity}.delete`, { id }, { json: true });
    let del = sql1(`SELECT deleted_at FROM \`${table}\` WHERE id=${id}`);
    const stillThere = sql1(`SELECT COUNT(*) FROM \`${table}\` WHERE id=${id}`);
    chk(`${label} 소프트 삭제`, String(stillThere) === '1' && del && del !== 'NULL',
      `행 보존 + deleted_at=${del} (HTTP ${res.status})`);

    // 2) 휴지통 목록에 노출
    const trashList = await admin.get(`${entity}.index`, { trash: 1 });
    chk(`${label} 휴지통 노출`, trashList.status === 200, `HTTP ${trashList.status}`);

    // 3) 일반 직원은 복원·완전삭제 불가
    let sres = await staff.post(`${entity}.restore`, { id }, { json: true });
    chk(`${label} 일반직원 복원 차단`, sres.status === 403, `HTTP ${sres.status}`);
    sres = await staff.post(`${entity}.purge`, { id }, { json: true });
    chk(`${label} 일반직원 완전삭제 차단`, sres.status === 403, `HTTP ${sres.status}`);
    const survived = sql1(`SELECT COUNT(*) FROM \`${table}\` WHERE id=${id}`);
    chk(`${label} 차단 후 데이터 보존`, String(survived) === '1', `행 ${survived}건 유지`);

    // 4) 최고운영자 복원
    res = await admin.post(`${entity}.restore`, { id }, { json: true });
    del = sql1(`SELECT deleted_at FROM \`${table}\` WHERE id=${id}`);
    chk(`${label} 최고운영자 복원`, !del || del === 'NULL', `deleted_at=${del} (HTTP ${res.status})`);

    // 5) 중복 복원이 안전한가 (이미 복원된 것을 또 복원)
    res = await admin.post(`${entity}.restore`, { id }, { json: true });
    const afterDup = sql1(`SELECT COUNT(*) FROM \`${table}\` WHERE id=${id}`);
    chk(`${label} 중복 복원 안전`, String(afterDup) === '1', `HTTP ${res.status} · 행 ${afterDup}건`);

    // 6) 다시 삭제 → 완전삭제
    await admin.post(`${entity}.delete`, { id }, { json: true });
    res = await admin.post(`${entity}.purge`, { id }, { json: true });
    const gone = sql1(`SELECT COUNT(*) FROM \`${table}\` WHERE id=${id}`);
    if (String(gone) === '0') {
      pass(`${label} 완전삭제`, `행 제거 확인 (HTTP ${res.status})`);
      // 7) 중복 완전삭제가 안전한가
      res = await admin.post(`${entity}.purge`, { id }, { json: true });
      chk(`${label} 중복 완전삭제 안전`, res.status !== 500, `HTTP ${res.status}`);
    } else {
      // 거부 사유를 추측하지 않는다 — 리다이렉트 뒤 플래시를 읽어 앱의 말을 그대로 남긴다.
      const back = await admin.get(`${entity}.index`, { trash: 1 });
      const f = flashOf(back.body);
      const msg = f ? f.msg : '(플래시 없음)';
      // 연결된 기록 때문에 막히는 건 기록보존 정책상 **지켜져야 할 동작**이다.
      // 데이터가 그대로 남아 있는지까지 확인하고 통과로 판정한다.
      if (/연결된 기록|휴지통에 남아 있는|먼저 완전삭제/.test(msg)) {
        const kept = sql1(`SELECT COUNT(*) FROM \`${table}\` WHERE id=${id}`);
        chk(`${label} 완전삭제 참조가드 작동`, String(kept) === '1', `${msg} · 데이터 보존 ${kept}건`);
      } else {
        warn(`${label} 완전삭제 거부`, `HTTP ${res.status} · 앱 메시지: ${msg}`);
      }
    }
  }

  // 말단 데이터부터 정리한다 — 참조가 남아 있으면 상위 완전삭제가 가드에 막힌다.
  section('말단 데이터 정리');
  for (const id of created.schedules) {
    await admin.post('schedule.delete', { id }, { json: true });
  }
  const schLeft = created.schedules.length
    ? sql1(`SELECT COUNT(*) FROM \`${P}schedules\` WHERE id IN (${created.schedules.join(',')})`) : '0';
  chk('QA 일정 삭제', String(schLeft) === '0', `잔존 ${schLeft}건`);

  for (const id of created.costs) {
    await admin.post('costs.cancel', { id }, { json: true });
  }
  const costCancelled = created.costs.length
    ? sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE id IN (${created.costs.join(',')}) AND cost_status='cancelled'`) : '0';
  info('QA 지출 취소', `${costCancelled}/${created.costs.length}건 cancelled (물리삭제 아님 — 정책상 기록 보존)`);

  for (const id of created.payments) {
    await admin.post('payments.delete', { id }, { json: true });
  }
  const payLive = created.payments.length
    ? sql1(`SELECT COUNT(*) FROM \`${P}payments\` WHERE id IN (${created.payments.join(',')}) AND status<>'cancelled'`) : '0';
  info('QA 입금 취소', `잔여 활성 ${payLive}건 (입금은 물리삭제 금지 정책 — 취소 전환)`);

  section('휴지통 수명주기');
  // 하위 참조가 있는 것부터 지운다(프로젝트 → 계약 → 견적 → 영업기회 → 고객).
  await trashCycle('프로젝트', 'projects', created.projects[0], `${P}projects`, 'name');
  await trashCycle('계약', 'contracts', created.contracts[0], `${P}contracts`, 'contract_no');
  await trashCycle('견적', 'quotes', created.quotes[0], `${P}quotes`, 'title');

  // ── 참조 가드 ──
  // 하위 데이터가 남은 고객은 삭제가 막혀야 한다. 이건 실패가 아니라 지켜져야 할 동작이다.
  // 복구본에서 이 가드가 죽어 있으면 연쇄 고아 데이터가 생기므로 명시적으로 확인한다.
  if (created.customers[0] && created.leads[0]) {
    const guardRes = await admin.post('customers.delete', { id: created.customers[0] }, { json: true });
    const stillAlive = sql1(`SELECT deleted_at FROM \`${P}customers\` WHERE id=${created.customers[0]}`);
    chk('참조 가드: 하위 데이터 있는 고객 삭제 차단',
      guardRes.status === 409 && (!stillAlive || stillAlive === 'NULL'),
      `HTTP ${guardRes.status} · deleted_at=${stillAlive}`);
  }

  // 영업기회를 먼저 정리한 뒤 고객 수명주기를 돈다.
  if (created.leads[0]) {
    await admin.post('pipeline.delete', { id: created.leads[0] }, { json: true });
    const pres = await admin.post('pipeline.purge', { id: created.leads[0] }, { json: true });
    const leadGone = sql1(`SELECT COUNT(*) FROM \`${P}leads\` WHERE id=${created.leads[0]}`);
    if (String(leadGone) === '0') {
      pass('영업기회 완전삭제', `행 제거 확인 (HTTP ${pres.status})`);
    } else {
      const back = await admin.get('pipeline.index', { trash: 1 });
      const f = flashOf(back.body);
      const msg = f ? f.msg : '(플래시 없음)';
      chk('영업기회 완전삭제 참조가드 작동', /연결된 기록|휴지통에 남아 있는|먼저 완전삭제/.test(msg),
        `${msg} · 데이터 보존 ${leadGone}건`);
    }
  }
  await trashCycle('고객', 'customers', created.customers[0], `${P}customers`, 'name');

  // ── 의존관계 없는 데이터로 완전삭제 실증 ────────────────────────────────
  // 위 체인은 전부 참조가드에 막혔다(정상). 그러면 "완전삭제가 실제로 되는가"는
  // 검증되지 않은 채 남는다. 하위 데이터가 전혀 없는 QA 고객을 따로 만들어
  // 소프트삭제 → 완전삭제 → 행 소멸 → 감사로그까지 한 번에 실증한다.
  section('완전삭제 실증(독립 데이터)');
  const soloName = `${QA}고객B-독립`;
  await admin.post('customers.save', {
    name: soloName, type: 'individual', status: 'active', privacy_agreed: '1',
  });
  const soloId = sql1(`SELECT id FROM \`${P}customers\` WHERE name='${soloName}' ORDER BY id DESC LIMIT 1`);
  chk('독립 QA 고객 생성', !!soloId, `id=${soloId}`);
  if (soloId) {
    created.customers.push(soloId);
    await admin.post('customers.delete', { id: soloId }, { json: true });
    const softDel = sql1(`SELECT deleted_at FROM \`${P}customers\` WHERE id=${soloId}`);
    chk('독립 고객 소프트 삭제', softDel && softDel !== 'NULL', `deleted_at=${softDel}`);

    const auditPre = Number(sql1(`SELECT COUNT(*) FROM \`${P}audit_logs\` WHERE action LIKE '%purge%'`));
    const pres = await admin.post('customers.purge', { id: soloId }, { json: true });
    const gone = sql1(`SELECT COUNT(*) FROM \`${P}customers\` WHERE id=${soloId}`);
    chk('독립 고객 완전삭제', String(gone) === '0', `행 제거 확인 (HTTP ${pres.status})`);
    const auditPost = Number(sql1(`SELECT COUNT(*) FROM \`${P}audit_logs\` WHERE action LIKE '%purge%'`));
    chk('완전삭제 감사로그 기록', auditPost > auditPre, `purge 로그 ${auditPre} → ${auditPost}`);
    // 중복 완전삭제가 500 을 내지 않는가
    const dup = await admin.post('customers.purge', { id: soloId }, { json: true });
    chk('중복 완전삭제 안전', dup.status !== 500, `HTTP ${dup.status}`);
  }

  // 감사 로그가 실제로 남았는가
  const auditAfter = Number(sql1(`SELECT COUNT(*) FROM \`${P}audit_logs\``));
  chk('휴지통 작업 감사로그 기록', auditAfter > auditBefore, `${auditBefore} → ${auditAfter} (+${auditAfter - auditBefore})`);
  const actions = sqlRows(`SELECT action, COUNT(*) c FROM \`${P}audit_logs\` WHERE id > ${
    sql1(`SELECT MAX(id) - ${auditAfter - auditBefore} FROM \`${P}audit_logs\``)} GROUP BY action ORDER BY c DESC LIMIT 8`);
  info('기록된 감사 액션', actions.map((a) => `${a.action}×${a.c}`).join(', ') || '(없음)');

  // ══ 5. orphan 재검증 — QA 작업이 참조 무결성을 깨지 않았는가 ═════════════
  section('무결성 재검증');
  const orphanChecks = {
    'quote→customer': `SELECT COUNT(*) FROM \`${P}quotes\` a LEFT JOIN \`${P}customers\` b ON a.customer_id=b.id WHERE a.customer_id IS NOT NULL AND b.id IS NULL`,
    'contract→customer': `SELECT COUNT(*) FROM \`${P}contracts\` a LEFT JOIN \`${P}customers\` b ON a.customer_id=b.id WHERE b.id IS NULL`,
    'project→contract': `SELECT COUNT(*) FROM \`${P}projects\` a LEFT JOIN \`${P}contracts\` b ON a.contract_id=b.id WHERE a.contract_id IS NOT NULL AND b.id IS NULL`,
    'payment→parent': `SELECT COUNT(*) FROM \`${P}payments\` a LEFT JOIN \`${P}contracts\` c ON a.contract_id=c.id LEFT JOIN \`${P}projects\` p ON a.project_id=p.id WHERE (a.contract_id IS NOT NULL AND c.id IS NULL) OR (a.project_id IS NOT NULL AND p.id IS NULL)`,
    'cost→project': `SELECT COUNT(*) FROM \`${P}costs\` a LEFT JOIN \`${P}projects\` b ON a.project_id=b.id WHERE b.id IS NULL`,
  };
  for (const [k, q] of Object.entries(orphanChecks)) {
    const n = sql1(q);
    chk(`orphan ${k}`, String(n) === '0', `${n}건`);
  }

  // ══ 6. 실데이터 불변 확인 ════════════════════════════════════════════════
  section('실데이터 보존');
  const after = realDataSnapshot();
  chk('복구된 실고객 건수 불변', before.customers === after.customers, `${before.customers} → ${after.customers}`);
  chk('복구된 실계약 건수 불변', before.contracts === after.contracts, `${before.contracts} → ${after.contracts}`);
  chk('복구된 실프로젝트 건수 불변', before.projects === after.projects, `${before.projects} → ${after.projects}`);
  info('입금 합계 변화', `${before.payments} → ${after.payments} (QA 입금 포함)`);

  const s = saveResults(`${L.RESTORE_ROOT}/_dr/evidence/t8_business.json`);
  require('fs').writeFileSync(`${L.RESTORE_ROOT}/_dr/evidence/t8_created.json`, JSON.stringify(created, null, 2));
  process.exit(s.FAIL > 0 ? 1 : 0);
})().catch((e) => { console.error('실행 오류:', e); process.exit(2); });
