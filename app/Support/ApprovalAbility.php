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
     * The DECLARED EXCEPTIONS to the `.submit` derivation: checker => its real maker.
     *
     * The derivation below replaces a checker's terminal segment with `submit`, which is right
     * wherever the maker was named for the convention. It is wrong wherever the maker already had a
     * name — `finance.invoice.approve`'s maker is `finance.invoice.generate`, and there is no
     * `finance.invoice.submit` to derive.
     *
     * THE COST OF THIS MAP IS THAT IT IS A LIST, and a list goes stale in a direction the
     * derivation cannot: rename a permission and the map keeps naming the old one, restoring
     * exactly the inert pair it was added to prevent. That is why it is only payable alongside
     * the assertion that already landed for it — `GrantsMapSeparationTest`'s "any maker-override
     * map on ApprovalAbility names only real permissions on BOTH sides", which was written before
     * this map existed, names it, and reds on an unrecognised constant so a second map cannot
     * arrive unasserted.
     *
     * @var array<string, string>
     */
    public const MAKER_OVERRIDES = [
        'finance.invoice.approve' => 'finance.invoice.generate',
    ];

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

        // The declared exception wins over the derivation — consulted FIRST, so a checker whose
        // maker already had a name never reaches the string surgery below and never yields a pair
        // naming a permission nobody can hold.
        if (array_key_exists($ability, self::MAKER_OVERRIDES)) {
            return self::MAKER_OVERRIDES[$ability];
        }

        $position = strrpos($ability, '.');

        return $position === false
            ? 'submit'
            : substr($ability, 0, $position + 1).'submit';
    }
}
