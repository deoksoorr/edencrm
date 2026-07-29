# 전체 감사·최적화 사이클 — 작업 전 기준 상태

측정: 2026-07-29
목적: 감사·리팩토링·배포 전 상태를 고정해, 이후 변경이 무엇을 바꿨는지 대조 가능하게 한다.

---

## 1. Git

- 브랜치: `accounting-ui-audit`
- 직전 커밋: `c6b69c4 fix(r16-1): 열람 범위를 해당 리소스의 읽기 권한으로 통일 …`
- 미추적 파일: `scripts/qa_browser/node_modules/`, `qa_b_browser.js`, `qa_r15a_browser.js` (모두 로컬 QA 도구 — 배포 제외 대상)
- 작업 중 커밋: `security: 운영 로그인 페이지의 테스트 계정 안내 노출 차단` (아래 4절)

## 2. 환경

| 항목 | 로컬 | 운영 |
|---|---|---|
| PHP | 8.5.4 (CLI) | 카페24 호스팅 |
| DB | MySQL 9.6.0 | **MariaDB 10.6.17** |
| 웹서버 | php -S :8080 | Apache (카페24 공유호스팅) |
| 경로 | 작업트리 | `/www/eden-crm` |
| 서비스 | 127.0.0.1:8080 | <SERVICE_URL> |

**주의: 로컬 MySQL 9.x ↔ 운영 MariaDB 10.6 차이.** 인덱스·함수·SQL 문법 변경 시 운영 기준으로 검증해야 한다.

## 3. 코드 규모

- PHP 111개 파일 / 24,570줄
- JS 10개 파일 / 1,863줄
- CSS 1,149줄

## 4. 운영 데이터 건수 (배포 전 기준선)

| 테이블 | 건수 | | 테이블 | 건수 |
|---|---|---|---|---|
| users | 7 | | employee_permissions | 36 |
| customers | 6 | | audit_logs | 392 |
| leads | 2 | | notifications | 32 |
| quotes | 4 | | quote_versions | 4 |
| quote_items | 4 | | contracts | 5 |
| payments | 13 | | projects | 11 |
| costs | 4 | | schedules | 2 |
| work_logs | 0 | | site_bonuses | 5 |
| project_assignments | 11 | | project_stage_progress | 64 |
| customer_activities | 1 | | project_files | 2 |
| contract_status_history | 9 | | project_status_history | 51 |
| site_bonus_history | 14 | | goals | 0 |

테이블 총수 46 (`edencrm_` prefix)

소프트 삭제 대기: customers 2 · quotes 2 · contracts 1 · projects 7 · site_bonuses 5

## 5. 계정 상태

| 역할 | 인원 | 비고 |
|---|---|---|
| super_admin (사장) | 1 | admin / 김덕수 |
| sales_manager | 1 | test1 / 차윤석 |
| site_manager | 4 | test2·test3·cy123·90cyc |
| staff | 1 | hghg / 송규호 |
| accountant | 0 | 계정 없음 |

`user_permissions`(구 개별 권한) 0행 — R16 이후 `employee_permissions` 36행이 유일한 권한 원장.

## 6. 정적 분석 초기 결과

| 항목 | 결과 |
|---|---|
| 디버그 코드(var_dump/print_r/console.log) | **0건** |
| 백업·임시 파일(.bak/.old/.tmp/~) | **0건** |
| 미사용 JS 파일 | **0건** (10개 전부 참조됨) |
| 라우트 미등록 컨트롤러 | **0건** (23개 전부 등록) |
| bootstrap 미등록 코어 클래스 | 1건 (`BizReg` — CustomersController 가 `require_once` 로 지연 로드, 정상) |
| `SELECT *` 사용 | 100건 (단건 조회 다수 — 목록/집계 위주로 선별 검토 대상) |
| 트랜잭션 | `Db::transaction()` 헬퍼 존재, 컨트롤러 12곳 이상에서 사용 중 |

**초기 판단: 코드베이스는 예상보다 정리된 상태.** 대규모 구조 변경이 아니라
선별적 개선(중복 권한 코드, 반복 쿼리, SELECT * 축소, 반응형 CSS)에 집중한다.

## 7. 작업 중 즉시 조치한 항목

### [Critical] 운영 로그인 페이지에 테스트 계정 노출
- 위치: `app/views/auth/login.php:22`
- 관측: 인증 없이 접근 가능한 운영 로그인 페이지가 `테스트 계정: admin / password123!` 를 그대로 출력.
  `curl <SERVICE_URL>/index.php?r=login` 로 실제 확인.
- 영향: 최고운영자 아이디 공개 → 표적 대입·계정 잠금 유발 가능. (실제 비밀번호는 사장이 변경한 상태였음)
- 조치: `APP_ENV !== 'production'` 게이트 추가 후 즉시 배포. 운영 재확인 결과 노출 0건.

---

## 8. 검증 기준선 (변경 전)

- `php scripts/tests/run.php` — 25 스위트 전건 통과
- `bash scripts/qa_r16_probe.sh` — 119건 전건 통과
- `node scripts/qa_browser/qa_r16_browser.js` — 43건 전건 통과

리팩토링 이후 위 3종이 동일하게 통과해야 회귀 없음으로 판단한다.
