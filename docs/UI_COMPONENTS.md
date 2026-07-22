# EDEN CRM — 디자인 시스템 컴포넌트 치트시트 (2026-07 재설계)

모든 관리자 화면은 이 공통 시스템(`public/assets/css/app.css`)을 사용한다. 화면별 새 CSS를 만들지 말고 아래 클래스를 재사용한다.

## 레이아웃
- `.page` — 콘텐츠 래퍼(최대폭 1440px 자동 중앙). 보드류 전체폭은 `.page.page-wide`.
- `.page-head` — 제목줄(`.page-title` + `.page-sub`) + 우측 `.page-actions`.
- `.section` + `.section-head`(`.st` 안에 `<h2>` + `.section-desc`, 우측 `.section-link`).
- `.split` — 본문(1.9fr) + 우측 레일(1fr) 2단, 1100px 이하 1단. 레일 고정 `.rail-sticky`.
- 카드: `.card` / 내부 여백형 `.card.pad`.

## 핵심 컴포넌트
- KPI: `.kpi-grid`(자동) / `.kpi-grid.k6`(6열). 항목 `a.kpi`(+`accent-danger|warn|ok|brand`) → `.kpi-label`,`.kpi-value`(`<span class="u">단위`),`.kpi-note`, 증감 `.delta.up|down|flat`.
- 주의 리스트: `.attn-list` > `a.attn-item`(+`warn|danger|zero`) : `.attn-label` + `.attn-cnt`.
- 상태 칩: `.chip-grid` > `a.chip-stat`(+`warn|danger|ok|zero`) : `.cn`(수치) + 라벨. 색점 `.dot`(배경 인라인 허용).
- 퍼널: `.funnel` > `.funnel-step`(+`is-won`) : `.fn`,`.fl`.
- 목표 진행바: `.goal` > `.goal-top`(`.goal-rate`+`.u`) / `.goal-track`>`.goal-fill`(+`low|mid|ok`, width 인라인) / `.goal-meta`.
- 미니 통계: `.kv-row` > `.kv`(`.kv-label`,`.kv-value`). 숫자는 `.mono`.
- 차트: `.chart-box`(230)/`.chart-box.sm`(180)/`.chart-box.lg`(280) 안에 `<canvas>`. 범례 `.chart-legend > .lg > .sw`.

## 공통 요소
- 표: `.table-wrap`(+`.border-0`) > `table.data`(th/td, 숫자열 `.num`, 줄바꿈금지 기본). 금액열은 `.num.mono`.
- 배지: `.badge` + `-ok|-warn|-danger|-info|-muted`.
- 버튼: `.btn` + `-primary|-outline|-danger|-ghost`, 크기 `.btn-sm`.
- 폼: `.form`,`.form-grid`,`.field`(`.field-label`,`.req`),`.input`,`.select`.
- 필터바: `.toolbar`(기존) 또는 파이프라인식 `.pl-filterbar`.
- 빈/로딩: `.empty`(`.empty-title`), `.loading-row`+`.spinner.spinner-dark`.
- 타임라인: `.timeline > .timeline-item`(+`call|visit|note`).

## 칸반(파이프라인/공정 공용)
- `.kanban`(가로스크롤) > `.kanban-col`(그룹색 `style="--gc:#색"`, 접힘 `.collapsed`) > `.kanban-col-head`(`.kanban-caret`,`.kanban-count`,`.kanban-col-sum`) + `.kanban-list`.
- 카드 `.kanban-card` + 단일 상태선 `.st-normal|st-warn|st-delayed|st-won|st-closed`.
- 그룹 탭 `.pl-tabs > .pl-tab`(`.tcnt`), 빠른필터 `.qf`, 적용칩 `.fchip`.

## PHP 헬퍼(뷰 전역 함수)
- `e($s)` 이스케이프 · `money($n)` 12,345,678 · **`moneyShort($n)` 146.4억/6,050만** · **`moneyCell($n)` 축약+정확값 title HTML** · `pct($n)` 12.3% · `pctSigned($n)` +12.3% · `fmtdate($d,'n/j')`.
- `Stages::pipelineGroups/pipelineTabs/processGroups/processTabs/importanceLabel`.
- 큰 금액(억 단위)이 카드·KPI·요약에 들어가면 `moneyShort`/`moneyCell` 사용. **표(table)의 정밀 금액은 `number_format` 유지 + `.num.mono` 우측정렬**(표는 정확값이 우선).

## 이미지
전역 `onerror` fallback + 서버측 기본 이미지가 이미 적용됨(추가 배선 불필요). 썸네일 컨테이너는 `object-fit:cover`.

## 원칙
색상 절제(포인트 1색 + 상태색 + 중립, 무지개 금지) · 밀도 우선 · 인라인 스타일 지양(동적 width/색상만 허용) · 과도한 라운드/그림자/이모지 금지 · 한글 라벨(HIGH→높음).
