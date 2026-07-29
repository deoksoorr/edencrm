/**
 * DR 기능 QA 공통 라이브러리 — 복구 환경 전용.
 *
 * HTTP 클라이언트는 기존 QA 하네스(scripts/qa_final/lib.js)의 Client 를 그대로 쓴다
 * (쿠키 유지·CSRF 추출이 이미 검증된 코드다). 다만 그쪽 sql() 은 개발 DB 로
 * 하드코딩돼 있어 여기서 복구 DB 전용 헬퍼를 새로 정의한다 — 복구 테스트가
 * 실수로 개발 DB 를 건드리는 일이 없어야 한다.
 */
process.env.QA_BASE = process.env.QA_BASE || 'http://127.0.0.1:8091/index.php';

const { Client, strip, num } = require('../../qa_final/lib.js');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const PROJECT_ROOT = '/Users/deoksookim/Desktop/코드/claude code/eden_crm';
const RESTORE_ROOT = '/Users/deoksookim/Desktop/코드/claude code/eden_crm_restore_test';
const RESTORE_DB = 'eden_crm_restore_test';
const DB_USER = 'eden_restore_user';
const DB_PASS = 'EdenRestore!test2026';
const SOCK = `${PROJECT_ROOT}/.devdb/mysql.sock`;
const P = 'edencrm_';                  // 복구본 테이블 prefix (운영과 동일)
const QA = 'QARESTORE-';               // 이 테스트가 만든 데이터의 표식

// 하드 가드: 대상 DB 이름이 복구용이 아니면 아예 실행하지 않는다.
if (!RESTORE_DB.endsWith('_restore_test')) {
  console.error('가드: 복구 DB 가 아님 — 중단');
  process.exit(9);
}

const TMPQ = path.join(os.tmpdir(), `dr_q_${process.pid}.sql`);
function sql(q) {
  fs.writeFileSync(TMPQ, q);
  const out = execFileSync('/bin/sh', ['-c',
    `mysql --socket='${SOCK}' --default-character-set=utf8mb4 -u ${DB_USER} -p'${DB_PASS}' ${RESTORE_DB} --batch --raw < '${TMPQ}' 2>&1 | grep -v '^mysql: \\[Warning\\]' || true`,
  ], { encoding: 'utf8' });
  return out.replace(/\n$/, '');
}
function sqlRows(q) {
  const out = sql(q).trim();
  if (!out) return [];
  const lines = out.split('\n');
  const cols = lines[0].split('\t');
  return lines.slice(1).map((l) => {
    const v = l.split('\t'); const o = {};
    cols.forEach((c, i) => { o[c] = v[i]; });
    return o;
  });
}
function sql1(q) { const r = sqlRows(q); return r.length ? Object.values(r[0])[0] : null; }

// ── 결과 집계 ──
const results = [];
let _section = '기타';
function section(s) { _section = s; console.log(`\n──── ${s} ────`); }
function record(status, item, evidence, note = '') {
  results.push({ section: _section, item, status, evidence: String(evidence || '').slice(0, 800), note });
  const icon = { PASS: '✅', FAIL: '❌', WARN: '⚠️', INFO: 'ℹ️' }[status];
  console.log(`${icon} [${_section}] ${item} — ${String(evidence || '').slice(0, 180)}`);
}
const pass = (i, e, n) => record('PASS', i, e, n);
const fail = (i, e, n) => record('FAIL', i, e, n);
const warn = (i, e, n) => record('WARN', i, e, n);
const info = (i, e, n) => record('INFO', i, e, n);
const chk = (i, cond, e, n) => (cond ? pass(i, e, n) : fail(i, e, n));

function summary() {
  const c = { PASS: 0, FAIL: 0, WARN: 0, INFO: 0 };
  results.forEach((r) => { c[r.status]++; });
  return c;
}
function saveResults(file) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify({ at: new Date().toISOString(), summary: summary(), results }, null, 2));
  const s = summary();
  console.log(`\n== 요약: PASS ${s.PASS} · FAIL ${s.FAIL} · WARN ${s.WARN} · INFO ${s.INFO} → ${file}`);
  return s;
}

/**
 * 응답에서 PHP·SQL 오류 흔적을 찾되 **심각도를 나눠서** 반환한다.
 *
 * 전부 한 덩어리로 묶으면 판정이 흐려진다. Deprecated 는 로컬 PHP 8.5 기준 경고이고
 * 운영(APP_ENV=production)에서는 화면에 뜨지도 않는다 — 복구 성패와 직결되지 않는다.
 * 반면 Fatal/SQLSTATE 는 그 화면이 실제로 안 뜬다는 뜻이라 복구 실패다.
 */
function pageErrors(html) {
  const fatal = [
    [/Fatal error/i, 'Fatal error'], [/Parse error/i, 'Parse error'],
    [/SQLSTATE\[/i, 'SQLSTATE'], [/Uncaught \w*(Exception|Error)/i, 'Uncaught exception'],
  ];
  const soft = [
    [/Warning<\/b>:/i, 'Warning'], [/Notice<\/b>:/i, 'Notice'],
    [/Deprecated<\/b>:/i, 'Deprecated'],
    [/Undefined (variable|index|array key|property)/i, 'Undefined symbol'],
  ];
  const hit = (list) => list.filter(([p]) => p.test(html)).map(([, n]) => n);
  const f = hit(fatal);
  const s = hit(soft);
  const out = [...f, ...s];
  out.fatal = f;      // 복구 실패 판정 대상
  out.soft = s;       // 기록은 하되 실패 판정에는 쓰지 않음
  return out;
}

/**
 * 리다이렉트 뒤에 남는 플래시 메시지를 뽑는다.
 * 저장 실패가 302 로 돌아오면 상태코드만으로는 이유를 알 수 없다 —
 * 원인을 추측하지 말고 앱이 남긴 문구를 그대로 읽는다.
 */
function flashOf(html) {
  const m = html.match(/<div class="flash flash-(\w+)"[^>]*>([\s\S]*?)<\/div>/);
  if (!m) return null;
  return { type: m[1], msg: m[2].replace(/<[^>]+>/g, '').trim() };
}

/** Deprecated 등 경고의 발생 위치(파일:라인)를 뽑아 원인 추적에 쓴다. */
function errorDetails(html) {
  const re = /(Deprecated|Warning|Notice)<\/b>:\s*(.+?)\s+in\s+<b>(.+?)<\/b>\s+on line\s+<b>(\d+)<\/b>/gi;
  const out = [];
  let m;
  while ((m = re.exec(html)) !== null) {
    const key = `${m[1]}|${m[3]}:${m[4]}`;
    if (!out.some((o) => o.key === key)) {
      out.push({ key, level: m[1], msg: m[2].replace(/<[^>]+>/g, ''), file: m[3].split('/').pop(), line: m[4] });
    }
  }
  return out;
}

module.exports = {
  Client, strip, num, sql, sqlRows, sql1,
  section, record, pass, fail, warn, info, chk,
  results, summary, saveResults, pageErrors, errorDetails, flashOf,
  PROJECT_ROOT, RESTORE_ROOT, RESTORE_DB, P, QA,
  BASE: process.env.QA_BASE,
};
