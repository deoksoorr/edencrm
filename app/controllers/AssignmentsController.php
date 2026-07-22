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
    private const ROLES = ['현장책임자', '도장작업자', '보조작업자', '실측담당', '자재담당', '검수담당', '영업담당'];

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
        if (!Db::val("SELECT 1 FROM users WHERE id=:id AND deleted_at IS NULL", [':id' => $userId])) {
            Response::error('존재하지 않는 직원입니다.', 422);
        }

        // 배분 방식: main(주담당 100) / ratio(직접입력) / role(역할기본배분 — 최소지원, ratio 와 동일 취급)
        $mode = $project['contribution_mode'];
        if ($mode === 'main') {
            $contributionPct = 100.0;
        } elseif ($contributionPct === null) {
            Response::error('기여도(%)를 입력하세요.', 422);
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
        Response::json(['id' => $id, 'contribution_sum' => round($sumNow, 2)]);
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
        Response::json(['id' => $id, 'contribution_sum' => round($sum, 2)]);
    }
}
