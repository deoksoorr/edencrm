# 브라우저 QA 스위트 (Playwright)

R8 입력폼 버그(저장 후 입력값 잔존·한글 IME) 수정 검증과 전 화면 브라우저 QA 자동화.
로컬 dev 환경(`scripts/start_dev.sh`, 127.0.0.1:8080, admin/password123!) 전용 — 운영 대상 실행 금지.

## 준비

```bash
npm i playwright@1.61   # 별도 디렉토리에서 설치해도 됨 (NODE_PATH 지정)
```

## 실행

```bash
# 1) 근본수정 회귀(10 케이스): 저장 성공 후 뒤로가기 폼 초기화 / 검증 실패 시 입력 보존 /
#    더블클릭 중복 제출 방지 / CDP 한글 IME 조합 무결성
node scripts/qa_browser/verify_fix.js

# 2) 종합 QA(60 케이스): 더미데이터 CRUD(고객·리드·견적·계약)·검색·필터·멀티탭·붙여넣기·
#    IME 53필드 전수·모바일 2기종 에뮬·17라우트 콘솔/HTTP 오류 스윕
node scripts/qa_browser/qa_full.js
```

- qa_full 은 `QA더미_` 접두사 데이터를 생성한다. 실행 전후 시드 리셋 권장:
  `mysql --socket=.devdb/mysql.sock ... < database/schema.sql + seed_core.sql + seed_dev.sql`
  후 `php scripts/reconcile_qa.php` 로 빈 기준선 복원 확인(56 assert).
- IME 프로브는 CDP `Input.imeSetComposition` 으로 자모 조합을 시뮬레이션하고
  조합 중 프로그램적 value 재대입(JS-SET)·포커스 이탈(BLUR)·DOM 교체를 감지한다.
  readonly/disabled 필드(예: 계약 잔금 자동계산)는 설계상 제외.
