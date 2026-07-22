<?php
/**
 * 비용(원가) 등록/수정/삭제 — perm cost.manage.
 * 입력 UI는 프로젝트 상세(T6)에서 폼/모달로 호출한다. 여기서는 저장 로직만 제공.
 * 프로젝트의 실제원가(projects.actual_cost)는 저장하지 않고, 조회 시 costs 를 SUM 하여 계산한다(Calc 사용부는 조회측 책임).
 */
class CostsController
{
    private const TYPES = ['estimate', 'actual'];

    /** {id?, project_id, type, category, amount, spent_date, memo} — id 있으면 수정. */
    public function save(): void
    {
        $id        = Util::postInt('id', 0) ?: 0;
        $projectId = Util::postInt('project_id', 0) ?: 0;
        $type      = Util::postStr('type');
        $category  = Util::postStr('category');
        $amount    = Util::postFloat('amount', 0.0) ?? 0.0;
        $spentDate = Util::nullIfEmpty(Util::postStr('spent_date'));
        $memo      = Util::nullIfEmpty(Util::postStr('memo'));

        if ($projectId <= 0) {
            Response::error('project_id 가 필요합니다.', 422);
        }
        $project = Db::one('SELECT id FROM projects WHERE id=:id AND deleted_at IS NULL', [':id' => $projectId]);
        if (!$project) {
            Response::error('프로젝트를 찾을 수 없습니다.', 404);
        }
        if (!Scope::canAccessProject($projectId)) {
            Response::error('이 프로젝트의 비용을 관리할 권한이 없습니다.', 403);
        }
        if (!in_array($type, self::TYPES, true)) {
            Response::error('type 값은 estimate 또는 actual 이어야 합니다.', 422);
        }
        if ($category === '') {
            Response::error('비용 항목(category)을 입력하세요.', 422);
        }
        if ($amount < 0) {
            Response::error('금액은 0 이상이어야 합니다.', 422);
        }

        $data = [
            'project_id' => $projectId,
            'type'       => $type,
            'category'   => $category,
            'amount'     => $amount,
            'spent_date' => $spentDate,
            'memo'       => $memo,
        ];

        if ($id > 0) {
            $before = Db::one('SELECT * FROM costs WHERE id=:id', [':id' => $id]);
            if (!$before || (int) $before['project_id'] !== $projectId) {
                Response::error('수정할 비용 항목을 찾을 수 없습니다.', 404);
            }
            Db::update('costs', $data, 'id=:id', [':id' => $id]);
            Audit::log('update', 'costs', $id, $before, $data);
        } else {
            $data['created_by'] = Auth::id();
            $id = Db::insert('costs', $data);
            Audit::log('create', 'costs', $id, null, $data);
        }

        $data['id'] = $id;
        Response::json($data);
    }

    /** {id} — 삭제. */
    public function delete(): void
    {
        $id = Util::postInt('id', 0) ?: 0;
        if ($id <= 0) {
            Response::error('id 가 필요합니다.', 422);
        }
        $row = Db::one('SELECT * FROM costs WHERE id=:id', [':id' => $id]);
        if (!$row) {
            Response::error('비용 항목을 찾을 수 없습니다.', 404);
        }
        if (!Scope::canAccessProject((int) $row['project_id'])) {
            Response::error('이 프로젝트의 비용을 관리할 권한이 없습니다.', 403);
        }
        Db::run('DELETE FROM costs WHERE id=:id', [':id' => $id]);
        Audit::log('delete', 'costs', $id, $row, null);
        Response::json(['id' => $id]);
    }
}
