<?php

namespace App\Services\Rollover;

use App\Models\Curriculum;
use Illuminate\Support\Collection;

/**
 * What a rollover would do, and whether it may run — computed once, then either shown or dispatched.
 *
 * ── THIS IS THE SEAM, NOT THE BATCH NAME ─────────────────────────────────────────────────────────
 * Slice 2's UI pre-flight binds to THIS: the selection it lists, the ring it renders, the CCM
 * offenders it links to. Returned as a loose array it would be reshaped at the controller boundary,
 * and a reshape is exactly where "one definition" quietly becomes two — the CLI reading one set of
 * keys and the UI another, drifting the first time a field is added. Named fields, one type, both
 * callers bound to the same thing.
 *
 * ── DISPATCH CONSUMES A PLAN; IT DOES NOT RE-PLAN ────────────────────────────────────────────────
 * The CLI computes and dispatches in one call, so nothing observable sits between them. The UI does
 * not: preview -> the operator reads it -> the operator confirms -> dispatch. If dispatch re-planned
 * at that point, a reassignment (or an enrolment, or a curriculum closing) between preview and
 * confirm would silently change what runs versus what was shown — a TOCTOU over an entire year
 * group, discovered afterwards or not at all.
 *
 * So the planner returns a plan and the dispatcher takes one. That API shape is decided HERE, in
 * slice 1, because slice 2 cannot introduce it later without rewriting both commands: a signature
 * that accepts the inputs and re-derives internally forecloses the safe shape, and one that accepts
 * a plan permits it. The CLI gains nothing from this today and pays nothing for it either.
 *
 * ── WHY `blockedBy` IS A LIST AND NOT A BOOLEAN ──────────────────────────────────────────────────
 * A registrar needs to know WHICH rule stopped them and what to do about it, and both gates can be
 * violated at once. A boolean would force the caller to re-evaluate the gates to say anything
 * useful, which is the duplication this class exists to prevent.
 */
final class RolloverPlan
{
    /**
     * @param  'end-of-term'|'end-of-year'  $kind
     * @param  Collection<int, Curriculum>  $curricula  what would be migrated, CCM already excluded
     * @param  bool  $progressionCheckRan  whether the cycle gate was EVALUATED at all
     * @param  list<string>|null  $progressionCycle  the named ring from ProgressionGraph::findCycle,
     *                                               level names in walk order with the entry
     *                                               repeated; null when the graph is acyclic. Read
     *                                               from the walk, never re-formatted here.
     * @param  Collection<int, Curriculum>  $ccmBlockers  CCM curricula that must be moved first
     * @param  list<string>  $warnings  non-blocking, operator-facing (e.g. a level whose final slot
     *                                  has no term in this session, or a batch still draining)
     * @param  list<string>  $blockedBy  gate identifiers: 'progression-cycle', 'ccm-active'
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $schoolId,
        public readonly string $batchName,
        public readonly Collection $curricula,
        public readonly int $pupilCount,
        public readonly bool $progressionCheckRan,
        public readonly ?array $progressionCycle,
        public readonly Collection $ccmBlockers,
        public readonly array $warnings,
        public readonly array $blockedBy,
    ) {}

    /**
     * May this plan be dispatched?
     *
     * Derived from `blockedBy` rather than stored, so a plan cannot be constructed claiming to be
     * runnable while naming a gate that stopped it.
     */
    public function isRunnable(): bool
    {
        return $this->blockedBy === [];
    }

    /**
     * The cycle gate ran and found nothing.
     *
     * ── WHY THIS EXISTS: `null` WAS CARRYING TWO MEANINGS ────────────────────────────────────────
     * `ProgressionGraph::findCycle` returns null for ACYCLIC, and an end-of-term plan never runs the
     * check at all — so `progressionCycle === null` meant "the graph is fine" on one kind and "we
     * never looked" on the other. Indistinguishable without branching on `kind`, which is precisely
     * the coupling the DTO exists to remove: the UI would have had to know which rollover kinds
     * evaluate which gates in order to render a result correctly.
     *
     * That is the same "one representation, two meanings" defect as the `blockedBy`-versus-raw-field
     * split found by mutation one field over, and it is worth more caught here — before slice 2
     * binds — than after a screen has already been written against the ambiguity.
     *
     * The three states are now distinct and readable without knowing anything about rollover kinds:
     *   ran=false, cycle=null  -> not applicable  (end-of-term)
     *   ran=true,  cycle=null  -> checked, acyclic
     *   ran=true,  cycle=[…]   -> checked, ring found
     */
    public function progressionIsAcyclic(): bool
    {
        return $this->progressionCheckRan && $this->progressionCycle === null;
    }

    /**
     * Nothing to migrate — a legitimate outcome, and NOT a block. "No active non-CCM curricula in
     * this term" is success with zero work, which the CLI already reports as such; conflating it
     * with a gate failure would tell a registrar a rule stopped them when none did.
     */
    public function isEmpty(): bool
    {
        return $this->curricula->isEmpty();
    }
}
