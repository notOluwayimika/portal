<?php

namespace App\Jobs;

use App\Enums\StudentStatusEnum;
use App\Jobs\Middleware\SchoolAware;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
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

    /**
     * Destination curriculum ids this run has already put through the seeding decision.
     *
     * A pure optimisation over the `exists()` guard, which is the real gate: the whole roster of one
     * source curriculum lands in a handful of destinations, and without this every pupil pays a
     * COUNT to be told what the first pupil already established. It is safe to memo because the
     * decision is "was this destination empty when we first reached it", and a second job seeding the
     * same destination converges through firstOrCreate rather than through this cache.
     *
     * @var array<int, true>
     */
    private array $seedingConsidered = [];

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
        // The TERMINAL test is on the COLUMN, not on the resolver's null, because the two nulls mean
        // different things and only one of them is terminality.
        //
        // HOW REACHABLE THE OTHER NULL IS, MEASURED RATHER THAN ASSUMED: not very. `class_levels`
        // does NOT soft-delete, and `class_levels_next_level_school_foreign` is ON DELETE RESTRICT
        // (2026_08_20_110000:67-70), so a referenced level cannot be deleted and the lookup cannot
        // come back empty in normal operation. This branch is therefore defence against the paths
        // that bypass the constraint — a restored dump, a bulk load with FOREIGN_KEY_CHECKS off —
        // not a state the application can produce.
        //
        // It is kept anyway because it costs one comparison and it fails in the safe direction: the
        // alternative treats a broken pointer as "terminal, nobody advances", which is a specific
        // false statement in the one log a person reads when a cohort did not move. Do not simplify
        // it back to testing the resolver's null.
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
     * THE SOURCE CURRICULUM'S SUBJECTS ARE STILL NOT CLONED ACROSS THE LEVEL BOUNDARY — a Year 11
     * subject list is meaningless in Year 12, and the pupil's optional selections deliberately do not
     * carry. That part of the original rule is unchanged.
     *
     * What changed is where the DESTINATION's own list comes from when it has none: see
     * {@see seedSubjectsFromClosingSession}. The seeding runs BEFORE the auto-attach below, because
     * the attach reads the destination's compulsory set and an empty destination attaches nothing —
     * which is exactly how every pupil promoted into a freshly-rolled session landed subject-less.
     *
     * Compulsory subjects are attached explicitly rather than left to StudentCurriculumObserver: the
     * observer's compulsory auto-attach would in fact cover it, but relying on a "safety net only"
     * hook for the primary path is how the optional carry-over silently no-opped in a queued job.
     */
    private function createEpisode(int $studentId, Curriculum $target): StudentCurriculum
    {
        $this->seedSubjectsFromClosingSession($target);

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

    /**
     * Give an EMPTY destination the subject list the SAME CLASS LEVEL taught in the CLOSING session.
     *
     * ── WHY THIS IS NOT A CONTRADICTION OF "THE TARGET LEVEL DEFINES ITS OWN" ─────────────────────
     * It is that rule's missing half. The source curriculum's subjects must not cross a level
     * boundary, and they still do not: nothing here reads `$this->curriculum`'s subject list. What is
     * copied is last year's instance of the DESTINATION's own level — 2025/26 Year 12 seeds 2026/27
     * Year 12 — which is the target level defining its own list, from the only place that list has
     * ever been written down. `class_level_arm_id` is session-independent (only `term_id` carries the
     * session), so "the same level a year earlier" is an exact key match rather than a name heuristic.
     *
     * ── IT LIVES IN THE JOB, NEVER IN THE RESOLVER ────────────────────────────────────────────────
     * NextYearPlacementResolver is shared verbatim between this job and RolloverPlanner's preview,
     * and that shared construction is what makes preview/commit parity a property of the code. This
     * is a WRITE. Putting it there would have a registrar building next year's curricula by opening
     * the screen — and worse, the very warning the screen exists to raise would read "configured"
     * from the second look onward.
     *
     * ── ONLY WHEN EMPTY, WHICH IS THE WHOLE SAFETY ARGUMENT ───────────────────────────────────────
     * A destination with ANY subject row has been configured by somebody — an operator, or an earlier
     * run — and their answer wins. Same discipline as MoveFromTermJob::canAdoptSourceSchemes
     * repairing only while a target is unused. The guard is on presence, not on `wasRecentlyCreated`:
     * a destination created bare by a PREVIOUS release of this job is exactly the row that needs
     * seeding, and it is not recently created.
     *
     * ── AND NO PRIOR CURRICULUM IS A LEGITIMATE ANSWER ────────────────────────────────────────────
     * A school's first year of operation, or a genuinely new level, has nothing to inherit from. That
     * falls back to the previous behaviour — a bare destination and the planner's existing
     * "unconfigured destination" warning — and is emphatically not an error.
     */
    private function seedSubjectsFromClosingSession(Curriculum $destination): void
    {
        $destinationId = (int) $destination->id;

        if (isset($this->seedingConsidered[$destinationId])) {
            return;
        }

        $this->seedingConsidered[$destinationId] = true;

        // THE GATE. CurriculumSubject carries no SchoolScope of its own; it is reached through the
        // curriculum, which is already pinned to this school by the key below.
        if (CurriculumSubject::where('curriculum_id', $destinationId)->exists()) {
            return;
        }

        $prior = $this->closingSessionCurriculumFor($destination);

        if ($prior === null) {
            Log::info('MoveToNextYearJob: no closing-session curriculum for this level, leaving the destination subject-less', [
                'curriculum_id' => $this->curriculum->id,
                'destination_curriculum_id' => $destinationId,
            ]);

            return;
        }

        $this->cloneSubjects($prior, $destination);
    }

    /**
     * Last year's instance of the DESTINATION's level: the same `class_level_arm_id`, `exam_type_id`
     * and `is_ccm`, with its term inside the CLOSING session, and which actually has subjects.
     *
     * The keys are taken from the DESTINATION rather than from the source curriculum, and that is the
     * point of the whole lookup — the source is a Year 11 row and would seed Year 12 with Year 11's
     * subjects, which is the behaviour the "no cloning across a level boundary" rule correctly
     * forbids. `is_ccm` is part of the key so a CCM destination seeds from the prior CCM curriculum
     * and never from its non-CCM sibling, whose weights mean something different.
     *
     * `whereHas` rather than a plain match: a closing session can hold a bare row of its own (created
     * by the year before's rollover and never configured), and inheriting emptiness from it would
     * silently shadow a term that does have the list.
     *
     * DETERMINISTIC BY THE TERM'S ORDER, DESCENDING — the latest term of the closing session, since
     * that is the most recently edited statement of what the level teaches. The id tie-break makes the
     * answer total rather than merely usually-unique. The destination cannot select itself: it sits in
     * the TARGET session, and passesGuards refuses a target session equal to the source's.
     */
    private function closingSessionCurriculumFor(Curriculum $destination): ?Curriculum
    {
        $closingSessionId = $this->sourceSessionId();

        if ($closingSessionId === null) {
            return null;
        }

        return Curriculum::withoutGlobalScope(SchoolScope::class)
            ->select('curricula.*')
            ->join('terms', 'terms.id', '=', 'curricula.term_id')
            ->where('terms.academic_session_id', $closingSessionId)
            ->where('curricula.school_id', $this->schoolId)
            ->where('curricula.class_level_arm_id', $destination->class_level_arm_id)
            ->where('curricula.exam_type_id', $destination->exam_type_id)
            ->where('curricula.is_ccm', $destination->is_ccm)
            ->whereHas('curriculumSubjects')
            ->orderByDesc('terms.order')
            ->orderByDesc('curricula.id')
            ->first();
    }

    /**
     * MIRRORS MoveFromTermJob::cloneCurriculumSubjects, and the mirroring is deliberate rather than
     * incidental — the two are the same operation across two different boundaries, and a second,
     * subtly-different clone is how the scheme split below drifts.
     *
     * firstOrCreate PER SUBJECT, never a bulk insert. Distribution can point several source arms at
     * one destination arm, so two jobs can reach the same empty destination; per-row convergence is
     * what makes that land one set instead of two.
     *
     * THE SCHEME SPLIT. Marking components are copied ONLY on the legacy path — a destination with no
     * marking scheme and no categorical grading. A scheme-backed destination resolves its components
     * THROUGH the scheme (CurriculumSubject::effectiveMarkingComponents), so copying subject-local
     * ones there would create rows that are never read on a curriculum whose scheme was resolved from
     * the target level.
     *
     * WHAT IS NOT COPIED: `grading_scheme_id` and `marking_scheme_id`. Those are resolved from the
     * TARGET level by the resolver and must stay that way — carrying last year's would reintroduce
     * exactly the copy-don't-resolve mistake the class docblock exists to prevent, one boundary up.
     */
    private function cloneSubjects(Curriculum $prior, Curriculum $destination): void
    {
        $copyComponents = ! $destination->marking_scheme_id && ! $destination->usesCategoricalGrading();

        foreach ($prior->curriculumSubjects as $oldSubject) {
            $newSubject = CurriculumSubject::firstOrCreate(
                [
                    'curriculum_id' => $destination->id,
                    'subject_id' => $oldSubject->subject_id,
                ],
                [
                    'is_compulsory' => $oldSubject->is_compulsory,
                    'display_order' => $oldSubject->display_order,
                    'active' => $oldSubject->active,
                ]
            );

            if ($newSubject->wasRecentlyCreated && $copyComponents) {
                foreach ($oldSubject->markingComponents as $component) {
                    $newSubject->markingComponents()->create([
                        'name' => $component->name,
                        'weight' => $component->weight,
                        'school_id' => $destination->school_id,
                        'is_ccm' => $destination->is_ccm,
                    ]);
                }
            }

            $newSubject->resultStatus()->firstOrCreate([], [
                'status' => 'draft',
                'rejection_reason' => null,
                'updated_by' => $this->causedByUserId,
            ]);

            foreach ($oldSubject->teacherAssignments as $assignment) {
                $newSubject->teacherAssignments()->firstOrCreate([
                    'teacher_id' => $assignment->teacher_id,
                ]);
            }
        }
    }
}
