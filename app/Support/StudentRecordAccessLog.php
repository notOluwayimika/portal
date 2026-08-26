<?php

namespace App\Support;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * The audit trail behind "did a parent read a child who is not theirs".
 *
 * WHY THIS EXISTS. On 2026-08-25 a guardian could read any student's records in
 * their School; 1039 guardian accounts were login-enabled at the time. The three
 * middlewares this attaches to closed the hole. They could not answer the
 * question the school asked next — "was it ever exploited?" — because nothing
 * recorded a result VIEW, only writes. That answer had to be given as "it cannot
 * be determined". This class exists so the same question is answerable next
 * time; it adds no authorisation and changes no outcome.
 *
 * TWO EVENTS, AT THE THREE EXISTING CHOKE POINTS. `student_record_viewed` when
 * EnsureGuardianOwnsStudent's ward check PASSES, and
 * `student_record_access_refused` on every 403 from any of the three. No fourth
 * choke point is introduced: a place that logs but does not decide would drift
 * away from the place that decides, and a log that disagrees with the guard is
 * worse than no log.
 *
 * STAFF VIEWS ARE DELIBERATELY NOT LOGGED, and that is a DECISION, not an
 * oversight. All three middlewares stand aside for anyone who is not
 * guardian-only (GuardianService::isActingAsGuardian), so this class is never
 * reached for staff — a teacher opening results all day is the job, and logging
 * it would write thousands of rows that drown the handful this exists to
 * surface. If "which member of staff read this student" ever becomes a question
 * worth answering, it is a different control with a different volume profile and
 * its own retention decision, not a widening of this one.
 *
 * SNAKE_CASE EVENT NAMES, because the activity log carries both conventions
 * today and only one of them is queryable: the read API filters on `event` with
 * whereIn (ActivityLogQueryService::applyFilters) and the screen offers those
 * exact values as a multi-select. A sentence in `event` is a row nobody can
 * select.
 *
 * PROPERTIES ARE ACTOR, SUBJECT, ROUTE AND RULE — nothing else. No request body,
 * no query string, no payload. The refusal entry names WHICH rule refused
 * (`guardian_ward`, `guardian_no_bulk`, `guardian_self` — the route-table
 * aliases from bootstrap/app.php, so the log speaks the same vocabulary as
 * `route:list`), because "refused" alone cannot tell a parent probing uuids from
 * a route wired with the wrong middleware.
 *
 * LOGGING MUST NEVER CHANGE THE OUTCOME. Every write goes through self::write(),
 * which swallows Throwable: a guard that refuses must still refuse when the log
 * is unwritable, and a view that was allowed must still be served. The failure
 * direction matters in both directions — an exception here would turn a 403 into
 * a 500 (indistinguishable, to a prober, from a hole) and a 200 into a 500 (an
 * outage for every parent in the school, caused by the audit trail). The
 * exception is still reported so a broken log surfaces somewhere.
 */
final class StudentRecordAccessLog
{
    public const LOG_NAME = 'guardian';

    public const VIEWED = 'student_record_viewed';

    public const REFUSED = 'student_record_access_refused';

    /**
     * A guardian was allowed to read the records of a student they own.
     *
     * @param  array<int,int>  $studentIds  every student the request named
     */
    public static function viewed(User $user, Request $request, array $studentIds): void
    {
        self::write(function () use ($user, $request, $studentIds) {
            $logger = activity(self::LOG_NAME)
                ->causedBy($user)
                ->event(self::VIEWED)
                ->withProperties(array_merge(
                    ['student_ids' => array_values($studentIds)],
                    self::routeProperties($request),
                ));

            if ($subject = self::resolveStudent($request, $studentIds[0] ?? null)) {
                $logger->performedOn($subject);
            }

            $logger->log(self::VIEWED);
        });
    }

    /**
     * One of the three middlewares refused this request.
     *
     * @param  string  $rule  the route-table alias that refused
     * @param  array<string,mixed>  $properties  what the caller asked for
     * @param  int|null  $subjectStudentId  the student the refusal was about, if any
     */
    public static function refused(
        User $user,
        Request $request,
        string $rule,
        array $properties = [],
        ?int $subjectStudentId = null,
    ): void {
        self::write(function () use ($user, $request, $rule, $properties, $subjectStudentId) {
            $logger = activity(self::LOG_NAME)
                ->causedBy($user)
                ->event(self::REFUSED)
                ->withProperties(array_merge(
                    ['rule' => $rule],
                    $properties,
                    self::routeProperties($request),
                ));

            if ($subject = self::resolveStudent($request, $subjectStudentId)) {
                $logger->performedOn($subject);
            }

            $logger->log(self::REFUSED.': '.$rule);
        });
    }

    /**
     * The route, named the way `route:list` names it.
     *
     * Falls back to the uri pattern for an unnamed route so an ad-hoc or probe
     * route still records WHERE it was refused rather than a null. The PATTERN,
     * never the resolved path: the path carries uuids, and the subject id in
     * `student_ids` already says which record without putting an address a
     * reader could replay into the audit trail.
     *
     * @return array<string,string|null>
     */
    private static function routeProperties(Request $request): array
    {
        $route = $request->route();

        return [
            'route' => $route?->getName() ?? $route?->uri(),
            'method' => $request->getMethod(),
        ];
    }

    /**
     * The Student to hang the entry on, without a query where the route already
     * bound one.
     *
     * Three of the eight ward-checked routes bind only `{studentCurriculum}` —
     * there is no Student instance on the request at all — so those resolve the
     * id the middleware already derived. Global scopes stand: the caller has
     * either just been proved to own this student (a view) or has just been
     * refused (a refusal), and in both cases the row is inside the active School
     * or the entry is written without a subject rather than reaching outside it.
     */
    private static function resolveStudent(Request $request, ?int $studentId): ?Student
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Student && (int) $parameter->id === $studentId) {
                return $parameter;
            }
        }

        return $studentId === null ? null : Student::find($studentId);
    }

    /**
     * Run a log write so that nothing it does can reach the caller.
     *
     * The inner try is not belt-and-braces: report() resolves the exception
     * handler out of the container and runs the application's reporting stack,
     * which is exactly the sort of thing that is also broken when the log write
     * failed. An audit trail that takes the request down with it when it breaks
     * is a denial of service wearing a security control's name.
     */
    private static function write(callable $log): void
    {
        try {
            $log();
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
                // Deliberately empty: there is nowhere left to report to, and
                // the request must still complete as it would have.
            }
        }
    }
}
