<?php

namespace App\Support;

/**
 * Which abilities are READ-ONLY — the convention `admin_viewer` is derived through.
 *
 * THE RULE IS A CONVENTION, NOT A LIST, for the same reason {@see ApprovalAbility}'s is: a
 * hand-written set of "the safe ones" drifts the moment a permission is coined, and it drifts
 * SILENTLY, because nothing goes red when a new write permission is simply absent from a list of
 * reads. Here the rule is one predicate over the ability's terminal segment:
 *
 *     an ability is read-only iff its terminal segment is `view` or begins with `view_`.
 *
 *     activity_log.view              → read
 *     activity_log.view_sensitive    → read
 *     guardian.view_audit            → read
 *     result.view_scores             → read
 *     view_behavioral_assessments    → read   (no dot: the whole name is its terminal segment)
 *     admin_area.view                → read
 *     activity_log.export            → NOT read
 *     academic_setup.manage          → NOT read
 *     finance.credit-note.approve    → NOT read
 *
 * IT IS FAIL-CLOSED, AND THAT IS THE DESIGN. Anything the predicate does not RECOGNISE is treated
 * as a write, so a permission coined tomorrow with a segment nobody anticipated is excluded from
 * every read-only role by default. The failure mode of a fail-open rule here is a read-only seat
 * silently acquiring a write; the failure mode of this one is a read-only seat missing a read,
 * which somebody notices and fixes deliberately.
 *
 * ─── `.access` IS DELIBERATELY NOT A READ SEGMENT, AND IT IS THE WHOLE REASON THIS FILE EXISTS ───
 *
 * The obvious version of this convention treats an area gate (`admin_area.access`,
 * `finance.access`, `result_review.access`) as a read, since a gate "only lets you in". That
 * reading is FALSE HERE, measured rather than reasoned: on 2026-09-06 `route:list` showed
 * `admin_area.access` as the sole guard on 18 write routes — including POST/PUT `/students` and
 * POST `/api/guardians/{guardian}/password` — because the group it gates is flat and mixes 22 GET
 * routes with those 18. A name-shaped rule cannot see that; only the route table can.
 *
 * So an area gate never earns read-only status BY NAME. It earns it by somebody coining an explicit
 * read-only door and widening exactly the GET routes onto it — which is what
 * {@see \App\Enums\Permission::ADMIN_AREA_VIEW} is, and why it is the one member of `admin_viewer`
 * that cannot be derived from `admin` (admin does not hold it; the OR-gate means it does not need
 * to).
 *
 * THIS PREDICATE IS A CONSTRUCTION RULE, NOT THE GUARANTEE. It decides what goes INTO the role;
 * it cannot promise that what went in is harmless, because that is a fact about routes and not
 * about names. The guarantee is asserted separately and by an independent path —
 * `AdminViewerHoldsNoWriteGateTest` walks the LIVE route table and fails if any ability
 * `admin_viewer` holds unlocks a non-GET route. Read that test as the property; read this as the
 * derivation.
 */
final class ReadOnlyAbility
{
    /**
     * Is $ability a read, under the convention above?
     */
    public static function isReadOnly(string $ability): bool
    {
        $segment = ApprovalAbility::terminalSegment($ability);

        return $segment === 'view' || str_starts_with($segment, 'view_');
    }

    /**
     * The read-only members of $abilities, order preserved, keys discarded.
     *
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function filter(array $abilities): array
    {
        return array_values(array_filter($abilities, self::isReadOnly(...)));
    }
}
