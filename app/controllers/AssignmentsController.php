<?php
/**
 * 프로젝트 기여도 합계(100 초과) 검증 실패 시 사용하는 예외.
 * 트랜잭션 롤백 트리거 겸 에러 메시지 전달용.
 */
class AssignmentContributionException extends RuntimeException
{
    public float $sum;

    public function __construct(float $sum)
    {
        $this->sum = $sum;
        parent::__construct('기여도 합계가 100%를 초과합니다. (저장 시도 후 합계 ' . number_format($sum, 2) . '%)');
    }
}

/**
 * 직원 배정 (T7). 프로젝트 상세(T6) 화면에서 폼으로 호출된다.
 * perm project.assign 은 라우터가 강제한다.
 */
class AssignmentsController
{
    /** 허용 역할 목록 — partials/assignment_form.php 가 select 옵션으로 재사용한다. */
    public const ROLES = ['현장책임자', '도장작업자', '보조작업자', '실측담당', '자재담당', '검수담당', '영업담당'];

    /**
     * 배정 서버 검증(테스트 가능 헬퍼): 통과 시 null, 실패 시 오류 메시지 반환.
     *  - 존재하지 않는 직원 거부
     *  - 비활성 직원 신규 배정(또는 담당 직원 교체) 거부 — 과거 비활성 직원의 기존 배정 이력은 보존
     *  - 동일 프로젝트·동일 직원 active 중복 배정 거부 (DB 백스톱: uq_assign_active_pair)
     */
    public static function validateAssignment(int $projectId, int $userId, string $status, ?array $existing): ?string
    {
        $user = Db::one("SELECT id, status FROM users WHERE id=:id AND deleted_at IS NULL", [':id' => $userId]);
        if (!$user) {
            return '존재하지 않는 직원입니다.';
        }
        if ($user['status'] !== 'active' && (!$existing || (int) $existing['user_id'] !== $userId)) {
            return '비활성(퇴사·휴직) 직원은 새로 배정할 수 없습니다. (기존 배정 이력은 보존됩니다)';
        }
        if ($status === 'active') {
            $params = [':pid' => $projectId, ':uid' => $userId];
            $sql = "SELECT 1 FROM project_assignments WHERE project_id=:pid AND user_id=:uid AND status='active'";
            if ($existing) {
                $sql .= " AND id != :self";
                $params[':self'] = (int) $existing['id'];
            }
            if (Db::val($sql, $params)) {
                return '이미 이 프로젝트에 배정 중(active)인 직원입니다. 기존 배정을 수정하거나 종료 후 다시 등록하세요.';
            }
        }
        return null;
    }

    public function save(): void
    {
        $id = Util::postInt('id', null);
        $projectId = Util::postInt('project_id');
        $userId = Util::postInt('user_id');
        $role = Util::postStr('role');
        $startDate = Util::nullIfEmpty(Util::postStr('start_date'));
        $endDate = Util::nullIfEmpty(Util::postStr('end_date'));
        $plannedHours = Util::postFloat('planned_hours', null);
        $contributionPct = Util::postFloat('contribution_pct', null);
        $status = Util::postStr('status', 'active');
        $memo = Util::nullIfEmpty(Util::postStr('memo'));

        if (!$projectId || !$userId || $role === '') {
            Response::error('프로젝트, 직원, 역할은 필수입니다.', 422);
        }
        if (!in_array($role, self::ROLES, true)) {
            Response::error('허용되지 않는 역할입니다: ' . $role, 422);
        }

        $project = Db::one(
            "SELECT id, project_no, name, contribution_mode FROM projects WHERE id = :id AND deleted_at IS NULL",
            [':id' => $projectId]
        );
        if (!$project) {
            Response::error('존재하지 않는 프로젝트입니다.', 422);
        }

        $existing = null;
        if ($id) {
            $existing = Db::one("SELECT * FROM project_assignments WHERE id = :id", [':id' => $id]);
            if (!$existing) {
                Response::error('배정 정보를 찾을 수 없습니다.', 404);
            }
        }
        // R3: 비활성 직원 신규 배정 거부 + 동일 프로젝트·직원 active 중복 배정 거부
        if (($err = self::validateAssignment((int) $projectId, (int) $userId, $status, $existing)) !== null) {
            Response::error($err, 422);
        }

        // R10: 배분 방식(main/ratio/role)과 무관하게 관리자 입력값을 존중한다.
        //  - 과거 main 모드가 입력을 폐기하고 100 을 강제 → "기여도가 무조건 100 저장" 운영 결함의 원인.
        //  - 자동 100 은 프론트 최초 배정 제안값으로만 사용(뷰), 서버는 입력값 그대로 저장.
        if ($contributionPct === null) {
            Response::error('기여도(%)를 입력하세요.', 422);
        }
        if ($contributionPct < 0 || $contributionPct > 100) {
            Response::error('기여도는 0~100 사이여야 합니다.', 422);
        }

        $data = [
            'project_id'       => $projectId,
            'user_id'          => $userId,
            'role'             => $role,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'planned_hours'    => $plannedHours,
            'contribution_pct' => $contributionPct,
            'status'           => $status,
            'memo'             => $memo,
        ];

        $before = null;
        try {
            $id = Db::transaction(function () use (&$before, $id, $projectId, $data) {
                if ($id) {
                    $before = Db::one("SELECT * FROM project_assignments WHERE id = :id", [':id' => $id]);
                    if (!$before) {
                        throw new RuntimeException('배정 정보를 찾을 수 없습니다.');
                    }
                    Db::update('project_assignments', $data, 'id = :id', [':id' => $id]);
                } else {
                    $id = Db::insert('project_assignments', $data);
                }

                $sum = (float) Db::val(
                    "SELECT COALESCE(SUM(contribution_pct),0) FROM project_assignments
                     WHERE project_id = :pid AND status != 'cancelled'",
                    [':pid' => $projectId]
                );
                if ($sum > 100.01) {
                    throw new AssignmentContributionException($sum);
                }
                return $id;
            });
        } catch (AssignmentContributionException $e) {
            Response::error($e->getMessage(), 422, ['sum' => round($e->sum, 2)]);
        } catch (PDOException $e) {
            // DB 백스톱: active 중복 배정 UNIQUE(uq_assign_active_pair) — 동시 요청 레이스 방어
            if (($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_assign_active_pair')) {
                Response::error('이미 이 프로젝트에 배정 중(active)인 직원입니다. 기존 배정을 수정하거나 종료 후 다시 등록하세요.', 422);
            }
            throw $e;
        }

        Audit::log($before ? 'assignment_update' : 'assignment_create', 'project_assignments', $id, $before, $data);

        // 신규 배정일 때만 알림 (수정은 알림 생략)
        if (!$before) {
            Notif::push(
                $userId,
                'assignment',
                '프로젝트 배정',
                ($project['project_no'] ?? '') . ' ' . ($project['name'] ?? '') . ' 프로젝트에 ' . $role . '(으)로 배정되었습니다.',
                'projects.show',
                ['id' => $projectId]
            );
        }

        $sumNow = (float) Db::val(
            "SELECT COALESCE(SUM(contribution_pct),0) FROM project_assignments WHERE project_id = :pid AND status != 'cancelled'",
            [':pid' => $projectId]
        );
        if (Response::wantsJson()) {
            Response::json(['id' => $id, 'contribution_sum' => round($sumNow, 2)]);
        }
        // 비-AJAX 제출(JS 미동작 등) 폴백 — 원시 JSON 노출 방지, 상세로 복귀(탭은 sessionStorage 복원)
        Response::redirect('projects.show', ['id' => $projectId],
            '배정이 저장되었습니다. (기여도 합계 ' . round($sumNow, 2) . '%)');
    }

    public function delete(): void
    {
        $id = Util::postInt('id');
        if (!$id) {
            Response::error('id가 필요합니다.', 422);
        }
        $row = Db::one("SELECT * FROM project_assignments WHERE id = :id", [':id' => $id]);
        if (!$row) {
            Response::error('배정 정보를 찾을 수 없습니다.', 404);
        }
        Db::run("DELETE FROM project_assignments WHERE id = :id", [':id' => $id]);
        Audit::log('assignment_delete', 'project_assignments', $id, $row, null);

        $sum = (float) Db::val(
            "SELECT COALESCE(SUM(contribution_pct),0) FROM project_assignments WHERE project_id = :pid AND status != 'cancelled'",
            [':pid' => $row['project_id']]
        );
        if (Response::wantsJson()) {
            Response::json(['id' => $id, 'contribution_sum' => round($sum, 2)]);
        }
        // 비-AJAX 제출 폴백 — 삭제 후 원시 JSON 이 화면에 노출되던 문제 수정(상세로 복귀, 탭은 sessionStorage 복원)
        Response::redirect('projects.show', ['id' => (int) $row['project_id']],
            '배정이 삭제되었습니다. (기여도 합계 ' . round($sum, 2) . '%)');
    }
}
