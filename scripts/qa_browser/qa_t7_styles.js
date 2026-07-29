/**
 * T7 — 계산된 스타일 스냅샷. CSS 정리(중복 병합·죽은 규칙 제거)가
 * 실제 렌더에 영향을 주지 않았음을 기계적으로 증명하기 위한 도구.
 * 사용: node scripts/qa_browser/qa_t7_styles.js out.json
 */
const fs = require('fs');
const puppeteer = require('puppeteer-core');
const CHROME = process.env.QA_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = process.env.QA_BASE || 'http://127.0.0.1:8080/index.php';

const VIEWPORTS = [[1440, 900], [900, 1180], [390, 844]];
const ROUTES = [
  'dashboard', 'customers.index', 'customers.show&id=9001', 'customers.form&id=9001',
  'pipeline.index', 'pipeline.show&id=9001', 'quotes.index', 'quotes.show&id=9001',
  'quotes.form&id=9001', 'contracts.index', 'contracts.show&id=9001', 'contracts.form&id=9001',
  'projects.index', 'projects.show&id=9001', 'projects.form&id=9001', 'process.board',
  'schedule.index', 'reports.index', 'reports.attendance', 'performance.index',
  'performance.user&id=1', 'halfyear.index', 'bonus.index', 'bonus.history', 'targets.index',
  'notifications.index', 'staff.index', 'staff.show&id=2', 'staff.form&id=2',
  'settings.index', 'settings.stages', 'audit.index', 'password.change',
];

const PROPS = ['display', 'position', 'width', 'height', 'margin', 'padding', 'border',
  'border-radius', 'background-color', 'color', 'font-size', 'font-weight', 'flex',
  'grid-template-columns', 'gap', 'align-items', 'justify-content', 'overflow',
  'white-space', 'text-overflow', 'opacity', 'cursor', 'line-height', 'min-width', 'max-width'];

const SNAP = `(() => {
  const props = ${JSON.stringify(PROPS)};
  const out = {};
  const nodes = document.querySelectorAll('body *');
  let i = 0;
  nodes.forEach((el) => {
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    const key = i++ + ':' + el.tagName.toLowerCase() + '.' + (typeof el.className === 'string' ? el.className.trim().replace(/\\s+/g, '.') : '');
    const v = { _r: [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)] };
    props.forEach((p) => { v[p] = cs.getPropertyValue(p); });
    out[key] = v;
  });
  return out;
})()`;

(async () => {
  const outPath = process.argv[2];
  if (!outPath) { console.error('usage: node qa_t7_styles.js out.json'); process.exit(1); }
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  await page.goto(`${BASE}?r=login`, { waitUntil: 'domcontentloaded' });
  await page.type('input[name=login_id]', 'admin');
  await page.type('input[name=password]', process.env.QA_ADMIN_PW || 'password123!');
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type=submit]')]);

  const snap = {};
  for (const [w, h] of VIEWPORTS) {
    await page.setViewport({ width: w, height: h, isMobile: w <= 480, hasTouch: w <= 480 });
    for (const r of ROUTES) {
      await page.goto(`${BASE}?r=${r}`, { waitUntil: 'networkidle2', timeout: 25000 });
      await new Promise((s) => setTimeout(s, 200));
      snap[`${w}|${r}`] = await page.evaluate(SNAP);
    }
    console.log('done ' + w);
  }
  fs.writeFileSync(outPath, JSON.stringify(snap));
  await browser.close();
})();
