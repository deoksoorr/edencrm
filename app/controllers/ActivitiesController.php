<?php
/**
 * 고객 활동(상담 타임라인) 기록. customer_activities.
 */
class ActivitiesController
{
    private const TYPES = ['call', 'visit', 'sms', 'email', 'note'];

    public function save(): void
    {
        $customerId = Util::postInt('customer_id', 0);
        [$scopeSql, $scopeParams] = Scope::customerWhere('c');
        $customer = Db::one("SELECT c.* FROM customers c WHERE c.id = :id AND c.deleted_at IS NULL AND $scopeSql", [':id' => $customerId] + $scopeParams);
        if (!$customer) {
            Response::error('고객을 찾을 수 없습니다.', 404);
        }

        $type = Util::postStr('activity_type');
        if (!in_array($type, self::TYPES, true)) {
            Response::error('활동 유형이 올바르지 않습니다.', 422);
        }
        $content = trim(Util::postStr('content'));
        $activityAt = Util::nullIfEmpty(Util::postStr('activity_at')) ?? date('Y-m-d H:i:s');

        $id = Db::insert('customer_activities', [
            'customer_id' => $customerId,
            'user_id' => Auth::id(),
            'activity_type' => $type,
            'content' => $content !== '' ? $content : null,
            'activity_at' => date('Y-m-d H:i:s', strtotime($activityAt) ?: time()),
        ]);

        // 최근상담일 갱신(기존 값보다 최신인 경우만)
        $activityDate = date('Y-m-d', strtotime($activityAt) ?: time());
        if (empty($customer['last_consult_date']) || $activityDate >= $customer['last_consult_date']) {
            Db::update('customers', ['last_consult_date' => $activityDate], 'id = :id', [':id' => $customerId]);
        }

        Audit::log('customer.activity_add', 'customer_activities', $id, null, ['customer_id' => $customerId, 'type' => $type]);

        $activity = Db::one(
            "SELECT a.*, u.name AS user_name FROM customer_activities a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = :id",
            [':id' => $id]
        );

        if (Response::wantsJson()) {
            Response::json(['activity' => $activity]);
        }
        Response::redirect('customers.show', ['id' => $customerId], '활동이 기록되었습니다.');
    }
}
