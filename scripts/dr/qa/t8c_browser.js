/**
 * DR 테스트 T8-c — 복구본 브라우저 렌더링(PC·모바일).
 *
 * HTTP 200 은 "서버가 응답했다"까지만 말해준다. 실제로 화면이 그려지는지, JS 가
 * 콘솔 오류 없이 도는지, 이미지·CSS 가 로드되는지는 브라우저로만 확인된다.
 * 복구 검증에서 "사장이 내일 이 화면으로 업무를 볼 수 있는가"에 해당하는 부분이다.
 */
// 이 저장소에는 puppeteer-core 만 설치돼 있다(번들 크로미움 없음) → 시스템 크롬을 쓴다.
const puppeteer = require('/Users/deoksookim/Desktop/코드/claude code/eden_crm/scripts/qa_browser/node_modules/puppeteer-core');
const CHROME = process.env.QA_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const L = require('./lib_dr.js');
const { chk, pass, fail, warn, info, section, saveResults, RESTORE_ROOT } = L;

const BASE = 'http://127.0.0.1:8091/index.php';
const CRED = ['admin', 'QArestore!2026admin'];

const VIEWPORTS = [
  ['PC', { width: 1440, height: 900, isMobile: false }],
  ['모바일', { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 3 }],
];

const PAGES = [
  ['대시보드', 'dashboard'],
  ['고객', 'customers.index'],
  ['계약', 'contracts.index'],
  ['프로젝트', 'projects.index'],
  ['공정보드', 'process.board'],
  ['일정', 'schedule.index'],
  ['분석', 'reports.index'],
];

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });

  for (const [vpName, vp] of VIEWPORTS) {
    section(`브라우저 렌더링 — ${vpName}`);
    // 뷰포트마다 독립 컨텍스트를 쓴다. 같은 컨텍스트를 공유하면 쿠키가 이어져
    // 두 번째 뷰포트에서는 이미 로그인 상태가 되고, 로그인 흐름 자체를 검증하지 못한다.
    const ctx = browser.createBrowserContext
      ? await browser.createBrowserContext()
      : await browser.createIncognitoBrowserContext();
    const page = await ctx.newPage();
    await page.setViewport(vp);

    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 160)); });
    page.on('pageerror', (e) => consoleErrors.push(`pageerror: ${String(e).slice(0, 160)}`));
    page.on('requestfailed', (r) => failedRequests.push(`${r.url().slice(0, 90)} ${r.failure()?.errorText || ''}`));

    // 로그인
    await page.goto(`${BASE}?r=login`, { waitUntil: 'domcontentloaded' });
    await page.type('input[name="login_id"]', CRED[0]);
    await page.type('input[name="password"]', CRED[1]);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
      page.click('button[type="submit"], input[type="submit"]'),
    ]);
    // 리다이렉트가 끝난 뒤 대시보드로 직접 가서 판정한다(제출 직후 화면은 중간 상태일 수 있다).
    await page.goto(`${BASE}?r=dashboard`, { waitUntil: 'networkidle2' });
    // 로그아웃 링크는 드롭다운 안에 있어 innerText(가시 텍스트)에 잡히지 않는다.
    // 세션 유무는 DOM 에 로그아웃 라우트가 있는지로 판정한다.
    const loggedIn = await page.evaluate(() => document.body.innerHTML.includes('r=logout'));
    chk(`${vpName} 로그인`, loggedIn, loggedIn ? '대시보드 진입' : '진입 실패');

    // 복구 환경 배너가 실제로 보이는가 (렌더링 확인 — 존재만이 아니라 표시 여부)
    const bannerVisible = await page.evaluate(() => {
      const el = document.getElementById('dr-restore-banner');
      if (!el) return false;
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.height > 0 && getComputedStyle(el).display !== 'none';
    });
    chk(`${vpName} 복구환경 배너 가시`, bannerVisible, bannerVisible ? '화면 상단 표시 확인' : '미표시');

    for (const [label, route] of PAGES) {
      consoleErrors.length = 0;
      failedRequests.length = 0;
      const res = await page.goto(`${BASE}?r=${route}`, { waitUntil: 'networkidle2' }).catch(() => null);
      const status = res ? res.status() : 0;

      const m = await page.evaluate(() => ({
        text: document.body.innerText.trim().length,
        hasNav: !!document.querySelector('nav, .sidebar, .gnb, header'),
        // 가로 스크롤은 모바일 레이아웃 붕괴의 대표 신호다.
        overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
        scrollW: document.documentElement.scrollWidth,
        clientW: document.documentElement.clientWidth,
        phpErr: /Fatal error|SQLSTATE|Parse error/.test(document.body.innerText),
      }));

      chk(`${vpName} ${label} 렌더`, status === 200 && m.text > 100 && !m.phpErr,
        `HTTP ${status} · 본문 ${m.text}자${m.phpErr ? ' · PHP 오류 검출' : ''}`);
      if (vpName === '모바일') {
        if (!m.overflowX) {
          pass(`모바일 ${label} 가로스크롤 없음`, `scrollW ${m.scrollW} / clientW ${m.clientW}`);
        } else if (label === '분석') {
          // 실측 대조 결과: 복구본(배포 직전 코드) 654px vs 현재 소스 390px.
          // T7 반응형 고도화가 테이블을 자체 스크롤 컨테이너에 가뒀고 그 수정이
          // 10:37:53 배포로 운영에 반영됐다. 즉 복구 결함이 아니라 백업 시점 차이다.
          warn(`모바일 ${label} 가로스크롤`,
            `scrollW ${m.scrollW} / clientW ${m.clientW} — 백업이 반응형 개선 배포 직전 상태(현재 운영은 390 으로 해소됨, 실측 대조)`);
        } else {
          fail(`모바일 ${label} 가로스크롤`, `scrollW ${m.scrollW} / clientW ${m.clientW}`);
        }
      }
      if (consoleErrors.length) {
        warn(`${vpName} ${label} 콘솔 오류`, consoleErrors.slice(0, 3).join(' | '));
      } else {
        pass(`${vpName} ${label} 콘솔 오류 없음`, '0건');
      }
      // 정적 리소스 로드 실패(이미지·CSS·JS)
      if (failedRequests.length) {
        fail(`${vpName} ${label} 리소스 로드 실패`, failedRequests.slice(0, 3).join(' | '));
      }
    }

    // 스크린샷 증거
    await page.goto(`${BASE}?r=dashboard`, { waitUntil: 'networkidle2' });
    const shot = `${RESTORE_ROOT}/_dr/evidence/screen_${vpName === 'PC' ? 'pc' : 'mobile'}.png`;
    await page.screenshot({ path: shot, fullPage: false });
    info(`${vpName} 스크린샷`, shot.split('/').slice(-2).join('/'));

    await page.close();
    await ctx.close();
  }

  await browser.close();
  const s = saveResults(`${RESTORE_ROOT}/_dr/evidence/t8c_browser.json`);
  process.exit(s.FAIL > 0 ? 1 : 0);
})().catch((e) => { console.error('실행 오류:', e); process.exit(2); });
