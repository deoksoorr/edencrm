# EDEN CRM 보안 감사 보고서 (exploit-driven)

- 감사일: 2026-07-29
- 대상: 로컬 인스턴스 `http://127.0.0.1:8080` (APP_ENV=local), 커밋 브랜치 `r15-trash-forms`
- 방식: 실제 요청 전송(curl) + 헤드리스 Chrome 실행 검증 + 정적 전수 grep. **운영(cafe24)은 건드리지 않음.**
- 범위: 인증·세션·권한우회·CSRF·XSS·SQLi·업로드·정보노출·보안헤더·감사로그
- 테스트 데이터: QA 계정 `qa_r16_*`(시더) + `QASEC-` prefix 도메인 데이터. **검수 후 전량 삭제, COUNT=0 검증 완료**(본 문서 말미).

> 실측/추측 구분 원칙: "실측"은 실제로 요청을 쏘거나 브라우저에서 실행해 관측한 것, "코드확인"은 소스에서 확인했으나 런타임 재현은 하지 않은 것.

---

## 요약 — 심각도별 순위

| # | 심각도 | 제목 | 지금 악용 가능? |
|---|--------|------|----------------|
| V1 | **High** | 저장형 XSS — 고객 중복검사 경고(dupcheck → `warnBox.innerHTML`) | **예 (브라우저에서 alert 실행 실측)** |
| V2 | **Medium** | Content-Security-Policy 부재 (+ Referrer-Policy/HSTS/Permissions-Policy) — XSS 2차 방어선 없음 | 예 (V1의 피해 증폭) |
| V3 | **Medium** | 로그인 IP 스로틀 부재 — 계정 단위 잠금만 존재(패스워드 스프레이·표적 DoS) | 예 |
| V4 | **Low→Med** | 저장형 XSS(속성) — 계약 입금 메모 수정 모달(`dataset.memo` 재삽입) | 부분 (심는 데 payment.manage=관리자 필요) |
| V5 | **Low** | 저장형 XSS — 활동 타임라인 `user_name` | 부분 (심는 데 staff.manage=관리자 필요) |
| V6 | **Low** | 계약 관계상 미이스케이프 DOM 싱크(스케줄러 color, `EDEN.confirm` 등) — 현재는 서버검증으로 방어 | 아니오(잠재) |
| V7 | **Low** | `<script>` 내 `json_encode`에 `JSON_HEX_TAG` 누락(A1~A6) — `</script>` 이스케이프로 현재는 무력 | 아니오(방어심화) |
| V8 | **Low** | GET 요청이 DB 쓰기 유발(process.board 자동 정합) + SameSite=Lax | 아니오(비파괴적) |
| V9 | **Low** | 비활성 계정 로그인 시 "비활성화된 계정" 메시지 → 활성/비활성 열거 | 예(정보량 소) |
| V10 | **Low** | CSRF 토큰이 로그인 시 회전되지 않음(pre-auth 토큰 잔존) | 아니오(경미) |
| V11 | **Info→Low** | 디스크 평문 비밀(deploy/cafe24.env·config.production.php) + FTP/DB 동일·약한 비밀번호 | 아니오(웹 비노출) |
| V12 | **Info** | `Db::insert/update` 의 `$table`/`$where` allowlist 부재(잠재 SQLi 하드닝) | 아니오(잠재) |

정상 확인 항목은 문서 하단 "정상 확인된 항목" 참조.

---

## 확인된 취약점 (상세)

### V1 — [High] 저장형 XSS: 고객 중복검사 경고 (실측 exploit)

- 위치:
  - 싱크: `app/views/customers/form.php:200-202` — `warnBox.innerHTML = '중복 의심 고객: ' + data.candidates.map(...c.name...c.company_name...c.phone...c.email...)`
  - 소스: `app/controllers/CustomersController.php` `dupCheck()` — 원시 `SELECT c.id, c.name, c.company_name, c.phone, c.email, c.biz_reg_no` 를 JSON 그대로 반환
- 유입 경로: `customer.manage` 권한 보유 직원이 고객명/업체명 등에 페이로드를 저장 → **누구든** 고객 등록/수정 폼에서 같은 전화·이메일을 입력하고 blur 하면 `customers.dupcheck` 응답이 이스케이프 없이 `innerHTML` 로 삽입됨.
- 재현(실측):
  1. `pbody customers.save name='QASEC-<img src=x onerror=alert(1)>' company_name='QASEC-Co"><svg onload=alert(2)>' phone=010-9999-0001 privacy_agreed=1` → 저장 OK.
  2. 헤드리스 Chrome 로 `customers.form` 접속 → `#fPhone` 에 `010-9999-0001` 입력 후 blur.
- 실제 관측 결과: **`dialog:1` 발생(=`alert(1)` 실행)**, `warnBox` 내부에 실제 `<img ... onerror="alert(1)">` 및 `<svg onload="alert(2)">` 활성 요소 2개가 DOM 에 삽입됨. (스크립트: `scripts/audit/xss_proof.js`)
- 영향: 세션 탈취(문서 쿠키는 HttpOnly 라 직접 탈취는 막히나, CSRF 토큰이 페이지 meta 에 노출되어 있어 **XSS→토큰 탈취→임의 상태변경**이 가능), UI 조작, 관리자 대상 표적 공격.
- 권장 수정: 클라이언트에서 삽입 값을 이스케이프. 같은 파일 `bizDupBox` 는 이미 `textContent` 를 쓰고 있으니 동일 패턴으로:
  ```js
  // 문자열 결합 대신 textContent 사용 또는 esc() 적용
  warnBox.textContent = '중복 의심 고객: ' + data.candidates.map(...).join(', ');
  ```
  또는 각 필드를 `esc()`(다른 뷰의 헬퍼) 로 감싼다. 서버 측 이중 방어로 `dupCheck` 응답 값에 대한 별도 조치는 불필요(출력지점 이스케이프가 정석).

### V2 — [Medium] Content-Security-Policy 부재 (보안 헤더 갭)

- 위치: `public/index.php`(앱 레벨 헤더 없음), `.htaccess:34-37`(Apache 레벨 헤더 = 운영 전용)
- 실측(로컬 응답 헤더):
  - 인증 후 `home` 응답 헤더: `Cache-Control: no-store, no-cache, must-revalidate`, `Pragma: no-cache` 만 존재(민감 페이지 캐시 방지는 OK). `X-Powered-By` 제거됨(OK).
  - **CSP 없음, Referrer-Policy 없음, HSTS 없음, Permissions-Policy 없음.**
  - `X-Content-Type-Options`/`X-Frame-Options` 는 `.htaccess` 에만 있음 → `php -S` 로컬에서는 미적용, 운영(Apache)에서만 적용. 로컬 응답엔 없음(실측).
- 영향: V1 같은 XSS 가 발생했을 때 **막을 2차 방어선이 전무**. 인라인 스크립트가 많아 완전한 CSP 는 어렵지만 최소한의 정책도 없다.
- 권장 수정: 앱 레벨(`public/index.php` 부트스트랩 직후, HTML 응답에 한해)에서 헤더 세팅. 기존 인라인 스크립트를 깨지 않으려면 우선 report-only 로 시작하거나 nonce 도입:
  ```php
  header("X-Content-Type-Options: nosniff");           // .htaccess 와 이중(로컬에도 적용)
  header("X-Frame-Options: SAMEORIGIN");
  header("Referrer-Policy: strict-origin-when-cross-origin");
  header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
  // 운영 HTTPS 에서만:
  if (($config['APP_ENV']??'')==='production') header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
  // CSP: 인라인 스크립트가 많으므로 nonce 또는 최소 정책부터
  // header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
  ```
  주의: 인라인 `<script>`·인라인 이벤트(`onerror` 등 데이터 유입점)와 충돌하므로 CSP 는 **인라인 스크립트를 nonce 화**한 뒤 적용해야 기능이 안 깨진다. 우선 V1 을 고치고, X-Frame/Referrer/HSTS/Permissions 먼저 적용 → CSP 는 점진 도입 권장.

### V3 — [Medium] 로그인 IP 스로틀 부재 (스프레이·DoS)

- 위치: `app/core/Auth.php:20-100`. `login_attempts` 테이블에 IP 를 기록(`recordAttempt`)하지만 **어디서도 IP 기준으로 조회·차단하지 않음**(grep 결과 `login_attempts` 소비처 없음). 잠금은 계정 단위(`failed_attempts`/`locked_until`)만.
- 실측:
  - 계정 잠금: `qa_r16_f` 에 5회 실패 → "5회 실패로 15분간 잠깁니다", 이후 시도는 "계정이 잠겼습니다", **잠금 중 올바른 비밀번호로도 로그인 거부**(302). 정상.
  - 그러나 IP 무관 → (a) 공격자가 한 비밀번호로 여러 계정에 스프레이 가능, (b) 공격자가 피해자 계정을 일부러 5회 실패시켜 **표적 계정 잠금 DoS** 가능(관리자 계정 포함).
- 권장 수정: `login_attempts` 를 활용해 동일 IP 의 최근 N분 실패 횟수 임계 초과 시 지연/차단(캡차 또는 점증 지연). 계정 잠금과 별개 축으로 IP 축 추가. 관리자 계정 잠금 DoS 완화를 위해 잠금 대신 점증 지연(exponential backoff)도 고려.

### V4 — [Low→Medium] 저장형 XSS(속성 컨텍스트): 계약 입금 메모 수정 모달

- 위치: `app/views/contracts/show.php:171`(서버 렌더 `data-memo="<?= e($pm['memo']) ?>"`) + `:296`(`value="' + (pm.memo || '') + '"`). `pm.memo` 는 `btn.dataset.memo` 에서 읽는데 브라우저가 **엔티티 디코드된 원문**을 돌려주므로, 이를 이스케이프 없이 `value="..."` 속성에 재삽입하면 `" onfocus=alert(1) autofocus x="` 로 속성 탈출 가능.
- 유입 경로: `payment.manage`(= ADMIN_ONLY, 최고운영자 전용) 로 입금 메모에 페이로드 저장 → 입금내역 갱신 모달을 여는 사용자(역시 payment.manage)에서 실행.
- 코드확인(런타임 미재현 — 계약+입금 데이터 구성 비용). 동일 구조인 `app/views/projects/_tab_settlement.php:205-222` 는 이미 `esc()` 로 감싸 안전 → 계약 쪽만 누락.
- 권장 수정: 모달 조립 시 각 값을 `esc()`(정산 탭과 동일 헬퍼) 로 감싼다.

### V5 — [Low] 저장형 XSS: 활동 타임라인 `user_name`

- 위치: `app/views/customers/show.php:286-288` — `div.innerHTML = ... + a.user_name + ...`. `a.user_name` 은 `activities.save` 응답의 활동 작성자 이름(`JOIN users`).
- 유입 경로: 직원 본인은 자기 이름을 못 바꾸므로(staff.save=관리자 전용) **관리자가 악성 이름을 심어야** 성립. `a.content` 는 `<`만 치환(텍스트 컨텍스트라 실질 무해), `a.activity_type`·`a.activity_at` 는 서버에서 정규화/allowlist 되어 안전.
- 권장 수정: `user_name` 을 `esc()` 처리(또는 `textContent` 조립).

### V6 — [Low] 미이스케이프 DOM 싱크 (관계상 안전, 잠재 위험)

- `public/assets/js/scheduler.js:116,179,274,310` — `users.color`/`participants.color` 를 `style="background:..."` 에 원문 삽입. 현재 `StaffController.php:258` 의 `Stages::isValidColor()` 서버검증으로 방어 → 악용 불가. 단 `users.color` 를 쓰는 다른 경로가 생기면 즉시 XSS.
- `public/assets/js/app.js:131`(`EDEN.confirm` `message` → `innerHTML`), `app.js:101`(`modal` body) — 계약상 미이스케이프. 현재 호출부는 전부 리터럴/사전이스케이프 문자열이라 무해.
- `app/views/staff/form.php:124`·`staff/index.php:134` — `data.temp_password`(서버 생성 랜덤) 원문 삽입. 공격자 비제어라 무해.
- 권장: 방어심화로 `esc()` 통일. 특히 `EDEN.confirm`/`modal` 은 "본문은 항상 이스케이프" 규약을 강제하거나 `textContent` 옵션 제공.

### V7 — [Low] `<script>` 내 `json_encode` 에 `JSON_HEX_TAG` 누락 (현재 무력 — 실측)

- 위치(A1~A6): `app/views/schedule/index.php:67`, `app/views/targets/index.php:248-249`, `app/views/bonus/_form_modal.php:17-18`, `app/views/quotes/form.php:115`, `app/views/projects/_tab_staff.php:247` — `json_encode($x, JSON_UNESCAPED_UNICODE)` 를 `<script>` 안에 출력(사용자 이름·프로젝트명·견적항목명 등 포함).
- 실측(중요): 고객명에 `QASEC-</script><script>window.__XSS_A6=1</script>` 를 저장하고 `projects.show` 를 헤드리스 Chrome 으로 로드 → **`window.__XSS_A6 === false`(미실행)**. 이유: PHP `json_encode` 기본값이 `/` 를 `\/` 로 이스케이프(`<\/script>`)해 `</script>` 종료 시퀀스가 성립하지 않음. → **표준 `</script>` 탈출로는 악용 불가.**
- 잔여 위험: `JSON_UNESCAPED_SLASHES` 를 쓰는 곳은 없으나, `<!--`/`<script` 등 slash 없는 엣지 시퀀스는 이론상 남음. 코드베이스는 이미 `app/views/reports/attendance.php:190,311` 에서 `JSON_HEX_TAG` 를 쓰고 주석까지 달아둠 → 컨벤션 일치가 바람직.
- 권장 수정: 6개 지점에 `| JSON_HEX_TAG | JSON_HEX_AMP` 추가(방어심화, 기능 영향 없음).

### V8 — [Low] GET 요청이 DB 쓰기 유발 + SameSite=Lax

- 위치: `app/controllers/ProcessController.php:42-44`(`board()` — GET, perm `process.view`=읽기) 가 `ProcessService::initWaiting()`/`moveStage()` 로 "보드 정합 보정" 쓰기를 수행. `PipelineStageService::attachSignals` 등도 GET 경로.
- 성격: **비파괴적·멱등적 자동복구**(미배치 프로젝트를 '대기중'으로 정렬)이며 공격자 파라미터로 임의 데이터를 조작할 수 없음. 세션 쿠키 `SameSite=Lax` 라 top-level GET 은 쿠키를 실어 보냄 → 개념상 CSRF 안전메서드 원칙 위반.
- 권장: 읽기 라우트에서 쓰기를 분리(예: 정합 보정은 배치/POST 로) 하거나, 최소한 이 동작이 멱등·비파괴적임을 유지. 우선순위 낮음.

### V9 — [Low] 비활성 계정 열거

- 위치: `app/core/Auth.php:61-64` — 계정이 존재하고 `status!=='active'` 이면 "비활성화된 계정입니다. 관리자에게 문의하세요." 반환.
- 실측: 존재하는 비활성 계정 → "비활성화된 계정입니다.", 없는 아이디 → "아이디 또는 비밀번호가 올바르지 않습니다.". **아이디 존재 여부/활성 상태가 메시지로 구분됨.** (정상 로그인 실패 메시지·타이밍은 아래 정상항목 참조 — 존재/미존재는 구분 불가하나 비활성만 구분됨.)
- 권장: 비활성 계정도 일반 실패와 동일 메시지로 통일(운영 편의를 위해 유지하려면 최소한 로그인 성공 후 안내하는 방식으로 전환).

### V10 — [Low] CSRF 토큰 미회전 (로그인 경계)

- 위치: `app/core/Csrf.php:7-13`(세션당 1회 생성, 이후 고정), `app/core/Auth.php:70`(`session_regenerate_id(true)` 는 세션 **데이터를 유지**하므로 pre-auth `csrf_token` 이 로그인 후에도 그대로 승계).
- 영향: 세션ID 는 회전되나 CSRF 토큰은 비인증 시점 값이 인증 세션까지 유지 → CSRF 토큰 고정 가능성(경미).
- 권장: `Auth::attempt()` 성공 시 `unset($_SESSION['csrf_token'])` 로 토큰 재발급 유도.

### V11 — [Info→Low] 디스크 평문 비밀 + 약한/재사용 비밀번호

- 위치: `deploy/cafe24.env`, `deploy/config.production.php` — 운영 DB(`<DB_ACCOUNT>`) 및 FTP 비밀번호가 평문. **동일 문자열 `<FTP_PASSWORD>` 를 FTP·DB 가 공유**하며 관리자 이메일 아이디(`a2381016`) 파생으로 추정되는 약한 값.
- 완화 상태: 둘 다 `.gitignore` 됨(git 추적 안 됨 — 실측: tracked 목록에 없음), 운영 `.htaccess` 가 `deploy/`·`*.env` 웹 접근 차단. → **웹으로 노출되지 않음.**
- 권장: DB/FTP 비밀번호 분리·강화, 파일 권한 최소화(600), 가능하면 환경변수/시크릿 매니저로 이관.

### V12 — [Info] 잠재 SQLi 하드닝 (`Db::insert/update`)

- 위치: `app/core/Db.php:121-144` — `$table`/`$where`/컬럼키를 이스케이프 없이 SQL 로 조립. **현 시점 전 호출부가 상수 테이블명·상수 where·정수캐스트만 전달**(정적 전수 검증 완료 — 아래 SQLi 항목)해 악용 불가하나, 향후 누군가 `$_POST` 배열을 `$data` 로 넘기면 즉시 취약.
- 권장: `Db::insert/update` 진입부에서 `$table` 을 `self::TABLES` allowlist 로, 컬럼키를 `/^[a-z_][a-z0-9_]*$/i` 로 검증(비용 극소, 실패모드 영구 차단).

---

## 정상 확인된 항목 (안전 — 근거 포함)

### 인증
- **비밀번호 해시: bcrypt cost 12** — `password_get_info(admin.hash)` = `{algo:'2y', algoName:'bcrypt', cost:12}`, `password_needs_rehash=false`. 평문/MD5/SHA1 아님. (실측)
- **계정 열거(존재 여부): 방어됨** — 존재/미존재 아이디 모두 동일 메시지 + 응답시간 유사(0.178s vs 0.180s). `Auth::DUMMY_HASH` 로 미존재 계정도 bcrypt 1회 수행(타이밍 방어). (실측) — 단 *활성/비활성* 구분은 V9 로 누출.
- **브루트포스 잠금: 정상** — 5회 실패 → 15분 잠금, 잠금 중 정답도 거부. (실측)
- **세션 고정 방어: 정상** — 로그인 시 `session_regenerate_id(true)` 로 SID 변경(pre `6c6c…` → post `32c7…`). (실측)
- **로그아웃 세션 무효화: 정상** — 로그아웃 후 동일 jar `home` 302, 구 SID 강제 주입도 302(재사용 불가). (실측)
- **must_change_password 강제**, **유휴 자동 로그아웃**(`SESSION_IDLE`) 라우터에서 강제. (코드확인)

### 세션
- **쿠키 플래그: HttpOnly + SameSite=Lax 항상, Secure 는 production 조건부** — 로컬 응답 `Set-Cookie: eden_crm_sid=…; path=/; HttpOnly; SameSite=Lax`(로컬은 http 라 Secure 없음이 정상), `app/bootstrap.php:19-25` 에서 `secure => APP_ENV==='production'`. (실측+코드확인)
- **직원 비활성화 후 기존 세션 즉시 무효화: 정상** — 세션 유지 중 `status='inactive'` 로 변경하자 다음 요청부터 `quotes.index` 302, `dashboard.data`(JSON) 401. `Auth::user()` 가 매 요청 `status` 재검증. (실측)
- **권한 변경 후 기존 세션에 즉시 반영: 정상** — 세션 유지 중 `employee_permissions` 삭제하자 다음 요청부터 `quotes.index`/`quotes.form` 403. 세션에 권한 캐시 없음. (실측)
- **세션 role_key 위조 무력** — `Perm::isSuperAdmin` 은 세션이 아니라 `roles` 테이블을 매 요청 조인해 판정(`Perm.php:143-217`). 세션 `role_key` 는 표시용. (코드확인)

### 권한 우회 (RBAC/IDOR)
- 라우트 전수(131개) + 보강 프로브: **기본 거부** 모델, `ADMIN_ONLY` perm(payment.manage/staff.*/settings.manage/audit.view/trash.manage 등)은 일반 직원에게 부여 수단 자체가 없음(`Perm::adminOnly`).
- **휴지통 복원/완전삭제: 이중 가드** — 라우터 `trash.manage`(ADMIN_ONLY) + 컨트롤러 `requireSuperAdmin`. 비관리자 restore/purge 전부 403. (기존 프로브 + 실측)
- **IDOR 표적 실측**:
  - `performance.user&id=<타인>` → 403, 본인 id → 200 (`Scope::canViewUserPerformance`). (실측)
  - `notifications.read&id=<타인 알림>` → 404(본인 소유만 `WHERE user_id`). (실측)
  - `bonus.history&user_id=1`(비관리자) → 컨트롤러가 `user_id = Auth::id()` 강제(`BonusController.php:301-302`), 타인 데이터 누출 없음. (실측+코드확인)
  - `targets.goal.history&id=1` → 404(`visibleGoalOr404`). (실측)
  - `quotes.show&id=379`(견적권한 없는 E) → 403. `customers.show`(권한 있는 자원) → 200. (실측)
  - `dashboard.data`(권한 전무 F) → `{"ok":true,"data":[]}` (민감정보 없음). (실측)
- **읽기 권한 없이 검색/AJAX**: 리포트 API·견적 리드 API·견적데이터 API 등 라우터 perm 게이트로 403(기존 프로브 재확인).
- **파일 다운로드 권한**: `files.download`/`licenseDownload` 는 `Upload::send` 콜백으로 엔티티 타입·프로젝트 스코프 검증(`ProjectsController.php:908-920`, `CustomersController.php:304-309`). (코드확인)

### CSRF
- **POST 라우트 72개 전수: 토큰 없으면 전부 403** — super_admin 세션으로도 72/72 모두 403(실측). 라우터 `public/index.php:66-68` 가 모든 POST 에 `Csrf::verify()` 일괄 적용.
- **GET 상태변경 라우트 탐색**: 데이터 변경 GET 은 `process.board` 자동정합(V8) 뿐이며 비파괴적·비파라미터. 그 외 상태변경은 전부 POST.

### SQL Injection
- 정적 전수(컨트롤러+코어) + 실측: **악용 가능 지점 없음.** `Db` 는 prepared-statement 전용(`EMULATE_PREPARES=false`).
  - 정렬 컬럼/방향: 전부 allowlist(`CustomersController::SORT_MAP`, `ContractsController` match, `ProjectsController $sortMap`+`$dir` 리터럴 삼항 등). 실측 페이로드 `sort='name); DROP TABLE customers;--'`, `dir='asc; DROP'` → HTTP 200, **customers 26행 무손상**.
  - LIMIT/OFFSET: `Util::paginate` 가 전부 int 보장(일부는 추가 `(int)` 캐스트, 일부는 `:lim/:off` 바인딩).
  - IN 절: `array_map('intval', …)` 또는 `?` 플레이스홀더로 구성. 사용자 문자열이 IN 에 직접 들어가는 곳 없음.
  - `Db::update` 의 `$where`·`$table`: 전 호출부 상수 확인.
  - 유일한 "따옴표 리터럴 내 직접 결합"은 `ProcessController.php:27,386`(`construction_type = '$boardType'`)이나 `Stages::normalizeConstructionType()` 가 `painting`/`interior` 로만 수렴 → 안전(단 하드닝 대상).

### 파일 업로드 (실측)
- `app/core/Upload.php` — 프로젝트 업로드에 7종 악성 파일 전송:
  - PHP 내용을 `.jpg`(image/jpeg 위장)으로 → **거부**(finfo MIME 불일치).
  - `.php`/`.phtml`/`.PHP`(대문자)/`shell.php.jpg`(이중확장자) → **거부**(확장자 블랙리스트가 모든 점 구간 검사 + 소문자화).
  - `.svg`(스크립트 포함) → **거부**(svg 블랙리스트).
  - 정상 MIME 이미지(`ok.png`)만 저장됨.
- 저장 파일명 랜덤(`randomName`), 저장소는 DocumentRoot(public/) 밖 `storage/uploads`, `storage/.htaccess` 가 웹접근 차단 + `php_flag engine off`/`RemoveHandler`(운영 이중방어). 다운로드는 PHP 스트리밍 + 권한 콜백만 허용.

### 민감정보 노출
- 오류 표시: `APP_ENV` 게이트(`config.php:49-57`, `index.php:97-105`). 운영은 `display_errors=0` + 파일 로그, 일반 사용자에겐 "처리 중 오류…" 만. 로컬만 상세 노출(정상).
- 응답 헤더에서 `X-Powered-By` 제거(실측). 감사로그는 `password/password_hash/new_password/_csrf` 마스킹(`Audit::mask`).
- 운영 비밀은 웹 비노출(V11 참조, `.htaccess` 차단 + gitignore).

### 감사 로그 (코드확인)
- 기록됨: `login`/`login_failed`/`logout`, `permission_change`, `access_denied`(Rbac::require)/`superadmin_denied`, 각 엔티티 `*_delete`/`trash_restore`/`trash_purge`/`*_purge`, `reset_password`/`toggle_active`, `attendance_mark_*`, `file_upload`/`file_download`, `settings_update`, `bonus.*` 등 광범위.
- 갭(경미): 라우터 perm 게이트가 없는 "본인 데이터 예외" 라우트에서 컨트롤러 스코프로 403/404 낼 때는 `access_denied` 미기록(예: `performance.user` 타인 조회 403). 보안상 치명적이지 않으나 탐지 관점의 사각.

---

## 테스트 데이터 정리 검증

- QA 계정: `php scripts/qa_r16_seed.php --cleanup` → `qa_r16_a~h` 7개 삭제.
- QASEC 도메인 데이터: customers/quotes/quote_versions/quote_items/leads/projects/project_files/customer_activities 전 테이블 `QASEC` 잔존 **합계 0** (실측).
- 업로드 물리 파일 + `storage/uploads/projects/2905/` 디렉토리 삭제.
- 테스트로 생성된 `audit_logs`(QASEC 참조 7건) 삭제.
- 세션 중 변경했던 `qa_r16_*` status/failed_attempts/locked_until 는 계정 삭제로 함께 제거.
- 사용자 테이블 원복 확인: `admin/chays/maeng/chaws` (+ 본 감사와 무관한 기존 `qafinal_emp` 잔존 — 타 세션 데이터로 미변경).

> 감사용 산출물: `scripts/audit/_lib.sh`, `scripts/audit/get_state_change.php`, `scripts/audit/xss_proof.js`, `scripts/audit/xss_proof2.js` (앱 코드 미변경, `scripts/audit/` 하위에만 생성).
