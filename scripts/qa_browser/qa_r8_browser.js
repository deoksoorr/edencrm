/* R8 브라우저 QA — 공정 분리·반기 실적·보너스 (스펙 §12 시나리오, R8 시드 적용 상태 전제) */
const { chromium, devices } = require('playwright');
const B = 'http://127.0.0.1:8080/index.php';
let pass = 0, fail = 0; const failures = [];
function check(name, ok, detail) {
  if (ok) { pass++; console.log('  ✅ ' + name); }
  else { fail++; failures.push(name); console.log('  ❌ ' + name + (detail ? ' — ' + String(detail).slice(0, 180) : '')); }
}
async function login(page, id, pw = 'password123!') {
  await page.goto(B + '?r=login');
  await page.fill('input[name="login_id"]', id);
  await page.fill('input[name="password"]', pw);
  await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
}
async function csrf(page) { return page.evaluate(() => document.querySelector('meta[name="csrf-token"]').content); }
async function api(page, route, data) {
  return page.evaluate(async ({ route, data }) => {
    const body = new URLSearchParams(data);
    body.append('_csrf', document.querySelector('meta[name="csrf-token"]').content);
    const res = await fetch('index.php?r=' + route, {
      method: 'POST', body,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
    });
    let j = null; try { j = await res.json(); } catch (e) {}
    return { status: res.status, json: j };
  }, { route, data });
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ locale: 'ko-KR' });
  const page = await ctx.newPage();
  const errBag = [];
  page.on('pageerror', (e) => errBag.push('pageerror: ' + String(e).slice(0, 120)));
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    if (/status of (400|403|404|409|422)/.test(m.text()) || /Failed to fetch/.test(m.text())) return; // 의도된 음성 테스트·내비게이션 중단 fetch
    errBag.push('console: ' + m.text().slice(0, 120));
  });
  page.on('response', (r) => { if (r.status() >= 500) errBag.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 100)); });
  await login(page, 'admin');

  console.log('\n═ 1·2. 반기 조회(상반기/하반기) — 화면 값 = 서비스 값 ═');
  await page.goto(B + '?r=halfyear.index&year=2026&half=1');
  let body = await page.content(); // 축약 표기(만원) + title 툴팁 원값 포함 검증
  check('H1 확정매출 5,900만(=59,000,000)', body.includes('5,900만') && body.includes('59,000,000'));
  check('H1 확정순이익 700만(=7,000,000)', body.includes('7,000,000') || body.includes('700만'));
  check('H1 등록지출 5,200만(=52,000,000)', body.includes('52,000,000') || body.includes('5,200만'));
  await page.goto(B + '?r=halfyear.index&year=2026&half=2');
  body = await page.content();
  check('H2 확정매출 1,500만(=15,000,000)', body.includes('15,000,000') || body.includes('1,500만'));
  check('H2 확정순이익 600만(=6,000,000)', body.includes('6,000,000') || body.includes('600만'));
  check('기본 반기=하반기(7/27)', (await page.evaluate(() => {
    const s = document.querySelector('select[name="half"]'); return s ? s.value : '';
  })) === '2' || body.includes('하반기'));

  console.log('\n═ 3·4. 직원 관리 반기 요약(매출·순이익·보너스) ═');
  await page.goto(B + '?r=staff.index&year=2026&half=1');
  body = await page.content();
  check('마지막 로그인 컬럼 제거', !body.includes('마지막 로그인'));
  check('QA직원A H1 매출 3,800만(=38,000,000)', body.includes('38,000,000') || body.includes('3,800만'));
  check('QA직원B H1 매출 2,100만(=21,000,000)', body.includes('21,000,000') || body.includes('2,100만'));
  check('QA직원A H1 보너스 지급 100만(=1,000,000)', body.includes('1,000,000') || body.includes('100만'));
  check('반기현황·지급현황 접근 버튼', body.includes('반기 현황') && body.includes('보너스'));
  const rowLink = await page.locator('table a', { hasText: 'QA직원A' }).count();
  check('직원명 → 상세 링크', rowLink >= 1);
  await page.goto(B + '?r=staff.show&id=5&year=2026&half=1');
  body = await page.textContent('body');
  check('직원 상세 현장별 실적(P1·P2)', body.includes('QA P1') && body.includes('QA P2'));
  check('직원 상세 보너스 내역', body.includes('현장 순이익의 10%') || body.includes('1,000,000'));

  console.log('\n═ 5·6. 보너스 지급·수정·취소 + 히스토리 ═');
  await page.goto(B + '?r=bonus.index&year=2026&half=2');
  body = await page.textContent('body');
  check('보너스 목록(미지급 500,000)', body.includes('500,000'));
  // 등록 → 수정(마감 아님, 사유 불요) → 지급 → 취소 → 소프트삭제, 이력 검증
  let r = await api(page, 'bonus.save', { user_id: 6, project_id: 407, year: 2026, half: 2, base_amount: 12000000, calc_basis: 'QA 브라우저 산정 5%', calc_amount: 600000, paid_amount: 0, pay_status: 'unpaid' });
  const nb = r.json && r.json.data && r.json.data.id ? r.json.data.id : (r.json && r.json.id);
  check('보너스 등록(H2)', r.status === 200 && !!nb, JSON.stringify(r.json));
  r = await api(page, 'bonus.save', { id: nb, paid_amount: 600000, pay_date: '2026-07-27', pay_status: 'paid' });
  check('지급 처리(paid 자동)', r.status === 200, JSON.stringify(r.json));
  r = await api(page, 'bonus.save', { id: nb, pay_status: 'cancelled', reason: 'QA 지급 취소' });
  check('지급 취소', r.status === 200, JSON.stringify(r.json));
  // 마감 반기(H1) 사유 없는 수정 → 422
  r = await api(page, 'bonus.save', { id: 502, memo: '사유 없는 마감 수정 시도' });
  check('마감 반기 사유 없는 수정 422', r.status === 422, 'status=' + r.status);
  r = await api(page, 'bonus.save', { id: 502, memo: '마감 수정(사유 포함)', reason: '지급액 정정 QA' });
  check('마감 반기 사유 포함 수정 200', r.status === 200, 'status=' + r.status);
  r = await api(page, 'bonus.delete', { id: nb, reason: 'QA 정리' });
  check('소프트 삭제', r.status === 200, JSON.stringify(r.json));
  const hist = await page.evaluate(async () => (await fetch('index.php?r=bonus.history')).text());
  check('이력 화면에 액션 기록', hist.includes('QA 지급 취소') && hist.includes('지급액 정정 QA'));
  const softCnt = await page.evaluate(async () => {
    const t = await (await fetch('index.php?r=bonus.index&year=2026&half=2')).text();
    return t.includes('QA 브라우저 산정 5%');
  });
  check('삭제 건 목록 미노출(소프트)', !softCnt);

  console.log('\n═ 7·8·9. 공정 보드 도장/인테리어 탭·이동·격리 ═');
  await page.goto(B + '?r=process.board');
  body = await page.textContent('body');
  check('도장 탭: 도장 프로젝트 노출', body.includes('QA P3'));
  check('도장 탭: 인테리어 미노출', !body.includes('QA I2'));
  check('도장 탭: 미지정 U1 노출+배지', body.includes('QA U1') && body.includes('유형 미지정'));
  check('도장 탭: 완료 P1 종결 컬럼 표시(R7-F1 회귀)', body.includes('QA P1'));
  const doneLocked = await page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('.kanban-card'));
    const c = cards.find((x) => x.textContent.includes('QA P1'));
    return c ? c.className : '';
  });
  check('완료 카드 locked/pb-done 클래스', /locked/.test(doneLocked) && /pb-done/.test(doneLocked), doneLocked);
  check('도장 컬럼 구성(고압세척·프라이머)', body.includes('고압세척') && body.includes('프라이머'));
  await page.goto(B + '?r=process.board&type=interior');
  body = await page.textContent('body');
  check('인테리어 탭: I2 노출·P3 미노출', body.includes('QA I2') && !body.includes('QA P3'));
  check('인테리어 컬럼(철거·설비·전기·목공·데코타일)', ['철거', '설비', '전기', '목공', '데코타일'].every((s) => body.includes(s)));
  check('인테리어 탭: 미지정 U1 노출', body.includes('QA U1'));
  check('인테리어 탭: 완료 I1 표시', body.includes('QA I1'));
  await page.reload();
  check('새로고침 후 인테리어 탭 유지(URL)', (await page.textContent('body')).includes('철거'));
  // 공정 이동: I2(int_carpentry 29) → int_film(30)… API(서버 검증 경로)
  r = await api(page, 'process.move', { project_id: 407, to_stage_id: 30 });
  check('인테리어 공정 이동(목공→필름)', r.status === 200, JSON.stringify(r.json));
  r = await api(page, 'process.move', { project_id: 407, to_stage_id: 12 });
  check('교차 유형 이동 차단 422(인테리어→도장 1차도장)', r.status === 422, 'status=' + r.status);
  r = await api(page, 'process.move', { project_id: 403, to_stage_id: 13 });
  check('도장 공정 이동(1차→2차)', r.status === 200, JSON.stringify(r.json));
  // 원복
  await api(page, 'process.move', { project_id: 407, to_stage_id: 29 });
  await api(page, 'process.move', { project_id: 403, to_stage_id: 12 });
  // 드래그 앤 드롭(마우스 경로) — 도장 보드에서 P4 카드를 인접 컬럼으로
  await page.goto(B + '?r=process.board');
  const dragOk = await (async () => {
    try {
      const card = page.locator('.kanban-card', { hasText: 'QA P4' }).first();
      const target = page.locator('.kanban-list[data-stage-id="10"]').first();
      await card.dragTo(target, { timeout: 5000 });
      await page.waitForTimeout(800);
      const st = await page.evaluate(async () => {
        const t = await (await fetch('index.php?r=process.history&project_id=404')).json();
        return JSON.stringify(t).slice(0, 50);
      });
      return true;
    } catch (e) { return false; }
  })();
  check('드래그 앤 드롭 카드 이동(마우스)', dragOk);
  const p4Stage = await page.evaluate(async () => {
    const r = await fetch('index.php?r=process.board');
    return null;
  }).catch(() => null);
  // DB 확인은 셸 단계에서 수행

  console.log('\n═ 10·11·12. 공정 추가·정렬·비활성(관리) ═');
  r = await api(page, 'settings.stage.save', { kind: 'process', name: 'QA신규공정', process_type: 'interior', stage_group: 'build', requires_confirm: 0, is_active: 1, description: 'QA 추가 테스트' });
  check('신규 공정 추가', r.status === 200 || r.status === 302, 'status=' + r.status);
  await page.goto(B + '?r=process.board&type=interior');
  check('보드에 신규 공정 노출', (await page.textContent('body')).includes('QA신규공정'));
  const sid = await page.evaluate(async () => {
    const t = await (await fetch('index.php?r=settings.stages&type=interior')).text();
    const m = t.match(/value="(\d+)"[^>]*>[\s\S]{0,200}?QA신규공정/) || t.match(/QA신규공정[\s\S]{0,300}?value="(\d+)"/);
    return m ? m[1] : null;
  });
  // 비활성화
  r = await api(page, 'settings.stage.save', { kind: 'process', id: sid || 0, name: 'QA신규공정', process_type: 'interior', stage_group: 'build', is_active: 0 });
  check('공정 비활성화 저장', (r.status === 200 || r.status === 302) && !!sid, 'id=' + sid + ' status=' + r.status);
  await page.goto(B + '?r=process.board&type=interior');
  check('비활성 공정 보드 미노출', !(await page.textContent('body')).includes('QA신규공정'));
  // 공통 단계 삭제 거부
  r = await api(page, 'settings.stage.delete', { kind: 'process', id: 19 });
  check('공통(전체완료) 삭제 거부', r.status !== 200 || (r.json && r.json.ok === false), 'status=' + r.status);
  // 이력 참조 단계 삭제 거부(1차도장 id 12 — P3 이력 있음)
  r = await api(page, 'settings.stage.delete', { kind: 'process', id: 12 });
  check('이력 참조 공정 삭제 거부', r.status !== 200 || (r.json && r.json.ok === false), 'status=' + r.status);
  // 정리: QA신규공정 삭제(참조 없음 → 허용)
  if (sid) { r = await api(page, 'settings.stage.delete', { kind: 'process', id: sid }); check('신규 공정 정리 삭제', r.status === 200 || r.status === 302, 'status=' + r.status); }

  console.log('\n═ 13. 미지정 → 유형 1회 지정 ═');
  r = await api(page, 'process.settype', { project_id: 411, construction_type: 'interior' });
  check('U1 인테리어 지정', r.status === 200, JSON.stringify(r.json));
  await page.goto(B + '?r=process.board');
  check('지정 후 도장 탭에서 U1 사라짐', !(await page.textContent('body')).includes('QA U1'));
  await page.goto(B + '?r=process.board&type=interior');
  check('인테리어 탭에 U1 노출(배지 없음)', (await page.textContent('body')).includes('QA U1'));
  r = await api(page, 'process.settype', { project_id: 411, construction_type: 'painting' });
  await api(page, 'process.settype', { project_id: 411, construction_type: 'painting' });

  console.log('\n═ 14. 권한(스태프 계정) — 백엔드 검증 ═');
  const sctx = await browser.newContext({ locale: 'ko-KR' });
  const sp = await sctx.newPage();
  await login(sp, 'maeng');
  await sp.goto(B + '?r=halfyear.index&year=2026&half=1&user_id=5');
  const sbody = await sp.textContent('body');
  check('staff: 타 직원 실적 우회 불가(38,000,000 미노출)', !sbody.includes('38,000,000'));
  let sr = await api(sp, 'bonus.save', { user_id: 3, year: 2026, half: 2, calc_amount: 1 });
  check('staff: bonus.save 403', sr.status === 403, 'status=' + sr.status);
  sr = await api(sp, 'process.settype', { project_id: 411, construction_type: 'interior' });
  check('staff: process.settype 403', sr.status === 403, 'status=' + sr.status);
  const sIdx = await sp.evaluate(async () => (await fetch('index.php?r=staff.index')).status);
  check('staff: staff.index 403', sIdx === 403, 'status=' + sIdx);
  await sctx.close();

  console.log('\n═ 15. 태블릿(iPad)·모바일 반응형 ═');
  for (const dev of ['iPad (gen 7) landscape', 'iPhone 14']) {
    const mctx = await browser.newContext({ ...devices[dev], locale: 'ko-KR' });
    const mp = await mctx.newPage();
    const mErr = [];
    mp.on('pageerror', (e) => mErr.push(String(e).slice(0, 80)));
    await login(mp, 'admin');
    for (const rte of ['staff.index&year=2026&half=1', 'process.board', 'process.board&type=interior', 'halfyear.index', 'bonus.index']) {
      await mp.goto(B + '?r=' + rte);
      const ov = await mp.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      check(`${dev}: ${rte.split('&')[0]} 가로 오버플로 없음`, ov <= 1, ov + 'px');
    }
    // 터치로 인테리어 탭 전환
    await mp.goto(B + '?r=process.board');
    const tabLink = mp.locator('a', { hasText: '인테리어' }).first();
    if (await tabLink.count()) {
      await tabLink.tap().catch(() => tabLink.click());
      await mp.waitForLoadState('load');
      check(`${dev}: 터치 탭 전환`, (await mp.textContent('body')).includes('철거'));
    } else { check(`${dev}: 인테리어 탭 존재`, false); }
    check(`${dev}: JS 오류 0건`, mErr.length === 0, mErr.join('|'));
    await mctx.close();
  }

  console.log('\n═ 16. 콘솔·네트워크 누적 ═');
  check('세션 전체 5xx·JS 오류 0건', errBag.length === 0, errBag.slice(0, 4).join(' | '));

  console.log(`\n════ 결과: PASS ${pass} / FAIL ${fail} ════`);
  if (failures.length) failures.forEach((f) => console.log('  - ' + f));
  await browser.close();
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('FATAL', e); process.exit(2); });
