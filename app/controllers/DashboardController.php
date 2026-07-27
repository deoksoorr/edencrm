<?php
/**
 * 대시보드 — 권한별 4변형(boss/sales/site/staff). 정보를 업무 목적별 섹션으로 그룹화한다.
 * R3: '주의가 필요한 항목' 레일 폐지 — 항목은 관련 섹션이 흡수(지연→공정, 미수금→재무, 계약대기→영업, 미배정→직원 업무).
 *  - boss  (super_admin·accountant): ①핵심 KPI ②직원 일정·업무 ③영업·계약 ④프로젝트·공정 ⑤재무·추이 ⑥직원성과 ⑦최근활동
 *  - sales (sales_manager)         : 내 영업 핵심 / 목표 / 파이프라인(연락·계약대기 흡수)
 *  - site  (site_manager)          : 공정 핵심(지연·미배정·일지·검수 포함) / 공정상태 / 일정
 *  - staff (staff)                 : 오늘 일정 / 내 프로젝트 / 작업할 공정 / 알림
 *
 * 금액은 Util::moneyShort/moneyCell 로 축약, 모든 계산은 Calc 사용(0 나눗셈 → null → '-').
 * 차트는 boss/sales 만 사용(월별추이 bar+line, 영업단계 6그룹 도넛). site/staff 는 차트 없이 칩·진행바·리스트.
 */
class DashboardController
{
    public function index(): void
    {
        // 업무 누락 알림 최신화(모든 역할 진입 시 1회)
        try {
            load_controller('NotificationsController');
            NotificationsController::generateMissing();
        } catch (\Throwable $e) { /* 알림 생성 실패는 대시보드를 막지 않음 */ }

        $u = Auth::user();
        switch ($u['role']) {
            case 'sales_manager': $this->renderSales($u); break;
            case 'site_manager':  $this->renderSite($u);  break;
            case 'staff':         $this->renderStaff($u); break;
            default:              $this->renderBoss($u);  break; // super_admin, accountant
        }
    }

    /** 차트 JSON(boss/sales 전용). */
    public function data(): void
    {
        $u = Auth::user();
        if ($u['role'] === 'sales_manager') {
            Response::json($this->salesCharts((int) $u['id']));
        } else {
            Response::json($this->bossCharts());
        }
    }

    // ═══════════════════════ BOSS (사장·회계) ═══════════════════════

    private function renderBoss(array $u): void
    {
        // '주의가 필요한 항목' 레일은 R3 에서 제거 — 항목은 각 섹션이 흡수(지연→공정, 미수금→재무, 계약대기→영업, 미배정→직원)
        $attn = $this->attention(null);
        View::render('dashboard/boss', [
            'title'      => '대시보드',
            'me'         => $u,
            'kpi'        => $this->bossKpi(),
            'attn'       => $attn,
            'funnel'     => $this->salesFunnel(null),
            'finance'    => $this->finance(null),
            'process'    => $this->processChips(null),
            'board'      => $this->processBoardCounts(),
            'workstatus' => $this->employeeWork(),
            'attend'     => $this->attendanceSummary(), // R4: 근태는 feature_worklog 아닌 feature_attendance 게이트
            'cash'       => $this->cashflowLists(),     // R4 T6: 최근 입금·출금(원가 지출) 리스트
            'workload'   => $this->employeeLoad(),
            'perf'       => $this->staffPerformance(),
            'activity'   => $this->recentActivity(),
            'wl'         => Settings::enabled('feature_worklog'),
            'scripts'    => ['vendor/chart.umd.js', 'js/dashboard.js'],
        ]);
    }

    /**
     * 오늘의 직원 업무 현황 — 오늘 일정이 있는 직원만(오늘 일정·현재 프로젝트·공정·상태).
     * 이번 달 출근 요약은 attendanceSummary()(R4 분리 — feature_worklog 와 무관)가 담당.
     */
    private function employeeWork(): array
    {
        $emps = Db::all("SELECT id, name, color, role_key FROM users WHERE deleted_at IS NULL AND status='active' AND role_key IN ('sales_manager','site_manager','staff') ORDER BY name");
        if (!$emps) { return ['today' => []]; }
        $ids = array_map('intval', array_column($emps, 'id'));
        $in = implode(',', $ids);
        $roleLabel = ['sales_manager' => '영업', 'site_manager' => '현장', 'staff' => '직원'];
        $colorOf = [];
        foreach ($emps as $e) { $colorOf[(int) $e['id']] = $e['color'] ?: Stages::defaultColorFor((int) $e['id']); }

        // 오늘 일정(참여) — 직원별(슬롯 순)
        $todayRows = Db::all(
            "SELECT sp.user_id uid, s.title, s.slot, p.name AS project_name, p.status AS pstatus, ps.name AS stage_name
             FROM schedule_participants sp
             JOIN schedules s ON s.id=sp.schedule_id
                  AND s.event_date<=CURDATE() AND COALESCE(s.end_date, s.event_date)>=CURDATE()
             LEFT JOIN projects p ON p.id=s.project_id AND p.deleted_at IS NULL
             LEFT JOIN process_stages ps ON ps.id=p.process_stage_id
             WHERE sp.user_id IN ($in)
               AND (p.id IS NULL OR p.status NOT IN ('cancelled','terminated'))
             ORDER BY FIELD(s.slot,'am','pm','night')"
        );
        $byUser = [];
        foreach ($todayRows as $r) { $byUser[(int) $r['uid']][] = $r; }

        $today = [];
        foreach ($emps as $e) {
            $id = (int) $e['id'];
            $list = $byUser[$id] ?? [];
            if (!$list) { continue; } // 오늘 일정 없는 직원 제외
            $f = $list[0];
            $status = ($f['pstatus'] === 'in_progress') ? 'active' : (($f['pstatus'] === 'completed') ? 'done' : 'planned');
            $today[] = [
                'id'      => $id,
                'name'    => $e['name'],
                'color'   => $colorOf[$id],
                'role'    => $roleLabel[$e['role_key']] ?? '직원',
                'sched'   => '[' . Stages::slotLabel($f['slot']) . '] ' . $f['title'],
                'more'    => count($list) - 1,
                'project' => $f['project_name'],
                'stage'   => $f['stage_name'],
                'status'  => $status,
            ];
        }

        return ['today' => $today];
    }

    /**
     * 이번 달 직원 출근 요약(boss ② 섹션) — AttendanceService 공용 집계(분석 탭과 동일 산식).
     * R4 복구: R2 에서 feature_worklog OFF 에 묶여 사라졌던 출근 현황을 feature_attendance(기본 ON)로 분리.
     * R6 최종 구조: 통계 3종만 표시 — 출근 일수(user_id+work_date DISTINCT − 무단결근 마킹일 제외)
     * · 지각(late 마크 수) · 무단결근(absent 마크 수). 휴가·출근율·전월 증감·자동 판정 표기는 제거.
     * 반환 null=기능 OFF(섹션 미표시).
     */
    private function attendanceSummary(): ?array
    {
        if (!Settings::enabled('feature_attendance')) { return null; }
        $emps = Db::all("SELECT id, name, color, role_key FROM users WHERE deleted_at IS NULL AND status='active' AND role_key IN ('sales_manager','site_manager','staff') ORDER BY name");
        $ids = array_map('intval', array_column($emps, 'id'));
        $ov = AttendanceService::monthOverview((int) date('Y'), (int) date('n'), $ids);
        $roleLabel = ['sales_manager' => '영업', 'site_manager' => '현장', 'staff' => '직원'];
        $rows = [];
        foreach ($emps as $e) {
            $id = (int) $e['id'];
            $rows[] = [
                'id'     => $id,
                'name'   => $e['name'],
                'color'  => $e['color'] ?: Stages::defaultColorFor($id),
                'role'   => $roleLabel[$e['role_key']] ?? '직원',
                'days'   => (int) ($ov['days'][$id] ?? 0),
                'late'   => (int) ($ov['late'][$id] ?? 0),
                'absent' => (int) ($ov['absent'][$id] ?? 0),
            ];
        }
        usort($rows, fn($a, $b) => $b['days'] <=> $a['days']);
        return ['rows' => $rows];
    }

    /**
     * 최근 입금·출금 리스트(boss ⑤-1, R4 T6) — 데이터 정의·정렬은 서비스 단일 출처:
     *  입금 = AccountingService::recentPaidPayments (paid 정상 입금, 환불·취소 제외 — 환불 발생 계약은 배지 병기)
     *  출금 = CostService::recentConfirmed (확정 actual 원가, 지출일 순 — 라벨 '최근 출금(원가 지출)')
     * pay_type 라벨은 ContractsController::PAY_TYPE_LABELS 재사용(중복 정의 금지).
     */
    private function cashflowLists(): array
    {
        load_controller('ContractsController');
        $in = AccountingService::recentPaidPayments(8);
        foreach ($in as &$r) {
            $r['pay_type_label'] = ContractsController::PAY_TYPE_LABELS[$r['pay_type']] ?? $r['pay_type'];
        }
        unset($r);
        return ['in' => $in, 'out' => CostService::recentConfirmed(8)];
    }

    /**
     * 핵심 KPI (R3): 금액 4종(이번 달 확정 매출(공급가액)·입금 총액(VAT 포함)·원가 총액(발생일)·실제 순이익)
     * + 건수 3종(계약 진행·진행 중 프로젝트·지연 프로젝트). 금액 집계는 전부 AccountingService 단일 출처.
     * 원가 총액은 costs(확정·actual) 발생일(spent_date) 기준 — 준공월 귀속 confirmedCost 와 축이 다르다.
     */
    private function bossKpi(): array
    {
        $mFrom = date('Y-m-01'); $mTo = date('Y-m-t');
        $pFrom = date('Y-m-01', strtotime('first day of last month'));
        $pTo   = date('Y-m-t', strtotime('last month'));

        $rev      = (float) AccountingService::confirmedRevenue($mFrom, $mTo);
        $prevRev  = (float) AccountingService::confirmedRevenue($pFrom, $pTo);
        $paid     = (float) AccountingService::paidTotal($mFrom, $mTo);
        $prevPaid = (float) AccountingService::paidTotal($pFrom, $pTo);
        $cost     = (float) AccountingService::costTotal($mFrom, $mTo);
        $prevCost = (float) AccountingService::costTotal($pFrom, $pTo);
        $profit   = (float) AccountingService::confirmedProfit($mFrom, $mTo);

        return [
            'revenue'   => ['value' => $rev,  'delta' => $this->delta($rev, $prevRev)],
            'paid'      => ['value' => $paid, 'delta' => $this->delta($paid, $prevPaid)],
            'cost'      => ['value' => $cost, 'delta' => $this->delta($cost, $prevCost)],
            'profit'    => ['value' => $profit],
            'contracts' => ['value' => AccountingService::activeContractCount()],
            'active'    => ['value' => $this->countProjects("status='in_progress'")],
            'delayed'   => ['value' => $this->countProjects($this->delayedCond())],
        ];
    }

    /**
     * 공정 보드 요약(대기중/진행 공정/검수 대기) — projects.process_stage_id + process_stages 기준.
     * 화면=보드 일치(acctverify): '대기중'은 공정 보드 대기중 컬럼과 동일 모집단
     * (preparing/in_progress/paused/warranty — ProcessController::BOARD_STATUSES 와 동일, 진행 예정 포함).
     * '공정 진행'·'검수 대기'는 진행 중(in_progress) 공사 기준 — 보드 상단 '검수 대기'와 동일 정의.
     */
    private function processBoardCounts(): array
    {
        $rows = Db::all(
            "SELECT CASE WHEN ps.stage_key='waiting' THEN 'waiting'
                         WHEN ps.stage_key='full_complete' THEN 'doing'
                         WHEN ps.requires_confirm=1 THEN 'inspect'
                         ELSE 'doing' END AS bucket, COUNT(*) cnt
             FROM projects p JOIN process_stages ps ON ps.id=p.process_stage_id
             WHERE p.deleted_at IS NULL
               AND (p.status='in_progress'
                    OR (ps.stage_key='waiting' AND p.status IN ('preparing','paused','warranty')))
             GROUP BY 1"
        );
        $by = array_column($rows, 'cnt', 'bucket');
        return [
            'waiting' => (int) ($by['waiting'] ?? 0),
            'doing'   => (int) ($by['doing'] ?? 0),
            'inspect' => (int) ($by['inspect'] ?? 0),
        ];
    }

    /**
     * 직원별 업무 부하(boss ② 섹션) — 배정·담당 중인 진행/예정 프로젝트 수 + 오늘 일정 수.
     * 취소·파기·완료 프로젝트는 업무량에서 제외(브리프 §2). 부하 큰 순 정렬.
     */
    private function employeeLoad(): array
    {
        $emps = Db::all("SELECT id, name, color, role_key FROM users WHERE deleted_at IS NULL AND status='active' AND role_key IN ('sales_manager','site_manager','staff') ORDER BY name");
        if (!$emps) { return []; }
        $ids = array_map('intval', array_column($emps, 'id'));
        $in = implode(',', $ids);

        $projRows = Db::all(
            "SELECT uid, COUNT(DISTINCT pid) c FROM (
                SELECT pa.user_id uid, pa.project_id pid FROM project_assignments pa
                    JOIN projects p ON p.id=pa.project_id AND p.deleted_at IS NULL AND p.status IN ('preparing','in_progress')
                UNION SELECT p.site_manager_id, p.id FROM projects p
                    WHERE p.deleted_at IS NULL AND p.status IN ('preparing','in_progress') AND p.site_manager_id IS NOT NULL
                UNION SELECT p.sales_user_id, p.id FROM projects p
                    WHERE p.deleted_at IS NULL AND p.status IN ('preparing','in_progress') AND p.sales_user_id IS NOT NULL
             ) t WHERE uid IN ($in) GROUP BY uid"
        );
        $projBy = array_column($projRows, 'c', 'uid');
        $schedRows = Db::all(
            "SELECT sp.user_id uid, COUNT(*) c FROM schedule_participants sp
             JOIN schedules s ON s.id=sp.schedule_id
                  AND s.event_date<=CURDATE() AND COALESCE(s.end_date, s.event_date)>=CURDATE()
             WHERE sp.user_id IN ($in) GROUP BY sp.user_id"
        );
        $schedBy = array_column($schedRows, 'c', 'uid');

        $roleLabel = ['sales_manager' => '영업', 'site_manager' => '현장', 'staff' => '직원'];
        $out = [];
        foreach ($emps as $e) {
            $id = (int) $e['id'];
            $out[] = [
                'id'       => $id,
                'name'     => $e['name'],
                'color'    => $e['color'] ?: Stages::defaultColorFor($id),
                'role'     => $roleLabel[$e['role_key']] ?? '직원',
                'projects' => (int) ($projBy[$id] ?? 0),
                'today'    => (int) ($schedBy[$id] ?? 0),
            ];
        }
        usort($out, fn($a, $b) => [$b['projects'], $b['today']] <=> [$a['projects'], $a['today']]);
        return $out;
    }

    /**
     * 최근 활동(boss ⑦ 섹션) — 영업(신규 문의)·계약 상태 변경·프로젝트 상태 변경·공정 이동을 시간순 통합.
     * 표시·링크 가공은 뷰가 담당(kind: lead|contract|project|process).
     */
    private function recentActivity(int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        return Db::all(
            "(SELECT 'contract' AS kind, h.changed_at AS at, c.id AS ref_id, c.contract_no AS title,
                     h.from_status AS f, h.to_status AS t, u.name AS actor
                FROM contract_status_history h
                JOIN contracts c ON c.id=h.contract_id AND c.deleted_at IS NULL
                LEFT JOIN users u ON u.id=h.changed_by)
             UNION ALL
             (SELECT 'project', h.changed_at, p.id, p.name, h.from_status, h.to_status, u.name
                FROM project_status_history h
                JOIN projects p ON p.id=h.project_id AND p.deleted_at IS NULL
                LEFT JOIN users u ON u.id=h.changed_by)
             UNION ALL
             (SELECT 'process', h.changed_at, p.id, p.name, fs.name, ts.name, u.name
                FROM project_process_history h
                JOIN projects p ON p.id=h.project_id AND p.deleted_at IS NULL
                LEFT JOIN process_stages fs ON fs.id=h.from_stage_id
                JOIN process_stages ts ON ts.id=h.to_stage_id
                LEFT JOIN users u ON u.id=h.changed_by)
             UNION ALL
             (SELECT 'lead', l.created_at, l.id, cu.name, NULL, l.work_type, u.name
                FROM leads l
                JOIN customers cu ON cu.id=l.customer_id
                LEFT JOIN users u ON u.id=l.sales_user_id
                WHERE l.deleted_at IS NULL)
             ORDER BY at DESC LIMIT $limit"
        );
    }

    // ═══════════════════════ SALES (영업관리자) ═══════════════════════

    private function renderSales(array $u): void
    {
        $uid = (int) $u['id'];
        View::render('dashboard/sales', [
            'title'   => '영업 대시보드',
            'me'      => $u,
            'kpi'     => $this->salesKpi($uid),
            'attn'    => $this->attention($uid),
            'funnel'  => $this->salesFunnel($uid),
            'goal'    => $this->goal($uid),
            'scripts' => ['vendor/chart.umd.js', 'js/dashboard.js'],
        ]);
    }

    private function salesKpi(int $uid): array
    {
        // 내 담당 리드 기준(가중) — AccountingService 단일 출처
        $pipeline = (float) AccountingService::weightedPipeline($uid);
        // 내 담당 이번달 수주(공급가 기준, 취소 제외) — AccountingService 단일 출처
        $mFrom = date('Y-m-01'); $mTo = date('Y-m-t');
        $rev = AccountingService::contractedAmount($mFrom, $mTo, $uid);
        $goal = $this->goal($uid);
        $today = date('Y-m-d');
        $contactToday = (int) Db::val(
            "SELECT COUNT(*) FROM leads l JOIN pipeline_stages ps ON ps.id=l.stage_id
             WHERE l.deleted_at IS NULL AND l.sales_user_id=:u AND ps.is_won=0 AND ps.is_lost=0
               AND l.next_contact_date=:d", [':u' => $uid, ':d' => $today]
        );
        $closing = (int) Db::val(
            "SELECT COUNT(*) FROM leads l JOIN pipeline_stages ps ON ps.id=l.stage_id
             WHERE l.deleted_at IS NULL AND l.sales_user_id=:u AND ps.stage_key IN ('negotiating','contract_pending')",
            [':u' => $uid]
        );
        return [
            'pipeline'   => ['value' => $pipeline],
            'revenue'    => ['value' => $rev],
            'conv'       => ['value' => $goal['rate']],
            'closing'    => ['value' => $closing],
            'contact'    => ['value' => $contactToday],
        ];
    }

    // ═══════════════════════ SITE (현장관리자) ═══════════════════════

    private function renderSite(array $u): void
    {
        $uid = (int) $u['id'];
        View::render('dashboard/site', [
            'title'    => '현장 대시보드',
            'me'       => $u,
            'kpi'      => $this->siteKpi($uid), // '주의' 레일 항목(지연·미배정·일지·검수)은 KPI 가 동일 표시 — attn 데이터 불필요(R3)
            'process'  => $this->processChips($uid),
            'pgroups'  => $this->processGroupCounts($uid),
            'schedule' => $this->scheduleSummary($uid),
        ]);
    }

    private function siteKpi(int $uid): array
    {
        // 현장관리자: 본인 담당(site_manager_id) 또는 배정된 프로젝트 범위
        $scope = "(p.site_manager_id=:u1 OR EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id=p.id AND pa.user_id=:u2))";
        $p = [':u1' => $uid, ':u2' => $uid];
        $out = [
            'active'    => ['value' => $this->countProjects("status='in_progress'", $scope, $p)],
            'preparing' => ['value' => $this->countProjects("status='preparing'", $scope, $p)],
            'delayed'   => ['value' => $this->countProjects($this->delayedCond(), $scope, $p)],
            'inspect'   => ['value' => $this->inspectionPending($uid)],
            'unassigned'=> ['value' => $this->unassignedProjects($uid)],
        ];
        if (Settings::enabled('feature_worklog')) {
            $out['worklog'] = ['value' => $this->worklogMissing($uid)];
        }
        return $out;
    }

    // ═══════════════════════ STAFF (일반직원) ═══════════════════════

    private function renderStaff(array $u): void
    {
        $uid = (int) $u['id'];
        View::render('dashboard/staff', [
            'title'    => '내 대시보드',
            'me'       => $u,
            'kpi'      => $this->staffKpi($uid),
            'goal'     => $this->goal($uid),
            'pgroups'  => $this->processGroupCounts($uid),
            'schedule' => $this->scheduleSummary($uid),
            'projects' => $this->myProjectsList($uid),
            'wl'       => Settings::enabled('feature_worklog'),
        ]);
    }

    private function staffKpi(int $uid): array
    {
        $mine = "(p.sales_user_id=:a OR p.site_manager_id=:b OR EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id=p.id AND pa.user_id=:c))";
        $mp = [':a' => $uid, ':b' => $uid, ':c' => $uid];
        $out = [
            'today'    => ['value' => (int) Db::val("SELECT COUNT(*) FROM schedules s WHERE EXISTS(SELECT 1 FROM schedule_participants sp WHERE sp.schedule_id=s.id AND sp.user_id=:u) AND s.event_date<=CURDATE() AND COALESCE(s.end_date, s.event_date)>=CURDATE()", [':u' => $uid])],
            'week'     => ['value' => (int) Db::val("SELECT COUNT(*) FROM schedules s WHERE EXISTS(SELECT 1 FROM schedule_participants sp WHERE sp.schedule_id=s.id AND sp.user_id=:u) AND s.event_date<CURDATE()+INTERVAL 7 DAY AND COALESCE(s.end_date, s.event_date)>=CURDATE()", [':u' => $uid])],
            'projects' => ['value' => $this->countProjects("status IN ('preparing','in_progress')", $mine, $mp)],
            'unread'   => ['value' => (int) Db::val("SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0", [':u' => $uid])],
        ];
        if (Settings::enabled('feature_worklog')) {
            $out['worklog'] = ['value' => $this->worklogMissing($uid)];
        }
        return $out;
    }

    // ═══════════════════════ 공용 집계 블록 ═══════════════════════

    /** 주의가 필요한 항목. $uid 지정 시 해당 담당 범위로 축소(sales/site). null=전체(boss). */
    private function attention(?int $uid): array
    {
        $today = date('Y-m-d');
        $leadScope = $uid !== null ? ' AND l.sales_user_id=:lu' : '';
        $leadP = $uid !== null ? [':lu' => $uid] : [];

        $contactOverdue = (int) Db::val(
            "SELECT COUNT(*) FROM leads l JOIN pipeline_stages ps ON ps.id=l.stage_id
             WHERE l.deleted_at IS NULL AND ps.is_won=0 AND ps.is_lost=0
               AND l.next_contact_date IS NOT NULL AND l.next_contact_date < :t $leadScope",
            array_merge([':t' => $today], $leadP)
        );
        $contactNone = (int) Db::val(
            "SELECT COUNT(*) FROM leads l JOIN pipeline_stages ps ON ps.id=l.stage_id
             WHERE l.deleted_at IS NULL AND ps.is_won=0 AND ps.is_lost=0
               AND (l.next_contact_date IS NULL OR l.stage_entered_at < CURDATE()-INTERVAL 3 DAY) $leadScope",
            $leadP
        );
        $contractStale = (int) Db::val(
            "SELECT COUNT(*) FROM leads l JOIN pipeline_stages ps ON ps.id=l.stage_id
             WHERE l.deleted_at IS NULL AND ps.stage_key='contract_pending'
               AND l.stage_entered_at < CURDATE()-INTERVAL 7 DAY $leadScope",
            $leadP
        );

        [$pscope, $pparams] = $this->siteScope($uid);
        $out = [
            'contact_overdue' => ['n' => $contactOverdue, 'label' => '연락 예정일 경과',    'route' => 'pipeline.index', 'params' => ['quick' => 'overdue'], 'sev' => 'danger'],
            'contact_none'    => ['n' => $contactNone,    'label' => '3일+ 미접촉 고객',      'route' => 'pipeline.index', 'params' => ['quick' => 'stale'],   'sev' => 'warn'],
            'contract_stale'  => ['n' => $contractStale,  'label' => '계약 대기 지연(7일+)',  'route' => 'pipeline.index', 'params' => ['tab' => 'contract'], 'sev' => 'warn'],
            'delayed'         => ['n' => $this->countProjects($this->delayedCond(), $pscope, $pparams), 'label' => '공정 지연 프로젝트', 'route' => 'projects.index', 'params' => ['status' => 'delayed'], 'sev' => 'danger'],
            'receivable'      => ['n' => $this->receivableCount(), 'label' => '미수금 발생 건',  'route' => 'contracts.index', 'params' => [], 'sev' => 'warn'],
            'unassigned'      => ['n' => $this->unassignedProjects($uid), 'label' => '직원 미배정 공사', 'route' => 'projects.index', 'params' => ['assign' => 'none'], 'sev' => 'warn'],
            'inspect'         => ['n' => $this->inspectionPending($uid), 'label' => '검수 대기',      'route' => 'process.board', 'params' => [], 'sev' => 'warn'],
        ];
        if (Settings::enabled('feature_worklog')) {
            $out['worklog'] = ['n' => $this->worklogMissing($uid), 'label' => '오늘 작업일지 미작성', 'route' => 'worklogs.index', 'params' => [], 'sev' => 'warn'];
        }
        return $out;
    }

    /** 영업 퍼널(단계 그룹 카운트). */
    private function salesFunnel(?int $uid): array
    {
        $scope = $uid !== null ? ' AND l.sales_user_id=:u' : '';
        $p = $uid !== null ? [':u' => $uid] : [];
        $rows = Db::all(
            "SELECT ps.stage_key, COUNT(l.id) cnt FROM pipeline_stages ps
             LEFT JOIN leads l ON l.stage_id=ps.id AND l.deleted_at IS NULL $scope
             GROUP BY ps.stage_key", $p
        );
        $by = array_column($rows, 'cnt', 'stage_key');
        $g = fn($keys) => array_sum(array_map(fn($k) => (int) ($by[$k] ?? 0), $keys));
        return [
            'steps' => [
                ['label' => '신규',    'n' => $g(['new_inquiry'])],
                ['label' => '상담·현장', 'n' => $g(['consult_booked', 'site_survey'])],
                ['label' => '견적',    'n' => $g(['quote_drafting', 'quote_sent', 'negotiating'])],
                ['label' => '계약대기', 'n' => $g(['contract_pending'])],
                ['label' => '계약완료', 'n' => $g(['contract_won']), 'won' => true],
            ],
            'conversion' => $this->conversionRate($uid),
            'avg_days'   => $this->avgContractDays($uid),
        ];
    }

    /** 재무 요약 + 목표. 확정매출/확정순이익 = completed·actual_end_date·공급가 기준(AccountingService). */
    private function finance(?int $uid): array
    {
        $mFrom = date('Y-m-01'); $mTo = date('Y-m-t');
        $revenue = (float) AccountingService::confirmedRevenue($mFrom, $mTo);
        $cost    = (float) AccountingService::confirmedCost($mFrom, $mTo);
        return [
            'revenue'          => $revenue,                                              // 확정 매출(공급가액)
            'contracted'       => (float) AccountingService::contractedAmount($mFrom, $mTo), // 이번달 수주액(신규)
            'pipeline'         => (float) AccountingService::weightedPipeline(),          // 가중 예상매출
            'expected_rev'     => (float) AccountingService::expectedRevenue(),           // 진행+미착공 공급가
            'actual_cost'      => $cost,
            'confirmed_profit' => (float) AccountingService::confirmedProfit($mFrom, $mTo),
            'profit_rate'      => Calc::profitRate($revenue, $cost),
            'paid_total'       => (float) AccountingService::paidTotal($mFrom, $mTo),     // 이번달 입금 총액(VAT 포함)
            'receivable'       => (float) AccountingService::receivable(),
            'receivable_count' => AccountingService::receivableCount(),                    // 미수금 발생 계약 수(재무 현황이 흡수)
            'goal'             => $this->goal(null),
        ];
    }

    /** 목표 진행: target/actual/remaining/rate. $uid=null → 전체(회사) 목표, actual=확정매출. */
    private function goal(?int $uid): array
    {
        $y = (int) date('Y'); $m = (int) date('n');
        if ($uid !== null) {
            // 개인 목표: 실제(actual) = 담당 이번달 수주 공급가 합(공급가 기준 통일, 취소 제외) — AccountingService 단일 출처
            $target = (float) Db::val("SELECT COALESCE(target_revenue,0) FROM targets WHERE user_id=:u AND year=:y AND month=:m", [':u' => $uid, ':y' => $y, ':m' => $m]);
            $mFrom = sprintf('%04d-%02d-01', $y, $m);
            $mTo   = date('Y-m-t', strtotime($mFrom));
            $actual = (float) AccountingService::contractedAmount($mFrom, $mTo, $uid);
        } else {
            // 회사 월 목표(목표 관리 화면의 company_targets 기준), actual = 확정매출
            $target = (float) Db::val("SELECT COALESCE(target_revenue,0) FROM company_targets WHERE period_type='month' AND year=:y AND period_no=:m", [':y' => $y, ':m' => $m]);
            $actual = (float) AccountingService::confirmedRevenue(date('Y-m-01'), date('Y-m-t'));
        }
        return [
            'target'    => $target,
            'actual'    => $actual,
            'remaining' => max(0, $target - $actual),
            'rate'      => AccountingService::achievement($actual, $target),
            'set'       => $target > 0,   // 목표 미설정 판별
        ];
    }

    /** 공정 상태 칩. */
    private function processChips(?int $uid): array
    {
        [$scope, $p] = $this->siteScope($uid);
        $soon = date('Y-m-d', strtotime('+7 day'));
        // T8: 3단 단순 상태(대기/공정/완료) — 공정보드·분석과 동일 그룹(StatusService 단일 출처)
        $in = static fn(string $g): string => "status IN ('" . implode("','", StatusService::simpleStatuses($g)) . "')";
        return [
            ['label' => StatusService::SIMPLE_LABELS['waiting'], 'n' => $this->countProjects($in('waiting'), $scope, $p), 'route' => 'projects.index', 'params' => ['status' => 'preparing']],
            ['label' => StatusService::SIMPLE_LABELS['working'], 'n' => $this->countProjects($in('working'), $scope, $p), 'route' => 'projects.index', 'params' => ['status' => 'in_progress']],
            ['label' => StatusService::SIMPLE_LABELS['done'],    'n' => $this->countProjects($in('done'), $scope, $p),    'route' => 'projects.index', 'params' => ['status' => 'completed']],
            ['label' => '지연',      'n' => $this->countProjects($this->delayedCond(), $scope, $p), 'route' => 'projects.index', 'params' => ['status' => 'delayed'], 'sev' => 'danger'],
            ['label' => '검수 대기', 'n' => $this->inspectionPending($uid), 'route' => 'process.board', 'params' => [], 'sev' => 'warn'],
            ['label' => '준공 임박', 'n' => $this->countProjects("status='in_progress' AND end_date IS NOT NULL AND end_date BETWEEN CURDATE() AND '$soon'", $scope, $p), 'route' => 'projects.index', 'params' => []],
        ];
    }

    /** 공정 그룹별 진행 프로젝트 수(현장/직원 대시보드). */
    private function processGroupCounts(?int $uid): array
    {
        [$scope, $p] = $uid !== null ? $this->mineScope($uid) : ['1=1', []];
        $rows = Db::all(
            "SELECT COALESCE(ps.stage_key,'') AS stage_key, COUNT(DISTINCT p.id) cnt FROM projects p
             LEFT JOIN process_stages ps ON ps.id=p.process_stage_id
             WHERE p.deleted_at IS NULL AND p.status='in_progress' AND $scope
             GROUP BY COALESCE(ps.stage_key,'')", $p
        );
        $by = array_column($rows, 'cnt', 'stage_key');
        // R8: 도장·인테리어 양 유형의 단계→그룹 매핑을 합산(인테리어 단계 체류 프로젝트 누락 방지)
        $mapAll = Stages::processStageToGroup('interior') + Stages::processStageToGroup('painting');
        $groupN = [];
        foreach ($by as $sk => $cnt) {
            $gk = $mapAll[$sk] ?? 'prep';
            $groupN[$gk] = ($groupN[$gk] ?? 0) + (int) $cnt;
        }
        $out = [];
        foreach (Stages::processGroups() as $gkey => $g) {
            $out[] = ['label' => $g['label'], 'color' => $g['color'], 'n' => (int) ($groupN[$gkey] ?? 0)];
        }
        return $out;
    }

    /** 오늘·이번주 일정 요약 리스트. */
    private function scheduleSummary(int $uid): array
    {
        return Db::all(
            "SELECT s.title, s.start_datetime, s.event_date, s.slot, s.type, p.name AS project_name
             FROM schedules s LEFT JOIN projects p ON p.id=s.project_id
             WHERE EXISTS(SELECT 1 FROM schedule_participants sp WHERE sp.schedule_id=s.id AND sp.user_id=:u)
               AND (s.project_id IS NULL OR p.status NOT IN ('cancelled','terminated'))
               AND s.event_date>=CURDATE() AND s.event_date<CURDATE()+INTERVAL 7 DAY
             ORDER BY s.event_date, FIELD(s.slot,'am','pm','night') LIMIT 8", [':u' => $uid]
        );
    }

    private function myProjectsList(int $uid): array
    {
        [$scope, $p] = $this->mineScope($uid);
        return Db::all(
            "SELECT DISTINCT p.id, p.name, p.status, p.end_date, p.actual_end_date, ps.name AS stage_name, ps.color AS stage_color
             FROM projects p LEFT JOIN process_stages ps ON ps.id=p.process_stage_id
             WHERE p.deleted_at IS NULL AND p.status IN ('preparing','in_progress') AND $scope
             ORDER BY (p.end_date IS NULL), p.end_date LIMIT 8", $p
        );
    }

    /**
     * 직원 성과 표(boss) — T9 기여율(contribution_pct) 기반 7항목:
     * 참여·완료 프로젝트수, 기여매출·기여원가·기여순이익(확정), 입금 기여, 이번 달 실적, 순이익률(가중), 회사기여율, 일정준수율.
     * 전체금액 100% 중복 귀속 금지 — 기여율 없으면 성과 미반영(AccountingService 단일 산식).
     */
    private function staffPerformance(): array
    {
        $mFrom = date('Y-m-01'); $mTo = date('Y-m-t');
        $users = Db::all("SELECT id, name, role_key FROM users WHERE deleted_at IS NULL AND status='active' AND role_key IN ('sales_manager','site_manager','staff') ORDER BY name");
        if (!$users) { return []; }

        // 참여 프로젝트 = 기여율>0 배정 기준(T9: 전체금액 100% 중복 귀속 금지 — 기여율 없으면 미반영)
        $cntBy = AccountingService::employeeProjectCountByUser();
        // 일정 준수율(완료 프로젝트 중 기한 내 완료 비율) — 분자·분모 모집단 동일(completed+settled).
        // 분자에 상태 조건이 없으면 warranty(준공일 보존) 프로젝트가 분자에만 포함되어 100% 초과 가능(acctverify 수정).
        $onRows = Db::all(
            "SELECT pa.user_id uid,
                    SUM(CASE WHEN p.status IN ('completed','settled') AND p.actual_end_date IS NOT NULL
                             AND (p.end_date IS NULL OR p.actual_end_date<=p.end_date) THEN 1 ELSE 0 END) ontime,
                    SUM(CASE WHEN p.status IN ('completed','settled') THEN 1 ELSE 0 END) done
             FROM project_assignments pa JOIN projects p ON p.id=pa.project_id AND p.deleted_at IS NULL
             GROUP BY pa.user_id"
        );
        $onBy = [];
        foreach ($onRows as $r) { $onBy[$r['uid']] = ['ontime' => (int) $r['ontime'], 'done' => (int) $r['done']]; }

        // 회사 전체 확정순이익(회사기여율 분모) — 루프 밖 1회 조회(N+1 방지)
        $companyProfit = (float) AccountingService::companyConfirmedProfit();
        // 직원별 기여매출·기여원가·기여순이익·완료수(누적) + 월별 실적(이번 달) + 입금 기여 — 전부 기여율 기반 일괄 조회(T9)
        $confirmedBy = AccountingService::employeeConfirmedByUser();
        $monthBy     = AccountingService::employeeConfirmedByUser($mFrom, $mTo);
        $paidBy      = AccountingService::employeePaidByUser();

        $roleLabel = ['sales_manager' => '영업', 'site_manager' => '현장', 'staff' => '직원'];
        $out = [];
        foreach ($users as $usr) {
            $id = (int) $usr['id'];
            $contrib = (float) ($confirmedBy[$id]['contrib'] ?? 0);
            $attrRev = (float) ($confirmedBy[$id]['revenue'] ?? 0);
            $on = $onBy[$id] ?? ['ontime' => 0, 'done' => 0];
            $out[] = [
                'user_id'      => $id,
                'name'         => $usr['name'],
                'role'         => $roleLabel[$usr['role_key']] ?? '직원',
                'assigned'     => (int) ($cntBy[$id] ?? 0),
                'done'         => (int) ($confirmedBy[$id]['done'] ?? 0),
                'attr_cost'    => (float) ($confirmedBy[$id]['cost'] ?? 0),
                'paid_contrib' => (float) ($paidBy[$id] ?? 0),
                'month_contrib'=> (float) ($monthBy[$id]['contrib'] ?? 0),
                'contrib'      => $contrib,
                'attr_rev'     => $attrRev,
                'margin'       => Calc::rate($contrib, $attrRev),        // 귀속순이익÷귀속매출×100
                'company_rate' => Calc::rate($contrib, $companyProfit),  // 회사 순이익 기여율
                'ontime'       => $on['done'] > 0 ? Calc::rate($on['ontime'], $on['done']) : null,
            ];
        }
        // 순이익 기여(확정) 큰 순
        usort($out, fn($a, $b) => $b['contrib'] <=> $a['contrib']);
        return $out;
    }

    // ═══════════════════════ 차트 JSON ═══════════════════════

    /** boss 대시보드 차트 — 월별추이만(영업단계 도넛은 R3 에서 제거, stage_groups 는 sales 전용). */
    private function bossCharts(): array
    {
        return [
            'monthly_trend' => $this->monthlyTrend(),
        ];
    }

    /** 영업(sales) 대시보드 차트 — 회사 재무(월별추이)는 제외(권한: 영업은 회사 재무를 볼 수 없음). */
    private function salesCharts(int $uid): array
    {
        return [
            'stage_groups' => $this->stageGroupDist($uid),
        ];
    }

    /**
     * 최근 6개월 회사 전체 확정매출·확정순이익(완료·준공월(actual_end_date)·공급가 기준 — AccountingService,
     * 리포트 월별추이(ReportsController::monthlyTrend)와 동일 산식으로 대시보드/리포트 차트를 일치시킨다).
     * boss 전용(회사 재무) — sales 는 권한상 표시하지 않는다.
     */
    private function monthlyTrend(): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) { $months[] = date('Y-m', strtotime("first day of -$i month")); }
        $out = [];
        foreach ($months as $ym) {
            $from = $ym . '-01';
            $to   = date('Y-m-t', strtotime($from));
            $rev  = (float) AccountingService::confirmedRevenue($from, $to);
            $profit = (float) AccountingService::confirmedProfit($from, $to);
            $out[] = ['ym' => substr($ym, 2), 'revenue' => $rev, 'profit' => $profit];
        }
        return $out;
    }

    /** 영업단계 6그룹 분포(도넛) — sales 대시보드 전용(본인 담당 리드). r4-refactor(T10): 미사용 전사(null) 분기 제거. */
    private function stageGroupDist(int $uid): array
    {
        $rows = Db::all(
            "SELECT ps.stage_key, COUNT(l.id) cnt FROM pipeline_stages ps
             LEFT JOIN leads l ON l.stage_id=ps.id AND l.deleted_at IS NULL AND l.sales_user_id=:u
             GROUP BY ps.stage_key", [':u' => $uid]
        );
        $by = array_column($rows, 'cnt', 'stage_key');
        $out = [];
        foreach (Stages::pipelineGroups() as $g) {
            $n = 0;
            foreach ($g['stages'] as $sk) { $n += (int) ($by[$sk] ?? 0); }
            $out[] = ['label' => $g['label'], 'color' => $g['color'], 'n' => $n];
        }
        return $out;
    }

    // ═══════════════════════ 저수준 헬퍼 ═══════════════════════

    private function delayedCond(): string
    {
        return "status NOT IN ('completed','settled','cancelled','terminated') AND end_date IS NOT NULL AND end_date<CURDATE() AND actual_end_date IS NULL";
    }

    private function countProjects(string $cond, string $scope = '1=1', array $params = []): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM projects p WHERE p.deleted_at IS NULL AND ($cond) AND ($scope)", $params);
    }

    private function stageCount(array $stageKeys): int
    {
        $in = implode(',', array_fill(0, count($stageKeys), '?'));
        return (int) Db::val(
            "SELECT COUNT(*) FROM leads l JOIN pipeline_stages ps ON ps.id=l.stage_id
             WHERE l.deleted_at IS NULL AND ps.stage_key IN ($in)", $stageKeys
        );
    }

    /** 미수금 발생 계약 수 — AccountingService 단일 출처(receivable() 과 동일 모집단). */
    private function receivableCount(): int
    {
        return AccountingService::receivableCount();
    }

    private function conversionRate(?int $uid): ?float
    {
        $scope = $uid !== null ? ' AND l.sales_user_id=:u' : '';
        $p = $uid !== null ? [':u' => $uid] : [];
        $total = (int) Db::val("SELECT COUNT(*) FROM leads l WHERE l.deleted_at IS NULL $scope", $p);
        $won = (int) Db::val("SELECT COUNT(*) FROM leads l JOIN pipeline_stages ps ON ps.id=l.stage_id WHERE l.deleted_at IS NULL AND ps.is_won=1 $scope", $p);
        return Calc::rate($won, $total);
    }

    private function avgContractDays(?int $uid): ?float
    {
        $scope = $uid !== null ? ' AND l.sales_user_id=:u' : '';
        $p = $uid !== null ? [':u' => $uid] : [];
        $v = Db::val(
            "SELECT ROUND(AVG(DATEDIFF(l.stage_entered_at,l.created_at)))
             FROM leads l JOIN pipeline_stages ps ON ps.id=l.stage_id
             WHERE ps.stage_key='contract_won' AND l.deleted_at IS NULL AND l.stage_entered_at IS NOT NULL $scope", $p
        );
        return $v !== null ? (float) $v : null;
    }

    private function unassignedProjects(?int $uid): int
    {
        [$scope, $p] = $this->siteScope($uid);
        return (int) Db::val(
            "SELECT COUNT(*) FROM projects p WHERE p.deleted_at IS NULL
               AND p.status IN ('preparing','in_progress')
               AND NOT EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id=p.id) AND ($scope)", $p
        );
    }

    private function worklogMissing(?int $uid): int
    {
        [$scope, $p] = $this->siteScope($uid);
        return (int) Db::val(
            "SELECT COUNT(*) FROM projects p WHERE p.deleted_at IS NULL AND p.status='in_progress'
               AND NOT EXISTS(SELECT 1 FROM work_logs w WHERE w.project_id=p.id AND w.work_date=CURDATE()) AND ($scope)", $p
        );
    }

    private function inspectionPending(?int $uid): int
    {
        [$scope, $p] = $this->siteScope($uid);
        return (int) Db::val(
            "SELECT COUNT(*) FROM projects p JOIN process_stages ps ON ps.id=p.process_stage_id
             WHERE p.deleted_at IS NULL AND p.status='in_progress' AND ps.requires_confirm=1
               AND ps.stage_key<>'full_complete' AND ($scope)", $p
        );
    }

    /** 현장 범위(site_manager_id 또는 배정). null → 전체. */
    private function siteScope(?int $uid): array
    {
        if ($uid === null) { return ['1=1', []]; }
        return ["(p.site_manager_id=:sm OR EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id=p.id AND pa.user_id=:sa))", [':sm' => $uid, ':sa' => $uid]];
    }

    /** 내 범위(담당 영업/현장/배정). */
    private function mineScope(int $uid): array
    {
        return ["(p.sales_user_id=:m1 OR p.site_manager_id=:m2 OR EXISTS(SELECT 1 FROM project_assignments pa WHERE pa.project_id=p.id AND pa.user_id=:m3))", [':m1' => $uid, ':m2' => $uid, ':m3' => $uid]];
    }

    private function delta(float $cur, float $prev): ?array
    {
        if ($prev <= 0) { return $cur > 0 ? ['dir' => 'up', 'pct' => null] : null; }
        $pct = round(($cur - $prev) / $prev * 100, 1);
        return ['dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'), 'pct' => $pct];
    }
}
