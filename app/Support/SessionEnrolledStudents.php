<?php

namespace App\Support;

use App\Enums\StudentStatusEnum;
use App\Http\Controllers\SetupController;
use App\Services\Dashboard\DashboardAnalysisService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HOW MANY STUDENTS ARE HERE THIS YEAR — one definition of the join, for every surface that displays
 * a student population.
 *
 * ── WHY THIS IS ONLY EXPRESSIBLE AS A JOIN ──────────────────────────────────────────────────────
 * A `Student` is a PERSISTENT PERSON. The row has a `school_id` and no session column, and it must
 * stay that way: a pupil who is here for six years is one row, not six. So "students this session"
 * is not a column anywhere — it is an ENROLLMENT fact, and the only path to it is
 *
 *     students -> student_curricula (status ACTIVE) -> curricula -> terms -> academic_sessions
 *
 * Counting `students` by `school_id`, which is what both displayed surfaces did before this class
 * existed, answers a different question — "how many pupils has this school ever registered" — whose
 * value only ever grows and includes everyone who has since graduated or left.
 *
 * ── IT IS ONE CLASS BECAUSE THE TOTAL AND THE BREAKDOWN MUST NOT DRIFT ──────────────────────────
 * Two callers want this predicate: the displayed TOTAL ({@see SetupController::index()} and the
 * dashboard's students KPI) and the population overview BY CLASS LEVEL
 * ({@see DashboardAnalysisService}). They are the same question presented two ways, so a reader who
 * finds the breakdown summing to something other than the total is looking at a bug — which is only
 * true while there is one join and not two. That is the same reasoning that moved "which term is
 * current" into {@see CurrentTerm}: this project has already paid for two hand-maintained copies of
 * one predicate disagreeing the day one of them gained a filter.
 *
 * ── WHAT IT DELIBERATELY DOES NOT SERVE ─────────────────────────────────────────────────────────
 * The ONBOARDING GATE. Three call sites read `entities['students']['active']` to decide whether the
 * "Add your students" step is complete, and that number must stay SCHOOL-WIDE: a school between
 * sessions — rolled over, nobody enrolled in the new session yet — would otherwise regress to
 * "incomplete" having done nothing wrong. This class is for DISPLAY. Nothing here may be wired to a
 * threshold.
 *
 * ── A NULL SESSION IS AN ANSWER, NOT A FAILURE ──────────────────────────────────────────────────
 * A school with no `is_current` session has nobody enrolled *this session* by definition, so the
 * count is 0 and the breakdown is empty. It is not an error and must not throw: the dashboard is the
 * screen a school looks at precisely when its setup is incomplete.
 *
 * ── SCOPES ARE DROPPED AND THE SCHOOL IS PINNED EXPLICITLY ──────────────────────────────────────
 * These are raw query-builder joins, so no Eloquent global scope applies and `school_id` is pinned
 * from the caller's own argument rather than inferred (Constitution 13). The session id passed in is
 * the school's OWN current session — `academic_sessions` is school-owned — which is what keeps
 * another school's session from ever widening this.
 */
final class SessionEnrolledStudents
{
    /**
     * The shared join, filtered to one school and one session, with NO select and NO grouping — the
     * caller decides whether it wants a count or a breakdown.
     *
     * Callers must handle a null session BEFORE calling this; see {@see count()} for why null is a
     * legitimate answer rather than a query.
     */
    public static function query(int $schoolId, int $academicSessionId): Builder
    {
        return DB::table('students')
            ->join('student_curricula', 'students.id', '=', 'student_curricula.student_id')
            ->join('curricula', 'student_curricula.curriculum_id', '=', 'curricula.id')
            ->join('terms', 'curricula.term_id', '=', 'terms.id')
            // DEFENCE IN DEPTH, AND MEASURED TO BE EXACTLY THAT. Deleting this predicate reds no
            // test, and that is not a gap in the arms: `student_curricula` carries composite foreign
            // keys on (student_id, school_id) and (curriculum_id, school_id) (2026_07_19_130000),
            // which force student.school_id = student_curricula.school_id = curricula.school_id — so
            // reaching this school's session already implies this school's pupils, and the
            // cross-school row is not constructible (a test asserts the 1452 refusal). It stays
            // because the school is pinned explicitly rather than inferred (Constitution 13), and
            // because it is the only thing left if either constraint is ever dropped.
            ->where('students.school_id', $schoolId)
            ->whereNull('students.deleted_at')
            ->where('student_curricula.status', StudentStatusEnum::ACTIVE->value)
            ->where('terms.academic_session_id', $academicSessionId);
    }

    /**
     * DISTINCT students, because the count is of PEOPLE and a pupil can hold more than one active
     * enrollment in a session (two curricula in the same term, or one per term). Counting rows would
     * report more pupils than the school has.
     */
    public static function count(int $schoolId, ?int $academicSessionId): int
    {
        if ($academicSessionId === null) {
            return 0;
        }

        return (int) self::query($schoolId, $academicSessionId)
            ->distinct()
            ->count('students.id');
    }
}
