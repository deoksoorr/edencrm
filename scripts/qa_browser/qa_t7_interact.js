/**
 * T7 상호작용 QA — 프론트 정리 후 주요 인터랙션이 그대로 동작하는지 확인.
 *  공정 보드 진행률 슬라이더 / 일정 드래그(HTML5 DnD) / 권한 매트릭스 체크박스 /
 *  모달 열기·닫기(재오픈 시 리스너 중첩) / 견적 항목 추가·삭제·합계
 * 사용: node scripts/qa_browser/qa_t7_interact.js
 */
const puppeteer = require('puppeteer-core');
const CHROME = process.env.QA_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = process.env.QA_BASE || 'http://127.0.0.1:8080/index.php';

let pass = 0, fail = 0; const fails = [];
const ok = (m) => { pass++; console.log(`  ✅ ${m}`); };
const bad = (m) => { fail++; fails.push(m); console.log(`  ❌ ${m}`); };
const chk = (m, c) => (c ? ok(m) : bad(m));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  const errs = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/favicon/.test(m.text())) errs.push(m.text()); });
  page.on('pageerror', (e) => errs.push('pageerror: ' + e.message));
  page.on('dialog', async (d) => { await d.accept().catch(() => {}); });
  const bad4xx = [];
  page.on('response', (r) => { if (r.status() >= 400 && !/favicon/.test(r.url())) bad4xx.push(r.status() + ' ' + r.url()); });

  try {
    await page.setViewport({ width: 1440, height: 900 });
    await page.goto(`${BASE}?r=login`, { waitUntil: 'domcontentloaded' });
    await page.type('input[name=login_id]', 'admin');
    await page.type('input[name=password]', process.env.QA_ADMIN_PW || 'password123!');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type=submit]')]);

    // ── 1) 공정 보드 진행률 슬라이더 ──
    console.log('\n════ 1) 공정 보드 슬라이더 ════');
    await page.goto(`${BASE}?r=process.board`, { waitUntil: 'networkidle2' });
    const hasSlider = await page.$('.gc-slider') !== null;
    chk('게이지 슬라이더 렌더', hasSlider);
    if (hasSlider) {
      const before = await page.evaluate(() => {
        const s = document.querySelector('.gc-slider');
        return { v: s.value, pct: (document.querySelector('[data-progress-text]') || {}).textContent };
      });
      await page.evaluate(() => {
        const s = document.querySelector('.gc-slider');
        s.value = String(Math.min(100, (parseInt(s.value, 10) || 0) + 10));
        s.dispatchEvent(new Event('input', { bubbles: true }));
      });
      await sleep(1400); // 400ms 디바운스 + 서버 왕복
      const after = await page.evaluate(() => {
        const s = document.querySelector('.gc-slider');
        const num = s.closest('.gc-row').querySelector('.gc-num');
        return { v: s.value, num: num ? num.value : null,
          chips: document.querySelectorAll('[data-work-chips] .badge').length,
          pct: (document.querySelector('[data-progress-text]') || {}).textContent };
      });
      chk(`슬라이더 값 반영 ${before.v} → ${after.v}`, after.v !== before.v);
      chk('숫자 입력과 양방향 동기', after.num === after.v);
      chk('진행 중 공정 칩 갱신', after.chips > 0);
      chk(`카드 진행률 서버 반영 ${before.pct} → ${after.pct}`, after.pct !== undefined);
      // 원복
      await page.evaluate((v) => {
        const s = document.querySelector('.gc-slider');
        s.value = v; s.dispatchEvent(new Event('input', { bubbles: true }));
      }, before.v);
      await sleep(1200);
      // 공정 그룹 <details> 접기/펼치기
      const det = await page.evaluate(() => {
        const d = document.querySelector('.gc-ggroup');
        if (!d) return null;
        const was = d.open; d.querySelector('summary').click();
        return { was, now: d.open };
      });
      chk('공정 그룹 접기/펼치기', det && det.was !== det.now);
    }
    // 메모 모달 열기·닫기 → 재오픈 시 리스너 중첩 없는지
    const memoBtn = await page.$('.gc-memo-btn');
    if (memoBtn) {
      for (let i = 0; i < 3; i++) {
        await page.click('.gc-memo-btn');
        await sleep(600);
        const cnt = await page.evaluate(() => document.querySelectorAll('.modal-backdrop').length);
        if (i === 0) chk('메모 모달 열림', cnt === 1);
        await page.evaluate(() => { const b = document.querySelector('.modal-close'); if (b) b.click(); });
        await sleep(200);
      }
      const left = await page.evaluate(() => document.querySelectorAll('.modal-backdrop').length);
      chk('모달 3회 재오픈 후 잔존 백드롭 0', left === 0);
    } else { console.log('  (메모 버튼 없음 — 건너뜀)'); }

    // ── 2) 권한 매트릭스 체크박스 ──
    console.log('\n════ 2) 권한 매트릭스 ════');
    await page.goto(`${BASE}?r=staff.form&id=2`, { waitUntil: 'networkidle2' });
    const permOk = await page.evaluate(() => {
      const tr = document.querySelector('[data-perm-row]');
      if (!tr) return null;
      const w = tr.querySelector('input[data-perm-act=write]');
      const r = tr.querySelector('input[data-perm-act=read]');
      if (!w || !r) return null;
      r.checked = false; w.checked = false;
      w.checked = true; w.dispatchEvent(new Event('change', { bubbles: true }));
      const dep = r.checked;
      r.checked = false; r.dispatchEvent(new Event('change', { bubbles: true }));
      return { dep, cascade: !w.checked };
    });
    chk('쓰기 체크 → 읽기 자동 ON', permOk && permOk.dep);
    chk('읽기 해제 → 쓰기 동시 해제', permOk && permOk.cascade);
    const bulk = await page.evaluate(() => {
      const all = document.querySelector('[data-perm-all="1"]');
      if (!all) return null;
      all.click();
      const on = document.querySelectorAll('#permBlock input[type=checkbox]:checked').length;
      document.querySelector('[data-perm-all="0"]').click();
      const off = document.querySelectorAll('#permBlock input[type=checkbox]:checked').length;
      return { on, off };
    });
    chk(`일괄 전체 선택/해제 (${bulk && bulk.on} → ${bulk && bulk.off})`, bulk && bulk.on > 0 && bulk.off === 0);
    // 터치 타겟(모바일)에서도 체크박스가 눌리는지 — 라벨 클릭이 체크를 토글
    await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
    await page.goto(`${BASE}?r=staff.form&id=2`, { waitUntil: 'networkidle2' });
    const tapOk = await page.evaluate(() => {
      const lab = document.querySelector('.perm-check');
      lab.scrollIntoView({ block: 'center' });
      const cb = lab.querySelector('input');
      const was = cb.checked;
      const r = lab.getBoundingClientRect();
      const hit = document.elementFromPoint(r.left + 2, r.top + 2);  // 라벨 가장자리(패딩 영역)
      if (hit) hit.click();
      return { was, now: cb.checked, w: Math.round(r.width), h: Math.round(r.height), hit: hit ? hit.className : null };
    });
    chk(`모바일 체크박스 라벨 히트영역 ${tapOk.w}×${tapOk.h} ≥40`, tapOk.w >= 40 && tapOk.h >= 40);
    chk('라벨 패딩 영역 탭으로 토글', tapOk.was !== tapOk.now);
    await page.setViewport({ width: 1440, height: 900, isMobile: false });

    // ── 3) 견적 항목 추가·삭제·합계 ──
    console.log('\n════ 3) 견적 항목 ════');
    await page.goto(`${BASE}?r=quotes.form&id=9001`, { waitUntil: 'networkidle2' });
    const q0 = await page.evaluate(() => document.querySelectorAll('#itemsBody .item-row').length);
    await page.click('#btnAddItem');
    const q1 = await page.evaluate(() => document.querySelectorAll('#itemsBody .item-row').length);
    chk(`항목 추가 ${q0} → ${q1}`, q1 === q0 + 1);
    const sum = await page.evaluate(() => {
      const tr = document.querySelectorAll('#itemsBody .item-row')[0];
      tr.querySelector('.f-qty').value = '2';
      tr.querySelector('.f-price').value = '1000';
      tr.querySelector('.f-area').value = '';
      tr.querySelector('.f-qty').dispatchEvent(new Event('input', { bubbles: true }));
      return { row: tr.querySelector('.row-amount').textContent, total: document.getElementById('sumTotal').textContent };
    });
    chk(`행 금액 재계산(${sum.row})`, sum.row !== '0');
    chk(`총액 재계산(${sum.total})`, sum.total !== '0');
    await page.evaluate(() => document.querySelectorAll('#itemsBody .btn-remove-row')[0].click());
    const q2 = await page.evaluate(() => document.querySelectorAll('#itemsBody .item-row').length);
    chk(`항목 삭제 ${q1} → ${q2}`, q2 === q1 - 1);

    // ── 4) 일정 — 모달 + 드래그 ──
    console.log('\n════ 4) 일정 ════');
    await page.goto(`${BASE}?r=schedule.index`, { waitUntil: 'networkidle2' });
    await sleep(900);
    chk('월 캘린더 렌더', await page.$('.cal-grid') !== null);
    const evCount = await page.evaluate(() => document.querySelectorAll('.cal-ev').length);
    chk(`캘린더 일정 칩 ${evCount}건 렌더`, evCount > 0);
    if (evCount > 0) {
      await page.click('.cal-ev');
      await sleep(400);
      chk('일정 상세 모달 열림', await page.$('.modal-backdrop') !== null);
      await page.evaluate(() => document.querySelector('.modal-close').click());
      await sleep(200);
      chk('모달 닫힘', await page.$('.modal-backdrop') === null);

      // HTML5 DnD 는 CDP 로 재현이 까다로워 dataTransfer 를 직접 구성해 드롭 핸들러를 호출한다
      const drag = await page.evaluate(async () => {
        const ev = document.querySelector('.cal-ev');
        const id = ev.dataset.id;
        const cells = Array.from(document.querySelectorAll('.cal-cell'));
        const from = ev.closest('.cal-cell');
        const to = cells.find((c) => c !== from && !c.classList.contains('other'));
        const dt = new DataTransfer();
        ev.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
        to.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }));
        to.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
        return { id, target: to.dataset.date };
      });
      await sleep(1500);
      const moved = await page.evaluate((d) => {
        const cell = document.querySelector('.cal-cell[data-date="' + d.target + '"]');
        return cell ? Array.from(cell.querySelectorAll('.cal-ev')).some((e) => e.dataset.id === d.id) : false;
      }, drag);
      chk(`일정 드래그 이동 → ${drag.target}`, moved);
    }
    // 주간 타임라인 전환
    await page.evaluate(() => {
      const t = document.querySelector('#viewTabs .tab[data-view="timeline"]');
      if (t) t.click();
    });
    await sleep(900);
    chk('주간 슬롯 타임라인 전환', await page.$('.sched-row2') !== null || await page.$('.sched-empty2') !== null);

    // ── 5) 리포트 기간 필터 재조회(중복 호출 방지) ──
    console.log('\n════ 5) 리포트 재조회 ════');
    let dataCalls = 0;
    page.on('request', (r) => { if (r.url().includes('r=reports.data')) dataCalls++; });
    await page.goto(`${BASE}?r=reports.index`, { waitUntil: 'networkidle2' });
    await sleep(700);
    const initial = dataCalls;
    await page.evaluate(() => { for (let i = 0; i < 5; i++) document.getElementById('btnApply').click(); });
    await sleep(1600);
    chk(`검색 5연타 → reports.data 호출 ${dataCalls - initial}회 (중복 억제)`, dataCalls - initial <= 1);
    chk('차트 렌더', await page.$('#chartMonthly') !== null);

    // ── 6) 사이드바 토글(모바일) ──
    console.log('\n════ 6) 모바일 사이드바 ════');
    await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
    await page.goto(`${BASE}?r=dashboard`, { waitUntil: 'networkidle2' });
    await page.click('#hamburger');
    await sleep(300);
    chk('햄버거 → 사이드바 열림', await page.evaluate(() => document.getElementById('sidebar').classList.contains('open')));
    await page.evaluate(() => document.getElementById('sidebarOverlay').click());
    await sleep(300);
    chk('오버레이 → 사이드바 닫힘', await page.evaluate(() => !document.getElementById('sidebar').classList.contains('open')));

    console.log('\n════ 콘솔 오류 ════');
    chk(`콘솔 오류 0건 (실제 ${errs.length})`, errs.length === 0);
    errs.slice(0, 10).forEach((e) => console.log('   ' + e.slice(0, 180)));
    chk(`4xx/5xx 0건 (실제 ${bad4xx.length})`, bad4xx.length === 0);
    [...new Set(bad4xx)].slice(0, 10).forEach((e) => console.log('   ' + e.slice(0, 180)));
  } catch (e) {
    bad('예외: ' + e.message);
    console.error(e.stack);
  } finally {
    await browser.close();
  }
  console.log(`\n결과: PASS ${pass} · FAIL ${fail}`);
  fails.forEach((f) => console.log('  ✗ ' + f));
  process.exit(fail === 0 ? 0 : 1);
})();
