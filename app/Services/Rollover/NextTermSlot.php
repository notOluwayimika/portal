<?php

namespace App\Services\Rollover;

use App\Enums\TermStatusEnum;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\Scopes\SchoolScope;
use App\Models\Term;

/**
 * Where a curriculum's roster goes at the end of its term — or why it goes nowhere.
 *
 * ── ONE DEFINITION, TWO CALLERS, AND THE SECOND ONE IS WHY THIS EXISTS ───────────────────────────
 * `MoveFromTermJob` has always resolved this correctly and no-opped when there was no next slot,
 * which is right per class level: a level running slots 1-2 simply stops at the end of slot 2.
 *
 * `RolloverPlanner` did NOT know about it. It selected on (school, term, status=active) alone, so an
 * end-of-term rollover on the session's LAST term promised "12 classes, 340 pupils would move",
 * queued twelve jobs, every one no-opped, and the batch reported 12/12 succeeded. A whole-school
 * silent no-op reported as complete success — and preview/commit count-honesty cannot catch it,
 * because the plan and the commit agree perfectly. They are both wrong the same way.
 *
 * The fix is not a second "will this move?" query in the planner. A second implementation would
 * drift from the job's, and the drift is invisible: the screen would promise a move the job then
 * declines, exactly the failure this class removes. So the resolution lives here and BOTH call it.
 *
 * ── THE REASON IS PART OF THE ANSWER, NOT A LOG LINE ─────────────────────────────────────────────
 * There are five distinct ways to end up with nowhere to go, and they mean different things to an
 * operator: "this level finishes here" is normal, while "the level participates in a later slot but
 * no Term row exists for it" is a configuration hole someone must fix. The job logs them; the
 * planner shows them. Returning the reason rather than a bare null is what lets one computation
 * serve both.
 */
final class NextTermSlot
{
    /** Resolved: there is a target term to move into. */
    public const OK = 'ok';

    /** The level's participation ends at this slot. Normal — this level finishes here. */
    public const NO_LATER_SLOT = 'no-later-slot';

    /** The level participates later, but the session has no Term row at that order. Config hole. */
    public const NO_TERM_AT_ORDER = 'no-term-at-order';

    /** The target term exists but is not upcoming/active — refused rather than promoted into. */
    public const TARGET_TERM_NOT_OPEN = 'target-term-not-open';

    /** The curriculum has no source term or no class-level arm — it cannot be placed at all. */
    public const UNPLACEABLE = 'unplaceable';

    private function __construct(
        public readonly string $reason,
        public readonly ?Term $term = null,
        public readonly bool $isCcm = false,
        public readonly ?int $termOrder = null,
    ) {}

    public function resolved(): bool
    {
        return $this->reason === self::OK;
    }

    /**
     * The next participating slot for this curriculum's class level, within the SAME session.
     *
     * Scopes are dropped deliberately: this is called from a job with no ambient school as well as
     * from a request that has one, and the school is pinned explicitly from the caller's own
     * `$schoolId` rather than inferred (Constitution 13).
     */
    public static function for(Curriculum $curriculum, int $schoolId): self
    {
        $sourceTerm = Term::withoutGlobalScope(SchoolScope::class)->find($curriculum->term_id);

        if ($sourceTerm === null) {
            return new self(self::UNPLACEABLE);
        }

        $classLevelArm = $curriculum->classLevelArm()->withoutGlobalScope(SchoolScope::class)->first();

        if ($classLevelArm === null) {
            return new self(self::UNPLACEABLE);
        }

        $next = ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->where('class_level_id', $classLevelArm->class_level_id)
            ->where('term_order', '>', (int) $sourceTerm->order)
            ->orderBy('term_order')
            ->first();

        if ($next === null) {
            return new self(self::NO_LATER_SLOT);
        }

        $targetTerm = Term::withoutGlobalScope(SchoolScope::class)
            ->where('academic_session_id', $sourceTerm->academic_session_id)
            ->where('order', $next->term_order)
            ->first();

        if ($targetTerm === null) {
            return new self(self::NO_TERM_AT_ORDER, termOrder: (int) $next->term_order);
        }

        // ALLOWLIST, not "anything but completed". `upcoming` is the NORMAL case — at the close of
        // term N its successor has not started — and `active` covers running slightly late, which is
        // still a forward move. Everything else is refused, so a status added to TermStatusEnum later
        // is rejected by default rather than silently accepted as a promotion target.
        if (! in_array($targetTerm->status, [TermStatusEnum::UPCOMING, TermStatusEnum::ACTIVE], true)) {
            return new self(self::TARGET_TERM_NOT_OPEN, term: $targetTerm, termOrder: (int) $next->term_order);
        }

        return new self(self::OK, term: $targetTerm, isCcm: (bool) $next->is_ccm, termOrder: (int) $next->term_order);
    }

    /** Operator-facing, for the plan. The job logs its own structured version. */
    public function explain(): string
    {
        return match ($this->reason) {
            self::NO_LATER_SLOT => 'this class level has no later term slot — it finishes here',
            self::NO_TERM_AT_ORDER => "the class level participates in slot {$this->termOrder}, but this session has no term at that order",
            self::TARGET_TERM_NOT_OPEN => 'the next term is not upcoming or active',
            self::UNPLACEABLE => 'the curriculum has no term or no class-level arm',
            default => 'resolved',
        };
    }
}
