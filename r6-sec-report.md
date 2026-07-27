# R6 Security QA 보고서 (T5 · sec)

- 대상: eden_crm (순수 PHP MVC + MySQL)
- 검수 방식: 로컬 임시 서버 **8092**(8080 상설 미접촉), 공유 dev DB, curl --max-time 10, 정적 코드 검토 + 라이브 프로브
- 계정: admin(super_admin)/chays(site_manager)/maeng(staff)/chaws(staff), password123!
- 베이스라인: 빈 시드(고객·계약 0, 일정 3), reconcile 56/0 청정 (검수 종료 시 원복 증명)
- 심각도: Critical / High / Medium / Low / Info

각 섹션은 완료 즉시 append 한다.

---

## 1. SQL 인젝션 — 결과: 취약 0 (PASS)

**정적 검토**
- 전 쿼리 단일 진입점 `Db::run()` → `PDO::prepare` + `ATTR_EMULATE_PREPARES=false`(실 prepared statement). 값은 전부 named/positional 바인딩.
- 앱·코어 전수 grep: 슈퍼글로벌(`$_GET/$_POST/$_REQUEST`)이 SQL 문자열에 직접 삽입되는 곳 **0건**, 사용자 값 raw concat **0건**.
- 동적 `ORDER BY` 3곳 전부 화이트리스트: Customers `SORT_MAP[$sort]??기본`, Projects `$sortMap[$sortKey]`(미존재 시 기본)+`$dir`(DESC/ASC 강제), Contracts `match($sort)` default 존재. `$lastPaid`/`PAID_SUM_SQL` 은 하드코딩 SQL 조각(사용자값 아님).
- `LIMIT/OFFSET` 은 `Util::paginate()` 반환 int, `IN(...)` 은 `array_fill('?')`/named placeholder + `(int)` 캐스팅 id. 전부 안전.
- id 파라미터는 `Util::int()`/`(int)` 캐스팅 후 바인딩 — `1 OR 1=1` → int `1`.

**라이브 프로브(8092, admin)**
- customers.index: `q=' OR 1=1--`, `q=' UNION SELECT password_hash FROM users--`, `sort=id;DROP TABLE users--`, `sales_user_id=1 OR 1=1`, `status=active'--` → 전부 **200**, SQL 오류/우회 없음.
- projects.index: `sort=p.id;DROP`, `dir=ASC,(SELECT 1)`, `q=' OR '1'='1` → **200**(화이트리스트로 무해화).
- contracts.index `sort=paid_recent'--` → 200. audit.index `action/user_id/entity_type/from/q` 인젝션 → 전부 200(바인딩).
- id 인젝션: customers.show/quotes.show/contracts.show `id='`·`id=0 UNION` → 404, projects.show → 403 (int 캐스팅).
- schedule.data `start/from=' OR 1=1` → **422**(`Util::dateOrNull` 검증 거부).
- **로그인 우회**: `login_id=admin' OR '1'='1` / `password=x'--` → 로그인 실패(post-bypass home 302). 
- 강제 오류 응답에 `SQLSTATE`/`PDOException`/스택 노출 **0건**.

## 2. XSS (저장·반사·DOM) — 결과: 취약 0 (PASS)

**서버 렌더(정적)**
- 전역 이스케이프 헬퍼 `e()` = `htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')`. 뷰 전수 grep: `e()`/전용 헬퍼를 거치지 않고 DB 텍스트 필드(name/memo/title/address/note/tags/company/contact 등)를 raw echo 하는 곳 **0건**.
- 폼 재표시 헬퍼 `$val`/`$cv`(customers·pipeline·projects·contracts·targets) 전부 내부에서 `e()` 호출 — 반사형 XSS 차단.

**저장형 라이브 테스트(8092, admin)**
- 고객 생성: name=`<script>alert(1)</script>`, memo=`"><img src=x onerror=alert(2)>`, address=`<b>bold</b>`, tags=`<svg/onload=alert(3)>`.
- customers.index/show 렌더 결과: raw `<script>` **0건**, `&lt;script&gt;` **3건**, memo=`memo&quot;&gt;&lt;img src=x onerror=alert(2)&gt;`(태그 전부 엔티티화·비활성 텍스트). **테스트 고객 즉시 삭제**(customers=0 복원).

**JS DOM 싱크**
- innerHTML 싱크 전수 점검: scheduler.js(`esc`)·reports.js(`esc`)·process-board.js(`escapeHtml`)·report_attendance.js(`esc`) — 사용자 데이터(title·name·memo·customer_name 등) 전부 이스케이프.
- quotes.js `escapeAttr`는 `"`만 치환하나 삽입 위치가 전부 `value="..."`(이중따옴표 속성)이라 속성 탈출 불가 — 컨텍스트상 안전(방어심화 여지는 Info).
- dashboard.js 미이스케이프 보간 `${r.label}`/`${r.color}`(line 84)의 출처는 `Stages::pipelineGroups()` **하드코딩 상수**(사용자 편집 불가) — 무해.
- JSON 엔드포인트(schedule.data/reports.data/dashboard.data/notifications.list)는 `Content-Type: application/json` + `json_encode(JSON_UNESCAPED_UNICODE)` — 브라우저 HTML 파싱 없음.

## 3. CSRF — 결과: 취약 0 (PASS)

- 전역 강제: `public/index.php` 라우터가 **모든 POST** 에 대해 컨트롤러 실행 전 `Csrf::verify()` 수행. 토큰은 세션당 1개(`random_bytes(32)`), `hash_equals` 타이밍 안전 비교, `_csrf` 본문 또는 `X-CSRF-Token` 헤더.
- 세션 쿠키 `SameSite=Lax` 로 교차 사이트 POST 시 쿠키 미전송(2차 방어).
- 라이브(admin 세션): 토큰 없는 POST 13종 전부 **419** — customers.save/delete, quotes.save, contracts.save, projects.save, payments.save, schedule.save, costs.save, staff.save, settings.save, attendance.mark, process.move, notifications.readall.
- 위조 토큰(`_csrf=deadbeefwrong`) POST → **419**(customers.save/schedule.save/settings.save). JSON(AJAX) 변형도 419 + 표준 에러 바디.

## 4. 파일 업로드 위장 — 결과: 취약 0 (PASS)

모든 업로드 경로(사업자등록증 customers.license.upload / 프로젝트 파일 projects.upload / 작업일지·현장사진 worklogs.photo / 하자보수 process.warranty.photo)가 단일 관문 `Upload::save()` 를 경유. 라이브 테스트(license 경로, 8092):

| 파일 | 결과 |
|---|---|
| evil.php (`<?php system(...)`) | ❌ "허용되지 않는 파일 형식입니다"(블랙리스트) |
| evil.phtml | ❌ 블랙리스트 |
| evil.php.jpg (이중 확장자) | ❌ 전 구간 검사로 `php` 감지 → 거부 |
| shell.png (PHP 내용, .png) MIME 위장 | ❌ "파일 내용이 확장자와 일치하지 않습니다"(finfo) |
| polyglot.gif (GIF89a+PHP) | ❌ 확장자 화이트리스트 거부 |
| .htaccess | ❌ 블랙리스트 |
| good.png (정상) | ✅ 업로드 성공(대조군) |
| big.png(11MB) | ❌ 엔진 post_max_size + 앱 `UPLOAD_MAX`(10MB) 이중 한계로 거부 |

- 방어 계층: 확장자 블랙리스트(php/phtml/phar/pht/sh/exe/htaccess/js/html/svg…, **파일명 전 구간** 검사로 이중 확장자 차단) → 확장자 화이트리스트 → `finfo` MIME=확장자 매칭 → 랜덤 저장명+화이트리스트 확장자 강제 → `is_uploaded_file`+`move_uploaded_file`.
- 저장 위치 `storage/uploads/**`(docroot=`public/` **밖**, `public/uploads` 부재). 다운로드는 `Upload::send()`(권한 콜백 검사 + `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`, inline 은 이미지/PDF 만) 경유만 — 직접 실행 URL 불가.
- 운영 `storage/.htaccess`(php engine off·RemoveHandler) 이중 차단은 T4 산출물 — 로컬 php -S 는 .htaccess 미해석이라 실효 검증은 T12 인계.
- (참고) `public/.user.ini`(post/upload 12M)는 FPM/CGI SAPI 에서만 적용 — CLI 개발서버 무관, 운영 영향 없음.

## 5-a. 권한 상승(RBAC) — 결과: 취약 0 (PASS)

라우터가 인증→CSRF→`Rbac::require(perm)` 순으로 강제. super_admin 은 항상 허용, 그 외는 role_permissions + user_permissions(grant/deny) 합성.

- **staff(maeng, perm=project.view_assigned·worklog.create)**: 권한 필요 GET 17종(customers/quotes/contracts/staff/settings/audit/reports/pipeline/targets/projects.form/export 3종) 전부 **403**. 유효 CSRF 동봉 POST-write 10종(customers/settings/staff/attendance/contracts/quotes/projects/costs/payments/schedule .save) 전부 **403**(CSRF 통과 후 perm 거부 — 419 아님). AJAX 변형 403 JSON.
- **site_manager(chays)**: super_admin·미보유 perm 라우트 11종 전부 **403**, 허용 라우트(customers.index·schedule·projects·home) **200** — 역할 분리 정상.
- **미인증(쿠키 없음)**: 보호 라우트 전부 **302→login**, JSON 은 **401**. 인증 성공 시에만 접근.

## 5-b. 세션/쿠키 — 결과: 취약 0 (PASS)

- 세션 쿠키(로그인 페이지 Set-Cookie): `HttpOnly; SameSite=Lax; path=/`. `secure` 는 `APP_ENV==='production'` 에서만 부여(로컬 HTTP 정상, 운영 HTTPS 강제).
- **세션 고정 방어**: 로그인 성공 시 세션ID 재생성 실측 — before `b71bb253…` → after `7d17bca7…`(변경 확인, `session_regenerate_id(true)`).
- 로그아웃: `$_SESSION=[]` + 쿠키 만료 + `session_destroy()`. 로그아웃 후 동일 쿠키 재사용 → home **302**(무효화 확인).
- 유휴 자동 로그아웃(`SESSION_IDLE`) + 매 요청 status/soft-delete 재검증(`Auth::user()`) — 비활성·삭제 계정 즉시 로그아웃.

## 6. IDOR / 경로 접근 — 결과: 취약 0 (PASS)

라우트 perm 이 없는(컨트롤러 authz 의존) 경로도 전부 쿼리/컨트롤러 레벨에서 소유권 강제(화면 숨김 아님):

- **프로젝트**(격리 DB 실측: 프로젝트 owner=chays, staff maeng 미배정):
  - maeng projects.show?id=1 → **403** / chays(소유) → 200 / admin(view_all) → 200.
  - maeng files.download?id=1 → **403**(`Scope::canAccessProject`), projects.upload project_id=1 → **403**.
- **성과(급여성)**: maeng performance.user?id=1(admin) → **403**, id=3(본인) → 200 (`Scope::canViewUserPerformance` = view_all ∨ 본인). performance.index 도 비-view_all 은 본인만.
- **알림**: notifications.read/list/readAll 전부 `WHERE user_id=본인` — maeng 이 admin 알림 id 마킹 시도 → **404**("찾을 수 없습니다").
- **files.download 게이트**: customer_license 는 전용 라우트만 허용(false 반환), project_id NULL 은 project.view_all 필요, 그 외 Scope 검사.
- 견적·계약은 라우터 perm(quote.view/contract.view)로 차단 — staff/site_manager 403.

**경로 조작**:
- 라우트 `r=../../etc/passwd`·URL 인코딩 변형 → **404**(라우트 레지스트리 미존재). files.download `id` 는 int 캐스팅(traversal 불가).
- 업로드 `path` 는 DB 저장값(randomName+int subdir) — 사용자 traversal 주입 지점 없음.

**내부 경로 웹 접근**(docroot=`public/`):
- /app/config/config.local.php·/database/schema.sql·/deploy/cafe24.env·/.git/config·/.superpowers/…·/storage/… → 소스 유출 **0**(전부 302 login, 파일 미서빙 — docroot 밖). 정적 asset(/assets/js/app.js)만 200.
- 운영(cafe24 단일 디렉토리) 정적 검증: 루트 `.htaccess` 가 `^(app|storage|database|scripts|docs|deploy|.superpowers|.devdb|.git)` 403, dotfile·민감확장자(sql/sh/env/log/md/ini…) deny, `Options -Indexes`, nosniff/SAMEORIGIN, HTTPS 강제. `storage/.htaccess` 전면 deny + `php_flag engine off`+RemoveHandler(위장 PHP 실행 이중 차단). **로컬 php -S 미해석 → 실효(Apache) 검증은 T12 인계**.

## 7. 인증(무차별 대입·bcrypt·계정 열거) — 결과: 취약 0, 하드닝 1건 수정 + 열거 2건 hold

- **무차별 대입 잠금(PASS)**: `login_max_attempts=5` 연속 실패 → `lock_minutes=15` 잠금(`locked_until` 설정, `failed_attempts` 리셋). 잠금 후 정확한 비밀번호도 거부. `login_attempts` 전 시도 기록(격리 실측 7건/6실패). IP 는 `REMOTE_ADDR` 만(X-Forwarded-For 미신뢰 — 스푸핑 우회 불가).
- **bcrypt(PASS)**: 저장 해시 `$2y$12$…`(cost **12**, 기본 10보다 강). 비밀번호 변경 정책: 8자 이상+영문+숫자+직전 재사용 금지.
- **계정 열거 — 타이밍 side-channel(수정 완료)**: 수정 전 존재 계정(bcrypt 실행) 0.20s vs 미존재 0.02s 로 아이디 유효성 노출. **수정**: `Auth::attempt` 에 더미 bcrypt 해시(cost 12) 상수 추가 — 사용자 부재 시에도 `password_verify` 1회 실행해 응답시간 균일화. 수정 후 0.214s vs 0.208s(격차 소멸). 로그인 성공/실패/잠금 회귀 없음.

## 8. 정보 노출 — 결과: 취약 0, 하드닝 1건 수정

- **강제 오류**: 잘못된 id(`abc`·`-1`·`0`·거대값)·미존재 라우트 → 404/403/200 클린, 응답 본문에 `SQLSTATE`/`PDOException`/`Fatal error`/스택/절대경로(`/Users/…`) 노출 **0건**. 404/403/419 에러 페이지 경로 유출 0.
- **운영 오류 처리**: `config.php` 가 `APP_ENV=production` 에서 `display_errors=0`+로그파일, 라우터 catch-all 이 운영에서 일반 메시지("처리 중 오류…")만 반환. 로컬은 상세 표시(개발 의도).
- **X-Powered-By(PHP/8.5.4) 핑거프린팅(수정 완료)**: `public/index.php` 에 `header_remove('X-Powered-By')` 추가 — 응답에서 제거 확인(호스트 expose_php 설정과 이중). 로그인 200 정상.

## 9. 인계(security_probe.sh) — 결과: 갱신 + PASS 9/0

- 구 시드 참조(계정 `staff1`, user id 6) → 신 4계정 기준 갱신: `maeng`(staff, id 3), IDOR 쿼리 `user_id/sales_user_id/site_manager_id<>3`.
- 대상 서버·DB env 재지정(`SEC_BASE`/`SEC_DB`) 지원 — 격리 검증 가능(기본은 상설 8080+eden_crm 유지).
- 격리 DB(8092/eden_crm_sectest, IDOR 대상 프로젝트 시딩) 실행 → **PASS 9/0**(비로그인 302·staff 관리API 403·SQLi 로그인우회 실패·검색 SQLi 안전·staff 타 프로젝트 403·login_attempts 기록·쿠키 HttpOnly·업로드 검증·docroot 밖 저장).

---

## 종합 (T5 Security)

**발견 총계**: Critical 0 · High 0 · Medium 0 · Low 3 (수정 2 / hold 1군)

| # | 심각도 | 항목 | 조치 |
|---|---|---|---|
| F1 | Low | 계정 열거 — 로그인 응답 **타이밍** side-channel(존재 계정 bcrypt 실행 0.20s vs 미존재 0.02s) | **수정** `Auth.php` 더미 bcrypt 해시로 응답시간 균일화(0.214 vs 0.208) |
| F2 | Low | 기술스택 노출 — `X-Powered-By: PHP/8.5.4` | **수정** `index.php` `header_remove('X-Powered-By')` |
| F3 | Low | 계정 열거 — 메시지 기반(비활성 계정 "비활성화된 계정입니다" 노출 · 계정별 잠금 메시지가 존재 여부 노출) | **hold** — 내부 4인 CRM UX 트레이드오프. 권고: 비활성 메시지 일반화 또는 IP 기반 스로틀(전면 차단 시) |
| I1 | Info | quotes.js `escapeAttr` 가 `"`만 치환 | 컨텍스트(이중따옴표 속성)상 안전 — 방어심화 권고만 |

**클린(취약 0)**: SQLi(정적+라이브 전수), XSS(저장·반사·DOM, 저장형 라이브), CSRF(전역 강제 13종 419), 업로드 위장(6종 거부·MIME·이중확장자·docroot 밖), 권한상승(staff 27종·site_manager 11종 403), 세션(HttpOnly·SameSite·ID 재생성·로그아웃 무효화), IDOR(프로젝트·성과·알림·파일 소유권 강제), 경로 조작/내부경로(docroot=public·소스유출 0), 정보노출(강제오류·에러페이지 유출 0·운영 display_errors off).

**적용 수정(3파일, 회귀 없음)**:
- `app/core/Auth.php` — 계정 열거 타이밍 방어(더미 bcrypt).
- `public/index.php` — `header_remove('X-Powered-By')`.
- `scripts/security_probe.sh` — 신 4계정 기준 갱신 + `SEC_BASE`/`SEC_DB` 파라미터화 → 격리 실행 PASS 9/0.

**회귀 검증(격리 scratch DB + 8080)**: php -l/bash -n 전건 PASS · acct 스위트(run.php) 전체 통과 · reconcile 56/0 · qa_smoke 33/0(8092·8080 양쪽). **운영 데이터 원복**: 임시 고객·업로드·감사로그 전량 정리, scratch DB drop, 8092 종료(8080 미접촉), **최종 shared reconcile 56/0**.

**운영(T12) 인계**: `.htaccess` 실효 검증은 Apache 필요 — (1) `/eden-crm/app|storage|database|scripts|deploy|.git` 403 (2) `storage/` PHP 실행 차단(위장 업로드) (3) 업로드 직접 URL 403·앱 스트리밍 정상 (4) `config.local.php` 소스 미노출 (5) HTTPS 리다이렉트·보안헤더 (6) 호스트 `expose_php=Off`. 로컬 php -S 는 .htaccess 미해석이라 정적 검토만 완료.
