<?php
/**
 * 데이터 접근 범위(IDOR 방지) 헬퍼. 권한에 따라 SQL WHERE 조건을 생성한다.
 * 화면 숨김이 아니라 쿼리 자체에 범위를 강제하기 위한 공통 로직.
 */
class Scope
{
    /**
     * 프로젝트 '전체 열람' 판정.
     *
     * R16-1: 열람 범위는 그 리소스 자신의 읽기 권한이 정한다.
     *
     * 이전에는 분석(리포트·손익) 읽기로 범위를 판정했다. 기존 역할과 1:1 로 맞아
     * 마이그레이션 시점의 가시 범위는 보존됐지만, 실제로는 두 가지가 잘못돼 있었다.
     *   ① 매트릭스에서 '프로젝트 읽기'를 부여해도 보이지 않았다 — 사장이 화면에서 켠 권한이
     *      실제로 동작하지 않으니 권한 설정 UI 자체가 거짓말이 된다.
     *   ② 리포트 권한을 주면 프로젝트·고객까지 함께 열리는 숨은 결합이 생겼다.
     *
     * 배정 기준 축소는 '권한은 있는데 남의 현장은 안 보인다'는 별개 개념이었으나,
     * R16 이후에는 사장이 직원별로 읽기를 켜고 끄는 것으로 통제한다(요청서 3~4절).
     * 읽기를 주지 않으면 아예 목록·상세·API 전부가 차단되므로 통제력은 오히려 더 강하다.
     */
    public static function canViewAllProjects(): bool
    {
        if (Perm::isSuperAdmin()) {
            return true;
        }
        // project.view_all / project.view_assigned 는 둘 다 (field.projects, read) 로 사상된다.
        return Rbac::can('project.view_all');
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
        // R16-1: 고객 범위는 고객 자신의 읽기 권한(customer.view = sales.customers read)이 정한다.
        //        역할 기반 판정(isRole)과 프로젝트/분석 권한 결합을 제거 — 매트릭스가 유일한 통제 지점.
        if (Perm::isSuperAdmin() || Rbac::can('customer.view')) {
            return ['1=1', []];
        }
        // 읽기 권한이 없으면 라우터가 이미 403 이지만, 방어적으로 본인 담당 고객만 남긴다.
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
