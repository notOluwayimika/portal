<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProvisionUserRequest;
use App\Models\Role;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

/**
 * Super-admin user provisioning — create an account and seat it in one or more schools.
 *
 * GENERALISED FROM THE ADMIN-ONLY FLOW (2026-09-06), NOT REPLACED BY A SECOND ONE. This controller
 * could previously mint exactly one role, `admin`, hardcoded in `grantSchoolAccess`'s default
 * parameter. Every other seat Brookstone runs on — the five finance roles, the school seats, and
 * now `admin_viewer` — was seeded and assignable through NO surface: `SyncUserRolesRequest` covers
 * a user who already exists in a school, and nothing created one. `executive_director` is the sharp
 * case: it is the sole checker on all five built finance pairs, so with no way to seat it the
 * platform could approve nothing financial and had no in-app remedy.
 *
 * WHAT IT DOES NOT DO, deliberately:
 *  - it never writes `model_has_roles` or the `school_user` pivot. Every grant goes through
 *    `User::grantSchoolAccess($school, $role)`, which is role-parameterised, sets the spatie team,
 *    and routes through `User::assignRole` — the one override that carries the null-team invariant
 *    AND the duty-separation guard. Writing the pivot directly would bypass both.
 *  - it never assigns `super_admin` (ProvisionUserRequest::NEVER_ASSIGNABLE).
 *  - it never resets an existing account's password.
 */
class AdminController extends Controller
{
    public function index()
    {
        $assignable = ProvisionUserRequest::assignableRoles();

        $roleIds = Role::whereIn('name', $assignable)->pluck('id');

        // Provisioned users are those holding any assignable role in any school's team. Read
        // unscoped: this page is the cross-school view, and a super_admin's passage through
        // authorization never crosses the isolation boundary on its own (ADR 0036) — the unscoped
        // read is what makes the cross-school listing explicit rather than incidental.
        $userIds = DB::table('model_has_roles')
            ->whereIn('role_id', $roleIds)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->unique();

        $users = User::withoutGlobalScope(SchoolScope::class)
            ->whereIn('id', $userIds)
            ->with('schools')
            ->orderBy('first_name')
            ->get();

        // role name + school id per user, so the screen can say WHICH seat in WHICH school rather
        // than a flat role list that loses the team dimension the whole model turns on.
        $roleNames = Role::whereIn('id', $roleIds)->pluck('name', 'id');
        $seats = DB::table('model_has_roles')
            ->whereIn('role_id', $roleIds)
            ->where('model_type', User::class)
            ->whereIn('model_id', $userIds)
            ->get(['model_id', 'role_id', 'school_id'])
            ->groupBy('model_id');

        $schoolNames = School::pluck('name', 'id');

        return Inertia::render('super-admin/admins/index', [
            'admins' => $users->map(fn ($u) => [
                'uuid' => $u->uuid,
                'name' => $u->full_name,
                'email' => $u->email,
                'disabled' => $u->isDisabled(),
                'schools' => $u->schools->map(fn ($s) => ['uuid' => $s->uuid, 'name' => $s->name])->values(),
                'seats' => collect($seats[$u->id] ?? [])
                    ->map(fn ($r) => [
                        'role' => $roleNames[$r->role_id] ?? null,
                        'school' => $r->school_id === null ? null : ($schoolNames[$r->school_id] ?? null),
                    ])
                    ->filter(fn ($s) => $s['role'] !== null)
                    ->values(),
            ])->values(),
            'schools' => School::orderBy('name')->get()
                ->map(fn ($s) => ['uuid' => $s->uuid, 'name' => $s->name])->values(),
            'assignable_roles' => $assignable,
        ]);
    }

    /**
     * Create (or extend) an account and grant it the requested roles in the requested schools.
     *
     * The duty-separation refusal has already happened in ProvisionUserRequest — this method is
     * only reached on a set that was checked while nothing was written. The transaction is
     * belt-and-braces for the case those two layers ever disagree: `grantSchoolAccess` can still
     * throw from `User::assignRole`, and a throw halfway through the loop must not leave an account
     * with some of its seats.
     */
    public function store(ProvisionUserRequest $request)
    {
        $data = $request->validated();

        $schools = School::whereIn('uuid', $data['schools'])->get();
        $roles = array_values(array_unique($data['roles']));

        $existing = $request->targetUser();

        $user = DB::transaction(function () use ($data, $schools, $roles, $existing) {
            $user = $existing ?? User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                // Primary school, as the admin-only flow set it: login access is governed by the
                // pivot, and this is the fallback the session falls back to.
                'school_id' => $schools->first()->id,
                // uuid is set by User's `creating` hook (:63-65) — passing one here would be a
                // second source for the same value.
                //
                // two_factor_confirmed_at is left NULL deliberately. Where a requested role is in
                // RbacSeeder::TWO_FACTOR_REQUIRED, EnsureTwoFactorEnrolled redirects this account
                // to settings/security on its first protected request — the enrolment flow, which
                // that middleware's EXEMPT_PATTERNS keep reachable. Pre-confirming here would seat
                // a 2FA-required role with 2FA silently satisfied and nobody enrolled.
                //
                // email_verified_at IS set, and without it the line above would be a lockout rather
                // than an enrolment. `settings/security` — the page EnsureTwoFactorEnrolled sends
                // them to — sits behind `verified` (routes/settings.php:15), and nothing on this
                // path fires the Registered event, so no verification mail is ever sent: the
                // account would bounce from the 2FA redirect to a verification notice it can never
                // satisfy. Provisioning BY A SUPER ADMIN is the verification event here — an
                // operator typed the address deliberately — so it is recorded as one. This also
                // closes the same latent gap on the admin-only flow this generalises, `admin` being
                // 2FA-required too. Pinned by UserProvisioningTest's enrolment arm, which walks the
                // whole redirect chain rather than asserting one hop.
                //
                // It is set by forceFill below and NOT in this array, because `email_verified_at`
                // is not in User::$fillable (:42) and `create()` drops an unfillable key SILENTLY —
                // no error, no warning, just an unverified account. Written here first, and the
                // enrolment arm is what caught it.
            ]);

            if ($existing === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            foreach ($schools as $school) {
                foreach ($roles as $role) {
                    // The PRIMITIVE, never a pivot write: role-parameterised, team-scoped per
                    // school, and it is what loops for cross-school seats such as
                    // executive_director and internal_auditor.
                    $user->grantSchoolAccess($school, $role);
                }
            }

            // Keep the fallback pointing at a school they can actually reach. Only ever set when it
            // is unset or stale — an existing staffer's primary school is theirs, not this flow's
            // to move.
            if ($user->school_id === null) {
                $user->forceFill(['school_id' => $schools->first()->id])->save();
            }

            return $user;
        });

        $user->flushSchoolAccessCache();

        return back()->with(
            'success',
            $existing === null
                ? 'User created and seated.'
                : 'Existing account granted the requested access.',
        );
    }

    /**
     * Replace a user's school access FOR ONE ROLE with the given set.
     *
     * The `role` parameter is what generalises this alongside `store`. It defaults to `admin`
     * because that is what every existing caller of this endpoint means — `grantSchoolAccess` and
     * `revokeSchoolAccess` both default the same way, and without the default this endpoint would
     * start revoking the wrong seat the moment the screen listed a non-admin user.
     *
     * REVOCATION STAYS ON THE SANCTIONED PATH, which matters for `internal_auditor`: its
     * cross-school semantics are pinned by InternalAuditorCrossSchoolRevocationTest, and
     * `revokeSchoolAccess` is the method that test exercises. Granting still runs through
     * `grantSchoolAccess` → `assignRole` → `DutySeparation::assertAssignmentAllowed`, so widening
     * someone's schools cannot smuggle in a both-sides state that `store` would have refused.
     */
    public function syncSchools(Request $request, string $uuid)
    {
        $data = $request->validate([
            'schools' => ['array'],
            'schools.*' => ['uuid', 'exists:schools,uuid'],
            'role' => ['sometimes', 'string', 'in:'.implode(',', ProvisionUserRequest::assignableRoles())],
        ]);

        $role = $data['role'] ?? 'admin';

        /** @var User $user */
        $user = User::withoutGlobalScope(SchoolScope::class)->where('uuid', $uuid)->firstOrFail();

        // Structural, as in ProvisionUserRequest: a super_admin's seats are not editable here.
        abort_if($user->isSuperAdmin(), 403, 'A super admin\'s access is not editable through this flow.');

        $target = School::whereIn('uuid', $data['schools'] ?? [])->get();
        $current = $user->schools()->get();

        DB::transaction(function () use ($user, $target, $current, $role) {
            foreach ($target as $school) {
                if (! $current->contains('id', $school->id)) {
                    $user->grantSchoolAccess($school, $role);
                }
            }

            foreach ($current as $school) {
                if (! $target->contains('id', $school->id)) {
                    $user->revokeSchoolAccess($school, $role);
                }
            }

            // Keep the fallback school_id pointing at a school they can access.
            if (! $target->contains('id', $user->school_id)) {
                $user->forceFill(['school_id' => $target->first()?->id])->save();
            }
        });

        return back()->with('success', 'School access updated.');
    }
}
