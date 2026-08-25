<?php

namespace App\Jobs;

use App\Enums\StudentStatusEnum;
use App\Jobs\Middleware\SchoolAware;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Services\Rollover\NextYearPlacement;
use App\Services\Rollover\NextYearPlacementResolver;
use App\Services\StudentSubjectService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\CauserResolver;

/**
 * End of year — promote one curriculum's roster into the NEXT CLASS LEVEL, in the next session.
 *
 * Sibling of MoveFromTermJob, and the differences all follow from four facts: this crosses into a new
 * SESSION, changes CLASS LEVEL, changes ARM, and its target arm is NOT deterministic.
 *
 * ── THE SESSION IS PASSED IN, NEVER INFERRED ──────────────────────────────────────────────────────
 * `academic_sessions` has no start_date, no order and no next-pointer — only a display name
 * ("2025/2026") and `is_current`. There is nothing to compute "the next session" from that is not
 * string-parsing a label. And `is_current` marks the session you are IN, not the one you are moving
 * to (both schools currently have it on 2026/2027), so reading it would make the job's behaviour
 * depend on whether an admin had flipped a flag yet. The orchestrator resolves the target session and
 * passes it, exactly as BackfillPastTermJob takes its targetTerm.
 *
 * ── RESOLVE, DON'T COPY ───────────────────────────────────────────────────────────────────────────
 * MoveFromTermJob copies the source's schemes because its target is the same class in the same
 * session, one term on — the class keeps the scheme VERSION it has been marked against. Nothing here
 * qualifies: a different class level in a different session is a different context in every axis. So
 * the grading scheme comes from the TARGET LEVEL (which owns `grading_scheme_id`) and the marking
 * scheme is resolved by (school, is_ccm, latest version), never copied. Copying the source's would be
 * the same mistake the CCM fix corrected in MoveFromTermJob, one boundary up.
 *
 * ── TWO IDEMPOTENCY ANCHORS, ONE PER PATH ─────────────────────────────────────────────────────────
 * ADVANCERS anchor on the source episode's `promoted_to_id`: if it is set, the pupil was promoted —
 * skip, wherever they now sit. firstOrCreate((student_id, curriculum_id)) is NOT sufficient for them,
 * and the reason is specifically reassignment: placement is a pure function, so a plain re-run would
 * converge — but after a pupil is manually moved 12B -> 12S, a re-run recomputes 12B, finds no
 * episode there, and mints a duplicate. The link anchor survives that; the curriculum anchor does not.
 *
 * HELD REPEATERS anchor on firstOrCreate((student_id, curriculum_id)) instead, which IS sufficient
 * for them because their target is fully deterministic — same level, same arm, first slot, no
 * distribution. Two anchors because the two paths differ in whether their target can vary, not for
 * symmetry.
 *
 * ── REPEATERS ARE HELD, AND CARRY NO PROMOTION LINK ───────────────────────────────────────────────
 * A source episode reading `repeated` does not advance a level; it is re-enrolled into the SAME level
 * (same arm) in the target session. Honouring the status if present is correct under both workflows:
 * if the school marks repeaters before the rollover the branch fires, and if it marks them afterwards
 * nothing is flagged at run time, the branch never fires, and the job advances everyone.
 *
 * The held pupil's source episode is LEFT ALONE — status stays `repeated`, `promoted_to_id` stays
 * NULL. `promoted_to_id` keeps meaning "was promoted", full stop. Writing a link without the
 * `promoted` status would introduce a state every existing reader of that column (the promotedTo
 * relation, promotion reporting, BackfillPromotionLinks) would have to learn to exclude — a broad
 * audit for one back-pointer, and a silent miscount of repeaters as promotions until it was done.
 *
 * ── GRADUATION IS NOT THIS JOB'S BUSINESS, AND THAT HAS A VISIBLE CONSEQUENCE ─────────────────────
 * A terminal level (`next_class_level_id IS NULL`) advances nobody. Its pupils are skipped BEFORE any
 * write, so `unresolved` stays 0 and the source curriculum closes with their episodes still reading
 * `active`. That is correct for the scope — nothing here decides what "left school" means, and
 * inventing a terminal status for leavers would be this job guessing at a records policy it has no
 * standing to set. But it does mean the rollover leaves the graduating cohort reading `active` under
 * a closed curriculum, so a later reader must not infer that end-of-year closes leavers out. If
 * graduates need marking, that is a separate operation.
 *
 * ── THE SOURCE IS CLOSED ONLY WHEN NOTHING WAS LEFT UNRESOLVED ────────────────────────────────────
 * Both sibling jobs close their source unconditionally. This one must not: a pupil can legitimately
 * be left unplaced (an `explicit_only` level with no label match, or no resolvable exam type), and
 * closing the source would make the guard abort every future run — stranding those pupils with no way
 * to place them after the config is fixed. So the source closes only if every non-withdrawn pupil was
 * resolved. That is also what makes the promoted_to_id anchor load-bearing rather than decorative:
 * the re-run after fixing config genuinely reaches the body, and the anchor is what stops it
 * double-promoting everyone who succeeded the first time.
 */
class MoveToNextYearJob implements ShouldQueue
{
    // Batchable is REQUIRED, not decorative: both rollover commands and the M4
    // controller dispatch these through Bus::batch, and PendingBatch refuses any job
    // without the trait. It was missing since the commands were written — every test
    // fakes the bus, and BusFake::batch() returns a PendingBatchFake that skips the
    // check entirely, so --commit had never actually run.
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Memoised, NOT constructor-injected: this job is serialised onto a queue, and a resolver holding
     * models and an arm cache has no business crossing that boundary. Built on first use inside
     * handle(), where the job is already hydrated.
     */
    private ?NextYearPlacementResolver $placement = null;

    public function __construct(
        public readonly Curriculum $curriculum,
        public readonly AcademicSession $targetSession,
        public readonly int $causedByUserId,
        public readonly int $schoolId,
    ) {}

    public function middleware(): array
    {
        return [new SchoolAware];
    }

    public function handle(): void
    {
        if (! $this->passesGuards()) {
            return;
        }

        $sourceArm = $this->curriculum->classLevelArm()->withoutGlobalScope(SchoolScope::class)->first();
        $sourceLevel = $sourceArm?->classLevel()->withoutGlobalScope(SchoolScope::class)->first();

        if ($sourceArm === null || $sourceLevel === null) {
            Log::warning('MoveToNextYearJob: source arm or class level missing, aborting', [
                'curriculum_id' => $this->curriculum->id,
            ]);

            return;
        }

        $targetLevel = $this->resolveTargetLevel($sourceLevel);

        $causer = User::find($this->causedByUserId);
        if ($causer) {
            app(CauserResolver::class)->setCauser($causer);
        }

        try {
            DB::transaction(function () use ($sourceArm, $sourceLevel, $targetLevel) {
                $unresolved = $this->migrateStudents($sourceArm, $sourceLevel, $targetLevel);

                // See the class docblock: closing unconditionally would strand anyone left unplaced.
                if ($unresolved === 0) {
                    $this->curriculum->update(['status' => 'closed']);

                    return;
                }

                Log::warning('MoveToNextYearJob: leaving the source curriculum OPEN — pupils remain unresolved', [
                    'curriculum_id' => $this->curriculum->id,
                    'unresolved' => $unresolved,
                ]);
            });
        } finally {
            app(CauserResolver::class)->setCauser(null);
        }
    }

    private function passesGuards(): bool
    {
        $source = $this->curriculum;

        $abort = function (string $reason): bool {
            Log::warning("MoveToNextYearJob: {$reason}, aborting", [
                'curriculum_id' => $this->curriculum->id,
            ]);

            return false;
        };

        if ($this->schoolId !== (int) $source->school_id) {
            return $abort('declared schoolId does not match the curriculum school');
        }

        if ((int) $this->targetSession->school_id !== (int) $source->school_id) {
            return $abort('target session belongs to another school');
        }

        if ($this->targetSession->id === $this->sourceSessionId()) {
            return $abort('target session is the source curriculum\'s own session');
        }

        if ($source->is_ccm === true) {
            return $abort('source curriculum is CCM — MoveFromCcmJob must run for this term first');
        }

        if ($source->status !== 'active') {
            return $abort('source curriculum is not active');
        }

        return true;
    }

    private function sourceSessionId(): ?int
    {
        return Term::withoutGlobalScope(SchoolScope::class)
            ->whereKey($this->curriculum->term_id)
            ->value('academic_session_id');
    }

    /**
     * NULL means TERMINAL — the graduating year, out of which nobody is promoted. It is a
     * configuration answer, not a missing one, so it is not a warning.
     */
    private function resolveTargetLevel(ClassLevel $sourceLevel): ?ClassLevel
    {
        // The TERMINAL test is on the column, not on the resolver's null. Both a terminal level and a
        // `next_class_level_id` pointing at a row that is gone resolve to null, and calling the second
        // one "terminal, nobody advances" would put a specific false statement in the log — the one
        // place someone looks when a cohort did not move.
        if ($sourceLevel->next_class_level_id === null) {
            Log::info('MoveToNextYearJob: terminal class level, nobody advances', [
                'curriculum_id' => $this->curriculum->id,
                'class_level_id' => $sourceLevel->id,
            ]);

            return null;
        }

        return $this->placement()->targetLevel($sourceLevel);
    }

    /**
     * Walk every source episode. Returns the number left UNRESOLVED, which decides whether the source
     * curriculum may be closed.
     *
     * A terminal level ($targetLevel === null) leaves advancers unresolved BY DESIGN rather than
     * counting them as failures — but it must not hold the source open forever either, so it returns
     * early: nobody advances out of a graduating year, and there is nothing a re-run would fix.
     */
    private function migrateStudents(ClassLevelArm $sourceArm, ClassLevel $sourceLevel, ?ClassLevel $targetLevel): int
    {
        $unresolved = 0;

        foreach ($this->curriculum->studentCurricula as $sourceEnrollment) {
            if ($sourceEnrollment->status === StudentStatusEnum::WITHDRAWN) {
                continue;
            }

            if ($sourceEnrollment->status === StudentStatusEnum::REPEATED) {
                if (! $this->holdRepeater($sourceEnrollment, $sourceArm, $sourceLevel)) {
                    $unresolved++;
                }

                continue;
            }

            // ADVANCER ANCHOR — set means promoted already, wherever they now sit. This is what
            // survives a manual reassignment that moved them off the arm this job would recompute.
            if ($sourceEnrollment->promoted_to_id !== null) {
                continue;
            }

            if ($targetLevel === null) {
                continue;
            }

            if (! $this->advance($sourceEnrollment, $sourceArm, $targetLevel)) {
                $unresolved++;
            }
        }

        return $unresolved;
    }

    /**
     * Re-enroll a repeater into their OWN level's first slot in the target session, same arm.
     *
     * The source episode is deliberately untouched — see the class docblock on why no promotion link
     * is written here.
     */
    private function holdRepeater(StudentCurriculum $sourceEnrollment, ClassLevelArm $sourceArm, ClassLevel $sourceLevel): bool
    {
        $placement = $this->placement()->forRepeater($sourceArm, $sourceLevel, create: true);

        if (! $placement->resolved() || $placement->curriculum === null) {
            Log::warning('MoveToNextYearJob: cannot hold repeater — no target curriculum for their own level', [
                'curriculum_id' => $this->curriculum->id,
                'student_id' => $sourceEnrollment->student_id,
                'reason' => $placement->reason,
            ]);

            return false;
        }

        // HELD-REPEATER ANCHOR — sound here because their target is fully deterministic.
        $this->createEpisode((int) $sourceEnrollment->student_id, $placement->curriculum);

        return true;
    }

    private function advance(StudentCurriculum $sourceEnrollment, ClassLevelArm $sourceArm, ClassLevel $targetLevel): bool
    {
        $placement = $this->placement()->forAdvancer($sourceEnrollment, $sourceArm, $targetLevel, create: true);

        if (! $placement->resolved() || $placement->curriculum === null) {
            $this->logRefusal($placement, $sourceEnrollment, $targetLevel);

            return false;
        }

        $newEpisode = $this->createEpisode((int) $sourceEnrollment->student_id, $placement->curriculum);

        // Link AND status in ONE update. The promoted_requires_link trigger (live on 5.7 since
        // 2026_08_20_140000) refuses status-before-link; one statement cannot trip it.
        $sourceEnrollment->update([
            'status' => StudentStatusEnum::PROMOTED,
            'promoted_to_id' => $newEpisode->id,
        ]);

        return true;
    }

    /**
     * The shared placement rules, in WRITE mode. One resolver per job: the source curriculum, school
     * and target session are constant across every pupil, and it caches each target level's arm list.
     *
     * The planner constructs the same object in READ-ONLY mode to build the preview, which is what
     * makes preview/commit parity a property of the code rather than a coincidence of two
     * implementations agreeing today.
     */
    private function placement(): NextYearPlacementResolver
    {
        return $this->placement ??= new NextYearPlacementResolver(
            $this->curriculum,
            $this->schoolId,
            $this->targetSession,
        );
    }

    /**
     * The refusals keep the job's own log lines and its own structure — the resolver returns a
     * reason, this decides what to say about it. Same split as MoveFromTermJob keeping its logging
     * when NextTermSlot took over its resolution.
     */
    private function logRefusal(NextYearPlacement $placement, StudentCurriculum $sourceEnrollment, ClassLevel $targetLevel): void
    {
        $context = [
            'curriculum_id' => $this->curriculum->id,
            'student_id' => $sourceEnrollment->student_id,
            'target_class_level_id' => $targetLevel->id,
        ];

        match ($placement->reason) {
            NextYearPlacement::MAP_OUTSIDE_TARGET => Log::warning(
                'MoveToNextYearJob: arm map points outside the progression target level — refusing it',
                $context + ['mapped_class_level_id' => $placement->mappedClassLevelId],
            ),
            NextYearPlacement::NO_ARM => Log::warning('MoveToNextYearJob: target class level has no arms', $context),
            NextYearPlacement::EXPLICIT_ONLY_NO_MATCH => Log::warning(
                'MoveToNextYearJob: target level is explicit_only and no map or label matched — leaving the pupil unplaced',
                $context,
            ),
            NextYearPlacement::NO_EXAM_TYPE => Log::warning(
                'MoveToNextYearJob: no resolvable exam type for the target level — refusing to guess',
                $context,
            ),
            NextYearPlacement::NO_PARTICIPATING_SLOT => Log::warning(
                'MoveToNextYearJob: class level has no participating term slots',
                $context,
            ),
            NextYearPlacement::NO_TERM_AT_SLOT => Log::warning(
                'MoveToNextYearJob: target session has no Term row for the first participating slot',
                $context + ['target_session_id' => $this->targetSession->id, 'term_order' => $placement->termOrder],
            ),
            default => Log::warning('MoveToNextYearJob: pupil left unplaced', $context + ['reason' => $placement->reason]),
        };
    }

    /**
     * SUBJECTS ARE NOT CLONED ACROSS A LEVEL BOUNDARY — the target level defines its own, so the
     * source's selections are meaningless there. Compulsory subjects are attached explicitly rather
     * than left to StudentCurriculumObserver: the observer's compulsory auto-attach would in fact
     * cover it, but relying on a "safety net only" hook for the primary path is how the optional
     * carry-over silently no-opped in a queued job. Optional selections deliberately do not carry.
     */
    private function createEpisode(int $studentId, Curriculum $target): StudentCurriculum
    {
        $episode = StudentCurriculum::firstOrCreate(
            [
                'student_id' => $studentId,
                'curriculum_id' => $target->id,
            ],
            [
                'status' => StudentStatusEnum::ACTIVE,
            ]
        );

        app(StudentSubjectService::class)->autoAttachCompulsorySubjects($episode);

        return $episode;
    }
}
