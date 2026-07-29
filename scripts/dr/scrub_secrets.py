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

# 고객 실명 → 익명 라벨.
#
# 실명을 이 파일에 적지 않는다. 2026-07-29 첫 판에서는 딕셔너리에 실명을 그대로
# 넣었는데, 히스토리 세탁을 돌리자 이 파일 자신도 치환 대상이 되어 매핑이
# '고객다'→'고객다' 같은 항등식으로 무너졌다. 개인정보를 담은 스크립트로
# 개인정보를 지우려 한 자기모순이었다.
#
# 그래서 매핑은 git 에 올라가지 않는 로컬 파일에서 읽는다. 파일이 없으면
# 고객명 치환은 건너뛰고 자격증명 마스킹만 수행한다(그쪽이 더 중요하다).
# 형식: 한 줄에 `실명=치환명`
CUSTOMER_MAP_FILE = os.path.join(ROOT, 'deploy', 'pii_map.local.txt')


def load_customer_map():
    if not os.path.exists(CUSTOMER_MAP_FILE):
        return {}
    out = {}
    for line in open(CUSTOMER_MAP_FILE, encoding='utf-8'):
        line = line.strip()
        if line and not line.startswith('#') and '=' in line:
            src, dst = line.split('=', 1)
            if src.strip():
                out[src.strip()] = dst.strip()
    return out


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
    raw += list(load_customer_map().items())

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
