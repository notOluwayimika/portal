<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

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
