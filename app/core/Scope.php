<?php
/**
 * 데이터 접근 범위(IDOR 방지) 헬퍼. 권한에 따라 SQL WHERE 조건을 생성한다.
 * 화면 숨김이 아니라 쿼리 자체에 범위를 강제하기 위한 공통 로직.
 */
class Scope
{
    /**
     * 프로젝트 '전체 열람' 판정(R16 신설).
     *
     * R16 이후 project.view_all 과 project.view_assigned 는 둘 다 (field.projects, read) 로 사상되므로
     * Rbac::can('project.view_all') 로는 전체/배정 구분이 불가능하다 — 읽기 권한자 전원이 전체가 돼버린다.
     * 그래서 열람 '범위'는 읽기 권한이 아니라 전사 데이터 열람 권한인 분석(리포트·손익) 읽기로 판정한다.
     * 이 기준은 기존 역할 매핑과 1:1 로 일치한다(database/seed_core.sql):
     *   - view_all 보유    = super_admin · sales_manager · accountant → 전부 리포트/손익 읽기 보유
     *   - view_assigned 보유 = site_manager · staff                  → 리포트/손익 읽기 없음
     * 즉 라우터 게이트(읽기)는 넓히되 행 범위는 기존과 동일하게 유지한다.
     */
    public static function canViewAllProjects(): bool
    {
        if (Perm::isSuperAdmin()) {
            return true;
        }
        $uid = (int) Auth::id();
        return $uid > 0 && Perm::can($uid, 'analytics.reports', 'read');
    }

    /**
     * 현재 사용자가 볼 수 있는 프로젝트 조건.
     * 전체 열람(canViewAllProjects)이면 전체(TRUE). 없으면 배정/담당 프로젝트만.
     *
     * @param string $alias projects 테이블 별칭 (예: 'p')
     * @return array [whereSql, params]  — whereSql 은 괄호로 감싼 조건
     */
    public static function projectWhere(string $alias = 'p'): array
    {
        if (self::canViewAllProjects()) {
            return ['1=1', []];
        }
        $uid = Auth::id();
        $sql = "($alias.sales_user_id = :sc_uid1
                 OR $alias.site_manager_id = :sc_uid2
                 OR EXISTS (SELECT 1 FROM project_assignments sca WHERE sca.project_id = $alias.id AND sca.user_id = :sc_uid3))";
        return [$sql, [':sc_uid1' => $uid, ':sc_uid2' => $uid, ':sc_uid3' => $uid]];
    }

    /** 특정 프로젝트에 현재 사용자가 접근 가능한지(상세/수정 진입 가드). */
    public static function canAccessProject(int $projectId): bool
    {
        if (self::canViewAllProjects()) {
            return Db::val("SELECT 1 FROM projects WHERE id=:id AND deleted_at IS NULL", [':id' => $projectId]) !== null;
        }
        $uid = Auth::id();
        return Db::val(
            "SELECT 1 FROM projects p WHERE p.id=:id AND p.deleted_at IS NULL AND (
                p.sales_user_id=:u1 OR p.site_manager_id=:u2
                OR EXISTS(SELECT 1 FROM project_assignments a WHERE a.project_id=p.id AND a.user_id=:u3))",
            [':id' => $projectId, ':u1' => $uid, ':u2' => $uid, ':u3' => $uid]
        ) !== null;
    }

    /**
     * 고객 접근 범위. customer.view 는 라우터가 이미 확인.
     * 영업담당 제한이 필요한 역할(예: staff)은 자기 담당 고객만 — 단 현재 모델상
     * customer.view 를 가진 역할(sales_manager, site_manager, accountant, super)은 전체 열람 허용.
     */
    public static function customerWhere(string $alias = 'c'): array
    {
        // 전체 고객 열람 권한군: customer.manage 또는 report/finance 계열 보유 시 전체
        // R16: project.view_all(=field.projects read) 대신 canViewAllProjects() — 프로젝트 읽기만 가진
        //      직원에게 전체 고객이 열리던 범위 확대를 차단한다(기존 역할 기준 결과는 동일).
        if (Rbac::can('customer.manage') || self::canViewAllProjects() || Rbac::isRole('super_admin', 'accountant', 'site_manager')) {
            return ['1=1', []];
        }
        // 그 외에는 본인 담당 고객만
        return ["$alias.sales_user_id = :sc_cuid", [':sc_cuid' => Auth::id()]];
    }

    /**
     * 성과/급여성 데이터 열람 대상 판단.
     * performance.view_all 이 없으면 본인 것만.
     */
    public static function canViewUserPerformance(int $targetUserId): bool
    {
        return Rbac::can('performance.view_all') || $targetUserId === Auth::id();
    }
}
