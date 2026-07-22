<?php
/**
 * 데이터 접근 범위(IDOR 방지) 헬퍼. 권한에 따라 SQL WHERE 조건을 생성한다.
 * 화면 숨김이 아니라 쿼리 자체에 범위를 강제하기 위한 공통 로직.
 */
class Scope
{
    /**
     * 현재 사용자가 볼 수 있는 프로젝트 조건.
     * project.view_all 권한이 있으면 전체(TRUE). 없으면 배정/담당 프로젝트만.
     *
     * @param string $alias projects 테이블 별칭 (예: 'p')
     * @return array [whereSql, params]  — whereSql 은 괄호로 감싼 조건
     */
    public static function projectWhere(string $alias = 'p'): array
    {
        if (Rbac::can('project.view_all')) {
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
        if (Rbac::can('project.view_all')) {
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
        if (Rbac::can('customer.manage') || Rbac::can('project.view_all') || Rbac::isRole('super_admin', 'accountant', 'site_manager')) {
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
