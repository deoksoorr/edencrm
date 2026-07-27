<?php
/**
 * 시스템 설정 + 영업단계(pipeline_stages)/공정단계(process_stages) 관리. perm settings.manage 는 라우터가 강제.
 */
class SettingsController
{
    /**
     * 화면 비노출 설정 키(R6 T1) — 자동 지각·조퇴 판정 폐지로 참조 화면이 없어진 키.
     * 데이터(행)는 보존하되 설정 화면 노출·저장 대상에서 제외한다(직접 POST 도 무시).
     */
    private const HIDDEN_KEYS = ['attendance_work_start', 'attendance_work_end'];

    public function index(): void
    {
        $rows = Db::all("SELECT * FROM settings ORDER BY `group`, setting_key");
        $groups = [];
        foreach ($rows as $r) {
            if (in_array($r['setting_key'], self::HIDDEN_KEYS, true)) {
                continue; // R6: 미사용 키 숨김(데이터 유지)
            }
            $groups[$r['group'] ?: 'general'][] = $r;
        }
        View::render('settings/index', [
            'title'  => '시스템 설정',
            'groups' => $groups,
        ]);
    }

    public function save(): void
    {
        $hidden = "'" . implode("','", self::HIDDEN_KEYS) . "'";
        $rows = Db::all("SELECT setting_key, value FROM settings WHERE setting_key NOT IN ($hidden)");
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

    /** R8-A: 공정 마스터 관리의 유형 탭(도장/인테리어/공통) 화이트리스트. */
    private const PROCESS_TYPE_TABS = ['painting' => '도장', 'interior' => '인테리어', 'common' => '공통'];

    /** 신규 공정 단계에 허용되는 stage_group(waiting/defect/complete 는 공통 예약). */
    private const PROCESS_GROUP_OPTIONS = ['prep' => '착공 준비', 'build' => '시공', 'finish' => '마무리'];

    /** 영업단계 + 공정단계 관리 화면. R8-A: 공정 섹션은 도장/인테리어/공통 탭 구분. */
    public function stages(): void
    {
        $processType = Util::str('type', 'painting');
        if (!isset(self::PROCESS_TYPE_TABS[$processType])) {
            $processType = 'painting';
        }

        $pipelineStages = Db::all("SELECT * FROM pipeline_stages ORDER BY sort_order");
        $processStages  = Db::all(
            "SELECT * FROM process_stages WHERE process_type = :t ORDER BY sort_order, id",
            [':t' => $processType]
        );
        // 신규 추가 기본 sort_order = 해당 유형 내 최대+1 (공통 탭은 신규 추가 없음)
        $processNextSort = 1 + (int) Db::val(
            "SELECT COALESCE(MAX(sort_order), 0) FROM process_stages WHERE process_type = :t",
            [':t' => $processType]
        );

        View::render('settings/stages', [
            'title'           => '영업/공정 단계 관리',
            'pipelineStages'  => $pipelineStages,
            'processStages'   => $processStages,
            'processType'     => $processType,
            'processTypeTabs' => self::PROCESS_TYPE_TABS,
            'processGroups'   => self::PROCESS_GROUP_OPTIONS,
            'processNextSort' => $processNextSort,
            'scripts'         => ['vendor/Sortable.min.js'],
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

        // R8-A: 드래그앤드롭 일괄 정렬(공정 전용) — order_json=[id,...] 순서대로 sort_order=1..N 재부여.
        // 같은 process_type(painting/interior) 소속 id 만 허용, 공통 3행(sort 0/18/19)은 재부여 대상 제외.
        if (Util::postStr('sort_bulk', '') === '1') {
            if ($kind !== 'process') {
                Response::error('일괄 정렬은 공정 단계만 지원합니다.', 400);
            }
            $ids = json_decode(Util::postStr('order_json', ''), true);
            if (!is_array($ids) || !$ids) {
                Response::error('정렬 데이터가 올바르지 않습니다.', 422);
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = Db::all("SELECT id, process_type FROM process_stages WHERE id IN ($in)", $ids);
            if (count($rows) !== count($ids)) {
                Response::error('존재하지 않는 단계가 포함되어 있습니다.', 422);
            }
            $types = array_unique(array_column($rows, 'process_type'));
            if (count($types) !== 1 || !in_array($types[0], ['painting', 'interior'], true)) {
                Response::error('같은 공사 유형(도장/인테리어)의 단계만 함께 정렬할 수 있습니다. 공통 단계는 순서를 변경할 수 없습니다.', 422);
            }
            Db::transaction(function () use ($ids) {
                foreach ($ids as $i => $sid) {
                    Db::update('process_stages', ['sort_order' => $i + 1], 'id = :id', [':id' => (int) $sid]);
                }
            });
            Audit::log('stage_reorder_bulk', 'process_stages', null, null,
                ['process_type' => $types[0], 'order' => $ids]);
            Response::json(['count' => count($ids), 'process_type' => $types[0]]);
        }

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
            // R8-A: 공정 탭(도장/인테리어/공통)에서 온 요청은 원래 탭으로 복귀
            $rtype = Util::postStr('rtype', '');
            Response::redirect('settings.stages',
                isset(self::PROCESS_TYPE_TABS[$rtype]) ? ['type' => $rtype] : [], '순서가 변경되었습니다.');
        }

        $name = Util::postStr('name');
        if ($name === '') {
            Response::redirect('settings.stages', [], '단계 이름을 입력하세요.', 'error');
        }
        $sortOrder = (int) Util::postInt('sort_order', 0);

        $before = $id ? Db::one("SELECT * FROM `$table` WHERE id = :id", [':id' => $id]) : null;
        if ($id && !$before) {
            Response::redirect('settings.stages', [], '대상 단계를 찾을 수 없습니다.', 'error');
        }

        $data = ['name' => $name, 'sort_order' => $sortOrder];
        $redirectParams = [];   // 공정 저장 후 원래 유형 탭으로 복귀
        $extraMsg = '';         // 비활성 전환 시 안내 문구
        if ($kind === 'pipeline') {
            $data['is_won']  = Util::postInt('is_won', 0) ? 1 : 0;
            $data['is_lost'] = Util::postInt('is_lost', 0) ? 1 : 0;
            $data['color']   = Util::nullIfEmpty(Util::postStr('color', ''));
        } else {
            $data['requires_confirm'] = Util::postInt('requires_confirm', 0) ? 1 : 0;
            $data['color']            = Util::nullIfEmpty(Util::postStr('color', ''));
            $data['description']      = Util::nullIfEmpty(mb_substr(Util::postStr('description', ''), 0, 255));

            // R8-A: 공통 예약 3행(waiting/warranty_repair/full_complete)은 유형·그룹·사용 여부 변경 불가
            //       (이름·색·설명·확인 여부만 수정). 그 외 행은 painting/interior + prep/build/finish 화이트리스트.
            $isCommon = $before !== null && ($before['process_type'] ?? '') === 'common';
            if ($isCommon) {
                $redirectParams = ['type' => 'common'];
            } else {
                $ptype = Util::postStr('process_type', '');
                if (!in_array($ptype, ['painting', 'interior'], true)) {
                    // common 으로의 변경·신규 생성 불가 — 기존 값 유지, 신규는 painting 기본
                    $ptype = in_array($before['process_type'] ?? '', ['painting', 'interior'], true)
                        ? $before['process_type'] : 'painting';
                }
                $data['process_type'] = $ptype;
                $redirectParams = ['type' => $ptype];

                $sgroup = Util::postStr('stage_group', '');
                if (!isset(self::PROCESS_GROUP_OPTIONS[$sgroup])) {
                    // waiting/defect/complete 는 공통 예약 그룹 — 일반 단계에 지정 불가
                    $sgroup = isset(self::PROCESS_GROUP_OPTIONS[$before['stage_group'] ?? '']) ? $before['stage_group'] : 'build';
                }
                $data['stage_group'] = $sgroup;
                $data['is_active']   = Util::postInt('is_active', 0) ? 1 : 0;

                // 신규 추가 시 sort_order 미지정(0 이하)이면 해당 유형 내 최대+1
                if (!$id && $sortOrder <= 0) {
                    $data['sort_order'] = 1 + (int) Db::val(
                        "SELECT COALESCE(MAX(sort_order), 0) FROM process_stages WHERE process_type = :t",
                        [':t' => $ptype]
                    );
                }
                // 사용 중 단계 비활성 전환 안내 — 보드 컬럼에서 사라져 해당 카드가 숨겨진다(공정 이동 권장)
                if ($id && (int) ($before['is_active'] ?? 1) === 1 && $data['is_active'] === 0) {
                    $refCnt = (int) Db::val(
                        "SELECT COUNT(*) FROM projects WHERE process_stage_id = :id AND deleted_at IS NULL",
                        [':id' => $id]
                    );
                    if ($refCnt > 0) {
                        $extraMsg = " 이 단계의 프로젝트 {$refCnt}건은 보드에서 숨겨집니다 — 먼저 공정을 이동해 두는 것을 권장합니다.";
                    }
                }
            }
        }

        if ($id) {
            Db::update($table, $data, 'id = :id', [':id' => $id]);
            Audit::log('stage_update', $table, $id, $before, $data);
        } else {
            $data['stage_key'] = $this->makeStageKey($table, $name); // 생성 시 1회 발급, 이후 이름이 바뀌어도 불변(공정 ID 유지)
            $id = Db::insert($table, $data);
            Audit::log('stage_create', $table, $id, null, $data);
        }

        if (Response::wantsJson()) {
            Response::json(['id' => $id]);
        }
        Response::redirect('settings.stages', $redirectParams, '저장되었습니다.' . $extraMsg);
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

        // R8-A: 공정 저장·삭제 후 원래 유형 탭으로 복귀
        $redirectParams = [];
        if ($kind === 'process' && isset(self::PROCESS_TYPE_TABS[$before['process_type'] ?? ''])) {
            $redirectParams = ['type' => $before['process_type']];
        }

        $deny = function (string $msg) use ($redirectParams): never {
            if (Response::wantsJson()) {
                Response::error($msg, 400);
            }
            Response::redirect('settings.stages', $redirectParams, $msg, 'error');
        };

        if ($kind === 'pipeline') {
            $refCount = (int) Db::val(
                "SELECT COUNT(*) FROM leads WHERE stage_id = :id AND deleted_at IS NULL",
                [':id' => $id]
            );
            if ($refCount > 0) {
                $deny("이 단계를 참조하는 영업기회이(가) {$refCount}건 있어 삭제할 수 없습니다.");
            }
        } else {
            // R8-A: 공통 예약 3행(대기중·하자보수·전체완료)은 삭제 불가(비활성도 불가 — saveStage 가 차단)
            if (($before['process_type'] ?? '') === 'common') {
                $deny('공통 예약 단계(대기중·하자보수·전체완료)는 삭제할 수 없습니다.');
            }
            $refCount = (int) Db::val(
                "SELECT COUNT(*) FROM projects WHERE process_stage_id = :id AND deleted_at IS NULL",
                [':id' => $id]
            );
            if ($refCount > 0) {
                $deny("이 단계를 참조하는 프로젝트이(가) {$refCount}건 있어 삭제할 수 없습니다.");
            }
            // R8-A: 공정 이력(project_process_history)이 참조하면 삭제 대신 비활성화 유도(이력 무결성 보존)
            $histCount = (int) Db::val(
                "SELECT COUNT(*) FROM project_process_history WHERE from_stage_id = :f OR to_stage_id = :t",
                [':f' => $id, ':t' => $id]
            );
            if ($histCount > 0) {
                $deny("이 단계를 참조하는 공정 이력이 {$histCount}건 있어 삭제할 수 없습니다. 대신 '사용' 체크를 해제(비활성화)를 사용하세요.");
            }
        }

        Db::run("DELETE FROM `$table` WHERE id = :id", [':id' => $id]);
        Audit::log('stage_delete', $table, $id, $before, null);

        if (Response::wantsJson()) {
            Response::json(['id' => $id]);
        }
        Response::redirect('settings.stages', $redirectParams, '삭제되었습니다.');
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
