<?php
/**
 * 영업 파이프라인(칸반) — 12 DB단계 유지 + 6그룹 탭(클라이언트 전환) / 서버측 필터·검색·빠른필터.
 * 카드 상세·폼·빠른수정은 JSON 을 반환(우측 슬라이드 패널 / 모달은 pipeline.js 가 렌더).
 */
class PipelineController
{
    private const HIGH_VALUE = 100000000; // 고액 견적 기준(1억)

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

    /** 현재 요청의 필터 상태(뷰·board 공용). */
    private function filters(): array
    {
        return [
            'q'          => Util::str('q'),
            'sales_user_id' => Util::int('sales_user_id', null),
            'importance' => in_array(Util::str('importance'), ['high', 'mid', 'low'], true) ? Util::str('importance') : '',
            'work_type'  => Util::str('work_type'),
            'quick'      => Util::str('quick'),
            'tab'        => Util::str('tab') ?: 'all',
        ];
    }

    /** 칸반 컬럼 데이터 구성(필터 반영). */
    private function loadBoard(array $f): array
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
        if ($f['importance'] !== '') {
            $where[] = 'l.importance = :imp';
            $params[':imp'] = $f['importance'];
        }
        if ($f['work_type'] !== '') {
            $where[] = 'l.work_type LIKE :wt';
            $params[':wt'] = '%' . $f['work_type'] . '%';
        }
        // 빠른 필터
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
            "SELECT l.*, c.name AS customer_name, c.company_name, u.name AS sales_user_name, ps.stage_key,
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
        $g2g = Stages::stageToGroup();
        foreach ($leads as &$l) {
            $amount = (float) $l['expected_amount'];
            $cost = (float) $l['expected_cost'];
            $l['profit'] = Calc::profit($amount, $cost);
            $l['profit_rate'] = Calc::profitRate($amount, $cost);
            $l['weighted_revenue'] = Calc::weightedRevenue($amount, (float) ($l['win_probability'] ?? 0));
            $l['delayed'] = !empty($l['next_contact_date']) && $l['next_contact_date'] < $today;
            $l['warn'] = !$l['delayed'] && !empty($l['next_contact_date']) && $l['next_contact_date'] <= $warnUntil;
            $l['group'] = $g2g[$l['stage_key']] ?? 'closed';
        }
        unset($l);

        $groupDefs = Stages::pipelineGroups();
        $stages = Db::all('SELECT * FROM pipeline_stages ORDER BY sort_order');
        $columns = [];
        foreach ($stages as $stage) {
            $gkey = $g2g[$stage['stage_key']] ?? 'closed';
            $columns[$stage['id']] = [
                'stage' => $stage,
                'group' => $gkey,
                'group_label' => $groupDefs[$gkey]['label'] ?? '',
                'group_color' => $groupDefs[$gkey]['color'] ?? '#9ca3af',
                'leads' => [],
                'sum' => 0.0,
            ];
        }
        foreach ($leads as $l) {
            if (!isset($columns[$l['stage_id']])) { continue; }
            $columns[$l['stage_id']]['leads'][] = $l;
            $columns[$l['stage_id']]['sum'] += (float) $l['expected_amount'];
        }
        return [$columns, count($leads)];
    }

    public function index(): void
    {
        $f = $this->filters();
        [$columns, $shown] = $this->loadBoard($f);
        $fullAccess = $this->fullAccess();
        View::render('pipeline/index', [
            'title' => '영업 파이프라인',
            'columns' => $columns,
            'shown' => $shown,
            'total' => $this->totalCount(),
            'filters' => $f,
            'groups' => Stages::pipelineGroups(),
            'tabs' => Stages::pipelineTabs(),
            'salesUsers' => $fullAccess ? $this->salesUserOptions() : [],
            'workTypes' => $this->workTypeOptions(),
            'fullAccess' => $fullAccess,
            'canManage' => Rbac::can('pipeline.manage'),
            'scripts' => ['vendor/Sortable.min.js', 'js/pipeline.js'],
        ]);
    }

    /** AJAX 필터 새로고침용 칸반 조각 HTML + 카운트. */
    public function board(): void
    {
        $f = $this->filters();
        [$columns, $shown] = $this->loadBoard($f);
        $html = View::capture('pipeline/board', [
            'columns' => $columns,
            'canManage' => Rbac::can('pipeline.manage'),
        ]);
        Response::json(['html' => $html, 'shown' => $shown, 'total' => $this->totalCount()]);
    }

    private function totalCount(): int
    {
        [$scopeSql, $scopeParams] = $this->scopeCondition();
        return (int) Db::val("SELECT COUNT(*) FROM leads l WHERE l.deleted_at IS NULL AND $scopeSql", $scopeParams);
    }

    public function move(): void
    {
        $leadId = Util::postInt('lead_id', 0);
        $toStageId = Util::postInt('to_stage_id', 0);
        if (!$leadId || !$toStageId) {
            Response::error('잘못된 요청입니다.', 422);
        }
        $lead = Db::one('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL', [':id' => $leadId]);
        if (!$lead) { Response::error('영업기회를 찾을 수 없습니다.', 404); }
        $this->assertAccess($lead);

        $toStage = Db::one('SELECT * FROM pipeline_stages WHERE id = :id', [':id' => $toStageId]);
        if (!$toStage) { Response::error('대상 단계를 찾을 수 없습니다.', 422); }

        $before = ['stage_id' => $lead['stage_id']];
        Db::update('leads', ['stage_id' => $toStageId, 'stage_entered_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $leadId]);
        Audit::log('lead.stage_change', 'leads', $leadId, $before, ['stage_id' => $toStageId]);

        $hint = (int) $toStage['is_won'] === 1
            ? '계약완료 처리되었습니다. 견적/계약 메뉴에서 계약을 등록해 주세요.' : null;
        Response::json(['lead_id' => $leadId, 'stage_id' => $toStageId, 'hint' => $hint]);
    }

    /** 드로어 빠른 수정: 담당·다음연락일·중요도만 부분 갱신. */
    public function patch(): void
    {
        $id = Util::postInt('id', 0);
        $lead = Db::one('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
        if (!$lead) { Response::error('영업기회를 찾을 수 없습니다.', 404); }
        $this->assertAccess($lead);

        $data = [];
        if (array_key_exists('sales_user_id', $_POST)) {
            $data['sales_user_id'] = Util::postInt('sales_user_id', null) ?: null;
        }
        if (array_key_exists('next_contact_date', $_POST)) {
            $data['next_contact_date'] = Util::dateOrNull(Util::postStr('next_contact_date'));
        }
        if (array_key_exists('importance', $_POST)) {
            $imp = Util::postStr('importance');
            $data['importance'] = in_array($imp, ['high', 'mid', 'low'], true) ? $imp : $lead['importance'];
        }
        if (!$data) { Response::error('변경할 항목이 없습니다.', 422); }

        Db::update('leads', $data, 'id = :id', [':id' => $id]);
        Audit::log('lead.patch', 'leads', $id, $lead, $data);
        Response::json(['id' => $id]);
    }

    public function form(): void
    {
        $id = Util::int('id', 0);
        $lead = null;
        if ($id) {
            $lead = Db::one('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
            if (!$lead) { Response::error('영업기회를 찾을 수 없습니다.', 404); }
            $this->assertAccess($lead);
        }
        $customerId = Util::int('customer_id', (int) ($lead['customer_id'] ?? 0));
        Response::json([
            'lead' => $lead,
            'stages' => Db::all('SELECT id, name, sort_order FROM pipeline_stages ORDER BY sort_order'),
            'salesUsers' => $this->salesUserOptions((int) ($lead['sales_user_id'] ?? Auth::id())),
            'customers' => Db::all("SELECT id, name, company_name, phone FROM customers WHERE deleted_at IS NULL ORDER BY name"),
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
        $importance = in_array(Util::postStr('importance'), ['low', 'mid', 'high'], true) ? Util::postStr('importance') : 'mid';

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
            'importance' => $importance,
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
        Response::redirect('pipeline.index', [], '저장되었습니다.');
    }

    /** 카드 상세(드로어). 리드 + 최근 활동 + 최근 견적 + 담당. */
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
        if (!$lead) { Response::error('영업기회를 찾을 수 없습니다.', 404); }
        if (!$this->fullAccess() && (int) $lead['sales_user_id'] !== Auth::id()) {
            Response::error('본인 담당 영업기회만 열람할 수 있습니다.', 403);
        }

        $amount = (float) $lead['expected_amount'];
        $cost = (float) $lead['expected_cost'];
        $lead['profit'] = Calc::profit($amount, $cost);
        $lead['profit_rate'] = Calc::profitRate($amount, $cost);
        $lead['weighted_revenue'] = Calc::weightedRevenue($amount, (float) ($lead['win_probability'] ?? 0));
        $lead['group'] = Stages::groupOf($lead['stage_key']);
        $lead['group_color'] = Stages::groupColor($lead['group']);
        $lead['importance_label'] = Stages::importanceLabel($lead['importance']);
        $lead['stay_days'] = (int) Db::val('SELECT DATEDIFF(CURDATE(), COALESCE(:se, :ca))', [':se' => $lead['stage_entered_at'], ':ca' => $lead['created_at']]);

        $activities = Db::all(
            "SELECT a.activity_type AS type, a.content, a.created_at, u.name AS user_name
             FROM customer_activities a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.customer_id = :cid ORDER BY a.created_at DESC LIMIT 5",
            [':cid' => $lead['customer_id']]
        );
        $quote = Db::one(
            "SELECT id, quote_no, status FROM quotes
             WHERE customer_id = :cid AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
            [':cid' => $lead['customer_id']]
        );

        Response::json([
            'lead' => $lead,
            'activities' => $activities,
            'quote' => $quote,
            'stages' => Db::all('SELECT id, name, stage_key FROM pipeline_stages ORDER BY sort_order'),
            'salesUsers' => $this->salesUserOptions((int) ($lead['sales_user_id'] ?? 0)),
            'canManage' => Rbac::can('pipeline.manage'),
        ]);
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
        Response::redirect('pipeline.index', [], '영업기회가 삭제되었습니다.');
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

    private function workTypeOptions(): array
    {
        return array_column(
            Db::all("SELECT DISTINCT work_type FROM leads WHERE deleted_at IS NULL AND work_type IS NOT NULL AND work_type<>'' ORDER BY work_type"),
            'work_type'
        );
    }
}
