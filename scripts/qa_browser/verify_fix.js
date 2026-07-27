/* T2 수정 검증: 저장 성공 후 뒤로가기 초기화 / 검증 실패 시 보존 / 중복 제출 방지 */
const { chromium } = require('playwright');
const B = 'http://127.0.0.1:8080/index.php';
let pass = 0, fail = 0;
function check(name, ok, detail) {
  if (ok) { pass++; console.log('  ✅ ' + name); }
  else { fail++; console.log('  ❌ ' + name + (detail ? ' — ' + detail : '')); }
}

async function login(page) {
  await page.goto(B + '?r=login');
  await page.fill('input[name="login_id"]', 'admin');
  await page.fill('input[name="password"]', 'password123!');
  await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
}

async function filledUserFields(page) {
  return page.evaluate(() => {
    const out = [];
    document.querySelectorAll('form input[type="text"], form input[type="tel"], form input[type="email"], form textarea').forEach((el) => {
      if (el.value && el.value !== '0') out.push(el.name + '=' + el.value.slice(0, 30)); // '0' = 빈 행 기본값
    });
    return out;
  });
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ locale: 'ko-KR' });
  const page = await ctx.newPage();
  await login(page);

  console.log('\n[1] 고객 등록: 저장 성공 → 뒤로가기 시 폼 초기화');
  await page.goto(B + '?r=customers.form');
  const nm = 'QA검증_' + Date.now().toString().slice(-6);
  await page.fill('input[name="name"]', nm);
  await page.fill('input[name="phone"]', '010-7777-0003');
  await page.fill('textarea[name="memo"]', '검증 메모');
  await page.check('input[name="privacy_agreed"]');
  await Promise.all([page.waitForNavigation(), page.click('#customerForm button[type="submit"]')]);
  check('저장 후 상세 페이지 도착', /customers\.show/.test(page.url()), page.url());
  const flash = await page.locator('#serverFlash').textContent().catch(() => '');
  check('성공 플래시 표시', /저장/.test(flash || ''), flash);
  await page.goBack();
  await page.waitForLoadState('load');
  await page.waitForTimeout(700); // pageshow → reload 대기
  const restored = await filledUserFields(page);
  check('뒤로가기 후 입력값 완전 초기화', restored.length === 0, JSON.stringify(restored));

  console.log('\n[2] 견적 등록: 저장 성공 → 뒤로가기 시 폼 초기화');
  await page.goto(B + '?r=quotes.form');
  await page.selectOption('#customerSelect', { index: 1 });
  await page.fill('#itemsBody .item-row input[name$="[name]"]', '검증 항목 외벽도장');
  await page.fill('#itemsBody .item-row .f-price', '2500000');
  await page.fill('textarea[name="memo"]', '검증 특이사항');
  await Promise.all([page.waitForNavigation(), page.click('.page-actions button[type="submit"]')]);
  check('견적 저장 성공', /quotes\.show/.test(page.url()), page.url());
  await page.goBack();
  await page.waitForLoadState('load');
  await page.waitForTimeout(700);
  const qRestored = await filledUserFields(page);
  check('견적 폼 뒤로가기 초기화', qRestored.length === 0, JSON.stringify(qRestored));
  const rowCount = await page.locator('#itemsBody .item-row').count();
  check('견적 항목 빈 행 1개로 리셋', rowCount === 1, 'rows=' + rowCount);

  console.log('\n[3] 서버 검증 실패 → 뒤로가기 시 입력값 보존(재작성 보호)');
  await page.goto(B + '?r=quotes.form');
  await page.fill('#itemsBody .item-row input[name$="[name]"]', '보존되어야 할 항목');
  // 고객 미선택 상태로 required 우회 제출 → 서버 검증 실패(error flash 리다이렉트)
  await page.evaluate(() => { document.getElementById('customerSelect').required = false; });
  await Promise.all([page.waitForNavigation(), page.click('.page-actions button[type="submit"]')]);
  const errFlash = await page.locator('#serverFlash').textContent().catch(() => '');
  check('검증 실패 플래시(고객 선택)', /고객을 선택/.test(errFlash || ''), errFlash);
  await page.goBack();
  await page.waitForLoadState('load');
  await page.waitForTimeout(700);
  const preserved = await page.evaluate(() => {
    const i = document.querySelector('#itemsBody .item-row input[name$="[name]"]');
    return i ? i.value : '(없음)';
  });
  check('실패 후 뒤로가기: 입력값 보존', preserved === '보존되어야 할 항목', preserved);

  console.log('\n[4] 중복 제출 방지(더블클릭)');
  await page.goto(B + '?r=customers.form');
  const dupName = 'QA중복_' + Date.now().toString().slice(-6);
  await page.fill('input[name="name"]', dupName);
  await page.check('input[name="privacy_agreed"]');
  await page.evaluate(() => {
    const btn = document.querySelector('#customerForm button[type="submit"]');
    btn.click(); btn.click(); btn.click(); // 연타
  });
  await page.waitForLoadState('load');
  await page.waitForTimeout(500);
  const dupCount = await page.evaluate(async (n) => {
    const d = await window.api('customers.dupcheck', { phone: '', email: '' }, { method: 'GET' }).catch(() => null);
    return null; // dupcheck 는 phone/email 기준이라 이름 검색 불가 — 목록 검색 사용
  }, dupName).catch(() => null);
  // 목록 검색으로 동일명 카운트
  await page.goto(B + '?r=customers.index&q=' + encodeURIComponent(dupName));
  const rows = await page.locator('table tbody tr').count();
  check('연타 제출에도 1건만 등록', rows === 1, 'rows=' + rows);

  console.log('\n[5] IME 회귀(수정 후에도 조합 간섭 없음)');
  await page.goto(B + '?r=customers.form');
  const el = page.locator('input[name="name"]');
  await el.click();
  const cdp = await ctx.newCDPSession(page);
  for (const syll of [['ㅎ', '하', '한'], ['ㄱ', '그', '글']]) {
    for (const step of syll) {
      await cdp.send('Input.imeSetComposition', { text: step, selectionStart: step.length, selectionEnd: step.length });
      await page.waitForTimeout(20);
    }
    await cdp.send('Input.insertText', { text: syll[syll.length - 1] });
  }
  const imeVal = await el.inputValue();
  check('IME 조합 입력 정상("한글")', imeVal === '한글', imeVal);

  console.log(`\n결과: PASS ${pass} / FAIL ${fail}`);
  await browser.close();
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('FATAL', e); process.exit(2); });
