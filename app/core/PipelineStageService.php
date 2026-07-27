<?php
/**
 * 파이프라인 자동 단계 산정 서비스 (R4 T7) — 조회 전용 보드의 표시 단계 단일 출처.
 *
 * 7그룹: new_inquiry(신규 문의)/consulting(상담 중)/site_check(현장 확인)/quoting(견적 진행)
 *        /contracted(계약)/on_hold(보류)/closed(종료)
 * 우선순위(신호 충돌 시): 종료 > 보류 > 계약 > 견적 진행 > 현장 확인 > 상담 중 > 신규 문의.
 *
 * 산정 원천(신호 우선, 부재 시 stage_id fallback — planner A-1·A-3 반영):
 *  1) 직접 연결 계약: leads → quotes.lead_id → contracts.quote_id (terminated/cancelled=종료,
 *     active/on_hold/completed=계약). 연결 데이터가 없으면 침묵(현 시드 0건 — r4-planner A-1).
 *  2) 리드 단계 플래그: is_lost(lost/cancelled)=종료, is_won(contract_won)=계약.
 *  3) 고객 단위 보조 신호(추정 — 파생 표시 전용, DB 무변경): 해당 고객의 영업기회가 1건뿐일 때만
 *     (동일 딜 모호성 배제) 고객 계약 상태를 반영. 시기 검증 포함 — 아래 attachSignals 참조.
 *  4) 유효 견적(lead_id 연결, status != 'draft') 존재 → 최소 견적 진행.
 *  5) 그 외 = 기존 12단계 stage_id 의 sort 위치 기준 자연 매핑(STAGE_FALLBACK).
 *     상담 중·현장 확인 그룹은 활동/일정에 lead 연결 원천이 없어 구조적으로 fallback 전용(planner A-4).
 *
 * 주의: 이 서비스는 leads 를 절대 UPDATE 하지 않는다(파생 표시만). 리드-계약 연결 확정은
 * 정책 확인(P-2) 대상 — scripts/lead_link_candidates.php 후보 리포트 참조.
 */
class PipelineStageService
{
    /** 7그룹 정의(순서 = 보드 컬럼 순서): key => [label, color]. */
    public const GROUPS = [
        'new_inquiry' => ['label' => '신규 문의', 'color' => '#64748b'],
        'consulting'  => ['label' => '상담 중',   'color' => '#3b82f6'],
        'site_check'  => ['label' => '현장 확인', 'color' => '#0891b2'],
        'quoting'     => ['label' => '견적 진행', 'color' => '#6366f1'],
        'contracted'  => ['label' => '계약',      'color' => '#16a34a'],
        'on_hold'     => ['label' => '보류',      'color' => '#d97706'],
        'closed'      => ['label' => '종료',      'color' => '#9ca3af'],
    ];

    /** 진행(미계약) 그룹 — 상단 '진행 예상 금액' 집계 대상. */
    public const OPEN_GROUPS = ['new_inquiry', 'consulting', 'site_check', 'quoting'];

    /**
     * 12단계 stage_key → 7그룹 fallback 매핑(sort 위치 기준 자연 매핑 — worklog [pipeline] 매핑표).
     * no_response 는 정책 확인(P-4) 전 잠정 on_hold(sort 10 — on_hold(9)와 lost(11) 사이 자연 위치).
     * contract_pending 은 체결 전(is_won 아님)이라 contracted 불가 → quoting 말미로 귀속.
     */
    public const STAGE_FALLBACK = [
        'new_inquiry'      => 'new_inquiry',
        'consult_booked'   => 'consulting',
        'site_survey'      => 'site_check',
        'quote_drafting'   => 'quoting',
        'quote_sent'       => 'quoting',
        'negotiating'      => 'quoting',
        'contract_pending' => 'quoting',
        'contract_won'     => 'contracted',
        'on_hold'          => 'on_hold',
        'no_response'      => 'on_hold',
        'lost'             => 'closed',
        'cancelled'        => 'closed',
    ];

    /** 그룹 라벨. */
    public static function groupLabel(string $group): string
    {
        return self::GROUPS[$group]['label'] ?? $group;
    }

    /** 그룹 색. */
    public static function groupColor(string $group): string
    {
        return self::GROUPS[$group]['color'] ?? '#9ca3af';
    }

    /**
     * 리드 1건의 파생 단계 산정. attachSignals() 가 붙인 신호 키가 있으면 사용하고,
     * 없으면 stage_key fallback 만으로 판단한다(신호 부재 = 원천 증거 없음).
     *
     * 사용 신호 키(옵션): _link_contract_status(직접 연결 계약 상태),
     *   _cust_signal('contracted'|'closed'|null), _has_valid_quote(bool)
     */
    public static function deriveStage(array $lead): string
    {
        $fallback = self::STAGE_FALLBACK[(string) ($lead['stage_key'] ?? '')] ?? 'new_inquiry';
        $linked   = $lead['_link_contract_status'] ?? null;
        $cust     = $lead['_cust_signal'] ?? null;

        // 1) 종료: 연결 계약 파기/취소 > 리드 실주/취소 단계 > 고객 단위 사망 신호
        if (in_array($linked, ['terminated', 'cancelled'], true) || $fallback === 'closed' || $cust === 'closed') {
            return 'closed';
        }
        // 2) 보류: 리드 보류(및 잠정 장기미응답) 단계
        if ($fallback === 'on_hold') {
            return 'on_hold';
        }
        // 3) 계약: 연결 계약 진행/보류/완료 > 리드 계약완료(is_won) 단계 > 고객 단위 추정
        if (in_array($linked, ['active', 'on_hold', 'completed'], true) || $fallback === 'contracted' || $cust === 'contracted') {
            return 'contracted';
        }
        // 4) 견적 진행: 유효 견적(비 draft) 연결 또는 견적 구간 단계
        if (!empty($lead['_has_valid_quote']) || $fallback === 'quoting') {
            return 'quoting';
        }
        // 5~7) 현장 확인/상담 중/신규 — 활동·일정의 lead 연결 원천 부재로 stage fallback 전용
        return in_array($fallback, ['site_check', 'consulting'], true) ? $fallback : 'new_inquiry';
    }

    /**
     * 문서 흐름(견적·계약 저장) → 리드 원본 단계 자동 전진(R7-F5).
     * 대시보드 깔때기·전환율은 원본 stage_id 를 세므로, 파생 단계(deriveStage)와 어긋나지 않게
     * 문서 이벤트 시 원본을 함께 전진시킨다. 규칙:
     *  - 종결 리드(is_won/is_lost)는 불변(계약완료·실주 확정 후 자동 변경 금지)
     *  - 승리 경로(신규~계약대기) 내에서는 전진만 허용(후퇴·동일 무시)
     *  - 보류·장기미응답 리드는 문서 증거(견적·계약)가 생기면 승리 경로로 재활성 허용
     * 이동 시 stage_entered_at 갱신 + 감사로그(lead_stage_auto). 반환: 실제 이동 여부.
     */
    public static function advanceLead(?int $leadId, string $targetKey, string $reason): bool
    {
        if (!$leadId) {
            return false;
        }
        $lead = Db::one(
            "SELECT l.id, l.stage_id, ps.stage_key, ps.sort_order, ps.is_won, ps.is_lost
             FROM leads l JOIN pipeline_stages ps ON ps.id = l.stage_id
             WHERE l.id = :id AND l.deleted_at IS NULL",
            [':id' => $leadId]
        );
        if (!$lead || (int) $lead['is_won'] === 1 || (int) $lead['is_lost'] === 1) {
            return false;
        }
        $target = Db::one("SELECT id, stage_key, sort_order FROM pipeline_stages WHERE stage_key = :k", [':k' => $targetKey]);
        if (!$target || (int) $target['id'] === (int) $lead['stage_id']) {
            return false;
        }
        $onWinPath = !in_array($lead['stage_key'], ['on_hold', 'no_response'], true);
        if ($onWinPath && (int) $target['sort_order'] <= (int) $lead['sort_order']) {
            return false; // 승리 경로 내 후퇴 금지
        }
        Db::update('leads', [
            'stage_id'         => (int) $target['id'],
            'stage_entered_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', [':id' => (int) $lead['id']]);
        Audit::log('lead_stage_auto', 'leads', (int) $lead['id'],
            ['stage_id' => (int) $lead['stage_id'], 'stage_key' => $lead['stage_key']],
            ['stage_id' => (int) $target['id'], 'stage_key' => $target['stage_key'], 'reason' => $reason]);
        return true;
    }

    /**
     * 리드 목록에 산정 신호·파생 단계·연결 문서를 배치로 부착(N+1 금지).
     * 입력 리드는 stage_key·customer_id·created_at 을 포함해야 한다.
     *
     * 부착 키:
     *  derived_stage / derived_label / derived_color / derived_source(산정 근거 문구)
     *  link_quote / link_contract / link_project (연결 행 또는 null — 고객 단위 추정이면 *_estimated=true)
     */
    public static function attachSignals(array $leads): array
    {
        if (!$leads) {
            return $leads;
        }
        $leadIds = array_map(static fn($l) => (int) $l['id'], $leads);
        $custIds = array_values(array_unique(array_map(static fn($l) => (int) $l['customer_id'], $leads)));

        // ── 1) 직접 연결 견적(quotes.lead_id) ──
        [$inL, $pL] = self::inClause($leadIds, 'lq');
        $quoteRows = Db::all(
            "SELECT id, lead_id, quote_no, status FROM quotes
             WHERE deleted_at IS NULL AND lead_id IN ($inL) ORDER BY id DESC",
            $pL
        );
        $quotesByLead = [];
        $quoteIds = [];
        foreach ($quoteRows as $qr) {
            $quotesByLead[(int) $qr['lead_id']][] = $qr;
            $quoteIds[] = (int) $qr['id'];
        }

        // ── 2) 연결 견적으로 체결된 계약 ──
        $contractsByQuote = [];
        $contractIds = [];
        if ($quoteIds) {
            [$inQ, $pQ] = self::inClause($quoteIds, 'qc');
            foreach (Db::all(
                "SELECT id, quote_id, contract_no, status FROM contracts
                 WHERE deleted_at IS NULL AND quote_id IN ($inQ) ORDER BY id DESC",
                $pQ
            ) as $cr) {
                $contractsByQuote[(int) $cr['quote_id']][] = $cr;
                $contractIds[] = (int) $cr['id'];
            }
        }

        // ── 3) 고객 단위 보조 신호 준비: 고객별 영업기회 수(전체 기준) + 고객 계약 ──
        [$inC, $pC] = self::inClause($custIds, 'cc');
        $leadCntByCust = [];
        foreach (Db::all(
            "SELECT customer_id, COUNT(*) AS n FROM leads
             WHERE deleted_at IS NULL AND customer_id IN ($inC) GROUP BY customer_id",
            $pC
        ) as $r) {
            $leadCntByCust[(int) $r['customer_id']] = (int) $r['n'];
        }
        $contractsByCust = [];
        foreach (Db::all(
            "SELECT c.id, c.customer_id, c.contract_no, c.status, c.contract_date, DATE(c.created_at) AS created_date,
                    (SELECT MAX(t.terminated_date) FROM contract_terminations t WHERE t.contract_id = c.id) AS terminated_date
             FROM contracts c
             WHERE c.deleted_at IS NULL AND c.customer_id IN ($inC) ORDER BY c.id DESC",
            $pC
        ) as $cr) {
            $contractsByCust[(int) $cr['customer_id']][] = $cr;
        }

        // ── 4) 연결/추정 계약의 프로젝트 (전체 후보 계약 id 로 배치 조회) ──
        foreach ($contractsByCust as $list) {
            foreach ($list as $cr) {
                $contractIds[] = (int) $cr['id'];
            }
        }
        $projectByContract = [];
        if ($contractIds) {
            $contractIds = array_values(array_unique($contractIds));
            [$inP, $pP] = self::inClause($contractIds, 'pc');
            foreach (Db::all(
                "SELECT id, contract_id, name, status FROM projects
                 WHERE deleted_at IS NULL AND contract_id IN ($inP) ORDER BY id DESC",
                $pP
            ) as $pr) {
                $projectByContract[(int) $pr['contract_id']] = $pr; // uq_projects_contract — 계약당 1건
            }
        }

        foreach ($leads as &$l) {
            $lid = (int) $l['id'];
            $cid = (int) $l['customer_id'];
            $leadCreated = substr((string) $l['created_at'], 0, 10);

            // 직접 연결 견적/계약 — 유효 견적(비 draft) 우선, 계약은 진행군 > 종결군 순으로 해석
            $myQuotes = $quotesByLead[$lid] ?? [];
            $validQuote = null;
            foreach ($myQuotes as $qr) {
                if ($qr['status'] !== 'draft') { $validQuote = $qr; break; }
            }
            $linkQuote = $validQuote ?? ($myQuotes[0] ?? null);
            $linkContract = null;
            foreach ($myQuotes as $qr) {
                foreach ($contractsByQuote[(int) $qr['id']] ?? [] as $cr) {
                    if (in_array($cr['status'], ['active', 'on_hold', 'completed'], true)) { $linkContract = $cr; break 2; }
                    if ($linkContract === null && in_array($cr['status'], ['terminated', 'cancelled'], true)) { $linkContract = $cr; }
                }
            }

            // 고객 단위 보조 신호(추정): 단독 영업기회 고객 + 시기 검증(파생 표시 전용)
            $custSignal = null;
            $custContract = null;
            if (($leadCntByCust[$cid] ?? 0) === 1 && $linkContract === null) {
                $custContracts = $contractsByCust[$cid] ?? [];
                $hasLive = false;
                foreach ($custContracts as $cr) {
                    if (in_array($cr['status'], ['active', 'on_hold', 'completed', 'draft'], true)) { $hasLive = true; break; }
                }
                foreach ($custContracts as $cr) {
                    // 계약 추정: 진행군 계약이 리드 등록 이후 체결(리드→계약 순방향)일 때만
                    if (in_array($cr['status'], ['active', 'on_hold', 'completed'], true)
                        && ($cr['contract_date'] ?? $cr['created_date']) >= $leadCreated) {
                        $custSignal = 'contracted';
                        $custContract = $cr;
                        break;
                    }
                }
                if ($custSignal === null && !$hasLive) {
                    foreach ($custContracts as $cr) {
                        // 사망 추정: 진행 계약이 전무하고 파기/취소가 리드 등록 이후 확정된 딜
                        $deadDate = $cr['terminated_date'] ?? $cr['contract_date'] ?? $cr['created_date'];
                        if (in_array($cr['status'], ['terminated', 'cancelled'], true) && $deadDate >= $leadCreated) {
                            $custSignal = 'closed';
                            $custContract = $cr;
                            break;
                        }
                    }
                }
            }

            $l['_link_contract_status'] = $linkContract['status'] ?? null;
            $l['_cust_signal'] = $custSignal;
            $l['_has_valid_quote'] = $validQuote !== null;

            $derived = self::deriveStage($l);
            $l['derived_stage'] = $derived;
            $l['derived_label'] = self::groupLabel($derived);
            $l['derived_color'] = self::groupColor($derived);

            // 표시용 연결 문서(직접 연결 우선, 없으면 고객 단위 추정 계약)
            $showContract = $linkContract ?? $custContract;
            $l['link_quote'] = $linkQuote;
            $l['link_contract'] = $showContract;
            $l['link_contract_estimated'] = $linkContract === null && $custContract !== null;
            $l['link_project'] = $showContract ? ($projectByContract[(int) $showContract['id']] ?? null) : null;

            // 산정 근거 문구(계산 기준 화면 명시 — 대원칙)
            $stageName = (string) ($l['stage_name'] ?? $l['stage_key'] ?? '');
            if ($linkContract !== null && in_array($linkContract['status'], ['terminated', 'cancelled'], true) && $derived === 'closed') {
                $l['derived_source'] = '연결 계약 ' . $linkContract['contract_no'] . ' 파기/취소 기준';
            } elseif ($linkContract !== null && $derived === 'contracted') {
                $l['derived_source'] = '연결 계약 ' . $linkContract['contract_no'] . ' 상태 기준';
            } elseif ($custSignal !== null && $derived === ($custSignal === 'closed' ? 'closed' : 'contracted') && self::STAGE_FALLBACK[(string) ($l['stage_key'] ?? '')] !== $derived) {
                $l['derived_source'] = '고객 단위 계약 ' . ($custContract['contract_no'] ?? '') . ' 추정(단독 영업기회 고객) — 연결 확정은 정책 확인 필요';
            } elseif ($validQuote !== null && $derived === 'quoting' && !in_array(self::STAGE_FALLBACK[(string) ($l['stage_key'] ?? '')], ['quoting'], true)) {
                $l['derived_source'] = '유효 견적 ' . $validQuote['quote_no'] . ' 연결 기준';
            } else {
                $l['derived_source'] = '원 단계 「' . $stageName . '」 매핑(원천 신호 부재 시 12단계 기준)';
            }
        }
        unset($l);
        return $leads;
    }

    /**
     * 파생 단계 기준 요약(보드 상단): 그룹별 건수·예상금액 합, 진행 예상/견적 진행/계약 전환/보류·종료.
     * @param array $leads attachSignals() 를 거친 리드 목록
     */
    public static function summarize(array $leads): array
    {
        $by = [];
        foreach (self::GROUPS as $g => $def) {
            $by[$g] = ['count' => 0, 'sum' => 0.0];
        }
        foreach ($leads as $l) {
            $g = $l['derived_stage'] ?? 'new_inquiry';
            $by[$g]['count']++;
            $by[$g]['sum'] += (float) ($l['expected_amount'] ?? 0);
        }
        $openAmount = 0.0;
        $openCount = 0;
        foreach (self::OPEN_GROUPS as $g) {
            $openAmount += $by[$g]['sum'];
            $openCount += $by[$g]['count'];
        }
        return [
            'total'           => count($leads),
            'by_group'        => $by,
            'open_count'      => $openCount,
            'open_amount'     => $openAmount,            // 진행(미계약) 예상 계약 금액 — 종료·보류·계약 제외
            'quoting_count'   => $by['quoting']['count'],
            'quoting_amount'  => $by['quoting']['sum'],
            'contracted_count'=> $by['contracted']['count'],
            'contracted_amount'=> $by['contracted']['sum'],
            'on_hold_count'   => $by['on_hold']['count'],
            'closed_count'    => $by['closed']['count'],
        ];
    }

    /** IN 절 플레이스홀더 생성. @return array{0:string,1:array} */
    private static function inClause(array $ids, string $prefix): array
    {
        $ph = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $key = ":{$prefix}{$i}";
            $ph[] = $key;
            $params[$key] = (int) $id;
        }
        return [implode(',', $ph), $params];
    }
}
