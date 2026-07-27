# 롤백 가이드 (R6 운영 · 카페24 <DB_ACCOUNT>)

운영 배포(카페24 `/www/eden-crm`)가 실패했을 때 **안전하게 되돌리는 절차**를 정리한 문서입니다.
배포 절차는 [DEPLOYMENT_REPORT.md](DEPLOYMENT_REPORT.md), 스키마·prefix 는 [MIGRATION_REPORT.md](MIGRATION_REPORT.md) 참조.

> **롤백은 파괴적 작업입니다. 실행은 코디네이터(T12) 전용이며, 검수 에이전트는 운영에 접속하지 않습니다.**
> **비밀값(DB/FTP 비밀번호)은 이 문서에 기록하지 않습니다** — 운영 접속값은 git 제외 `deploy/cafe24.env` 에만 존재합니다.

---

## 1. 롤백 판단 — 실패 트리거

아래 중 하나라도 확인되면 롤백을 검토합니다(경미한 것은 핫픽스 우선, 아래는 롤백 신호):

| 트리거 | 예 |
|---|---|
| **마이그레이션 실패** | `001_schema.sql`/`002_core.sql` 적재 중 오류, `SHOW TABLES LIKE 'edencrm\_%'` 39개 미달, MariaDB 문법 비호환 |
| **서비스 미기동** | 로그인 페이지 500/백지, 치명 오류(Fatal/Exception) 노출, DB 연결 실패 |
| **보안 노출** | 내부 경로(`app/`·`storage/`·`database/`) 소스 노출, `config.local.php` 소스 노출(DB_PASS 노출), 업로드 PHP 실행 |
| **데이터 정합 붕괴** | 회계 화면=DB 대사 불일치, 근태/기여 항등 붕괴, 화면 대량 오류 |
| **타 프로젝트 간섭** | 타 prefix(`gnuland_`/`land_`/`landlanding_`/`opening_`) 테이블·파일 영향 관측 |
| **.htaccess 무력** | 운영 Apache 에서 내부 경로 403 미적용, 리라이트 실패 |

---

## 2. 롤백 순서 (파일 복원 → DB 롤백/복원 → 정상화 확인)

> 롤백 전 **반드시 현재 상태 백업**을 먼저 확보합니다(원인 분석·재시도 대비). 백업 없이는 진행하지 않습니다.

### 2-0. 사전 백업 (필수)

```bash
./deploy/backup.sh          # 원격 파일 미러 → database/backups/ftp_<타임스탬프>/ (웹루트 밖)
```
- DB 는 phpMyAdmin 에서 **`edencrm_%` 테이블만** export(타 prefix 미포함 확인). rollback.sql 실행 전 필수.

### 2-1. 파일 복원

- **직전 정상본이 있으면** 그 백업(`database/backups/ftp_<이전>/`)을 `deploy/deploy.sh`(CONFIRM=yes)로 재업로드하거나, 원격 배포 디렉토리를 제거 후 재배포.
- **원격 배포 디렉토리 제거** — `deploy/rollback.sh`:
  ```bash
  ./deploy/rollback.sh              # 삭제 대상 파일 목록만 출력(기본 — 안전)
  CONFIRM=yes ./deploy/rollback.sh  # 실제 삭제(/www/eden-crm 재확인 가드 통과 시에만)
  ```
  - **안전 가드**: `FTP_REMOTE_PATH` 가 정확히 `/www/eden-crm` 이 아니면 즉시 중단(오타로 타 프로젝트 삭제 방지).
  - `CONFIRM=yes` 없이는 **목록만** 출력하고 아무것도 지우지 않습니다.

### 2-2. DB 롤백 또는 복원 (택1)

- **(A) 신규 배포 실패 — 전량 제거**: `database/cafe24/rollback.sql` 로 `edencrm_` **39개 테이블만** DROP.
  - **안전핀**: 파일 상단에 강제 오류 1행(`SIGNAL_SAFETY_PIN_...`)이 있어, **직접 주석 처리해야만 실행**됩니다(실수 실행 방지).
  - 실행 전 절차: ① 백업 완료 확인 ② `SHOW TABLES LIKE 'edencrm\_%'` 결과가 rollback.sql 의 39개 목록과 일치 확인 ③ 안전핀 주석 처리.
  - phpMyAdmin 등으로 실행. **`edencrm_` 이외의 DROP 문 추가 금지**(타 prefix 무접촉).
  - 실행 후 확인: `SHOW TABLES LIKE 'edencrm\_%'` → **0 rows**.
- **(B) 기존 운영본 위 업그레이드 실패 — 이전 상태로 복원**: 2-0 에서 받은 **`edencrm_%` DB export 를 재적재**(DROP 후 import 또는 사전 백업 복원). 타 prefix 테이블은 건드리지 않습니다.

### 2-3. 정상화 확인

- `deploy/verify.sh`(읽기 전용)로 재검: 로그인 200 · 내부 경로 403/404 · config 소스 노출 0 · 오류 문자열 0.
- (B) 복원의 경우 이전 서비스가 정상 동작하는지, (A) 제거의 경우 서비스가 깨끗이 내려갔는지(또는 재배포 후 정상) 확인.
- 타 프로젝트(`gnuland_`/`land_`/`landlanding_`/`opening_`) 무영향 재확인.

---

## 3. 백업 위치 규칙 (웹루트 밖)

- 원격 파일 백업: `database/backups/ftp_<타임스탬프>/` — **웹루트(`public`) 밖**이며 `.gitignore` 로 git 제외(`database/backups/`).
- DB export: 로컬 안전 위치(웹루트·공개 경로 밖)에 보관. 운영 서버에 남기지 않음.
- 백업 파일은 비밀·개인정보를 포함할 수 있으므로 **공개 경로·git 커밋 금지**.

---

## 4. 롤백 후 후속

- 실패 원인을 배포 로그(`deploy/deploy_<타임스탬프>.log`)·마이그레이션 오류·verify 결과로 분석.
- 수정 후 [DEPLOYMENT_REPORT.md](DEPLOYMENT_REPORT.md) 절차를 처음부터 재수행(백업 → 마이그레이션 → 업로드 → Smoke → 검수).
- 롤백 발생·원인·조치를 DEPLOYMENT_REPORT '7. 잔여·후속'에 기록.

---

*근거: `database/cafe24/rollback.sql`(안전핀·39개 한정 DROP) · `deploy/rollback.sh`(CONFIRM 가드·경로 검증) · `deploy/backup.sh` · `.superpowers/sdd/r6-dbprefix-report.md` §7 · `r6-worklog.md` §[dbprefix] 12항(T12 인계 체크리스트).*
