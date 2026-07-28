# R16 직원 권한 마이그레이션 결과

생성: 2026-07-29 07:46:36 · 모드: APPLY

변환 규칙: 현재 유효 권한(역할 ∪ 개별부여 − 개별제외)을 리소스×액션으로 사상.
기존에 쓰기 perm 으로 삭제가 가능하던 라우트는 delete 를 승계해 업무 중단을 막았다.
최고운영자 전용으로 이관된 권한은 "상실" 열에 표시한다.

| 직원 | 로그인ID | 역할 | 상태 | 부여된 리소스 (R/W/D) | 상실 권한 |
|---|---|---|---|---|---|
| 김덕수 | admin | super_admin | active | 최고운영자 — 코드상 전체 권한(행 미생성) | – |
| 차윤석 | chays | site_manager | active | field.costs(RWD)<br>field.process_board(RWD)<br>field.projects(RW-)<br>field.schedules(RWD)<br>field.worklogs(RW-)<br>sales.customers(R--) | – |
| 맹기현 | maeng | staff | active | field.projects(R--)<br>field.worklogs(RW-) | – |
| 차우석 | chaws | staff | active | field.projects(R--)<br>field.worklogs(RW-) | – |

## 요약
- 전체 계정: 4
- 최고운영자(행 미생성): 1
- 권한 행 생성 대상: 3
- 권한 전무(최소 권한 적용): 0
