# CRM R14 Implementation Plan — 공정 게이지 보드·카드 메모·예외 계약총액 연동·반기 개편

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 공정 보드를 드래그 칸반에서 '카드내 공정별 게이지' 구조로 개편(상태 완전 자동·즉시 반영·일자별 메모), 예외 프로젝트 정산 기준을 계약총액으로 단일화, 반기 실적을 계약/공사 탭으로 재구성한다.

**Architecture:** 순수 PHP MVC(오토로더 없음, app/bootstrap.php 명시 로드). 게이지 저장·파생은 `ProcessService` 단일 진입점(`setStageProgress`)이 담당 — `process_stage_id`/이력/상태는 기존 서비스(`moveStage`/`applyProjectStatus`) 경유로만 갱신해 R13 동기화·보너스 훅과 정합 유지. 보드 화면은 상태 그룹 4개 + 카드내 게이지로 재구성하고 AJAX 응답으로 카드 전체(배지 텍스트 포함)를 즉시 갱신한다.

**Tech Stack:** PHP 8.x, MySQL/MariaDB(로컬 .devdb 소켓, 운영 <DB_ACCOUNT> 외부 3306 · prefix `edencrm_`), 경량 테스트 러너(scripts/tests/lib.php), vanilla JS(EDEN.modal/api/toast).

## Global Constraints

- `process_stage_id` 변경은 **반드시 `ProcessService` 경유**(직접 UPDATE 금지, 이력 기록). 상태 변경은 `StatusService::applyProjectStatus` 경유(보너스·정산 훅 정합).
- 게이지 규칙(사장 확정): **게이지>0 → 자동 '진행 중'** / **전 공정 100% → 클라 확인 후 '완료'**(서버 재검증) / **completed·settled에서 게이지<100 수정 → 자동 재개(in_progress)** / **하자보수 = 카드 버튼**(warranty 상태에서는 보드 위치 warranty_repair 유지, 게이지만 기록).
- `projects.progress` = 해당 유형 활성 실공정 pct **평균**(기존 위치 기반 폐지).
- 예외 프로젝트 정산 기준 총액 = `contract_amount`(>0) 우선, 레거시 fallback `expected_amount` — 기존 테스트(unit_r11/r13, contract_amount=0 시드)가 fallback으로 계속 통과해야 한다.
- 라벨 정확 문구: 산정 대상 매출→**총매출**, 기여도 적용 매출/적용 매출→**기여도 반영 매출**, 적용 순이익→**기여도 반영 순이익**. 반기 탭명: **계약 실적**/**공사 실적**.
- 모든 신규 화면 PC(≥1440)/모바일(390) 반응형 — 보드 그리드 ≤900px 1컬럼.
- 신규 코어 없음(기존 클래스 확장) — bootstrap 로드 목록 변경 불필요.
- 테스트는 `Db::pdo()->beginTransaction()` … `rollBack()` 잔재 0. 운영 배포는 로컬 QA 통과 후(사장 사전 승인됨 — QC 통과 시 진행).
- 커밋 트레일러: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`

---

## 파일 구조

- **생성:** `database/migrations/2026-07-28_r14_gauge_memo.sql`, `database/cafe24/012_r14_gauge_memo.sql`(prefix판), `deploy/backfill_r14.php`(로컬/운영 겸용 PHP 백필, --dry), `scripts/tests/unit_r14.php`
- **수정(코어):** `app/core/ProcessService.php`(게이지 저장·파생), `app/core/AccountingService.php`(예외 총액·salesPaidByUser), `app/core/StatusService.php`(recalc SELECT), `app/core/Nav.php`(직원 성과 메뉴 제거)
- **수정(컨트롤러):** `app/controllers/ProcessController.php`(board 데이터·progressSet·completeConfirm·warrantySet·memo 3종·move 제거), `app/controllers/ProjectsController.php`(expectedExpr·expected 저장부 제거), `app/controllers/SettlementController.php`(expectedSave 제거), `app/controllers/BonusController.php`(overview 확장), `app/routes.php`
- **수정(뷰/JS/CSS):** `app/views/process/board.php`(전면 재구성), `public/assets/js/process-board.js`(전면 재작성), `public/assets/css/app.css`(게이지·그룹), `app/views/projects/_tab_settlement.php`, `app/views/projects/form.php`, `app/views/halfyear/index.php`, `app/views/bonus/index.php`, `app/views/bonus/history.php`, `app/views/projects/_tab_staff.php`
- **수정(스키마/QA):** `database/schema.sql`(신규 테이블 2), `scripts/tests/run.php`, `scripts/qa_smoke.sh`(process.move → process.progress.set)

---

## Phase 1 — DB·코어 (게이지 기반)

### Task 1: 마이그레이션 — project_stage_progress·project_memos + 백필 스크립트

**Files:**
- Create: `database/migrations/2026-07-28_r14_gauge_memo.sql`
- Create: `database/cafe24/012_r14_gauge_memo.sql`
- Create: `deploy/backfill_r14.php`
- Modify: `database/schema.sql` (테이블 2개 추가 — work_logs CREATE 앞에)

**Interfaces:**
- Produces: 테이블 `project_stage_progress(project_id,stage_id,pct,updated_by,updated_at)` PK(project_id,stage_id); `project_memos(id,project_id,memo_date,content,created_by,created_at,updated_at)`.
- Produces: `php deploy/backfill_r14.php [--prod] [--dry]` — 게이지 백필(현 보드 상태 보존) + 예외 계약총액 백필.

- [ ] **Step 1: 로컬 마이그레이션 SQL 작성** — `database/migrations/2026-07-28_r14_gauge_memo.sql`

```sql
-- R14 (2026-07-28): 공정 게이지 보드 + 카드 일자별 메모.
-- project_stage_progress: 카드내 공정별 진행률(0~100). 실공정만(공통 예약 제외 — 앱 레벨 검증).
-- project_memos: 보드 카드 일자별 작업 메모(경량 — work_logs와 별개, 항상 ON).
CREATE TABLE IF NOT EXISTS `project_stage_progress` (
  `project_id` INT UNSIGNED NOT NULL,
  `stage_id`   INT UNSIGNED NOT NULL COMMENT '실공정(process_stages) — waiting/warranty_repair/full_complete 제외(앱 검증)',
  `pct`        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '진행률 0~100',
  `updated_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`project_id`, `stage_id`),
  KEY `idx_psp_stage` (`stage_id`),
  CONSTRAINT `fk_psp_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_psp_stage` FOREIGN KEY (`stage_id`) REFERENCES `process_stages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_psp_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_memos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `memo_date`  DATE NOT NULL COMMENT '작업 일자',
  `content`    VARCHAR(1000) NOT NULL COMMENT '작업 내용 메모',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pmemo_project_date` (`project_id`, `memo_date`),
  CONSTRAINT `fk_pmemo_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pmemo_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: cafe24판 작성** — `database/cafe24/012_r14_gauge_memo.sql`: 위와 동일하되 모든 테이블명에 `edencrm_` prefix (`edencrm_project_stage_progress`, `edencrm_project_memos`, FK 참조도 `edencrm_projects`/`edencrm_process_stages`/`edencrm_users`). 헤더 주석에 "R14 (2026-07-28)" 명시. 제약: DROP/TRUNCATE/CREATE DATABASE 금지(run_migration 게이트).

- [ ] **Step 3: schema.sql 갱신** — `database/schema.sql`의 `work_logs` CREATE 문 **앞**에 Step 1과 동일한 두 CREATE 블록 삽입(신규 설치 정합).

- [ ] **Step 4: 백필 스크립트 작성** — `deploy/backfill_r14.php`

```php
<?php
/**
 * R14 백필 — 로컬/운영 겸용(PDO 직결). 기본 dry-run 아님에 주의: --dry 로 미리보기.
 *  (1) 게이지 백필: 현 process_stage_id 위치 P 기준 — 위치<P pct=100, 위치=P pct=50,
 *      completed/settled/전체완료 카드는 전부 100, 대기중·미배치는 0(행 미생성).
 *  (2) 예외 계약총액 백필: is_exception=1 & contract_amount=0 & expected_amount>0
 *      → contract_amount=expected_amount + supply/vat 분해(VAT 10%).
 * 사용: php deploy/backfill_r14.php [--prod] [--dry]
 */
$prod = in_array('--prod', $argv, true);
$dry  = in_array('--dry', $argv, true);
if ($prod) {
    $env = [];
    foreach (file(__DIR__ . '/cafe24.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim($v);
    }
    $P = $env['TBL_PREFIX'] ?? 'edencrm_';
    $pdo = new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4",
        $env['DB_USER'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} else {
    $P = '';
    $pdo = new PDO('mysql:unix_socket=' . __DIR__ . '/../.devdb/mysql.sock;dbname=eden_crm;charset=utf8mb4',
        'eden_crm_user', 'EdenCrm!local2026', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
function q(PDO $pdo, string $sql, array $p = []): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }

// ── (1) 게이지 백필 ──
$stages = q($pdo, "SELECT id, process_type, sort_order, stage_key FROM {$P}process_stages
                   WHERE is_active = 1 AND process_type IN ('painting','interior') ORDER BY process_type, sort_order, id");
$byType = []; // type => [stage_id...] 위치순
foreach ($stages as $s) { $byType[$s['process_type']][] = (int) $s['id']; }
$projects = q($pdo, "SELECT p.id, p.status, p.construction_type, p.process_stage_id, ps.stage_key AS cur_key, ps.process_type AS cur_type
                     FROM {$P}projects p LEFT JOIN {$P}process_stages ps ON ps.id = p.process_stage_id
                     WHERE p.deleted_at IS NULL");
$ins = 0;
foreach ($projects as $pr) {
    $type = in_array($pr['construction_type'], ['painting', 'interior'], true) ? $pr['construction_type'] : 'painting';
    $ids = $byType[$type] ?? [];
    if (!$ids) continue;
    $rows = []; // stage_id => pct
    $doneAll = in_array($pr['status'], ['completed', 'settled'], true) || ($pr['cur_key'] ?? '') === 'full_complete';
    if ($doneAll) {
        foreach ($ids as $sid) { $rows[$sid] = 100; }
    } elseif ($pr['process_stage_id'] !== null && in_array((int) $pr['process_stage_id'], $ids, true)) {
        $pos = array_search((int) $pr['process_stage_id'], $ids, true); // 0-base
        foreach ($ids as $i => $sid) { $rows[$sid] = $i < $pos ? 100 : ($i === $pos ? 50 : 0); }
    } else {
        continue; // 대기중·하자보수·미배치 — 게이지 0(행 미생성)
    }
    foreach ($rows as $sid => $pct) {
        if ($pct === 0) continue;
        $ins++;
        if (!$dry) {
            $pdo->prepare("INSERT INTO {$P}project_stage_progress (project_id, stage_id, pct)
                           VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pct = VALUES(pct)")
                ->execute([(int) $pr['id'], $sid, $pct]);
        }
    }
}
echo "게이지 백필: " . count($projects) . "개 프로젝트 검사, pct행 {$ins}건" . ($dry ? " (dry)" : " 적용") . "\n";

// ── (2) 예외 계약총액 백필 ──
$targets = q($pdo, "SELECT id, expected_amount FROM {$P}projects
                    WHERE deleted_at IS NULL AND is_exception = 1 AND contract_id IS NULL
                      AND COALESCE(contract_amount,0) = 0 AND COALESCE(expected_amount,0) > 0");
foreach ($targets as $t) {
    $amt = (int) $t['expected_amount'];
    $supply = (int) round($amt / 1.1);   // VAT 10% — AccountingService::computeSplit 와 동일 산식
    $vat = $amt - $supply;
    echo "  예외 #{$t['id']}: contract_amount 0 → " . number_format($amt) . " (공급 " . number_format($supply) . ")\n";
    if (!$dry) {
        $pdo->prepare("UPDATE {$P}projects SET contract_amount = ?, supply_amount = ?, vat_amount = ? WHERE id = ?")
            ->execute([$amt, $supply, $vat, (int) $t['id']]);
    }
}
echo "계약총액 백필: " . count($targets) . "건" . ($dry ? " (dry)" : " 적용") . "\n";
```

- [ ] **Step 5: 로컬 적용 + 검증**

Run: `MYSQL=/opt/homebrew/bin/mysql; $MYSQL --socket=.devdb/mysql.sock -ueden_crm_user -p'EdenCrm!local2026' eden_crm < database/migrations/2026-07-28_r14_gauge_memo.sql && php deploy/backfill_r14.php --dry && php deploy/backfill_r14.php`
Expected: 테이블 2개 생성, dry 출력 후 적용 출력. `SHOW TABLES LIKE 'project_%'`에 두 테이블. (DB 미기동 시 `bash scripts/start_dev.sh` 선행)

- [ ] **Step 6: 커밋**

```bash
git add database/migrations/2026-07-28_r14_gauge_memo.sql database/cafe24/012_r14_gauge_memo.sql database/schema.sql deploy/backfill_r14.php
git commit -m "feat(r14): 게이지·메모 테이블(012)+로컬/운영 백필 스크립트(현 보드 상태 보존·예외 계약총액)"
```

---

### Task 2: ProcessService 게이지 저장·파생 (TDD)

**Files:**
- Modify: `app/core/ProcessService.php` (메서드 3개 추가)
- Create: `scripts/tests/unit_r14.php`
- Modify: `scripts/tests/run.php` (`'unit_r13'];` → `'unit_r13', 'unit_r14'];`)

**Interfaces:**
- Produces: `ProcessService::gaugeStages(string $type): array` — [{id,stage_key,name,sort_order,color}] 위치순(활성·해당 유형만, 공통 자동 제외).
- Produces: `ProcessService::setStageProgress(int $projectId, int $stageId, int $pct, ?int $userId): array` — 반환 `{pct:int, progress:int, status:string, current_stage_id:int, all_done:bool}`. RuntimeException: 프로젝트 없음/취소·파기/유형 불일치 공정.
- Consumes: `StatusService::applyProjectStatus`, `ProcessService::moveStage/waitingStageId` (기존).

- [ ] **Step 1: 실패 테스트 작성** — `scripts/tests/unit_r14.php`

```php
<?php
/** R14 — 게이지 파생·자동 상태·계약총액 연동·메모·반기 집계 회귀 (트랜잭션 롤백). */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/StatusService.php';
require_once APP_PATH . '/core/ProcessService.php';
require_once APP_PATH . '/core/BonusService.php';
require_once APP_PATH . '/core/Audit.php';
require_once APP_PATH . '/core/Auth.php';
require_once APP_PATH . '/core/Stages.php';

echo "R14 회귀 (트랜잭션 롤백)\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
try {
    // ── Task 2: 게이지 파생 ──
    $stages = ProcessService::gaugeStages('painting');
    $n = count($stages);
    t_true('도장 실공정 목록(공통 제외) 존재', $n >= 10);
    $first = (int) $stages[0]['id']; $second = (int) $stages[1]['id'];

    $gp = Db::insert('projects', ['project_no' => 'R14-G1', 'name' => 'R14게이지', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => '게이지고객', 'contract_amount' => 0,
        'construction_type' => 'painting', 'status' => 'preparing',
        'process_stage_id' => ProcessService::waitingStageId()]);

    // 게이지 시작 → 자동 진행 중 + 현재 공정 파생
    $r = ProcessService::setStageProgress($gp, $first, 30, null);
    t_true('게이지>0 → 자동 진행 중', $r['status'] === 'in_progress');
    t_int('현재 공정 = 시작 공정', $first, $r['current_stage_id']);
    t_int('전체 진행률 = round(30/N)', (int) round(30 / $n), $r['progress']);
    t_true('아직 all_done 아님', $r['all_done'] === false);

    // 뒤 공정 시작 → 현재 공정 전진(pct>0 최후방)
    $r = ProcessService::setStageProgress($gp, $second, 20, null);
    t_int('현재 공정 = 더 뒤 공정', $second, $r['current_stage_id']);
    $row = Db::one("SELECT process_stage_id, progress, status FROM projects WHERE id=:id", [':id' => $gp]);
    t_int('projects.process_stage_id 동기', $second, (int) $row['process_stage_id']);

    // 전부 100 → all_done (상태는 클라 확인 후 별도 완료 — 여기선 파생 플래그만)
    foreach ($stages as $st) { $r = ProcessService::setStageProgress($gp, (int) $st['id'], 100, null); }
    t_true('전 공정 100 → all_done', $r['all_done'] === true);
    t_int('전체 진행률 100', 100, $r['progress']);

    // 완료 확정(컨트롤러 흐름 재현) → R13 T6이 전체완료 이동
    $prow = Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $gp]);
    StatusService::applyProjectStatus($prow, 'completed', ['reason' => 'R14 게이지 완료 확인']);
    $row = Db::one("SELECT status, process_stage_id FROM projects WHERE id=:id", [':id' => $gp]);
    t_true('완료 상태', $row['status'] === 'completed');
    t_int('보드 전체완료 이동', (int) ProcessService::stageIdByKey('full_complete'), (int) $row['process_stage_id']);

    // completed에서 게이지 낮춤 → 자동 재개 + 현재 공정 복귀
    $r = ProcessService::setStageProgress($gp, $second, 60, null);
    t_true('게이지 재수정 → 자동 재개(in_progress)', $r['status'] === 'in_progress');
    t_true('all_done 해제', $r['all_done'] === false);
    $row = Db::one("SELECT process_stage_id FROM projects WHERE id=:id", [':id' => $gp]);
    t_true('보드 위치 실공정 복귀(전체완료 아님)',
        (int) $row['process_stage_id'] !== (int) ProcessService::stageIdByKey('full_complete'));

    // 취소 프로젝트 거부
    $cx = Db::insert('projects', ['project_no' => 'R14-G2', 'name' => 'R14취소', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 0,
        'construction_type' => 'painting', 'status' => 'cancelled']);
    $threw = false;
    try { ProcessService::setStageProgress($cx, $first, 10, null); } catch (RuntimeException $e) { $threw = true; }
    t_true('취소 프로젝트 게이지 거부', $threw);

    $pdo->rollBack();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "  [FAIL] 예외: " . $e->getMessage() . "\n";
    $GLOBALS['__T']['fail']++;
}
exit(t_summary());
```

- [ ] **Step 2: 실패 확인** — Run: `php scripts/tests/unit_r14.php` / Expected: FAIL(`gaugeStages` 미정의).

- [ ] **Step 3: ProcessService 구현** — `app/core/ProcessService.php`의 `ensureStageMatchesType()` 아래에 추가

```php
    /** R14: 게이지 대상 실공정(유형·활성, 공통 예약 자동 제외 — common 은 process_type 불일치) 위치순. */
    public static function gaugeStages(string $constructionType): array
    {
        return Db::all(
            "SELECT id, stage_key, name, sort_order, color FROM process_stages
             WHERE process_type = :t AND is_active = 1
             ORDER BY sort_order, id",
            [':t' => $constructionType]
        );
    }

    /**
     * R14: 공정 게이지 저장 + 파생 — 보드 게이지의 단일 진입점.
     * 파생: progress=pct 평균, 현재 공정=pct>0 최후방(없으면 대기중), 상태 자동 전환
     *  (preparing/paused+시작→in_progress, completed/settled+미완→재개). 전부 100 은 all_done
     *  플래그만 반환(완료 확정은 클라 확인 후 별도 호출 — 서버 재검증).
     * warranty 상태는 보드 위치(warranty_repair) 유지 — 게이지만 기록.
     */
    public static function setStageProgress(int $projectId, int $stageId, int $pct, ?int $userId): array
    {
        $pct = max(0, min(100, $pct));
        $project = Db::one("SELECT * FROM projects WHERE id = :id AND deleted_at IS NULL", [':id' => $projectId]);
        if (!$project) {
            throw new RuntimeException('프로젝트를 찾을 수 없습니다.');
        }
        if (in_array($project['status'], ['cancelled', 'terminated'], true)) {
            throw new RuntimeException('취소·파기 프로젝트는 공정 게이지를 수정할 수 없습니다.');
        }
        $type = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        $stages = self::gaugeStages($type);
        $ids = array_map(static fn($s) => (int) $s['id'], $stages);
        if (!in_array($stageId, $ids, true)) {
            throw new RuntimeException('이 프로젝트 유형의 공정이 아닙니다.');
        }
        Db::run("INSERT INTO project_stage_progress (project_id, stage_id, pct, updated_by)
                 VALUES (:p, :s, :v, :u)
                 ON DUPLICATE KEY UPDATE pct = VALUES(pct), updated_by = VALUES(updated_by)",
            [':p' => $projectId, ':s' => $stageId, ':v' => $pct, ':u' => $userId]);

        // ── 파생 ──
        $pctMap = [];
        foreach (Db::all("SELECT stage_id, pct FROM project_stage_progress WHERE project_id = :p", [':p' => $projectId]) as $r) {
            $pctMap[(int) $r['stage_id']] = (int) $r['pct'];
        }
        $sum = 0; $currentStageId = null; $allDone = count($ids) > 0;
        foreach ($ids as $sid) {
            $v = $pctMap[$sid] ?? 0;
            $sum += $v;
            if ($v > 0) { $currentStageId = $sid; }   // 위치순 순회 — pct>0 최후방
            if ($v < 100) { $allDone = false; }
        }
        $progress = count($ids) ? (int) round($sum / count($ids)) : 0;
        Db::update('projects', ['progress' => $progress], 'id = :id', [':id' => $projectId]);

        $status = (string) $project['status'];
        if (!$allDone && in_array($status, ['completed', 'settled'], true)) {
            StatusService::applyProjectStatus($project, 'in_progress', ['reason' => '공정 게이지 수정 재개(종결 해제)']);
            $status = 'in_progress';
        } elseif ($currentStageId !== null && in_array($status, ['preparing', 'paused'], true)) {
            StatusService::applyProjectStatus($project, 'in_progress', ['reason' => '공정 게이지 시작 자동 전환']);
            $status = 'in_progress';
        }
        // 보드 위치 동기 — 종결(전체완료 유지)·하자보수(warranty_repair 유지) 제외
        $targetStage = $currentStageId ?? self::waitingStageId();
        if (!in_array($status, ['completed', 'settled', 'warranty'], true)) {
            self::moveStage($projectId, $targetStage, $userId, '공정 게이지 파생 이동', true);
        }
        $cur = (int) Db::val("SELECT process_stage_id FROM projects WHERE id = :id", [':id' => $projectId]);
        return ['pct' => $pct, 'progress' => $progress, 'status' => $status,
            'current_stage_id' => $cur, 'all_done' => $allDone];
    }
```

- [ ] **Step 4: 통과 확인** — Run: `php scripts/tests/unit_r14.php` / Expected: PASS 전체.
- [ ] **Step 5: 스위트 등록 + 전체 회귀** — run.php 수정 후 `php scripts/tests/run.php` / Expected: ✅ 전체 통과.
- [ ] **Step 6: 커밋**

```bash
git add app/core/ProcessService.php scripts/tests/unit_r14.php scripts/tests/run.php
git commit -m "feat(r14): ProcessService.setStageProgress — 게이지 저장·파생(진행률 평균·현재공정 최후방·자동 상태) TDD"
```

---

### Task 3: ProcessController — 게이지·완료확정·하자보수·메모 API + 드래그 제거

**Files:**
- Modify: `app/controllers/ProcessController.php` — `move()` 메서드 **삭제**, 신규 5개 메서드 추가. `board()`는 Task 4에서 수정.
- Modify: `app/routes.php` — `'process.move'` 라우트 삭제, 신규 라우트 추가.
- Modify: `scripts/qa_smoke.sh` — `process.move` 참조를 `process.progress.set`으로 교체.

**Interfaces:**
- Produces 라우트(모두 POST): `process.progress.set`(perm process.move), `process.complete.confirm`(perm process.move), `process.warranty.set`(perm process.move), `process.memo.save`(perm process.move), `process.memo.delete`(perm process.move); GET: `process.memo.list`(perm 없음 — 로그인만, 보드 열람자).
- progress.set 응답 JSON: `{pct, progress, status, status_label, badge_class, group, current_stage_id, current_stage_name, current_stage_color, all_done, summary}`.
- memo.list 응답: `{memos: [{id, memo_date, content, user_name, created_at}], count}` (최근 50).

- [ ] **Step 1: routes.php 수정** — `'process.move' => [...]` 행 삭제, 그 자리에:

```php
    'process.progress.set'    => ['ProcessController', 'progressSet', 'perm' => 'process.move', 'method' => 'POST'],
    'process.complete.confirm'=> ['ProcessController', 'completeConfirm', 'perm' => 'process.move', 'method' => 'POST'],
    'process.warranty.set'    => ['ProcessController', 'warrantySet', 'perm' => 'process.move', 'method' => 'POST'],
    'process.memo.list'       => ['ProcessController', 'memoList'],
    'process.memo.save'       => ['ProcessController', 'memoSave', 'perm' => 'process.move', 'method' => 'POST'],
    'process.memo.delete'     => ['ProcessController', 'memoDelete', 'perm' => 'process.move', 'method' => 'POST'],
```

- [ ] **Step 2: ProcessController 신규 메서드** — 기존 `move()`(154~270행 부근) 전체를 삭제하고 아래로 대체. 상태 그룹 매핑 헬퍼 포함.

```php
    /** R14: 상태 → 보드 상태그룹 키(waiting/active/warranty/done). */
    public static function statusGroup(string $status): string
    {
        if ($status === 'preparing') return 'waiting';
        if ($status === 'warranty') return 'warranty';
        if (in_array($status, ['completed', 'settled'], true)) return 'done';
        return 'active'; // in_progress·paused
    }

    /** 접근 가드 — 보드 대상(스코프·삭제 제외) 프로젝트 로드, 실패 시 JSON 에러. */
    private function guardBoardProject(int $projectId): array
    {
        [$scopeSql, $params] = Scope::projectWhere('p');
        $project = Db::one("SELECT p.* FROM projects p WHERE p.id = :id AND p.deleted_at IS NULL AND $scopeSql",
            array_merge([':id' => $projectId], $params));
        if (!$project) {
            Response::error('프로젝트를 찾을 수 없거나 접근 권한이 없습니다.', 404);
        }
        return $project;
    }

    /** R14: 카드 게이지 저장 — 파생 결과(배지·현재공정 라벨 포함)를 즉시 반영용으로 반환. */
    public function progressSet(): void
    {
        $project = $this->guardBoardProject((int) Util::postInt('project_id', 0));
        try {
            $r = ProcessService::setStageProgress((int) $project['id'], (int) Util::postInt('stage_id', 0),
                (int) Util::postInt('pct', 0), Auth::id());
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
        $cur = Db::one("SELECT id, name, color FROM process_stages WHERE id = :id", [':id' => $r['current_stage_id']]);
        $type = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        Response::json([
            'pct' => $r['pct'], 'progress' => $r['progress'], 'status' => $r['status'],
            'status_label' => StatusService::PROJECT_LABELS[$r['status']] ?? $r['status'],
            'badge_class' => StatusService::PROJECT_BADGE[$r['status']] ?? 'badge',
            'group' => self::statusGroup($r['status']),
            'current_stage_id' => $r['current_stage_id'],
            'current_stage_name' => $cur['name'] ?? '대기중',
            'current_stage_color' => $cur['color'] ?? '#64748b',
            'all_done' => $r['all_done'],
            'summary' => $this->boardSummaryFor($type),
        ]);
    }

    /** R14: 전 공정 100% 확인 후 완료 확정 — 서버 재검증. */
    public function completeConfirm(): void
    {
        $project = $this->guardBoardProject((int) Util::postInt('project_id', 0));
        $type = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        $stages = ProcessService::gaugeStages($type);
        foreach ($stages as $st) {
            $v = (int) (Db::val("SELECT pct FROM project_stage_progress WHERE project_id = :p AND stage_id = :s",
                [':p' => (int) $project['id'], ':s' => (int) $st['id']]) ?? 0);
            if ($v < 100) {
                Response::error('아직 100%가 아닌 공정이 있어 완료 처리할 수 없습니다: ' . $st['name'], 422);
            }
        }
        if (!in_array($project['status'], ['completed', 'settled'], true)) {
            Db::transaction(function () use ($project) {
                StatusService::applyProjectStatus($project, 'completed', ['reason' => '공정 게이지 전체 100% 완료 확인']);
            });
        }
        Response::json(['status' => 'completed', 'status_label' => StatusService::PROJECT_LABELS['completed'],
            'badge_class' => StatusService::PROJECT_BADGE['completed'], 'group' => 'done',
            'summary' => $this->boardSummaryFor($type)]);
    }

    /** R14: 하자보수 전환/해제(해제=완료 복귀) — 카드 버튼. */
    public function warrantySet(): void
    {
        $project = $this->guardBoardProject((int) Util::postInt('project_id', 0));
        $on = Util::postStr('action', 'set') !== 'clear';
        $type = Stages::normalizeConstructionType($project['construction_type'] ?? null);
        Db::transaction(function () use ($project, $on) {
            if ($on && $project['status'] !== 'warranty') {
                StatusService::applyProjectStatus($project, 'warranty', ['reason' => '보드 하자보수 전환(버튼)']);
                $wr = ProcessService::stageIdByKey('warranty_repair');
                if ($wr !== null) {
                    ProcessService::moveStage((int) $project['id'], $wr, Auth::id(), '하자보수 전환 보드 이동', true);
                }
            } elseif (!$on && $project['status'] === 'warranty') {
                StatusService::applyProjectStatus($project, 'completed', ['reason' => '하자보수 종료(완료 복귀)']);
            }
        });
        $status = $on ? 'warranty' : 'completed';
        Response::json(['status' => $status, 'status_label' => StatusService::PROJECT_LABELS[$status],
            'badge_class' => StatusService::PROJECT_BADGE[$status], 'group' => self::statusGroup($status),
            'summary' => $this->boardSummaryFor($type)]);
    }

    /** R14: 카드 메모 목록(일자 내림차순, 최근 50). */
    public function memoList(): void
    {
        $project = $this->guardBoardProject((int) Util::postInt('project_id', 0) ?: (int) ($_GET['project_id'] ?? 0));
        $rows = Db::all(
            "SELECT m.id, m.memo_date, m.content, m.created_at, u.name AS user_name
             FROM project_memos m LEFT JOIN users u ON u.id = m.created_by
             WHERE m.project_id = :p ORDER BY m.memo_date DESC, m.id DESC LIMIT 50",
            [':p' => (int) $project['id']]
        );
        Response::json(['memos' => $rows, 'count' => count($rows)]);
    }

    /** R14: 카드 메모 등록. */
    public function memoSave(): void
    {
        $project = $this->guardBoardProject((int) Util::postInt('project_id', 0));
        $date = Util::dateOrNull(Util::postStr('memo_date')) ?? date('Y-m-d');
        $content = trim(mb_substr(Util::postStr('content', ''), 0, 1000));
        if ($content === '') {
            Response::error('메모 내용을 입력하세요.', 422);
        }
        $id = Db::insert('project_memos', ['project_id' => (int) $project['id'], 'memo_date' => $date,
            'content' => $content, 'created_by' => Auth::id() ?: null]);
        Audit::log('project_memo_create', 'project_memos', $id, null, ['memo_date' => $date, 'content' => $content]);
        Response::json(['id' => $id]);
    }

    /** R14: 카드 메모 삭제(물리 — 경량 메모, 감사 로그 보존). */
    public function memoDelete(): void
    {
        $id = (int) Util::postInt('id', 0);
        $memo = Db::one("SELECT * FROM project_memos WHERE id = :id", [':id' => $id]);
        if (!$memo) {
            Response::error('메모를 찾을 수 없습니다.', 404);
        }
        $this->guardBoardProject((int) $memo['project_id']);
        Db::run("DELETE FROM project_memos WHERE id = :id", [':id' => $id]);
        Audit::log('project_memo_delete', 'project_memos', $id, $memo, null);
        Response::json(['id' => $id]);
    }
```

> 주의: `move()` 삭제 시 그 안에서만 쓰던 지역 로직(진행률 위치 계산·skip_warn)은 함께 제거된다. `boardSummaryFor()`·`board()`·`history` 관련 메서드는 유지. `StatusService::boardStatusFor`는 더 이상 호출부가 없지만 규칙 문서·테스트가 있으므로 **유지**(게이지 규칙과 의미 동일).

- [ ] **Step 3: qa_smoke.sh 교체** — `process.move` 문자열을 `process.progress.set`으로 교체(419 CSRF 검사 대상 라우트 교체).

- [ ] **Step 4: 검증**

Run: `php -l app/controllers/ProcessController.php && php -l app/routes.php && php scripts/tests/run.php`
Expected: 문법 OK + ✅ 전체 통과. `grep -n "process.move'" app/routes.php` → 라우트 없음(권한 키 'process.move'는 잔존 — 의도).

- [ ] **Step 5: 커밋**

```bash
git add app/controllers/ProcessController.php app/routes.php scripts/qa_smoke.sh
git commit -m "feat(r14): 보드 API 개편 — progressSet/completeConfirm/warrantySet/memo 3종, 드래그 move 라우트 제거"
```

---

### Task 4: 보드 화면 재구성 — 상태 그룹 + 카드내 게이지 + 메모 팝업 (반응형)

**Files:**
- Modify: `app/controllers/ProcessController.php` — `board()` 데이터 조립 변경.
- Modify: `app/views/process/board.php` — 상태 그룹·게이지 카드로 재구성.
- Modify: `public/assets/js/process-board.js` — 드래그 전면 제거, 게이지/메모/완료확인 JS.
- Modify: `public/assets/css/app.css` — 게이지·상태그룹 스타일.

**Interfaces:**
- Consumes: Task 2·3의 서비스/API. `EDEN.modal/confirm`, `api()`, `toast()` (`public/assets/js/app.js`).
- board() 가 뷰에 추가 전달: `$gaugeStages`(유형 실공정 위치순, 위치번호 포함), `$pctByProject`(pid→[stage_id→pct]), `$memoCounts`(pid→건수), `$statusGroups`(그룹키→[projects]).

- [ ] **Step 1: board() 데이터 조립** — 기존 `$broken` 보정·`$stages`·`$projects` 로드는 유지하되, 컬럼 버킷(`$byStage/$groupCols`) 대신 상태 그룹 버킷으로 교체. `$projects` 로드 후에 추가:

```php
        // R14: 카드내 게이지 데이터 — 유형 실공정 + 프로젝트별 pct + 메모 수 + 상태 그룹 버킷
        $gaugeStages = ProcessService::gaugeStages($boardType);
        $pctByProject = [];
        $memoCounts = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT project_id, stage_id, pct FROM project_stage_progress WHERE project_id IN ($in)", $ids) as $r) {
                $pctByProject[(int) $r['project_id']][(int) $r['stage_id']] = (int) $r['pct'];
            }
            foreach (Db::all("SELECT project_id, COUNT(*) c FROM project_memos WHERE project_id IN ($in) GROUP BY project_id", $ids) as $r) {
                $memoCounts[(int) $r['project_id']] = (int) $r['c'];
            }
        }
        $statusGroups = ['waiting' => [], 'active' => [], 'warranty' => [], 'done' => []];
        foreach ($projects as $p) {
            $statusGroups[self::statusGroup((string) $p['status'])][] = $p;
        }
```
뷰 전달 배열에 `'gaugeStages','pctByProject','memoCounts','statusGroups'` 추가. (기존 `$stages/$byStage/$groupCols/$s2g` 전달은 제거 — 뷰가 더 이상 안 씀. `Stages::processGroups`의 공정그룹(prep/build/finish)은 카드내 게이지 그룹핑에 재사용: `$s2g = Stages::processStageToGroup($boardType); $groupDefs = Stages::processGroups($boardType);` 는 **유지 전달**.)

- [ ] **Step 2: board.php 재구성** — 유형 탭·KPI 요약 스트립(`data-summary`)·필터는 유지. 기존 `.pb-groups`(공정 컬럼) 블록 전체를 아래 구조로 대체. 카드 상단 메타(사진·고객·주소·영업·배정·일정·이력 버튼)는 기존 마크업 재사용.

```php
<?php
$sgDefs = [
    'waiting'  => ['name' => '대기중',   'color' => '#f59e0b'],
    'active'   => ['name' => '진행 중',  'color' => '#3b82f6'],
    'warranty' => ['name' => '하자보수', 'color' => '#ef4444'],
    'done'     => ['name' => '종결',     'color' => '#0d9488'],
];
// 카드내 게이지의 공정그룹(착공준비/시공/마무리) 묶음
$gaugeByGroup = [];
foreach ($gaugeStages as $i => $st) {
    $gk = $s2g[$st['stage_key']] ?? 'build';
    $st['pos'] = $i + 1;
    $gaugeByGroup[$gk][] = $st;
}
?>
<div class="sg-board" id="processBoard">
  <?php foreach ($sgDefs as $gkey => $g): $list = $statusGroups[$gkey] ?? []; ?>
  <section class="sg-group" data-group="<?= e($gkey) ?>" style="--gc:<?= e($g['color']) ?>">
    <div class="sg-head"><span class="sg-dot"></span><b><?= e($g['name']) ?></b>
      <span class="badge badge-muted sg-count" data-group-count="<?= e($gkey) ?>"><?= count($list) ?></span></div>
    <div class="sg-cards" data-group-cards="<?= e($gkey) ?>">
      <?php foreach ($list as $p): $pid = (int) $p['id'];
        $pmap = $pctByProject[$pid] ?? [];
        $isDone = in_array($p['status'], ['completed', 'settled'], true); ?>
      <div class="gauge-card" data-project-id="<?= $pid ?>" data-status="<?= e($p['status']) ?>">
        <!-- 기존 카드 상단 메타 블록(제목·상태배지·고객·주소·영업·배정·일정) 재사용:
             상태 배지 span 에 data-status-badge 속성 추가, 제목 링크는 기존 유지 -->
        <div class="gc-top">
          <a class="pc-title" href="<?= e(url('projects.show', ['id' => $pid])) ?>"><?= e(mb_substr($p['name'], 0, 24)) ?></a>
          <span class="badge <?= e($statusBadge[$p['status']] ?? 'badge') ?>" data-status-badge><?= e($statusLabels[$p['status']] ?? $p['status']) ?></span>
        </div>
        <div class="pc-sub"><?= e($p['customer_name'] ?? '-') ?><?= (int) ($p['is_exception'] ?? 0) === 1 ? ' <span class="badge badge-warn">예외</span>' : '' ?></div>
        <div class="gc-progress">
          <div class="progress"><div class="progress-bar<?= (int) $p['progress'] >= 100 ? ' ok' : '' ?>" data-progress-bar style="width:<?= (int) $p['progress'] ?>%"></div></div>
          <span class="gc-pct" data-progress-text><?= (int) $p['progress'] ?>%</span>
          <span class="badge badge-stage" data-current-stage>현재: <?= e($p['process_stage_name'] ?? '대기중') ?></span>
        </div>
        <div class="gc-gauges">
          <?php foreach ($gaugeByGroup as $ggk => $sts): $gdef = $groupDefs[$ggk] ?? null; ?>
          <details class="gc-ggroup" <?= $ggk === 'build' ? 'open' : '' ?>>
            <summary><?= e($gdef['name'] ?? $ggk) ?> <span class="muted fs-12 gc-gsum" data-ggroup-sum="<?= e($ggk) ?>"></span></summary>
            <?php foreach ($sts as $st): $v = $pmap[(int) $st['id']] ?? 0; ?>
            <div class="gc-row" data-stage-row="<?= (int) $st['id'] ?>">
              <span class="gc-name"><?= (int) $st['pos'] ?>. <?= e($st['name']) ?></span>
              <input type="range" class="gc-slider" min="0" max="100" step="5" value="<?= $v ?>"
                     data-stage-id="<?= (int) $st['id'] ?>" <?= $isDone ? '' : '' ?>>
              <span class="gc-val" data-stage-val><?= $v ?>%</span>
            </div>
            <?php endforeach; ?>
          </details>
          <?php endforeach; ?>
        </div>
        <div class="gc-actions">
          <button type="button" class="btn btn-sm btn-outline gc-memo-btn">메모<?php $mc = $memoCounts[$pid] ?? 0; ?><?= $mc ? ' <span class="badge badge-muted">' . $mc . '</span>' : '' ?></button>
          <?php if ($p['status'] === 'warranty'): ?>
            <button type="button" class="btn btn-sm btn-outline gc-warranty-btn" data-action="clear">하자보수 종료</button>
          <?php elseif ($isDone): ?>
            <button type="button" class="btn btn-sm btn-ghost gc-warranty-btn" data-action="set">하자보수 전환</button>
          <?php endif; ?>
          <button type="button" class="btn btn-sm btn-ghost history-btn" data-project-id="<?= $pid ?>">이력</button>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$list): ?><div class="empty-mini">프로젝트 없음</div><?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
```
(주: `$statusLabels`/`$statusBadge`는 이 뷰 상단에서 이미 정의돼 있으면 재사용, 없으면 `StatusService::PROJECT_LABELS/PROJECT_BADGE`로 정의. 기존 카드의 사진/영업/배정/일정 메타 블록은 위 `.pc-sub` 아래에 기존 코드 그대로 옮겨 붙인다 — 삭제 금지.)

- [ ] **Step 3: process-board.js 재작성** — 드래그(dragstart/dragover/drop/handleDrop) 전부 제거. 유지: `updateSummary()`, history 모달, 유형 탭. 신규:

```js
  // ── R14: 게이지 저장(디바운스) + 즉시 반영 ──
  var timers = {};
  document.querySelectorAll('.gc-slider').forEach(function (sl) {
    sl.addEventListener('input', function () {
      var row = sl.closest('.gc-row');
      row.querySelector('[data-stage-val]').textContent = sl.value + '%';
      var card = sl.closest('.gauge-card');
      var key = card.dataset.projectId + ':' + sl.dataset.stageId;
      clearTimeout(timers[key]);
      timers[key] = setTimeout(function () { saveGauge(card, sl); }, 400);
    });
  });

  async function saveGauge(card, sl) {
    try {
      var data = await api('process.progress.set', {
        project_id: card.dataset.projectId, stage_id: sl.dataset.stageId, pct: sl.value,
      });
      applyCardState(card, data);
      if (data.all_done && card.dataset.status !== 'completed' && card.dataset.status !== 'settled') {
        var ok = await EDEN.confirm('모든 공정이 100%입니다. 프로젝트를 완료 처리할까요? (공정 보드 종결)', { okLabel: '완료 처리' });
        if (ok) {
          var d2 = await api('process.complete.confirm', { project_id: card.dataset.projectId });
          applyCardState(card, d2);
        }
      }
    } catch (e) { toast(e.message, 'error'); }
  }

  // 응답 → 카드 즉시 반영(배지 텍스트/클래스·진행률·현재 공정·그룹 이동·KPI)
  function applyCardState(card, d) {
    if (d.progress !== undefined) {
      var bar = card.querySelector('[data-progress-bar]');
      if (bar) { bar.style.width = d.progress + '%'; bar.classList.toggle('ok', d.progress >= 100); }
      var pt = card.querySelector('[data-progress-text]');
      if (pt) pt.textContent = d.progress + '%';
    }
    if (d.current_stage_name) {
      var cs = card.querySelector('[data-current-stage]');
      if (cs) cs.textContent = '현재: ' + d.current_stage_name;
    }
    if (d.status) {
      card.dataset.status = d.status;
      var badge = card.querySelector('[data-status-badge]');
      if (badge) { badge.textContent = d.status_label; badge.className = 'badge ' + d.badge_class; }
      var target = document.querySelector('[data-group-cards="' + d.group + '"]');
      if (target && card.parentElement !== target) {
        target.insertBefore(card, target.firstElementChild);
        document.querySelectorAll('[data-group-count]').forEach(function (el) {
          var grp = el.dataset.groupCount;
          var wrap = document.querySelector('[data-group-cards="' + grp + '"]');
          if (wrap) el.textContent = wrap.querySelectorAll('.gauge-card').length;
        });
      }
    }
    if (d.summary) updateSummary(d.summary);
  }

  // ── 하자보수 버튼 ──
  document.querySelectorAll('.gc-warranty-btn').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var card = btn.closest('.gauge-card');
      var setOn = btn.dataset.action === 'set';
      var ok = await EDEN.confirm(setOn ? '이 프로젝트를 하자보수 상태로 전환할까요?' : '하자보수를 종료하고 완료로 복귀할까요?');
      if (!ok) return;
      try {
        var d = await api('process.warranty.set', { project_id: card.dataset.projectId, action: btn.dataset.action });
        applyCardState(card, d);
        location.reload(); // 버튼 상태(전환/종료) 갱신 단순화
      } catch (e) { toast(e.message, 'error'); }
    });
  });

  // ── 메모 레이어팝업 ──
  document.querySelectorAll('.gc-memo-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { openMemo(btn.closest('.gauge-card')); });
  });
  async function openMemo(card) {
    var pid = card.dataset.projectId;
    var d = await api(EDEN.url('process.memo.list', { project_id: pid }));
    var items = (d.memos || []).map(function (m) {
      return '<div class="memo-item"><div class="memo-date">' + m.memo_date +
        ' <span class="muted fs-12">' + (m.user_name || '') + '</span>' +
        ' <button type="button" class="btn btn-sm btn-ghost memo-del" data-id="' + m.id + '">삭제</button></div>' +
        '<div class="memo-body">' + escHtml(m.content) + '</div></div>';
    }).join('') || '<div class="empty-mini">메모 없음</div>';
    var body = '<form class="form memo-form">' +
      '<div class="memo-add"><input type="date" name="memo_date" class="input" value="' + today() + '">' +
      '<textarea name="content" class="input" rows="2" maxlength="1000" placeholder="오늘 작업 내용"></textarea>' +
      '<button type="submit" class="btn btn-sm btn-primary">등록</button></div></form>' +
      '<div class="memo-list">' + items + '</div>';
    var m = EDEN.modal({ title: '작업 메모', body: body, footer: false });
    m.body.querySelector('.memo-form').addEventListener('submit', async function (ev) {
      ev.preventDefault();
      try {
        await api('process.memo.save', { project_id: pid,
          memo_date: this.memo_date.value, content: this.content.value });
        m.close(); openMemo(card); toast('메모가 등록되었습니다.', 'success');
      } catch (e) { toast(e.message, 'error'); }
    });
    m.body.querySelectorAll('.memo-del').forEach(function (db) {
      db.addEventListener('click', async function () {
        if (!(await EDEN.confirm('이 메모를 삭제할까요?', { danger: true }))) return;
        try { await api('process.memo.delete', { id: db.dataset.id }); m.close(); openMemo(card); }
        catch (e) { toast(e.message, 'error'); }
      });
    });
  }
  function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function today() { var d = new Date(); return d.toISOString().slice(0, 10); }
```
(`EDEN.url` GET 조합·`api` GET 사용법은 app.js 기존 시그니처 준수. memoList는 GET이므로 `api(EDEN.url(...))` 대신 `api('process.memo.list?project_id='+pid)`가 기존 api() 규약과 안 맞으면 POST 없는 GET 호출 방식으로 app.js 관례에 맞춘다 — 구현 시 app.js:25-59 확인.)

- [ ] **Step 4: CSS 추가** — `public/assets/css/app.css` 말미:

```css
/* R14 게이지 보드 */
.sg-board{display:flex;flex-direction:column;gap:16px}
.sg-group{border:1px solid var(--border,#e5e7eb);border-radius:12px;background:#fff;padding:12px}
.sg-head{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.sg-dot{width:10px;height:10px;border-radius:50%;background:var(--gc,#9ca3af)}
.sg-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(340px,100%),1fr));gap:12px}
.gauge-card{border:1px solid #e5e7eb;border-radius:10px;padding:12px;background:#fff}
.gc-top{display:flex;justify-content:space-between;align-items:center;gap:8px}
.gc-progress{display:flex;align-items:center;gap:8px;margin:8px 0}
.gc-progress .progress{flex:1}
.gc-ggroup summary{cursor:pointer;font-weight:600;padding:6px 0}
.gc-row{display:flex;align-items:center;gap:8px;padding:3px 0}
.gc-name{flex:0 0 40%;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gc-slider{flex:1;accent-color:#3b82f6;min-height:24px}
.gc-val{flex:0 0 44px;text-align:right;font-size:12px;font-variant-numeric:tabular-nums}
.gc-actions{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap}
.memo-item{border-top:1px solid #f1f5f9;padding:8px 0}
.memo-add{display:flex;gap:6px;flex-wrap:wrap}
.memo-add textarea{flex:1;min-width:180px}
.empty-mini{color:#94a3b8;font-size:13px;padding:16px;text-align:center;border:1px dashed #e2e8f0;border-radius:8px}
@media(max-width:900px){.sg-cards{grid-template-columns:1fr}.gc-name{flex-basis:34%}}
```

- [ ] **Step 5: 검증** — `php -l app/views/process/board.php && php -l app/controllers/ProcessController.php`, 로컬 서버에서 관리자 세션 curl로 `?r=process.board` 200·PHP오류 0, `process.progress.set` POST로 pct 저장→응답 필드(status_label·badge_class·current_stage_name) 확인, `process.memo.save/list` 왕복 확인. 전체 회귀 `php scripts/tests/run.php` ✅.

- [ ] **Step 6: 커밋**

```bash
git add app/controllers/ProcessController.php app/views/process/board.php public/assets/js/process-board.js public/assets/css/app.css
git commit -m "feat(r14): 게이지 보드 UI — 상태그룹 4개·카드내 공정 슬라이더·즉시 반영(배지 포함)·메모 팝업·반응형"
```

---

## Phase 2 — 예외 계약총액 연동

### Task 5: 정산 기준 총액 = contract_amount (fallback expected_amount)

**Files:**
- Modify: `app/core/AccountingService.php` (projectPaySummary·RECEIVABLE_EXCEPTION_COND·receivable·receivableCount)
- Modify: `app/core/StatusService.php` (recalcProjectSettlement SELECT)
- Modify: `app/controllers/ProjectsController.php` (expectedExpr·expected_amount 저장부 제거)
- Modify: `app/controllers/SettlementController.php` (expectedSave 메서드 제거)
- Modify: `app/routes.php` (`projects.expected.save` 제거)
- Modify: `app/views/projects/_tab_settlement.php` (수정 버튼·모달 제거)
- Modify: `app/views/projects/form.php` (예정 금액 필드 제거)
- Modify: `scripts/tests/unit_r14.php` (검증 추가)

**Interfaces:**
- Produces: 예외 총액 결정식(단일 출처) — PHP: `$expected = ((int)($p['contract_amount'] ?? 0)) > 0 ? (int)$p['contract_amount'] : ($p['expected_amount'] !== null ? (int)$p['expected_amount'] : 0);` / SQL 조각: `COALESCE(NULLIF(p.contract_amount, 0), p.expected_amount, 0)`.

- [ ] **Step 1: 실패 테스트 추가** — unit_r14.php rollback 앞:

```php
    // ── Task 5: 예외 계약총액 연동 ──
    $xp = Db::insert('projects', ['project_no' => 'R14-X1', 'name' => 'R14예외총액', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 33000000,
        'expected_amount' => null, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    $p = Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $xp]);
    $s = AccountingService::projectPaySummary($p);
    t_int('예외 총액 = contract_amount(expected NULL이어도)', 33000000, $s['expected']);
    t_true('expected_set = true', $s['expected_set'] === true);
    Db::insert('payments', ['project_id' => $xp, 'pay_type' => 'down', 'amount' => 33000000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    t_true('전액 입금 → 자동 전액 입금 완료', StatusService::recalcProjectSettlement($xp) === 'settled');
    // 레거시 fallback: contract_amount=0 + expected_amount만 있는 행
    $lg = Db::insert('projects', ['project_no' => 'R14-X2', 'name' => 'R14레거시', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 0,
        'expected_amount' => 5000000, 'status' => 'in_progress', 'settlement_status' => 'unsettled']);
    $p2 = Db::one("SELECT * FROM projects WHERE id=:id", [':id' => $lg]);
    t_int('레거시 fallback = expected_amount', 5000000, AccountingService::projectPaySummary($p2)['expected']);
```

- [ ] **Step 2: 실패 확인** — Run: `php scripts/tests/unit_r14.php` / Expected: 첫 단언 FAIL(expected=0).

- [ ] **Step 3: AccountingService 수정**
  - `projectPaySummary` 예외 분기(:241·:246):
```php
            $expected = ((int) ($p['contract_amount'] ?? 0)) > 0
                ? (int) $p['contract_amount']
                : ($p['expected_amount'] !== null ? (int) $p['expected_amount'] : 0); // R14: 계약총액 우선, 레거시 fallback
            ...
            $expectedSet = $expected > 0;
```
  - `RECEIVABLE_EXCEPTION_COND`: `p.expected_amount > 0` → `COALESCE(NULLIF(p.contract_amount, 0), p.expected_amount, 0) > 0`.
  - `receivable()` 예외 SUM: `GREATEST(0, p.expected_amount - ...)` → `GREATEST(0, COALESCE(NULLIF(p.contract_amount, 0), p.expected_amount, 0) - ...)`.
  - `receivableCount()` 비교: `p.expected_amount > (PAID)` → `COALESCE(NULLIF(p.contract_amount, 0), p.expected_amount, 0) > (PAID)`.

- [ ] **Step 4: StatusService** — `recalcProjectSettlement` SELECT에 `contract_amount` 추가: `SELECT id, contract_id, is_exception, contract_amount, expected_amount, settlement_status ...`.

- [ ] **Step 5: ProjectsController** — `$expectedExpr`(:37-38)를 `"CASE WHEN p.is_exception = 1 AND p.contract_id IS NULL THEN COALESCE(NULLIF(p.contract_amount, 0), p.expected_amount, 0) ELSE p.contract_amount END"`로 교체. 저장부: expected_amount POST 파싱·저장(:657-662, :693-695)·변경 감사(:720-726) 블록 제거.

- [ ] **Step 6: 라우트·컨트롤러·뷰 제거**
  - routes.php `'projects.expected.save'` 행 삭제 → `SettlementController::expectedSave()` 메서드 삭제.
  - `_tab_settlement.php`: `btnEditExpected` 버튼(:28-30)과 계약 총액 수정 모달 JS 블록(:258-276 부근) 삭제. '미설정' 배지의 title을 "프로젝트 수정에서 계약금액을 입력하세요"로 변경.
  - `form.php`: `$exMode` 게이트의 예정 금액 필드 블록(:138-141 부근) 삭제(계약금액 필드는 유지).

- [ ] **Step 7: 통과 확인 + 회귀** — `php scripts/tests/unit_r14.php` PASS → `php scripts/tests/run.php` ✅(unit_r11/r13은 contract_amount=0 시드라 fallback으로 통과). php -l 변경 파일 전부.

- [ ] **Step 8: 커밋**

```bash
git add app/core/AccountingService.php app/core/StatusService.php app/controllers/ProjectsController.php app/controllers/SettlementController.php app/routes.php app/views/projects/_tab_settlement.php app/views/projects/form.php scripts/tests/unit_r14.php
git commit -m "feat(r14): 예외 정산 총액 = 계약총액 단일화(fallback 레거시) — 탭 고정·이중입력 제거"
```

---

## Phase 3 — 반기 실적·용어·메뉴

### Task 6: 반기 실적 계약/공사 탭 + salesPaidByUser + 담당 프로젝트 수

**Files:**
- Modify: `app/core/AccountingService.php` (salesPaidByUser 신규)
- Modify: `app/controllers/BonusController.php` (overview staffRows 확장)
- Modify: `app/views/halfyear/index.php` (탭 2개 재구성)
- Modify: `scripts/tests/unit_r14.php`

**Interfaces:**
- Produces: `AccountingService::salesPaidByUser(string $from, string $to): array` — uid⇒순입금(현금 VAT포함): 계약 입금→`contracts.sales_user_id` 귀속 + 예외 직접 입금→`projects.sales_user_id` 귀속, paid·환불 차감, paid_date 기간.
- Consumes: `AccountingService::employeeProjectCountByUser`(기존 :509-519 — 시그니처 확인 후 그대로 재사용), `contractedAmountByUser`, `employeeConfirmedByUser`, 보너스 지급 합(기존 overview 쿼리).

- [ ] **Step 1: 실패 테스트 추가** — unit_r14.php rollback 앞:

```php
    // ── Task 6: salesPaidByUser — 담당영업 귀속 매출금액(입금) ──
    $su = Db::insert('users', ['login_id' => 'r14sales', 'password_hash' => 'x', 'name' => 'R14영업',
        'role_id' => 4, 'role_key' => 'staff', 'email' => 'r14s@t.t', 'status' => 'active']);
    // 예외 직접 입금 1,100,000 → p.sales_user_id 귀속
    $sp = Db::insert('projects', ['project_no' => 'R14-S1', 'name' => 'R14영업귀속', 'customer_id' => null,
        'is_exception' => 1, 'customer_name_snapshot' => 'x', 'contract_amount' => 2000000,
        'sales_user_id' => $su, 'status' => 'in_progress']);
    Db::insert('payments', ['project_id' => $sp, 'pay_type' => 'down', 'amount' => 1100000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    // 환불 100,000 차감
    Db::insert('payments', ['project_id' => $sp, 'pay_type' => 'refund', 'kind' => 'refund', 'amount' => 100000, 'status' => 'paid', 'paid_date' => date('Y-m-d')]);
    $map = AccountingService::salesPaidByUser(date('Y-m-01'), date('Y-m-t'));
    t_int('담당영업 귀속 순입금(예외·환불 차감)', 1000000, $map[$su] ?? 0);
```

- [ ] **Step 2: 실패 확인** — Run: `php scripts/tests/unit_r14.php` / Expected: FAIL(메서드 없음).

- [ ] **Step 3: AccountingService::salesPaidByUser 구현** — `employeePaidByUser` 아래에:

```php
    /**
     * R14: 담당 영업 귀속 매출금액(입금·현금 VAT 포함, 순입금 = 입금 − 환불, 취소 제외).
     * 계약 입금 → 계약 담당(contracts.sales_user_id), 예외 직접 입금 → 프로젝트 담당(projects.sales_user_id).
     * @return array<int,int> user_id => 순입금
     */
    public static function salesPaidByUser(string $from, string $to): array
    {
        $rows = Db::all(
            "SELECT uid, COALESCE(SUM(amt), 0) AS s FROM (
                SELECT c.sales_user_id AS uid,
                       CASE WHEN pm.kind = 'refund' THEN -pm.amount ELSE pm.amount END AS amt
                  FROM payments pm
                  JOIN contracts c ON c.id = pm.contract_id AND c.deleted_at IS NULL
                 WHERE pm.status = 'paid' AND pm.paid_date BETWEEN :f1 AND :t1
                   AND c.sales_user_id IS NOT NULL
                UNION ALL
                SELECT p.sales_user_id AS uid,
                       CASE WHEN pm.kind = 'refund' THEN -pm.amount ELSE pm.amount END AS amt
                  FROM payments pm
                  JOIN projects p ON p.id = pm.project_id AND p.deleted_at IS NULL
                 WHERE pm.status = 'paid' AND pm.paid_date BETWEEN :f2 AND :t2
                   AND p.sales_user_id IS NOT NULL
             ) t GROUP BY uid",
            [':f1' => $from, ':t1' => $to, ':f2' => $from, ':t2' => $to]
        );
        $map = [];
        foreach ($rows as $r) { $map[(int) $r['uid']] = (int) $r['s']; }
        return $map;
    }
```

- [ ] **Step 4: BonusController::overview 확장** — staffRows 조립부(:201-230)에서 `$salesPaid = AccountingService::salesPaidByUser($from, $to);` `$projCnt = AccountingService::employeeProjectCountByUser(...)`(기존 시그니처 그대로 — :509 확인) 로드 후 각 행에 `'sales_paid' => $salesPaid[$sid] ?? 0, 'project_cnt' => $projCnt[$sid] ?? 0` 추가.

- [ ] **Step 5: halfyear/index.php 재구성** — 직원별 반기 실적 카드(:132-156)를 탭 2개로:

```php
  <div class="card">
    <div class="card-head"><h2>직원별 반기 실적</h2>
      <div class="tab-mini" role="tablist">
        <button type="button" class="tab-btn active" data-hy-tab="contract">계약 실적</button>
        <button type="button" class="tab-btn" data-hy-tab="construction">공사 실적</button>
      </div></div>
    <div data-hy-panel="contract">
      <div class="muted fs-12" style="padding:0 12px">계약금액=담당 영업 수주(공급가) · 매출금액=담당 계약·예외 프로젝트 입금(현금·VAT 포함, 환불 차감)</div>
      <div class="table-wrap"><table class="data compact">
        <thead><tr><th>직원</th><th class="num">계약금액</th><th class="num">매출금액(입금)</th></tr></thead>
        <tbody><?php foreach ($staffRows as $s): ?>
          <tr><td><a href="<?= e(url('staff.show', ['id' => $s['id']])) ?>"><?= e($s['name']) ?></a></td>
            <td class="num mono"><?= money($s['contracted']) ?></td>
            <td class="num mono"><?= money($s['sales_paid']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    </div>
    <div data-hy-panel="construction" hidden>
      <div class="muted fs-12" style="padding:0 12px">배정·기여도 기준 — 순이익=기여도 반영 순이익 누적 · 보너스=지급완료 확정(취소 제외)</div>
      <div class="table-wrap"><table class="data compact">
        <thead><tr><th>직원</th><th class="num">담당 프로젝트 수</th><th class="num">기여도 반영 순이익 누적</th><th class="num">보너스 지급</th></tr></thead>
        <tbody><?php foreach ($staffRows as $s): ?>
          <tr><td><a href="<?= e(url('staff.show', ['id' => $s['id']])) ?>"><?= e($s['name']) ?></a></td>
            <td class="num mono"><?= (int) $s['project_cnt'] ?>건</td>
            <td class="num mono<?= (int) $s['profit'] < 0 ? ' text-danger' : '' ?>"><?= money($s['profit']) ?></td>
            <td class="num mono"><?= money($s['bonus_paid']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    </div>
  </div>
  <script>
  document.querySelectorAll('[data-hy-tab]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-hy-tab]').forEach(function (x) { x.classList.toggle('active', x === b); });
      document.querySelectorAll('[data-hy-panel]').forEach(function (p) { p.hidden = p.dataset.hyPanel !== b.dataset.hyTab; });
    });
  });
  </script>
```
(기존 '입금'·'확정매출' 단독 컬럼은 제거. `.tab-btn` 스타일이 없으면 app.css에 최소 스타일 추가: `.tab-mini{display:flex;gap:6px}.tab-btn{padding:4px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer}.tab-btn.active{background:#111827;color:#fff}`.)

- [ ] **Step 6: 통과 + 회귀** — unit_r14 PASS, run.php ✅, php -l 대상 파일.
- [ ] **Step 7: 커밋**

```bash
git add app/core/AccountingService.php app/controllers/BonusController.php app/views/halfyear/index.php public/assets/css/app.css scripts/tests/unit_r14.php
git commit -m "feat(r14): 반기 실적 계약/공사 탭 — 담당영업 매출금액(입금) 집계·담당 프로젝트 수·기여도 반영 순이익"
```

---

### Task 7: 보너스 용어 리네임 전수 + 직원 성과 메뉴 제거

**Files:**
- Modify: `app/views/halfyear/index.php:175`(원장 헤더), `app/views/bonus/index.php`(69-72·195·200-203·249·288·322·325), `app/views/bonus/history.php:14`, `app/views/projects/_tab_staff.php:75`, `app/controllers/BonusController.php`(주석), `app/core/Nav.php:28`

**Interfaces:** 없음(문구·메뉴).

- [ ] **Step 1: 리네임 적용** — 아래 매핑을 모든 나열 위치에 적용(schema 주석은 제외):
  - `산정 대상 매출` → `총매출`
  - `기여도 적용 매출` → `기여도 반영 매출` / 단독 `적용 매출` → `기여도 반영 매출`
  - `적용 순이익` → `기여도 반영 순이익`
  - bonus/index.php:72의 산정액 title 문구 내 "적용 매출"도 동일 교체.
- [ ] **Step 2: Nav 메뉴 제거** — `app/core/Nav.php:28`의 `['performance.index', '직원 성과', null, 'trending']` 행 삭제(라우트·페이지·내부 링크는 유지 — 직원 상세 '성과 보기'/대시보드/qa_smoke 참조).
- [ ] **Step 3: 검증** — `grep -rn "산정 대상 매출\|적용 매출\|적용 순이익" app/views app/controllers` → 0건(단, "기여도 반영" 결과는 제외 검색: `grep -v '기여도 반영'`). `grep -n "직원 성과" app/core/Nav.php` → 0건. php -l 대상 파일. `php scripts/tests/run.php` ✅. `bash scripts/qa_smoke.sh` PASS(performance 라우트 잔존 확인).
- [ ] **Step 4: 커밋**

```bash
git add app/views/halfyear/index.php app/views/bonus/index.php app/views/bonus/history.php app/views/projects/_tab_staff.php app/controllers/BonusController.php app/core/Nav.php
git commit -m "feat(r14): 보너스 용어 리네임(총매출·기여도 반영 매출/순이익) 전수 + 직원 성과 메뉴 제거"
```

---

## Phase 4 — QA·배포

### Task 8: 통합 QA — 회귀 + 브라우저 실측(PC/모바일)

- [ ] **Step 1: 회귀 전체** — `php scripts/tests/run.php` ✅ · `php scripts/reconcile_qa.php`(기지 예외: notifications 잔재) · `bash scripts/qa_smoke.sh` PASS.
- [ ] **Step 2: 브라우저 실측** — `bash scripts/start_dev.sh` 후 puppeteer(scripts/qa_browser 인프라)로 PC 1440/모바일 390 스크린샷: ①보드 게이지 조작→배지·그룹 즉시 변경 ②전부 100→완료 팝업→종결 그룹 ③하자보수 버튼 ④메모 팝업 등록/삭제 ⑤예외 정산탭 계약총액 고정 표시 ⑥반기 계약/공사 탭 전환 ⑦모바일 1컬럼·슬라이더 터치 크기. PHP/JS 콘솔 오류 0.
- [ ] **Step 3: 시나리오 데이터 검증** — 임시 예외 프로젝트로: 게이지 50% → 진행중 + 요약헤더 현재 공정 일치(새 페이지 로드), 전액 입금 → '전액 입금 완료', 환불 → 보너스 재계산(R13 훅 그대로 동작 확인). 종료 후 임시 데이터 정리.
- [ ] **Step 4: harness 검증 기록** — `harness_progress.js verify --id T8 --add "..."`.

### Task 9: 운영 배포 + 재검증 (QC 통과 시 — 사장 사전 승인)

- [ ] **Step 1: 운영 DB 백업** — `deploy/db_dump.php` 패턴으로 edencrm_% 백업(기존 절차).
- [ ] **Step 2: 마이그레이션** — `php deploy/run_migration.php database/cafe24/012_r14_gauge_memo.sql --dry` → 실행. 이어서 `php deploy/backfill_r14.php --prod --dry` → 검토 → `php deploy/backfill_r14.php --prod`.
- [ ] **Step 3: 코드 배포** — `./deploy/deploy.sh`(dry 검토) → `CONFIRM=yes ./deploy/deploy.sh`.
- [ ] **Step 4: 운영 재검증** — `./deploy/verify.sh` 그린 + 운영 URL 핵심 시나리오(게이지·메모·정산탭·반기 탭) 재확인. 이상 시 rollback.sh + 백업 복원.
- [ ] **Step 5: 기록** — harness verify/hold 기록, 메모리 갱신.

---

## Self-Review

- **스펙 커버리지:** 게이지 보드+즉시반영(#6·#1→T2-T4), 메모(#5→T1·T3·T4), 계약총액(#2→T1·T5), 반기 탭+워딩+프로젝트 수(#3·#4→T6·T7), 메뉴 제거(#4→T7), 세션(#7→완료), 반응형(T4 CSS·T8 QA). ✅
- **플레이스홀더:** 코드 블록 전부 실코드. board.php 카드 메타 "기존 블록 재사용"은 원본 파일에 존재하는 코드의 이동 지시로 구체적. memoList GET 규약은 app.js 확인 지시 포함.
- **타입 일관성:** `setStageProgress(int,int,int,?int):array{pct,progress,status,current_stage_id,all_done}` — T3 컨트롤러 사용과 일치. `statusGroup(string):string` T3·T4 공유. `salesPaidByUser(string,string):array<int,int>` T6 일치. COALESCE(NULLIF(...)) 조각 T5 전 지점 동일.
