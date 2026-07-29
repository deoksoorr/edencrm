const puppeteer = require('/Users/deoksookim/Desktop/코드/claude code/eden_crm/scripts/qa_browser/node_modules/puppeteer-core');
const CHROME = process.env.QA_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = 'http://127.0.0.1:8080/index.php';
async function login(page, id, pw) {
  await page.goto(`${BASE}?r=login`, { waitUntil: 'domcontentloaded' });
  await page.type('input[name=login_id]', id);
  await page.type('input[name=password]', pw);
  await Promise.all([ page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type=submit]') ]);
}
(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  let dialogs = [];
  page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
  await login(page, 'admin', process.env.QA_ADMIN_PW || 'password123!');

  // A6: projects.show → cfg json_encode script block (</script> breakout attempt)
  await page.goto(`${BASE}?r=projects.show&id=2905`, { waitUntil: 'networkidle2' });
  const a6 = await page.evaluate(() => window.__XSS_A6 === 1);
  console.log('[A6] json_encode </script> breakout executed? =', a6, '(false = NOT exploitable, slash-escaped)');
  console.log('[A6] dialogs so far =', JSON.stringify(dialogs));

  await browser.close();
})().catch(e => { console.error('ERR', e); process.exit(1); });
