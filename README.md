# EDEN CRM — 도장회사 내부 CRM·영업·공정관리 시스템

도장(페인트) 시공회사가 **고객 유입 → 상담 → 견적 → 계약 → 공사(공정) → 정산 → 직원 성과 분석**까지
하나의 웹 시스템에서 관리하는 실운영형 사내 업무 시스템. 순수 PHP 8.2+ / MySQL 8+ (프레임워크 미사용).

> 기존 도장회사 홈페이지와 **완전히 분리된 별도 프로젝트**(별도 폴더·별도 DB 스키마)입니다.

## 기술 스택
- PHP 8.2+ (PDO, 세션 인증), MySQL 8 / MariaDB 호환
- 프론트: HTML5 / CSS3 / Vanilla JS(fetch), Chart.js 4, SortableJS (로컬 번들, CDN 의존 없음)
- 프레임워크 없는 경량 자체 MVC 구조 — 일반 PHP 호스팅(Cafe24 등) 업로드 가능

## 빠른 시작 (로컬)

```bash
# 1) DB 준비 — 전용 DB/계정 생성(root 권한 필요) 후 config.local.php 설정
#    (개발 편의를 위해 프로젝트 전용 격리 MySQL 인스턴스를 쓸 수도 있음 — 아래 '개발 DB' 참고)
mysql -uroot -p -e "CREATE DATABASE eden_crm DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'eden_crm_user'@'localhost' IDENTIFIED BY '비밀번호';
  GRANT ALL ON eden_crm.* TO 'eden_crm_user'@'localhost'; FLUSH PRIVILEGES;"

# 2) 스키마·시드 적재
mysql -ueden_crm_user -p eden_crm < database/schema.sql
mysql -ueden_crm_user -p eden_crm < database/seed_core.sql   # 운영 필수(역할·권한·단계·설정)
mysql -ueden_crm_user -p eden_crm < database/seed_dev.sql    # 개발용 더미(운영 배포 시 제외)

# 3) 환경설정
cp app/config/config.local.example.php app/config/config.local.php
#   → DB_NAME/DB_USER/DB_PASS 등 입력

# 4) 로컬 서버 실행 (DocumentRoot = public)
php -S 127.0.0.1:8080 -t public
#   브라우저: http://127.0.0.1:8080/index.php?r=login
```

### 개발 DB(격리 인스턴스) — root 권한이 없을 때
로컬에 root 접근이 없으면 프로젝트 전용 MySQL 인스턴스를 별도 포트로 띄워 씁니다(사용자 기존 MySQL 미간섭).
`.devdb/` 에 데이터디렉토리가 있으며 포트 3307/소켓으로 동작합니다. `config.local.php` 가 이 인스턴스를 가리킵니다.
운영 배포 시에는 `config.local.php` 를 실제 호스팅 MySQL 정보로 교체하세요.

```bash
# 개발 DB 인스턴스 기동(이미 초기화된 경우)
/opt/homebrew/bin/mysqld --datadir="$PWD/.devdb/data" --port=3307 \
  --socket="$PWD/.devdb/mysql.sock" --mysqlx=0 --log-error="$PWD/.devdb/error.log" &
```

## 테스트 계정 (⚠️ 운영 배포 전 반드시 변경)
| 아이디 | 비밀번호 | 역할 |
|---|---|---|
| admin | password123! | 슈퍼관리자(사장) |
| sales1 | password123! | 영업관리자 |
| site1 | password123! | 현장관리자 |
| staff1 | password123! | 일반직원 |
| acct1 | password123! | 회계·정산 |

> staff6 은 최초 로그인 시 비밀번호 변경 요구(must_change_password) 케이스입니다.

## 권한 체계(RBAC)
5개 역할(super_admin / sales_manager / site_manager / staff / accountant)과 30개 세분 권한.
**화면 메뉴 숨김에 그치지 않고, 라우터가 모든 요청에서 권한(perm)·로그인·CSRF·메서드를 강제**하며,
데이터 접근 범위(본인 담당/배정 프로젝트만 등)는 `Scope` 헬퍼로 쿼리 레벨에서 차단합니다(IDOR 방지).
자세한 매핑은 `docs/ARCHITECTURE.md` 6절, 권한 키는 `docs/DB_INTERFACE.md` 참조.

## 보안
- 비밀번호 `password_hash()`(bcrypt), 로그인 5회 실패 시 15분 잠금, 세션 고정 방어(로그인 시 `session_regenerate_id`)
- 유휴 60분 자동 로그아웃, 비활성/삭제 계정 매 요청 재검증
- 전 변경요청 CSRF 토큰 검증, 전 쿼리 PDO Prepared Statement
- 업로드: DocumentRoot 밖(`storage/uploads`) 랜덤 파일명 저장, 확장자·MIME·이중확장자 검증, 다운로드는 권한검사 후 스트리밍(직접 실행 불가)
- 중요 행동 감사 로그(`audit_logs`) 기록

## 폴더 구조
```
public/          DocumentRoot (index.php 단일 진입점, assets)
app/
  config/        config.php(공통) + config.local.php(비밀값, git 제외)
  core/          Db Auth Rbac Csrf Audit View Response Util Calc Upload Nav Notif Scope
  controllers/   모듈별 컨트롤러
  models/        (선택) 쿼리 모음
  views/         layout/ + 모듈별 템플릿
  routes.php     라우트 레지스트리
storage/         uploads/(업로드) logs/(에러로그)
database/        schema.sql, seed_core.sql, seed_dev.sql
docs/            ARCHITECTURE.md, DB_INTERFACE.md, MODULE_GUIDE.md, PLAN.md
scripts/         qa_smoke.sh, security_probe.sh
```

## 주요 기능
- **고객 CRM**: 고객 등록/상세(활동 타임라인·견적·계약·프로젝트 통합), 중복검사·병합, CSV 내보내기
- **영업 파이프라인**: 칸반 드래그앤드롭 단계 이동, 예상 순이익률 자동계산, 가중 예상매출
- **견적·계약**: 견적 항목·버전 이력·인쇄, 계약·입금(payments)·미수금, 계약→프로젝트 전환
- **프로젝트·공정 보드**: 드래그앤드롭 공정 단계 이동(이력·사유·지연표시·완료잠금), 파일/현장사진
- **일정 스케줄러**: 월 캘린더 + 직원별 주간 타임라인, 드래그 이동, 일정 충돌 경고(승인식)
- **직원 배정·작업일지**: 다중 배정·기여도, 작업일지·현장사진·관리자 확인
- **원가·수익률**: 예상/실제 원가·순이익·순이익률, 적자·달성률
- **직원 성과·수익 기여도**: 기여도 비율 반영(중복합산 방지), 목표 달성률
- **대시보드·리포트**: 권한별 대시보드(Chart.js), 기간 리포트·CSV
- **알림·감사로그**: 업무 누락 방지 알림, 중요 행동 감사 추적

## 운영 배포 체크리스트
- [ ] `config.local.php` 를 운영 DB 정보로 교체, `APP_ENV=production`
- [ ] `seed_dev.sql`(더미 데이터) 미적용, 테스트 계정 비밀번호 변경/삭제
- [ ] `storage/` 쓰기 권한, 웹에서 `storage/`·`app/` 직접 접근 차단(웹서버 설정)
- [ ] display_errors off(운영 자동), 에러 로그 경로 확인
