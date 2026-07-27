<?php
/**
 * 사이드바 메뉴 정의. 권한(perm)이 있는 항목만 노출한다.
 * 화면 숨김은 UX 편의일 뿐, 실제 차단은 라우터의 Rbac::require 가 담당한다.
 */
class Nav
{
    /** [section => [ [route, label, perm(optional), icon] ... ] ] */
    public static function items(): array
    {
        return [
            '' => [
                ['home', '대시보드', null, 'grid'],
            ],
            '영업' => [
                ['customers.index', '고객 CRM', 'customer.view', 'users'],
                ['pipeline.index', '영업 파이프라인', 'pipeline.view', 'columns'],
                ['quotes.index', '견적', 'quote.view', 'file'],
                ['contracts.index', '계약', 'contract.view', 'check'],
            ],
            '현장' => array_values(array_filter([
                ['projects.index', '프로젝트', null, 'briefcase'],
                ['process.board', '공정 보드', null, 'trello'],
                ['schedule.index', '일정', null, 'calendar'],
                Settings::enabled('feature_worklog') ? ['worklogs.index', '작업일지', null, 'book'] : null,
            ])),
            '분석' => [
                ['performance.index', '직원 성과', null, 'trending'],
                ['halfyear.index', '반기 보너스 지급 현황', null, 'bar'],
                ['reports.index', '리포트', 'report.view', 'bar'],
            ],
            '관리' => [
                ['staff.index', '직원 관리', 'staff.view', 'id'],
                ['targets.index', '목표 관리', null, 'target'], // R9: 직원도 본인 공개 목표 열람(컨트롤러 스코프)
                ['settings.index', '시스템 설정', 'settings.manage', 'settings'],
                ['audit.index', '감사 로그', 'audit.view', 'shield'],
            ],
        ];
    }

    /** 현재 라우트가 속한 최상위 그룹 판단용. */
    public static function isActive(string $route, string $current): bool
    {
        $base = explode('.', $route)[0];
        $curBase = explode('.', $current)[0];
        return $base === $curBase;
    }

    /** 간단한 인라인 SVG 아이콘(외부 의존 없음). */
    public static function icon(string $key): string
    {
        $p = [
            'grid'   => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
            'users'  => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'columns'=> '<path d="M12 3v18"/><rect x="3" y="3" width="18" height="18" rx="1"/>',
            'file'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
            'check'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'briefcase'=> '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            'trello' => '<rect x="3" y="3" width="18" height="18" rx="2"/><rect x="7" y="7" width="3" height="9"/><rect x="14" y="7" width="3" height="5"/>',
            'calendar'=> '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'book'   => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            'trending'=> '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
            'bar'    => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
            'id'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M15 8h3M15 12h3M7 16h10"/>',
            'settings'=> '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
        ];
        $body = $p[$key] ?? $p['grid'];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">' . $body . '</svg>';
    }
}
