<?php
/**
 * 직원 관리 — 목록/상세(perm staff.view), 생성/수정/비번초기화/활성토글(perm staff.manage 는 라우터가 강제).
 */
class StaffController
{
    /** 목록: 검색/부서/역할/재직상태 필터 + 페이지네이션. */
    public function index(): void
    {
        $q      = Util::str('q');
        $deptId = Util::int('department_id', 0) ?: 0;
        $roleId = Util::int('role_id', 0) ?: 0;
        $status = Util::str('status');
        $page   = max(1, Util::int('page', 1) ?: 1);

        $where  = ['u.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(u.name LIKE :q1 OR u.login_id LIKE :q2 OR u.email LIKE :q3)';
            $params[':q1'] = "%$q%";
            $params[':q2'] = "%$q%";
            $params[':q3'] = "%$q%";
        }
        if ($deptId > 0) {
            $where[] = 'u.department_id = :dept';
            $params[':dept'] = $deptId;
        }
        if ($roleId > 0) {
            $where[] = 'u.role_id = :role';
            $params[':role'] = $roleId;
        }
        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'u.status = :status';
            $params[':status'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::val("SELECT COUNT(*) FROM users u WHERE $whereSql", $params);
        $per   = (int) ($GLOBALS['config']['PAGE_SIZE'] ?? 20);
        $pg    = Util::paginate($total, $page, $per);

        $rows = Db::all(
            "SELECT u.id, u.login_id, u.name, u.position, u.status, u.last_login_at, u.role_key,
                    d.name AS department_name, r.name AS role_name
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE $whereSql
             ORDER BY u.name ASC
             LIMIT {$pg['per']} OFFSET {$pg['offset']}",
            $params
        );

        View::render('staff/index', [
            'title'       => '직원 관리',
            'rows'        => $rows,
            'pg'          => $pg,
            'q'           => $q,
            'departmentId'=> $deptId,
            'roleId'      => $roleId,
            'status'      => $status,
            'departments' => Db::all('SELECT id, name FROM departments ORDER BY sort_order'),
            'roles'       => Db::all('SELECT id, role_key, name FROM roles ORDER BY id'),
        ]);
    }

    /** 상세: 정보 + 담당 프로젝트 요약 + 성과 링크. */
    public function show(): void
    {
        $id = Util::int('id', 0) ?: 0;
        $staff = Db::one(
            "SELECT u.*, d.name AS department_name, r.name AS role_name
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.deleted_at IS NULL",
            [':id' => $id]
        );
        if (!$staff) {
            http_response_code(404);
            View::renderError(404, '직원을 찾을 수 없음', '요청한 직원 정보를 찾을 수 없습니다.');
            return;
        }

        $projectParams = [':u1' => $id, ':u2' => $id, ':u3' => $id, ':u4' => $id];
        $projects = Db::all(
            "SELECT DISTINCT p.id, p.project_no, p.name, p.status, p.contract_amount, p.progress, p.created_at
             FROM projects p
             LEFT JOIN project_assignments pa ON pa.project_id = p.id AND pa.user_id = :u1
             WHERE p.deleted_at IS NULL AND (p.sales_user_id = :u2 OR p.site_manager_id = :u3 OR pa.user_id = :u4)
             ORDER BY p.created_at DESC
             LIMIT 10",
            $projectParams
        );
        $projectCount = (int) Db::val(
            "SELECT COUNT(DISTINCT p.id) FROM projects p
             LEFT JOIN project_assignments pa ON pa.project_id = p.id AND pa.user_id = :u1
             WHERE p.deleted_at IS NULL AND (p.sales_user_id = :u2 OR p.site_manager_id = :u3 OR pa.user_id = :u4)",
            $projectParams
        );

        View::render('staff/show', [
            'title'        => '직원 상세',
            'staff'        => $staff,
            'projects'     => $projects,
            'projectCount' => $projectCount,
            'canViewPerf'  => Scope::canViewUserPerformance((int) $id),
        ]);
    }

    /** 생성/수정 폼. */
    public function form(): void
    {
        $id    = Util::int('id', 0) ?: 0;
        $staff = null;
        if ($id > 0) {
            $staff = Db::one('SELECT * FROM users WHERE id=:id AND deleted_at IS NULL', [':id' => $id]);
            if (!$staff) {
                http_response_code(404);
                View::renderError(404, '직원을 찾을 수 없음', '요청한 직원 정보를 찾을 수 없습니다.');
                return;
            }
        }
        View::render('staff/form', [
            'title'       => $id > 0 ? '직원 정보 수정' : '직원 등록',
            'staff'       => $staff,
            'departments' => Db::all('SELECT id, name FROM departments ORDER BY sort_order'),
            'roles'       => Db::all('SELECT id, role_key, name FROM roles ORDER BY id'),
        ]);
    }

    /** 생성/수정 저장. 생성 시 초기 비밀번호 발급 + must_change_password=1. */
    public function save(): void
    {
        $id           = Util::postInt('id', 0) ?: 0;
        $loginId      = Util::postStr('login_id');
        $email        = Util::postStr('email');
        $name         = Util::postStr('name');
        $phone        = Util::nullIfEmpty(Util::postStr('phone'));
        $departmentId = Util::postInt('department_id', null);
        $position     = Util::nullIfEmpty(Util::postStr('position'));
        $roleId       = Util::postInt('role_id', 0) ?: 0;
        $hireDate     = Util::nullIfEmpty(Util::postStr('hire_date'));
        $status       = Util::postStr('status', 'active');
        $initialPw    = Util::postStr('initial_password');

        if ($loginId === '' || $email === '' || $name === '' || $roleId <= 0) {
            Response::error('아이디, 이메일, 이름, 역할은 필수입니다.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('이메일 형식이 올바르지 않습니다.', 422);
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $role = Db::one('SELECT id, role_key FROM roles WHERE id=:id', [':id' => $roleId]);
        if (!$role) {
            Response::error('선택한 역할을 찾을 수 없습니다.', 422);
        }

        $dupParams = [':lid' => $loginId, ':email' => $email];
        $dupSql = 'SELECT id FROM users WHERE (login_id=:lid OR email=:email) AND deleted_at IS NULL';
        if ($id > 0) {
            $dupSql .= ' AND id != :id';
            $dupParams[':id'] = $id;
        }
        if (Db::one($dupSql, $dupParams)) {
            Response::error('이미 사용 중인 아이디 또는 이메일입니다.', 422);
        }

        $tempPassword = null;

        if ($id > 0) {
            $before = Db::one('SELECT * FROM users WHERE id=:id AND deleted_at IS NULL', [':id' => $id]);
            if (!$before) {
                Response::error('직원을 찾을 수 없습니다.', 404);
            }
            // 슈퍼관리자 보호: 역할 강등/비활성화 금지
            if ($before['role_key'] === 'super_admin' && $role['role_key'] !== 'super_admin') {
                Response::error('슈퍼관리자 계정의 역할은 변경할 수 없습니다.', 403);
            }
            if ($before['role_key'] === 'super_admin' && $status !== 'active') {
                Response::error('슈퍼관리자 계정은 비활성화할 수 없습니다.', 403);
            }
            $data = [
                'login_id'      => $loginId,
                'email'         => $email,
                'name'          => $name,
                'phone'         => $phone,
                'department_id' => $departmentId ?: null,
                'position'      => $position,
                'role_id'       => $roleId,
                'role_key'      => $role['role_key'],
                'hire_date'     => $hireDate,
                'status'        => $status,
            ];
            Db::update('users', $data, 'id=:id', [':id' => $id]);
            Audit::log('update', 'users', $id, $before, $data);
        } else {
            $tempPassword = $initialPw !== '' ? $initialPw : self::randomPassword();
            $data = [
                'login_id'             => $loginId,
                'email'                => $email,
                'password_hash'        => password_hash($tempPassword, PASSWORD_DEFAULT),
                'name'                 => $name,
                'phone'                => $phone,
                'department_id'        => $departmentId ?: null,
                'position'             => $position,
                'role_id'              => $roleId,
                'role_key'             => $role['role_key'],
                'hire_date'            => $hireDate,
                'status'               => $status,
                'must_change_password' => 1,
                'failed_attempts'      => 0,
            ];
            $id = Db::insert('users', $data);
            $logData = $data;
            unset($logData['password_hash']);
            Audit::log('create', 'users', $id, null, $logData);
        }

        Response::json(['id' => $id, 'temp_password' => $tempPassword]);
    }

    /** 비밀번호 초기화: 랜덤(또는 입력) 발급 + must_change_password=1. */
    public function resetPassword(): void
    {
        $id  = Util::postInt('id', 0) ?: 0;
        $new = Util::postStr('new_password');

        $user = Db::one('SELECT * FROM users WHERE id=:id AND deleted_at IS NULL', [':id' => $id]);
        if (!$user) {
            Response::error('직원을 찾을 수 없습니다.', 404);
        }

        $temp = $new !== '' ? $new : self::randomPassword();
        Db::update('users', [
            'password_hash'         => password_hash($temp, PASSWORD_DEFAULT),
            'must_change_password'  => 1,
            'failed_attempts'       => 0,
            'locked_until'          => null,
        ], 'id=:id', [':id' => $id]);

        Audit::log('reset_password', 'users', $id, null, ['login_id' => $user['login_id']]);
        Notif::push(
            $id,
            'password_reset',
            '비밀번호가 초기화되었습니다',
            '관리자가 임시 비밀번호를 발급했습니다. 로그인 후 반드시 비밀번호를 변경하세요.',
            'password.change'
        );

        Response::json(['id' => $id, 'temp_password' => $temp]);
    }

    /** 활성/비활성 토글. 본인 계정·슈퍼관리자 계정은 보호. */
    public function toggleActive(): void
    {
        $id = Util::postInt('id', 0) ?: 0;
        $user = Db::one('SELECT * FROM users WHERE id=:id AND deleted_at IS NULL', [':id' => $id]);
        if (!$user) {
            Response::error('직원을 찾을 수 없습니다.', 404);
        }
        if ($id === Auth::id()) {
            Response::error('본인 계정의 상태는 변경할 수 없습니다.', 403);
        }
        if ($user['role_key'] === 'super_admin') {
            Response::error('슈퍼관리자 계정은 비활성화할 수 없습니다.', 403);
        }

        $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
        Db::update('users', ['status' => $newStatus], 'id=:id', [':id' => $id]);
        Audit::log('toggle_active', 'users', $id, ['status' => $user['status']], ['status' => $newStatus]);

        Response::json(['id' => $id, 'status' => $newStatus]);
    }

    /** 임시 비밀번호 생성기(초기발급/초기화 공용). */
    private static function randomPassword(int $len = 10): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }
}
