<?php

namespace App\Services\Rollover;

use App\Models\ClassLevelArm;
use App\Models\Curriculum;

/**
 * Where one pupil lands at the end of a year — or why they land nowhere.
 *
 * The end-of-year sibling of {@see NextTermSlot}, and it exists for the same reason: a resolution
 * that only the job knew is now needed by the planner too, and a SECOND implementation of it would
 * drift invisibly. The planner would promise a placement the job then computed differently, and both
 * would report success — which is precisely the shape of the last-term defect (the screen promised
 * "12 classes, 340 pupils", twelve jobs no-opped, the batch reported 12/12 succeeded).
 *
 * ── THIS IS THE RESULT; {@see NextYearPlacementResolver} COMPUTES IT ─────────────────────────────
 * Split in two because the resolution needs a context (source curriculum, school, target session)
 * that is constant across every pupil in a curriculum, while the result is per pupil. Folding both
 * into static factories produced seven-parameter calls at both call sites. The result object is
 * shaped on NextTermSlot — reason constants, `resolved()`, `explain()` — so the two read alike.
 *
 * ── THE FIVE KEYS ARE THE CONTRACT, AND THEY ARE BUILT ONCE ─────────────────────────────────────
 * `curriculumKeys` is the (school_id, term_id, class_level_arm_id, exam_type_id, is_ccm) identity of
 * the destination. The resolver builds it ONCE and feeds both the preview's lookup and the job's
 * firstOrCreate from that single construction — never two arrays that happen to agree today.
 *
 * That is the whole parity claim. Both modes share arm and exam-type resolution, so wherever the
 * destination already exists they are provably identical; the ONLY divergence is the create path,
 * and it is sound exactly when the keys the preview looked up are the keys the commit creates. Two
 * separately-built arrays would be one field-addition away from a preview naming a destination the
 * commit does not create — the drift this class removes, reintroduced inside it.
 *
 * ── A NULL `curriculum` WITH A NON-NULL `curriculumKeys` IS NOT AN ERROR ─────────────────────────
 * It is the preview's most useful answer: "resolved fine, and the destination DOES NOT EXIST YET".
 * That is the subject-readiness signal — MoveToNextYearJob::createEpisode attaches only COMPULSORY
 * subjects, read from the target curriculum, so a curriculum the job is about to mint has none and
 * the pupil lands with no subjects at all. Nothing re-attaches them afterwards (every caller of
 * autoAttachCompulsorySubjects fires at enrollment-creation time), which is why the screen has to
 * say so BEFORE the rollover rather than report it after.
 */
final class NextYearPlacement
{
    /** Resolved: there is a destination for this pupil. */
    public const OK = 'ok';

    /**
     * The source level has no `next_class_level_id`. Nobody advances out of a graduating year.
     *
     * UNREACHABLE TODAY, and that is recorded rather than left to be discovered. Both callers test
     * `$targetLevel === null` before asking (MoveToNextYearJob::migrateStudents and
     * RolloverPlanner::placementFor, which routes those pupils to the graduating bucket), so nothing
     * observes this constant. It is kept because `forAdvancer` accepts a nullable target level and
     * must answer SOMETHING for null — deleting it would leave that parameter with no defined
     * response — but a reason set that lists an outcome nobody can produce reads as more exhaustive
     * than it is. If a caller ever stops guarding, this is what it will get.
     */
    public const TERMINAL_LEVEL = 'terminal-level';

    /** The target class level has no arms configured at all. */
    public const NO_ARM = 'no-arm';

    /** Target level is `explicit_only` and neither the arm map nor a label matched. */
    public const EXPLICIT_ONLY_NO_MATCH = 'explicit-only-no-match';

    /** The arm map points at an arm outside the progression target level — refused, never followed. */
    public const MAP_OUTSIDE_TARGET = 'map-outside-target';

    /** The target level does not run the source's exam type and has no default. Never guessed. */
    public const NO_EXAM_TYPE = 'no-exam-type';

    /** The class level participates in no term slot at all. */
    public const NO_PARTICIPATING_SLOT = 'no-participating-slot';

    /** The target session has no `Term` row at the level's first participating slot. Config hole. */
    public const NO_TERM_AT_SLOT = 'no-term-at-slot';

    /**
     * @param  array<string, mixed>|null  $curriculumKeys  the five-key destination identity
     * @param  Curriculum|null  $curriculum  the destination; null with keys set means "would be created"
     * @param  bool  $destinationHasCompulsorySubjects  whether the destination has ACTIVE COMPULSORY
     *                                                  curriculum subjects — the property that
     *                                                  actually decides whether a pupil lands able to
     *                                                  study. False when there is no destination yet.
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?ClassLevelArm $arm = null,
        public readonly ?int $examTypeId = null,
        public readonly ?array $curriculumKeys = null,
        public readonly ?Curriculum $curriculum = null,
        public readonly bool $destinationHasCompulsorySubjects = false,
        public readonly ?int $mappedClassLevelId = null,
        public readonly ?int $termOrder = null,
    ) {}

    public function resolved(): bool
    {
        return $this->reason === self::OK;
    }

    /**
     * Resolved, but the destination has NO ACTIVE COMPULSORY SUBJECTS — so a pupil placed there
     * lands unable to study, and nothing will attach any afterwards.
     *
     * ── THIS TESTS SUBJECTS, NOT EXISTENCE, AND THE DIFFERENCE IS THE WHOLE POINT ────────────────
     * It used to be `$this->curriculum === null`, which measured whether the destination ROW existed.
     * That is the wrong property, and it fails on precisely the run that matters:
     *
     *   Run 1 — destinations do not exist          -> flagged. Nothing is at risk yet; the operator
     *                                                 is warned about a state they can still fix.
     *   Run 2 — the RE-RUN the job's own workflow depends on, after the arm or exam-type config is
     *           corrected and the previously-unresolved pupils finally get placed. Run 1 already
     *           created those destinations, EMPTY (destination() firstOrCreates with min_subjects,
     *           status and two scheme ids — and no subjects). They now EXIST, so an existence check
     *           reads "configured", the panel is silent, the confirm line is silent, and the
     *           acknowledgment gate passes on an empty set — while the pupils land subject-less.
     *
     * So the existence check guarded the run where nothing was at risk and passed the run where
     * everything was. Keyed now on what StudentSubjectService::autoAttachCompulsorySubjects actually
     * reads: `curriculumSubjects()->active()->where('is_compulsory', true)`. Non-existence is
     * subsumed — a destination that is not there has no subjects either.
     *
     * KNOWN FALSE POSITIVE, accepted deliberately: a class level that legitimately runs no compulsory
     * subjects will flag on every rollover. That is the noise-teaches-skipping problem and it is a
     * real cost — but it is the lesser evil against a cohort landing subject-less in silence, and
     * distinguishing "intentionally empty" from "not configured yet" needs a signal the schema does
     * not carry today. Watch for it on the drive; it is a follow-up, not a reason to keep measuring
     * the wrong thing.
     */
    public function destinationIsUnconfigured(): bool
    {
        return $this->resolved() && ! $this->destinationHasCompulsorySubjects;
    }

    /**
     * A stable identity for the destination, for the acknowledgment set the commit checks.
     *
     * Derived from the five keys rather than from a curriculum id, BECAUSE the destinations that
     * matter here are precisely the ones with no curriculum id yet. Sorted before hashing so the
     * identity cannot depend on key insertion order.
     */
    public function destinationKey(): ?string
    {
        if ($this->curriculumKeys === null) {
            return null;
        }

        $keys = $this->curriculumKeys;
        ksort($keys);

        return md5(json_encode($keys, JSON_THROW_ON_ERROR));
    }

    /** Operator-facing. The job logs its own structured version. */
    public function explain(): string
    {
        return match ($this->reason) {
            self::TERMINAL_LEVEL => 'this class level is terminal — nobody is promoted out of it',
            self::NO_ARM => 'the target class level has no arms',
            self::EXPLICIT_ONLY_NO_MATCH => 'the target level only accepts an explicit arm mapping, and none matched',
            self::MAP_OUTSIDE_TARGET => 'the arm mapping points outside the progression target level',
            self::NO_EXAM_TYPE => 'the target level does not run this exam type and has no default',
            self::NO_PARTICIPATING_SLOT => 'the target class level participates in no term slot',
            self::NO_TERM_AT_SLOT => "the target session has no term at order {$this->termOrder}",
            default => 'resolved',
        };
    }
}
