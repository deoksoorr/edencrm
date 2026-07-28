<?php
/**
 * 보너스 원장 실시간 재계산(R13) — 단일 진입점.
 * 입금/환불/철회/파기/계약금 변경 등 확정매출·지출 변동 시 해당 프로젝트의 보너스 원장
 * 파생 컬럼(base_amount·contrib_revenue·contrib_profit·calc_amount)을 실입금 기준으로 다시 쓴다.
 * confirmed_bonus(실지급 확정)와 contribution_pct_at_calc(기여율 스냅샷)는 절대 건드리지 않는다.
 * 캐싱 스냅샷 유지 금지 — 산정값은 항상 현재 순입금(입금−환불) 기준.
 */
class BonusService
{
    /** @return int 재계산으로 값이 바뀐 보너스 행 수 */
    public static function recalcForProject(int $projectId): int
    {
        if ($projectId <= 0) {
            return 0;
        }
        $proj = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$proj) {
            return 0;
        }
        $rows = Db::all(
            "SELECT * FROM site_bonuses
             WHERE project_id = :p AND deleted_at IS NULL AND pay_status <> 'cancelled'",
            [':p' => $projectId]
        );
        if (!$rows) {
            return 0;
        }
        $revenue    = AccountingService::projectConfirmedRevenue($proj); // 확정매출(공급가·입금 기준)
        $base       = max(0, $revenue);
        $profitBase = $revenue - (int) ($proj['actual_cost'] ?? 0);       // 기여도 반영 순이익 분자(음수 가능)

        $changed = 0;
        foreach ($rows as $b) {
            $pct  = $b['contribution_pct_at_calc'] !== null ? (float) $b['contribution_pct_at_calc'] : null;
            $rate = $b['bonus_rate'] !== null ? (float) $b['bonus_rate'] : null;
            // 기여율 스냅샷 보존 — 스냅샷 기준 비례 재계산(스냅샷 없으면 기존값 유지).
            $contribRev    = $pct !== null ? (int) round($base * $pct / 100) : (int) $b['contrib_revenue'];
            $contribProfit = $pct !== null ? (int) round($profitBase * $pct / 100) : (int) $b['contrib_profit'];
            $calc          = ($rate !== null) ? (int) round($contribRev * $rate / 100) : (int) $b['calc_amount'];

            if ((int) $b['base_amount'] === $base
                && (int) $b['contrib_revenue'] === $contribRev
                && (int) $b['contrib_profit'] === $contribProfit
                && (int) $b['calc_amount'] === $calc) {
                continue; // 변경 없음
            }
            $data = [
                'base_amount'     => $base,
                'contrib_revenue' => $contribRev,
                'contrib_profit'  => $contribProfit,
                'calc_amount'     => $calc,
            ];
            Db::update('site_bonuses', $data, 'id = :id', [':id' => (int) $b['id']]);
            Db::insert('site_bonus_history', [
                'bonus_id'    => (int) $b['id'],
                'action'      => 'recalc',
                'before_json' => json_encode($b, JSON_UNESCAPED_UNICODE),
                'after_json'  => json_encode(array_merge($b, $data), JSON_UNESCAPED_UNICODE),
                'reason'      => '입금/환불 이벤트 자동 재계산(R13)',
                'changed_by'  => null,
            ]);
            $changed++;
        }
        return $changed;
    }
}
