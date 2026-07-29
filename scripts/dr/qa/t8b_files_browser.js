/**
 * DR 테스트 T8-b — 첨부파일 복구 검증 + 브라우저(PC·모바일) 렌더링 확인.
 *
 * DB 만 복원되고 첨부가 없으면 복구 성공이 아니다. DB 의 파일 경로와 실제 파일을
 * 대조하고, 실제로 다운로드가 되는지, 권한 없는 사용자는 막히는지까지 본다.
 * 개인정보가 담긴 파일이므로 파일명·내용은 보고서에 남기지 않고 크기·해시만 쓴다.
 */
const L = require('./lib_dr.js');
const { Client, chk, pass, fail, warn, info, section, saveResults, sql1, sqlRows, P, RESTORE_ROOT } = L;
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const http = require('http');

/**
 * 바이너리 안전 GET.
 * 공용 Client 는 응답을 UTF-8 문자열로 읽어(setEncoding) 이미지·PDF 의 바이트 수가
 * 실제와 달라진다. 첨부 무결성은 바이트로 따져야 하므로 여기서만 raw 로 받는다.
 */
function getBinary(url, cookieHeader) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const req = http.request({
      hostname: u.hostname, port: u.port || 80, path: u.pathname + u.search,
      method: 'GET', headers: { Cookie: cookieHeader },
    }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, buf: Buffer.concat(chunks) }));
    });
    req.on('error', reject);
    req.end();
  });
}

const CRED = {
  admin: ['admin', 'QArestore!2026admin'],
  staff: ['hghg', 'QArestore!2026staff'],
};

(async () => {
  const admin = new Client('admin');
  await admin.login(...CRED.admin);
  const staff = new Client('staff');
  await staff.login(...CRED.staff);

  // ══ 1. DB 파일 레코드 ↔ 실제 파일 대조 ═══════════════════════════════════
  section('첨부파일 복구');
  const rows = sqlRows(`SELECT id, entity_type, entity_id, path, size, mime, original_name
                          FROM \`${P}project_files\` ORDER BY id`);
  info('DB 첨부 레코드', `${rows.length}건`);

  const uploadRoot = path.join(RESTORE_ROOT, 'storage', 'uploads');
  let matched = 0;
  const missing = [];
  for (const r of rows) {
    // path 는 storage/uploads 기준 상대경로다. 절대경로가 저장돼 있으면 basename 으로 폴백.
    const cand = [
      path.join(uploadRoot, r.path),
      path.join(RESTORE_ROOT, r.path),
      path.join(RESTORE_ROOT, 'storage', r.path),
    ];
    const found = cand.find((p) => fs.existsSync(p) && fs.statSync(p).isFile());
    if (!found) { missing.push(r.id); continue; }
    const stat = fs.statSync(found);
    const sizeOk = String(stat.size) === String(r.size);
    // 파일명·내용은 남기지 않는다 — id·크기·해시 앞자리만.
    const sha = crypto.createHash('sha256').update(fs.readFileSync(found)).digest('hex').slice(0, 12);
    chk(`첨부 #${r.id} 파일 존재·크기 일치`, sizeOk,
      `${r.entity_type} · DB ${r.size}B / 실제 ${stat.size}B · sha256 ${sha}…`);
    if (sizeOk) matched++;
  }
  chk('DB 경로 ↔ 실제 파일 전건 일치', missing.length === 0 && matched === rows.length,
    `일치 ${matched}/${rows.length}${missing.length ? ` · 누락 id: ${missing.join(',')}` : ''}`);

  // 백업에 있던 업로드 파일이 전부 복원됐는가 (역방향 확인 — 고아 파일 검출)
  const walk = (d) => fs.existsSync(d)
    ? fs.readdirSync(d, { withFileTypes: true }).flatMap((e) =>
      e.isDirectory() ? walk(path.join(d, e.name)) : [path.join(d, e.name)])
    : [];
  const onDisk = walk(uploadRoot);
  chk('디스크 파일 수 = DB 레코드 수', onDisk.length === rows.length,
    `디스크 ${onDisk.length}개 / DB ${rows.length}건 (고아 파일 ${Math.max(0, onDisk.length - rows.length)}개)`);

  // ══ 2. 다운로드 동작 ═════════════════════════════════════════════════════
  section('첨부 다운로드');
  // 복구된 첨부는 전부 customer_license 다 — 전용 라우트를 쓴다.
  // files.download 는 프로젝트 첨부용(perm=project.view_all)이라 여기선 403 이 정상.
  // 다운로드 성공은 **활성 고객**의 첨부로 검증한다.
  // 휴지통 고객의 첨부는 접근이 막히는 게 정상이라(아래 별도 항목), 그걸로 성공을
  // 검증하려 들면 정상 동작을 결함으로 오판한다.
  const aliveIds = sqlRows(`SELECT id FROM \`${P}customers\` WHERE deleted_at IS NULL`).map((x) => String(x.id));
  const trashedIds = sqlRows(`SELECT id FROM \`${P}customers\` WHERE deleted_at IS NOT NULL`).map((x) => String(x.id));
  const aliveFile = rows.find((r) => aliveIds.includes(String(r.entity_id)));
  const trashedFile = rows.find((r) => trashedIds.includes(String(r.entity_id)));
  info('첨부 소유 고객 상태', `활성 고객 첨부 ${aliveFile ? '있음' : '없음'} · 휴지통 고객 첨부 ${trashedFile ? '있음' : '없음'}`);

  if (aliveFile) {
    const target = aliveFile;
    const dl = await admin.get('customers.license.download', { id: target.id });
    const got = Buffer.byteLength(dl.body, 'binary');
    chk('최고운영자 첨부 다운로드', dl.status === 200 && got > 0,
      `HTTP ${dl.status} · ${got}B 수신 (DB 기록 ${target.size}B)`);
    // 바이트 단위 무결성은 raw 수신으로 확인한다(텍스트 디코딩은 바이트 수를 바꾼다).
    const bin = await getBinary(`${L.BASE}?r=customers.license.download&id=${target.id}`, admin.cookieHeader());
    const diskPath = [path.join(uploadRoot, target.path), path.join(RESTORE_ROOT, target.path)]
      .find((p) => fs.existsSync(p));
    const diskSha = diskPath ? crypto.createHash('sha256').update(fs.readFileSync(diskPath)).digest('hex') : null;
    const dlSha = crypto.createHash('sha256').update(bin.buf).digest('hex');
    chk('다운로드 바이트 무결성(sha256 일치)', diskSha !== null && diskSha === dlSha,
      `수신 ${bin.buf.length}B / 원본 ${target.size}B · sha256 ${dlSha.slice(0, 12)}… ${diskSha === dlSha ? '일치' : '불일치'}`);
    chk('다운로드 Content-Type 보존', String(bin.headers['content-type'] || '').includes(target.mime.split('/')[0]),
      `${bin.headers['content-type']} (DB mime ${target.mime})`);

    // 엔티티 타입이 다른 라우트로는 못 가져간다(라우트 혼용 방어)
    const wrongRoute = await admin.get('files.download', { id: target.id });
    chk('타 엔티티 라우트로 접근 차단', wrongRoute.status !== 200 || wrongRoute.body.length < 1000,
      `files.download → HTTP ${wrongRoute.status}`);

    const dlStaff = await staff.get('customers.license.download', { id: target.id });
    chk('권한 없는 직원 다운로드 차단', dlStaff.status === 403 || dlStaff.status === 302,
      `HTTP ${dlStaff.status}`);
    const anon = new Client('anon');
    const dlAnon = await anon.get('customers.license.download', { id: target.id });
    chk('비로그인 다운로드 차단', dlAnon.status === 302 || dlAnon.status === 403, `HTTP ${dlAnon.status}`);
  } else {
    warn('첨부 다운로드', '활성 고객에 연결된 첨부가 없어 성공 경로를 검증하지 못함');
  }

  // 휴지통 고객의 첨부는 최고운영자라도 이 라우트로는 못 받는다(스코프 가드).
  // 복구본에서 이 가드가 풀려 있으면 삭제된 고객의 개인정보가 노출된다.
  if (trashedFile) {
    const dl = await admin.get('customers.license.download', { id: trashedFile.id });
    chk('휴지통 고객 첨부 접근 차단', dl.status === 403,
      `첨부 #${trashedFile.id} (고객 휴지통) → HTTP ${dl.status}`);
  }

  // ══ 3. 경로 traversal / 실행파일 방어 ════════════════════════════════════
  section('업로드 경로 보안');
  for (const evil of ['../../app/config/config.php', '..%2F..%2Fapp%2Froutes.php', '/etc/passwd']) {
    const r = await admin.get('files.download', { id: evil });
    const leaked = /DB_PASS|DB_USER|root:/.test(r.body);
    chk(`경로 traversal 차단 (${evil.slice(0, 22)})`, !leaked && r.status !== 200,
      `HTTP ${r.status}${leaked ? ' — 내용 유출!' : ''}`);
  }
  // 업로드 디렉터리 직접 웹 접근 — 상태코드가 아니라 **파일 바이트가 나갔는가**로 본다.
  // 로그인 상태에서 알 수 없는 경로는 앱 기본 화면(200)으로 떨어지므로
  // 상태코드만 보면 "유출됐다"고 오판한다.
  if (onDisk.length) {
    const fileBytes = fs.readFileSync(onDisk[0]);
    const rel = path.relative(path.join(RESTORE_ROOT, 'public'), onDisk[0]).replace(/\\/g, '/');
    for (const [label, url] of [
      ['정규화 경로', `http://127.0.0.1:8091/storage/uploads/${path.relative(uploadRoot, onDisk[0]).replace(/\\/g, '/')}`],
      ['상위탈출 경로', `http://127.0.0.1:8091/${rel}`],
    ]) {
      const direct = await admin.request('GET', url);
      const served = Buffer.byteLength(direct.body, 'binary') === fileBytes.length
        && /image\//.test(direct.headers['content-type'] || '');
      chk(`업로드 파일 직접접근 미서빙 (${label})`, !served,
        `HTTP ${direct.status} · content-type=${direct.headers['content-type'] || '없음'} · ${Buffer.byteLength(direct.body, 'binary')}B (원본 ${fileBytes.length}B)`);
    }
    info('운영 방어선', '.htaccess 22행 RewriteRule ^(app|storage|...)(/|$) - [F,L] 로 403 차단 — php -S 는 .htaccess 를 읽지 않으므로 이 검증은 앱 레벨 결과만 반영');
  }

  const s = saveResults(`${RESTORE_ROOT}/_dr/evidence/t8b_files.json`);
  process.exit(s.FAIL > 0 ? 1 : 0);
})().catch((e) => { console.error('실행 오류:', e); process.exit(2); });
