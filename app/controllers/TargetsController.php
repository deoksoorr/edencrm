<?php
/**
 * 목표(KPI) 관리 — R9 확장.
 *
 * ① 회사 목표 그리드(company_targets, 월12·분기4·연간1) — 기존 유지(대시보드·리포트 소비, 무회귀).
 * ② 목표 원장(goals) — 유형(매출·순이익·계약액·계약건수·입금·프로젝트수) × 대상(회사·부서·직원)
 *    × 기간(월·분기·반기·연간·사용자지정). 달성률·상태는 GoalService 가 실데이터로 계산.
 *
 * 권한: 쓰기 전부 라우터 perm=settings.manage. 조회(index·history·progress)는 컨트롤러 스코프 —
 *   settings.manage 없으면 is_public=1 이면서 (회사 전체 | 본인 개인 | 본인 부서) 목표만 노출(쿼리 레벨).
 * 원장 원칙: 소프트 삭제만, 모든 변경은 goal_history 에 전/후 JSON 보존(등록/수정/종료/중단/삭제).
 * 중복 정책(기획 확정): 같은 대상·유형·기간 겹침은 경고 후 관리자 확인(confirm_dup=1)으로만 등록.
 */
class TargetsController
{
    // ── 화면 ──

    public function index(): void
    {
        $canManage = Rbac::can('settings.manage');
        $year = Util::int('year', (int) date('Y'));
        if ($year < 2000 || $year > 2100) { $year = (int) date('Y'); }

        // ① 회사 목표(관리자 전용 그리드) → [period_type][period_no] = row
        $company = [];
        if ($canManage) {
            foreach (Db::all("SELECT * FROM company_targets WHERE year=:y", [':y' => $year]) as $r) {
                $company[$r['period_type']][(int) $r['period_no']] = $r;
            }
        }

        // ② 목표 원장 — 필터 + 스코프
        $fMetric  = Util::str('metric');
        if (!in_array($fMetric, GoalService::METRICS, true)) { $fMetric = ''; }
        $fPeriod  = Util::str('period_type');
        if (!in_array($fPeriod, GoalService::PERIODS, true)) { $fPeriod = ''; }
        $fSubject = Util::str('subject_type');
        if (!in_array($fSubject, GoalService::SUBJECTS, true)) { $fSubject = ''; }
        $fStatus  = Util::str('status');
        if (!in_array($fStatus, ['active', 'ended', 'cancelled'], true)) { $fStatus = ''; }
        $fUserId  = $canManage ? (Util::int('user_id', 0) ?: 0) : 0;

        $where  = ['g.deleted_at IS NULL'];
        $params = [];
        // 연도 필터 = 목표 기간이 해당 연도와 겹치는 것
        $where[] = 'g.start_date <= :ye AND g.end_date >= :ys';
        $params[':ys'] = "$year-01-01";
        $params[':ye'] = "$year-12-31";
        if ($fMetric !== '')  { $where[] = 'g.metric = :m';        $params[':m'] = $fMetric; }
        if ($fPeriod !== '')  { $where[] = 'g.period_type = :p';   $params[':p'] = $fPeriod; }
        if ($fSubject !== '') { $where[] = 'g.subject_type = :s';  $params[':s'] = $fSubject; }
        if ($fStatus !== '')  { $where[] = 'g.status = :st';       $params[':st'] = $fStatus; }
        if ($fUserId > 0)     { $where[] = 'g.user_id = :fu';      $params[':fu'] = $fUserId; }
        $where[] = $this->goalScopeWhere($canManage, $params);

        $goals = Db::all(
            "SELECT g.*, u.name AS user_name, d.name AS dept_name, o.name AS owner_name
             FROM goals g
             LEFT JOIN users u ON u.id = g.user_id
             LEFT JOIN departments d ON d.id = g.department_id
             LEFT JOIN users o ON o.id = g.owner_user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY g.status = 'active' DESC, g.start_date DESC, g.id DESC
             LIMIT 200",
            $params
        );
        foreach ($goals as &$g) {
            $g['progress'] = GoalService::progress($g);
            $g['range_label'] = GoalService::resolveRange(
                $g['period_type'], (int) $g['year'], (int) $g['period_no'], $g['start_date'], $g['end_date']
            )['label'];
        }
        unset($g);

        View::render('targets/index', [
            'title'     => '목표 관리',
            'canManage' => $canManage,
            'year'      => $year,
            'years'     => range((int) date('Y') + 1, (int) date('Y') - 3),
            'company'   => $company,
            'goals'     => $goals,
            'f'         => ['metric' => $fMetric, 'period' => $fPeriod, 'subject' => $fSubject,
                            'status' => $fStatus, 'userId' => $fUserId],
            'users'     => $canManage ? Db::all(
                "SELECT id, name, status FROM users WHERE deleted_at IS NULL ORDER BY status='active' DESC, name"
            ) : [],
            'depts'     => Db::all('SELECT id, name FROM departments ORDER BY sort_order, name'),
            'scripts'   => [],
        ]);
    }

    /** 회사 목표(월12·분기4·연간1) 일괄 저장 — 기존 기능 유지. */
    public function save(): void
    {
        $year = Util::postInt('year', (int) date('Y'));
        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $rows[] = ['month', $m, $this->pf("m_rev_$m"), $this->pf("m_pft_$m")];
        }
        for ($q = 1; $q <= 4; $q++) {
            $rows[] = ['quarter', $q, $this->pf("q_rev_$q"), $this->pf("q_pft_$q")];
        }
        $rows[] = ['year', 0, $this->pf('y_rev'), $this->pf('y_pft')];

        foreach ($rows as [$type, $no, $rev, $pft]) {
            Db::run(
                "INSERT INTO company_targets(period_type,year,period_no,target_revenue,target_profit)
                 VALUES(:t,:y,:n,:r,:p)
                 ON DUPLICATE KEY UPDATE target_revenue=VALUES(target_revenue), target_profit=VALUES(target_profit)",
                [':t' => $type, ':y' => $year, ':n' => $no, ':r' => $rev, ':p' => $pft]
            );
        }
        Audit::log('company_target_save', 'company_targets', null, null, ['year' => $year]);
        if (Response::wantsJson()) { Response::json(['ok' => true]); }
        Response::redirect('targets.index', ['year' => $year], '회사 목표가 저장되었습니다.');
    }

    // ── 목표 원장 쓰기 (라우터가 settings.manage + POST + CSRF 강제) ──

    /** 목표 등록/수정(id 유무 분기). 중복(대상·유형·기간 겹침) 시 confirm_dup=1 없으면 경고 반환. */
    public function goalSave(): void
    {
        $id = Util::postInt('id', 0) ?: 0;
        $before = null;
        if ($id > 0) {
            $before = Db::one('SELECT * FROM goals WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
            if (!$before) {
                Response::error('목표를 찾을 수 없습니다.', 404);
            }
        }

        $name = trim(Util::postStr('name'));
        if ($name === '' || mb_strlen($name) > 100) {
            Response::error('목표명은 1~100자로 입력하세요.', 422);
        }
        $metric = Util::postStr('metric');
        if (!in_array($metric, GoalService::METRICS, true)) {
            Response::error('목표 유형이 올바르지 않습니다.', 422);
        }
        $subject = Util::postStr('subject_type');
        if (!in_array($subject, GoalService::SUBJECTS, true)) {
            Response::error('목표 대상이 올바르지 않습니다.', 422);
        }
        $userId = Util::postInt('user_id', 0) ?: 0;
        $deptId = Util::postInt('department_id', 0) ?: 0;
        if ($subject === 'user') {
            if ($userId <= 0 || !Db::one('SELECT id FROM users WHERE id=:id AND deleted_at IS NULL', [':id' => $userId])) {
                Response::error('대상 직원을 선택하세요.', 422);
            }
            $deptId = 0;
        } elseif ($subject === 'department') {
            if ($deptId <= 0 || !Db::one('SELECT id FROM departments WHERE id=:id', [':id' => $deptId])) {
                Response::error('대상 부서를 선택하세요.', 422);
            }
            $userId = 0;
        } else {
            $userId = 0;
            $deptId = 0;
        }

        $periodType = Util::postStr('period_type');
        if (!in_array($periodType, GoalService::PERIODS, true)) {
            Response::error('목표 기간 유형이 올바르지 않습니다.', 422);
        }
        $year = Util::postInt('year', 0) ?: 0;
        $no   = Util::postInt('period_no', 0) ?: 0;
        $customStart = trim(Util::postStr('start_date'));
        $customEnd   = trim(Util::postStr('end_date'));
        if ($periodType === 'custom') {
            foreach (['시작일' => $customStart, '종료일' => $customEnd] as $lbl => $d) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    Response::error("사용자 지정 기간의 {$lbl}을 입력하세요. (YYYY-MM-DD)", 422);
                }
            }
            if ($customStart > $customEnd) {
                Response::error('기간 시작일이 종료일보다 늦을 수 없습니다.', 422);
            }
            $year = (int) substr($customStart, 0, 4); // 연도 필터용
            $no   = 0;
        } else {
            if ($year < 2020 || $year > 2100) {
                Response::error('연도는 2020~2100 사이여야 합니다.', 422);
            }
            $valid = ['month' => [1, 12], 'quarter' => [1, 4], 'half' => [1, 2], 'year' => [0, 0]];
            [$lo, $hi] = $valid[$periodType];
            if ($no < $lo || $no > $hi) {
                Response::error('기간 값이 올바르지 않습니다.', 422);
            }
        }
        $range = GoalService::resolveRange($periodType, $year, $no, $customStart ?: null, $customEnd ?: null);

        $raw = str_replace([',', ' '], '', Util::postStr('target_value'));
        if (!preg_match('/^\d+$/', $raw) || (int) $raw <= 0) {
            Response::error('목표값은 1 이상의 정수여야 합니다.', 422);
        }
        $target = (int) $raw;

        $ownerId = Util::postInt('owner_user_id', 0) ?: 0;
        if ($ownerId > 0 && !Db::one('SELECT id FROM users WHERE id=:id AND deleted_at IS NULL', [':id' => $ownerId])) {
            Response::error('담당 직원을 찾을 수 없습니다.', 422);
        }
        $memo = trim(Util::postStr('memo'));
        if (mb_strlen($memo) > 500) {
            Response::error('메모는 500자 이내여야 합니다.', 422);
        }
        $isPublic = Util::postInt('is_public', 1) === 1 ? 1 : 0;
        $reason   = trim(Util::postStr('reason'));

        // 중복 경고 — 같은 대상·유형 + 기간 겹침 + 활성 (기획 확정: 경고 후 관리자 확인으로만 등록)
        $dupParams = [':m' => $metric, ':s' => $subject, ':f' => $range['from'], ':t' => $range['to'], ':me' => $id];
        $subjCond = "g.subject_type = :s";
        if ($subject === 'user')       { $subjCond .= ' AND g.user_id = :du';       $dupParams[':du'] = $userId; }
        if ($subject === 'department') { $subjCond .= ' AND g.department_id = :dd'; $dupParams[':dd'] = $deptId; }
        $dups = Db::all(
            "SELECT g.id, g.name, g.start_date, g.end_date FROM goals g
             WHERE g.deleted_at IS NULL AND g.status = 'active' AND g.id <> :me
               AND g.metric = :m AND $subjCond
               AND g.start_date <= :t AND g.end_date >= :f",
            $dupParams
        );
        if ($dups && Util::postInt('confirm_dup', 0) !== 1) {
            $names = implode(', ', array_map(
                static fn ($d) => "{$d['name']}({$d['start_date']}~{$d['end_date']})", array_slice($dups, 0, 3)
            ));
            Response::json(['dup_warning' => true,
                'message' => "같은 대상·유형의 기간이 겹치는 활성 목표가 " . count($dups) . "건 있습니다: $names"]);
        }

        $data = [
            'name'          => $name,
            'metric'        => $metric,
            'subject_type'  => $subject,
            'user_id'       => $userId > 0 ? $userId : null,
            'department_id' => $deptId > 0 ? $deptId : null,
            'period_type'   => $periodType,
            'year'          => $year,
            'period_no'     => $no,
            'start_date'    => $range['from'],
            'end_date'      => $range['to'],
            'target_value'  => $target,
            'owner_user_id' => $ownerId > 0 ? $ownerId : null,
            'memo'          => $memo !== '' ? $memo : null,
            'is_public'     => $isPublic,
        ];

        if ($id > 0) {
            Db::update('goals', $data, 'id = :id', [':id' => $id]);
            $action = 'update';
        } else {
            $data['status'] = 'active';
            $data['created_by'] = Auth::id();
            $id = Db::insert('goals', $data);
            $action = 'create';
        }
        $this->logGoalChange($id, $action, $before, $reason);
        Response::json(['id' => $id]);
    }

    /** 종료(ended)/중단(cancelled) 처리 — 중단은 사유 필수. 과거 목표는 물리 삭제하지 않는다. */
    public function goalEnd(): void
    {
        $id  = Util::postInt('id', 0) ?: 0;
        $row = Db::one('SELECT * FROM goals WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
        if (!$row) {
            Response::error('목표를 찾을 수 없습니다.', 404);
        }
        $to = Util::postStr('to_status');
        if (!in_array($to, ['ended', 'cancelled', 'active'], true)) {
            Response::error('상태 값이 올바르지 않습니다.', 422);
        }
        $reason = trim(Util::postStr('reason'));
        if ($to === 'cancelled' && $reason === '') {
            Response::error('중단 처리에는 사유가 필요합니다.', 422);
        }
        Db::update('goals', ['status' => $to, 'status_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null],
            'id = :id', [':id' => $id]);
        $this->logGoalChange($id, $to === 'cancelled' ? 'cancel' : ($to === 'active' ? 'update' : 'end'), $row, $reason);
        Response::json(['id' => $id]);
    }

    /** 소프트 삭제(원장 원칙 — 물리 DELETE 금지, 이력 보존). */
    public function goalDelete(): void
    {
        $id  = Util::postInt('id', 0) ?: 0;
        $row = Db::one('SELECT * FROM goals WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
        if (!$row) {
            Response::error('목표를 찾을 수 없습니다.', 404);
        }
        $reason = trim(Util::postStr('reason'));
        Db::update('goals', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
        $this->logGoalChange($id, 'delete', $row, $reason, false);
        Response::json(['id' => $id]);
    }

    // ── 목표 원장 조회 API (스코프: 컨트롤러 강제) ──

    /** 변경 이력(JSON) — goal_history 원장. */
    public function goalHistory(): void
    {
        $g = $this->visibleGoalOr404(Util::int('id', 0) ?: 0);
        $rows = Db::all(
            "SELECT h.action, h.before_json, h.after_json, h.reason, h.changed_at, cu.name AS changed_by_name
             FROM goal_history h LEFT JOIN users cu ON cu.id = h.changed_by
             WHERE h.goal_id = :id ORDER BY h.changed_at DESC, h.id DESC LIMIT 100",
            [':id' => (int) $g['id']]
        );
        Response::json(['rows' => $rows]);
    }

    /** 월별 실적 추이(JSON) — 상세 모달. */
    public function goalProgress(): void
    {
        $g = $this->visibleGoalOr404(Util::int('id', 0) ?: 0);
        Response::json([
            'trend'    => GoalService::monthlyTrend($g),
            'progress' => GoalService::progress($g),
            'unit'     => in_array($g['metric'], GoalService::COUNT_METRICS, true) ? '건' : '원',
            'target'   => (int) $g['target_value'],
        ]);
    }

    // ── 내부 헬퍼 ──

    /** 조회 스코프 SQL(별칭 g 고정) — settings.manage 없으면 공개된 본인 관련 목표만. */
    private function goalScopeWhere(bool $canManage, array &$params): string
    {
        if ($canManage) {
            return '1=1';
        }
        $me = Auth::user();
        $params[':su'] = (int) $me['id'];
        $sql = "(g.is_public = 1 AND (g.subject_type = 'company' OR (g.subject_type = 'user' AND g.user_id = :su)";
        $dept = (int) ($me['department_id'] ?? 0);
        if ($dept > 0) {
            $sql .= " OR (g.subject_type = 'department' AND g.department_id = :sd)";
            $params[':sd'] = $dept;
        }
        $sql .= '))';
        return $sql;
    }

    /** 단건 스코프 검증 — 열람 불가면 404(존재 여부 은닉). */
    private function visibleGoalOr404(int $id): array
    {
        $g = Db::one('SELECT * FROM goals WHERE id = :id AND deleted_at IS NULL', [':id' => $id]);
        if (!$g) {
            Response::error('목표를 찾을 수 없습니다.', 404);
        }
        if (!Rbac::can('settings.manage')) {
            $me = Auth::user();
            $ok = (int) $g['is_public'] === 1 && (
                $g['subject_type'] === 'company'
                || ($g['subject_type'] === 'user' && (int) $g['user_id'] === (int) $me['id'])
                || ($g['subject_type'] === 'department'
                    && (int) $g['department_id'] === (int) ($me['department_id'] ?? 0))
            );
            if (!$ok) {
                Response::error('목표를 찾을 수 없습니다.', 404);
            }
        }
        return $g;
    }

    /** goal_history 적재 + Audit — after 는 저장 후 재조회(소프트삭제 시 미조회). */
    private function logGoalChange(int $id, string $action, ?array $before, string $reason, bool $fetchAfter = true): void
    {
        $after = $fetchAfter ? Db::one('SELECT * FROM goals WHERE id = :id', [':id' => $id]) : null;
        Db::insert('goal_history', [
            'goal_id'     => $id,
            'action'      => $action,
            'before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after_json'  => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'reason'      => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'changed_by'  => Auth::id(),
        ]);
        Audit::log('goal.' . $action, 'goals', $id, $before, $after);
    }

    private function pf(string $key): float
    {
        return $this->num($_POST[$key] ?? 0);
    }
    private function num($v): float
    {
        return (float) str_replace([',', ' '], '', (string) $v);
    }
}
