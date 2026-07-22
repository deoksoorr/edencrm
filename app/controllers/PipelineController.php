<?php
/**
 * 영업 파이프라인(칸반). 목록/이동/카드 상세/등록수정/삭제.
 * 카드 상세·폼은 모달로 처리하므로 show/form 은 JSON 을 반환한다(별도 뷰 없음).
 */
class PipelineController
{
    /** 전체 파이프라인 열람/이동 권한 여부(브리프 정의 그대로). */
    private function fullAccess(): bool
    {
        return Rbac::can('pipeline.manage') || Rbac::isRole('super_admin', 'sales_manager');
    }

    /**
     * 특정 lead 에 대한 접근 가능 여부(IDOR 방지).
     * super_admin/sales_manager 역할은 전체 접근. 그 외(staff/site_manager 가 개별 grant 로
     * pipeline.manage 만 받은 경우 등)는 본인 담당(sales_user_id) 건만 허용.
     */
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

    /** 칸반 컬럼 데이터 구성(검색/담당영업 필터 반영). */
    private function loadBoard(): array
    {
        [$scopeSql, $scopeParams] = $this->scopeCondition();
        $where = ['l.deleted_at IS NULL', $scopeSql];
        $params = $scopeParams;

        $q = Util::str('q');
        if ($q !== '') {
            $where[] = '(c.name LIKE :q1 OR c.company_name LIKE :q2 OR l.work_type LIKE :q3 OR l.site_address LIKE :q4)';
            $like = '%' . $q . '%';
            $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like; $params[':q4'] = $like;
        }
        $salesUserId = Util::int('sales_user_id', null);
        if ($salesUserId) {
            $where[] = 'l.sales_user_id = :su_f';
            $params[':su_f'] = $salesUserId;
        }
        $whereSql = implode(' AND ', $where);

        $leads = Db::all(
            "SELECT l.*, c.name AS customer_name, c.company_name, u.name AS sales_user_name,
                    DATEDIFF(CURDATE(), COALESCE(l.stage_entered_at, l.created_at)) AS stay_days
             FROM leads l
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN users u ON u.id = l.sales_user_id
             WHERE $whereSql
             ORDER BY (l.next_contact_date IS NULL), l.next_contact_date ASC, l.id DESC",
            $params
        );

        $today = date('Y-m-d');
        $warnUntil = date('Y-m-d', strtotime('+2 day'));
        foreach ($leads as &$l) {
            $amount = (float) $l['expected_amount'];
            $cost = (float) $l['expected_cost'];
            $l['profit'] = Calc::profit($amount, $cost);
            $l['profit_rate'] = Calc::profitRate($amount, $cost);
            $l['weighted_revenue'] = Calc::weightedRevenue($amount, (float) ($l['win_probability'] ?? 0));
            $l['delayed'] = !empty($l['next_contact_date']) && $l['next_contact_date'] < $today;
            $l['warn'] = !$l['delayed'] && !empty($l['next_contact_date']) && $l['next_contact_date'] <= $warnUntil;
        }
        unset($l);

        $stages = Db::all('SELECT * FROM pipeline_stages ORDER BY sort_order');
        $columns = [];
        foreach ($stages as $stage) {
            $columns[$stage['id']] = ['stage' => $stage, 'leads' => [], 'sum' => 0.0];
        }
        foreach ($leads as $l) {
            if (!isset($columns[$l['stage_id']])) {
                continue;
            }
            $columns[$l['stage_id']]['leads'][] = $l;
            $columns[$l['stage_id']]['sum'] += (float) $l['expected_amount'];
        }
        return $columns;
    }

    public function index(): void
    {
        $columns = $this->loadBoard();
        $fullAccess = $this->fullAccess();
        View::render('pipeline/index', [
            'title' => '영업 파이프라인',
            'columns' => $columns,
            'q' => Util::str('q'),
            'salesUserId' => Util::int('sales_user_id', null),
            'salesUsers' => $fullAccess ? $this->salesUserOptions() : [],
            'fullAccess' => $fullAccess,
            'canManage' => Rbac::can('pipeline.manage'),
            'scripts' => ['vendor/Sortable.min.js', 'js/pipeline.js'],
        ]);
    }

    /** AJAX 필터 새로고침용 칸반 조각 HTML. */
    public function board(): void
    {
        $columns = $this->loadBoard();
        $html = View::capture('pipeline/board', ['columns' => $columns, 'canManage' => Rbac::can('pipeline.manage')]);
        Response::json(['html' => $html]);
    }

    public function move(): void
    {
        $leadId = Util::postInt('lead_id', 0);
        $toStageId = Util::postInt('to_stage_id', 0);
        if (!$leadId || !$toStageId) {
            Response::error('잘못된 요청입니다.', 422);
        }

        $lead = Db::one('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL', [':id' => $leadId]);
        if (!$lead) {
            Response::error('영업기회를 찾을 수 없습니다.', 404);
        }
        $this->assertAccess($lead);

        $toStage = Db::one('SELECT * FROM pipeline_stages WHERE id = :id', [':id' => $toStageId]);
        if (!$toStage) {
            Response::error('대상 단계를 찾을 수 없습니다.', 422);
        }

        $before = ['stage_id' => $lead['stage_id']];
        Db::update('leads', [
            'stage_id' => $toStageId,
            'stage_entered_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', [':id' => $leadId]);
        Audit::log('lead.stage_change', 'leads', $leadId, $before, ['stage_id' => $toStageId]);

        $hint = null;
        if ((int) $toStage['is_won'] === 1) {
            $hint = '계약완료 처리되었습니다. 견적/계약 메뉴에서 계약을 등록해 주세요.';
        }

        Response::json(['lead_id' => $leadId, 'stage_id' => $toStageId, 'hint' => $hint]);
    }

    /** 카드 등록/수정 모달용 참조데이터 + (수정 시) 기존값. JSON 전용. */
    public function form(): void
    {
        $id = Util::int('id', 0);
        $lead = null;
        if ($id) {
            $lead = Db::one('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
            if (!$lead) {
                Response::error('영업기회를 찾을 수 없습니다.', 404);
            }
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
        if (!$customer) {
            Response::error('고객을 선택하세요.', 422);
        }

        $lead = null;
        if ($id) {
            $lead = Db::one('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
            if (!$lead) {
                Response::error('영업기회를 찾을 수 없습니다.', 404);
            }
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
            'next_contact_date' => Util::nullIfEmpty(Util::postStr('next_contact_date')),
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

        if (Response::wantsJson()) {
            Response::json(['id' => $id]);
        }
        Response::redirect('pipeline.index', [], '저장되었습니다.');
    }

    /** 카드 상세(읽기 전용). JSON 전용(모달 렌더는 pipeline.js). */
    public function show(): void
    {
        $id = Util::int('id', 0);
        $lead = Db::one(
            "SELECT l.*, c.name AS customer_name, c.company_name, c.phone AS customer_phone, c.site_address AS customer_site_address,
                    u.name AS sales_user_name, ps.name AS stage_name, ps.color AS stage_color, ps.is_won, ps.is_lost
             FROM leads l
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN users u ON u.id = l.sales_user_id
             JOIN pipeline_stages ps ON ps.id = l.stage_id
             WHERE l.id = :id AND l.deleted_at IS NULL",
            [':id' => $id]
        );
        if (!$lead) {
            Response::error('영업기회를 찾을 수 없습니다.', 404);
        }
        if (!$this->fullAccess() && (int) $lead['sales_user_id'] !== Auth::id()) {
            Response::error('본인 담당 영업기회만 열람할 수 있습니다.', 403);
        }

        $amount = (float) $lead['expected_amount'];
        $cost = (float) $lead['expected_cost'];
        $lead['profit'] = Calc::profit($amount, $cost);
        $lead['profit_rate'] = Calc::profitRate($amount, $cost);
        $lead['weighted_revenue'] = Calc::weightedRevenue($amount, (float) ($lead['win_probability'] ?? 0));
        $lead['stay_days'] = (int) Db::val(
            'SELECT DATEDIFF(CURDATE(), COALESCE(:se, :ca))',
            [':se' => $lead['stage_entered_at'], ':ca' => $lead['created_at']]
        );

        Response::json(['lead' => $lead]);
    }

    public function delete(): void
    {
        $id = Util::postInt('id', 0);
        $lead = Db::one('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
        if (!$lead) {
            Response::error('영업기회를 찾을 수 없습니다.', 404);
        }
        $this->assertAccess($lead);

        Db::update('leads', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
        Audit::log('lead.delete', 'leads', $id, $lead, null);

        if (Response::wantsJson()) {
            Response::json(['id' => $id]);
        }
        Response::redirect('pipeline.index', [], '영업기회가 삭제되었습니다.');
    }

    private function defaultStageId(): int
    {
        return (int) (Db::val("SELECT id FROM pipeline_stages ORDER BY sort_order ASC LIMIT 1") ?? 1);
    }

    private function salesUserOptions(int $includeId = 0): array
    {
        $rows = Db::all("SELECT id, name FROM users WHERE role_key = 'sales_manager' AND status = 'active' AND deleted_at IS NULL ORDER BY name");
        if ($includeId && !in_array($includeId, array_column($rows, 'id'), true)) {
            $extra = Db::one('SELECT id, name FROM users WHERE id = :id', [':id' => $includeId]);
            if ($extra) {
                $extra['name'] .= ' (비활성)';
                $rows[] = $extra;
            }
        }
        return $rows;
    }
}
