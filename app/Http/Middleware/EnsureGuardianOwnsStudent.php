<?php

namespace App\Http\Middleware;

use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Services\GuardianService;
use App\Support\StudentRecordAccessLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A guardian may only address a student they actually own.
 *
 * WHAT THIS CLOSES. Eight read routes carry a student-owned route binding and
 * are granted to the `guardian` role — five under `result.view`
 * (routes/web.php, routes/api.php), three under `student_curriculum.view`,
 * `curriculum_subject.view` and `student_status.view`. Every one of them
 * authorised on the ABILITY alone and never on the relationship, so a signed-in
 * parent could read any student in their School by editing a uuid in the
 * address bar. The permission was never the bug; the missing ownership check
 * was.
 *
 * WHY ONE MIDDLEWARE AND NOT EIGHT INLINE CHECKS. The absence of a single choke
 * point is precisely how eight doors came to be open at once, and an inline
 * check is invisible in `route:list` — a ninth route added next month inherits
 * nothing. Attaching this by name means the protection is a visible property of
 * the route table.
 *
 * IT RESOLVES EVERY STUDENT-OWNED BINDING, NOT THE FIRST ONE IT FINDS, and both
 * of the shapes those bindings come in:
 *
 *   - `{student}`           — the student directly (routes 1, 2, 3, 5, 6)
 *   - `{studentCurriculum}` — the student reached THROUGH the enrollment
 *                             (routes 4, 7, 8; there is no `{student}` on the
 *                             URL at all)
 *
 * A guard that looks only for a `{student}` parameter protects nothing on the
 * second shape while appearing, in `route:list`, to cover it. And three of the
 * eight carry `withoutScopedBindings()`, so a second bound model is NOT
 * constrained by the first: on `students/{student}/results/{studentCurriculum}`
 * a guardian can pass their own ward as `{student}` alongside a stranger's
 * enrollment. Stopping at the first match authorises that request. The loop
 * below therefore refuses unless the guardian owns EVERY student the request
 * names. (Of the three, only that route carries two STUDENT-owned bindings; the
 * second binding on the other two is School-owned — `{curriculum}`,
 * `{curriculumSubject}` — so a single-student check covers them. The loop is
 * written for the general case so that stays true when a route is added.)
 *
 * WHY ROLE-CONDITIONAL RATHER THAN UNIVERSAL. Ownership is the wrong question
 * for staff: an admin, teacher, head of school, key stage coordinator, boarding
 * parent or bursar holding these abilities is *supposed* to read students they
 * have no family relationship with — that is the job. A universal check would
 * take the school's own staff off its results screens the day it shipped, which
 * is a bigger outage than the hole it closes. So the check is scoped to the
 * accounts that should never have had this reach: parents.
 *
 * The condition is deliberately "the guardian role AND no other role" rather
 * than the bare `hasRole('guardian')` used by the visibility filters further
 * down these same routes. It lives in ONE place —
 * `GuardianService::isActingAsGuardian()`, which carries the full reasoning —
 * because DenyGuardianBulkRecords and EnsureGuardianOwnsGuardianRecord ask the
 * same question of four more routes. Three copies of it would drift, and a
 * drifted copy is a hole or an outage depending on the direction.
 *
 * The refusal says only that the student is not the caller's ward. It reveals
 * nothing about existence beyond what the School scope on the route binding
 * already reveals — a student outside the active School never resolves and 404s
 * before this middleware runs.
 *
 * BOTH ARMS ARE AUDITED — see App\Support\StudentRecordAccessLog, which carries
 * the reasoning. A pass writes `student_record_viewed`, a refusal writes
 * `student_record_access_refused` naming `guardian_ward` as the rule. Staff never
 * reach either, deliberately. The log can never alter what this middleware
 * decides: every write is swallowed, so an unwritable audit trail leaves a 403 a
 * 403 and a 200 a 200.
 */
class EnsureGuardianOwnsStudent
{
    public function __construct(private GuardianService $guardians) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! $this->guardians->isActingAsGuardian($user)) {
            return $next($request);
        }

        $studentIds = $this->boundStudentIds($request);

        // FAIL CLOSED on nothing to check. Every route this is attached to binds
        // a student one way or the other, so an empty list means the binding did
        // not resolve — a renamed model, a route that lost its parameter, an
        // ordering change that put this ahead of SubstituteBindings. Passing
        // there would make the middleware a no-op that still reads as protection
        // in `route:list`, which is the failure mode this whole change exists to
        // remove. Refusing instead turns that mistake into an immediately visible
        // 403 for parents rather than a silent re-opening of eight doors.
        if ($studentIds === []) {
            return $this->refuse($request, $user, [], null);
        }

        foreach ($studentIds as $studentId) {
            if (! $this->guardians->isWardOf($user, $studentId)) {
                return $this->refuse($request, $user, $studentIds, $studentId);
            }
        }

        // The ward check has PASSED: a parent is about to read a child's record.
        // This is the entry that makes "was it ever exploited" answerable, so it
        // is written on the allow path and not only on refusals — a log of
        // refusals alone records the attempts that failed and nothing about the
        // reads that succeeded, which is the half the question is about.
        StudentRecordAccessLog::viewed($user, $request, $studentIds);

        return $next($request);
    }

    /**
     * Every student the request names, through either binding shape.
     *
     * Reads the route's RESOLVED parameters, so it runs after
     * SubstituteBindings (a group middleware; this one is attached at the route
     * level and therefore always later). Matching on the model CLASS rather than
     * on parameter names is what makes this survive a rename or a new route: a
     * `{ward}` or `{enrollment}` parameter binding the same models is caught
     * without touching this file.
     *
     * @return array<int,int>
     */
    private function boundStudentIds(Request $request): array
    {
        $ids = [];

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Student) {
                $ids[] = (int) $parameter->id;

                continue;
            }

            // No null guard: student_curricula.student_id is NOT NULL (it is the
            // parent half of the composite FK to students), so a bound enrollment
            // always names a student. Larastan flags the check as always-true.
            if ($parameter instanceof StudentCurriculum) {
                $ids[] = (int) $parameter->student_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int,int>  $studentIds  every student the request named
     * @param  int|null  $offendingStudentId  the one that failed the check, or null
     *                                        when nothing resolved at all
     */
    private function refuse(Request $request, User $user, array $studentIds, ?int $offendingStudentId): Response
    {
        // BEFORE the abort, which throws. Both refusal arms come through here,
        // so the fail-closed "nothing resolved" case is audited too — that one
        // is a WIRING mistake rather than a probe, and telling the two apart is
        // exactly what recording the offending id (null here) is for.
        StudentRecordAccessLog::refused(
            $user,
            $request,
            'guardian_ward',
            ['student_ids' => array_values($studentIds)],
            $offendingStudentId,
        );

        $message = 'You can only view records for a student in your care.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->forbidden($message);
        }

        abort(403, $message);
    }
}
