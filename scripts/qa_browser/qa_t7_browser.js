/**
 * T7 프론트엔드 QA — 전 화면 × 3뷰포트(1440 PC / 900 태블릿 / 390 모바일)
 *  · 가로 스크롤(body.scrollWidth > innerWidth) 검출 + 원인 요소 추적
 *  · 콘솔 오류 / 4xx·5xx 응답 수집
 *  · 모바일 터치 타겟(<40px) 검출
 * 사용: node scripts/qa_browser/qa_t7_browser.js [--json out.json]
 */
const fs = require('fs');
const puppeteer = require('puppeteer-core');
const CHROME = process.env.QA_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = process.env.QA_BASE || 'http://127.0.0.1:8080/index.php';
const ADMIN = { id: 'admin', pw: process.env.QA_ADMIN_PW || 'password123!' };

const VIEWPORTS = (process.env.QA_VP ? process.env.QA_VP.split(',').map((w) => ({ name: 'W' + w, width: +w, height: 900, isMobile: +w <= 480 })) : [
  { name: 'PC 1440', width: 1440, height: 900, isMobile: false },
  { name: '태블릿 1024', width: 1024, height: 1366, isMobile: false },
  { name: '태블릿 900', width: 900, height: 1180, isMobile: false },
  { name: '태블릿 768', width: 768, height: 1024, isMobile: false },
  { name: '모바일 390', width: 390, height: 844, isMobile: true },
]);

// 화면 목록 — 시드(QAT7, id 9001~) 기준
const SCREENS = [
  ['대시보드', 'dashboard'],
  ['고객 목록', 'customers.index'],
  ['고객 상세', 'customers.show&id=9001'],
  ['고객 등록폼', 'customers.form'],
  ['고객 수정폼', 'customers.form&id=9001'],
  ['영업기회 목록', 'pipeline.index'],
  ['영업기회 상세', 'pipeline.show&id=9001'],
  ['영업기회 폼', 'pipeline.form&id=9001'],
  ['견적 목록', 'quotes.index'],
  ['견적 상세', 'quotes.show&id=9001'],
  ['견적 폼', 'quotes.form&id=9001'],
  ['견적 신규폼', 'quotes.form'],
  ['계약 목록', 'contracts.index'],
  ['계약 상세', 'contracts.show&id=9001'],
  ['계약 폼', 'contracts.form&id=9001'],
  ['계약 신규폼', 'contracts.form'],
  ['프로젝트 목록', 'projects.index'],
  ['프로젝트 상세', 'projects.show&id=9001'],
  ['프로젝트 폼', 'projects.form&id=9001'],
  ['공정 보드', 'process.board'],
  ['일정', 'schedule.index'],
  ['작업일지 목록', 'worklogs.index'],
  ['작업일지 상세', 'worklogs.show&id=9001'],
  ['작업일지 폼', 'worklogs.form'],
  ['리포트', 'reports.index'],
  ['출근 분석', 'reports.attendance'],
  ['성과', 'performance.index'],
  ['성과 상세', 'performance.user&id=1'],
  ['반기 현황', 'halfyear.index'],
  ['보너스 원장', 'bonus.index'],
  ['보너스 이력', 'bonus.history'],
  ['목표(KPI)', 'targets.index'],
  ['알림', 'notifications.index'],
  ['직원 목록', 'staff.index'],
  ['직원 상세', 'staff.show&id=2'],
  ['직원 폼(권한매트릭스)', 'staff.form&id=2'],
  ['설정', 'settings.index'],
  ['단계 관리', 'settings.stages'],
  ['감사 로그', 'audit.index'],
  ['비밀번호 변경', 'password.change'],
  ['견적 휴지통', 'quotes.index&trash=1'],
];

async function login(page) {
  await page.goto(`${BASE}?r=login`, { waitUntil: 'domcontentloaded' });
  await page.type('input[name=login_id]', ADMIN.id);
  await page.type('input[name=password]', ADMIN.pw);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('button[type=submit]'),
  ]);
}

/** 가로 스크롤 원인 요소 — 뷰포트 오른쪽 경계를 넘는 요소를 전 DOM에서 수집.
 *  overflow:visible 조상은 border-box 가 넘지 않아도 자식이 넘칠 수 있으므로 전수 순회한다. */
const OVERFLOW_PROBE = `(() => {
  const vw = document.documentElement.clientWidth;
  const out = [];
  const path = (el) => {
    const parts = [];
    for (let c = el; c && c !== document.body && parts.length < 4; c = c.parentElement) {
      parts.unshift(c.tagName.toLowerCase()
        + (c.id ? '#' + c.id : '')
        + (c.className && typeof c.className === 'string' ? '.' + c.className.trim().split(/\\s+/).slice(0,3).join('.') : ''));
    }
    return parts.join(' > ');
  };
  document.querySelectorAll('*').forEach((c) => {
    const cs = getComputedStyle(c);
    if (cs.position === 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') return;
    const r = c.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) return;
    const scrolls = c.scrollWidth > c.clientWidth + 1 && /auto|scroll/.test(cs.overflowX);
    if (r.right > vw + 1 && !scrolls) {
      out.push({ sel: path(c), right: Math.round(r.right), w: Math.round(r.width), sw: c.scrollWidth, cw: c.clientWidth, depth: (() => { let d=0,x=c; while(x=x.parentElement) d++; return d; })() });
    }
  });
  out.sort((a, b) => a.depth - b.depth);
  return { bodySW: document.body.scrollWidth, docSW: document.documentElement.scrollWidth, vw, count: out.length, offenders: out.slice(0, 14) };
})()`;

/** 터치 타겟 — 요소 자신의 박스가 40px 미만이면, 클릭을 대신 받는 <label> 조상의 박스를
 *  실효 히트영역으로 본다(체크박스는 라벨 전체가 타겟). 스크린리더/포커스에서 제외된
 *  보조 컨트롤(aria-hidden, tabindex=-1, .sr-only)은 타겟이 아니므로 제외. */
const TOUCH_PROBE = `(() => {
  const sels = 'a,button,input[type=checkbox],input[type=radio],select,summary,[role=button],[onclick]';
  const bad = [];
  document.querySelectorAll(sels).forEach((el) => {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') return;
    if (el.getAttribute('aria-hidden') === 'true' || el.getAttribute('tabindex') === '-1') return;
    if (el.closest('.sr-only')) return;
    let r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return;
    if (r.width < 40 || r.height < 40) {
      const lab = el.closest('label');
      if (lab && lab !== el) { const lr = lab.getBoundingClientRect(); if (lr.width >= 40 && lr.height >= 40) return; }
    } else { return; }
    const sel = el.tagName.toLowerCase()
      + (el.id ? '#' + el.id : '')
      + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\\s+/).slice(0,3).join('.') : '');
    bad.push({ sel, w: Math.round(r.width), h: Math.round(r.height), txt: (el.textContent||'').trim().slice(0,18) });
  });
  return bad;
})()`;

(async () => {
  const argJson = process.argv.includes('--json') ? process.argv[process.argv.indexOf('--json') + 1] : null;
  const only = process.argv.includes('--only') ? process.argv[process.argv.indexOf('--only') + 1].split(',') : null;
  const screens = only ? SCREENS.filter(([n]) => only.some((o) => n.includes(o))) : SCREENS;
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  const consoleErrors = [];
  const badResponses = [];
  let ctx = '';
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`[${ctx}] ${m.text()}`); });
  page.on('pageerror', (e) => consoleErrors.push(`[${ctx}] pageerror: ${e.message}`));
  page.on('dialog', async (d) => { await d.accept().catch(() => {}); });
  page.on('response', (r) => { if (r.status() >= 400) badResponses.push(`[${ctx}] ${r.status()} ${r.url()}`); });

  const results = [];
  const touchIssues = {};
  try {
    await page.setViewport({ width: 1440, height: 900 });
    await login(page);

    for (const vp of VIEWPORTS) {
      await page.setViewport({ width: vp.width, height: vp.height, isMobile: vp.isMobile, hasTouch: vp.isMobile });
      for (const [name, route] of screens) {
        ctx = `${vp.name} / ${name}`;
        const url = `${BASE}?r=${route}`;
        let probe = null, err = null;
        try {
          await page.goto(url, { waitUntil: 'networkidle2', timeout: 25000 });
          await new Promise((r) => setTimeout(r, 250));
          probe = await page.evaluate(OVERFLOW_PROBE);
          if (vp.width === 390) {
            const t = await page.evaluate(TOUCH_PROBE);
            if (t.length) touchIssues[name] = t;
          }
        } catch (e) { err = e.message; }
        const over = probe ? Math.max(probe.bodySW, probe.docSW) - probe.vw : -1;
        results.push({ vp: vp.name, name, route, over, probe, err });
        if (err) console.log(`  ⚠️  ${ctx}: ${err}`);
        else if (over > 1) {
          console.log(`  ❌ ${ctx}: +${over}px (body ${probe.bodySW} / vw ${probe.vw})`);
          probe.offenders.slice(0, 5).forEach((o) => console.log(`        ${o.sel}  right=${o.right} w=${o.w} sw=${o.sw} cw=${o.cw}`));
        }
      }
      const ovf = results.filter((r) => r.vp === vp.name && r.over > 1).length;
      const errs = results.filter((r) => r.vp === vp.name && r.err).length;
      console.log(`\n═══ ${vp.name}: 화면 ${screens.length} · 가로스크롤 ${ovf} · 로드실패 ${errs}\n`);
    }

    console.log('════ 모바일 터치 타겟 <40px ════');
    const agg = {};
    Object.entries(touchIssues).forEach(([scr, list]) => list.forEach((t) => {
      const k = t.sel.replace(/#[^.]*/, '');
      agg[k] = agg[k] || { count: 0, min: null, screens: new Set() };
      agg[k].count++; agg[k].screens.add(scr);
      if (!agg[k].min || t.w * t.h < agg[k].min[0] * agg[k].min[1]) agg[k].min = [t.w, t.h];
    }));
    Object.entries(agg).sort((a, b) => a[1].min[0] * a[1].min[1] - b[1].min[0] * b[1].min[1]).slice(0, 40)
      .forEach(([k, v]) => console.log(`  ${v.min[0]}×${v.min[1]}  ×${v.count}  ${k}   (${[...v.screens].slice(0,3).join(', ')})`));

    console.log('\n════ 콘솔 오류 ════');
    const real = consoleErrors.filter((e) => !/favicon|net::ERR_/i.test(e));
    console.log(`  ${real.length}건`);
    real.slice(0, 25).forEach((e) => console.log('   ' + e.slice(0, 200)));

    console.log('\n════ 4xx/5xx ════');
    const badReal = badResponses.filter((r) => !/favicon/i.test(r));
    console.log(`  ${badReal.length}건`);
    [...new Set(badReal)].slice(0, 25).forEach((r) => console.log('   ' + r.slice(0, 200)));

    if (argJson) fs.writeFileSync(argJson, JSON.stringify({ results, touchIssues, consoleErrors: real, badResponses: badReal }, null, 1));
  } catch (e) {
    console.error('예외:', e.stack);
  } finally {
    await browser.close();
  }
})();
