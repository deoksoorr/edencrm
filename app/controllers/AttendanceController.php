<?php
/**
 * 근태 수동 마킹(R6 T1) — 관리자용 지각·무단결근 등록·변경·해제 JSON API.
 * perm attendance.manage + POST + CSRF 는 라우터가 강제(attendance.mark/unmark).
 * 조회(분석 탭)는 기존 report.view — 권한 없는 사용자는 마킹 UI 미노출 + 이 API 403.
 *
 * 규칙(브리프 §1):
 *  - 같은 날 1상태만: attendance_marks UNIQUE(user_id, mark_date). 같은 상태 재등록은 422,
 *    다른 상태 선택은 UPDATE(상태 변경), 해제는 DELETE. 동시 등록 경합은 UNIQUE 위반을 잡아 422.
 *  - 등록·변경·해제 전부 Audit(직원ID·날짜·기존→변경 상태·메모(사유)·관리자·시각·IP — Audit::log 가
 *    user_id·ip·user_agent·created_at 자동 기록, 해제 여부는 action 으로 구분).
 */
class AttendanceController
{
    private const TYPES = ['late' => '지각', 'absent' => '무단결근'];

    /** 등록 또는 상태 변경: {user_id, mark_date, mark_type, memo?} → {id, mode:created|updated}. */
    public function mark(): void
    {
        [$userId, $date] = $this->targetOrFail();
        $type = Util::postStr('mark_type');
        if (!isset(self::TYPES[$type])) {
            Response::error('상태는 지각(late) 또는 무단결근(absent)만 선택할 수 있습니다.', 422);
        }
        if ($date > date('Y-m-d')) {
            Response::error('미래 날짜에는 근태 상태를 등록할 수 없습니다.', 422);
        }
        $memo = Util::nullIfEmpty(mb_substr(Util::postStr('memo'), 0, 255));

        $existing = Db::one(
            "SELECT * FROM attendance_marks WHERE user_id = :u AND mark_date = :d",
            [':u' => $userId, ':d' => $date]
        );

        if ($existing) {
            if ($existing['mark_type'] === $type && ($existing['memo'] ?? null) === $memo) {
                // 같은 날 같은 상태 재등록(중복 방지 — UNIQUE 와 동일 규칙을 화면 메시지로)
                Response::error(
                    '이미 해당 날짜에 ' . self::TYPES[$type] . ' 상태가 등록되어 있습니다. 상태를 바꾸려면 다른 상태를 선택하고, 지우려면 해제하세요.',
                    422
                );
            }
            // 상태 변경(또는 메모 수정) = UPDATE — 기존→변경 값을 Audit 에 남긴다
            Db::update('attendance_marks', ['mark_type' => $type, 'memo' => $memo], 'id = :id', [':id' => $existing['id']]);
            Audit::log('attendance_mark_update', 'attendance_marks', (int) $existing['id'], $existing, [
                'user_id' => $userId, 'mark_date' => $date, 'mark_type' => $type, 'memo' => $memo,
            ]);
            Response::json(['id' => (int) $existing['id'], 'mode' => 'updated']);
        }

        try {
            $id = Db::insert('attendance_marks', [
                'user_id'    => $userId,
                'mark_date'  => $date,
                'mark_type'  => $type,
                'memo'       => $memo,
                'created_by' => Auth::id(),
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                // 동시 등록 경합 — UNIQUE(user_id, mark_date)가 원천 차단
                Response::error('같은 날짜에 이미 근태 상태가 등록되어 있습니다(같은 날 1상태만 가능).', 422);
            }
            throw $e;
        }
        Audit::log('attendance_mark_create', 'attendance_marks', (int) $id, null, [
            'user_id' => $userId, 'mark_date' => $date, 'mark_type' => $type, 'memo' => $memo,
        ]);
        Response::json(['id' => (int) $id, 'mode' => 'created']);
    }

    /** 해제(DELETE): {user_id, mark_date, reason?} — 확인 절차는 화면(confirm), 기록은 Audit. */
    public function unmark(): void
    {
        [$userId, $date] = $this->targetOrFail();
        $row = Db::one(
            "SELECT * FROM attendance_marks WHERE user_id = :u AND mark_date = :d",
            [':u' => $userId, ':d' => $date]
        );
        if (!$row) {
            Response::error('해당 날짜에 등록된 근태 상태가 없습니다.', 404);
        }
        Db::run("DELETE FROM attendance_marks WHERE id = :id", [':id' => $row['id']]);
        Audit::log('attendance_mark_delete', 'attendance_marks', (int) $row['id'], $row, [
            'reason' => Util::nullIfEmpty(mb_substr(Util::postStr('reason'), 0, 255)),
        ]);
        Response::json(['id' => (int) $row['id'], 'mode' => 'deleted']);
    }

    /** 공통 대상 검증: 실존(미삭제) 직원 + 유효 날짜. 실패 시 422 로 즉시 종료. */
    private function targetOrFail(): array
    {
        $userId = (int) (Util::postInt('user_id') ?? 0);
        $date = Util::dateOrNull(Util::postStr('mark_date'));
        if ($userId <= 0 || $date === null) {
            Response::error('직원과 날짜를 올바르게 지정하세요.', 422);
        }
        if (!Db::val("SELECT 1 FROM users WHERE id = :id AND deleted_at IS NULL", [':id' => $userId])) {
            Response::error('존재하지 않는 직원입니다.', 422);
        }
        return [$userId, $date];
    }
}
