<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\GuardianService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A guardian may not reach a screen whose response is inherently many-students.
 *
 * WHAT THIS CLOSES. Three read routes are granted to the `guardian` role and
 * return records for a whole cohort, with no per-student parameter anywhere on
 * the URL:
 *
 *   GET class-level/{classLevel:uuid}/results          a whole class level
 *   GET class-level-arm/{classLevelArm:uuid}/results   a whole arm
 *   GET setup/curriculum-subject/{curriculumSubject}   a full score grid —
 *       the closure loads `scores.student` and `studentResults.student` for
 *       EVERY student in the subject
 *
 * None of the three needs a uuid guessed: the permission grant reaches the data
 * on its own, and the first two are linked from the results screens a parent is
 * already meant to use. EnsureGuardianOwnsStudent closed the eight routes that
 * name a student; these are the ones that name a container.
 *
 * WHY A SECOND MIDDLEWARE AND NOT A WIDER FIRST ONE. The rules are genuinely
 * different, and one name that means two things is a name that stops meaning
 * either. `guardian_ward` answers "does this parent own the student on the URL"
 * — a per-request, per-student question. Here there is no student to own, so
 * there is no question: the answer is no for every parent, every time, and the
 * route table should say so in its own word. Folding it into the first would
 * also have to travel through that middleware's fail-closed branch (its
 * "attached but nothing resolved" arm), which exists to make a WIRING MISTAKE
 * visible. Routing deliberate policy through a mistake detector destroys the
 * detector.
 *
 * IT IS THE ROLE, NOT A RELATIONSHIP, so it does not resolve a Guardian row and
 * does not care whether one exists: a `guardian`-only account with no guardian
 * record in the active School is refused here exactly as one with three wards
 * is. There is nothing about this cohort that any parent may see.
 *
 * STAFF ARE UNTOUCHED via the shared `GuardianService::isActingAsGuardian()` —
 * the guardian role and no other. See that method for why the bare
 * `hasRole('guardian')` is the wrong test, and note that a teacher who is also
 * a parent at this school keeps their full staff reach through these three.
 *
 * The refusal reveals nothing: the container was already resolvable to anyone
 * holding the ability, and School isolation is enforced ahead of this by the
 * route binding and the controllers' own `abort_unless` on `school_id`.
 */
class DenyGuardianBulkRecords
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

        $message = 'This record covers more than one student and is not available to guardians.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->forbidden($message);
        }

        abort(403, $message);
    }
}
