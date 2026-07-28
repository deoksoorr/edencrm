<?php
/**
 * R16 — 직원별 세부 권한(영업·현장·분석 × 읽기·쓰기·삭제).
 *
 * 설계 요지
 *  - 권한 원장은 employee_permissions(직원 × 리소스 1행) 정규화 테이블 하나뿐이다.
 *  - 기존 Rbac::can(perm_key) 호출부는 그대로 두고 REGISTRY 로 (리소스, 액션)에 사상한다.
 *    → 라우터에 이미 걸린 게이트를 전부 재사용하므로 누락 위험이 없다.
 *  - 기본 정책은 '거부'다. 미등록 리소스 키·미등록 액션·행 부재는 전부 false.
 *  - ADMIN_ONLY perm 은 리소스로 사상하지 않는다 = 일반 직원에게 부여할 수단 자체가 없다.
 *  - 최고운영자 판정은 역할 컬럼(roles.role_key)만 본다. 특정 user id 하드코딩 금지.
 *    세션의 role_key 는 표시용이며 판정에 쓰지 않는다(쿠키·세션 위조 차단).
 */
class Perm
{
    public const SUPER_ROLE = 'super_admin';

    /** 읽기 전용 리소스(쓰기·삭제 개념이 없는 화면) — 저장 시 강제 0. */
    private const READ_ONLY_RESOURCES = ['analytics.reports'];

    /** user_id => [resource_key => ['can_read'=>bool,...]] — 요청 단위 캐시. */
    private static array $cache = [];

    // ────────────────────────────────────────────────────────────────
    // 매트릭스 정의 — 화면 표시명과 리소스 키를 분리한다.
    // 메뉴명이 바뀌어도 resource_key 는 불변이므로 기존 권한이 깨지지 않는다.
    // ────────────────────────────────────────────────────────────────
    public static function resources(): array
    {
        return [
            // ── 영업 ──
            'sales.customers'    => ['section' => 'sales', 'label' => '고객 CRM',   'order' => 10],
            'sales.leads'        => ['section' => 'sales', 'label' => '영업기회',    'order' => 20],
            'sales.quotes'       => ['section' => 'sales', 'label' => '견적',       'order' => 30],
            'sales.contracts'    => ['section' => 'sales', 'label' => '계약',       'order' => 40],
            // ── 현장 ──
            'field.projects'     => ['section' => 'field', 'label' => '프로젝트',    'order' => 10],
            'field.process_board'=> ['section' => 'field', 'label' => '공정 보드',   'order' => 20],
            'field.schedules'    => ['section' => 'field', 'label' => '현장 일정',   'order' => 30],
            'field.worklogs'     => ['section' => 'field', 'label' => '작업일지',    'order' => 40],
            'field.costs'        => ['section' => 'field', 'label' => '비용·원가',   'order' => 50],
            // ── 분석(읽기 전용) ──
            // R16-1: 프로젝트·고객 열람 범위와의 결합을 제거했다(각 리소스의 읽기 권한이 스스로 결정).
            // 이 권한은 이제 리포트·손익 화면 접근만을 뜻한다.
            'analytics.reports'  => ['section' => 'analytics', 'label' => '리포트·손익',
                                     'order' => 10, 'read_only' => true,
                                     'note'  => '전사 매출·순이익 리포트를 열람합니다. 쓰기·삭제 개념이 없습니다.'],
        ];
    }

    /** 섹션 표시명. */
    public static function sections(): array
    {
        return ['sales' => '영업 권한', 'field' => '현장 권한', 'analytics' => '분석 권한'];
    }

    /**
     * perm_key => [resource_key, action].
     * 여기에 없는 perm_key 는 super_admin 외 전원 거부된다(기본 거부).
     */
    public static function registry(): array
    {
        return [
            // 영업 — 고객 CRM
            'customer.view'        => ['sales.customers', 'read'],
            'customer.export'      => ['sales.customers', 'read'],
            'customer.manage'      => ['sales.customers', 'write'],
            'customer.delete'      => ['sales.customers', 'delete'],
            // 영업 — 영업기회
            'pipeline.view'        => ['sales.leads', 'read'],
            'pipeline.manage'      => ['sales.leads', 'write'],
            'pipeline.delete'      => ['sales.leads', 'delete'],
            // 영업 — 견적
            'quote.view'           => ['sales.quotes', 'read'],
            'quote.manage'         => ['sales.quotes', 'write'],
            'quote.delete'         => ['sales.quotes', 'delete'],
            // 영업 — 계약
            'contract.view'        => ['sales.contracts', 'read'],
            'contract.manage'      => ['sales.contracts', 'write'],
            'contract.delete'      => ['sales.contracts', 'delete'],
            // 현장 — 프로젝트
            'project.view_all'     => ['field.projects', 'read'],
            'project.view_assigned'=> ['field.projects', 'read'],
            'project.manage'       => ['field.projects', 'write'],
            'project.assign'       => ['field.projects', 'write'],
            'project.delete'       => ['field.projects', 'delete'],
            // 현장 — 공정 보드
            'process.view'         => ['field.process_board', 'read'],
            'process.move'         => ['field.process_board', 'write'],
            'process.delete'       => ['field.process_board', 'delete'],
            // 현장 — 일정
            'schedule.view_all'    => ['field.schedules', 'read'],
            'schedule.manage'      => ['field.schedules', 'write'],
            'schedule.delete'      => ['field.schedules', 'delete'],
            // 현장 — 작업일지
            'worklog.view_all'     => ['field.worklogs', 'read'],
            'worklog.create'       => ['field.worklogs', 'write'],
            'worklog.confirm'      => ['field.worklogs', 'write'],
            'worklog.delete'       => ['field.worklogs', 'delete'],
            // 현장 — 비용
            'cost.view'            => ['field.costs', 'read'],
            'cost.manage'          => ['field.costs', 'write'],
            'cost.delete'          => ['field.costs', 'delete'],
            // 분석 — 리포트·손익
            'report.view'          => ['analytics.reports', 'read'],
            'report.export'        => ['analytics.reports', 'read'],
            'finance.view'         => ['analytics.reports', 'read'],
        ];
    }

    /**
     * 최고운영자 전용 perm — 개별 직원에게 부여할 수단이 없다.
     * 정산·전직원성과·출근통계·보너스·직원·설정·감사·휴지통.
     */
    public static function adminOnly(): array
    {
        return [
            'payment.manage'      => '입금·정산',
            'performance.view_all'=> '전 직원 성과·기여도',
            'attendance.manage'   => '근태 마킹',
            'bonus.manage'        => '보너스 관리',
            'staff.view'          => '직원 목록',
            'staff.manage'        => '직원 계정 관리',
            'settings.manage'     => '시스템 설정',
            'audit.view'          => '감사 로그',
            'trash.manage'        => '휴지통 조회·복원·완전삭제',
        ];
    }

    public static function isAdminOnly(string $permKey): bool
    {
        return isset(self::adminOnly()[$permKey]);
    }

    // ────────────────────────────────────────────────────────────────
    // 판정
    // ────────────────────────────────────────────────────────────────

    /**
     * 최고운영자 여부. 역할 컬럼만 신뢰한다.
     * $user 미지정 시 Auth::user() (매 요청 DB 재조회 + status 검증을 거친 행).
     */
    public static function isSuperAdmin(?array $user = null): bool
    {
        if ($user === null) {
            $user = class_exists('Auth') ? Auth::user() : null;
        }
        if (!is_array($user)) {
            return false;
        }
        // Auth::user() 는 roles.role_key 를 'role' 별칭으로 조인해 온다.
        return ($user['role'] ?? null) === self::SUPER_ROLE;
    }

    /** 특정 직원의 리소스별 권한 맵. 요청 단위 캐시. */
    public static function of(int $userId): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }
        $map = [];
        try {
            $rows = Db::all(
                "SELECT resource_key, can_read, can_write, can_delete
                   FROM employee_permissions WHERE user_id = :u",
                [':u' => $userId]
            );
            foreach ($rows as $r) {
                $map[$r['resource_key']] = [
                    'read'   => (int) $r['can_read'] === 1,
                    'write'  => (int) $r['can_write'] === 1,
                    'delete' => (int) $r['can_delete'] === 1,
                ];
            }
        } catch (\Throwable $e) {
            // 조회 실패는 '권한 없음'으로 취급한다(fail-closed).
            error_log('[perm] ' . $e->getMessage());
            $map = [];
        }
        return self::$cache[$userId] = $map;
    }

    /**
     * 직원 × 리소스 × 액션 판정. 기본 거부.
     * super_admin 은 리소스·액션과 무관하게 항상 true.
     */
    public static function can(int $userId, string $resourceKey, string $action): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if (self::isSuperAdminId($userId)) {
            return true;
        }
        if (!in_array($action, ['read', 'write', 'delete'], true)) {
            return false;                       // 미등록 액션 → 거부
        }
        if (!isset(self::resources()[$resourceKey])) {
            return false;                       // 미등록 리소스 키(오타 포함) → 거부
        }
        $perms = self::of($userId);
        return !empty($perms[$resourceKey][$action]);
    }

    /** user_id 로 super_admin 여부 판정(현재 로그인 사용자면 캐시된 행 재사용). */
    private static function isSuperAdminId(int $userId): bool
    {
        if (class_exists('Auth') && Auth::check() && Auth::id() === $userId) {
            return self::isSuperAdmin(Auth::user());
        }
        $row = Db::one(
            "SELECT r.role_key FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1",
            [':id' => $userId]
        );
        return $row && $row['role_key'] === self::SUPER_ROLE;
    }

    // ────────────────────────────────────────────────────────────────
    // 저장
    // ────────────────────────────────────────────────────────────────

    /**
     * 종속 규칙 정규화.
     *  - 쓰기 또는 삭제가 있으면 읽기 자동 ON
     *  - 읽기 전용 리소스는 쓰기·삭제 강제 0
     * 읽기 없이 쓰기/삭제만 있는 조합은 저장되지 않는다(읽기를 켜서 해소).
     */
    public static function normalize(array $row, ?string $resourceKey = null): array
    {
        $r = !empty($row['can_read']) ? 1 : 0;
        $w = !empty($row['can_write']) ? 1 : 0;
        $d = !empty($row['can_delete']) ? 1 : 0;

        if ($resourceKey !== null && in_array($resourceKey, self::READ_ONLY_RESOURCES, true)) {
            $w = 0;
            $d = 0;
        }
        if ($w === 1 || $d === 1) {
            $r = 1;                              // 종속 규칙 1·2
        }
        return ['can_read' => $r, 'can_write' => $w, 'can_delete' => $d];
    }

    /**
     * 직원 권한 전량 교체 저장. 매트릭스에 없는 리소스 키는 조용히 무시한다.
     * 모든 값이 0인 리소스는 행을 남기지 않는다(기본 거부이므로 동일 의미).
     *
     * @param array $matrix resource_key => ['can_read'=>..,'can_write'=>..,'can_delete'=>..]
     * @return array 감사 로그용 ['before'=>..., 'after'=>...]
     * @throws RuntimeException super_admin 대상이거나 대상 직원이 없을 때
     */
    public static function save(int $userId, array $matrix, int $actorId): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('대상 직원이 올바르지 않습니다.');
        }
        if (self::isSuperAdminId($userId)) {
            // 최고운영자는 항상 전체 권한 — 권한 행을 만들지 않는다(자기 권한 제거 방지).
            throw new RuntimeException('최고운영자는 항상 전체 권한을 보유하므로 별도 설정할 수 없습니다.');
        }

        $defs   = self::resources();
        $before = self::snapshot($userId);
        $after  = [];

        foreach ($matrix as $key => $vals) {
            if (!isset($defs[$key]) || !is_array($vals)) {
                continue;                        // 미등록 키 → 거부(저장하지 않음)
            }
            $n = self::normalize($vals, $key);
            if ($n['can_read'] === 0 && $n['can_write'] === 0 && $n['can_delete'] === 0) {
                continue;                        // 전부 0 → 행 미생성
            }
            $after[$key] = $n;
        }

        $pdo = Db::pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) { $pdo->beginTransaction(); }
        try {
            Db::run("DELETE FROM employee_permissions WHERE user_id = :u", [':u' => $userId]);
            foreach ($after as $key => $n) {
                Db::insert('employee_permissions', [
                    'user_id'      => $userId,
                    'section'      => $defs[$key]['section'],
                    'resource_key' => $key,
                    'can_read'     => $n['can_read'],
                    'can_write'    => $n['can_write'],
                    'can_delete'   => $n['can_delete'],
                    'updated_by'   => $actorId ?: null,
                ]);
            }
            if ($ownTx) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        self::reset();
        if (class_exists('Rbac')) { Rbac::reset(); }
        return ['before' => $before, 'after' => $after];
    }

    /** 감사 로그·UI 표시용 현재 권한 스냅샷. */
    public static function snapshot(int $userId): array
    {
        $out = [];
        foreach (self::of($userId) as $key => $v) {
            $out[$key] = [
                'can_read'   => $v['read'] ? 1 : 0,
                'can_write'  => $v['write'] ? 1 : 0,
                'can_delete' => $v['delete'] ? 1 : 0,
            ];
        }
        ksort($out);
        return $out;
    }

    // ────────────────────────────────────────────────────────────────
    // 차단
    // ────────────────────────────────────────────────────────────────

    /**
     * 최고운영자가 아니면 감사 로그를 남기고 403 으로 끊는다.
     * 내부 구조·SQL·파일 경로를 응답에 노출하지 않는다.
     */
    public static function requireSuperAdmin(string $context): void
    {
        if (self::isSuperAdmin()) {
            return;
        }
        Audit::log('superadmin_denied', 'permission', null, null, [
            'context' => $context,
            'route'   => $_GET['r'] ?? '',
        ]);
        if (Response::wantsJson()) {
            Response::error('이 작업은 최고운영자만 실행할 수 있습니다.', 403);
        }
        http_response_code(403);
        View::renderError(403, '접근 권한 없음', '이 기능은 최고운영자만 사용할 수 있습니다.');
        exit;
    }

    public static function reset(): void
    {
        self::$cache = [];
    }
}
