<?php

namespace App\Support;

use App\Enums\Permission;
use App\Exceptions\DutySeparationViolationException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Segregation-of-duties DETECTION (never enforcement). The act-level guarantee — no one approves a
 * request they submitted — is absolute and lives in the database (`CHECK (submitted_by <>
 * decided_by)` on every approval table). This class detects the softer, CAPABILITY-level problem
 * that CHECK cannot: a single user holding BOTH sides of a maker-checker pair, a setup that reads
 * as segregated but lets one person approve a colleague's work in both directions.
 *
 * THE RULE (stated once, so the invariant test and the audit command agree): for every checker
 * ability C, with maker M = ApprovalAbility::matchingMakerFor(C), a user holding BOTH C and M
 * WITHIN THE SAME SCHOOL is a violation. Derived from the ApprovalAbility convention over the
 * Permission catalog — never an enumerated list — so a future instance (refunds) joins with no
 * edit, the same property the D1 route gate has.
 *
 * EVALUATED ON EFFECTIVE ABILITY (`$user->can()`), not raw grant. This is load-bearing for
 * super_admin: it is excluded from the Gate::before bypass on checker abilities (ADR 0040), so it
 * can hold a maker effectively (bypass) but NEVER a checker — and therefore can never be both-sides.
 * A raw-grant reading would misreport the platform administrator forever; an effective reading
 * never does. Scoped PER SCHOOL (spatie teams, team_foreign_key = school_id): a maker at school A
 * and a checker at school B share no record on which both apply, so that is not a violation.
 *
 * This class is DEFINITION + DETECTION only. It revokes nothing and refuses nothing.
 */
final class DutySeparation
{
    /**
     * The maker-checker pairs, derived from the convention over the Permission catalog.
     *
     * @return list<array{checker: string, maker: string}>
     */
    public static function pairs(): array
    {
        $pairs = [];
        foreach (Permission::cases() as $case) {
            $ability = $case->value;
            if (! ApprovalAbility::isExcludedFromSuperAdminBypass($ability)) {
                continue; // not a checker action
            }
            $maker = ApprovalAbility::matchingMakerFor($ability);
            if ($maker !== null) {
                $pairs[] = ['checker' => $ability, 'maker' => $maker];
            }
        }

        return $pairs;
    }

    /**
     * Does $user EFFECTIVELY hold $ability within $schoolId? Sets the spatie team, clears the
     * cached role/permission relations (a prior team's cache would otherwise answer), and asks the
     * Gate — so the super-admin bypass and its checker-exclusion are honoured exactly as at a real
     * check. The team is left set to $schoolId; callers iterating many schools set it each turn.
     */
    public static function holds(User $user, int $schoolId, string $ability): bool
    {
        setPermissionsTeamId($schoolId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user->can($ability);
    }

    /**
     * The pairs that are ENFORCED at grant time (Decision 0). Everything else in `pairs()` is
     * detection-only. THE SCOPE BOUNDARY IS THIS ONE LINE:
     *
     *     Finance pairs only — widen to `pairs()` (all, incl. result) ONLY when the result
     *     workstream's own audit is clean AND they have agreed to enforcement on the record.
     *
     * Kept as one obvious, commented line so widening the blast radius later is a one-line change
     * and is visibly a one-line change — not a condition scattered through the guards.
     *
     * @return list<array{checker: string, maker: string}>
     */
    public static function enforcedPairs(): array
    {
        return array_values(array_filter(
            self::pairs(),
            fn (array $pair) => str_starts_with($pair['checker'], 'finance.'),
        ));
    }

    /**
     * GRANT-TIME ENFORCEMENT (throws before the write). If assigning $newRoles to $user in $schoolId
     * would leave them holding BOTH sides of an ENFORCED (Finance) pair — counting the roles they
     * already hold there PLUS the new ones — throw {@see DutySeparationViolationException}. The check
     * is on the RESULTING role set, so it catches both directions (grant a checker to a maker-holder
     * and the mirror) and, because callers invoke it BEFORE the spatie write, refuses WHOLESALE:
     * nothing lands. Result (and any non-Finance) pairs are never refused here — detection only.
     *
     * @param  array<int, mixed>  $newRoles  role names or Role objects being assigned
     */
    public static function assertAssignmentAllowed(User $user, int $schoolId, array $newRoles): void
    {
        setPermissionsTeamId($schoolId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $newNames = collect($newRoles)->flatten()
            ->map(fn ($r) => is_string($r) ? $r : ($r->name ?? null))
            ->filter()->values();

        $currentNames = $user->roles()->pluck('name');
        self::assertRoleSetAllowed(
            $user->email ?? ('user#'.$user->id),
            $schoolId,
            $currentNames->merge($newNames)->unique()->all(),
        );
    }

    /**
     * The core enforcement primitive, shared by the assignment guard and the role-permission-sync
     * guard (Decision 1: one rule, one implementation, invoked by each path). Given a LABEL, a
     * school, and the FULL set of role names a user would end up holding there, throw if that set
     * grants both sides of an enforced pair. Naming both roles per side keeps the error actionable.
     *
     * @param  list<string>  $roleNames
     */
    public static function assertRoleSetAllowed(string $userLabel, int $schoolId, array $roleNames): void
    {
        $roleAbilities = self::abilitiesByRole($roleNames);
        $all = collect($roleAbilities)->flatten()->unique();

        foreach (self::enforcedPairs() as $pair) {
            if ($all->contains($pair['checker']) && $all->contains($pair['maker'])) {
                throw new DutySeparationViolationException(
                    $userLabel,
                    $schoolId,
                    $pair,
                    self::rolesCarrying($roleAbilities, $pair['checker']),
                    self::rolesCarrying($roleAbilities, $pair['maker']),
                );
            }
        }
    }

    /**
     * ENFORCEMENT for the RBAC matrix (role→permission SYNC path). Editing $roleName's permission set
     * to $requestedAbilities must not leave any MEMBER of that role holding both sides of an enforced
     * pair — counting, per school, the member's OTHER roles PLUS the role's requested abilities. Only
     * pairs whose OPPOSITE side arrives from another role are reported (the requested set carries
     * exactly one side): the same-role both-sides case is the role-level guard's, and a member already
     * both-sides via two OTHER roles is the audit's finding, not this edit's to block. Returns the
     * violations as DATA (not throwing) so the FormRequest surfaces them as validation errors — the
     * same wholesale, pre-write refuse as the role-level guard. Finance pairs only (Decision 0).
     *
     * @param  array<array-key, mixed>  $requestedAbilities  raw request input — filtered to strings here
     * @return list<array{userLabel: string, schoolId: int, pair: array{checker: string, maker: string}}>
     */
    public static function violationsFromRolePermissionSync(string $roleName, array $requestedAbilities): array
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
        if ($role === null) {
            return [];
        }

        $requested = collect($requestedAbilities)->filter(fn ($a) => is_string($a))->unique();

        // Only pairs the edit could newly cause: the requested set carries EXACTLY ONE side, so a
        // violation needs the OTHER side from another role. Pairs where requested carries both sides
        // belong to the role-level guard; pairs it carries neither of it cannot introduce.
        $relevant = array_values(array_filter(
            self::enforcedPairs(),
            fn (array $pair) => $requested->contains($pair['checker']) !== $requested->contains($pair['maker']),
        ));
        if ($relevant === []) {
            return [];
        }

        $teamKey = config('permission.column_names.team_foreign_key');
        $table = config('permission.table_names.model_has_roles');
        $morphKey = config('permission.column_names.model_morph_key');

        $members = DB::table($table)
            ->where('model_type', User::class)
            ->where('role_id', $role->id)
            ->whereNotNull($teamKey)
            ->get([$morphKey.' as model_id', $teamKey.' as school_id']);

        $labels = User::whereIn('id', $members->pluck('model_id')->unique())->pluck('email', 'id');

        $out = [];
        foreach ($members as $m) {
            // The member's OTHER roles in this school — the edited role is replaced by $requested.
            $otherRoleIds = DB::table($table)
                ->where('model_type', User::class)
                ->where($morphKey, $m->model_id)
                ->where($teamKey, $m->school_id)
                ->where('role_id', '!=', $role->id)
                ->pluck('role_id');
            $otherNames = Role::whereIn('id', $otherRoleIds)->pluck('name')->all();

            $resulting = collect(self::abilitiesByRole($otherNames))->flatten()->merge($requested)->unique();

            foreach ($relevant as $pair) {
                if ($resulting->contains($pair['checker']) && $resulting->contains($pair['maker'])) {
                    $out[] = [
                        'userLabel' => $labels[$m->model_id] ?? ('user#'.$m->model_id),
                        'schoolId' => (int) $m->school_id,
                        'pair' => $pair,
                    ];
                }
            }
        }

        return $out;
    }

    /** @param list<string> $roleNames @return array<string, list<string>> roleName => permission names */
    private static function abilitiesByRole(array $roleNames): array
    {
        $roles = Role::query()->whereIn('name', $roleNames)->where('guard_name', 'web')->with('permissions')->get();

        $out = [];
        foreach ($roles as $role) {
            $out[$role->name] = $role->permissions->pluck('name')->all();
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $roleAbilities
     * @return list<string>
     */
    private static function rolesCarrying(array $roleAbilities, string $ability): array
    {
        return array_keys(array_filter(
            $roleAbilities,
            fn (array $abilities) => in_array($ability, $abilities, true),
        ));
    }

    /**
     * Does $user hold $ability via an actual GRANT (a role), ignoring the super-admin Gate::before
     * bypass? Used by the STAFFING readiness check, where the question is "does the school have real
     * staff who can operate this side" — and super_admin, which holds every maker effectively via
     * the bypass but is a platform admin rather than school staff, must NOT count. (The both-sides
     * AUDIT uses {@see holds} / effective ability instead, so it never misreports super_admin as a
     * violator; staffing uses this raw lens so it never miscounts super_admin as an operator.)
     */
    public static function holdsViaGrant(User $user, int $schoolId, string $ability): bool
    {
        setPermissionsTeamId($schoolId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user->hasPermissionTo($ability);
    }

    /**
     * The pairs $user holds BOTH sides of within $schoolId — empty when clean.
     *
     * @return list<array{checker: string, maker: string}>
     */
    public static function violations(User $user, int $schoolId): array
    {
        return array_values(array_filter(
            self::pairs(),
            fn (array $pair) => self::holds($user, $schoolId, $pair['checker'])
                && self::holds($user, $schoolId, $pair['maker']),
        ));
    }
}
