/**
 * 지출 등록 핫픽스 검수 — 2026-07-31 운영 500 장애.
 *
 * 원인: CostsController::findRecentDuplicateCost() 의 $itemName 이 non-nullable
 * 타입힌트라, 내용/자재명을 비우고 저장하면 TypeError 로 500 이 났다.
 *
 * "고쳤다"를 믿지 않고 경우의 수를 전수로 태운다. 특히:
 *  - 장애 조건이 재현되지 않는가 (수정 확인)
 *  - 필수 검증이 500 이 아니라 422 로 안내되는가 (사용자가 이유를 알 수 있는가)
 *  - 정상 저장이 여전히 되는가 (회귀)
 *  - 중복 저장 방지가 계속 동작하는가 (수정으로 깨뜨리지 않았는가)
 *
 * 대상: 복구 검수 환경(:8091) = 운영 코드 + 운영 데이터 사본.
 * 생성 데이터는 QAHOTFIX- 표식만 쓰고 끝나면 지운다.
 */
process.env.QA_BASE = process.env.QA_BASE || 'http://127.0.0.1:8091/index.php';

const { Client } = require('../qa_final/lib.js');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const PROJECT_ROOT = '/Users/deoksookim/Desktop/코드/claude code/eden_crm';
const DB = 'eden_crm_restore_test';
const SOCK = `${PROJECT_ROOT}/.devdb/mysql.sock`;
const P = 'edencrm_';
const MARK = 'QAHOTFIX-';

const TMPQ = path.join(os.tmpdir(), `qa_hotfix_${process.pid}.sql`);
function sql(q) {
  fs.writeFileSync(TMPQ, q);
  return execFileSync('/bin/sh', ['-c',
    `mysql --socket='${SOCK}' --default-character-set=utf8mb4 -uroot ${DB} --batch --raw < '${TMPQ}' 2>&1 | grep -v '^mysql: \\[Warning\\]' || true`,
  ], { encoding: 'utf8' }).replace(/\n$/, '');
}
function sql1(q) {
  const out = sql(q).trim().split('\n');
  return out.length > 1 ? out[1] : null;
}

const results = [];
let sec = '';
function section(s) { sec = s; console.log(`\n──── ${s} ────`); }
function rec(st, item, ev) {
  results.push({ sec, item, st, ev: String(ev).slice(0, 300) });
  console.log(`${{ PASS: '✅', FAIL: '❌', INFO: 'ℹ️' }[st]} ${item} — ${String(ev).slice(0, 150)}`);
}
const chk = (i, c, e) => rec(c ? 'PASS' : 'FAIL', i, e);
const info = (i, e) => rec('INFO', i, e);

/**
 * 사용자에게 실제로 보이는 오류 문구를 뽑는다.
 *
 * CostsController::fail() 은 JSON 요청이면 즉시 에러 응답을, 일반 폼 제출이면
 * projects.show 로 **리다이렉트하면서 플래시**를 남긴다. 그래서 POST 응답 본문에는
 * 문구가 없다 — 리다이렉트를 따라가야 사용자가 보는 것과 같은 화면을 얻는다.
 * (첫 판에서 이걸 놓쳐 "안내 문구 없음" 오탐이 6건 났다.)
 */
async function messageOf(client, res, projectId) {
  if (res.json && res.json.error) return res.json.error;
  const page = await client.get('projects.show', { id: projectId, tab: 'costs' });
  const m = page.body.match(/<div class="flash flash-(\w+)"[^>]*>([\s\S]*?)<\/div>/);
  return m ? m[2].replace(/<[^>]+>/g, '').trim() : '';
}

(async () => {
  const admin = new Client('admin');
  await admin.login('admin', 'QArestore!2026admin');

  const projectId = sql1(`SELECT id FROM \`${P}projects\` WHERE deleted_at IS NULL ORDER BY id LIMIT 1`);
  if (!projectId) { console.error('검수용 프로젝트 없음'); process.exit(2); }
  const before = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\``));
  info('대상', `프로젝트 id=${projectId} · 기존 지출 ${before}건`);

  const base = { project_id: projectId, type: 'actual', cost_status: 'confirmed' };
  const post = (extra) => admin.post('costs.save', { ...base, ...extra });
  const countMark = () => Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name LIKE '${MARK}%'`));

  // ══ 1. 장애 조건 재현 시도 ═══════════════════════════════════════════════
  section('장애 조건 (수정 확인)');
  {
    // 운영에서 500 을 냈던 그 요청 그대로 — 내용/자재명 없이 금액만.
    const r = await post({ category: 'material', spent_date: '2026-07-31', amount: '490000' });
    const msg = await messageOf(admin, r, projectId);
    chk('내용 없이 저장 → 500 아님', r.status !== 500, `HTTP ${r.status}`);
    chk('사용자에게 이유가 안내됨', /내용|자재명/.test(msg), msg || '(메시지 없음)');
    chk('DB 에 저장되지 않음', Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\``)) === before, '건수 불변');
  }

  // ══ 2. 필수값 검증 ═══════════════════════════════════════════════════════
  section('필수값 검증');
  const required = [
    ['발생일 누락', { category: 'material', item_name: `${MARK}자재`, amount: '10000' }, /발생일/],
    ['비용구분 누락', { spent_date: '2026-07-31', item_name: `${MARK}자재`, amount: '10000' }, /비용 구분/],
    ['내용 누락', { category: 'material', spent_date: '2026-07-31', amount: '10000' }, /내용|자재명/],
    ['금액·자동계산 모두 없음', { category: 'material', spent_date: '2026-07-31', item_name: `${MARK}자재` }, /금액/],
  ];
  for (const [label, payload, pattern] of required) {
    const r = await post(payload);
    const msg = await messageOf(admin, r, projectId);
    chk(`${label} → 차단(500 아님)`, r.status !== 500, `HTTP ${r.status}`);
    chk(`${label} → 안내 문구`, pattern.test(msg), msg || '(메시지 없음)');
  }
  chk('검증 실패건이 DB 에 남지 않음', countMark() === 0, `${MARK} 지출 ${countMark()}건`);

  // ══ 3. 정상 저장 (회귀) ══════════════════════════════════════════════════
  section('정상 저장');
  {
    const r = await post({
      category: 'material', spent_date: '2026-07-31',
      item_name: `${MARK}수성 상도 페인트`, amount: '490000',
    });
    const row = sql(`SELECT id, item_name, amount, cost_status FROM \`${P}costs\`
                      WHERE item_name='${MARK}수성 상도 페인트' ORDER BY id DESC LIMIT 1`);
    chk('금액 직접 입력 저장', row.includes('490000'), row.split('\n').pop() || '(저장 안 됨)');
    chk('저장 후 500 아님', r.status !== 500, `HTTP ${r.status}`);
  }
  {
    // 자동계산 경로 — 수량×단가
    const r = await post({
      category: 'material', spent_date: '2026-07-31',
      item_name: `${MARK}자동계산`, qty: '2', unit_price: '15000',
    });
    const amt = sql1(`SELECT amount FROM \`${P}costs\` WHERE item_name='${MARK}자동계산' ORDER BY id DESC LIMIT 1`);
    chk('수량×단가 자동계산', String(amt) === '30000', `2 × 15,000 = ${amt} (HTTP ${r.status})`);
  }
  {
    // 인건비 — 일수×일당
    const r = await post({
      category: 'labor', spent_date: '2026-07-31',
      item_name: `${MARK}인건비`, work_days: '3', unit_price: '200000',
    });
    const amt = sql1(`SELECT amount FROM \`${P}costs\` WHERE item_name='${MARK}인건비' ORDER BY id DESC LIMIT 1`);
    chk('일수×일당 자동계산', String(amt) === '600000', `3일 × 200,000 = ${amt} (HTTP ${r.status})`);
  }

  // ══ 4. 중복 저장 방지 (수정으로 깨지지 않았는가) ═════════════════════════
  section('중복 저장 방지');
  {
    const payload = {
      category: 'material', spent_date: '2026-07-31',
      item_name: `${MARK}중복테스트`, amount: '77000',
    };
    await post(payload);
    const n1 = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name='${MARK}중복테스트'`));
    await post(payload);   // 즉시 재전송 = 저장 연타
    const n2 = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name='${MARK}중복테스트'`));
    chk('동일 지출 연타 시 1건만', n1 === 1 && n2 === 1, `1회차 ${n1}건 → 2회차 ${n2}건`);

    // 금액이 다르면 별개 지출이므로 막으면 안 된다
    await post({ ...payload, amount: '88000' });
    const n3 = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name='${MARK}중복테스트'`));
    chk('금액 다르면 별건 저장', n3 === 2, `${n3}건`);
  }

  // ══ 5. 조정 사유 (자동계산과 다른 금액) ══════════════════════════════════
  section('조정 사유');
  {
    const r = await post({
      category: 'material', spent_date: '2026-07-31',
      item_name: `${MARK}사유없음`, qty: '2', unit_price: '10000', amount: '50000',
    });
    const msg = await messageOf(admin, r, projectId);
    chk('자동계산과 다른 금액 → 사유 요구', /사유/.test(msg), msg || '(메시지 없음)');
    const saved = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name='${MARK}사유없음'`));
    chk('사유 없으면 미저장', saved === 0, `${saved}건`);

    const r2 = await post({
      category: 'material', spent_date: '2026-07-31',
      item_name: `${MARK}사유있음`, qty: '2', unit_price: '10000', amount: '50000',
      adjust_reason: '현장 할증',
    });
    const amt = sql1(`SELECT amount FROM \`${P}costs\` WHERE item_name='${MARK}사유있음' ORDER BY id DESC LIMIT 1`);
    chk('사유 있으면 저장', String(amt) === '50000', `금액 ${amt} (HTTP ${r2.status})`);
  }

  // ══ 6. 화면 필수 표시가 서버와 일치하는가 ════════════════════════════════
  section('화면 ↔ 서버 계약');
  {
    const page = await admin.get('projects.show', { id: projectId, tab: 'costs' });
    const form = page.body;
    const hasReq = (name) => {
      const re = new RegExp(`name="${name}"[^>]*required`, 'i');
      return re.test(form);
    };
    chk('발생일 required 속성', hasReq('spent_date'), hasReq('spent_date') ? '있음' : '없음');
    chk('비용구분 required 속성', hasReq('category'), hasReq('category') ? '있음' : '없음');
    chk('내용/자재명 required 속성', hasReq('item_name'), hasReq('item_name') ? '있음' : '없음');
    const labelStars = (form.match(/field-label">[^<]*<span class="req">\*<\/span>/g) || []).length;
    info('필수(*) 표시 개수', `${labelStars}개`);
  }

  // ══ 7. 정리 ══════════════════════════════════════════════════════════════
  section('QA 데이터 정리');
  {
    const n = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name LIKE '${MARK}%'`));
    sql(`DELETE FROM \`${P}costs\` WHERE item_name LIKE '${MARK}%'`);
    const left = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name LIKE '${MARK}%'`));
    chk('QA 지출 정리', left === 0, `${n}건 생성 → ${left}건 잔존`);
    const total = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\``));
    chk('기존 데이터 불변', total === before, `${before} → ${total}건`);
  }

  const c = { PASS: 0, FAIL: 0, INFO: 0 };
  results.forEach((r) => { c[r.st]++; });
  console.log(`\n== 요약: PASS ${c.PASS} · FAIL ${c.FAIL} · INFO ${c.INFO} ==`);
  fs.writeFileSync(path.join(PROJECT_ROOT, 'scripts/qa_hotfix/result.json'),
    JSON.stringify({ at: new Date().toISOString(), summary: c, results }, null, 2));
  try { fs.unlinkSync(TMPQ); } catch (e) { /* 임시파일 없으면 무시 */ }
  process.exit(c.FAIL > 0 ? 1 : 0);
})().catch((e) => { console.error('실행 오류:', e); process.exit(2); });
