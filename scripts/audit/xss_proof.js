/**
 * XSS 실증 — 실제 브라우저에서 페이로드 실행 여부를 확인한다(감사 전용).
 * 선행: QASEC- 고객 데이터 존재. 사용: node scripts/audit/xss_proof.js
 */
const puppeteer = require('/Users/deoksookim/Desktop/코드/claude code/eden_crm/scripts/qa_browser/node_modules/puppeteer-core');
const CHROME = process.env.QA_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = process.env.QA_BASE || 'http://127.0.0.1:8080/index.php';
const ADMIN = { id: 'admin', pw: process.env.QA_ADMIN_PW || 'password123!' };

async function login(page, id, pw) {
  await page.goto(`${BASE}?r=login`, { waitUntil: 'domcontentloaded' });
  await page.type('input[name=login_id]', id);
  await page.type('input[name=password]', pw);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('button[type=submit]'),
  ]);
}

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  const fired = [];
  page.on('dialog', async (d) => { fired.push('dialog:' + d.message()); await d.dismiss(); });
  await page.evaluateOnNewDocument(() => { window.__XSS = []; });
  await login(page, ADMIN.id, ADMIN.pw);

  // ── 시나리오 1: 고객 등록 폼 → 전화번호 중복검사 → warnBox.innerHTML ──
  await page.goto(`${BASE}?r=customers.form`, { waitUntil: 'networkidle2' });
  await page.evaluate(() => { window.__XSS_HIT = 0; });
  // onerror 가 실행되면 __XSS_HIT 증가하도록 페이로드를 심어둔 데이터가 필요 → 실제 저장된 payload 는 alert
  await page.type('#fPhone', '010-9999-0001');
  await page.evaluate(() => document.getElementById('fEmail').focus());  // blur 유발
  await new Promise(r => setTimeout(r, 1200));
  const box = await page.evaluate(() => {
    const w = document.getElementById('dupWarning');
    return { html: w ? w.innerHTML : null, imgs: w ? w.querySelectorAll('img,svg,script').length : 0, cls: w ? w.className : null };
  });
  console.log('[시나리오1] dupWarning innerHTML =', JSON.stringify(box.html));
  console.log('[시나리오1] 주입된 활성 요소 수(img/svg/script) =', box.imgs);
  console.log('[시나리오1] dialog 발생 =', JSON.stringify(fired));

  await browser.close();
})().catch(e => { console.error('ERR', e); process.exit(1); });
