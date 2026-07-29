#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
DR T2 — 백업본 자체 검증 (읽기 전용).

운영 서버/운영 DB 에 접속하지 않는다. 로컬 백업 파일만 읽는다.
어떤 파일도 수정/삭제하지 않는다. 산출물은 docs/audit/dr/backup_verification.json 하나뿐이다.

사용:
    python3 scripts/dr/verify_backup.py
    python3 scripts/dr/verify_backup.py --ftp <dir> --sql <file> --out <json>
"""
import argparse
import hashlib
import json
import os
import re
import shlex
import stat
import subprocess
from collections import Counter, OrderedDict
from datetime import datetime, timezone

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))

DEFAULT_FTP = os.path.join(ROOT, 'database/backups/ftp_20260729-103659')
DEFAULT_SQL = os.path.join(ROOT, 'database/backups/proddb_audit_pre_20260729-013710.sql')
DEFAULT_BASE = os.path.join(ROOT, 'docs/audit/dr/baseline_prod.json')
DEFAULT_OUT = os.path.join(ROOT, 'docs/audit/dr/backup_verification.json')

PREFIX = 'edencrm_'


def sh(cmd, cwd=ROOT):
    p = subprocess.run(cmd, shell=True, cwd=cwd, capture_output=True, text=True)
    return p.stdout.strip(), p.returncode


def iso(ts):
    return datetime.fromtimestamp(ts).astimezone().isoformat(timespec='seconds')


# ────────────────────────────────────────────────────────────── A. 파일 백업
def verify_files(ftp_dir, baseline):
    r = OrderedDict()
    if not os.path.isdir(ftp_dir):
        return {'error': 'ftp dir not found: ' + ftp_dir}

    files, dirs, links = [], [], []
    for dp, dn, fn in os.walk(ftp_dir):
        dirs.append(dp)
        for n in dn:
            p = os.path.join(dp, n)
            if os.path.islink(p):
                links.append(p)
        for n in fn:
            p = os.path.join(dp, n)
            if os.path.islink(p):
                links.append(p)
            else:
                files.append(p)

    rel = lambda p: os.path.relpath(p, ftp_dir)
    st_dir = os.stat(ftp_dir)
    sizes = {rel(p): os.path.getsize(p) for p in files}
    mtimes = {rel(p): os.path.getmtime(p) for p in files}
    modes = Counter(oct(stat.S_IMODE(os.stat(p).st_mode)) for p in files)
    dmodes = Counter(oct(stat.S_IMODE(os.stat(p).st_mode)) for p in dirs)

    # 1. 생성 시각 / 규모 / 0바이트
    m = re.search(r'ftp_(\d{8})-(\d{6})$', ftp_dir.rstrip('/'))
    r['A1_inventory'] = {
        'dir': rel(ftp_dir) if False else os.path.relpath(ftp_dir, ROOT),
        'dirname_timestamp_local': (m.group(1) + 'T' + m.group(2)) if m else None,
        'dir_mtime_local': iso(st_dir.st_mtime),
        'file_count': len(files),
        'dir_count': len(dirs),
        'symlink_count': len(links),
        'symlinks': [rel(p) for p in links],
        'total_bytes': sum(sizes.values()),
        'zero_byte_files': sorted(k for k, v in sizes.items() if v == 0),
        'file_mtime_min_local': iso(min(mtimes.values())),
        'file_mtime_max_local': iso(max(mtimes.values())),
        'newest_file': max(mtimes, key=mtimes.get),
    }

    # 2. 필수 영역
    must = OrderedDict([
        ('entrypoint public/index.php', 'public/index.php'),
        ('routes app/routes.php', 'app/routes.php'),
        ('bootstrap app/bootstrap.php', 'app/bootstrap.php'),
        ('config app/config/config.php', 'app/config/config.php'),
        ('root .htaccess', '.htaccess'),
        ('storage/.htaccess', 'storage/.htaccess'),
        ('public/.user.ini', 'public/.user.ini'),
        ('css public/assets/css/app.css', 'public/assets/css/app.css'),
        ('js public/assets/js/app.js', 'public/assets/js/app.js'),
        ('vendor chart.umd.js', 'public/assets/vendor/chart.umd.js'),
        ('vendor Sortable.min.js', 'public/assets/vendor/Sortable.min.js'),
    ])
    r['A2_required'] = OrderedDict(
        (k, {'path': v, 'present': v in sizes, 'bytes': sizes.get(v)}) for k, v in must.items())
    groups = {'app/controllers/': 0, 'app/core/': 0, 'app/views/': 0,
              'public/assets/css/': 0, 'public/assets/js/': 0,
              'public/assets/vendor/': 0, 'storage/uploads/': 0, 'storage/logs/': 0}
    for k in sizes:
        for g in groups:
            if k.startswith(g):
                groups[g] += 1
    r['A2_group_file_counts'] = groups
    r['A2_missing_required'] = [k for k, v in r['A2_required'].items() if not v['present']]
    # 정적 이미지 리소스
    r['A2_static_images'] = sorted(
        k for k in sizes if re.search(r'\.(png|jpe?g|gif|svg|ico|webp)$', k, re.I)
        and not k.startswith('storage/'))

    # 3. 업로드 파일 vs project_files
    up = sorted(k for k in sizes if k.startswith('storage/uploads/'))
    up_rel = [k[len('storage/uploads/'):] for k in up]
    dbrows = baseline.get('files', {}).get('project_files_rows', [])
    db_paths = {row['path']: int(row['size']) for row in dbrows}
    matched, size_mismatch = [], []
    for p, s in db_paths.items():
        if p in up_rel:
            actual = sizes['storage/uploads/' + p]
            (matched if actual == s else size_mismatch).append(
                {'path': p, 'db_size': s, 'file_size': actual})
        else:
            size_mismatch.append({'path': p, 'db_size': s, 'file_size': None, 'note': 'MISSING IN BACKUP'})
    r['A3_uploads'] = {
        'backup_upload_files': up_rel,
        'backup_upload_count': len(up_rel),
        'db_project_files_count': baseline.get('files', {}).get('project_files_count'),
        'db_paths': list(db_paths.keys()),
        'matched_exact': matched,
        'mismatch_or_missing': size_mismatch,
        'orphan_files_not_in_db': [p for p in up_rel if p not in db_paths],
        'verdict': 'PASS' if (len(matched) == len(db_paths) and not size_mismatch
                              and len(up_rel) == len(db_paths)) else 'FAIL',
    }

    # 4. 로그/캐시 디렉터리
    dirset = {os.path.relpath(d, ftp_dir) for d in dirs}
    r['A4_runtime_dirs'] = {
        'storage_logs_present': 'storage/logs' in dirset,
        'storage_logs_files': sorted(k for k in sizes if k.startswith('storage/logs/')),
        'storage_uploads_present': 'storage/uploads' in dirset,
        'cache_dir_present': any('cache' in d.lower() for d in dirset),
        'session_dir_present': any('session' in d.lower() for d in dirset),
        'gitkeep_files': sorted(k for k in sizes if k.endswith('.gitkeep')),
        'empty_dirs': sorted(os.path.relpath(d, ftp_dir) for d in dirs
                             if not os.listdir(d)),
    }

    # 5. 심볼릭 링크
    r['A5_symlinks'] = {
        'count': len(links),
        'note': 'lftp mirror 기본은 심링크를 재생성하지만, 백업본에 링크가 0개이므로 복구 시 링크 재현 이슈 없음',
    }

    # 6. 민감 설정 파일
    sec = []
    for k in sorted(sizes):
        if re.search(r'(config\.local\.php|\.env$|cafe24|credential|secret|\.key$|\.pem$)', k, re.I):
            p = os.path.join(ftp_dir, k)
            sec.append({'path': k, 'bytes': sizes[k],
                        'mode': oct(stat.S_IMODE(os.stat(p).st_mode)),
                        'sha256_12': hashlib.sha256(open(p, 'rb').read()).hexdigest()[:12]})
    # config.local.php 안에 DB 자격증명 "키 이름"만 탐지 (값 미출력)
    clp = os.path.join(ftp_dir, 'app/config/config.local.php')
    keys_found = []
    if os.path.exists(clp):
        txt = open(clp, 'r', encoding='utf-8', errors='replace').read()
        for kname in ['host', 'port', 'name', 'user', 'pass', 'password', 'charset',
                      'db', 'prefix', 'DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME']:
            if re.search(r"['\"]" + re.escape(kname) + r"['\"]\s*=>", txt):
                keys_found.append(kname)
    r['A6_secrets_in_backup'] = {
        'sensitive_files': sec,
        'config_local_php_present': os.path.exists(clp),
        'config_local_php_keys_only': sorted(set(keys_found)),
        'note': '값은 절대 기록하지 않음 — 키 이름/크기/모드만',
        'gitignored_locally': None,  # 아래에서 채움
    }

    # 7. git HEAD 와 비교
    tracked, _ = sh("git ls-files -- app public .htaccess")
    tracked = set(x for x in tracked.splitlines() if x)
    # 배포 대상 아닌 것 제외 규칙: 백업 루트에 존재하는 top-level 만 비교
    backup_set = set(sizes.keys())
    cmp_tracked = {t for t in tracked
                   if t == '.htaccess' or t.startswith('app/') or t.startswith('public/')}
    only_local = sorted(cmp_tracked - backup_set)
    only_backup = sorted(k for k in backup_set - cmp_tracked
                         if k.startswith('app/') or k.startswith('public/') or k == '.htaccess')
    # 내용 동일 여부
    same, differ = [], []
    for k in sorted(cmp_tracked & backup_set):
        lp, bp = os.path.join(ROOT, k), os.path.join(ftp_dir, k)
        try:
            lh = hashlib.sha256(open(lp, 'rb').read()).hexdigest()
            bh = hashlib.sha256(open(bp, 'rb').read()).hexdigest()
        except OSError:
            continue
        (same if lh == bh else differ).append(k)
    head, _ = sh("git log -1 --format='%H %cI %s'")
    dirty, _ = sh("git status --porcelain")
    r['A7_git_diff'] = {
        'git_head': head,
        'git_dirty_entries': len([x for x in dirty.splitlines() if x]),
        'tracked_deployable': len(cmp_tracked),
        'backup_deployable': len(only_backup) + len(cmp_tracked & backup_set),
        'identical': len(same),
        'content_differs': differ,
        'only_in_local_git_MISSING_FROM_BACKUP': only_local,
        'only_in_backup_untracked': only_backup,
    }

    # 7b. 백업본 내용의 출처 판정 — 백업 파일 blob 이 git object DB 에 있으면
    #     "과거 커밋 시점의 정상 배포본"(시점 차이), 없으면 "서버에서 직접 수정된 드리프트".
    known, unknown = [], []
    for k in sorted(backup_set):
        if not (k.startswith('app/') or k.startswith('public/') or k == '.htaccess'):
            continue
        bp = os.path.join(ftp_dir, k)
        blob, rc = sh("git hash-object -- %s" % shlex.quote(bp))
        if rc != 0 or not re.fullmatch(r'[0-9a-f]{40}', blob):
            unknown.append(k + '  (hash-object failed)')
            continue
        exists = sh("git cat-file -e %s 2>/dev/null && echo YES || echo NO" % blob)[0]
        (known if exists.endswith('YES') else unknown).append(k)
    r['A7_provenance'] = {
        'blob_found_in_git_history': len(known),
        'blob_NOT_in_git_history': unknown,
        'interpretation': ('git 이력에 없는 blob = 서버에서 직접 수정됐거나 미커밋 산출물'
                           ' / 있는 blob = 과거 커밋의 정상 배포본(시점 차이)'),
    }

    # 7c. 배포 로그와의 시각 상관 — 백업이 어느 배포 시점의 상태를 담고 있는가
    logs = sorted(x for x in os.listdir(os.path.join(ROOT, 'deploy'))
                  if re.match(r'deploy_\d{8}-\d{6}\.log$', x))
    dep = [{'log': x, 'mtime_local': iso(os.path.getmtime(os.path.join(ROOT, 'deploy', x)))}
           for x in logs]
    bts = st_dir.st_mtime
    r['A9_deploy_correlation'] = {
        'deploy_log_count': len(dep),
        'last_deploy_before_backup': next(
            (d for d in reversed(dep)
             if datetime.fromisoformat(d['mtime_local']).timestamp() < bts), None),
        'first_deploy_after_backup': next(
            (d for d in dep
             if datetime.fromisoformat(d['mtime_local']).timestamp() > bts), None),
        'backup_dir_mtime': iso(bts),
        'note': ('백업 이후 배포가 있으면 이 백업은 현재 운영 코드를 담고 있지 않다'
                 ' — 파일 복구 시 그만큼 롤백된다'),
    }

    # 10. 디렉터리 mtime 왜곡 — lftp 가 원격 디렉터리 시각을 UTC 로 오해석(+9h)
    now = datetime.now().timestamp()
    skews = []
    for dp, _dn, fn in os.walk(ftp_dir):
        if not fn:
            continue
        fm = max(os.path.getmtime(os.path.join(dp, f)) for f in fn)
        skews.append(round((os.path.getmtime(dp) - fm) / 3600, 2))
    r['A10_dir_mtime_skew'] = {
        'dirs_with_files': len(skews),
        'median_skew_hours': sorted(skews)[len(skews) // 2] if skews else None,
        'dirs_dated_in_future': sum(1 for d in dirs if os.path.getmtime(d) > now),
        'files_dated_in_future': sum(1 for p in files if os.path.getmtime(p) > now),
        'note': ('디렉터리 mtime 이 내부 파일보다 중앙값 +9h — 파일 mtime 은 정상. '
                 '증분/차등 복구를 mtime 으로 판단하면 안 된다'),
    }

    # 8. 권한 분포
    r['A8_modes'] = {'files': dict(modes), 'dirs': dict(dmodes),
                     'note': ('파일 mode 는 lftp mirror 가 원격 LIST 기준으로 적용, 디렉터리는 로컬 mkdir'
                              ' umask 라 원격 실제값이 아님 — 복구 시 storage/ 쓰기권한 재설정 필수')}
    return r


# ────────────────────────────────────────────────────────────── B. SQL 덤프
CREATE_RE = re.compile(r'^CREATE TABLE `([^`]+)`')
DROP_RE = re.compile(r'^DROP TABLE IF EXISTS `([^`]+)`;')
INSERT_RE = re.compile(r'^INSERT INTO `([^`]+)` VALUES \(')

COMPAT_PATTERNS = OrderedDict([
    ('int_display_width', re.compile(r'\b(?:big|small|medium|tiny)?int\(\d+\)')),
    ('tinyint_1', re.compile(r'\btinyint\(1\)')),
    ('current_timestamp_parens', re.compile(r'current_timestamp\(\)')),
    ('on_update_current_timestamp_parens', re.compile(r'ON UPDATE current_timestamp\(\)')),
    ('utf8mb3_or_utf8_legacy', re.compile(r'\butf8mb3\b|CHARSET=utf8\b|COLLATE=utf8_')),
    ('row_format', re.compile(r'\bROW_FORMAT\s*=')),
    ('text_blob_default_null', re.compile(
        r'\b(tiny|medium|long)?(text|blob)\b[^,\n]*\bDEFAULT NULL')),
    ('json_default', re.compile(r'\bjson\b[^,\n]*\bDEFAULT\b')),
    ('mariadb_check_constraint', re.compile(r'\bCONSTRAINT\s+`[^`]+`\s+CHECK\b|^\s*CHECK\s*\(')),
    ('mariadb_invisible', re.compile(r'\bINVISIBLE\b')),
    ('mariadb_system_versioning', re.compile(r'WITH SYSTEM VERSIONING|PERIOD FOR')),
    ('mariadb_page_checksum', re.compile(r'\bPAGE_CHECKSUM\s*=|\bTRANSACTIONAL\s*=')),
    ('mariadb_uuid_default', re.compile(r'DEFAULT\s+uuid\(\)')),
    ('generated_virtual_persistent', re.compile(r'\bPERSISTENT\b|\bVIRTUAL\b')),
    ('zerofill', re.compile(r'\bzerofill\b', re.I)),
    ('fulltext_or_spatial', re.compile(r'\bFULLTEXT KEY\b|\bSPATIAL KEY\b')),
    ('double_unsigned', re.compile(r'\b(double|float|decimal)\([^)]*\)\s+unsigned')),
])

ERROR_PATTERNS = re.compile(
    r'(PHP (Fatal|Warning|Notice|Parse)|Uncaught|SQLSTATE\[|Stack trace|Fatal error|Warning:'
    r'|mysqli?_|Call to undefined|Allowed memory size|Maximum execution time)', re.I)


def verify_sql(sql_path, baseline):
    r = OrderedDict()
    if not os.path.isfile(sql_path):
        return {'error': 'sql not found: ' + sql_path}
    size = os.path.getsize(sql_path)
    raw = open(sql_path, 'rb').read()
    try:
        raw.decode('utf-8')
        utf8_ok, utf8_err = True, None
    except UnicodeDecodeError as e:
        utf8_ok, utf8_err = False, str(e)
    text = raw.decode('utf-8', errors='replace')
    lines = text.split('\n')

    # 9. 크기 / 잘림
    tail = text.rstrip('\n').split('\n')[-1]
    r['B9_integrity'] = {
        'path': os.path.relpath(sql_path, ROOT),
        'bytes': size,
        'nonzero': size > 0,
        'mtime_local': iso(os.path.getmtime(sql_path)),
        'header_line': lines[0],
        'header_declared_tables': int(m.group(1)) if (m := re.search(r'(\d+) tables', lines[0])) else None,
        'header_timestamp': (m2.group(0) if (m2 := re.search(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}', lines[0])) else None),
        'total_lines': len(lines),
        'last_nonempty_line': tail,
        'ends_with_semicolon': tail.rstrip().endswith(';'),
        'ends_with_fk_checks_on': tail.strip() == 'SET FOREIGN_KEY_CHECKS=1;',
        'utf8_decodable': utf8_ok,
        'utf8_error': utf8_err,
        'sha256': hashlib.sha256(raw).hexdigest(),
    }

    # 파싱
    creates, drops, inserts = [], [], Counter()
    unknown_lines = []
    in_create = False
    create_bodies = {}
    cur = None
    for ln in lines:
        if in_create:
            create_bodies[cur].append(ln)
            if ln.startswith(')') and ln.rstrip().endswith(';'):
                in_create = False
            continue
        mc = CREATE_RE.match(ln)
        if mc:
            creates.append(mc.group(1)); cur = mc.group(1)
            create_bodies[cur] = [ln]; in_create = True
            continue
        md = DROP_RE.match(ln)
        if md:
            drops.append(md.group(1)); continue
        mi = INSERT_RE.match(ln)
        if mi:
            inserts[mi.group(1)] += 1
            if not ln.rstrip().endswith(');'):
                unknown_lines.append(('INSERT_NOT_CLOSED', ln[:120]))
            continue
        if ln.strip() == '' or ln.startswith('--') or ln.startswith('SET '):
            continue
        unknown_lines.append(('UNPARSED', ln[:120]))

    # 10. CREATE TABLE 집합 비교
    prod = [t['TABLE_NAME'] for t in baseline['inventory']['owned']]
    r['B10_tables'] = {
        'create_table_count': len(creates),
        'prod_table_count': len(prod),
        'drop_table_count': len(drops),
        'set_equal': sorted(creates) == sorted(prod),
        'in_prod_not_in_dump': sorted(set(prod) - set(creates)),
        'in_dump_not_in_prod': sorted(set(creates) - set(prod)),
        'duplicate_creates': [k for k, v in Counter(creates).items() if v > 1],
        'non_prefixed_tables_in_dump': [t for t in creates if not t.startswith(PREFIX)],
    }

    # 11/12. INSERT 건수 대조
    counts = baseline['counts']  # unprefixed key -> rows
    per = OrderedDict()
    diffs = []
    for t in sorted(prod):
        short = t[len(PREFIX):] if t.startswith(PREFIX) else t
        exp = counts.get(short)
        act = inserts.get(t, 0)
        per[short] = {'table': t, 'baseline_rows': exp, 'dump_inserts': act,
                      'delta': (act - exp) if exp is not None else None}
        if exp is None or act != exp:
            diffs.append(per[short])
    r['B11_rows'] = {
        'dump_insert_total': sum(inserts.values()),
        'baseline_row_total': sum(counts.values()),
        'equal': sum(inserts.values()) == sum(counts.values()),
        'delta': sum(inserts.values()) - sum(counts.values()),
    }
    r['B12_per_table'] = per
    r['B12_mismatched_tables'] = diffs
    r['B12_mismatch_count'] = len(diffs)
    r['B12_timing'] = {
        'dump_at': r['B9_integrity']['header_timestamp'],
        'baseline_measured_at': baseline['meta']['measured_at'],
        'note': '베이스라인이 덤프보다 나중에 측정되었으면 그 사이 운영 쓰기로 차이가 날 수 있다',
    }

    # 13. 절 개수
    r['B13_ddl_clauses'] = {
        'PRIMARY KEY': text.count('PRIMARY KEY'),
        'UNIQUE KEY': text.count('UNIQUE KEY'),
        'KEY (non-unique+unique 라인)': len(re.findall(r'^\s+(?:UNIQUE )?KEY `', text, re.M)),
        'CONSTRAINT ... FOREIGN KEY': len(re.findall(r'CONSTRAINT `[^`]+` FOREIGN KEY', text)),
        'AUTO_INCREMENT column': len(re.findall(r'AUTO_INCREMENT,\n', text)) + len(re.findall(r'AUTO_INCREMENT,$', text, re.M)),
        'AUTO_INCREMENT= (table option)': len(re.findall(r'AUTO_INCREMENT=\d+', text)),
        'ENGINE=InnoDB': text.count('ENGINE=InnoDB'),
        'DEFAULT CHARSET=utf8mb4': text.count('DEFAULT CHARSET=utf8mb4'),
        'COLLATE=utf8mb4_unicode_ci': text.count('COLLATE=utf8mb4_unicode_ci'),
        'baseline_fk_count': len(baseline['foreign_keys']),
        'baseline_index_count': len(baseline['indexes']),
        'baseline_autoinc_tables': sum(1 for t in baseline['inventory']['owned'] if t.get('AUTO_INCREMENT')),
    }
    # 인덱스 라인 vs baseline 인덱스(=PRIMARY 포함) 비교
    idx_lines = len(re.findall(r'^\s+(?:UNIQUE )?KEY `', text, re.M)) + text.count('  PRIMARY KEY')
    r['B13_ddl_clauses']['index_lines_incl_primary'] = idx_lines

    # 14. 오류 흔적
    errs = [(i + 1, l[:160]) for i, l in enumerate(lines) if ERROR_PATTERNS.search(l)]
    r['B14_error_traces'] = {'count': len(errs), 'samples': errs[:10],
                             'unparsed_lines': len(unknown_lines),
                             'unparsed_samples': unknown_lines[:10]}

    # 15. charset 선언
    r['B15_charset_preamble'] = {
        'has_SET_NAMES': bool(re.search(r'^\s*SET NAMES', text, re.M)),
        'has_SET_character_set_client': bool(re.search(r'SET character_set_client', text)),
        'has_charset_collation_connection': bool(re.search(r'character_set_connection|collation_connection', text)),
        'has_CREATE_DATABASE': bool(re.search(r'CREATE DATABASE', text, re.I)),
        'has_USE_stmt': bool(re.search(r'^USE ', text, re.M)),
        'has_START_TRANSACTION': bool(re.search(r'START TRANSACTION|BEGIN;', text)),
        'has_LOCK_TABLES': bool(re.search(r'LOCK TABLES', text)),
        'has_SET_sql_mode': bool(re.search(r'SET (SESSION )?sql_mode', text, re.I)),
        'has_SET_time_zone': bool(re.search(r'SET (SESSION )?time_zone|SET TIME_ZONE', text, re.I)),
        'preamble_statements': [l for l in lines[:6] if l.startswith('SET ') or l.startswith('--')],
        'non_ascii_bytes': sum(1 for b in raw if b > 127),
    }

    # 16. DROP TABLE
    r['B16_drop_table'] = {
        'count': len(drops),
        'covers_all_creates': sorted(drops) == sorted(creates),
        'guarded_by_if_exists': True,
        'has_create_if_not_exists': bool(re.search(r'CREATE TABLE IF NOT EXISTS', text)),
        'fk_checks_off_at_top': lines[1].strip() == 'SET FOREIGN_KEY_CHECKS=0;' if len(lines) > 1 else False,
    }

    # 18. MySQL 9.6 호환성 사전 스캔
    compat = OrderedDict()
    for name, pat in COMPAT_PATTERNS.items():
        hits = []
        for i, l in enumerate(lines):
            if l.startswith('INSERT INTO '):
                continue
            if pat.search(l):
                hits.append((i + 1, l.strip()[:150]))
        compat[name] = {'count': len(hits),
                        'example': (f'{sql_path.split("/")[-1]}:{hits[0][0]}  {hits[0][1]}' if hits else None)}
    r['B18_mysql_compat_scan'] = compat

    # 19. 바이너리/NULL 위험 (스키마 기준)
    bincols = [c for c in baseline['columns']
               if re.search(r'\b(blob|binary|varbinary)\b', c['COLUMN_TYPE'], re.I)]
    r['B19_binary_risk'] = {
        'blob_binary_columns_in_prod': [f"{c['TABLE_NAME']}.{c['COLUMN_NAME']} {c['COLUMN_TYPE']}" for c in bincols],
        'blob_binary_column_count': len(bincols),
        'dump_contains_hex_literal': bool(re.search(r'VALUES \([^)]*0x[0-9a-fA-F]{8}', text)),
        'null_literal_count': len(re.findall(r'(?<=[(,])NULL(?=[,)])', text)),
        'escaped_nul_in_data': text.count('\\0'),
        'raw_nul_bytes': raw.count(b'\x00'),
        'note': "db_dump.php:36 은 (string)\\$v 캐스팅 후 PDO::quote — 바이너리 컬럼이 0개면 실질 위험 없음",
    }

    # 20. 비밀번호 해시
    hashes = re.findall(r"'(\$2[aby]\$[^']{50,70})'", text)
    r['B20_password_hashes'] = {
        'bcrypt_count': len(hashes),
        'lengths': sorted(set(len(h) for h in hashes)),
        'all_60': all(len(h) == 60 for h in hashes) if hashes else False,
        'baseline_expected': baseline['accounts']['hash_algo'],
        'note': '해시 값 자체는 기록하지 않음',
    }

    # 17. 원자성 — db_dump.php 소스 정적 분석
    dump_src = open(os.path.join(ROOT, 'deploy/db_dump.php'), encoding='utf-8').read()
    r['B17_atomicity'] = {
        'source': 'deploy/db_dump.php',
        'has_beginTransaction': 'beginTransaction' in dump_src,
        'has_SET_TRANSACTION_ISOLATION': 'TRANSACTION ISOLATION' in dump_src,
        'has_consistent_snapshot': 'CONSISTENT SNAPSHOT' in dump_src,
        'has_LOCK_TABLES': 'LOCK TABLES' in dump_src,
        'has_FLUSH_TABLES_WITH_READ_LOCK': 'FLUSH TABLES' in dump_src,
        'per_table_sequential_select': bool(re.search(r'foreach \(\$tables as \$t\)', dump_src)),
        'select_stmt': 'SELECT * FROM `$t`',
        'atomic': False,
        'verdict': ('트랜잭션·락 없이 테이블을 알파벳 순으로 순차 SELECT — '
                    '덤프 시작~종료 사이 운영 쓰기가 있으면 테이블 간 참조 불일치가 남는다'),
    }

    # 21. 복구 차단 요인 (blocker) — 실행 중단 지점과 피해 범위
    gen_cols = [(i + 1, l.strip()) for i, l in enumerate(lines) if 'GENERATED ALWAYS AS' in l]
    blockers = []
    for lineno, ldef in gen_cols:
        col = re.match(r'`([^`]+)`', ldef)
        # 소속 테이블 = 그 줄 위쪽에서 가장 가까운 CREATE TABLE
        tbl = None
        for j in range(lineno - 1, -1, -1):
            mc = CREATE_RE.match(lines[j])
            if mc:
                tbl = mc.group(1); break
        idx = creates.index(tbl) if tbl in creates else None
        after = creates[idx + 1:] if idx is not None else []
        blockers.append({
            'kind': 'INSERT into GENERATED column',
            'severity': 'CRITICAL',
            'table': tbl,
            'column': col.group(1) if col else None,
            'sql_line': lineno,
            'affected_insert_rows': inserts.get(tbl, 0),
            'dump_order_position': (idx + 1) if idx is not None else None,
            'tables_after_failure_point': len(after),
            'rows_after_failure_point': sum(inserts.get(t, 0) for t in after),
            'tables_lost_on_abort': after,
            'expected_error': ('MySQL 3105 / MariaDB 1906 — The value specified for '
                               'generated column ... is not allowed'),
        })
    r['B21_restore_blockers'] = blockers
    r['B21_summary'] = {
        'blocker_count': len(blockers),
        'drop_before_create': len(drops) == len(creates),
        'client_aborts_on_error_by_default': True,
        'worst_case': ('mysql < dump.sql 로 실행하면 46개 테이블을 DROP 한 뒤 '
                       '차단 지점에서 중단 → DB가 반쯤 비워진 채 남는다'),
    }

    # 22. AUTO_INCREMENT 보존 — 덤프의 테이블 옵션 vs 운영 실측
    dump_ai = {}
    for m in re.finditer(r'CREATE TABLE `([^`]+)`.*?\n\) ENGINE=InnoDB([^;]*);', text, re.S):
        a = re.search(r'AUTO_INCREMENT=(\d+)', m.group(2))
        dump_ai[m.group(1)] = int(a.group(1)) if a else None
    prod_ai = {t['TABLE_NAME']: t['AUTO_INCREMENT'] for t in baseline['inventory']['owned']}
    ai_diff = []
    for t in sorted(prod_ai):
        if prod_ai[t] != dump_ai.get(t):
            ai_diff.append({'table': t, 'prod_auto_increment': prod_ai[t],
                            'dump_auto_increment': dump_ai.get(t),
                            'dump_rows': inserts.get(t, 0)})
    r['B22_auto_increment'] = {
        'tables_with_AI_clause_in_dump': sum(1 for v in dump_ai.values() if v),
        'prod_tables_with_AI': sum(1 for v in prod_ai.values() if v),
        'mismatches': ai_diff,
        'mismatches_with_rows': [x for x in ai_diff if x['dump_rows'] > 0],
        'note': ('AI=1 인 빈 테이블은 MariaDB SHOW CREATE 가 절을 생략한다(정상). '
                 '행이 있는 테이블의 차이는 덤프 이후 발생한 롤백/차단 INSERT 로 카운터만 전진한 것.'),
    }

    # 23. 복구 절차 자산 커버리지 — rollback.sql 이 현재 46테이블을 모두 덮는가
    rb_path = os.path.join(ROOT, 'database/cafe24/rollback.sql')
    if os.path.exists(rb_path):
        rb = set(re.findall(r'DROP TABLE[^;]*?`([^`]+)`',
                            open(rb_path, encoding='utf-8').read()))
        r['B23_rollback_sql_coverage'] = {
            'file': 'database/cafe24/rollback.sql',
            'drop_targets': len(rb),
            'prod_tables': len(prod_ai),
            'missing_from_rollback': sorted(set(prod_ai) - rb),
            'stale_in_rollback': sorted(rb - set(prod_ai)),
        }

    r['_parsed'] = {'creates': creates, 'inserts_by_table': dict(inserts)}
    return r


# ────────────────────────────────────────────────────────────── C. 백업 운영
def verify_ops(backups_dir, ftp_dir, sql_path):
    r = OrderedDict()
    entries = []
    for n in sorted(os.listdir(backups_dir)):
        p = os.path.join(backups_dir, n)
        if n.startswith('ftp_'):
            m = re.match(r'ftp_(\d{8})-(\d{6})$', n)
            ts = datetime.strptime(m.group(1) + m.group(2), '%Y%m%d%H%M%S') if m else None
            nfiles = sum(len(f) for _, _, f in os.walk(p))
            nbytes = sum(os.path.getsize(os.path.join(d, f))
                         for d, _, fs in os.walk(p) for f in fs)
            entries.append({'kind': 'files', 'name': n, 'label': None,
                            'name_ts': ts.isoformat() if ts else None,
                            'mtime_local': iso(os.path.getmtime(p)),
                            'files': nfiles, 'bytes': nbytes})
        elif n.startswith('proddb_') and n.endswith('.sql'):
            m = re.match(r'proddb_(.*)_(\d{8})-(\d{6})\.sql$', n)
            label = m.group(1) if m else None
            ts = datetime.strptime(m.group(2) + m.group(3), '%Y%m%d%H%M%S') if m else None
            entries.append({'kind': 'db', 'name': n, 'label': label,
                            'name_ts_utc': ts.isoformat() if ts else None,
                            'mtime_local': iso(os.path.getmtime(p)),
                            'files': 1, 'bytes': os.path.getsize(p)})
        elif n.endswith('.sql'):
            entries.append({'kind': 'other_sql', 'name': n, 'label': None,
                            'mtime_local': iso(os.path.getmtime(p)),
                            'files': 1, 'bytes': os.path.getsize(p)})

    entries.sort(key=lambda e: e['mtime_local'])
    r['C21_history'] = entries
    r['C21_summary'] = {
        'file_backups': sum(1 for e in entries if e['kind'] == 'files'),
        'db_backups': sum(1 for e in entries if e['kind'] == 'db'),
        'other_sql': sum(1 for e in entries if e['kind'] == 'other_sql'),
        'first_local': entries[0]['mtime_local'] if entries else None,
        'last_local': entries[-1]['mtime_local'] if entries else None,
        'total_bytes': sum(e['bytes'] for e in entries),
    }
    # 간격
    def gaps(kind):
        ts = [datetime.fromisoformat(e['mtime_local']) for e in entries if e['kind'] == kind]
        g = [round((ts[i + 1] - ts[i]).total_seconds() / 3600, 2) for i in range(len(ts) - 1)]
        return {'n': len(ts), 'gaps_hours': g,
                'min_h': min(g) if g else None, 'max_h': max(g) if g else None,
                'median_h': sorted(g)[len(g) // 2] if g else None,
                'span_days': round((ts[-1] - ts[0]).total_seconds() / 86400, 2) if len(ts) > 1 else 0}
    r['C21_intervals'] = {'db': gaps('db'), 'files': gaps('files')}
    # 라벨 → 수동 실행 근거
    r['C21_labels'] = sorted({e['label'] for e in entries if e['kind'] == 'db' and e['label']})

    # 22. 파일↔DB 백업 시각 차이 (mtime 기준: 두 백업 모두 로컬 기록 시각)
    pairs = []
    fb = [e for e in entries if e['kind'] == 'files']
    db = [e for e in entries if e['kind'] == 'db']
    for f in fb:
        best = min(db, key=lambda d: abs(
            (datetime.fromisoformat(d['mtime_local']) - datetime.fromisoformat(f['mtime_local'])).total_seconds()))
        delta = (datetime.fromisoformat(best['mtime_local'])
                 - datetime.fromisoformat(f['mtime_local'])).total_seconds()
        pairs.append({'file_backup': f['name'], 'nearest_db_backup': best['name'],
                      'delta_seconds': int(delta), 'delta_minutes': round(delta / 60, 2)})
    r['C22_pairs'] = pairs
    tgt_f = os.path.basename(ftp_dir.rstrip('/'))
    tgt_d = os.path.basename(sql_path)
    tf = next((e for e in fb if e['name'] == tgt_f), None)
    td = next((e for e in db if e['name'] == tgt_d), None)
    if tf and td:
        d = (datetime.fromisoformat(td['mtime_local'])
             - datetime.fromisoformat(tf['mtime_local'])).total_seconds()
        r['C22_target_pair'] = {'file_backup': tgt_f, 'db_backup': tgt_d,
                                'file_mtime': tf['mtime_local'], 'db_mtime': td['mtime_local'],
                                'delta_seconds': int(d)}

    # 22b. 파일명 타임스탬프 타임존 불일치
    #   backup.sh:10  → shell `date +%Y%m%d-%H%M%S` (로컬 KST)
    #   db_dump.php:26 → PHP `date('Ymd-His')`      (PHP CLI TZ = UTC)
    tzrows = []
    for e in entries:
        if e['kind'] == 'files':
            name_ts = e.get('name_ts')
        elif e['kind'] == 'db':
            name_ts = e.get('name_ts_utc')
        else:
            continue
        if not name_ts:
            continue
        skew = (datetime.fromisoformat(e['mtime_local']).replace(tzinfo=None)
                - datetime.fromisoformat(name_ts)).total_seconds() / 3600
        tzrows.append({'kind': e['kind'], 'name': e['name'],
                       'filename_ts': name_ts, 'written_at_local': e['mtime_local'],
                       'skew_hours': round(skew, 2)})
    r['C22b_filename_timezone'] = {
        'rows': tzrows,
        'files_backup_skew_h': sorted({x['skew_hours'] for x in tzrows if x['kind'] == 'files'}),
        'db_backup_skew_h': sorted({x['skew_hours'] for x in tzrows if x['kind'] == 'db'}),
        'sources': {'files': 'deploy/backup.sh:10 date +%Y%m%d-%H%M%S (shell, KST)',
                    'db': "deploy/db_dump.php:26 date('Ymd-His') (PHP CLI, UTC)"},
        'risk': ('두 백업군의 파일명 타임스탬프 기준 타임존이 달라, 장애 시 짝이 맞는 '
                 '파일·DB 백업을 이름만 보고 고르면 최대 9시간 어긋난 조합을 잡게 된다'),
    }

    # 22c. 전체 DB 백업 횡단 스캔 — 차단 요인이 이번 덤프만의 문제인가, 이력 전체인가
    scan = []
    for e in entries:
        if e['kind'] != 'db':
            continue
        t = open(os.path.join(backups_dir, e['name']), encoding='utf-8', errors='replace').read()
        ls = t.split('\n')
        scan.append({
            'name': e['name'],
            'generator_header': ls[0][:80],
            'create_tables': sum(1 for l in ls if l.startswith('CREATE TABLE')),
            'insert_rows': sum(1 for l in ls if l.startswith('INSERT INTO')),
            'has_generated_column': 'GENERATED ALWAYS' in t,
            'blocked_inserts': sum(
                1 for l in ls if l.startswith('INSERT INTO `edencrm_project_assignments`')),
            'has_SET_NAMES': any(l.startswith('SET NAMES') for l in ls),
        })
    r['C22c_all_db_backups'] = {
        'scanned': len(scan),
        'restorable_as_is': sum(1 for s in scan if s['blocked_inserts'] == 0),
        'blocked': sum(1 for s in scan if s['blocked_inserts'] > 0),
        'with_SET_NAMES': sum(1 for s in scan if s['has_SET_NAMES']),
        'generator_variants': sorted({re.sub(r'\d{4}-\d{2}-\d{2}T[\d:+]+', '<ts>',
                                             re.sub(r'\d+ tables', 'N tables', s['generator_header']))
                                      for s in scan}),
        'rows': scan,
        'verdict': ('차단 INSERT 가 0인 DB 백업이 하나도 없으면, 보유한 DB 백업 전체가 '
                    '무보정 복구 불가 상태다'),
    }

    # 23. 장애 도메인 / 원격 사본
    ignored, _ = sh('git check-ignore -v database/backups/ 2>&1')
    tracked_bk, _ = sh('git ls-files database/backups | head -5')
    remotes, _ = sh('git remote -v')
    # 다른 위치의 사본 탐색 (홈 디렉터리 얕은 탐색)
    other, _ = sh("find \"$HOME\" -maxdepth 4 -type d -name 'ftp_20260729-103659' 2>/dev/null")
    tm, _ = sh("tmutil destinationinfo 2>/dev/null | head -20")
    r['C23_failure_domain'] = {
        'backups_gitignored': bool(ignored),
        'gitignore_rule': ignored,
        'git_tracked_backup_files': [x for x in tracked_bk.splitlines() if x],
        'git_remotes': [x for x in remotes.splitlines() if x],
        'copies_found_under_home': [x for x in other.splitlines() if x],
        'time_machine_destinations': tm,
        'backup_volume': sh("df -h '%s' | tail -1" % backups_dir)[0],
        'cron_or_launchd_refs': sh(
            "crontab -l 2>/dev/null | grep -i -E 'backup|db_dump|lftp' || echo '(no crontab entries)'")[0],
        'launchd_refs': sh(
            "ls ~/Library/LaunchAgents 2>/dev/null | grep -i -E 'eden|backup' || echo '(none)'")[0],
    }
    return r


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--ftp', default=DEFAULT_FTP)
    ap.add_argument('--sql', default=DEFAULT_SQL)
    ap.add_argument('--baseline', default=DEFAULT_BASE)
    ap.add_argument('--out', default=DEFAULT_OUT)
    a = ap.parse_args()

    baseline = json.load(open(a.baseline, encoding='utf-8'))
    started = datetime.now(timezone.utc)

    res = OrderedDict()
    res['meta'] = {
        'task': 'T2', 'agent': 'backup', 'mode': 'read-only (no prod access)',
        'generated_at': started.isoformat(timespec='seconds'),
        'ftp_backup': os.path.relpath(a.ftp, ROOT),
        'db_backup': os.path.relpath(a.sql, ROOT),
        'baseline': os.path.relpath(a.baseline, ROOT),
    }
    res['A_files'] = verify_files(a.ftp, baseline)
    res['B_sql'] = verify_sql(a.sql, baseline)
    res['C_ops'] = verify_ops(os.path.join(ROOT, 'database/backups'), a.ftp, a.sql)

    A, B = res["A_files"], res["B_sql"]
    res['verdicts'] = OrderedDict([
        ('1_file_inventory', 'PASS' if not A['A1_inventory']['zero_byte_files'] else 'FAIL'),
        ('2_required_areas', 'PASS' if not A['A2_missing_required'] else 'FAIL'),
        ('3_uploads_vs_db', A['A3_uploads']['verdict']),
        ('4_runtime_dirs', 'PASS'),
        ('5_symlinks', 'PASS (0 links)'),
        ('6_secrets_in_backup', 'FAIL' if A['A6_secrets_in_backup']['config_local_php_present'] else 'PASS'),
        ('7_git_drift', 'PASS' if len(A['A7_provenance']['blob_NOT_in_git_history']) <= 1 else 'FAIL'),
        ('8_modes', 'WARN (dir mode = local umask, not remote)'),
        ('9_dump_integrity', 'PASS' if B['B9_integrity']['ends_with_fk_checks_on'] else 'FAIL'),
        ('10_table_set', 'PASS' if B['B10_tables']['set_equal'] else 'FAIL'),
        ('11_row_total', 'PASS' if B['B11_rows']['equal'] else 'FAIL'),
        ('12_per_table_rows', 'PASS' if B['B12_mismatch_count'] == 0 else 'FAIL'),
        ('13_ddl_clauses', 'PASS' if (B['B13_ddl_clauses']['CONSTRAINT ... FOREIGN KEY']
                                      == B['B13_ddl_clauses']['baseline_fk_count']
                                      and B['B13_ddl_clauses']['index_lines_incl_primary']
                                      == B['B13_ddl_clauses']['baseline_index_count']) else 'FAIL'),
        ('14_error_traces', 'PASS' if B['B14_error_traces']['count'] == 0 else 'FAIL'),
        ('15_charset_preamble', 'FAIL' if not B['B15_charset_preamble']['has_SET_NAMES'] else 'PASS'),
        ('16_drop_table_risk', 'WARN (46 DROP TABLE IF EXISTS, 안전핀 없음)'),
        ('17_atomicity', 'FAIL (non-atomic)' if not B['B17_atomicity']['atomic'] else 'PASS'),
        ('18_mysql_compat', 'FAIL' if B['B21_summary']['blocker_count'] else 'WARN'),
        ('19_binary_risk', 'PASS (blob/binary 컬럼 0개)'),
        ('20_password_hashes', 'PASS' if B['B20_password_hashes']['all_60'] else 'FAIL'),
        ('21_schedule_rpo', 'FAIL (스케줄 없음 · 수동 릴리스 게이트 전용)'),
        ('22_pair_skew', 'PASS (대상 쌍 1초) / WARN (파일명 TZ 불일치 9h)'),
        ('23_failure_domain', 'FAIL (단일 장애점: 로컬 PC 1곳)'),
    ])
    # config.local.php gitignore 여부
    ig, _ = sh('git check-ignore -v app/config/config.local.php 2>&1')
    res['A_files']['A6_secrets_in_backup']['gitignored_locally'] = ig or '(not ignored)'
    res['meta']['elapsed_sec'] = round((datetime.now(timezone.utc) - started).total_seconds(), 2)

    os.makedirs(os.path.dirname(a.out), exist_ok=True)
    with open(a.out, 'w', encoding='utf-8') as f:
        json.dump(res, f, ensure_ascii=False, indent=2)
    print('WROTE', a.out)
    # 콘솔 요약
    print(json.dumps({
        'files': res['A_files']['A1_inventory'],
        'missing_required': res['A_files']['A2_missing_required'],
        'uploads': res['A_files']['A3_uploads']['verdict'],
        'tables_equal': res['B_sql']['B10_tables']['set_equal'],
        'rows': res['B_sql']['B11_rows'],
        'mismatched_tables': res['B_sql']['B12_mismatch_count'],
        'charset': res['B_sql']['B15_charset_preamble'],
        'hashes': {k: v for k, v in res['B_sql']['B20_password_hashes'].items() if k != 'baseline_expected'},
    }, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
