<?php
/**
 * 영업 파이프라인 — 조회 전용 보드(R4 T7).
 *
 * 보드 표시 단계는 PipelineStageService 가 원본 데이터에서 자동 산정한 7그룹 파생값이다.
 * DnD·단계 직접 변경·인라인 수정은 기능 제거(쓰기 라우트 pipeline.move/patch 삭제 → 404).
 * 리드 원본 데이터의 등록·수정(form/save)·삭제(delete)는 별도 페이지로 유지 — 12단계 stage_id 는
 * 산정 입력·이력으로 보존되며 수정 폼에서만 변경한다(보드에서는 불가).
 * 기간 필터는 R4 공통 규약(Util::periodRange + partials/period_filter) — 레거시 period
 * 키(7d/30d/90d/month)는 normalizePeriod() 가 하위호환 매핑한다(worklog [pipeline] 제거 일정).
 */
class PipelineController
{
    private const HIGH_VALUE = 100000000; // 고액 견적 기준(1억)

    /** 기간 기준 컬럼(basis) 정의: key => [라벨, SQL 컬럼식]. 기본 = 영업 등록일. */
    private const BASIS_COLS = [
        'created'      => ['label' => '등록일',      'col' => 'DATE(l.created_at)'],
        'last_contact' => ['label' => '최근 연락일', 'col' => 'l.last_activity_date'],
    ];

    private function fullAccess(): bool
    {
        return Rbac::can('pipeline.manage') || Rbac::isRole('super_admin', 'sales_manager');
    }

    private function assertAccess(array $lead): void
    {
        if (!Rbac::isRole('super_admin', 'sales_manager') && (int) $lead['sales_user_id'] !== Auth::id()) {
            Response::error('본인 담당 영업기회만 접근할 수 있습니다.', 403);
        }
    }

    private function scopeCondition(): array
    {
        if ($this->fullAccess()) {
            return ['1=1', []];
        }
        return ['l.sales_user_id = :su_self', [':su_self' => Auth::id()]];
    }

    /**
     * 레거시 period 키(7d/30d/90d/month) → 공통 규약 하위호환 매핑.
     * 7d/30d/90d 는 동일 경계의 custom(시작일만, 개구간)으로, month 는 this_month 로 변환.
     * @return array{0:string,1:string,2:string} [period, date_from, date_to]
     */
    public static function normalizePeriod(string $period, string $from, string $to, ?string $anchor = null): array
    {
        $legacyDays = ['7d' => 7, '30d' => 30, '90d' => 90];
        if ($period === 'month') {
            return ['this_month', '', ''];
        }
        if (isset($legacyDays[$period])) {
            $base = Util::dateOrNull($anchor) ?? date('Y-m-d');
            $f = (new DateTimeImmutable($base))->modify('-' . $legacyDays[$period] . ' days')->format('Y-m-d');
            return ['custom', $f, ''];
        }
        return [$period, $from, $to];
    }

    /** 현재 요청의 필터 상태(공통 기간 규약 포함). */
    private function filters(): array
    {
        $period = Util::str('period');
        $fromIn = Util::str('date_from');
        $toIn   = Util::str('date_to');
        [$period, $fromIn, $toIn] = self::normalizePeriod($period, $fromIn, $toIn);
        if ($period === '' && ($fromIn !== '' || $toIn !== '')) {
            $period = 'custom'; // 하위호환: date_from/to 직접 진입
        }
        if ($period !== '' && !isset(Util::PERIOD_PRESETS[$period])) {
            $period = ''; // 알 수 없는 키 → 전체
        }
        $range = Util::periodRange($period, $fromIn !== '' ? $fromIn : null, $toIn !== '' ? $toIn : null);
        $basis = Util::str('basis');
        if (!isset(self::BASIS_COLS[$basis])) {
            $basis = 'created';
        }
        return [
            'q'             => Util::str('q'),
            'sales_user_id' => Util::int('sales_user_id', null),
            'quick'         => Util::str('quick'),
            'period'        => $period,
            'date_from'     => $period === 'custom' ? (string) ($range['from'] ?? '') : '',
            'date_to'       => $period === 'custom' ? (string) ($range['to'] ?? '') : '',
            'basis'         => $basis,
            '_range'        => $range,
        ];
    }

    /** 필터 반영 리드 로드(파생 단계 산정 전 원본 행). */
    private function loadLeads(array $f): array
    {
        [$scopeSql, $scopeParams] = $this->scopeCondition();
        $where = ['l.deleted_at IS NULL', $scopeSql];
        $params = $scopeParams;
        $today = date('Y-m-d');

        if ($f['q'] !== '') {
            $where[] = '(c.name LIKE :q1 OR c.company_name LIKE :q2 OR c.phone LIKE :q3 OR l.work_type LIKE :q4 OR l.site_address LIKE :q5)';
            $like = '%' . $f['q'] . '%';
            $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like];
        }
        if ($f['sales_user_id']) {
            $where[] = 'l.sales_user_id = :su_f';
            $params[':su_f'] = $f['sales_user_id'];
        }
        // 기간(공통 규약): 기준 컬럼(basis) 폐구간 — 견적·계약 탭과 동일 경계(Util::periodRange 단일 계산)
        $col = self::BASIS_COLS[$f['basis']]['col'];
        if ($f['_range']['from'] !== null) {
            $where[] = "$col >= :pf";
            $params[':pf'] = $f['_range']['from'];
        }
        if ($f['_range']['to'] !== null) {
            $where[] = "$col <= :pt";
            $params[':pt'] = $f['_range']['to'];
        }
        // 빠른 필터(대시보드 링크·플래그 칩 공용 — 원본 단계 신호 기준, r3 유지)
        switch ($f['quick']) {
            case 'overdue':
                $where[] = 'l.next_contact_date IS NOT NULL AND l.next_contact_date < :qd AND ps.is_won=0 AND ps.is_lost=0';
                $params[':qd'] = $today; break;
            case 'stale':
                $where[] = '(l.next_contact_date IS NULL OR l.stage_entered_at < CURDATE()-INTERVAL 3 DAY) AND ps.is_won=0 AND ps.is_lost=0';
                break;
            case 'today':
                $where[] = 'l.next_contact_date = :qt'; $params[':qt'] = $today; break;
            case 'highvalue':
                $where[] = 'l.expected_amount >= :qv'; $params[':qv'] = self::HIGH_VALUE; break;
            case 'closing':
                $where[] = "ps.stage_key IN ('negotiating','contract_pending')"; break;
            case 'longstay':
                $where[] = 'l.stage_entered_at < CURDATE()-INTERVAL 30 DAY AND ps.is_won=0 AND ps.is_lost=0'; break;
            case 'unassigned':
                $where[] = 'l.sales_user_id IS NULL'; break;
        }
        $whereSql = implode(' AND ', $where);

        $leads = Db::all(
            "SELECT l.*, c.name AS customer_name, c.company_name, u.name AS sales_user_name,
                    ps.stage_key, ps.name AS stage_name,
                    DATEDIFF(CURDATE(), COALESCE(l.stage_entered_at, l.created_at)) AS stay_days
             FROM leads l
             JOIN customers c ON c.id = l.customer_id
             JOIN pipeline_stages ps ON ps.id = l.stage_id
             LEFT JOIN users u ON u.id = l.sales_user_id
             WHERE $whereSql
             ORDER BY (l.next_contact_date IS NULL), l.next_contact_date ASC, l.id DESC",
            $params
        );

        $warnUntil = date('Y-m-d', strtotime('+2 day'));
        foreach ($leads as &$l) {
            $l['delayed'] = !empty($l['next_contact_date']) && $l['next_contact_date'] < $today;
            $l['warn'] = !$l['delayed'] && !empty($l['next_contact_date']) && $l['next_contact_date'] <= $warnUntil;
        }
        unset($l);
        return $leads;
    }

    public function index(): void
    {
        // R16: 휴지통 목록은 최고운영자 전용 — trash=1 진입 자체를 403 으로 끊는다(일반 목록 폴백 금지).
        if (Util::int('trash', 0) === 1) {
            Perm::requireSuperAdmin('pipeline.trash');
            $this->trashIndex();
            return;
        }

        $f = $this->filters();
        $leads = PipelineStageService::attachSignals($this->loadLeads($f));

        // 파생 7그룹 컬럼 구성(빈 그룹 포함 — 컬럼 고정)
        $columns = [];
        foreach (PipelineStageService::GROUPS as $gkey => $def) {
            $columns[$gkey] = ['key' => $gkey, 'label' => $def['label'], 'color' => $def['color'], 'leads' => [], 'sum' => 0.0];
        }
        foreach ($leads as $l) {
            $g = $l['derived_stage'];
            $columns[$g]['leads'][] = $l;
            $columns[$g]['sum'] += (float) $l['expected_amount'];
        }

        $fullAccess = $this->fullAccess();
        View::render('pipeline/index', [
            'title'      => '영업 파이프라인',
            'columns'    => $columns,
            'summary'    => PipelineStageService::summarize($leads),
            'total'      => $this->totalCount(),
            'filters'    => $f,
            'range'      => $f['_range'],
            'basisOptions' => array_map(static fn($b) => $b['label'], self::BASIS_COLS),
            'salesUsers' => $fullAccess ? $this->salesUserOptions() : [],
            'fullAccess' => $fullAccess,
            'canManage'  => Rbac::can('pipeline.manage'),
            'quickAlertsOn' => Settings::enabled('feature_pipeline_quick_alerts'),
            'scripts'    => ['js/pipeline.js'],
        ]);
    }

    /**
     * 휴지통 목록(소프트삭제 영업기회) — 파생 단계 보드가 아니라 표로 표시한다.
     * 삭제된 리드는 단계 산정·요약의 입력이 아니므로 보드 컬럼에 섞지 않는다.
     * 고객이 함께 휴지통에 있으면 복원이 막히므로(부모 우선) 화면에서 미리 표시한다.
     */
    private function trashIndex(): void
    {
        $q = Util::str('q');
        $where = ['l.deleted_at IS NOT NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(c.name LIKE :q1 OR c.company_name LIKE :q2 OR l.work_type LIKE :q3 OR l.site_address LIKE :q4)';
            $like = '%' . $q . '%';
            $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }
        $rows = Db::all(
            "SELECT l.id, l.work_type, l.site_address, l.expected_amount, l.deleted_at,
                    c.name AS customer_name, c.deleted_at AS customer_deleted_at,
                    ps.name AS stage_name, u.name AS sales_user_name
             FROM leads l
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN pipeline_stages ps ON ps.id = l.stage_id
             LEFT JOIN users u ON u.id = l.sales_user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY l.deleted_at DESC, l.id DESC LIMIT 300",
            $params
        );

        View::render('pipeline/index', [
            'title'   => '영업 파이프라인 — 휴지통',
            'trash'   => true,
            'rows'    => $rows,
            'trashQ'  => $q,
        ]);
    }

    private function totalCount(): int
    {
        [$scopeSql, $scopeParams] = $this->scopeCondition();
        return (int) Db::val("SELECT COUNT(*) FROM leads l WHERE l.deleted_at IS NULL AND $scopeSql", $scopeParams);
    }

    /** 리드 상세 페이지(조회 전용 — 수정은 별도 form 페이지). */
    public function show(): void
    {
        $id = Util::int('id', 0);
        $lead = Db::one(
            "SELECT l.*, c.name AS customer_name, c.company_name, c.phone AS customer_phone, c.site_address AS customer_site_address,
                    c.source AS customer_source, u.name AS sales_user_name, ps.name AS stage_name, ps.stage_key, ps.color AS stage_color,
                    ps.is_won, ps.is_lost
             FROM leads l
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN users u ON u.id = l.sales_user_id
             JOIN pipeline_stages ps ON ps.id = l.stage_id
             WHERE l.id = :id AND l.deleted_at IS NULL",
            [':id' => $id]
        );
        if (!$lead) {
            http_response_code(404);
            View::renderError(404, '영업기회를 찾을 수 없음', '요청하신 영업기회가 존재하지 않거나 삭제되었습니다.');
            return;
        }
        if (!$this->fullAccess() && (int) $lead['sales_user_id'] !== Auth::id()) {
            http_response_code(403);
            View::renderError(403, '접근 권한 없음', '본인 담당 영업기회만 열람할 수 있습니다.');
            return;
        }

        $amount = (float) $lead['expected_amount'];
        $cost = (float) $lead['expected_cost'];
        $lead['profit'] = Calc::profit($amount, $cost);
        $lead['profit_rate'] = Calc::profitRate($amount, $cost);
        $lead['weighted_revenue'] = Calc::weightedRevenue($amount, (float) ($lead['win_probability'] ?? 0));
        $lead['stay_days'] = (int) Db::val('SELECT DATEDIFF(CURDATE(), COALESCE(:se, :ca))', [':se' => $lead['stage_entered_at'], ':ca' => $lead['created_at']]);
        $lead = PipelineStageService::attachSignals([$lead])[0];

        $activities = Db::all(
            "SELECT a.activity_type AS type, a.content, a.created_at, u.name AS user_name
             FROM customer_activities a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.customer_id = :cid ORDER BY a.created_at DESC LIMIT 5",
            [':cid' => $lead['customer_id']]
        );

        View::render('pipeline/show', [
            'title'      => '영업기회 상세 — ' . $lead['customer_name'],
            'lead'       => $lead,
            'activities' => $activities,
            'canManage'  => Rbac::can('pipeline.manage'),
        ]);
    }

    /** 리드 등록·수정 폼 페이지(원본 데이터 관리 — 보드 인라인 수정 대체). */
    public function form(): void
    {
        $id = Util::int('id', 0);
        $lead = null;
        if ($id) {
            $lead = Db::one('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
            if (!$lead) {
                http_response_code(404);
                View::renderError(404, '영업기회를 찾을 수 없음', '요청하신 영업기회가 존재하지 않거나 삭제되었습니다.');
                return;
            }
            $this->assertAccess($lead);
        }
        $customerId = Util::int('customer_id', (int) ($lead['customer_id'] ?? 0));
        View::render('pipeline/form', [
            'title'      => $id ? '영업기회 수정' : '신규 영업기회',
            'lead'       => $lead,
            'stages'     => Db::all('SELECT id, name, sort_order FROM pipeline_stages ORDER BY sort_order'),
            'salesUsers' => $this->salesUserOptions((int) ($lead['sales_user_id'] ?? Auth::id())),
            'customers'  => Db::all("SELECT id, name, company_name, phone FROM customers WHERE deleted_at IS NULL ORDER BY name"),
            'defaultCustomerId' => $customerId ?: null,
        ]);
    }

    public function save(): void
    {
        $id = Util::postInt('id', 0);
        $customerId = Util::postInt('customer_id', 0);
        $customer = Db::one('SELECT id FROM customers WHERE id = :id AND deleted_at IS NULL', [':id' => $customerId]);
        if (!$customer) { Response::error('고객을 선택하세요.', 422); }

        $lead = null;
        if ($id) {
            $lead = Db::one('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
            if (!$lead) { Response::error('영업기회를 찾을 수 없습니다.', 404); }
            $this->assertAccess($lead);
        }

        $stageId = Util::postInt('stage_id', 0) ?: (int) ($lead['stage_id'] ?? $this->defaultStageId());
        $amount = Util::postFloat('expected_amount', 0.0) ?? 0.0;
        $cost = Util::postFloat('expected_cost', 0.0) ?? 0.0;
        $winProb = Util::postFloat('win_probability', null);

        $data = [
            'customer_id' => $customerId,
            'sales_user_id' => Util::postInt('sales_user_id', null) ?: null,
            'stage_id' => $stageId,
            'work_type' => Util::nullIfEmpty(Util::postStr('work_type')),
            'site_address' => Util::nullIfEmpty(Util::postStr('site_address')),
            'expected_amount' => $amount,
            'expected_cost' => $cost,
            'win_probability' => $winProb,
            'expected_profit' => Calc::profit($amount, $cost),
            'next_contact_date' => Util::dateOrNull(Util::postStr('next_contact_date')),
            'tags' => Util::nullIfEmpty(Util::postStr('tags')),
            'memo' => Util::nullIfEmpty(Util::postStr('memo')),
        ];

        if ($id) {
            if ((int) $stageId !== (int) $lead['stage_id']) {
                $data['stage_entered_at'] = date('Y-m-d H:i:s');
            }
            Db::update('leads', $data, 'id = :id', [':id' => $id]);
            Audit::log('lead.update', 'leads', $id, $lead, $data);
        } else {
            $data['stage_entered_at'] = date('Y-m-d H:i:s');
            $data['last_activity_date'] = date('Y-m-d');
            $id = Db::insert('leads', $data);
            Audit::log('lead.create', 'leads', $id, null, $data);
        }

        if (Response::wantsJson()) { Response::json(['id' => $id]); }
        Response::redirect('pipeline.show', ['id' => $id], '저장되었습니다.');
    }

    public function delete(): void
    {
        $id = Util::postInt('id', 0);
        $lead = Db::one('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
        if (!$lead) { Response::error('영업기회를 찾을 수 없습니다.', 404); }
        $this->assertAccess($lead);

        Db::update('leads', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
        Audit::log('lead.delete', 'leads', $id, $lead, null);

        if (Response::wantsJson()) { Response::json(['id' => $id]); }
        Response::redirect('pipeline.index', [], '영업기회가 휴지통으로 이동되었습니다.');
    }

    /* ───────────────────── 휴지통: 복원·완전삭제 (R16 T4) ─────────────────────
     * 견적·계약·프로젝트(R15)와 동일 정책 — 목록 진입·복원·완전삭제 전부 최고운영자 전용.
     * 영업기회는 quotes.lead_id(FK RESTRICT)의 부모이자 customers 의 자식이다. */

    /** 완전삭제 차단 사유 — 활성(미삭제) 견적이 참조 중이면 차단. 없으면 null. */
    public static function purgeBlockReason(int $leadId): ?string
    {
        $n = (int) Db::val("SELECT COUNT(*) FROM quotes WHERE lead_id = :id AND deleted_at IS NULL", [':id' => $leadId]);
        return $n > 0
            ? "연결된 기록(견적 {$n}건)이 있어 완전삭제할 수 없습니다. 기록 보존을 위해 휴지통에 유지하세요."
            : null;
    }

    /**
     * 휴지통에 남은 견적 참조 — 소프트삭제 견적도 FK(RESTRICT)가 영업기회 물리 삭제를 막는다.
     * 견적 휴지통에서 먼저 완전삭제해야 한다. 없으면 null.
     */
    public static function purgeResidualReason(int $leadId): ?string
    {
        $n = (int) Db::val("SELECT COUNT(*) FROM quotes WHERE lead_id = :id AND deleted_at IS NOT NULL", [':id' => $leadId]);
        return $n > 0 ? "휴지통에 남아 있는 견적 {$n}건을 견적 휴지통에서 먼저 완전삭제하세요." : null;
    }

    /** 복원 차단 사유 — 부모 고객이 아직 휴지통이면 복원해도 목록에 뜨지 않는다(고객 먼저 복원). */
    public static function restoreBlockReason(int $leadId): ?string
    {
        $customerDeletedAt = Db::val(
            "SELECT c.deleted_at FROM leads l JOIN customers c ON c.id = l.customer_id WHERE l.id = :id",
            [':id' => $leadId]
        );
        return $customerDeletedAt !== null
            ? '고객이 휴지통에 있어 영업기회를 복원할 수 없습니다. 고객을 먼저 복원하세요.'
            : null;
    }

    /**
     * 영업기회 완전삭제 실행(정적, 테스트/액션 공용) — leads 는 소유 하위 테이블이 없어 행만 물리 삭제.
     * 참조가 남아 있으면 예외로 트랜잭션 전체를 롤백한다(FK 위반·부분 삭제 방지).
     * 이미 트랜잭션 안이면 그대로 참여(중첩 begin 금지 — QuotesController::purgeQuote 패턴).
     */
    public static function purgeLead(int $id): void
    {
        $run = function () use ($id) {
            $reason = self::purgeBlockReason($id) ?? self::purgeResidualReason($id);
            if ($reason !== null) {
                throw new RuntimeException($reason);
            }
            Db::run("DELETE FROM leads WHERE id = :id", [':id' => $id]);
        };
        if (Db::pdo()->inTransaction()) {
            $run();
        } else {
            Db::transaction($run);
        }
    }

    /** 복원 실행(정적, 테스트/액션 공용) — deleted_at 해제. */
    public static function restoreLead(int $id): void
    {
        Db::update('leads', ['deleted_at' => null], 'id = :id', [':id' => $id]);
    }

    /** 완전삭제(super_admin 전용) — 휴지통(deleted_at IS NOT NULL)에 있는 영업기회만. */
    public function purge(): void
    {
        Perm::requireSuperAdmin('pipeline.purge');   // R16: 라우터 trash.manage 와 이중 가드
        $id = (int) Util::postInt('id', 0);
        $row = Db::one("SELECT * FROM leads WHERE id = :id AND deleted_at IS NOT NULL", [':id' => $id]);
        if (!$row) {
            Response::redirect('pipeline.index', ['trash' => 1], '휴지통에 있는 영업기회만 완전삭제할 수 있습니다.', 'error');
        }
        $reason = self::purgeBlockReason($id) ?? self::purgeResidualReason($id);
        if ($reason !== null) {
            Response::redirect('pipeline.index', ['trash' => 1], $reason, 'error');
        }
        // 감사 로그의 before 는 삭제 직전 스냅샷($row) — 고객명은 행 소멸 후 조인이 불가하므로 미리 확보한다.
        $label = (string) (Db::val("SELECT name FROM customers WHERE id = :id", [':id' => $row['customer_id']]) ?? '');
        try {
            self::purgeLead($id);
        } catch (\Throwable $e) {
            error_log('[PipelineController::purge] ' . $e->getMessage());
            Response::redirect('pipeline.index', ['trash' => 1], '완전삭제에 실패했습니다: ' . $e->getMessage(), 'error');
        }
        Audit::log('trash_purge', 'leads', $id, $row, ['name' => $label . ' / ' . (string) ($row['work_type'] ?? '')]);
        Response::redirect('pipeline.index', ['trash' => 1], '완전삭제되었습니다.');
    }

    /** 복원(휴지통 → 정상) — super_admin 전용. */
    public function restore(): void
    {
        Perm::requireSuperAdmin('pipeline.restore');  // R16: 라우터 trash.manage 와 이중 가드
        $id = (int) Util::postInt('id', 0);
        $row = Db::one("SELECT * FROM leads WHERE id = :id AND deleted_at IS NOT NULL", [':id' => $id]);
        if (!$row) {
            Response::redirect('pipeline.index', ['trash' => 1], '휴지통에 있는 영업기회만 복원할 수 있습니다.', 'error');
        }
        $reason = self::restoreBlockReason($id);
        if ($reason !== null) {
            Response::redirect('pipeline.index', ['trash' => 1], $reason, 'error');
        }
        self::restoreLead($id);
        Audit::log('trash_restore', 'leads', $id, $row, null);
        Response::redirect('pipeline.index', ['trash' => 1], '영업기회가 복원되었습니다.');
    }

    private function defaultStageId(): int
    {
        return (int) (Db::val("SELECT id FROM pipeline_stages ORDER BY sort_order ASC LIMIT 1") ?? 1);
    }

    private function salesUserOptions(int $includeId = 0): array
    {
        $rows = Db::all("SELECT id, name FROM users WHERE role_key = 'sales_manager' AND status = 'active' AND deleted_at IS NULL ORDER BY name");
        if ($includeId && !in_array($includeId, array_map('intval', array_column($rows, 'id')), true)) {
            $extra = Db::one('SELECT id, name FROM users WHERE id = :id', [':id' => $includeId]);
            if ($extra) { $extra['name'] .= ' (비활성)'; $rows[] = $extra; }
        }
        return $rows;
    }
}
