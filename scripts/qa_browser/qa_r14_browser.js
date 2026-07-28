/* R14 브라우저 QA — 공정 게이지 보드·카드 메모·예외 계약총액 연동·반기 계약/공사 탭.
 * puppeteer-core + 시스템 Chrome. PC 1440x900 / 모바일 390x844. */
const puppeteer = require('puppeteer-core');
const fs = require('fs');

const B = 'http://127.0.0.1:8080/index.php';
const SHOTS = '/private/tmp/claude-501/-Users-deoksookim-Desktop----claude-code-eden-crm/1dbb54a6-d3c8-491b-9509-7e4c9bfe5749/scratchpad/shots-r14';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const PROJECT_A_ID = Number(process.env.QA_PROJECT_A || 0);
const PROJECT_B_ID = Number(process.env.QA_PROJECT_B || 0);
const STAGE3_ID = Number(process.env.QA_STAGE3 || 0);

if (!PROJECT_A_ID || !PROJECT_B_ID || !STAGE3_ID) {
  console.error('필수 env 누락: QA_PROJECT_A, QA_PROJECT_B, QA_STAGE3');
  process.exit(2);
}

const results = []; // {name, ok, detail}
function check(name, ok, detail) {
  results.push({ name, ok: !!ok, detail: detail !== undefined ? String(detail).slice(0, 300) : '' });
  console.log((ok ? '  [PASS] ' : '  [FAIL] ') + name + (detail !== undefined ? ' — ' + String(detail).slice(0, 200) : ''));
}

const allConsoleErrors = []; // {page, text}

function attachConsole(page, label) {
  page.on('pageerror', (e) => allConsoleErrors.push({ page: label, text: 'pageerror: ' + String(e).slice(0, 200) }));
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const text = m.text();
    if (/favicon/.test(text)) return;
    allConsoleErrors.push({ page: label, text: 'console: ' + text.slice(0, 200) });
  });
  page.on('response', (r) => {
    if (r.status() >= 500) allConsoleErrors.push({ page: label, text: 'HTTP ' + r.status() + ' ' + r.url().slice(0, 120) });
  });
}

async function login(page) {
  await page.goto(B + '?r=login', { waitUntil: 'networkidle0' });
  await page.type('input[name="login_id"]', 'admin');
  await page.type('input[name="password"]', 'password123!');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.click('button[type="submit"]'),
  ]);
}

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--window-size=1440,900'],
  });

  // ── PC 컨텍스트(격리 — 쿠키 공유 방지) ──
  const pcCtx = await browser.createBrowserContext();
  const page = await pcCtx.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  attachConsole(page, 'pc');
  await login(page);

  // ═ A. 공정 보드 PC ═
  await page.goto(B + '?r=process.board', { waitUntil: 'networkidle0' });
  await page.waitForSelector('.sg-board', { timeout: 5000 }).catch(() => {});
  let html = await page.content();
  const hasGroups = ['대기중', '진행 중', '하자보수', '종결'].every((g) => html.includes(g));
  check('A-1 상태 그룹 4개 렌더(대기중/진행중/하자보수/종결)', hasGroups);

  const cardA = await page.$(`.gauge-card[data-project-id="${PROJECT_A_ID}"]`);
  check('A-2 카드 A 존재', !!cardA);
  let cardAGroup = null;
  if (cardA) {
    cardAGroup = await page.evaluate((el) => el.closest('.sg-group')?.dataset.group, cardA);
  }
  check('A-3 카드 A가 진행 중(active) 그룹에 위치', cardAGroup === 'active', cardAGroup);

  const sliderCount = cardA ? await page.evaluate((el) => el.querySelectorAll('.gc-slider').length, cardA) : 0;
  check('A-4 카드 A 게이지 슬라이더 존재', sliderCount > 0, sliderCount);

  const progressText = cardA ? await page.evaluate((el) => el.querySelector('[data-progress-text]')?.textContent, cardA) : null;
  const progressPct = progressText ? parseInt(progressText, 10) : 0;
  check('A-5 전체 진행률 > 0', progressPct > 0, progressText);

  const currentStageChip = cardA ? await page.evaluate((el) => el.querySelector('[data-current-stage]')?.textContent, cardA) : null;
  check('A-6 현재 공정 칩 존재', !!currentStageChip, currentStageChip);

  const memoBtnText = cardA ? await page.evaluate((el) => el.querySelector('.gc-memo-btn')?.textContent.trim(), cardA) : null;
  check('A-7 메모 버튼 카운트 배지(1)', !!memoBtnText && /1/.test(memoBtnText), memoBtnText);

  const dragArtifacts = await page.evaluate(() => !!document.querySelector('[draggable="true"], .kanban-col, [data-drop-target]'));
  check('A-8 드래그 흔적 없음', !dragArtifacts);

  const phpErrText = /Fatal error|Warning:|Notice:|Parse error|Uncaught/i.test(html);
  check('A-9 PHP 에러 텍스트 없음', !phpErrText);

  await page.screenshot({ path: `${SHOTS}/board_pc.png`, fullPage: true });

  // ═ B. 게이지 상호작용(3번째 슬라이더 변경, 리로드 없이 반영) ═
  let bOk = false, bDetail = '';
  if (cardA) {
    const slider3 = await page.evaluateHandle(
      (el, sid) => el.querySelector(`.gc-slider[data-stage-id="${sid}"]`),
      cardA, STAGE3_ID
    );
    const slider3El = slider3.asElement();
    if (slider3El) {
      await page.evaluate((el) => {
        el.value = '40';
        el.dispatchEvent(new Event('input', { bubbles: true }));
      }, slider3El);
      // 디바운스(400ms) + 저장 응답 대기 — progress 텍스트가 바뀔 때까지 폴링(reload 없이)
      const before = progressPct;
      let after = before;
      const deadline = Date.now() + 3000;
      while (Date.now() < deadline) {
        await new Promise((r) => setTimeout(r, 200));
        after = await page.evaluate((el) => parseInt(el.querySelector('[data-progress-text]')?.textContent || '0', 10), cardA);
        if (after !== before) break;
      }
      const badgeAfter = await page.evaluate((el) => el.querySelector('[data-stage-val]')?.textContent, slider3El.asElement ? slider3El : slider3El);
      const rowVal = await page.evaluate((el, sid) => {
        const row = el.querySelector(`.gc-row[data-stage-row="${sid}"]`);
        return row ? row.querySelector('[data-stage-val]').textContent : null;
      }, cardA, STAGE3_ID);
      bOk = after !== before && rowVal === '40%';
      bDetail = `before=${before} after=${after} rowVal=${rowVal}`;
    } else {
      bDetail = '3번째 슬라이더를 찾지 못함';
    }
  } else {
    bDetail = '카드 A 없음';
  }
  check('B 게이지 조작 → 리로드 없이 진행률/값 즉시 반영', bOk, bDetail);
  await page.screenshot({ path: `${SHOTS}/board_memo_pc.png`, fullPage: true }).catch(() => {});
  // (파일명은 스펙 지정 그대로 두고, 실제 게이지 반영 스샷은 아래에서 별도 저장)
  await page.screenshot({ path: `${SHOTS}/board_gauge_after_pc.png`, fullPage: true });

  // ═ C. 메모 팝업 ═
  let cOk1 = false, cOk2 = false, cDetail = '';
  if (cardA) {
    const memoBtn = await cardA.$('.gc-memo-btn');
    if (memoBtn) {
      await memoBtn.click();
      await page.waitForSelector('.modal, [class*="modal"]', { timeout: 3000 }).catch(() => {});
      await new Promise((r) => setTimeout(r, 300));
      const modalText1 = await page.evaluate(() => document.body.textContent);
      cOk1 = modalText1.includes('QA 메모 테스트');
      // 새 메모 추가
      const form = await page.$('.memo-form');
      if (form) {
        await page.evaluate(() => {
          const ta = document.querySelector('.memo-form textarea[name="content"]');
          if (ta) ta.value = 'QA 메모 추가분';
        });
        await page.click('.memo-form button[type="submit"]');
        await new Promise((r) => setTimeout(r, 800));
        const itemCount = await page.evaluate(() => document.querySelectorAll('.memo-item').length);
        cOk2 = itemCount >= 2;
        cDetail = `itemCount=${itemCount}`;
      } else {
        cDetail = '메모 등록 폼 없음(canMove 권한 확인 필요)';
      }
      await page.screenshot({ path: `${SHOTS}/board_memo_pc.png`, fullPage: false });
      // 모달 닫기
      await page.keyboard.press('Escape').catch(() => {});
      await new Promise((r) => setTimeout(r, 200));
    } else {
      cDetail = '메모 버튼 없음';
    }
  } else {
    cDetail = '카드 A 없음';
  }
  check('C-1 메모 목록에 시드 메모 표시', cOk1, cDetail);
  check('C-2 메모 등록 후 목록 2건으로 갱신', cOk2, cDetail);

  // ═ D. 모바일 390(격리 컨텍스트) ═
  const mobileCtx = await browser.createBrowserContext();
  const mpage = await mobileCtx.newPage();
  await mpage.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
  attachConsole(mpage, 'mobile');
  await login(mpage);
  await mpage.goto(B + '?r=process.board', { waitUntil: 'networkidle0' });
  await mpage.waitForSelector('.sg-cards', { timeout: 5000 }).catch(() => {});
  const gridCols = await mpage.evaluate(() => {
    const el = document.querySelector('.sg-cards');
    if (!el) return null;
    return getComputedStyle(el).gridTemplateColumns;
  });
  const colCount = gridCols ? gridCols.trim().split(/\s+/).length : 0;
  check('D-1 모바일 .sg-cards 1컬럼 그리드', colCount === 1, gridCols);

  const sliderHeights = await mpage.evaluate(() => {
    return Array.from(document.querySelectorAll('.gc-slider')).slice(0, 5).map((el) => el.getBoundingClientRect().height);
  });
  const minH = sliderHeights.length ? Math.min(...sliderHeights) : 0;
  check('D-2 슬라이더 탭 가능 높이 >= 24px', sliderHeights.length > 0 && minH >= 24, JSON.stringify(sliderHeights));
  await mpage.screenshot({ path: `${SHOTS}/board_mobile.png`, fullPage: true });

  // ═ E. 정산 탭 ═
  await page.goto(B + `?r=projects.show&id=${PROJECT_A_ID}`, { waitUntil: 'networkidle0' });
  await page.click('#projTabs .tab[data-tab="settlement"]').catch(() => {});
  await new Promise((r) => setTimeout(r, 300));
  let settleHtmlA = await page.content();
  const eTotalA = settleHtmlA.includes('33,000,000');
  const eNotUnset = !/미설정/.test(settleHtmlA.match(/계약 총액[\s\S]{0,300}/)?.[0] || '');
  const eNoEditBtn = !/btnEditExpected/.test(settleHtmlA);
  const ePaid = settleHtmlA.includes('11,000,000');
  const ePartial = settleHtmlA.includes('일부 입금');
  check('E-1 [A] 계약 총액 33,000,000 표시', eTotalA);
  check('E-2 [A] "미설정" 아님', eNotUnset);
  check('E-3 [A] 수정 버튼(btnEditExpected) 없음', eNoEditBtn);
  check('E-4 [A] 누적 입금 11,000,000', ePaid);
  check('E-5 [A] 입금 상태 "일부 입금"', ePartial, settleHtmlA.match(/입금 상태[\s\S]{0,150}/)?.[0]?.replace(/\s+/g, ' '));
  await page.screenshot({ path: `${SHOTS}/settlement_pc.png`, fullPage: true });

  await page.goto(B + `?r=projects.show&id=${PROJECT_B_ID}`, { waitUntil: 'networkidle0' });
  await page.click('#projTabs .tab[data-tab="settlement"]').catch(() => {});
  await new Promise((r) => setTimeout(r, 300));
  let settleHtmlB = await page.content();
  check('E-6 [B] 계약 총액 5,500,000(레거시 fallback)', settleHtmlB.includes('5,500,000'));

  // ═ F. 반기 화면 ═
  await page.goto(B + '?r=halfyear.index', { waitUntil: 'networkidle0' });
  let hyHtml = await page.content();
  check('F-1 계약 실적 탭 admin 행 매출금액(입금) 11,000,000 포함', hyHtml.includes('11,000,000'));
  await page.screenshot({ path: `${SHOTS}/halfyear_contract_pc.png`, fullPage: true });

  const constructionTabBtn = await page.$('[data-hy-tab="construction"]');
  let fPanelSwitch = false;
  if (constructionTabBtn) {
    await constructionTabBtn.click();
    await new Promise((r) => setTimeout(r, 200));
    fPanelSwitch = await page.evaluate(() => {
      const contract = document.querySelector('[data-hy-panel="contract"]');
      const construction = document.querySelector('[data-hy-panel="construction"]');
      return !!contract && !!construction && contract.hidden === true && construction.hidden === false;
    });
  }
  check('F-2 공사 실적 탭 클릭 시 패널 전환(hidden 토글)', fPanelSwitch);
  hyHtml = await page.content();
  check('F-3 범례 "담당 프로젝트 수=현재 배정 기준" 포함', hyHtml.includes('담당 프로젝트 수=현재 배정 기준'));
  await page.screenshot({ path: `${SHOTS}/halfyear_construction_pc.png`, fullPage: true });

  const newTermsOk = hyHtml.includes('총매출') && hyHtml.includes('기여도 반영 매출') && hyHtml.includes('기여도 반영 순이익');
  const oldTermsAbsent = !hyHtml.includes('산정 대상 매출') && !/적용\s*매출/.test(hyHtml) && !/적용\s*순이익/.test(hyHtml);
  check('F-4 현장 보너스 원장 신규 용어(총매출/기여도 반영 매출/기여도 반영 순이익) 표시', newTermsOk);
  check('F-5 구용어(산정 대상 매출/적용 매출/적용 순이익) 부재', oldTermsAbsent);

  const hyMobileCtx = await browser.createBrowserContext();
  const hymobile = await hyMobileCtx.newPage();
  await hymobile.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
  attachConsole(hymobile, 'halfyear-mobile');
  await login(hymobile);
  await hymobile.goto(B + '?r=halfyear.index', { waitUntil: 'networkidle0' });
  await hymobile.screenshot({ path: `${SHOTS}/halfyear_mobile.png`, fullPage: true });
  const hyOverflow = await hymobile.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check('F-6 모바일 반기 화면 가로 오버플로 없음(<=4px)', hyOverflow <= 4, hyOverflow);

  // ═ G. 사이드바 ═
  const navHtml = await page.content();
  const staffPerfAbsent = !navHtml.includes('직원 성과');
  const halfyearBonusPresent = /반기\s*보너스/.test(navHtml);
  check('G-1 사이드바 "직원 성과" 메뉴 부재', staffPerfAbsent);
  check('G-2 사이드바 "반기 보너스" 메뉴 존재', halfyearBonusPresent);

  await browser.close();

  // ═ H. 콘솔 에러 집계 ═
  check('H 전체 방문 페이지 JS 콘솔/5xx 에러 0건', allConsoleErrors.length === 0,
    allConsoleErrors.map((e) => `[${e.page}] ${e.text}`).join(' | '));

  const pass = results.filter((r) => r.ok).length;
  const fail = results.length - pass;
  console.log(`\n==== 결과: PASS ${pass} / FAIL ${fail} ====`);
  fs.writeFileSync(`${SHOTS}/results.json`, JSON.stringify({ results, consoleErrors: allConsoleErrors }, null, 2));
  process.exit(fail ? 1 : 0);
})().catch((e) => {
  console.error('FATAL', e);
  process.exit(2);
});
