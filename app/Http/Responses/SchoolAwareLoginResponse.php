<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Support\ActiveSchool;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;

/**
 * Post-login school resolution (used for both password and 2FA logins):
 *  - super admins go to the super admin area (global context)
 *  - users with exactly one accessible school are logged straight in
 *  - users with several accessible schools pick one first
 *  - users with none are rejected
 *
 * ─── WHERE AN INTERNAL AUDITOR LANDS, AND WHY THE BRANCH IS HERE ────────────────────────────────
 *
 * `internal_auditor` holds `finance.invoice.approve`, `activity_log.view`, `view_all` and `export`
 * — and NOT `dashboard.view`. `config('fortify.home')` is `/dashboard`, which sits behind
 * `permission:dashboard.view` (routes/web.php), so a cold login for that seat 403s: authenticated,
 * in a school, and looking at an error page.
 *
 * THE BRANCH IS HERE AND NOT IN DashboardController. That controller's persona redirects only run
 * AFTER `dashboard.view` has admitted the request, so for a seat that does not hold it they are
 * unreachable. This response is the seam that runs for every login.
 *
 * PRECEDENCE, STATED RATHER THAN LEFT IMPLICIT:
 *
 *   1. `super_admin` FIRST, unchanged and deliberately ahead of this. It is a platform seat with no
 *      school context, and a user holding both super_admin and internal_auditor is a platform
 *      administrator who happens to carry an audit role — sending them to one school's queue would
 *      strand the account that exists to work across schools.
 *   2. THEN the no-school and multi-school branches, also unchanged: this branch sits INSIDE the
 *      single-school case, after `school_id` is put in the session. It has to — the queue is
 *      school-scoped and would 403 at `tenant` without a context. A multi-school auditor therefore
 *      still picks a school first and lands via `SchoolSwitchController`, which is a separate
 *      landing this change does not alter.
 *   3. A user holding internal_auditor AND another school role — say head_of_school — lands on the
 *      DASHBOARD, unchanged. The predicate is `holds the approve ability AND does NOT hold
 *      dashboard.view`, so this branch only ever RESCUES a seat that would otherwise 403; it never
 *      redirects one that already had somewhere to go.
 *
 *      That is the conservative direction and it is chosen on purpose. `finance.invoice.approve` is
 *      held by `internal_auditor` alone, so nothing else can match the first half — but the second
 *      half is what makes the guarantee independent of who is granted that ability tomorrow. A
 *      matrix edit handing it to head_of_school moves nobody's landing. If Brookstone later want
 *      the queue to win for a dual-role seat, that is dropping the second condition, which is a
 *      one-line change and a decision to record rather than a redesign.
 *
 * `redirect()->intended()` STILL WINS, and that is unchanged. A seat bounced to login from a
 * deep link returns to that link; this branch only decides the COLD login, which is the case that
 * was 403ing.
 */
class SchoolAwareLoginResponse implements LoginResponse, TwoFactorLoginResponse
{
    public function toResponse($request)
    {
        /** @var User $user */
        $user = auth()->user();

        setPermissionsTeamId(null);

        if ($user->isSuperAdmin()) {
            $request->session()->forget('school_id');

            return redirect()->intended('/super-admin');
        }

        $schools = $user->accessibleSchools();

        if ($schools->isEmpty()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'You are not authorized to log in to any school.',
            ]);
        }

        if ($schools->count() === 1) {
            // Resolved ONCE. Two `->id` reads would be two Larastan findings on an untyped
            // Collection where the baseline carries one, and the second is not a new fact about
            // the code — it is the same access written twice.
            $schoolId = (int) $schools->first()->id;

            $request->session()->put('school_id', $schoolId);

            return redirect()->intended($this->homeFor($user, $schoolId));
        }

        // Multiple schools: let the user pick which one to enter.
        $request->session()->forget('school_id');

        return redirect()->route('school.select');
    }

    /**
     * Where this user lands once their school is resolved.
     *
     * Read INSIDE the school's team context: spatie scopes grants per team, and `can()` outside one
     * answers about the wrong school — which for this branch would mean sending an auditor to a
     * dashboard they cannot open, or a bursar to a queue they cannot.
     *
     * The ability, never the role name. A seat granted `finance.invoice.approve` by the RBAC matrix
     * rather than by holding `internal_auditor` lands in the same place, and a role renamed
     * tomorrow changes nothing here — the same reason the sidebar composes on `can()`.
     */
    private function homeFor(User $user, int $schoolId): string
    {
        return ActiveSchool::runFor(
            $schoolId,
            fn (): string => $user->can('finance.invoice.approve') && ! $user->can('dashboard.view')
                ? route('internal-audit.review-queue', absolute: false)
                : config('fortify.home', '/dashboard')
        );
    }
}
