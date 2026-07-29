#!/usr/bin/env python3
"""
저장소 공개 전 비밀값·개인정보 마스킹.

왜 필요한가:
  2026-07-29 git 원격 연결을 준비하며 히스토리를 스캔한 결과, 트래킹 파일 16개와
  커밋 9개에 운영 자격증명이 남아 있었다. 특히 docs/audit/SECURITY_AUDIT.md 는
  "비밀번호가 평문으로 노출돼 있다"고 지적하는 문장 안에 실제 비밀번호를 적어 두었다.

무엇을 가리는가:
  - FTP/DB 비밀번호            → <FTP_PASSWORD> / <DB_PASSWORD>
  - 계정명(카페24는 DB계정=FTP계정=DB명이 동일) → <DB_ACCOUNT>
  - 호스트·도메인·서비스 URL   → <FTP_HOST> / <SERVICE_URL>
  - 고객 실명                  → 고객A, 고객B …

무엇을 남기는가:
  건수·금액·계약번호 같은 업무 지표는 남긴다. 이걸 지우면 감사 보고서와 절차서가
  근거를 잃어 문서로서 쓸모가 없어진다. 식별정보만 제거하는 게 목적이다.

치환 순서가 중요하다: 긴 문자열부터 처리해야 도메인(<FTP_HOST>)이
계정명(<DB_ACCOUNT>) 치환에 먼저 걸려 '<DB_ACCOUNT>.co.kr' 같은 잔재가 생기지 않는다.

사용:
  python3 scripts/dr/scrub_secrets.py --check    # 검사만(변경 없음)
  python3 scripts/dr/scrub_secrets.py --apply    # 실제 치환
  python3 scripts/dr/scrub_secrets.py --rules    # filter-repo 용 규칙 파일 출력
"""
import argparse
import os
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ENV_FILE = os.path.join(ROOT, 'deploy', 'cafe24.env')

# 고객 실명 → 익명 라벨. 감사 문서의 한글 왕복 검증 시료로 쓰이므로
# 한글이면서 길이가 다양한 값으로 바꿔 charset 검증의 의미를 보존한다.
CUSTOMER_NAMES = {
    '고객다': '고객다',
    '고객라마바': '고객라마바',
    '고객사아자': '고객사아자',
    '고객나': '고객나',
}


def load_env():
    env = {}
    if not os.path.exists(ENV_FILE):
        sys.exit(f'환경파일 없음: {ENV_FILE}')
    for line in open(ENV_FILE, encoding='utf-8'):
        line = line.strip()
        if '=' in line and not line.startswith('#'):
            k, v = line.split('=', 1)
            env[k.strip()] = v.strip()
    return env


def build_rules(env):
    """(원문, 치환문) 목록. 긴 것부터 정렬해 부분 치환 사고를 막는다."""
    raw = []
    for key, token in [
        ('SERVICE_URL', '<SERVICE_URL>'),
        ('CAFE24_DOMAIN', '<FTP_HOST>'),
        ('FTP_HOST', '<FTP_HOST>'),
        ('DB_HOST', '<DB_HOST>'),
        ('FTP_PASSWORD', '<FTP_PASSWORD>'),
        ('DB_PASSWORD', '<DB_PASSWORD>'),
        ('FTP_USER', '<DB_ACCOUNT>'),
        ('DB_USER', '<DB_ACCOUNT>'),
        ('DB_NAME', '<DB_ACCOUNT>'),
    ]:
        v = env.get(key, '').strip()
        # 너무 짧은 값은 오탐이 크다(예: 포트). 4자 미만은 건너뛴다.
        if v and len(v) >= 4:
            raw.append((v, token))
    raw += list(CUSTOMER_NAMES.items())

    # 중복 제거 후 길이 내림차순 — <FTP_HOST> 이 <DB_ACCOUNT> 보다 먼저 처리돼야 한다.
    seen, rules = set(), []
    for src, dst in sorted(raw, key=lambda x: -len(x[0])):
        if src not in seen:
            seen.add(src)
            rules.append((src, dst))
    return rules


def tracked_files():
    out = subprocess.run(['git', 'ls-files'], cwd=ROOT,
                         capture_output=True, text=True).stdout.split()
    return [f for f in out if os.path.exists(os.path.join(ROOT, f))]


def scan(rules, apply_changes):
    hits, changed = {}, 0
    for rel in tracked_files():
        path = os.path.join(ROOT, rel)
        try:
            with open(path, encoding='utf-8') as fh:
                text = fh.read()
        except (UnicodeDecodeError, IsADirectoryError):
            continue          # 바이너리·디렉터리는 대상 아님

        new = text
        found = []
        for src, dst in rules:
            if src in new:
                found.append(dst)
                new = new.replace(src, dst)
        if not found:
            continue
        hits[rel] = sorted(set(found))
        if apply_changes and new != text:
            with open(path, 'w', encoding='utf-8') as fh:
                fh.write(new)
            changed += 1
    return hits, changed


def main():
    ap = argparse.ArgumentParser()
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument('--check', action='store_true')
    g.add_argument('--apply', action='store_true')
    g.add_argument('--rules', action='store_true')
    args = ap.parse_args()

    env = load_env()
    rules = build_rules(env)

    if args.rules:
        # git-filter-repo --replace-text 형식: literal==>replacement
        for src, dst in rules:
            print(f'{src}==>{dst}')
        return

    hits, changed = scan(rules, args.apply)
    label = '치환됨' if args.apply else '검출됨'
    print(f'═══ 대상 파일 {len(hits)}개 ({label}) ═══')
    for rel, tokens in sorted(hits.items()):
        print(f'  {rel}')
        print(f'     → {", ".join(tokens)}')
    if args.apply:
        print(f'\n  {changed}개 파일 수정')
    if args.check and hits:
        sys.exit(1)
    if args.check:
        print('  ✅ 잔존 없음')


if __name__ == '__main__':
    main()
