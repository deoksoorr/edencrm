<?php
/**
 * 영업 파이프라인 단계 그룹 정의 — 단일 출처(Single Source of Truth).
 *
 * DB 의 12개 pipeline_stages(stage_key)를 6개 상위 그룹으로 매핑한다.
 * DB 단계는 그대로 유지(드래그 정밀도·이력 보존)하고, 화면에서만 그룹으로 묶어 보여준다.
 * 컨트롤러(대시보드/파이프라인)와 뷰가 모두 이 정의를 참조한다.
 */
class Stages
{
    /** 6개 상위 그룹 정의: key => [라벨, 절제된 단색, 소속 stage_key 배열]. 순서 = 화면 순서. */
    public static function pipelineGroups(): array
    {
        return [
            'new'      => ['label' => '신규',      'color' => '#64748b', 'stages' => ['new_inquiry']],
            'consult'  => ['label' => '상담',      'color' => '#3b82f6', 'stages' => ['consult_booked']],
            'survey'   => ['label' => '현장확인',  'color' => '#0891b2', 'stages' => ['site_survey']],
            'quote'    => ['label' => '견적',      'color' => '#6366f1', 'stages' => ['quote_drafting', 'quote_sent', 'negotiating']],
            'contract' => ['label' => '계약',      'color' => '#16a34a', 'stages' => ['contract_pending', 'contract_won']],
            'closed'   => ['label' => '보류·종료', 'color' => '#9ca3af', 'stages' => ['on_hold', 'no_response', 'lost', 'cancelled']],
        ];
    }

    /** 상단 탭 정의: key => [라벨, 포함 그룹key 배열]. */
    public static function pipelineTabs(): array
    {
        return [
            'all'      => ['label' => '전체',       'groups' => ['new', 'consult', 'survey', 'quote', 'contract', 'closed']],
            'early'    => ['label' => '신규·상담',   'groups' => ['new', 'consult']],
            'mid'      => ['label' => '현장·견적',   'groups' => ['survey', 'quote']],
            'contract' => ['label' => '계약',        'groups' => ['contract']],
            'closed'   => ['label' => '보류·종료',   'groups' => ['closed']],
        ];
    }

    /** stage_key => 그룹key 역매핑. */
    public static function stageToGroup(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::pipelineGroups() as $gkey => $g) {
                foreach ($g['stages'] as $sk) {
                    $map[$sk] = $gkey;
                }
            }
        }
        return $map;
    }

    /** 특정 stage_key 의 그룹key(없으면 'closed'). */
    public static function groupOf(string $stageKey): string
    {
        return self::stageToGroup()[$stageKey] ?? 'closed';
    }

    /** 특정 그룹의 절제된 단색. */
    public static function groupColor(string $groupKey): string
    {
        return self::pipelineGroups()[$groupKey]['color'] ?? '#9ca3af';
    }

    /** 탭 key → 표시할 stage_key 배열(순서 유지). 'all' 또는 미지정 → 전체. */
    public static function stagesForTab(?string $tabKey): array
    {
        $tabs = self::pipelineTabs();
        $groups = $tabs[$tabKey]['groups'] ?? $tabs['all']['groups'];
        $g = self::pipelineGroups();
        $keys = [];
        foreach ($groups as $gk) {
            foreach ($g[$gk]['stages'] as $sk) {
                $keys[] = $sk;
            }
        }
        return $keys;
    }

    // ── 공정(process) 단계 그룹 — 18단계 → 5그룹(공정 보드용) ──

    /** 공정 5그룹: key => [라벨, 단색, stage_key 배열]. */
    public static function processGroups(): array
    {
        return [
            'prep'    => ['label' => '착공 준비', 'color' => '#64748b', 'stages' => ['site_survey', 'drawing', 'material_order', 'prep']],
            'surface' => ['label' => '표면·방수', 'color' => '#0891b2', 'stages' => ['protection', 'pressure_wash', 'surface_prep', 'crack_repair', 'putty', 'waterproofing']],
            'paint'   => ['label' => '도장',      'color' => '#6366f1', 'stages' => ['primer', 'paint_1st', 'paint_2nd', 'paint_3rd']],
            'finish'  => ['label' => '마감·검수', 'color' => '#16a34a', 'stages' => ['drying', 'site_cleanup', 'final_inspection']],
            'defect'  => ['label' => '하자보수',  'color' => '#d97706', 'stages' => ['warranty_repair']],
        ];
    }

    public static function processTabs(): array
    {
        return [
            'all'     => ['label' => '전체',      'groups' => ['prep', 'surface', 'paint', 'finish', 'defect']],
            'prep'    => ['label' => '착공 준비', 'groups' => ['prep']],
            'surface' => ['label' => '표면·방수', 'groups' => ['surface']],
            'paint'   => ['label' => '도장',      'groups' => ['paint']],
            'finish'  => ['label' => '마감·검수', 'groups' => ['finish', 'defect']],
        ];
    }

    public static function processStageToGroup(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::processGroups() as $gkey => $g) {
                foreach ($g['stages'] as $sk) {
                    $map[$sk] = $gkey;
                }
            }
        }
        return $map;
    }

    public static function processStagesForTab(?string $tabKey): array
    {
        $tabs = self::processTabs();
        $groups = $tabs[$tabKey]['groups'] ?? $tabs['all']['groups'];
        $g = self::processGroups();
        $keys = [];
        foreach ($groups as $gk) {
            foreach ($g[$gk]['stages'] as $sk) {
                $keys[] = $sk;
            }
        }
        return $keys;
    }

    /** 중요도(영문) → 한글 라벨. */
    public static function importanceLabel(?string $imp): string
    {
        return ['high' => '높음', 'mid' => '보통', 'low' => '낮음'][$imp] ?? '보통';
    }

    // ── 직원 개인색 팔레트(고정 12색, RGB 자유입력 대체) ──

    /** 고정 팔레트(직원 등록 시 선택). */
    public static function staffColors(): array
    {
        return ['#1a56db', '#0f9d58', '#e8710a', '#d93025', '#7c3aed', '#0891b2',
                '#c026d3', '#65a30d', '#b45309', '#db2777', '#0d9488', '#4b5563'];
    }

    /** 팔레트에 속한 색인지 검증(임의 hex 차단). */
    public static function isValidColor(?string $c): bool
    {
        return $c !== null && in_array(strtolower($c), array_map('strtolower', self::staffColors()), true);
    }

    /** id 기반 기본색(색 미지정 직원 fallback). */
    public static function defaultColorFor(int $id): string
    {
        $p = self::staffColors();
        return $p[($id > 0 ? $id - 1 : 0) % count($p)];
    }

    // ── 일정 시간대 슬롯(오전/오후/야간) ──

    /** slot key => 한글 라벨(순서 = 화면 순서). */
    public static function scheduleSlots(): array
    {
        return ['am' => '오전', 'pm' => '오후', 'night' => '야간'];
    }

    public static function slotLabel(?string $slot): string
    {
        return self::scheduleSlots()[$slot] ?? '오전';
    }

    public static function isValidSlot(?string $slot): bool
    {
        return $slot !== null && array_key_exists($slot, self::scheduleSlots());
    }

    /** 슬롯 → 대표 시각[시작, 종료](start_datetime/end_datetime 호환 산출용). */
    public static function slotTimes(string $slot): array
    {
        return [
            'am'    => ['09:00:00', '12:00:00'],
            'pm'    => ['13:00:00', '18:00:00'],
            'night' => ['18:00:00', '22:00:00'],
        ][$slot] ?? ['09:00:00', '12:00:00'];
    }
}
