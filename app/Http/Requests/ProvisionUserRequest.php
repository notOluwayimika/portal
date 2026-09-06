<?php

namespace App\Http\Requests;

use App\Exceptions\DutySeparationViolationException;
use App\Models\Role;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Support\DutySeparation;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The super-admin user-provisioning flow: create (or extend) an account and give it a role in one
 * or more schools.
 *
 * WHAT THIS REQUEST IS FOR. Every role Brookstone runs on has existed and been seeded for months;
 * what did not exist was a way to put a PERSON in one of those seats. `SuperAdmin\AdminController`
 * could mint an `admin` and nothing else, so `executive_director` — sole checker on all five built
 * finance pairs — was assignable through no surface at all. This generalises that one flow rather
 * than adding a second: same actor gate, same primitive (`User::grantSchoolAccess`), same duty
 * separation.
 *
 * ─── THE STRUCTURAL REFUSAL (ADR 0040 mechanism 2) ───────────────────────────────────────────────
 *
 * A single user must not end up holding both sides of a maker-checker pair. Three layers stand
 * between a request and that state, and this file is only the outermost:
 *
 *   1. the database `CHECK (submitted_by <> decided_by)` — absolute, act-level, the backstop;
 *   2. `User::assignRole` (:440) calls `DutySeparation::assertAssignmentAllowed` and THROWS before
 *      the spatie write, so no call site — HTTP, programmatic or seeder — can bypass it;
 *   3. this request, which asks the SAME question before the controller is entered at all.
 *
 * ─── HOW MUCH LAYER 3 IS ACTUALLY WORTH, MEASURED RATHER THAN ASSERTED ───────────────────────────
 *
 * Less than it first appears, and saying so here is cheaper than someone discovering it later.
 * Layer 3 was bite-proven by deleting its call: the provisioning suite stayed 12/12 GREEN. The
 * reason is that `bootstrap/app.php:145 (DutySeparationViolationException)` ALREADY renders that
 * exception as a redirect-back-with-errors keyed on `roles` — the identical shape this produces — so
 * layer 2's throw is indistinguishable from layer 3's refusal at the HTTP boundary, and the
 * controller's `DB::transaction` rolls back whatever the loop had written. On observable behaviour
 * the two layers agree.
 *
 * The original version of this docblock claimed layer 3 was what stopped a half-provisioned
 * account. That was FALSE once the transaction landed, and it is the kind of claim that reads as
 * verification and stops anyone looking. What layer 3 genuinely buys is narrower and real:
 *
 *   · the conflict is decided BEFORE any write is attempted, so no `User` row is inserted and
 *     rolled back — no `created` model event fires, no listener runs, no id is consumed. That is
 *     the ONE difference an assertion can see, and `ProvisioningDutySeparationTest`'s
 *     "never reaches the controller" arm is built on exactly it, because every other arm in that
 *     file passes with layer 3 deleted.
 *   · it evaluates every requested school, rather than stopping at the first one the loop reaches.
 *
 * Keep both layers. Layer 2 is the one that cannot be bypassed and is therefore the load-bearing
 * one; layer 3 is the one that refuses cleanly.
 *
 * ONE DEFINITION OF THE CONFLICT, and it is deliberately not a list of role names. The check calls
 * `DutySeparation::assertRoleSetAllowed()` — the same primitive `User::assignRole` reaches through
 * `assertAssignmentAllowed`, and the same one the RBAC matrix's grant guard shares
 * (SyncRolePermissionsRequest:112, via `violationsFromRolePermissionSync`). It resolves each role
 * to its PERMISSION set and compares against `enforcedPairs()`, itself derived from the Permission
 * catalog by the `approve`/`reject` terminal-segment convention. So a pair split across two roles
 * this file has never heard of is refused with no edit here — pinned by
 * `ProvisioningDutySeparationTest`'s throwaway-roles arm.
 *
 * IT FIRES ON BOTH PATHS. The resulting role set is `roles they already hold in that school` ∪
 * `roles requested`, evaluated per school. A create carrying a maker AND a checker is caught
 * because the requested half alone violates; an EXISTING user holding the checker who is now
 * offered the maker is caught because the union does. Neither needs its own branch, and on the
 * second path nothing about their existing roles is touched.
 */
class ProvisionUserRequest extends FormRequest
{
    /**
     * Roles this surface may NEVER assign, whatever the actor.
     *
     * `super_admin` is platform authority, not a seat in a school: it is granted by the
     * `2026_07_12_000004_seed_first_super_admin` migration and by console, and it is the one role
     * whose holder bypasses authorization entirely. Mirrors SyncUserRolesRequest's D1, and like D1
     * it is STRUCTURAL rather than a permission check — the only actors here are super admins, whom
     * `Gate::before` passes every permission, so a permission-shaped guard would constrain nobody.
     */
    public const NEVER_ASSIGNABLE = ['super_admin'];

    /**
     * The assignable set: every seeded role except {@see NEVER_ASSIGNABLE}.
     *
     * DERIVED FROM `RbacSeeder::ROLES` rather than written out, so a seat added to the platform is
     * assignable without a second edit here — the failure mode of a hand-listed copy is a role that
     * exists, is seeded, and can be given to nobody, which is the exact hole `executive_director`
     * sat in. The safety of deriving rests on the exclusion above being the only thing that must
     * never join, and `UserProvisioningTest` pins both directions: super_admin is absent, and every
     * other seeded role is present.
     *
     * @return list<string>
     */
    public static function assignableRoles(): array
    {
        return array_values(array_diff(RbacSeeder::ROLES, self::NEVER_ASSIGNABLE));
    }

    public function authorize(): bool
    {
        // The route group already gates on role:super_admin; this repeats it as defence in depth,
        // the same way SyncRolePermissionsRequest:21 does, so the endpoint is not one refactor away
        // from unguarded.
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // NOT `unique:users,email`. An existing address is the cross-school staffer who already
            // has an account — the flow ATTACHES to them rather than refusing or minting a
            // duplicate. See {@see targetUser()} and AdminController::store().
            'email' => ['required', 'email', 'max:255'],
            // Required only when the address is new; an existing account keeps its own password and
            // this flow must never silently reset one.
            'password' => [Rule::requiredIf(fn () => ! $this->targetUser()), 'nullable', 'string', 'min:8'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(self::assignableRoles())],
            'schools' => ['required', 'array', 'min:1'],
            'schools.*' => ['uuid', 'exists:schools,uuid'],
        ];
    }

    /** The existing account for this email, if any. Null means "create". */
    public function targetUser(): ?User
    {
        $email = $this->input('email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        // withoutGlobalScope: the address may belong to a staffer in another school entirely, which
        // is the whole point of the existing-email path.
        return User::withoutGlobalScope(SchoolScope::class)
            ->where('email', $email)
            ->first();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                // The checks below resolve schools and roles from the input; running them on input
                // that failed its own rules produces confusing secondary errors.
                return;
            }

            $target = $this->targetUser();

            // STRUCTURAL, on the target rather than on a permission: a super_admin's roles are not
            // editable through a School-shaped surface. Holds with the Gate::before bypass on.
            if ($target?->isSuperAdmin()) {
                $v->errors()->add('email', 'This account is a super admin; its roles are not editable through this flow.');

                return;
            }

            $this->assertNoDutySeparationViolation($v, $target);
        });
    }

    /**
     * Refuse — before anything is written — any assignment that would leave one user holding both
     * sides of an enforced maker-checker pair in one school.
     */
    private function assertNoDutySeparationViolation(Validator $v, ?User $target): void
    {
        /** @var list<string> $requested */
        $requested = array_values(array_filter((array) $this->input('roles', []), 'is_string'));

        $schools = School::whereIn('uuid', (array) $this->input('schools', []))->get();

        // `->` and not `?->`: null-coalescing already suppresses the access on null, so the
        // nullsafe is redundant and Larastan says so (nullsafe.neverNull).
        $label = $target->email ?? (string) $this->input('email');

        foreach ($schools as $school) {
            // The roles they ALREADY hold in THIS school. A new account holds none, so the same
            // call covers the create path without a branch. Read through the spatie team context,
            // which is how every other reader of this relation resolves it.
            $existing = [];

            if ($target !== null) {
                setPermissionsTeamId($school->id);
                $target->unsetRelation('roles')->unsetRelation('permissions');
                $existing = $target->roles()->pluck('name')->all();
            }

            try {
                DutySeparation::assertRoleSetAllowed(
                    $label,
                    (int) $school->id,
                    array_values(array_unique([...$existing, ...$requested])),
                );
            } catch (DutySeparationViolationException $e) {
                // The exception's own message already names the pair, both sides, the roles
                // carrying each, and the school — it was written to be actionable (see its
                // docblock), so it is surfaced verbatim rather than paraphrased into something
                // that would drift from the one the throwing layer produces.
                $v->errors()->add('roles', $e->getMessage());

                // ALSO keyed by position where the requested set carries a side, so the console can
                // put the error on the offending chip — the same two-key convention
                // SyncRolePermissionsRequest:96-102 uses.
                foreach ($this->rolesCarrying($requested, $e->pair) as $index) {
                    $v->errors()->add("roles.{$index}", $e->getMessage());
                }

                return;
            }
        }
    }

    /**
     * Positions in $requested whose role carries either side of $pair.
     *
     * @param  list<string>  $requested
     * @param  array{checker: string, maker: string}  $pair
     * @return list<int>
     */
    private function rolesCarrying(array $requested, array $pair): array
    {
        $out = [];

        foreach ($requested as $index => $role) {
            $abilities = Role::where('name', $role)
                ->where('guard_name', RbacSeeder::GUARD)
                ->whereNull('school_id')
                ->with('permissions')
                ->first()?->permissions->pluck('name')->all() ?? [];

            if (in_array($pair['checker'], $abilities, true) || in_array($pair['maker'], $abilities, true)) {
                $out[] = $index;
            }
        }

        return $out;
    }
}
