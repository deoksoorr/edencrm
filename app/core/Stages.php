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

    // ── 공사 유형(construction_type) — 도장/인테리어 분리(R8-A) 단일 출처 ──
    //    projects.construction_type · contracts.construction_type · process_stages.process_type 공용.
    //    NULL(미지정)은 유실 방지를 위해 보드 양쪽 탭에 노출되며, 정규화 기본값은 'painting'(기존 데이터 호환).

    /** 공사 유형 key => 한글 라벨(폼 옵션·배지 공용). */
    public static function constructionTypes(): array
    {
        return ['painting' => '도장', 'interior' => '인테리어'];
    }

    /** 유효 유형으로 정규화(미지정·무효 → 'painting' — 기존 도장 단일 체계 호환). */
    public static function normalizeConstructionType(?string $type): string
    {
        $t = strtolower(trim((string) $type));
        return array_key_exists($t, self::constructionTypes()) ? $t : 'painting';
    }

    /** 유형 한글 라벨(NULL·무효는 '미지정'). */
    public static function constructionTypeLabel(?string $type): string
    {
        $t = strtolower(trim((string) $type));
        return self::constructionTypes()[$t] ?? '미지정';
    }

    // ── 공정(process) 단계 그룹 — 6그룹(공정 보드용, R3 브리프 §1 + R4 T3 + R8-A 유형 분리) ──
    //    그룹 메타(라벨·색)는 코드가 단일 출처, 각 그룹의 stage_key 목록은 DB(process_stages)가 단일 출처.

    /** 공정 6그룹 메타 공개 접근자 — 설정 화면(그룹 탭)과 보드가 동일 라벨·순서를 공유(R10, 이중 출처 방지). */
    public static function processGroupMeta(): array
    {
        return self::PROCESS_GROUP_META;
    }

    /** 공정 6그룹 메타: key => [라벨, 절제된 단색]. 순서 = 화면 순서. */
    private const PROCESS_GROUP_META = [
        'waiting'  => ['label' => '대기중',    'color' => '#f59e0b'],
        'prep'     => ['label' => '착공 준비', 'color' => '#64748b'],
        'build'    => ['label' => '시공',      'color' => '#6366f1'],
        'finish'   => ['label' => '마무리',    'color' => '#16a34a'],
        'defect'   => ['label' => '하자보수',  'color' => '#d97706'],
        'complete' => ['label' => '종결',      'color' => '#0d9488'],
    ];

    /** DB 실패 시 폴백 stage_key 목록(도장 기준 — R8-A 이전 하드코딩 보존). */
    private const PROCESS_GROUP_FALLBACK = [
        'waiting'  => ['waiting'],
        'prep'     => ['site_survey', 'drawing', 'material_order', 'prep'],
        'build'    => ['protection', 'pressure_wash', 'surface_prep', 'crack_repair', 'putty', 'waterproofing', 'primer', 'paint_1st', 'paint_2nd', 'paint_3rd', 'drying'],
        'finish'   => ['site_cleanup', 'final_inspection'],
        'defect'   => ['warranty_repair'],
        'complete' => ['full_complete'],
    ];

    /**
     * 공정 6그룹: key => [라벨, 단색, stage_key 배열] — 유형별(도장/인테리어).
     * stages 는 DB 에서 로드: (process_type = 유형 OR 'common') AND is_active=1, sort_order 순.
     * 요청 내 static 캐시(유형별). DB 실패 시 기존 하드코딩 배열(도장 기준) 폴백.
     */
    public static function processGroups(?string $type = 'painting'): array
    {
        static $cache = [];
        $type = self::normalizeConstructionType($type);
        if (isset($cache[$type])) {
            return $cache[$type];
        }
        $groups = [];
        foreach (self::PROCESS_GROUP_META as $gkey => $meta) {
            $groups[$gkey] = $meta + ['stages' => []];
        }
        try {
            $rows = Db::all(
                "SELECT stage_key, stage_group FROM process_stages
                 WHERE (process_type = :t OR process_type = 'common') AND is_active = 1
                 ORDER BY sort_order, id",
                [':t' => $type]
            );
            if (!$rows) {
                throw new RuntimeException('process_stages 활성 단계 없음');
            }
            foreach ($rows as $r) {
                $gkey = isset($groups[$r['stage_group']]) ? $r['stage_group'] : 'prep'; // 알 수 없는 그룹은 prep 폴백(기존 관례)
                $groups[$gkey]['stages'][] = $r['stage_key'];
            }
        } catch (\Throwable $e) {
            foreach (self::PROCESS_GROUP_FALLBACK as $gkey => $stages) {
                $groups[$gkey]['stages'] = $stages;
            }
        }
        return $cache[$type] = $groups;
    }

    /** 상단 그룹 필터 탭 — 구성(그룹 묶음)은 유형과 무관하게 동일(시그니처만 유형 전파, R8-A 호환). */
    public static function processTabs(?string $type = 'painting'): array
    {
        return [
            'all'     => ['label' => '전체',        'groups' => ['waiting', 'prep', 'build', 'finish', 'defect', 'complete']],
            'waiting' => ['label' => '대기중',      'groups' => ['waiting']],
            'prep'    => ['label' => '착공 준비',   'groups' => ['prep']],
            'build'   => ['label' => '시공',        'groups' => ['build']],
            'finish'  => ['label' => '마무리·하자', 'groups' => ['finish', 'defect', 'complete']],
        ];
    }

    /** stage_key => 그룹key 역매핑(유형별 캐시). */
    public static function processStageToGroup(?string $type = 'painting'): array
    {
        static $maps = [];
        $type = self::normalizeConstructionType($type);
        if (!isset($maps[$type])) {
            $map = [];
            foreach (self::processGroups($type) as $gkey => $g) {
                foreach ($g['stages'] as $sk) {
                    $map[$sk] = $gkey;
                }
            }
            $maps[$type] = $map;
        }
        return $maps[$type];
    }

    public static function processStagesForTab(?string $tabKey, ?string $type = 'painting'): array
    {
        $tabs = self::processTabs($type);
        $groups = $tabs[$tabKey]['groups'] ?? $tabs['all']['groups'];
        $g = self::processGroups($type);
        $keys = [];
        foreach ($groups as $gk) {
            foreach ($g[$gk]['stages'] as $sk) {
                $keys[] = $sk;
            }
        }
        return $keys;
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

    // ── 일정 시간대 슬롯(오전/오후/야간) — R3: 복수 선택, 원본은 schedule_time_slots ──
    //    표준 키 = morning/afternoon/night. legacy 키(am/pm)는 별칭으로 정규화 수용.
    //    schedules.slot 은 하위호환 미러(첫 슬롯의 legacy 키) — slotToLegacy() 로 산출.

    /** slot key => 한글 라벨(순서 = 화면 순서). */
    public static function scheduleSlots(): array
    {
        return ['morning' => '오전', 'afternoon' => '오후', 'night' => '야간'];
    }

    /** legacy 별칭(am/pm) 포함 표준 키로 정규화. 유효하지 않으면 null. */
    public static function normalizeSlot(?string $slot): ?string
    {
        $s = strtolower(trim((string) $slot));
        $s = ['am' => 'morning', 'pm' => 'afternoon'][$s] ?? $s;
        return array_key_exists($s, self::scheduleSlots()) ? $s : null;
    }

    public static function slotLabel(?string $slot): string
    {
        $s = self::normalizeSlot($slot);
        return $s !== null ? self::scheduleSlots()[$s] : '오전';
    }

    public static function isValidSlot(?string $slot): bool
    {
        return self::normalizeSlot($slot) !== null;
    }

    /**
     * 배열 또는 콤마 문자열 → 정규화·중복 제거·화면 순서(오전→오후→야간) 정렬된 슬롯 배열.
     * 유효 슬롯이 하나도 없으면 [] (컨트롤러가 422 처리).
     */
    public static function parseSlots($raw): array
    {
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        $set = [];
        foreach ($list as $s) {
            $n = self::normalizeSlot(is_scalar($s) ? (string) $s : null);
            if ($n !== null) {
                $set[$n] = true;
            }
        }
        return array_values(array_intersect(array_keys(self::scheduleSlots()), array_keys($set)));
    }

    /** 슬롯 배열 → "오전 · 오후" 형식 라벨. 유효 슬롯 없으면 '-'. */
    public static function slotLabels(array $slots): string
    {
        $slots = self::parseSlots($slots);
        if (!$slots) {
            return '-';
        }
        $all = self::scheduleSlots();
        return implode(' · ', array_map(fn($s) => $all[$s], $slots));
    }

    // ── 일정 유형 — 유형 목록 단일 출처(폼 옵션·라벨·검증 공용). 스키마는 VARCHAR(20)라 마이그레이션 불필요.
    //    R6: 'vacation'(휴가) 전면 비노출 — 옵션·화이트리스트에서 제거(신규 등록 차단은 ScheduleController 가 422).
    //    기존 vacation 데이터는 DB 보존, 화면(캘린더·목록)에는 미표시(schedule.data 가 필터).

    /** type key => 한글 라벨(순서 = 폼 옵션 순서). 캘린더·프로젝트 상세 인라인 폼·컨트롤러 공용. */
    public static function scheduleTypes(): array
    {
        return ['work' => '작업', 'meeting' => '회의', 'site_visit' => '현장방문', 'other' => '기타'];
    }

    /** 유효 type 으로 정규화(미지정·무효 → 'work'). 컨트롤러 저장 검증용(R6: vacation 도 무효). */
    public static function normalizeScheduleType(?string $type): string
    {
        $t = strtolower(trim((string) $type));
        return array_key_exists($t, self::scheduleTypes()) ? $t : 'work';
    }

    /** type 한글 라벨(무효 키는 원문 그대로 — 과거 자유 입력 데이터 방어). */
    public static function scheduleTypeLabel(?string $type): string
    {
        return self::scheduleTypes()[strtolower(trim((string) $type))] ?? (string) $type;
    }

    /** 표준 키 → legacy 단일값 키(am/pm/night). schedules.slot 하위호환 미러 산출용. */
    public static function slotToLegacy(string $slot): string
    {
        return ['morning' => 'am', 'afternoon' => 'pm', 'night' => 'night'][self::normalizeSlot($slot) ?? 'morning'];
    }

    /** 슬롯 → 대표 시각[시작, 종료](start_datetime/end_datetime 호환 산출용). legacy 키 수용. */
    public static function slotTimes(string $slot): array
    {
        return [
            'morning'   => ['09:00:00', '12:00:00'],
            'afternoon' => ['13:00:00', '18:00:00'],
            'night'     => ['18:00:00', '22:00:00'],
        ][self::normalizeSlot($slot) ?? 'morning'];
    }

    /** 복수 슬롯 대표 구간: [가장 이른 시작, 가장 늦은 종료]. 빈 배열이면 morning 기준. */
    public static function slotSpanTimes(array $slots): array
    {
        $slots = self::parseSlots($slots) ?: ['morning'];
        return [self::slotTimes($slots[0])[0], self::slotTimes($slots[count($slots) - 1])[1]];
    }
}
