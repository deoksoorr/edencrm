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
    /** 지급 상태 화이트리스트. */
    private const PAY_STATUSES = ['unpaid', 'partial', 'paid', 'cancelled'];

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

    /** 보너스 합계(산정액/지급액/미지급액=산정−지급) — cancelled 제외. */
    private function bonusTotals(array $rows): array
    {
        $t = ['calc' => 0, 'paid' => 0, 'unpaid' => 0];
        foreach ($rows as $r) {
            if ($r['pay_status'] === 'cancelled') {
                continue;
            }
            $t['calc'] += (int) $r['calc_amount'];
            $t['paid'] += (int) $r['paid_amount'];
        }
        $t['unpaid'] = $t['calc'] - $t['paid'];
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
            $bonusByUser = [];
            foreach (Db::all(
                "SELECT user_id, COALESCE(SUM(paid_amount),0) AS s FROM site_bonuses
                 WHERE deleted_at IS NULL AND pay_status <> 'cancelled' AND year=:y AND half=:h
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
                    'paid'       => (int) ($paidByUser[$sid] ?? 0),
                    'revenue'    => (int) ($byUser[$sid]['revenue'] ?? 0),
                    'profit'     => (int) ($byUser[$sid]['contrib'] ?? 0),
                    'bonus_paid' => (int) ($bonusByUser[$sid] ?? 0),
                ];
            }
        }

        View::render('halfyear/index', [
            'title'        => '반기 현황',
            'f'            => $f,
            'years'        => self::yearOptions(),
            'users'        => $this->userOptions($f['canAll']),
            'projects'     => $this->projectOptions(),
            'revenueKpi'   => [
                'contracted' => $contracted, 'paid' => $paid, 'revenue' => $revenue,
                'receivable' => $receivable, 'projectCount' => $projectCount,
            ],
            'profitKpi'    => [
                'revenue' => $revenue, 'costReg' => $costReg, 'costDirect' => $costDirect,
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

    // ── 쓰기 (라우터가 bonus.manage + POST + CSRF 강제) ──

    /**
     * 등록/수정(id 유무로 분기). 수정 시 미전송 필드는 기존 값 유지(지급 처리 등 부분 폼 지원).
     * pay_status 는 cancelled 명시가 아니면 지급액/산정액 관계로 자동 보정.
     * 마감 반기의 수정은 reason 필수(422). 저장 후 history + Audit 적재.
     */
    public function save(): void
    {
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
        $paid = $money('paid_amount', $before['paid_amount'] ?? 0);

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
        if ($projectId > 0) {
            if (!Db::one('SELECT id FROM projects WHERE id = :id AND deleted_at IS NULL', [':id' => $projectId])) {
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

        // pay_status 보정: cancelled 명시 외에는 지급액으로 산출
        if ($payStatusIn === 'cancelled') {
            $payStatus = 'cancelled';
        } elseif ($paid <= 0) {
            $payStatus = 'unpaid';
        } elseif ($paid < $calc) {
            $payStatus = 'partial';
        } else {
            $payStatus = 'paid';
        }

        // 기여율 스냅샷: 등록 시 해당 user+project 의 active 배정 합(없으면 NULL),
        // 수정 시 기존 값 유지 — 이후 담당자·배정 변경이 과거 원장을 훼손하지 않도록.
        if ($before !== null) {
            $pctSnapshot = $before['contribution_pct_at_calc'];
        } else {
            $pctSnapshot = null;
            if ($projectId > 0) {
                $pctSnapshot = Db::val(
                    "SELECT SUM(contribution_pct) FROM project_assignments
                     WHERE project_id = :p AND user_id = :u AND status = 'active'",
                    [':p' => $projectId, ':u' => $userId]
                );
                $pctSnapshot = $pctSnapshot !== null ? (float) $pctSnapshot : null;
            }
        }

        $data = [
            'user_id'                  => $userId,
            'project_id'               => $projectId > 0 ? $projectId : null,
            'year'                     => $year,
            'half'                     => $half,
            'base_amount'              => $base,
            'calc_basis'               => $calcBasis !== '' ? $calcBasis : null,
            'calc_amount'              => $calc,
            'paid_amount'              => $paid,
            'pay_date'                 => $payDate !== '' ? $payDate : null,
            'pay_status'               => $payStatus,
            'paid_by'                  => $paidBy > 0 ? $paidBy : null,
            'memo'                     => $memo !== '' ? $memo : null,
            'contribution_pct_at_calc' => $pctSnapshot,
        ];

        if ($id > 0) {
            Db::update('site_bonuses', $data, 'id = :id', [':id' => $id]);
            // 액션 판별: 취소 전환 > 지급액·상태 변경(pay) > 일반 수정
            if ($payStatus === 'cancelled' && $before['pay_status'] !== 'cancelled') {
                $action = 'cancel';
            } elseif ((int) $before['paid_amount'] !== $paid || $before['pay_status'] !== $payStatus) {
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
