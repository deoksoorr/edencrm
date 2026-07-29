/**
 * DR 테스트 T7 — 복구본 기동·인증·권한 검증.
 *
 * 검증 관점: "앱이 뜬다"가 아니라 "복구된 계정으로 실제 업무 권한이 그대로 살아있는가".
 * 권한은 긍정(되어야 할 것이 된다)과 부정(막혀야 할 것이 막힌다) 양쪽을 다 본다 —
 * 한쪽만 보면 "전부 허용"인 망가진 복구본도 통과해버린다.
 */
const L = require('./lib_dr.js');
const { Client, chk, pass, fail, warn, info, section, saveResults, pageErrors, errorDetails, sql1, sqlRows, P } = L;

const CRED = {
  admin: ['admin', 'QArestore!2026admin'],
  sales: ['test1', 'QArestore!2026sales'],
  staff: ['hghg', 'QArestore!2026staff'],
};

(async () => {
  // ── 1. 기동 ──────────────────────────────────────────────────────────────
  section('기동');
  const anon = new Client('anon');
  const login = await anon.get('login');
  chk('로그인 페이지 200', login.status === 200, `HTTP ${login.status}`);
  chk('복구 테스트 배너 표시', login.body.includes('재해복구 테스트 환경'), '배너 주입 확인');
  chk('검색엔진 차단 헤더', /noindex/i.test(login.headers['x-robots-tag'] || ''), login.headers['x-robots-tag'] || '없음');
  chk('세션 쿠키 격리', /eden_restore_sid/.test((login.headers['set-cookie'] || []).join(';')),
    (login.headers['set-cookie'] || []).join(';').split(';')[0]);
  const errs = pageErrors(login.body);
  chk('로그인 페이지 치명 오류 없음', errs.fatal.length === 0, errs.fatal.join(',') || '치명 오류 0');

  // DB 연결이 살아있는지 — settings 를 읽어 앱 이름이 반영되는지로 간접 확인
  const appName = sql1(`SELECT value FROM \`${P}settings\` WHERE setting_key='app_name'`);
  info('DB 연결', `settings.app_name = ${appName || '(미설정)'}`);

  // ── 2. 인증 ──────────────────────────────────────────────────────────────
  section('인증');
  const bad = new Client('bad');
  await bad.login(CRED.admin[0], 'WrongPassword!nope');
  const badDash = await bad.get('dashboard');
  chk('잘못된 비밀번호 거부', !/로그아웃/.test(badDash.body), `대시보드 진입 차단 (HTTP ${badDash.status})`);

  const admin = new Client('admin');
  await admin.login(...CRED.admin);
  const adminDash = await admin.get('dashboard');
  chk('최고운영자 로그인', adminDash.status === 200 && /로그아웃/.test(adminDash.body), `HTTP ${adminDash.status}`);
  chk('최고운영자 대시보드 치명 오류 없음', pageErrors(adminDash.body).fatal.length === 0,
    pageErrors(adminDash.body).fatal.join(',') || '치명 오류 0');

  const sales = new Client('sales');
  await sales.login(...CRED.sales);
  const salesDash = await sales.get('dashboard');
  chk('영업관리자 로그인', salesDash.status === 200 && /로그아웃/.test(salesDash.body), `HTTP ${salesDash.status}`);

  const staff = new Client('staff');
  await staff.login(...CRED.staff);
  const staffDash = await staff.get('dashboard');
  chk('일반 직원 로그인', staffDash.status === 200 && /로그아웃/.test(staffDash.body), `HTTP ${staffDash.status}`);
  chk('일반 직원 대시보드 치명 오류 없음', pageErrors(staffDash.body).fatal.length === 0,
    pageErrors(staffDash.body).fatal.join(',') || '치명 오류 0');

  // ── 3. 세션 ──────────────────────────────────────────────────────────────
  section('세션');
  const sid1 = admin.cookies.eden_restore_sid;
  const again = await admin.get('dashboard');
  chk('세션 유지', /로그아웃/.test(again.body) && admin.cookies.eden_restore_sid === sid1, `sid 동일 (${String(sid1).slice(0, 8)}…)`);

  const tmp = new Client('tmp');
  await tmp.login(...CRED.staff);
  await tmp.logout();
  const afterLogout = await tmp.get('dashboard');
  chk('로그아웃 후 세션 무효화', !/로그아웃/.test(afterLogout.body), `HTTP ${afterLogout.status} · 대시보드 차단`);

  // ── 4. 권한 — 긍정(최고운영자는 되어야 한다) ─────────────────────────────
  // 라우트 키는 복구본 app/routes.php 에서 그대로 가져왔다(추측 금지).
  section('권한: 최고운영자 허용');
  const ADMIN_ROUTES = [
    ['분석', 'reports.index', {}], ['설정', 'settings.index', {}],
    ['직원관리', 'staff.index', {}], ['감사로그', 'audit.index', {}],
    ['고객', 'customers.index', {}], ['영업기회', 'pipeline.index', {}],
    ['견적', 'quotes.index', {}], ['계약', 'contracts.index', {}],
    ['프로젝트', 'projects.index', {}], ['공정보드', 'process.board', {}],
    ['일정', 'schedule.index', {}], ['성과', 'performance.index', {}],
    // 휴지통은 별도 라우트가 아니라 목록의 trash=1 모드다(R16: 최고운영자 전용).
    ['휴지통(고객)', 'customers.index', { trash: 1 }],
    ['휴지통(계약)', 'contracts.index', { trash: 1 }],
    ['휴지통(프로젝트)', 'projects.index', { trash: 1 }],
  ];
  for (const [label, route, params] of ADMIN_ROUTES) {
    const r = await admin.get(route, params);
    const denied = r.status === 403 || /권한이 없|접근 권한/.test(r.body);
    chk(`최고운영자 ${label} 접근`, r.status === 200 && !denied, `HTTP ${r.status}`);
    const e = pageErrors(r.body);
    // 치명(Fatal/SQLSTATE)은 복구 실패. 경고(Deprecated 등)는 위치까지 기록만 한다.
    if (e.fatal.length) fail(`${label} 치명 오류`, e.fatal.join(','));
    if (e.soft.length) {
      const d = errorDetails(r.body).map((x) => `${x.level} ${x.file}:${x.line} ${x.msg}`).join(' | ');
      warn(`${label} 경고`, d || e.soft.join(','));
    }
  }

  // ── 5. 권한 — 부정(일반 직원은 막혀야 한다) ──────────────────────────────
  section('권한: 일반 직원 차단');
  const DENY_ROUTES = [
    ['분석', 'reports.index', {}], ['설정', 'settings.index', {}],
    ['직원관리', 'staff.index', {}], ['감사로그', 'audit.index', {}],
    ['휴지통(고객)', 'customers.index', { trash: 1 }],
    ['휴지통(계약)', 'contracts.index', { trash: 1 }],
    ['휴지통(프로젝트)', 'projects.index', { trash: 1 }],
  ];
  for (const [label, route, params] of DENY_ROUTES) {
    const r = await staff.get(route, params);
    // 403 이 정석. 302(로그인 리다이렉트)나 권한 문구도 차단으로 인정한다.
    // 중요한 건 "일반 목록으로 조용히 폴백"하지 않는 것 — 그건 휴지통 노출이다.
    const blocked = r.status === 403 || r.status === 302 || /권한이 없|접근 권한/.test(r.body);
    chk(`일반 직원 ${label} 차단`, blocked, `HTTP ${r.status}${blocked ? '' : ' — 접근됨(문제)'}`);
  }

  // ── 6. URL·API 직접 우회 ─────────────────────────────────────────────────
  section('권한: URL·API 우회');
  // 휴지통 복원·완전삭제는 엔티티별 라우트이며 perm=trash.manage 로 보호된다.
  const TRASH_OPS = [
    ['고객 복원', 'customers.restore'], ['고객 완전삭제', 'customers.purge'],
    ['계약 복원', 'contracts.restore'], ['계약 완전삭제', 'contracts.purge'],
    ['프로젝트 복원', 'projects.restore'], ['프로젝트 완전삭제', 'projects.purge'],
  ];
  for (const [label, route] of TRASH_OPS) {
    const r = await staff.post(route, { id: '1' }, { json: true });
    const blocked = r.status === 403 || r.status === 302
      || (r.json && r.json.ok === false) || /권한/.test(r.body);
    chk(`일반 직원 ${label} 차단`, blocked, `HTTP ${r.status}`);
  }

  // 비로그인 직접 접근
  const anon2 = new Client('anon2');
  for (const route of ['dashboard', 'customers.index', 'reports.index', 'contracts.index']) {
    const r = await anon2.get(route);
    const blocked = r.status === 302 || r.status === 403 || /로그인/.test(r.body);
    chk(`비로그인 ${route} 차단`, blocked, `HTTP ${r.status}`);
  }

  // CSRF 토큰 없는 POST 거부
  const noCsrf = await admin.request('POST', `${L.BASE}?r=customers.save`, { name: 'QARESTORE-csrf-probe' });
  chk('CSRF 토큰 없는 POST 거부', noCsrf.status === 403 || /CSRF|토큰/.test(noCsrf.body), `HTTP ${noCsrf.status}`);
  // 그 시도가 실제로 데이터를 만들지 않았는지 확인한다(거부 문구만 믿지 않는다).
  const leaked = sql1(`SELECT COUNT(*) FROM \`${P}customers\` WHERE name LIKE 'QARESTORE-csrf%'`);
  chk('CSRF 거부 후 데이터 미생성', String(leaked) === '0', `잔존 ${leaked}건`);

  // ── 6. 복구된 권한 데이터가 실제로 적용되는가 ────────────────────────────
  section('권한 데이터 반영');
  const permRows = sqlRows(`SELECT u.login_id, ep.section, ep.resource_key, ep.can_read, ep.can_write, ep.can_delete
                              FROM \`${P}employee_permissions\` ep
                              JOIN \`${P}users\` u ON u.id = ep.user_id
                             WHERE u.login_id = '${CRED.staff[0]}' ORDER BY ep.section, ep.resource_key`);
  info('일반 직원 권한 레코드', `${permRows.length}건 복구됨`);
  const writable = permRows.filter((r) => r.can_write === '1').map((r) => r.resource_key);
  const readable = permRows.filter((r) => r.can_read === '1').map((r) => r.resource_key);
  info('읽기 권한 자원', readable.join(',') || '(없음)');
  info('쓰기 권한 자원', writable.join(',') || '(없음)');

  const s = saveResults(`${L.RESTORE_ROOT}/_dr/evidence/t7_auth.json`);
  process.exit(s.FAIL > 0 ? 1 : 0);
})().catch((e) => { console.error('실행 오류:', e); process.exit(2); });
