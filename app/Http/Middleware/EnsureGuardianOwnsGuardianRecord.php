<?php

namespace App\Http\Middleware;

use App\Models\Guardian;
use App\Models\User;
use App\Services\GuardianService;
use App\Support\StudentRecordAccessLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A guardian may only address their OWN guardian record.
 *
 * WHAT THIS CLOSES. `GET /api/guardians/{guardian:uuid}/students` is granted to
 * the `guardian` role under `student_status.view` and returns a guardian's ward
 * list. It authorised on the ability alone, so a signed-in parent could read any
 * other parent's children by editing a uuid — the same shape of hole
 * EnsureGuardianOwnsStudent closed, one relationship further out.
 *
 * WHY THIS IS NOT THE SAME PREDICATE AS `guardian_ward`, and must not be folded
 * into it. That middleware asks whether the caller owns the STUDENT on the URL.
 * The thing owned here is a GUARDIAN row, and the relation is identity, not
 * custody: "is this record you". A parent with no wards at all still owns their
 * own record, and a parent who owns every student in the school still does not
 * own another parent's. Answering one question with the other's predicate gets
 * both wrong in one direction or the other.
 *
 * IDENTITY IS RESOLVED SERVER-SIDE, NEVER TRUSTED FROM THE REQUEST. The bound
 * Guardian is compared against the row `GuardianService::forUserInActiveSchool()`
 * returns for the acting user — the same resolver `isWardOf()` uses, and for the
 * same reason its docblock gives: `$user->guardian` is an unordered `hasOne`
 * whose scope ORs on School access, so for a parent with rows in two Schools it
 * hands back an arbitrary one. Here that would be the difference between
 * "is this record yours in the School you are actually in" and "…in some other
 * School", on the code path deciding whether a parent reads a stranger's
 * children.
 *
 * AND THE BINDING ITSELF IS NOT SCHOOL-SAFE. `Guardian::applySchoolScope()`
 * matches `school_id = active OR user_id has access to active`, so a Guardian row
 * owned by ANOTHER School still resolves through route-model binding whenever its
 * user can reach the active one. Comparing primary keys against the server-resolved
 * row closes that too; a check that compared uuids from the request, or trusted
 * the binding to have been school-correct, would not.
 *
 * FAIL CLOSED ON NO GUARDIAN BINDING, for the reason EnsureGuardianOwnsStudent
 * gives at length: the protection's value is that it is visible in `route:list`,
 * and that is worth nothing if the middleware can be attached and then find
 * nothing to check. A renamed model or a lost parameter becomes an immediately
 * visible 403 for parents rather than a silently reopened door. Likewise no
 * guardian row in the active School at all: there is then no record this caller
 * owns, so there is none they may read.
 *
 * STAFF ARE UNTOUCHED via the shared `GuardianService::isActingAsGuardian()`.
 * A registrar, admin or teacher reading a parent's ward list is the job.
 *
 * EVERY REFUSAL IS AUDITED as `student_record_access_refused` naming
 * `guardian_self` — see App\Support\StudentRecordAccessLog. The bound GUARDIAN
 * ids are recorded rather than a student subject: what was asked for here is a
 * parent's identity, not a child's record, and recording it as a student view
 * would put the wrong thing in the column the audit query reads. There is no
 * VIEW event on the pass arm for the same reason — this route returns a ward
 * LIST for the caller's own record, which is not a student record read; the
 * eight routes that are one are covered by `guardian_ward`.
 */
class EnsureGuardianOwnsGuardianRecord
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

        $bound = $this->boundGuardianIds($request);

        if ($bound === []) {
            return $this->refuse($request);
        }

        $own = $this->guardians->forUserInActiveSchool($user);

        if (! $own) {
            return $this->refuse($request);
        }

        foreach ($bound as $guardianId) {
            if ($guardianId !== (int) $own->id) {
                return $this->refuse($request);
            }
        }

        return $next($request);
    }

    /**
     * Every Guardian the request names.
     *
     * Matches on the model CLASS rather than a parameter name, so a `{parent}`
     * or `{ward_guardian}` parameter binding the same model is caught without
     * touching this file — and reads the RESOLVED parameters, so it runs after
     * SubstituteBindings (a group middleware; this is attached at the route
     * level and therefore always later). The loop refuses unless EVERY bound
     * Guardian is the caller's own: a route that ever carries two of them, or
     * carries one alongside `withoutScopedBindings()`, is covered the day it is
     * added rather than the day it is exploited.
     *
     * @return array<int,int>
     */
    private function boundGuardianIds(Request $request): array
    {
        $ids = [];

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Guardian) {
                $ids[] = (int) $parameter->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function refuse(Request $request): Response
    {
        // BEFORE the abort, which throws. $user is non-null on every path that
        // reaches here: handle() returns early when there is none.
        /** @var User $user */
        $user = $request->user();

        StudentRecordAccessLog::refused($user, $request, 'guardian_self', [
            'guardian_ids' => $this->boundGuardianIds($request),
        ]);

        $message = 'You can only view your own guardian record.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->forbidden($message);
        }

        abort(403, $message);
    }
}
