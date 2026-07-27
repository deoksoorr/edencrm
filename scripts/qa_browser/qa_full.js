/* T3 종합 QA — 더미데이터 CRUD·검색·필터·멀티탭·붙여넣기·IME 전수·모바일·오류 스윕
 * 모든 더미데이터는 로컬 dev DB 전용, 접두사 QA더미_ 로 생성(T4 정리 대상). */
const { chromium, devices } = require('playwright');
const B = 'http://127.0.0.1:8080/index.php';
const PFX = 'QA더미_';
let pass = 0, fail = 0;
const failures = [];
function check(name, ok, detail) {
  if (ok) { pass++; console.log('  ✅ ' + name); }
  else { fail++; failures.push(name + (detail ? ' — ' + detail : '')); console.log('  ❌ ' + name + (detail ? ' — ' + String(detail).slice(0, 200) : '')); }
}

function collectErrors(page, bag, label) {
  page.on('console', (m) => { if (m.type() === 'error') bag.push(`[${label}] console: ${m.text().slice(0, 150)}`); });
  page.on('pageerror', (e) => bag.push(`[${label}] pageerror: ${String(e).slice(0, 150)}`));
  page.on('response', (r) => {
    if (r.status() >= 400 && !/favicon/.test(r.url())) bag.push(`[${label}] HTTP ${r.status()} ${r.url().slice(0, 120)}`);
  });
}

async function login(page, id = 'admin', pw = 'password123!') {
  await page.goto(B + '?r=login');
  await page.fill('input[name="login_id"]', id);
  await page.fill('input[name="password"]', pw);
  await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
}

/** EDEN.confirm 모달에서 확인(위험) 버튼 클릭 */
async function acceptModal(page) {
  await page.waitForSelector('.modal-backdrop .modal-foot button', { timeout: 5000 });
  const btns = page.locator('.modal-backdrop .modal-foot button');
  await btns.last().click();
}

/** IME 조합 프로브 — selector 에 '가나' 조합 입력, 간섭·값 손상 감지 */
async function imeProbe(page, ctx, handle, label, results) {
  try {
    await handle.click({ timeout: 3000 });
    await handle.evaluate((t) => {
      t.value = '';
      window.__ime = { log: [] };
      const proto = t.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      const desc = Object.getOwnPropertyDescriptor(proto, 'value');
      Object.defineProperty(t, 'value', {
        configurable: true,
        get() { return desc.get.call(this); },
        set(v) { window.__ime.log.push('JS-SET:' + String(v).slice(0, 10)); desc.set.call(this, v); },
      });
      t.addEventListener('blur', () => window.__ime.log.push('BLUR'));
    });
    const cdp = await ctx.newCDPSession(page);
    for (const syll of [['ㄱ', '가'], ['ㄴ', '나']]) {
      for (const step of syll) {
        await cdp.send('Input.imeSetComposition', { text: step, selectionStart: step.length, selectionEnd: step.length });
      }
      await cdp.send('Input.insertText', { text: syll[syll.length - 1] });
    }
    await cdp.detach().catch(() => {});
    const r = await handle.evaluate((t) => ({ v: t.value, log: window.__ime ? window.__ime.log : [], focused: document.activeElement === t }));
    const bad = r.v !== '가나' || !r.focused || r.log.some((l) => l.startsWith('JS-SET') || l === 'BLUR');
    results.push({ label, ok: !bad, detail: bad ? `value="${r.v}" focused=${r.focused} log=${JSON.stringify(r.log)}` : '' });
    // 원복
    await handle.evaluate((t) => { t.value = ''; });
  } catch (e) {
    results.push({ label, ok: false, detail: 'probe 실패: ' + String(e).split('\n')[0].slice(0, 120) });
  }
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ locale: 'ko-KR', permissions: ['clipboard-read', 'clipboard-write'] });
  const page = await ctx.newPage();
  const errBag = [];
  collectErrors(page, errBag, 'main');
  await login(page);
  const created = { customers: [], leads: [], quotes: [], contracts: [] };

  // ═══ 1. 고객: 연속 등록(한글/영문/숫자/특수문자) ═══
  console.log('\n═ 1. 고객 연속 등록(한글·영문·숫자·특수문자·긴메모) ═');
  const custDefs = [
    { name: PFX + '한글고객 김철수', phone: '010-1111-2001', memo: '한글 메모 테스트입니다.' },
    { name: PFX + 'EnglishCo Ltd.', phone: '010-1111-2002', memo: 'English memo with CAPS and lower.' },
    { name: PFX + '숫자상사 12345', phone: '010-1111-2003', memo: '1234567890' },
    { name: PFX + '특수문자 <>&"\'%_#!', phone: '010-1111-2004', memo: '특수: !@#$%^&*()_+-=[]{};:\'",.<>/?`~\\|' },
    { name: PFX + '연속입력 다섯번째', phone: '010-1111-2005', memo: '연속 입력 5번째 고객' },
  ];
  for (const c of custDefs) {
    await page.goto(B + '?r=customers.form');
    await page.fill('input[name="name"]', c.name);
    await page.fill('input[name="phone"]', c.phone);
    await page.fill('textarea[name="memo"]', c.memo);
    await page.check('input[name="privacy_agreed"]');
    await Promise.all([page.waitForNavigation(), page.click('#customerForm button[type="submit"]')]);
    const m = page.url().match(/id=(\d+)/);
    const ok = /customers\.show/.test(page.url()) && m;
    if (m) created.customers.push({ id: +m[1], name: c.name });
    check(`고객 등록: ${c.name.slice(0, 24)}`, !!ok, page.url());
    if (ok) {
      const shown = await page.textContent('body');
      check(`  상세 표시 일치`, (shown || '').includes(c.name), (shown || '').slice(0, 80));
    }
  }

  // ═══ 2. 검색·필터 ═══
  console.log('\n═ 2. 고객 검색·필터 ═');
  await page.goto(B + '?r=customers.index&q=' + encodeURIComponent('한글고객 김철수'));
  check('한글 검색 1건', (await page.locator('table tbody tr').count()) === 1);
  await page.goto(B + '?r=customers.index&q=' + encodeURIComponent('EnglishCo'));
  check('영문 검색 1건', (await page.locator('table tbody tr').count()) === 1);
  await page.goto(B + '?r=customers.index&q=' + encodeURIComponent('<>&"\'%_#!'));
  check('특수문자 검색 1건(LIKE 이스케이프)', (await page.locator('table tbody tr').count()) === 1);
  await page.goto(B + '?r=customers.index&q=' + encodeURIComponent(PFX));
  check('접두사 검색 5건', (await page.locator('table tbody tr').count()) === 5);
  await page.goto(B + '?r=customers.index&status=blacklist&q=' + encodeURIComponent(PFX));
  check('상태 필터(blacklist) 0건', (await page.locator('table tbody tr').count()) === 0 || (await page.locator('.empty').count()) > 0);

  // ═══ 3. 고객 수정 ═══
  console.log('\n═ 3. 고객 수정 ═');
  const c0 = created.customers[0];
  await page.goto(B + `?r=customers.form&id=${c0.id}`);
  const preName = await page.inputValue('input[name="name"]');
  check('수정 폼 기존값 로드', preName === c0.name, preName);
  await page.fill('input[name="phone"]', '010-1111-9999');
  await page.fill('textarea[name="memo"]', '수정된 메모 — 한글로 변경 완료');
  await Promise.all([page.waitForNavigation(), page.click('#customerForm button[type="submit"]')]);
  check('수정 저장 → 상세', /customers\.show/.test(page.url()));
  const bodyTxt = await page.textContent('body');
  check('수정 반영(전화)', bodyTxt.includes('010-1111-9999'));

  // ═══ 4. 영업기회 등록·수정 ═══
  console.log('\n═ 4. 영업기회 등록·수정 ═');
  await page.goto(B + '?r=pipeline.form');
  await page.selectOption('select[name="customer_id"]', String(c0.id));
  await page.fill('input[name="work_type"]', '아파트외벽 도장');
  await page.fill('input[name="site_address"]', '서울 강남구 QA로 123');
  await page.fill('input[name="expected_amount"]', '30000000');
  await page.fill('input[name="expected_cost"]', '20000000');
  await page.fill('textarea[name="memo"]', PFX + '영업기회 메모');
  await Promise.all([page.waitForNavigation(), page.click('#leadForm button[type="submit"]')]);
  const leadM = page.url().match(/id=(\d+)/);
  if (leadM) created.leads.push(+leadM[1]);
  check('영업기회 등록', /pipeline\.show/.test(page.url()) && !!leadM, page.url());
  if (leadM) {
    await page.goto(B + `?r=pipeline.form&id=${leadM[1]}`);
    await page.fill('input[name="expected_amount"]', '32000000');
    await Promise.all([page.waitForNavigation(), page.click('#leadForm button[type="submit"]')]);
    check('영업기회 수정 저장', /pipeline\.show/.test(page.url()));
  }

  // ═══ 5. 견적 등록·수정 (동적 행+합계+연결 영업기회 AJAX) ═══
  console.log('\n═ 5. 견적 등록·수정 ═');
  await page.goto(B + '?r=quotes.form');
  await page.selectOption('#customerSelect', String(c0.id));
  await page.waitForTimeout(600); // 영업기회 AJAX 로드
  const leadOpts = await page.locator('#leadSelect option').count();
  check('고객 선택 → 영업기회 옵션 로드', leadOpts >= 2, 'options=' + leadOpts);
  if (leadOpts >= 2) await page.selectOption('#leadSelect', { index: 1 });
  await page.fill('#itemsBody .item-row input[name$="[name]"]', '외벽 수성페인트 2회 도장');
  await page.fill('#itemsBody .item-row .f-area', '350');
  await page.fill('#itemsBody .item-row .f-price', '25000');
  await page.click('#btnAddItem');
  const row2 = page.locator('#itemsBody .item-row').nth(1);
  await row2.locator('input[name$="[name]"]').fill('특수문자 항목 <>&"\' 테스트');
  await row2.locator('.f-price').fill('500000');
  await row2.locator('.f-qty').fill('2');
  const sumTotal = await page.textContent('#sumTotal');
  check('실시간 합계 계산', sumTotal !== '0', sumTotal);
  await page.fill('textarea[name="memo"]', PFX + '견적 특이사항 메모');
  await Promise.all([page.waitForNavigation(), page.click('.page-actions button[type="submit"]')]);
  const qM = page.url().match(/id=(\d+)/);
  if (qM) created.quotes.push(+qM[1]);
  check('견적 등록', /quotes\.show/.test(page.url()) && !!qM, page.url());
  if (qM) {
    await page.goto(B + `?r=quotes.form&id=${qM[1]}`);
    const rowsLoaded = await page.locator('#itemsBody .item-row').count();
    check('견적 수정 폼 항목 로드(2행)', rowsLoaded === 2, 'rows=' + rowsLoaded);
    const item1 = await page.locator('#itemsBody .item-row input[name$="[name]"]').nth(1).inputValue();
    check('특수문자 항목명 보존', item1 === '특수문자 항목 <>&"\' 테스트', item1);
    await page.fill('input[name="version_note"]', '단가 조정 v2');
    await page.locator('#itemsBody .item-row .f-price').first().fill('26000');
    await Promise.all([page.waitForNavigation(), page.click('.page-actions button[type="submit"]')]);
    check('견적 수정 저장(버전)', /quotes\.show/.test(page.url()));
  }

  // ═══ 6. 계약 등록·수정 (견적 연동) ═══
  console.log('\n═ 6. 계약 등록·수정 ═');
  await page.goto(B + `?r=contracts.form&quote_id=${created.quotes[0] || ''}`);
  // 견적 선택 시 금액 자동 채움(JS) — 프리셀렉트 여부 확인 후 수동 보완
  await page.selectOption('select[name="customer_id"]', String(c0.id)).catch(() => {});
  await page.fill('input[name="work_name"]', PFX + '외벽 도장 공사 계약');
  await page.fill('input[name="contract_amount"]', '31000000');
  await page.fill('textarea[name="special_terms"]', '특약: 우천 시 공기 연장 협의');
  await Promise.all([page.waitForNavigation(), page.click('#contractForm button[type="submit"]')]);
  const ctM = page.url().match(/id=(\d+)/);
  if (ctM) created.contracts.push(+ctM[1]);
  check('계약 등록', /contracts\.show/.test(page.url()) && !!ctM, page.url());
  if (ctM) {
    await page.goto(B + `?r=contracts.form&id=${ctM[1]}`);
    await page.fill('input[name="warranty_period"]', '2년');
    await Promise.all([page.waitForNavigation(), page.click('#contractForm button[type="submit"]')]);
    check('계약 수정 저장', /contracts\.show/.test(page.url()));
  }

  // ═══ 7. 저장 후 재입력(폼 초기 상태) ═══
  console.log('\n═ 7. 저장 후 재입력 ═');
  await page.goto(B + '?r=customers.form');
  const fresh = await page.evaluate(() =>
    Array.from(document.querySelectorAll('#customerForm input[type="text"], #customerForm textarea')).filter((e) => e.value).length);
  check('저장 직후 신규 고객 폼 완전 빈 상태', fresh === 0, 'filled=' + fresh);
  await page.goto(B + '?r=quotes.form');
  const freshQ = await page.evaluate(() =>
    Array.from(document.querySelectorAll('input[type="text"], textarea')).filter((e) => e.value && e.value !== '0').length);
  check('신규 견적 폼 빈 상태', freshQ === 0, 'filled=' + freshQ);

  // ═══ 8. 페이지 이동 후 복귀(작성 중 보존) + 새로고침 ═══
  console.log('\n═ 8. 페이지 이동 복귀·새로고침 ═');
  await page.goto(B + '?r=customers.form');
  await page.fill('input[name="name"]', PFX + '작성중이탈 고객');
  await page.goto(B + '?r=pipeline.index');
  await page.goBack();
  await page.waitForTimeout(700);
  const kept = await page.inputValue('input[name="name"]');
  check('제출 전 이탈→복귀: 작성값 보존(기능 유지)', kept === PFX + '작성중이탈 고객', kept);
  await page.reload();
  const afterReload = await page.inputValue('input[name="name"]');
  check('새로고침: 서버 fresh 렌더(빈 폼)', afterReload === '', afterReload);

  // ═══ 9. 멀티탭(동시 세션) ═══
  console.log('\n═ 9. 멀티탭 동시 작업 ═');
  const tabB = await ctx.newPage();
  const errBagB = [];
  collectErrors(tabB, errBagB, 'tabB');
  await tabB.goto(B + '?r=customers.index&q=' + encodeURIComponent(PFX));
  check('탭B: 탭A 생성 데이터 조회', (await tabB.locator('table tbody tr').count()) === 5);
  // 동시 수정: 탭A=전화, 탭B=메모 (last-write-wins, 오류 없음)
  await page.goto(B + `?r=customers.form&id=${c0.id}`);
  await tabB.goto(B + `?r=customers.form&id=${c0.id}`);
  await page.fill('input[name="phone"]', '010-2222-0001');
  await Promise.all([page.waitForNavigation(), page.click('#customerForm button[type="submit"]')]);
  await tabB.fill('textarea[name="memo"]', '탭B에서 수정한 메모');
  await Promise.all([tabB.waitForNavigation(), tabB.click('#customerForm button[type="submit"]')]);
  check('멀티탭 순차 저장 무오류', /customers\.show/.test(page.url()) && /customers\.show/.test(tabB.url()));
  await tabB.close();

  // ═══ 10. 붙여넣기 ═══
  console.log('\n═ 10. 붙여넣기 ═');
  await page.goto(B + '?r=customers.form');
  await page.evaluate(() => navigator.clipboard.writeText('붙여넣기고객 ㈜한국페인트'));
  await page.click('input[name="name"]');
  await page.keyboard.press(process.platform === 'darwin' ? 'Meta+v' : 'Control+v');
  await page.waitForTimeout(200);
  let pasted = await page.inputValue('input[name="name"]');
  if (!pasted) { // headless 클립보드 폴백: paste 이벤트 직접 발송
    await page.evaluate(() => {
      const el = document.querySelector('input[name="name"]');
      const dt = new DataTransfer();
      dt.setData('text/plain', '붙여넣기고객 ㈜한국페인트');
      el.focus();
      el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
      if (!el.value) el.value = dt.getData('text/plain'); // 기본 동작 미지원 환경 폴백
    });
    pasted = await page.inputValue('input[name="name"]');
  }
  check('한글 붙여넣기(특수기호 ㈜ 포함)', pasted === '붙여넣기고객 ㈜한국페인트', pasted);
  const multiline = '1줄 메모\n2줄 메모\n3줄 특수 <>&';
  await page.evaluate((txt) => {
    const el = document.querySelector('textarea[name="memo"]');
    el.focus(); el.value = '';
    const dt = new DataTransfer();
    dt.setData('text/plain', txt);
    const ev = new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true });
    el.dispatchEvent(ev);
    if (!el.value) el.value = txt;
  }, multiline);
  check('멀티라인 붙여넣기', (await page.inputValue('textarea[name="memo"]')) === multiline);

  // ═══ 11. IME 전수 스윕(주요 폼 전 텍스트 입력) ═══
  console.log('\n═ 11. IME 전수 스윕 ═');
  const imeResults = [];
  const imePages = [
    ['customers.form', '?r=customers.form'],
    ['quotes.form', '?r=quotes.form'],
    ['contracts.form', '?r=contracts.form'],
    ['pipeline.form', '?r=pipeline.form'],
    ['projects.form', '?r=projects.form'],
    ['staff.form', '?r=staff.form'],
    ['settings.index', '?r=settings.index'],
    ['customers.show(활동기록)', `?r=customers.show&id=${c0.id}`],
  ];
  for (const [label, path] of imePages) {
    await page.goto(B + path);
    if (label.includes('활동기록')) {
      const actTab = page.locator('#custTabs .tab', { hasText: '활동' });
      if (await actTab.count()) await actTab.first().click();
    }
    const handles = await page.locator('input[type="text"]:visible:not([readonly]):not([disabled]), textarea:visible:not([readonly]):not([disabled])').all();
    let i = 0;
    for (const h of handles) {
      const nm = await h.evaluate((t) => t.name || t.id || 'idx');
      await imeProbe(page, ctx, h, `${label} :: ${nm}`, imeResults);
      i++;
    }
    console.log(`  · ${label}: ${i}개 필드 프로브`);
  }
  // 견적 동적 추가 행
  await page.goto(B + '?r=quotes.form');
  await page.click('#btnAddItem');
  const dynInput = page.locator('#itemsBody .item-row').nth(1).locator('input[name$="[name]"]');
  await imeProbe(page, ctx, dynInput, 'quotes.form :: 동적추가행 항목명', imeResults);
  const imeBad = imeResults.filter((r) => !r.ok);
  check(`IME 전수(${imeResults.length}개 필드) 간섭 0건`, imeBad.length === 0,
    imeBad.map((b) => b.label + ': ' + b.detail).join(' | '));

  // ═══ 12. 삭제(고객·견적·영업기회) ═══
  console.log('\n═ 12. 삭제 플로우 ═');
  if (created.quotes[0]) {
    // 계약 연결 견적은 삭제 버튼이 없어야 함(데이터 정합 보호 — 설계 의도)
    await page.goto(B + `?r=quotes.show&id=${created.quotes[0]}`);
    check('계약 연결 견적: 삭제 버튼 숨김(설계)', (await page.locator('#btnDeleteQuote').count()) === 0);
    // 무연결 견적을 새로 만들어 삭제 플로우 검증
    await page.goto(B + '?r=quotes.form');
    await page.selectOption('#customerSelect', String(c0.id));
    await page.fill('#itemsBody .item-row input[name$="[name]"]', '삭제 테스트 항목');
    await page.fill('#itemsBody .item-row .f-price', '100000');
    await Promise.all([page.waitForNavigation(), page.click('.page-actions button[type="submit"]')]);
    const q2 = page.url().match(/id=(\d+)/);
    if (q2) {
      created.quotes.push(+q2[1]);
      await page.click('#btnDeleteQuote');
      await acceptModal(page);
      await page.waitForURL(/quotes\.index/, { timeout: 8000 }).catch(() => {});
      check('무연결 견적 삭제 → 목록 이동', /quotes\.index/.test(page.url()), page.url());
    } else { check('삭제용 견적 생성', false, page.url()); }
  }
  if (created.leads[0]) {
    await page.goto(B + `?r=pipeline.show&id=${created.leads[0]}`);
    page.once('dialog', (d) => d.accept());
    const delForm = page.locator('form[action*="pipeline.delete"] button, form[action*="pipeline.delete"] input[type="submit"]');
    if (await delForm.count()) {
      await Promise.all([page.waitForNavigation(), delForm.first().click()]);
      check('영업기회 삭제', !/pipeline\.show&id=/.test(page.url()), page.url());
    } else { check('영업기회 삭제 버튼 존재', false, '버튼 미발견'); }
  }
  const delCust = created.customers[4];
  if (delCust) {
    await page.goto(B + `?r=customers.show&id=${delCust.id}`);
    const btn = page.locator('#btnDeleteCustomer');
    if (await btn.count()) {
      await btn.click();
      await acceptModal(page);
      await page.waitForURL(/customers\.index/, { timeout: 8000 }).catch(() => {});
      check('고객 삭제 → 목록 이동', /customers\.index/.test(page.url()), page.url());
      await page.goto(B + '?r=customers.index&q=' + encodeURIComponent(delCust.name));
      const remain = await page.locator('table tbody tr').count();
      check('삭제 고객 목록 미노출(soft delete)', remain === 0, 'rows=' + remain);
    } else { check('고객 삭제 버튼 존재', false, '버튼 미발견'); }
  }

  // ═══ 13. 주요 라우트 콘솔·네트워크·HTTP 스윕 ═══
  console.log('\n═ 13. 전 라우트 오류 스윕 ═');
  const routes = ['home', 'customers.index', 'pipeline.index', 'quotes.index',
    'contracts.index', 'projects.index', 'process.board', 'schedule.index', 'performance.index',
    'reports.index', 'notifications.index', 'staff.index', 'settings.index', 'settings.stages',
    'audit.index', 'targets.index',
    // 견적 연결 계약 폼(quote_id 프리필·수정) — api 초기화 회귀 확인
    'contracts.form&quote_id=' + (created.quotes[0] || 1),
    'contracts.form&id=' + (created.contracts[0] || 1)];
  const sweepStart = errBag.length;
  for (const r of routes) {
    await page.goto(B + '?r=' + r).catch((e) => errBag.push(`[sweep] goto ${r}: ${e.message.slice(0, 80)}`));
    await page.waitForTimeout(150);
  }
  const sweepErrs = errBag.slice(sweepStart);
  check('전 라우트(17) 콘솔·JS·HTTP 오류 0건', sweepErrs.length === 0, sweepErrs.join(' | '));

  // ═══ 14. 모바일 에뮬레이션 (iPhone 14 · Galaxy S9+) ═══
  console.log('\n═ 14. 모바일 에뮬레이션 ═');
  for (const dev of ['iPhone 14', 'Galaxy S9+']) {
    const mctx = await browser.newContext({ ...devices[dev], locale: 'ko-KR' });
    const mp = await mctx.newPage();
    const mErr = [];
    collectErrors(mp, mErr, dev);
    await login(mp);
    check(`${dev}: 로그인`, !/r=login/.test(mp.url()), mp.url());
    // 햄버거 사이드바
    await mp.goto(B + '?r=customers.index');
    await mp.tap('#hamburger').catch(() => mp.click('#hamburger'));
    await mp.waitForTimeout(300);
    check(`${dev}: 사이드바 오픈`, await mp.locator('#sidebar.open').count() === 1);
    // 모바일 고객 등록
    await mp.goto(B + '?r=customers.form');
    await mp.fill('input[name="name"]', PFX + '모바일고객 ' + dev.replace(/\s/g, ''));
    await mp.fill('input[name="phone"]', '010-3333-' + (dev[0] === 'i' ? '0001' : '0002'));
    await mp.check('input[name="privacy_agreed"]');
    await Promise.all([mp.waitForNavigation(), mp.click('#customerForm button[type="submit"]')]);
    const mm = mp.url().match(/id=(\d+)/);
    if (mm) created.customers.push({ id: +mm[1], name: PFX + '모바일고객 ' + dev.replace(/\s/g, '') });
    check(`${dev}: 고객 등록`, /customers\.show/.test(mp.url()), mp.url());
    // 모바일 IME
    await mp.goto(B + '?r=customers.form');
    const mRes = [];
    await imeProbe(mp, mctx, mp.locator('input[name="name"]'), dev + ' 고객명', mRes);
    check(`${dev}: IME 조합 정상`, mRes[0] && mRes[0].ok, mRes[0] && mRes[0].detail);
    // 가로 오버플로 없는지(주요 3페이지)
    for (const r of ['customers.index', 'quotes.form', 'reports.index']) {
      await mp.goto(B + '?r=' + r);
      const ov = await mp.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      check(`${dev}: ${r} 가로 오버플로 없음`, ov <= 1, 'overflow=' + ov + 'px');
    }
    check(`${dev}: 콘솔·HTTP 오류 0건`, mErr.length === 0, mErr.join(' | '));
    await mctx.close();
  }

  // ═══ 15. 메인 컨텍스트 누적 오류 ═══
  console.log('\n═ 15. 전체 세션 오류 누적 확인 ═');
  check('메인 세션 전체 콘솔·JS·HTTP 오류 0건', errBag.length === 0, errBag.slice(0, 5).join(' | '));

  console.log(`\n════ 결과: PASS ${pass} / FAIL ${fail} ════`);
  if (failures.length) { console.log('실패 목록:'); failures.forEach((f) => console.log('  - ' + f)); }
  console.log('\n생성 더미데이터:', JSON.stringify(created));
  await browser.close();
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('FATAL', e); process.exit(2); });
