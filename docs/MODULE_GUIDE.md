# EDEN CRM — 모듈 작성 가이드 (T4~T8 공통)

코어(T3)는 완성돼 있고 라우트(app/routes.php)에 모든 라우트가 이미 선언돼 있다.
너는 **자기 모듈의 컨트롤러·뷰·(필요시)모델·모듈 JS 파일만** 새로 만든다.
**절대 수정 금지 공유 파일**: app/routes.php, app/bootstrap.php, app/core/*, public/assets/css/app.css,
public/assets/js/app.js, public/index.php, 다른 모듈의 파일. (CSS 클래스는 app.css 에 이미 충분히 있다. 부족하면
자기 뷰 안에 `<style>` 블록으로 최소한만 추가한다 — app.css 는 건드리지 말 것.)

## 컨트롤러 규약
- 파일: `app/controllers/<Name>Controller.php`, 클래스명 동일, 액션 = public 메서드(라우트에 선언된 이름 그대로).
- 라우터가 이미 로그인·권한(perm)·CSRF·메서드를 강제하므로 컨트롤러에서 중복 검사 불필요.
  단 **데이터 범위(IDOR)** 는 컨트롤러가 책임진다 → `Scope` 헬퍼 사용.
- 조회는 `Db::all/one/val`, 저장은 `Db::insert/update` + `Db::transaction`. **문자열 조립 쿼리 금지(항상 바인딩).**
- 목록 액션: 검색·필터·정렬 + **서버 사이드 페이지네이션**(`Util::paginate`, 기본 page_size=20). 절대 전체 로드 금지.
- JSON 응답: `Response::json($data)` / `Response::error($msg,$status)`. 페이지: `View::render('module/tpl', $data)`.
- 저장/삭제 성공 후: 폼 제출이면 `Response::redirect('module.index',[],'저장되었습니다.')`,
  AJAX면 `Response::json(['id'=>$id])`. 중요 변경은 `Audit::log(...)`, 알림 필요 시 `Notif::push(...)`.
- 금액 계산은 반드시 `Calc::` 사용(0 나눗셈 null 처리 통일).

## 뷰 규약
- 위치 `app/views/<module>/<tpl>.php`. 레이아웃 자동 적용(기본 default = 사이드바+톱바). 부분은 `View::partial`.
- **모든 출력 이스케이프**: `<?= e($x) ?>`. 금액 `<?= money($x) ?>`, 퍼센트 `<?= pct($x) ?>`, 날짜 `<?= fmtdate($x) ?>`.
- URL 은 `url('route.key',['id'=>$id])`. CSRF 폼엔 `<?= csrf_field() ?>`. 권한 분기 `<?php if(can('perm')): ?>`.
- 표는 `.table-wrap > table.data`(가로 스크롤). 금액 열은 `class="num"`. 상태는 `.badge .badge-ok/warn/danger`.
- 빈 데이터엔 `.empty`(안내+주요 액션), 로딩은 버튼 `disabled`+`.spinner`.
- 페이지 상단은 `.page > .page-head(.page-title + .page-actions)`.

## JS 규약
- 공통 래퍼 사용: `await api('route.key', {k:v})` (POST) / `api('route.key', {q}, )` GET. CSRF 자동 첨부, 실패 시 throw.
- 토스트 `toast(msg,'success'|'error')`, 모달 `EDEN.modal({title,body,buttons})`, 확인 `await EDEN.confirm('...',{danger:true})`.
- 모듈 전용 JS 는 `public/assets/js/<module>.js` 로 만들고, 뷰에서
  `View::render('tpl',[...,'scripts'=>['js/<module>.js'],'inlineScript'=>$js])` 로 로드(레이아웃이 지원).
- 칸반 DnD 는 vendor/Sortable.min.js, 차트는 vendor/chart.umd.js 를 `scripts`에 추가해 사용.

## 핵심 계약값
- 권한키·역할키·단계키·전체 컬럼은 `docs/DB_INTERFACE.md` 참조(그대로 사용, 컬럼 추정 금지).
- 계산 산식은 `docs/ARCHITECTURE.md` 8절 = `Calc` 클래스.
- 프로젝트 상태코드: preparing/in_progress/paused/completed 등(projects.status VARCHAR). contribution_mode: main/ratio/role.
- DB 접속(검증용): `/opt/homebrew/bin/mysql --socket="$PWD/.devdb/mysql.sock" -ueden_crm_user -pEdenCrm!local2026 eden_crm`
- 로컬 서버: http://127.0.0.1:8080 (php -S, DocumentRoot=public). 테스트: admin/password123!, staff1/password123!.

## 완료 기준(각 모듈)
1. `php -l` 전 파일 무오류.
2. 실제 서버에 로그인해 자기 라우트를 curl 로 호출 → 200/정상 JSON 확인, 에러로그(storage/logs) 깨끗.
3. 권한 없는 계정으로 자기 쓰기 라우트 호출 시 403 확인(해당되면).
4. report 파일에 검증 커맨드 실제 출력 첨부. git add/commit.
