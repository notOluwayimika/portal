<?php

namespace App\Support;

/**
 * Which abilities are excluded from the `super_admin` Gate::before bypass
 * (ADR 0040: "super_admin never overrides maker–checker").
 *
 * THE RULE IS A CONVENTION, NOT A LIST. Any ability whose terminal segment is
 * `approve` or `reject` is a checker action and is never bypassed:
 *
 *     result.approve            → excluded
 *     result.reject             → excluded
 *     finance.invoice.approve   → excluded on the day it is created
 *     approve / reject          → excluded (bare Policy ability names)
 *     result.view_scores        → NOT excluded
 *     student_curriculum.promote→ NOT excluded
 *
 * ADR 0040 words the exclusion as `finance.*.approve`, because it was written
 * against the Ph3 Finance approvals engine. `result.approve` / `result.reject`
 * (ADR 0044) do not match that pattern, so a literal list or a `finance.`
 * prefix match would have shipped the denylist-drift bug in the very first
 * implementation — the academic approvals would sit outside the exclusion the
 * ADR exists to guarantee. The convention closes that by construction:
 * SuperAdminBypassExclusionTest enumerates App\Enums\Permission and asserts
 * every terminally-approve/reject case is excluded, so a future
 * `finance.invoice.approve` is covered without anyone remembering anything.
 *
 * Scope note: this governs only the *bypass*. It is one of the two mechanisms
 * ADR 0040 requires and does not, by itself, enforce maker ≠ checker — that is
 * structural (`decided_by <> submitted_by` at Policy + DB, see
 * SubjectResultPolicy and the subject_result_statuses CHECK constraint). Each
 * covers what the other cannot: without the exclusion a super admin approves
 * anything; without the structure, any single identity holding both sides
 * approves its own work.
 */
class ApprovalAbility
{
    /** Terminal segments that mark an ability as a checker action. */
    public const CHECKER_SEGMENTS = ['approve', 'reject'];

    /**
     * Is this ability excluded from the super-admin bypass?
     */
    public static function isExcludedFromSuperAdminBypass(string $ability): bool
    {
        return in_array(self::terminalSegment($ability), self::CHECKER_SEGMENTS, true);
    }

    /**
     * The part after the last dot — `finance.invoice.approve` → `approve`,
     * and a bare Policy ability name (`approve`) is its own terminal segment.
     */
    public static function terminalSegment(string $ability): string
    {
        $position = strrpos($ability, '.');

        return $position === false ? $ability : substr($ability, $position + 1);
    }

    /**
     * The maker ability matching a checker ability — same prefix, terminal
     * `submit`: `result.approve` → `result.submit`,
     * `finance.invoice.reject` → `finance.invoice.submit`. Null when the
     * given ability is not a checker action.
     *
     * Used by the C6 matrix's grant-time SoD guard (no role may end up
     * holding a checker together with its matching maker) — convention, not
     * a pair list, so a future finance.invoice.submit/approve pair is
     * covered the day it exists.
     */
    public static function matchingMakerFor(string $ability): ?string
    {
        if (! self::isExcludedFromSuperAdminBypass($ability)) {
            return null;
        }

        $position = strrpos($ability, '.');

        return $position === false
            ? 'submit'
            : substr($ability, 0, $position + 1).'submit';
    }
}
