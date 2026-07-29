/**
 * R16 브라우저 QA — 권한 매트릭스 UI · 종속 규칙 · 모바일 레이아웃 · 콘솔 오류.
 * 선행: php scripts/qa_r16_seed.php --seed
 * 사용: node scripts/qa_browser/qa_r16_browser.js
 */
const puppeteer = require('puppeteer-core');
const CHROME = process.env.QA_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const BASE = process.env.QA_BASE || 'http://127.0.0.1:8080/index.php';
const ADMIN = { id: 'admin', pw: process.env.QA_ADMIN_PW || 'password123!' };
const QAPW = 'QaR16!verify2026';

let pass = 0, fail = 0;
const fails = [];
const ok = (m) => { pass++; console.log(`  ✅ ${m}`); };
const bad = (m) => { fail++; fails.push(m); console.log(`  ❌ ${m}`); };
const chk = (m, cond) => cond ? ok(m) : bad(m);

async function login(page, id, pw) {
  await page.goto(`${BASE}?r=login`, { waitUntil: 'domcontentloaded' });
  await page.type('input[name=login_id]', id);
  await page.type('input[name=password]', pw);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('button[type=submit]'),
  ]);
}

async function logout(page) {
  await page.goto(`${BASE}?r=logout`, { waitUntil: 'domcontentloaded' });
}

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));
  // 미저장 이탈 경고(beforeunload)는 정상 동작이다 — 테스트 진행을 위해 자동 수락한다.
  let dialogSeen = 0;
  page.on('dialog', async (d) => { dialogSeen++; await d.accept().catch(() => {}); });
  // 403/500 응답을 URL 과 함께 수집 — 어떤 화면이 권한 없는 엔드포인트를 호출하는지 특정한다.
  const badResponses = [];
  page.on('response', (r) => {
    if (r.status() >= 400) badResponses.push(`${r.status()} ${r.url()} (from ${page.url()})`);
  });

  try {
    // ───────────── 1) 최고운영자 — 권한 매트릭스 렌더 ─────────────
    console.log('\n════ 1) 매트릭스 렌더 ════');
    await login(page, ADMIN.id, ADMIN.pw);

    // qa_r16_a 의 id 찾기
    await page.goto(`${BASE}?r=staff.index`, { waitUntil: 'domcontentloaded' });
    // 직원 목록의 행은 staff.show 로 링크되고 tr 에 data-staff-row 가 붙는다.
    const targetId = await page.evaluate(() => {
      const tr = Array.from(document.querySelectorAll('tr[data-staff-row]'))
        .find((x) => x.textContent.includes('QA영업읽기'));
      return tr ? tr.getAttribute('data-staff-row') : null;
    });
    chk('QA 대상 직원 행 발견', targetId !== null);
    if (!targetId) throw new Error('QA영업읽기 계정을 직원 목록에서 찾지 못함');

    await page.goto(`${BASE}?r=staff.form&id=${targetId}`, { waitUntil: 'domcontentloaded' });
    chk('권한 매트릭스 블록 렌더', await page.$('#permBlock') !== null);

    const counts = await page.evaluate(() => ({
      boxes: document.querySelectorAll('#permBlock input[type=checkbox]').length,
      checked: document.querySelectorAll('#permBlock input[type=checkbox]:checked').length,
      sections: document.querySelectorAll('#permBlock [data-perm-section]').length,
      rows: document.querySelectorAll('#permBlock [data-perm-row]').length,
    }));
    chk(`체크박스 28개 (실제 ${counts.boxes})`, counts.boxes === 28);
    chk(`저장 권한 4개 체크 (실제 ${counts.checked})`, counts.checked === 4);
    chk(`섹션 3개 (실제 ${counts.sections})`, counts.sections === 3);
    chk(`리소스 행 10개 (실제 ${counts.rows})`, counts.rows === 10);

    // ───────────── 2) 종속 규칙 ─────────────
    console.log('\n════ 2) 종속 규칙 ════');
    // 쓰기 체크 → 읽기 자동 ON
    const r1 = await page.evaluate(() => {
      const tr = document.querySelector('[data-perm-row="field.projects"]');
      const w = tr.querySelector('input[data-perm-act=write]');
      const r = tr.querySelector('input[data-perm-act=read]');
      r.checked = false; w.checked = false;
      w.checked = true; w.dispatchEvent(new Event('change', { bubbles: true }));
      return { read: r.checked, write: w.checked };
    });
    chk('쓰기 체크 → 읽기 자동 ON', r1.read === true && r1.write === true);

    // 삭제 체크 → 읽기 자동 ON
    const r2 = await page.evaluate(() => {
      const tr = document.querySelector('[data-perm-row="field.schedules"]');
      const d = tr.querySelector('input[data-perm-act=delete]');
      const r = tr.querySelector('input[data-perm-act=read]');
      r.checked = false; d.checked = false;
      d.checked = true; d.dispatchEvent(new Event('change', { bubbles: true }));
      return { read: r.checked, del: d.checked };
    });
    chk('삭제 체크 → 읽기 자동 ON', r2.read === true && r2.del === true);

    // 읽기 해제 → 쓰기·삭제 동시 해제
    const r3 = await page.evaluate(() => {
      const tr = document.querySelector('[data-perm-row="field.projects"]');
      const r = tr.querySelector('input[data-perm-act=read]');
      const w = tr.querySelector('input[data-perm-act=write]');
      const d = tr.querySelector('input[data-perm-act=delete]');
      r.checked = true; w.checked = true; d.checked = true;
      r.checked = false; r.dispatchEvent(new Event('change', { bubbles: true }));
      return { read: r.checked, write: w.checked, del: d.checked };
    });
    chk('읽기 해제 → 쓰기·삭제 동시 해제', !r3.read && !r3.write && !r3.del);

    // 분석 리소스는 쓰기·삭제 체크박스가 없어야 한다
    const analytics = await page.evaluate(() => {
      const tr = document.querySelector('[data-perm-row="analytics.reports"]');
      return {
        boxes: tr.querySelectorAll('input[type=checkbox]').length,
        hasRead: !!tr.querySelector('input[data-perm-act=read]'),
        na: tr.querySelectorAll('.perm-na').length,
        noteShown: !!tr.querySelector('.perm-row-note'),
      };
    });
    chk('분석 리소스는 읽기 1개만', analytics.boxes === 1 && analytics.hasRead);
    chk('분석 쓰기·삭제는 비활성 표기', analytics.na === 2);
    chk('전사 열람 결합 안내 노출', analytics.noteShown);

    // ───────────── 3) 일괄 선택 ─────────────
    console.log('\n════ 3) 일괄 선택 ════');
    const all = await page.evaluate(() => {
      document.querySelector('[data-perm-all="1"]').click();
      return document.querySelectorAll('#permBlock input[type=checkbox]:checked').length;
    });
    chk(`전체 선택 → 28개 전부 (실제 ${all})`, all === 28);

    const none = await page.evaluate(() => {
      document.querySelector('[data-perm-all="0"]').click();
      return document.querySelectorAll('#permBlock input[type=checkbox]:checked').length;
    });
    chk(`전체 해제 → 0개 (실제 ${none})`, none === 0);

    const sectionSel = await page.evaluate(() => {
      document.querySelector('[data-perm-section-all="sales"]').click();
      const sec = document.querySelector('[data-perm-section="sales"]');
      return {
        inSec: sec.querySelectorAll('input:checked').length,
        outSec: document.querySelectorAll('[data-perm-section="field"] input:checked').length,
      };
    });
    chk('영업 영역 전체 선택(12개)', sectionSel.inSec === 12);
    chk('다른 영역은 영향 없음', sectionSel.outSec === 0);

    const rowSel = await page.evaluate(() => {
      document.querySelector('[data-perm-all="0"]').click();
      document.querySelector('[data-perm-row-all="sales.quotes"]').click();
      return document.querySelectorAll('[data-perm-row="sales.quotes"] input:checked').length;
    });
    chk('행 전체 선택(3개)', rowSel === 3);

    const colSel = await page.evaluate(() => {
      document.querySelector('[data-perm-all="0"]').click();
      document.querySelector('[data-perm-col="read"][data-perm-col-section="field"]').click();
      const sec = document.querySelector('[data-perm-section="field"]');
      return {
        read: sec.querySelectorAll('input[data-perm-act=read]:checked').length,
        write: sec.querySelectorAll('input[data-perm-act=write]:checked').length,
      };
    });
    chk('현장 읽기 열 전체 선택(5개)', colSel.read === 5);
    chk('열 선택이 다른 열을 건드리지 않음', colSel.write === 0);

    // ───────────── 4) 최고운영자 대상은 편집 불가 ─────────────
    console.log('\n════ 4) 최고운영자 대상 ════');
    // 미저장 이탈 경고가 실제로 무장되는지 확인한 뒤, 이동을 위해 해제한다.
    chk('미저장 변경 시 이탈 경고 무장', await page.evaluate(() => {
      const e = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(e);
      return e.defaultPrevented === true;
    }));
    // 이후 이동은 dialog 핸들러가 경고를 자동 수락한다(폼 제출은 하지 않는다 — 실제 저장 방지).
    await page.goto(`${BASE}?r=staff.index`, { waitUntil: 'domcontentloaded' });
    const superId = await page.evaluate(() => {
      const tr = Array.from(document.querySelectorAll('tr[data-staff-row]'))
        .find((x) => /슈퍼관리자|사장|super_admin/i.test(x.textContent));
      return tr ? tr.getAttribute('data-staff-row') : null;
    });
    if (superId) {
      await page.goto(`${BASE}?r=staff.form&id=${superId}`, { waitUntil: 'domcontentloaded' });
      const superView = await page.evaluate(() => ({
        notice: !!document.querySelector('.perm-notice'),
        boxes: document.querySelectorAll('#permBlock input[type=checkbox]').length,
      }));
      chk('최고운영자는 안내문만 표시', superView.notice === true);
      chk('최고운영자는 체크박스 없음', superView.boxes === 0);
    } else {
      bad('최고운영자 행을 찾지 못함');
    }

    // ───────────── 5) 모바일 레이아웃 ─────────────
    console.log('\n════ 5) 모바일(390x844) ════');
    await page.setViewport({ width: 390, height: 844, isMobile: true });
    await page.goto(`${BASE}?r=staff.form&id=${targetId}`, { waitUntil: 'domcontentloaded' });
    const mobile = await page.evaluate(() => {
      const tr = document.querySelector('[data-perm-row="sales.quotes"]');
      const td = tr.querySelector('td[data-label]');
      return {
        display: getComputedStyle(tr).display,
        theadHidden: getComputedStyle(document.querySelector('.perm-table thead')).display === 'none',
        labelShown: getComputedStyle(td, '::before').content !== 'none',
        bodyOverflow: document.body.scrollWidth <= window.innerWidth + 2,
      };
    });
    chk('모바일에서 표 → 카드형 전환', mobile.display === 'block');
    chk('모바일에서 표 머리글 숨김', mobile.theadHidden);
    chk('모바일에서 항목 라벨 표시', mobile.labelShown);
    chk('가로 스크롤 없음', mobile.bodyOverflow);
    await page.setViewport({ width: 1440, height: 900, isMobile: false });

    // ───────────── 6) 권한 없는 계정의 UI 노출 ─────────────
    console.log('\n════ 6) 권한별 UI 노출 ════');
    await logout(page);
    await login(page, 'qa_r16_c', QAPW);   // 영업 읽기·쓰기·삭제
    await page.goto(`${BASE}?r=quotes.index`, { waitUntil: 'domcontentloaded' });
    const cView = await page.evaluate(() => ({
      trash: !!Array.from(document.querySelectorAll('a')).find((a) => a.textContent.trim() === '휴지통'),
      create: !!Array.from(document.querySelectorAll('a')).find((a) => a.textContent.includes('견적 등록')),
      nav: Array.from(document.querySelectorAll('.nav-section')).map((n) => n.textContent.trim()),
    }));
    chk('삭제 권한자에게도 휴지통 버튼 미노출', cView.trash === false);
    chk('쓰기 권한자에게 등록 버튼 노출', cView.create === true);
    chk('사이드바에 분석·관리 그룹 없음',
      !cView.nav.includes('분석') && !cView.nav.includes('관리'));

    await logout(page);
    await login(page, 'qa_r16_a', QAPW);   // 영업 읽기만
    await page.goto(`${BASE}?r=quotes.index`, { waitUntil: 'domcontentloaded' });
    const aView = await page.evaluate(() => ({
      create: !!Array.from(document.querySelectorAll('a')).find((a) => a.textContent.includes('견적 등록')),
      trash: !!Array.from(document.querySelectorAll('a')).find((a) => a.textContent.trim() === '휴지통'),
    }));
    chk('읽기 전용자에게 등록 버튼 미노출', aView.create === false);
    chk('읽기 전용자에게 휴지통 미노출', aView.trash === false);

    // ───────────── 7) 최고운영자 휴지통 ─────────────
    console.log('\n════ 7) 최고운영자 휴지통 ════');
    await logout(page);
    await login(page, ADMIN.id, ADMIN.pw);
    for (const [route, name] of [['quotes.index', '견적'], ['contracts.index', '계약'],
                                 ['projects.index', '프로젝트'], ['customers.index', '고객'],
                                 ['pipeline.index', '영업기회']]) {
      await page.goto(`${BASE}?r=${route}&trash=1`, { waitUntil: 'domcontentloaded' });
      // 오류 판정은 본문 텍스트 스캔이 아니라 오류 페이지 마커로 한다 —
      // 본문에는 직원 이름 등 사용자 데이터가 섞여 '권한' 같은 단어가 정상적으로 등장한다.
      const t = await page.evaluate(() => ({
        status: document.title,
        hasRestore: !!Array.from(document.querySelectorAll('button')).find((b) => b.textContent.trim() === '복원'),
        hasPurge: !!Array.from(document.querySelectorAll('button')).find((b) => b.textContent.trim() === '완전삭제'),
        rows: document.querySelectorAll('tbody tr').length,
        err: !!document.querySelector('.error-page, .err-page')
             || /접근 권한 없음|페이지를 찾을 수 없|서버 오류/.test(document.querySelector('h1')?.textContent || ''),
      }));
      chk(`${name} 휴지통 진입`, !t.err);
      if (t.rows > 0) {
        chk(`${name} 휴지통 복원·완전삭제 버튼 노출`, t.hasRestore && t.hasPurge);
      } else {
        ok(`${name} 휴지통 비어 있음(버튼 검증 생략)`);
      }
    }

    // ───────────── 8) 콘솔 오류 ─────────────
    console.log('\n════ 8) 콘솔 오류 ════');
    const real = consoleErrors.filter((e) => !/favicon|net::ERR_/i.test(e));
    chk(`브라우저 콘솔 오류 0건 (실제 ${real.length})`, real.length === 0);
    real.slice(0, 5).forEach((e) => console.log('     ' + e.slice(0, 160)));
    const badReal = badResponses.filter((r) => !/favicon/i.test(r));
    chk(`4xx/5xx 응답 0건 (실제 ${badReal.length})`, badReal.length === 0);
    badReal.slice(0, 8).forEach((r) => console.log('     ' + r));

  } catch (e) {
    bad('예외: ' + e.message);
    console.error(e.stack);
  } finally {
    await browser.close();
  }

  console.log('\n════════════════════════════════════════');
  console.log(`결과: PASS ${pass} · FAIL ${fail}`);
  fails.forEach((f) => console.log('  ✗ ' + f));
  process.exit(fail === 0 ? 0 : 1);
})();
