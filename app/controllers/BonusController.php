<?php
/**
 * 반기 현황(halfyear.index) + 현장 보너스 원장(bonus.*) — R4/R8-B.
 *
 * 스코프 규칙: 조회 라우트에 perm 이 없으므로 컨트롤러가 강제한다.
 *  - performance.view_all 없으면 직원 필터를 본인(Auth::id())으로 쿼리 레벨에서 고정(IDOR 방지 — 화면 숨김만으로 처리 금지).
 *  - 쓰기(save/delete)는 라우터가 bonus.manage 를 강제.
 *
 * 회계 값(계약금액·입금·확정매출·순이익 등)은 반드시 AccountingService 공통 서비스를 사용해
 * 대시보드·리포트·성과 화면과 산식을 1원까지 일치시킨다(임의 쿼리 재계산 금지).
 * 보너스(site_bonuses)는 회계 서비스 밖 신규 도메인 — 이 컨트롤러가 직접 쿼리한다.
 *
 * 원장 원칙: 삭제는 소프트 삭제(deleted_at)만, 모든 변경은 site_bonus_history 에 전/후 JSON 으로 적재.
 * 마감 반기(Util::isHalfClosed)의 수정·삭제는 reason(사유) 필수.
 */
class BonusController
{
    /** 지급 상태 화이트리스트(R12: 부분지급 폐지). */
    private const PAY_STATUSES = ['unpaid', 'paid', 'cancelled'];

    // ── 공통 헬퍼 ──

    /**
     * 공통 필터 파싱 + 스코프 강제.
     * @return array{year:int, half:int, userId:int, projectId:int, payStatus:string, canAll:bool}
     */
    private function filters(): array
    {
        $cur  = Util::currentHalf();
        $year = Util::int('year', $cur['year']) ?: $cur['year'];
        if ($year < 2020 || $year > 2100) {
            $year = $cur['year'];
        }
        $half = Util::int('half', $cur['half']);
        if (!in_array($half, [1, 2], true)) {
            $half = $cur['half'];
        }
        $canAll = Rbac::can('performance.view_all');
        $userId = Util::int('user_id', 0) ?: 0;
        if (!$canAll) {
            // 전체 열람 권한 없으면 본인으로 강제(쿼리 레벨) — user_id 파라미터 무시
            $userId = (int) Auth::id();
        }
        $projectId = Util::int('project_id', 0) ?: 0;
        $payStatus = Util::str('pay_status');
        if (!in_array($payStatus, self::PAY_STATUSES, true)) {
            $payStatus = '';
        }
        return ['year' => $year, 'half' => $half, 'userId' => $userId,
            'projectId' => $projectId, 'payStatus' => $payStatus, 'canAll' => $canAll];
    }

    /**
     * 연도 선택지 = 데이터 존재 범위(보너스 연도 ∪ 프로젝트 계약연도) + 현재 연도, 내림차순.
     * StaffController(반기 요약 툴바)도 재사용한다.
     * @return int[]
     */
    public static function yearOptions(): array
    {
        $years = array_map('intval', array_column(Db::all(
            "SELECT DISTINCT year AS y FROM site_bonuses WHERE deleted_at IS NULL
             UNION SELECT DISTINCT YEAR(contract_date) FROM projects
                WHERE deleted_at IS NULL AND contract_date IS NOT NULL"
        ), 'y'));
        $years[] = (int) date('Y');
        $years = array_values(array_unique($years));
        rsort($years);
        return $years;
    }

    /** 보너스 목록 WHERE 조각 + 파라미터(별칭 b 고정, deleted 제외). */
    private function bonusWhere(array $f): array
    {
        $where  = ['b.deleted_at IS NULL', 'b.year = :by', 'b.half = :bh'];
        $params = [':by' => $f['year'], ':bh' => $f['half']];
        if ($f['userId'] > 0) {
            $where[] = 'b.user_id = :bu';
            $params[':bu'] = $f['userId'];
        }
        if ($f['projectId'] > 0) {
            $where[] = 'b.project_id = :bp';
            $params[':bp'] = $f['projectId'];
        }
        if ($f['payStatus'] !== '') {
            $where[] = 'b.pay_status = :bs';
            $params[':bs'] = $f['payStatus'];
        }
        return [implode(' AND ', $where), $params];
    }

    /** 필터 적용 보너스 목록(직원·프로젝트·지급담당자 JOIN). */
    private function bonusList(array $f): array
    {
        [$where, $params] = $this->bonusWhere($f);
        return Db::all(
            "SELECT b.*, u.name AS user_name, p.name AS project_name, p.project_no,
                    pb.name AS paid_by_name
             FROM site_bonuses b
             JOIN users u ON u.id = b.user_id
             LEFT JOIN projects p ON p.id = b.project_id
             LEFT JOIN users pb ON pb.id = b.paid_by
             WHERE $where
             ORDER BY u.name ASC, b.id DESC",
            $params
        );
    }

    /** 보너스 합계(R12: 확정 보너스/지급 완료액/미지급액) — cancelled 제외.
     *  확정 보너스 = 관리자 확정 지급 금액 합. 지급완료 = pay_status='paid' 확정 보너스 합.
     *  미지급 = pay_status='unpaid' 확정 보너스 합(아직 보내지 않은 금액). 산정액(참고)은 별도 표시. */
    private function bonusTotals(array $rows): array
    {
        $t = ['calc' => 0, 'confirmed' => 0, 'paid' => 0, 'unpaid' => 0];
        foreach ($rows as $r) {
            if ($r['pay_status'] === 'cancelled') {
                continue;
            }
            $confirmed = (int) $r['confirmed_bonus'];
            $t['calc']      += (int) $r['calc_amount'];
            $t['confirmed'] += $confirmed;
            if ($r['pay_status'] === 'paid') {
                $t['paid'] += $confirmed;
            } else {
                $t['unpaid'] += $confirmed;
            }
        }
        return $t;
    }

    /** 필터용 직원 목록(canAll 이면 전체 비삭제, 아니면 본인만). */
    private function userOptions(bool $canAll): array
    {
        if ($canAll) {
            return Db::all(
                "SELECT id, name, status FROM users WHERE deleted_at IS NULL ORDER BY status='active' DESC, name"
            );
        }
        $me = Auth::user();
        return [['id' => (int) $me['id'], 'name' => $me['name'], 'status' => $me['status'] ?? 'active']];
    }

    /** 필터용 프로젝트 목록(비삭제). */
    private function projectOptions(): array
    {
        return Db::all("SELECT id, project_no, name FROM projects WHERE deleted_at IS NULL ORDER BY name");
    }

    // ── 화면 ──

    /** 반기 현황 — 매출/순이익/현장 보너스 3개 섹션 + 직원별 표(view_all). */
    public function overview(): void
    {
        $f = $this->filters();
        $range = Util::halfRange($f['year'], $f['half']);
        $from  = $range['from'];
        $to    = $range['to'];
        $uid   = $f['userId'] > 0 ? $f['userId'] : null;

        // (a) 매출 현황 — 전부 AccountingService 공통 산식(대시보드·리포트와 일치)
        $contracted = AccountingService::contractedAmount($from, $to, $uid);
        if ($uid !== null) {
            $paidMap = AccountingService::employeePaidByUser($from, $to);
            $paid    = (int) ($paidMap[$uid] ?? 0);
            $revenue = AccountingService::employeeConfirmedRevenue($uid, $from, $to);
        } else {
            $paid    = AccountingService::paidTotal($from, $to);
            $revenue = AccountingService::confirmedRevenue($from, $to);
        }
        $receivable = AccountingService::receivable(); // 현재 시점 스냅샷(기간 무관) — 뷰에서 라벨 명시

        // 프로젝트 수: 기간 내 계약일(없으면 등록일) 기준, 취소·파기 제외
        $pp = [':pf' => $from, ':pt' => $to];
        $userCond = '';
        if ($uid !== null) {
            $userCond = " AND (p.sales_user_id = :pu1 OR p.site_manager_id = :pu2
                OR EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id = p.id AND pa.user_id = :pu3))";
            $pp += [':pu1' => $uid, ':pu2' => $uid, ':pu3' => $uid];
        }
        $projectCount = (int) Db::val(
            "SELECT COUNT(*) FROM projects p
             WHERE p.deleted_at IS NULL AND p.status NOT IN ('cancelled','terminated')
               AND COALESCE(p.contract_date, DATE(p.created_at)) BETWEEN :pf AND :pt $userCond",
            $pp
        );

        // (b) 순이익 현황 — 등록 지출·직접 원가는 전사 축(스펙 고정), 순이익만 직원 선택 시 귀속치
        $costReg    = AccountingService::costTotal($from, $to);
        $costDirect = AccountingService::confirmedCost($from, $to);
        if ($uid !== null) {
            $byUser = AccountingService::employeeConfirmedByUser($from, $to);
            $profit = (int) ($byUser[$uid]['contrib'] ?? 0);
        } else {
            $profit = AccountingService::confirmedProfit($from, $to);
        }
        $profitRate = $revenue > 0 ? round($profit / $revenue * 100, 1) : null; // 0 나눗셈 방지

        // (c) 현장 보너스 목록 + 합계
        $bonuses     = $this->bonusList($f);
        $bonusTotals = $this->bonusTotals($bonuses);

        // 직원별 표(view_all 시) — 배치 메서드로 N+1 제거
        $staffRows = [];
        if ($f['canAll']) {
            $byUser     = $byUser ?? AccountingService::employeeConfirmedByUser($from, $to);
            $paidByUser = $paidMap ?? AccountingService::employeePaidByUser($from, $to);
            $contractedByUser = AccountingService::contractedAmountByUser($from, $to);
            $contractCntByUser = AccountingService::contractedCountByUser($from, $to);
            $salesPaidByUser  = AccountingService::salesPaidByUser($from, $to);
            $projCntByUser    = AccountingService::employeeProjectCountByUser();
            $bonusByUser = [];
            foreach (Db::all(
                "SELECT user_id, COALESCE(SUM(confirmed_bonus),0) AS s FROM site_bonuses
                 WHERE deleted_at IS NULL AND pay_status = 'paid' AND year=:y AND half=:h
                 GROUP BY user_id",
                [':y' => $f['year'], ':h' => $f['half']]
            ) as $b) {
                $bonusByUser[(int) $b['user_id']] = (int) $b['s'];
            }
            foreach (Db::all(
                "SELECT id, name FROM users WHERE deleted_at IS NULL AND status='active' ORDER BY name"
            ) as $u) {
                $sid = (int) $u['id'];
                $staffRows[] = [
                    'user_id'    => $sid,
                    'name'       => $u['name'],
                    'contracted' => (int) ($contractedByUser[$sid] ?? 0),
                    'contract_cnt' => (int) ($contractCntByUser[$sid] ?? 0),
                    'paid'       => (int) ($paidByUser[$sid] ?? 0),
                    'revenue'    => (int) ($byUser[$sid]['revenue'] ?? 0),
                    'profit'     => (int) ($byUser[$sid]['contrib'] ?? 0),
                    'bonus_paid' => (int) ($bonusByUser[$sid] ?? 0),
                    'sales_paid' => (int) ($salesPaidByUser[$sid] ?? 0),
                    'project_cnt' => (int) ($projCntByUser[$sid] ?? 0),
                ];
            }
        }

        $canManage = Rbac::can('bonus.manage');
        View::render('halfyear/index', [
            'title'        => '반기 보너스 지급 현황',
            'f'            => $f,
            'years'        => self::yearOptions(),
            'users'        => $this->userOptions($f['canAll']),
            'projects'     => $this->projectOptions(),
            'canManage'    => $canManage,
            // 관리 모달 셀렉트용(활성 직원만 — save 검증과 동일 기준)
            'formUsers'    => $canManage ? Db::all(
                "SELECT id, name FROM users WHERE deleted_at IS NULL AND status='active' ORDER BY name"
            ) : [],
            'revenueKpi'   => [
                'contracted' => $contracted, 'paid' => $paid, 'revenue' => $revenue,
                'receivable' => $receivable, 'projectCount' => $projectCount,
            ],
            'profitKpi'    => [
                'revenue' => $revenue, 'costReg' => $costReg, 'costDirect' => $costDirect,
                'costOther' => $costReg - $costDirect,
                'profit' => $profit, 'profitRate' => $profitRate,
            ],
            'bonuses'      => $bonuses,
            'bonusTotals'  => $bonusTotals,
            'staffRows'    => $staffRows,
            'isClosed'     => Util::isHalfClosed($f['year'], $f['half']),
        ]);
    }

    /** 보너스 지급 현황 — 목록 + 합계행 + 관리(bonus.manage) CRUD 모달. */
    public function index(): void
    {
        $f       = $this->filters();
        $bonuses = $this->bonusList($f);

        $canManage = Rbac::can('bonus.manage');
        View::render('bonus/index', [
            'title'       => '보너스 지급 현황',
            'f'           => $f,
            'years'       => self::yearOptions(),
            'users'       => $this->userOptions($f['canAll']),
            'projects'    => $this->projectOptions(),
            'bonuses'     => $bonuses,
            'bonusTotals' => $this->bonusTotals($bonuses),
            'canManage'   => $canManage,
            // 관리 모달 셀렉트용(활성 직원만 — save 검증과 동일 기준)
            'formUsers'   => $canManage ? Db::all(
                "SELECT id, name FROM users WHERE deleted_at IS NULL AND status='active' ORDER BY name"
            ) : [],
        ]);
    }

    /** 변경 이력 — site_bonus_history 원장 열람(스코프: view_all 없으면 본인 대상 건만). */
    public function history(): void
    {
        $canAll  = Rbac::can('performance.view_all');
        $year    = Util::int('year', 0) ?: 0;      // 0 = 전체 연도
        $half    = Util::int('half', 0) ?: 0;      // 0 = 전체 반기
        $bonusId = Util::int('bonus_id', 0) ?: 0;
        $userId  = Util::int('user_id', 0) ?: 0;
        if (!$canAll) {
            $userId = (int) Auth::id();            // 본인 대상 건만(쿼리 레벨 강제)
        }
        $page = max(1, Util::int('page', 1) ?: 1);

        $where  = ['1=1'];
        $params = [];
        if ($bonusId > 0) {
            $where[] = 'h.bonus_id = :bid';
            $params[':bid'] = $bonusId;
        }
        if ($year >= 2020 && $year <= 2100) {
            $where[] = 'b.year = :y';
            $params[':y'] = $year;
        }
        if (in_array($half, [1, 2], true)) {
            $where[] = 'b.half = :h';
            $params[':h'] = $half;
        }
        if ($userId > 0) {
            $where[] = 'b.user_id = :u';
            $params[':u'] = $userId;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::val(
            "SELECT COUNT(*) FROM site_bonus_history h JOIN site_bonuses b ON b.id = h.bonus_id WHERE $whereSql",
            $params
        );
        $per = (int) ($GLOBALS['config']['PAGE_SIZE'] ?? 20);
        $pg  = Util::paginate($total, $page, $per);

        $rows = Db::all(
            "SELECT h.*, b.year, b.half, b.user_id AS target_user_id, b.deleted_at AS bonus_deleted_at,
                    tu.name AS target_name, p.name AS project_name, cu.name AS changed_by_name
             FROM site_bonus_history h
             JOIN site_bonuses b ON b.id = h.bonus_id
             LEFT JOIN users tu ON tu.id = b.user_id
             LEFT JOIN projects p ON p.id = b.project_id
             LEFT JOIN users cu ON cu.id = h.changed_by
             WHERE $whereSql
             ORDER BY h.changed_at DESC, h.id DESC
             LIMIT {$pg['per']} OFFSET {$pg['offset']}",
            $params
        );

        View::render('bonus/history', [
            'title'   => '보너스 변경 이력',
            'rows'    => $rows,
            'pg'      => $pg,
            'year'    => $year,
            'half'    => $half,
            'bonusId' => $bonusId,
            'userId'  => $userId,
            'canAll'  => $canAll,
            'years'   => self::yearOptions(),
            'users'   => $this->userOptions($canAll),
        ]);
    }

    // ── 연동 산정 (R12 산식 — 프론트는 미리보기, 저장 시 서버가 동일 산식으로 재계산) ──
    //   총매출 = 프로젝트 확정 매출(공급가액·VAT 제외, 입금 기준) — 계약 입금/예외 프로젝트 직접 입금 공통.
    //   기여도 반영 매출 = round(총매출 × 기여율/100). 배정 기여율 합 100%면 잔여 원 단위를
    //                    마지막 직원에게 배분해 Σ = 총매출 보장.
    //   기여도 반영 순이익 = round((프로젝트 확정 매출 − 프로젝트 확정 지출) × 기여율/100) — 참고·경고용.
    //   산정액(참고) = round(기여도 반영 매출 × 보너스율/100).
    //   확정 보너스 = 관리자 확정 지급 금액(기본값 = 산정액, 수정 가능) — 지급완료 시 이 금액만 지급.

    /** 프로젝트의 총매출 = 확정 매출(공급가액·VAT 제외, 입금 기준). 예외 프로젝트 포함(R12). */
    private static function projectBonusBase(array $p): int
    {
        return max(0, AccountingService::projectConfirmedRevenue($p));
    }

    /** 프로젝트 순이익(확정 매출 공급가 − 확정 지출) — 기여도 반영 순이익 배분의 분자(음수 가능). */
    private static function projectProfitBase(array $p): int
    {
        return AccountingService::projectConfirmedRevenue($p) - (int) ($p['actual_cost'] ?? 0);
    }

    /** 활성 배정 직원 목록(user_id/name/pct/user_status) — 이름순 고정(잔여 배분 순서의 단일 기준). */
    private static function activeAssignees(int $projectId): array
    {
        return Db::all(
            "SELECT pa.user_id, u.name, pa.contribution_pct AS pct, u.status AS user_status
             FROM project_assignments pa
             JOIN users u ON u.id = pa.user_id AND u.deleted_at IS NULL
             WHERE pa.project_id = :p AND pa.status = 'active'
             ORDER BY u.name, pa.user_id",
            [':p' => $projectId]
        );
    }

    /**
     * 직원별 기여도 반영 매출 배분표. Σpct 가 100±0.01 이면 마지막 직원 = base − Σ(앞선 배분)으로
     * 합계를 총매출과 정확히 일치시킨다(§4 검증식).
     * @return array<int,int> user_id => contrib_revenue
     */
    private static function allocateContrib(int $base, array $assignees): array
    {
        $map = [];
        $pctSum = 0.0;
        foreach ($assignees as $a) {
            $pctSum += (float) $a['pct'];
        }
        $exact = abs($pctSum - 100.0) < 0.01;
        $acc = 0;
        $n = count($assignees);
        foreach ($assignees as $i => $a) {
            if ($exact && $i === $n - 1) {
                $v = $base - $acc; // 잔여 배분
            } else {
                $v = (int) round($base * (float) $a['pct'] / 100);
            }
            $map[(int) $a['user_id']] = $v;
            $acc += $v;
        }
        return $map;
    }

    /**
     * 연동 산정 정보(JSON, bonus.manage) — 보너스 폼 자동 채움.
     * 프로젝트 선택 직후 필요한 값 일체: 계약금액·누적입금(=총매출)·배정 직원 목록(기여도·배분액 포함).
     */
    public function calcInfo(): void
    {
        $projectId = Util::int('project_id', 0) ?: 0;
        $userId    = Util::int('user_id', 0) ?: 0;
        $p = Db::one('SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL', [':id' => $projectId]);
        if (!$p) {
            Response::error('프로젝트를 찾을 수 없습니다.', 404);
        }
        $base       = self::projectBonusBase($p);            // 확정 매출(공급가·VAT 제외)
        $profitBase = self::projectProfitBase($p);           // 확정 매출 − 지출
        $netPaid    = (int) ($p['contract_id'] ?? 0) > 0     // 현금(VAT 포함) — 참고 표시용
            ? AccountingService::contractNetPaid((int) $p['contract_id'])
            : ((int) ($p['is_exception'] ?? 0) === 1 ? AccountingService::projectNetPaid((int) $p['id']) : 0);
        $assignees = self::activeAssignees($projectId);
        $alloc     = self::allocateContrib($base, $assignees);
        $pctSum    = 0.0;
        $list      = [];
        foreach ($assignees as $a) {
            $pctSum += (float) $a['pct'];
            $uidA = (int) $a['user_id'];
            $list[] = [
                'user_id'         => $uidA,
                'name'            => $a['name'],
                'pct'             => (float) $a['pct'],
                'user_status'     => $a['user_status'],
                'contrib_revenue' => $alloc[$uidA],
                'contrib_profit'  => (int) round($profitBase * (float) $a['pct'] / 100),
            ];
        }
        $pct = $contribProfit = null;
        foreach ($list as $a) {
            if ($a['user_id'] === $userId) {
                $pct = $a['pct'];
                $contribProfit = $a['contrib_profit'];
                break;
            }
        }
        Response::json([
            'contract_amount' => (int) $p['contract_amount'],
            'net_paid'        => $netPaid,     // 누적 확정 입금(현금·VAT 포함) — 참고
            'base'            => $base,        // 총매출 = 확정 매출(공급가·VAT 제외)
            'profit_base'     => $profitBase,  // 프로젝트 순이익(확정매출−지출)
            'project_cost'    => (int) ($p['actual_cost'] ?? 0),
            'has_contract'    => (int) ($p['contract_id'] ?? 0) > 0,
            'is_exception'    => (int) ($p['is_exception'] ?? 0) === 1,
            'pct_sum'         => round($pctSum, 2),
            'assignees'       => $list,
            'pct'             => $pct,
            'contribRevenue'  => $userId > 0 ? ($alloc[$userId] ?? null) : null,
            'contribProfit'   => $contribProfit,
        ]);
    }

    // ── 쓰기 (라우터가 bonus.manage + POST + CSRF 강제) ──

    /**
     * 등록/수정(id 유무로 분기). 수정 시 미전송 필드는 기존 값 유지(지급 처리 등 부분 폼 지원).
     * pay_status 는 cancelled 명시가 아니면 지급액/산정액 관계로 자동 보정.
     * 마감 반기의 수정은 reason 필수(422). 저장 후 history + Audit 적재.
     */
    public function save(): void
    {
        // R10: 전체 배정 직원 일괄 등록 모드 — 별도 흐름
        if (Util::postInt('all_assignees', 0) === 1) {
            $this->saveBulk();
        }
        $id     = Util::postInt('id', 0) ?: 0;
        $before = null;
        if ($id > 0) {
            $before = Db::one('SELECT * FROM site_bonuses WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
            if (!$before) {
                Response::error('보너스 내역을 찾을 수 없습니다.', 404);
            }
        }
        $posted = static fn (string $k): bool => array_key_exists($k, $_POST);

        // ── 입력 병합(수정 시 미전송 필드는 기존 값) ──
        $userId    = $posted('user_id') ? (Util::postInt('user_id', 0) ?: 0) : (int) ($before['user_id'] ?? 0);
        $projectId = $posted('project_id') ? (Util::postInt('project_id', 0) ?: 0) : (int) ($before['project_id'] ?? 0);
        $year      = $posted('year') ? (Util::postInt('year', 0) ?: 0) : (int) ($before['year'] ?? 0);
        $half      = $posted('half') ? (Util::postInt('half', 0) ?: 0) : (int) ($before['half'] ?? 0);
        $calcBasis = $posted('calc_basis') ? trim(Util::postStr('calc_basis')) : (string) ($before['calc_basis'] ?? '');
        $memo      = $posted('memo') ? trim(Util::postStr('memo')) : (string) ($before['memo'] ?? '');
        $payDate   = $posted('pay_date') ? trim(Util::postStr('pay_date')) : (string) ($before['pay_date'] ?? '');
        $payStatusIn = $posted('pay_status') ? Util::postStr('pay_status') : (string) ($before['pay_status'] ?? '');
        $paidBy    = $posted('paid_by') ? (Util::postInt('paid_by', 0) ?: 0) : (int) ($before['paid_by'] ?? 0);
        $reason    = trim(Util::postStr('reason'));

        // 금액: 콤마 허용, 0 이상 정수만
        $money = static function (string $key, $current) use ($posted) {
            if (!$posted($key)) {
                return (int) ($current ?? 0);
            }
            $v = str_replace([',', ' '], '', Util::postStr($key));
            if ($v === '') {
                return 0;
            }
            if (!preg_match('/^\d+$/', $v)) {
                Response::error('금액은 0 이상의 정수여야 합니다.', 422);
            }
            return (int) $v;
        };
        $base = $money('base_amount', $before['base_amount'] ?? 0);
        $calc = $money('calc_amount', $before['calc_amount'] ?? 0);
        // R12: 확정 보너스(구 paid_amount) — 실제 지급 금액. 미전송 시 기존 값(신규는 0 → 재계산 시 산정액으로 기본).
        $confirmedBonus = $posted('confirmed_bonus')
            ? $money('confirmed_bonus', 0)
            : (int) ($before['confirmed_bonus'] ?? 0);
        // 기여도 반영 매출(R9-2) — 미전송 시 기존 값 유지(레거시 행은 NULL 유지)
        $contribRev = $posted('contrib_revenue')
            ? $money('contrib_revenue', 0)
            : ($before !== null && $before['contrib_revenue'] !== null ? (int) $before['contrib_revenue'] : null);
        // 기여도 반영 순이익(R12) — 미전송 시 기존 값 유지, 재계산 시 서버가 산출
        $contribProfit = $before !== null && $before['contrib_profit'] !== null ? (int) $before['contrib_profit'] : null;
        // 보너스율(R10) — 0~100, 소수 2자리
        $bonusRate = $before !== null && $before['bonus_rate'] !== null ? (float) $before['bonus_rate'] : null;
        if ($posted('bonus_rate')) {
            $r = str_replace([',', ' '], '', Util::postStr('bonus_rate'));
            if ($r === '') {
                $bonusRate = null;
            } else {
                if (!preg_match('/^\d+(\.\d{1,2})?$/', $r) || (float) $r > 100) {
                    Response::error('보너스율은 0~100 사이여야 합니다. (소수 2자리)', 422);
                }
                $bonusRate = (float) $r;
            }
        }

        // ── 검증 ──
        if ($userId <= 0) {
            Response::error('대상 직원을 선택하세요.', 422);
        }
        // 활성 직원 검증 — 신규 또는 대상 변경 시(기존 원장의 퇴직자 건 수정은 허용: 과거 이력 보존)
        if ($before === null || (int) $before['user_id'] !== $userId) {
            $u = Db::one(
                "SELECT id FROM users WHERE id = :id AND deleted_at IS NULL AND status = 'active'",
                [':id' => $userId]
            );
            if (!$u) {
                Response::error('대상 직원이 존재하지 않거나 활성 상태가 아닙니다.', 422);
            }
        }
        $proj = null;
        if ($projectId > 0) {
            $proj = Db::one('SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL', [':id' => $projectId]);
            if (!$proj) {
                Response::error('선택한 프로젝트를 찾을 수 없습니다.', 422);
            }
        }
        if ($year < 2020 || $year > 2100) {
            Response::error('연도는 2020~2100 사이여야 합니다.', 422);
        }
        if (!in_array($half, [1, 2], true)) {
            Response::error('반기는 상반기(1) 또는 하반기(2)여야 합니다.', 422);
        }
        if ($payDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
            Response::error('지급일 형식이 올바르지 않습니다. (YYYY-MM-DD)', 422);
        }
        if ($payStatusIn !== '' && !in_array($payStatusIn, self::PAY_STATUSES, true)) {
            Response::error('지급 상태 값이 올바르지 않습니다.', 422);
        }
        if (mb_strlen($memo) > 500) {
            Response::error('메모는 500자 이내여야 합니다.', 422);
        }
        if (mb_strlen($calcBasis) > 100) {
            Response::error('산정 기준은 100자 이내여야 합니다.', 422);
        }
        if ($paidBy > 0 && !Db::one('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL', [':id' => $paidBy])) {
            Response::error('지급 담당자를 찾을 수 없습니다.', 422);
        }

        // 마감 반기 게이트 — 수정(기존 반기 또는 이동 대상 반기가 마감)이면 사유 필수
        if ($id > 0) {
            $closed = Util::isHalfClosed((int) $before['year'], (int) $before['half'])
                || Util::isHalfClosed($year, $half);
            if ($closed && $reason === '') {
                Response::error('마감된 반기 수정은 사유가 필요합니다.', 422);
            }
        }

        // ── R10 연동 강제·재계산 (프로젝트 연결 시) ──
        $assignPct = null;
        if ($projectId > 0) {
            $assignees = self::activeAssignees($projectId);
            $found = null;
            foreach ($assignees as $a) {
                if ((int) $a['user_id'] === $userId) {
                    $found = $a;
                    break;
                }
            }
            // 배정 직원만 신규 등록 가능(§3) — 기존 원장 수정(배정 해제된 과거 건)은 이력 보존 위해 허용
            $keyChanged = $before === null
                || (int) $before['user_id'] !== $userId
                || (int) ($before['project_id'] ?? 0) !== $projectId;
            if ($keyChanged && !$found) {
                Response::error('해당 프로젝트에 배정된 직원만 보너스 대상으로 등록할 수 있습니다.', 422);
            }
            $assignPct = $found !== null ? (float) $found['pct'] : null;

            // 중복 산정 경고(동일 프로젝트·직원·반기, 취소·삭제 제외) — 관리자 확인(confirm_dup) 후 등록
            $dupKeyChanged = $keyChanged
                || (int) ($before['year'] ?? 0) !== $year
                || (int) ($before['half'] ?? 0) !== $half;
            if ($dupKeyChanged && Util::postInt('confirm_dup', 0) !== 1) {
                $dupCnt = (int) Db::val(
                    "SELECT COUNT(*) FROM site_bonuses
                     WHERE deleted_at IS NULL AND pay_status <> 'cancelled'
                       AND project_id = :p AND user_id = :u AND year = :y AND half = :h AND id <> :me",
                    [':p' => $projectId, ':u' => $userId, ':y' => $year, ':h' => $half, ':me' => $id]
                );
                if ($dupCnt > 0) {
                    Response::json(['dup_warning' => true,
                        'message' => "같은 프로젝트·직원·반기의 보너스가 이미 {$dupCnt}건 있습니다. 중복 산정에 주의하세요."]);
                }
            }

            // 서버 재계산(§5: 프론트 값 불신) — 신규이거나 산정 관련 필드가 전송된 수정이면 최신 데이터로 재계산.
            //   지급처리 부분 폼(지급액·지급일만 전송)은 재계산하지 않음 — 지급완료 건 자동 덮어쓰기 금지.
            $recompute = $before === null
                || $posted('bonus_rate') || $posted('base_amount') || $posted('user_id') || $posted('project_id');
            if ($recompute) {
                $base       = self::projectBonusBase($proj);
                $profitBase = self::projectProfitBase($proj);
                $alloc      = self::allocateContrib($base, $assignees);
                if (isset($alloc[$userId])) {
                    $contribRev = $alloc[$userId];
                } elseif ($assignPct !== null) {
                    $contribRev = (int) round($base * $assignPct / 100);
                }
                if ($assignPct !== null) {
                    $contribProfit = (int) round($profitBase * $assignPct / 100);
                }
                if ($bonusRate !== null && $contribRev !== null) {
                    $calc = (int) round($contribRev * $bonusRate / 100);
                }
                // 신규 등록·재계산 시 확정 보너스 미입력이면 산정액을 기본값으로 채운다(관리자가 수정 가능)
                if (!$posted('confirmed_bonus') && $confirmedBonus === 0) {
                    $confirmedBonus = $calc;
                }
            }
        }

        // R12: pay_status = 명시값(미지급/지급완료/취소)만. 부분지급 폐지 — 금액 관계로 자동 파생하지 않는다.
        $payStatus = in_array($payStatusIn, self::PAY_STATUSES, true) ? $payStatusIn : 'unpaid';

        // 기여율 스냅샷: 산정(등록·재계산) 시점의 배정 기여율 보존 — 이후 배정 변경이 과거 원장을 훼손하지 않도록.
        //   지급처리 등 재계산 없는 수정은 기존 스냅샷 유지.
        if ($projectId > 0 && ($recompute ?? false) && $assignPct !== null) {
            $pctSnapshot = $assignPct;
        } elseif ($before !== null) {
            $pctSnapshot = $before['contribution_pct_at_calc'];
        } else {
            $pctSnapshot = $assignPct;
        }

        $data = [
            'user_id'                  => $userId,
            'project_id'               => $projectId > 0 ? $projectId : null,
            'year'                     => $year,
            'half'                     => $half,
            'base_amount'              => $base,
            'calc_basis'               => $calcBasis !== '' ? $calcBasis : null,
            'contrib_revenue'          => $contribRev,
            'contrib_profit'           => $contribProfit,
            'bonus_rate'               => $bonusRate,
            'calc_amount'              => $calc,
            'confirmed_bonus'          => $confirmedBonus,
            'pay_date'                 => $payDate !== '' ? $payDate : null,
            'pay_status'               => $payStatus,
            'paid_by'                  => $paidBy > 0 ? $paidBy : null,
            'memo'                     => $memo !== '' ? $memo : null,
            'contribution_pct_at_calc' => $pctSnapshot,
        ];

        if ($id > 0) {
            Db::update('site_bonuses', $data, 'id = :id', [':id' => $id]);
            // 액션 판별: 취소 전환 > 확정 보너스·상태 변경(pay) > 일반 수정
            if ($payStatus === 'cancelled' && $before['pay_status'] !== 'cancelled') {
                $action = 'cancel';
            } elseif ((int) $before['confirmed_bonus'] !== $confirmedBonus || $before['pay_status'] !== $payStatus) {
                $action = 'pay';
            } else {
                $action = 'update';
            }
        } else {
            $data['created_by'] = Auth::id();
            $id = Db::insert('site_bonuses', $data);
            $action = 'create';
        }

        $after = Db::one('SELECT * FROM site_bonuses WHERE id = :id', [':id' => $id]);
        Db::insert('site_bonus_history', [
            'bonus_id'    => $id,
            'action'      => $action,
            'before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after_json'  => json_encode($after, JSON_UNESCAPED_UNICODE),
            'reason'      => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'changed_by'  => Auth::id(),
        ]);
        Audit::log('bonus.save', 'site_bonuses', $id, $before, $after);

        Response::json(['id' => $id]);
    }

    /**
     * 전체 배정 직원 일괄 등록(R10) — 프로젝트의 활성 배정 직원 전원(기여도>0·활성 직원)에게
     * 동일 보너스율로 초안(unpaid) 생성. 잔여 원 단위는 배분표(allocateContrib)가 마지막 직원에 배분.
     */
    private function saveBulk(): void
    {
        $projectId = Util::postInt('project_id', 0) ?: 0;
        $proj = $projectId > 0
            ? Db::one('SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL', [':id' => $projectId]) : null;
        if (!$proj) {
            Response::error('일괄 등록에는 프로젝트를 선택해야 합니다.', 422);
        }
        $year = Util::postInt('year', 0) ?: 0;
        $half = Util::postInt('half', 0) ?: 0;
        if ($year < 2020 || $year > 2100) {
            Response::error('연도는 2020~2100 사이여야 합니다.', 422);
        }
        if (!in_array($half, [1, 2], true)) {
            Response::error('반기는 상반기(1) 또는 하반기(2)여야 합니다.', 422);
        }
        $r = str_replace([',', ' '], '', Util::postStr('bonus_rate'));
        if ($r === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $r) || (float) $r > 100) {
            Response::error('일괄 등록에는 보너스율(0~100)이 필요합니다.', 422);
        }
        $bonusRate = (float) $r;
        $memo = trim(Util::postStr('memo'));
        if (mb_strlen($memo) > 500) {
            Response::error('메모는 500자 이내여야 합니다.', 422);
        }

        $assignees = self::activeAssignees($projectId);
        if (!$assignees) {
            Response::error('배정 직원이 없는 프로젝트입니다 — 보너스를 등록할 수 없습니다.', 422);
        }
        // 대상 = 기여도 > 0 + 활성 직원(§3: 비활성 직원 신규 지급 제한)
        $targets = array_values(array_filter(
            $assignees,
            static fn ($a) => (float) $a['pct'] > 0 && $a['user_status'] === 'active'
        ));
        if (!$targets) {
            Response::error('기여도가 0%보다 큰 활성 배정 직원이 없습니다.', 422);
        }

        // 중복 경고 — 대상 중 동일 반기 기존 건 존재 시(취소·삭제 제외)
        if (Util::postInt('confirm_dup', 0) !== 1) {
            $in = implode(',', array_map(static fn ($a) => (int) $a['user_id'], $targets)); // int 캐스팅 완료
            $dupNames = array_column(Db::all(
                "SELECT DISTINCT u.name FROM site_bonuses b JOIN users u ON u.id = b.user_id
                 WHERE b.deleted_at IS NULL AND b.pay_status <> 'cancelled'
                   AND b.project_id = :p AND b.year = :y AND b.half = :h AND b.user_id IN ($in)",
                [':p' => $projectId, ':y' => $year, ':h' => $half]
            ), 'name');
            if ($dupNames) {
                Response::json(['dup_warning' => true,
                    'message' => '같은 프로젝트·반기의 보너스가 이미 있는 직원: ' . implode(', ', $dupNames)
                        . ' — 중복 산정에 주의하세요.']);
            }
        }

        $base       = self::projectBonusBase($proj);
        $profitBase = self::projectProfitBase($proj);
        $alloc      = self::allocateContrib($base, $assignees);
        $ids = [];
        foreach ($targets as $a) {
            $uid = (int) $a['user_id'];
            $contribRev = (int) ($alloc[$uid] ?? 0);
            $calc = (int) round($contribRev * $bonusRate / 100);
            $data = [
                'user_id'                  => $uid,
                'project_id'               => $projectId,
                'year'                     => $year,
                'half'                     => $half,
                'base_amount'              => $base,
                'calc_basis'               => null,
                'contrib_revenue'          => $contribRev,
                'contrib_profit'           => (int) round($profitBase * (float) $a['pct'] / 100),
                'bonus_rate'               => $bonusRate,
                'calc_amount'              => $calc,
                'confirmed_bonus'          => $calc, // 확정 보너스 기본값 = 산정액(관리자가 수정 가능)
                'pay_date'                 => null,
                'pay_status'               => 'unpaid',
                'paid_by'                  => null,
                'memo'                     => $memo !== '' ? $memo : null,
                'contribution_pct_at_calc' => (float) $a['pct'],
                'created_by'               => Auth::id(),
            ];
            $newId = Db::insert('site_bonuses', $data);
            $after = Db::one('SELECT * FROM site_bonuses WHERE id = :id', [':id' => $newId]);
            Db::insert('site_bonus_history', [
                'bonus_id'    => $newId,
                'action'      => 'create',
                'before_json' => null,
                'after_json'  => json_encode($after, JSON_UNESCAPED_UNICODE),
                'reason'      => null,
                'changed_by'  => Auth::id(),
            ]);
            Audit::log('bonus.save', 'site_bonuses', $newId, null, $after);
            $ids[] = $newId;
        }
        Response::json(['ids' => $ids, 'count' => count($ids)]);
    }

    /** 소프트 삭제(원장 원칙 — 물리 DELETE 금지). 마감 반기면 reason 필수. */
    public function delete(): void
    {
        $id  = Util::postInt('id', 0) ?: 0;
        $row = Db::one('SELECT * FROM site_bonuses WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
        if (!$row) {
            Response::error('보너스 내역을 찾을 수 없습니다.', 404);
        }
        $reason = trim(Util::postStr('reason'));
        if (Util::isHalfClosed((int) $row['year'], (int) $row['half']) && $reason === '') {
            Response::error('마감된 반기 수정은 사유가 필요합니다.', 422);
        }

        Db::update('site_bonuses', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
        Db::insert('site_bonus_history', [
            'bonus_id'    => $id,
            'action'      => 'delete',
            'before_json' => json_encode($row, JSON_UNESCAPED_UNICODE),
            'after_json'  => null,
            'reason'      => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'changed_by'  => Auth::id(),
        ]);
        Audit::log('bonus.delete', 'site_bonuses', $id, $row, null);

        Response::json(['id' => $id]);
    }
}
