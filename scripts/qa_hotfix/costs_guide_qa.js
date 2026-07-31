/**
 * 지출 입력 강화 검수 — 금액 잠금 + 구분별 필수(수량/일수·단가) + 가이드.
 *
 * costs.amount 는 projects.actual_cost → 확정 순이익 → 직원 보너스로 이어진다.
 * 근거(수량×단가) 없이 금액만 적히면 그 연쇄가 검증 불가능해지므로,
 * 구분 8종 전부에 대해 "근거 없이는 저장되지 않는가"를 확인한다.
 */
process.env.QA_BASE = 'http://127.0.0.1:8091/index.php';
const { Client } = require('../qa_final/lib.js');
const { execFileSync } = require('child_process');
const fs = require('fs'); const os = require('os'); const path = require('path');
const ROOT = '/Users/deoksookim/Desktop/코드/claude code/eden_crm';
const DB = 'eden_crm_restore_test', P = 'edencrm_', MARK = 'QAGUIDE-';
const TMPQ = path.join(os.tmpdir(), `qg_${process.pid}.sql`);
function sql(q){fs.writeFileSync(TMPQ,q);return execFileSync('/bin/sh',['-c',
  `mysql --socket='${ROOT}/.devdb/mysql.sock' --default-character-set=utf8mb4 -uroot ${DB} --batch --raw < '${TMPQ}' 2>&1 | grep -v '^mysql: \\[Warning\\]' || true`],{encoding:'utf8'}).replace(/\n$/,'');}
function sql1(q){const o=sql(q).trim().split('\n');return o.length>1?o[1]:null;}
let pass=0, fail=0; const fails=[];
const chk=(m,c,e)=>{ if(c){pass++;console.log(`✅ ${m} — ${e}`);} else {fail++;fails.push(m);console.log(`❌ ${m} — ${e}`);} };
const CATS = { material:'자재비', labor:'인건비', outsourcing:'외주비', equipment:'장비비',
               transport:'운송비', meal:'식비', waste:'폐기물 처리비', etc:'기타' };

(async()=>{
  const a = new Client('a'); await a.login('admin','QArestore!2026admin');
  const pid = sql1(`SELECT id FROM \`${P}projects\` WHERE deleted_at IS NULL ORDER BY id LIMIT 1`);
  const before = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\``));
  const base = { project_id: pid, type:'actual', cost_status:'confirmed', spent_date:'2026-07-31' };
  const post = (x) => a.post('costs.save', { ...base, ...x });
  const msg = async () => {
    const p = await a.get('projects.show', { id: pid, tab:'costs' });
    const m = p.body.match(/<div class="flash flash-(\w+)"[^>]*>([\s\S]*?)<\/div>/);
    return m ? m[2].replace(/<[^>]+>/g,'').trim() : '';
  };

  console.log('\n──── 구분 8종: 근거 없이 저장 차단 ────');
  for (const [cat,label] of Object.entries(CATS)) {
    // 판정 기준: (1) DB 에 저장되지 않았는가 (2) 그 구분에 맞는 안내가 나왔는가.
    // 안내 문구는 구분마다 자연스럽게 다르므로(「대수 또는 일수」·「회당 운임」·「인원수」…)
    // 고정 단어로 매칭하면 정상 동작을 실패로 오판한다 — 구분 라벨 + 예시 포함으로 본다.
    const saved = (n) => Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name='${n}'`));

    const n1 = `${MARK}${label}-단가없음`;
    await post({ category:cat, item_name:n1 });
    const m1 = await msg();
    chk(`${label}: 단가 없으면 차단`,
        saved(n1)===0 && m1.includes(label) && m1.includes('예:'), m1.slice(0,70));

    const n2 = `${MARK}${label}-수량없음`;
    await post({ category:cat, item_name:n2, unit_price:'10000' });
    const m2 = await msg();
    const wantWord = cat==='labor' ? /일수|시간/ : /입력하세요/;
    chk(`${label}: ${cat==='labor'?'일수/시간':'수량'} 없으면 차단`,
        saved(n2)===0 && wantWord.test(m2) && m2.includes('예:'), m2.slice(0,70));
  }
  const leaked = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name LIKE '${MARK}%'`));
  chk('차단된 건은 DB 미저장', leaked===0, `${leaked}건`);

  console.log('\n──── 구분 8종: 정상 저장 + 자동계산 ────');
  for (const [cat,label] of Object.entries(CATS)) {
    const p = cat==='labor'
      ? { category:cat, item_name:`${MARK}${label}`, unit_price:'200000', work_days:'3' }
      : { category:cat, item_name:`${MARK}${label}`, unit_price:'15000', qty:'2' };
    const want = cat==='labor' ? 600000 : 30000;
    await post(p);
    const amt = sql1(`SELECT amount FROM \`${P}costs\` WHERE item_name='${MARK}${label}' ORDER BY id DESC LIMIT 1`);
    chk(`${label}: 자동계산 저장`, Number(amt)===want, `${amt} (기대 ${want})`);
  }

  console.log('\n──── 금액 수동 입력 ────');
  await post({ category:'material', item_name:`${MARK}수동-사유없음`, unit_price:'10000', qty:'2', amount:'50000' });
  chk('자동계산과 다른 금액 → 사유 요구', /사유/.test(await msg()), '차단됨');
  chk('사유 없으면 미저장', Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name='${MARK}수동-사유없음'`))===0, '0건');
  await post({ category:'material', item_name:`${MARK}수동-사유있음`, unit_price:'10000', qty:'2', amount:'50000', adjust_reason:'부가세 포함' });
  chk('사유 있으면 저장', sql1(`SELECT amount FROM \`${P}costs\` WHERE item_name='${MARK}수동-사유있음'`)==='50000', '50,000 저장');

  console.log('\n──── 화면: 가이드·잠금 ────');
  const page = await a.get('projects.show', { id: pid, tab:'costs' });
  chk('금액 readonly', /name="amount"[^>]*readonly/.test(page.body), 'readonly 속성');
  chk('수동입력 체크박스', /id="amountManual"/.test(page.body), 'amountManual');
  chk('단가 required', /name="unit_price"[^>]*required/.test(page.body), 'required');
  chk('가이드 영역', /id="catGuide"/.test(page.body), 'catGuide');
  const guideCount = (page.body.match(/"hint":/g)||[]).length;
  chk('가이드 8구분 주입', guideCount===8, `${guideCount}종`);

  console.log('\n──── 정리 ────');
  sql(`DELETE FROM \`${P}costs\` WHERE item_name LIKE '${MARK}%'`);
  const left = Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\` WHERE item_name LIKE '${MARK}%'`));
  chk('QA 데이터 정리', left===0, `잔존 ${left}건`);
  chk('기존 데이터 불변', Number(sql1(`SELECT COUNT(*) FROM \`${P}costs\``))===before, `${before}건`);

  console.log(`\n== PASS ${pass} · FAIL ${fail} ==`);
  if (fails.length) console.log('실패:', fails.join(' / '));
  try{fs.unlinkSync(TMPQ);}catch(e){}
  process.exit(fail?1:0);
})().catch(e=>{console.error('오류:',e);process.exit(2);});
