<?php
/**
 * 원가(costs) 도메인 단일 출처 — 카테고리 표준 키, 금액 자동계산, 원가 총액 재계산.
 * 정책(브리프 §3):
 *  - projects.actual_cost = SUM(costs WHERE type='actual' AND cost_status='confirmed')
 *    비용 CRUD 후 반드시 recalcProject() 호출 (DB 트리거 사용 금지).
 *  - 자재비 = qty × unit_price, 인건비 = (work_days 또는 work_hours) × unit_price.
 *    자동값과 다른 수동 금액은 adjust_reason 필수 (컨트롤러가 서버 검증).
 *  - 취소(cancelled)·임시 저장(draft)·확인 대기(pending)는 원가 총액에서 제외.
 */
class CostService
{
    /** 표준 카테고리 키 → 한글 라벨 (브리프 §3 확정 8종). */
    public const CATEGORIES = [
        'material'    => '자재비',
        'labor'       => '인건비',
        'outsourcing' => '외주비',
        'equipment'   => '장비비',
        'transport'   => '운송비',
        'meal'        => '식비',
        'waste'       => '폐기물 처리비',
        'etc'         => '기타',
    ];

    /**
     * 구분별 입력 가이드 — 화면 안내와 서버 오류 문구가 같은 문장을 쓰도록 여기 한 곳에 둔다.
     * 따로 관리하면 한쪽만 바뀌어 "화면은 이렇게 하라는데 서버는 다른 걸 요구"하는
     * 상태가 된다(2026-07-31 지출 장애가 정확히 그 형태였다).
     *
     * qty_label  : 수량 칸에 무엇을 넣는지
     * unit_label : 단가 칸에 무엇을 넣는지
     * example    : 실제 입력 예시
     * hint       : 한 줄 요약(구분 선택 시 화면에 표시)
     */
    public const CATEGORY_GUIDE = [
        'material'    => ['qty_label' => '자재 수량', 'unit_label' => '자재 단가',
                          'example'   => '페인트 2말 × 45,000원',
                          'hint'      => '수량 × 단가 — 자재를 몇 개 샀고 개당 얼마인지'],
        'labor'       => ['qty_label' => '작업 일수(또는 시간)', 'unit_label' => '일당(또는 시급)',
                          'example'   => '3일 × 200,000원',
                          'hint'      => '일수 × 일당 — 수량 대신 아래 「일수/시간」 칸을 채우세요'],
        'outsourcing' => ['qty_label' => '건수(보통 1)', 'unit_label' => '건당 금액',
                          'example'   => '도장 외주 1식 × 5,000,000원',
                          'hint'      => '건수 × 건당 금액 — 일괄 계약이면 수량 1, 단가에 총액'],
        'equipment'   => ['qty_label' => '대수 또는 일수', 'unit_label' => '대당·일당 임대료',
                          'example'   => '고소작업대 2일 × 150,000원',
                          'hint'      => '대수(또는 일수) × 단가'],
        'transport'   => ['qty_label' => '운행 횟수', 'unit_label' => '회당 운임',
                          'example'   => '자재 운반 3회 × 50,000원',
                          'hint'      => '횟수 × 회당 운임'],
        'meal'        => ['qty_label' => '인원수', 'unit_label' => '1인당 금액',
                          'example'   => '인부 4명 × 12,000원',
                          'hint'      => '인원 × 1인당 금액'],
        'waste'       => ['qty_label' => '처리 수량(보통 1)', 'unit_label' => '처리 단가',
                          'example'   => '폐페인트 처리 1식 × 300,000원',
                          'hint'      => '수량 × 단가 — 일괄 처리면 수량 1, 단가에 총액'],
        'etc'         => ['qty_label' => '수량(보통 1)', 'unit_label' => '단가',
                          'example'   => '기타 비용 1건 × 80,000원',
                          'hint'      => '수량 × 단가 — 단건이면 수량 1, 단가에 총액'],
    ];

    /** 구분의 입력 가이드. 알 수 없는 구분이면 기타 기준으로 돌려준다. */
    public static function guide(string $category): array
    {
        return self::CATEGORY_GUIDE[$category] ?? self::CATEGORY_GUIDE['etc'];
    }

    /** 비용 상태 키 → 한글 라벨. 원가 총액에는 confirmed 만 포함. */
    public const STATUSES = [
        'draft'     => '임시 저장',
        'pending'   => '확인 대기',
        'confirmed' => '확정',
        'cancelled' => '취소',
    ];

    /** 카테고리 라벨 (미지 키는 그대로 반환 — 마이그레이션 전 데이터 방어). */
    public static function categoryLabel(string $key): string
    {
        return self::CATEGORIES[$key] ?? $key;
    }

    /** 비용 상태 라벨. */
    public static function statusLabel(string $key): string
    {
        return self::STATUSES[$key] ?? $key;
    }

    /**
     * projects.actual_cost 캐시 재계산 = 원가 총액(확정만).
     * 비용 등록/수정/취소 후 항상 호출한다. 재계산된 원가 총액(int)을 반환.
     */
    public static function recalcProject(int $projectId): int
    {
        $sum = (int) Db::val(
            "SELECT COALESCE(SUM(amount),0) FROM costs
             WHERE project_id = :pid AND type = 'actual' AND cost_status = 'confirmed'",
            [':pid' => $projectId]
        );
        Db::run(
            "UPDATE projects SET actual_cost = :sum WHERE id = :pid",
            [':sum' => $sum, ':pid' => $projectId]
        );
        return $sum;
    }

    /**
     * 금액 자동계산. 계산 불가(입력값 부족)면 null.
     *  - labor: (work_days 우선, 없으면 work_hours) × unit_price(일당/시급)
     *  - 그 외: qty × unit_price
     */
    public static function autoAmount(
        string $category,
        ?float $qty,
        ?float $unitPrice,
        ?float $workDays = null,
        ?float $workHours = null
    ): ?int {
        if ($unitPrice === null || $unitPrice <= 0) {
            return null;
        }
        if ($category === 'labor') {
            if ($workDays !== null && $workDays > 0) {
                return (int) round($workDays * $unitPrice);
            }
            if ($workHours !== null && $workHours > 0) {
                return (int) round($workHours * $unitPrice);
            }
            return null;
        }
        if ($qty !== null && $qty > 0) {
            return (int) round($qty * $unitPrice);
        }
        return null;
    }

    /**
     * 프로젝트 원가 소계 집계 — 원가 총액(확정 actual)과 자재비/인건비/기타 소계.
     * 반환:
     *  - material/labor/other: 확정 소계 (기타 = 8종 중 자재·인건 제외 전부)
     *  - total: 원가 총액(확정) — projects.actual_cost 와 동일 기준
     *  - entry_count: 확정 actual 비용 행 수 (미입력 0건과 0원 구분용)
     *  - has_entries: entry_count > 0
     */
    public static function subtotals(int $projectId): array
    {
        $rows = Db::all(
            "SELECT category, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS sum_amount
             FROM costs
             WHERE project_id = :pid AND type = 'actual' AND cost_status = 'confirmed'
             GROUP BY category",
            [':pid' => $projectId]
        );
        $t = ['material' => 0, 'labor' => 0, 'other' => 0, 'total' => 0, 'entry_count' => 0, 'has_entries' => false];
        foreach ($rows as $r) {
            $sum = (int) $r['sum_amount'];
            $t['entry_count'] += (int) $r['cnt'];
            $t['total'] += $sum;
            if ($r['category'] === 'material') {
                $t['material'] += $sum;
            } elseif ($r['category'] === 'labor') {
                $t['labor'] += $sum;
            } else {
                $t['other'] += $sum;
            }
        }
        $t['has_entries'] = $t['entry_count'] > 0;
        return $t;
    }

    /**
     * 미입력(비용 행 0건)과 0원을 구분한 원가 총액 표시 문자열.
     * 행이 없으면 '미입력', 있으면 "12,345,678원" 형식.
     */
    public static function totalLabel(array $subtotals): string
    {
        if (empty($subtotals['has_entries'])) {
            return '미입력';
        }
        return number_format((int) $subtotals['total']) . '원';
    }

    /**
     * 최근 출금(원가 지출) 리스트(대시보드 T6) — 확정(confirmed)·실제(actual) 비용 최근 $limit 건.
     * 취소(cancelled)·임시(draft)·대기(pending)·예상(estimate)·삭제 프로젝트 제외.
     * 정렬: spent_date DESC(지출일 기준 — 등록일 정렬 금지, 발생일 미상 NULL 은 뒤) → id DESC.
     * 환불(payments kind='refund')은 costs 원천이 아니므로 이 리스트에 없다 —
     * 대시보드 최근 입금 리스트의 환불 배지가 표기한다(planner S3, 라벨 '최근 출금(원가 지출)').
     */
    public static function recentConfirmed(int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        return Db::all(
            "SELECT cs.id, cs.spent_date, cs.category, cs.amount, cs.vendor, cs.item_name,
                    cs.receipt_file_id, cs.project_id, p.name AS project_name, u.name AS created_by_name
             FROM costs cs
             JOIN projects p ON p.id = cs.project_id AND p.deleted_at IS NULL
             LEFT JOIN users u ON u.id = cs.created_by
             WHERE cs.type='actual' AND cs.cost_status='confirmed'
             ORDER BY cs.spent_date DESC, cs.id DESC
             LIMIT $limit"
        );
    }

    /** 원가 목록 필터 입력(GET) 수집 — 프로젝트 상세 목록·CSV 가 공유. */
    public static function listFilters(): array
    {
        $cat = Util::str('cost_cat');
        return [
            'cat'    => isset(self::CATEGORIES[$cat]) ? $cat : '',
            'worker' => Util::str('cost_worker'),
            'from'   => Util::dateOrNull(Util::str('cost_from')),
            'to'     => Util::dateOrNull(Util::str('cost_to')),
        ];
    }

    /**
     * 필터 → WHERE 조각(costs 별칭 c 고정). worker 값이 숫자면 worker_id, 아니면 worker_name 매칭.
     * @return array [whereSql, params]
     */
    public static function filterWhere(int $projectId, array $f): array
    {
        $sql = 'c.project_id = :f_pid';
        $params = [':f_pid' => $projectId];
        if ($f['cat'] !== '') {
            $sql .= ' AND c.category = :f_cat';
            $params[':f_cat'] = $f['cat'];
        }
        if ($f['worker'] !== '') {
            if (ctype_digit($f['worker'])) {
                $sql .= ' AND c.worker_id = :f_wid';
                $params[':f_wid'] = (int) $f['worker'];
            } else {
                $sql .= ' AND c.worker_name = :f_wname';
                $params[':f_wname'] = $f['worker'];
            }
        }
        if (!empty($f['from'])) {
            $sql .= ' AND c.spent_date >= :f_from';
            $params[':f_from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $sql .= ' AND c.spent_date <= :f_to';
            $params[':f_to'] = $f['to'];
        }
        return [$sql, $params];
    }
}
