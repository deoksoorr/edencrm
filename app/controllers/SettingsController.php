<?php
/**
 * 시스템 설정 + 영업단계(pipeline_stages)/공정단계(process_stages) 관리. perm settings.manage 는 라우터가 강제.
 */
class SettingsController
{
    public function index(): void
    {
        $rows = Db::all("SELECT * FROM settings ORDER BY `group`, setting_key");
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['group'] ?: 'general'][] = $r;
        }
        View::render('settings/index', [
            'title'  => '시스템 설정',
            'groups' => $groups,
        ]);
    }

    public function save(): void
    {
        $rows = Db::all("SELECT setting_key, value FROM settings");
        $before = [];
        $after  = [];
        foreach ($rows as $r) {
            $key = $r['setting_key'];
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $val = trim((string) $_POST[$key]);
            if ($val === $r['value']) {
                continue;
            }
            $before[$key] = $r['value'];
            $after[$key]  = $val;
            Db::update('settings', ['value' => $val], 'setting_key = :k', [':k' => $key]);
        }
        if ($after) {
            Audit::log('settings_update', 'settings', null, $before, $after);
        }
        Response::redirect('settings.index', [], '설정이 저장되었습니다.');
    }

    /** 영업단계 + 공정단계 관리 화면. */
    public function stages(): void
    {
        $pipelineStages = Db::all("SELECT * FROM pipeline_stages ORDER BY sort_order");
        $processStages  = Db::all("SELECT * FROM process_stages ORDER BY sort_order");

        View::render('settings/stages', [
            'title'          => '영업/공정 단계 관리',
            'pipelineStages' => $pipelineStages,
            'processStages'  => $processStages,
        ]);
    }

    /** 단계 추가/수정 (+ sort_only=1 이면 순서만 갱신하는 경량 AJAX 경로). */
    public function saveStage(): void
    {
        $kind = Util::postStr('kind');
        if (!in_array($kind, ['pipeline', 'process'], true)) {
            Response::error('잘못된 요청입니다.', 400);
        }
        $table = $kind === 'pipeline' ? 'pipeline_stages' : 'process_stages';
        $id    = (int) Util::postInt('id', 0);

        // 위/아래 버튼(순서 변경) — 이름 등 다른 값은 건드리지 않는다. swap_id 가 있으면 두 단계의 sort_order 를 함께 맞바꾼다.
        if (Util::postStr('sort_only', '') === '1' && $id) {
            $sortOrder = (int) Util::postInt('sort_order', 0);
            $swapId    = (int) Util::postInt('swap_id', 0);
            $swapSort  = Util::postInt('swap_sort_order', null);

            $before = Db::one("SELECT * FROM `$table` WHERE id = :id", [':id' => $id]);
            if (!$before) {
                Response::redirect('settings.stages', [], '대상을 찾을 수 없습니다.', 'error');
            }

            Db::transaction(function () use ($table, $id, $sortOrder, $swapId, $swapSort) {
                Db::update($table, ['sort_order' => $sortOrder], 'id = :id', [':id' => $id]);
                if ($swapId && $swapSort !== null) {
                    Db::update($table, ['sort_order' => $swapSort], 'id = :id', [':id' => $swapId]);
                }
            });
            Audit::log('stage_reorder', $table, $id, ['sort_order' => $before['sort_order']], ['sort_order' => $sortOrder]);

            if (Response::wantsJson()) {
                Response::json(['id' => $id, 'sort_order' => $sortOrder]);
            }
            Response::redirect('settings.stages', [], '순서가 변경되었습니다.');
        }

        $name = Util::postStr('name');
        if ($name === '') {
            Response::redirect('settings.stages', [], '단계 이름을 입력하세요.', 'error');
        }
        $sortOrder = (int) Util::postInt('sort_order', 0);

        $data = ['name' => $name, 'sort_order' => $sortOrder];
        if ($kind === 'pipeline') {
            $data['is_won']  = Util::postInt('is_won', 0) ? 1 : 0;
            $data['is_lost'] = Util::postInt('is_lost', 0) ? 1 : 0;
            $data['color']   = Util::nullIfEmpty(Util::postStr('color', ''));
        } else {
            $data['requires_confirm'] = Util::postInt('requires_confirm', 0) ? 1 : 0;
            $data['color']            = Util::nullIfEmpty(Util::postStr('color', ''));
        }

        if ($id) {
            $before = Db::one("SELECT * FROM `$table` WHERE id = :id", [':id' => $id]);
            if (!$before) {
                Response::redirect('settings.stages', [], '대상 단계를 찾을 수 없습니다.', 'error');
            }
            Db::update($table, $data, 'id = :id', [':id' => $id]);
            Audit::log('stage_update', $table, $id, $before, $data);
        } else {
            $data['stage_key'] = $this->makeStageKey($table, $name);
            $id = Db::insert($table, $data);
            Audit::log('stage_create', $table, $id, null, $data);
        }

        if (Response::wantsJson()) {
            Response::json(['id' => $id]);
        }
        Response::redirect('settings.stages', [], '저장되었습니다.');
    }

    /** 단계 삭제 — 참조하는 leads/projects 가 있으면 거부. */
    public function deleteStage(): void
    {
        $kind = Util::postStr('kind');
        if (!in_array($kind, ['pipeline', 'process'], true)) {
            Response::error('잘못된 요청입니다.', 400);
        }
        $table = $kind === 'pipeline' ? 'pipeline_stages' : 'process_stages';
        $id    = (int) Util::postInt('id', 0);

        $before = Db::one("SELECT * FROM `$table` WHERE id = :id", [':id' => $id]);
        if (!$before) {
            Response::redirect('settings.stages', [], '대상 단계를 찾을 수 없습니다.', 'error');
        }

        if ($kind === 'pipeline') {
            $refCount = (int) Db::val(
                "SELECT COUNT(*) FROM leads WHERE stage_id = :id AND deleted_at IS NULL",
                [':id' => $id]
            );
            $refLabel = '영업기회';
        } else {
            $refCount = (int) Db::val(
                "SELECT COUNT(*) FROM projects WHERE process_stage_id = :id AND deleted_at IS NULL",
                [':id' => $id]
            );
            $refLabel = '프로젝트';
        }

        if ($refCount > 0) {
            $msg = "이 단계를 참조하는 {$refLabel}이(가) {$refCount}건 있어 삭제할 수 없습니다.";
            if (Response::wantsJson()) {
                Response::error($msg, 400);
            }
            Response::redirect('settings.stages', [], $msg, 'error');
        }

        Db::run("DELETE FROM `$table` WHERE id = :id", [':id' => $id]);
        Audit::log('stage_delete', $table, $id, $before, null);

        if (Response::wantsJson()) {
            Response::json(['id' => $id]);
        }
        Response::redirect('settings.stages', [], '삭제되었습니다.');
    }

    private function makeStageKey(string $table, string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $name), '_'));
        if ($base === '') {
            $base = 'stage_' . bin2hex(random_bytes(3));
        }
        $base = substr($base, 0, 24);
        $key = $base;
        $i = 1;
        while (Db::val("SELECT 1 FROM `$table` WHERE stage_key = :k", [':k' => $key]) !== null) {
            $i++;
            $key = substr($base, 0, 27) . '_' . $i;
        }
        return substr($key, 0, 30);
    }
}
