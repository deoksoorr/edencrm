# Git 원격 저장소 연결 절차

**작성**: 2026-07-29 · 히스토리 세탁 완료 후

---

## 현재 상태 (푸시 준비 완료)

| 항목 | 값 |
|---|---|
| 브랜치 | `main` (138커밋, 2026-07-22 ~ 07-29) |
| 원격 | **없음** — 아직 한 번도 푸시된 적 없음 |
| 용량 | 3.69 MiB (328 파일) |
| 히스토리 자격증명 | **0건** (세탁 완료·검증 완료) |

---

## ⚠️ 반드시 비공개(private) 저장소로 만들 것

공개하면 안 되는 이유:

- 감사 산출물에 **계약 금액·건수·업무 지표**가 들어 있다 (`docs/audit/`)
- 운영 구조가 상세히 문서화돼 있다 (`docs/disaster-recovery.md`, `docs/ARCHITECTURE.md`)
- 자격증명은 마스킹했지만, **호스팅 구성·경로·테이블 prefix** 등 공격 표면이 될 정보가 남아 있다

---

## 1. 저장소 생성 (웹)

github.com → New repository

- 이름: 예 `eden-crm`
- **Visibility: Private** ← 반드시
- **README·.gitignore·license 는 추가하지 말 것** (빈 저장소여야 한다. 파일을 넣으면
  첫 푸시에서 히스토리가 충돌한다)

## 2. 연결 + 푸시

```bash
cd "/Users/deoksookim/Desktop/코드/claude code/eden_crm"

git remote add origin <저장소 URL>
git push -u origin main
```

`https://github.com/...` 형식이면 첫 푸시에서 인증을 묻는다. 비밀번호 대신
**Personal Access Token**(GitHub → Settings → Developer settings → Personal access
tokens → repo 권한)을 입력한다.

SSH(`git@github.com:...`)를 쓰려면 키를 먼저 만든다:

```bash
ssh-keygen -t ed25519 -C "<본인 이메일>"
cat ~/.ssh/id_ed25519.pub    # 출력을 GitHub → Settings → SSH and GPG keys 에 등록
```

## 3. 푸시 후 확인

```bash
git remote -v
git log --oneline origin/main | head -3
```

---

## 커밋 저자 정보 (선택)

현재 커밋 저자는 `Deoksoo Kim <deoksookim@Deoksoo-M4.local>` 이다. 이 이메일은
실제 주소가 아니라 맥 호스트명에서 자동 생성된 값이라, GitHub 이 커밋을 계정과
연결하지 못한다(잔디에 안 찍힘). 기록 자체는 정상이므로 그대로 두어도 무방하다.

앞으로의 커밋만 계정에 연결하려면:

```bash
git config user.name "이름"
git config user.email "GitHub 계정 이메일"
```

과거 138커밋까지 소급 적용하려면 `git filter-repo --mailmap` 이 필요하다.
이미 푸시한 뒤에는 강제 푸시가 되므로, **하려면 첫 푸시 전이 좋다.**

---

## 푸시하면 안 되는 것 (이미 .gitignore 처리됨)

| 파일 | 내용 |
|---|---|
| `deploy/cafe24.env` | 운영 FTP·DB 접속정보 |
| `deploy/config.production.php` | 운영 DB 설정 |
| `app/config/config.local.php` | 로컬 DB 설정 |
| `deploy/ADMIN_CREDENTIALS.local.txt` | 관리자 계정 |
| `deploy/pii_map.local.txt` | 고객 실명 치환 매핑 |
| `deploy/*.log` | 배포 로그 |
| `database/backups/` | 운영 백업(파일·DB) |
| `node_modules/` | 51MB 의존성 |

**새 파일을 커밋하기 전에 항상 검사한다:**

```bash
python3 scripts/dr/scrub_secrets.py --check
```

`✅ 잔존 없음` 이 나와야 안전하다. 무언가 걸리면 커밋하지 말고 먼저 마스킹한다.

---

## 히스토리 세탁 이력 (참고)

2026-07-29 첫 푸시 준비 중 히스토리 스캔에서 발견된 것:

- **FTP·DB 비밀번호**가 `docs/audit/SECURITY_AUDIT.md` 에 평문으로 존재
  (아이러니하게도 "비밀번호가 평문 노출돼 있다"고 지적하는 문장 안에)
- 계정명·호스트·도메인이 트래킹 파일 19개, 커밋 9개에 산재
- 고객 실명 4명이 감사 JSON 과 진행 문서에 존재

`git filter-repo --replace-text` 로 전체 히스토리를 치환했다. 커밋 해시는 전부
바뀌었지만 **메시지·날짜·순서·저자는 그대로**이며 138커밋이 온전히 보존됐다.

원격에 푸시된 적이 없었기 때문에 **외부 노출은 0**이다. 세탁 전 `.git` 은
`eden_crm_git_backup_20260729-142509.tar.gz` 로 보관돼 있다(문제 없으면 삭제해도 된다).

### 다시 세탁해야 할 때

```bash
python3 scripts/dr/scrub_secrets.py --rules > /tmp/rules.txt
git filter-repo --replace-text /tmp/rules.txt --force
```

**주의**: 이미 푸시한 뒤에 세탁하면 강제 푸시가 필요하고, 저장소를 클론한 사람은
전원 다시 클론해야 한다. 그리고 **이미 올라간 비밀값은 회수할 수 없으므로
반드시 해당 자격증명을 교체해야 한다.** 세탁은 첫 푸시 전에 끝내는 게 최선이다.
