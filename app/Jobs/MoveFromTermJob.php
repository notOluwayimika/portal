<?php

namespace App\Jobs;

use App\Enums\StudentStatusEnum;
use App\Enums\StudentSubjectStatus;
use App\Jobs\Middleware\SchoolAware;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\MarkingScheme;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Models\Term;
use App\Models\User;
use App\Services\Rollover\NextTermSlot;
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
 * End of term — carry one curriculum's roster into the class level's NEXT PARTICIPATING term slot.
 *
 * One job per source curriculum, self-guarding and idempotent, matching the single-curriculum design
 * of MoveFromCcmJob and BackfillPastTermJob.
 *
 * ── WHAT THIS JOB MIRRORS, AND WHAT IT DELIBERATELY DOES NOT ──────────────────────────────────────
 * It is NOT a copy of MoveFromCcmJob. That job resolves its target for a CCM -> non-CCM flip WITHIN
 * one term and therefore copies NEITHER `grading_scheme_id` NOR `marking_scheme_id` — it re-derives
 * the marking scheme on purpose, because the whole point of that move is that CCM weights must not
 * carry. Reusing its resolveTargetCurriculum here would roll an Early Years CATEGORICAL class into
 * next term as NUMERIC, silently: `grading_mode` is derived from `grading_scheme_id`
 * (CurriculumResource), so dropping the scheme does not error, it just changes what the results are.
 *
 * The scheme handling therefore comes from BackfillPastTermJob instead — both schemes copied, plus
 * its canAdoptSourceSchemes repair guard for targets created by an earlier version of this job while
 * they are still unused.
 *
 * ── BUT THE TARGET IS 'active', NOT 'closed' ──────────────────────────────────────────────────────
 * BackfillPastTermJob hardcodes `'status' => 'closed'`, which is right for it — it backfills a term
 * that has already finished. This job moves FORWARD, into a term pupils are about to sit, so the
 * target is created 'active' explicitly. It is equally deliberate that the status is NOT inherited
 * from the source the way MoveFromCcmJob does it: that only works there because it resolves the
 * target BEFORE closing the source, an ordering dependency that is invisible until someone reorders
 * two lines and every future curriculum starts life closed.
 *
 * ── IDEMPOTENCY ───────────────────────────────────────────────────────────────────────────────────
 * THE GUARD IS THE REAL ANCHOR, and it is worth being precise about which mechanism does the work.
 * handle() wraps everything in ONE transaction and closes the source INSIDE it, so a committed run
 * always leaves the source closed — and a re-run therefore aborts at passesGuards() before reaching
 * any write. A run that fails rolls the close back with everything else. There is no committed state
 * in which the body runs twice.
 *
 * The firstOrCreate((student_id, curriculum_id)) convergence is a second line, not the first, and is
 * essentially unreachable given atomic rollback plus the in-transaction close. It still matters as
 * defence for a target that already exists for another reason (a partially hand-built next term), so
 * it stays — but the test that proves idempotency is proving the GUARD, and says so.
 *
 * (End of YEAR cannot lean on either: its target arm varies under distribution, so a re-run would
 * land a different curriculum_id and mint a second live episode. That job anchors on the source's
 * promoted_to_id instead.)
 *
 * ── ORDERING AGAINST THE CCM MOVE ─────────────────────────────────────────────────────────────────
 * A CCM source is REFUSED. MoveFromCcmJob leaves the non-CCM curriculum active at term end, so
 * running this first would carry a CCM class forward as CCM and it would never get its non-CCM term.
 * The orchestrator owns that ordering; this guard makes the job refuse to be the one that breaks it
 * rather than trusting the caller.
 *
 * ── EXPECTED LOG NOISE ────────────────────────────────────────────────────────────────────────────
 * StudentCurriculumObserver logs one academic-anomalies warning per enrollment created here (the row
 * must exist before subjects can reference it), and its auto-attach remediation converges with this
 * job's own firstOrCreate writes through student_subjects' (student_curriculum_id,
 * curriculum_subject_id) UNIQUE. Expected and harmless — documented so a rollover's noise is not
 * mistaken for a real anomaly.
 */
class MoveFromTermJob implements ShouldQueue
{
    // Batchable is REQUIRED, not decorative: both rollover commands and the M4
    // controller dispatch these through Bus::batch, and PendingBatch refuses any job
    // without the trait. It was missing since the commands were written — every test
    // fakes the bus, and BusFake::batch() returns a PendingBatchFake that skips the
    // check entirely, so --commit had never actually run.
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly Curriculum $curriculum,
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

        $participation = $this->resolveNextParticipation();

        if ($participation === null) {
            // The per-class-level version of "do nothing if this is the last term". Not a failure:
            // a class running slots 1-2 simply has nowhere to go at the end of slot 2.
            Log::info('MoveFromTermJob: no next participating term slot, nothing to do', [
                'curriculum_id' => $this->curriculum->id,
            ]);

            return;
        }

        [$targetTerm, $targetIsCcm] = $participation;

        // Audit attribution only — never auth()->setUser() (§5.6). School context comes solely from
        // the declared schoolId via SchoolAware.
        $causer = User::find($this->causedByUserId);
        if ($causer) {
            app(CauserResolver::class)->setCauser($causer);
        }

        try {
            DB::transaction(function () use ($targetTerm, $targetIsCcm) {
                $targetCurriculum = $this->resolveTargetCurriculum($targetTerm, $targetIsCcm);
                $subjectMap = $this->cloneCurriculumSubjects($targetCurriculum);
                $this->migrateStudents($targetCurriculum, $subjectMap);

                // LAST, and after the target is resolved — see the class docblock on why the target's
                // status is set explicitly rather than inherited. Both existing jobs close their
                // source; leaving it active would have the orchestrator re-select it every run.
                $this->curriculum->update(['status' => 'closed']);
            });
        } finally {
            app(CauserResolver::class)->setCauser(null);
        }
    }

    private function passesGuards(): bool
    {
        $source = $this->curriculum;

        $abort = function (string $reason): bool {
            Log::warning("MoveFromTermJob: {$reason}, aborting", [
                'curriculum_id' => $this->curriculum->id,
            ]);

            return false;
        };

        if ($this->schoolId !== (int) $source->school_id) {
            return $abort('declared schoolId does not match the curriculum school');
        }

        if ($source->is_ccm === true) {
            return $abort('source curriculum is CCM — MoveFromCcmJob must run for this term first');
        }

        if ($source->status !== 'active') {
            // Also the idempotency backstop: the first run closes the source.
            return $abort('source curriculum is not active');
        }

        return true;
    }

    /**
     * The next term slot this class level participates in, and whether that slot is CCM.
     *
     * SLOT CONTIGUITY IS NEVER ASSUMED. The next slot is the LOWEST configured `term_order` GREATER
     * than the current term's order — not `order + 1`. A class running slots 1 and 3 must skip 2
     * rather than stop at it, and `terms.order` carries no contiguity guarantee in the first place.
     *
     * TERMS ARE NEVER CREATED. If the class participates in a further slot but no Term row exists for
     * it yet (next year's calendar not entered), this returns null and the job no-ops. Minting a term
     * from a migration job would fabricate the academic calendar as a side effect of a rollover.
     *
     * A COMPLETED target term is refused: moving a live roster into a finished term is
     * BackfillPastTermJob's job, in the opposite direction, and would put an active roster somewhere
     * results are already closed. `active` and `upcoming` both qualify — `upcoming` is in fact the
     * normal case, since at the moment a term ends its successor has not started.
     *
     * @return array{0: Term, 1: bool}|null
     */
    /**
     * Delegates to {@see NextTermSlot} — the SAME resolution RolloverPlanner uses to decide whether
     * a class has anywhere to go.
     *
     * It used to live here alone, and the planner did not know about it: an end-of-term rollover on
     * the session's last term promised a move for every class, queued a job each, and every one
     * no-opped through this method while the batch reported success. Two implementations could not
     * have fixed that — the drift is precisely the failure — so there is one, and this is a caller.
     *
     * The logging stays here because it is the JOB's concern; the planner presents the same reasons
     * to an operator instead.
     */
    private function resolveNextParticipation(): ?array
    {
        $slot = NextTermSlot::for($this->curriculum, $this->schoolId);

        if ($slot->resolved()) {
            return [$slot->term, $slot->isCcm];
        }

        if ($slot->reason === NextTermSlot::NO_TERM_AT_ORDER) {
            Log::info('MoveFromTermJob: class level participates in a later slot but no Term row exists for it', [
                'curriculum_id' => $this->curriculum->id,
                'term_order' => $slot->termOrder,
            ]);
        }

        if ($slot->reason === NextTermSlot::TARGET_TERM_NOT_OPEN) {
            Log::warning('MoveFromTermJob: target term is not upcoming or active, aborting', [
                'curriculum_id' => $this->curriculum->id,
                'target_term_id' => $slot->term?->id,
                'target_term_status' => $slot->term?->status->value,
            ]);
        }

        return null;
    }

    /**
     * Find (or create) the curriculum for the next slot. Matching the curricula table's unique key
     * guarantees a re-run reuses the previously created target.
     *
     * `is_ccm` comes from CONFIG, not from the source: a class level may enter the CCM variant of the
     * next slot without having been CCM in this one. That is the whole point of holding CCM
     * participation at (class level, term slot) granularity.
     *
     * THE MARKING SCHEME IS NOT COPIED WHEN THE TARGET IS CCM — see resolveTargetMarkingSchemeId.
     * The grading scheme IS copied either way: categorical-vs-numeric is a property of the class, not
     * of half-term-vs-full-term, so it is is_ccm-agnostic.
     */
    private function resolveTargetCurriculum(Term $targetTerm, bool $targetIsCcm): Curriculum
    {
        $source = $this->curriculum;
        $markingSchemeId = $this->resolveTargetMarkingSchemeId($targetIsCcm);

        $target = Curriculum::withoutGlobalScope(SchoolScope::class)->firstOrCreate(
            [
                'school_id' => $source->school_id,
                'term_id' => $targetTerm->id,
                'class_level_arm_id' => $source->class_level_arm_id,
                'exam_type_id' => $source->exam_type_id,
                'is_ccm' => $targetIsCcm,
            ],
            [
                'min_subjects' => $source->min_subjects,
                // Forward, into a term pupils are about to sit — see the class docblock.
                'status' => 'active',
                'marking_scheme_id' => $markingSchemeId,
                'grading_scheme_id' => $source->grading_scheme_id,
            ]
        );

        // Repair a target created by a pre-scheme version of this job, but ONLY while it is unused:
        // once any component, score or result exists its configuration is historical data. Uses the
        // RESOLVED scheme, not the source's — otherwise the repair path re-introduces exactly the
        // CCM mis-scheme the resolver above exists to prevent.
        if (! $target->wasRecentlyCreated && $this->canAdoptSourceSchemes($target, $markingSchemeId)) {
            $target->update([
                'marking_scheme_id' => $target->marking_scheme_id ?? $markingSchemeId,
                'grading_scheme_id' => $target->grading_scheme_id ?? $source->grading_scheme_id,
            ]);
        }

        return $target;
    }

    /**
     * Which marking scheme the TARGET curriculum should carry.
     *
     * COPYING THE SOURCE'S IS ONLY CORRECT WHEN THE TARGET IS NON-CCM. The source is always non-CCM
     * (passesGuards refuses a CCM source), so `$source->marking_scheme_id` is a NON-CCM scheme —
     * full-term weights. When config says the next slot is the CCM variant, copying that stamps a
     * half-term curriculum with full-term weights, and because cloneCurriculumSubjects skips the
     * subject-local component copy whenever `marking_scheme_id` is set, EVERY component on that CCM
     * curriculum then resolves through the non-CCM scheme. Half-term work scored on full-term
     * weights, silently, for the whole CCM window before MoveFromCcmJob moves the class out of it.
     *
     * So a CCM target resolves its own scheme the way MoveFromCcmJob::attachMarkingComponents does —
     * active, this school, `is_ccm` matching, latest version.
     *
     * NULL IS A LEGITIMATE ANSWER, not a failure: it drops the target onto the LEGACY path, where
     * cloneCurriculumSubjects copies subject-local components and stamps them
     * `'is_ccm' => $targetCurriculum->is_ccm` — which is already correct for CCM. Every CCM curriculum
     * in this database today is legacy (17 of 17), which is why this bug is latent rather than live;
     * school 2 nonetheless already has both an active is_ccm=1 marking scheme and 13 scheme-backed
     * curricula, so it is one participation row away from firing.
     *
     * A NON-CCM target copies the source's scheme rather than looking up the latest, deliberately: the
     * class keeps the exact scheme VERSION it has been marked against instead of jumping to a newer
     * one mid-session.
     */
    private function resolveTargetMarkingSchemeId(bool $targetIsCcm): ?int
    {
        $source = $this->curriculum;

        if (! $targetIsCcm) {
            return $source->marking_scheme_id;
        }

        $ccmScheme = MarkingScheme::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->active()
            ->where('school_id', $source->school_id)
            ->where('is_ccm', true)
            ->latest('version')
            ->first();

        if ($ccmScheme === null) {
            Log::info('MoveFromTermJob: CCM target has no active CCM marking scheme; using the legacy per-subject component path', [
                'curriculum_id' => $source->id,
            ]);
        }

        return $ccmScheme?->id;
    }

    /**
     * Takes the RESOLVED marking scheme, not the source's — the repair has to be decided against the
     * scheme that would actually be written, or a CCM target with a NULL resolved scheme looks
     * repairable because the SOURCE has one.
     */
    private function canAdoptSourceSchemes(Curriculum $target, ?int $markingSchemeId): bool
    {
        $source = $this->curriculum;

        if (
            (! $markingSchemeId || $target->marking_scheme_id)
            && (! $source->grading_scheme_id || $target->grading_scheme_id)
        ) {
            return false;
        }

        return ! $target->curriculumSubjects()
            ->where(function ($query) {
                $query->whereHas('markingComponents')
                    ->orWhereHas('scores')
                    ->orWhereHas('studentResults');
            })
            ->exists();
    }

    /**
     * Clone every curriculum subject onto the target. The same subjects carry over within a class
     * level — unlike the end-of-year move, which crosses a class-level boundary and must let the
     * target class define its own.
     *
     * Scheme-backed curricula resolve shared components through the target curriculum; only legacy
     * (scheme-less, non-categorical) curricula copy subject-local components.
     *
     * @return array<int, CurriculumSubject> old curriculum_subject_id => new CurriculumSubject
     */
    private function cloneCurriculumSubjects(Curriculum $targetCurriculum): array
    {
        $subjectMap = [];

        foreach ($this->curriculum->curriculumSubjects as $oldSubject) {
            $newSubject = CurriculumSubject::firstOrCreate(
                [
                    'curriculum_id' => $targetCurriculum->id,
                    'subject_id' => $oldSubject->subject_id,
                ],
                [
                    'is_compulsory' => $oldSubject->is_compulsory,
                    'display_order' => $oldSubject->display_order,
                    'active' => $oldSubject->active,
                ]
            );

            if (
                $newSubject->wasRecentlyCreated
                && ! $targetCurriculum->marking_scheme_id
                && ! $targetCurriculum->usesCategoricalGrading()
            ) {
                foreach ($oldSubject->markingComponents as $component) {
                    $newSubject->markingComponents()->create([
                        'name' => $component->name,
                        'weight' => $component->weight,
                        'school_id' => $targetCurriculum->school_id,
                        'is_ccm' => $targetCurriculum->is_ccm,
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

            $subjectMap[$oldSubject->id] = $newSubject;
        }

        return $subjectMap;
    }

    /**
     * Carry the roster forward, then record each source episode as promoted WITH its link.
     *
     * STATUS POLICY. `withdrawn` is excluded outright — mirroring MoveFromCcmJob verbatim would carry
     * a withdrawn pupil into next term and mark their old episode promoted. Both `promoted` and
     * `repeated` land as `active`: each is terminal for the SOURCE episode, but the pupil is actively
     * enrolled in the new term regardless of which one it was. Inheriting `repeated` (as
     * MoveFromCcmJob would, since it remaps only `promoted`) would mint an episode that softEnd()
     * treats as terminal while `ended_at` is NULL — the "ended but reads as current" shape
     * CurriculumEnrollmentService::softEnd exists to close.
     *
     * OPTIONAL SUBJECTS ARE CLONED EXPLICITLY. StudentCurriculumObserver's carry-over is gated on
     * auth()->user(), which is NULL in a queued job, so it silently no-ops there — relying on it
     * would lose every pupil's optional picks at rollover, without an error.
     *
     * LINK AND STATUS IN ONE UPDATE. student_curricula_promoted_requires_link rejects a promoted row
     * with a NULL link, so these can never be written as two statements.
     *
     * @param  array<int, CurriculumSubject>  $subjectMap  old curriculum_subject_id => new CurriculumSubject
     */
    private function migrateStudents(Curriculum $targetCurriculum, array $subjectMap): void
    {
        foreach ($this->curriculum->studentCurricula as $sourceEnrollment) {
            if ($sourceEnrollment->status === StudentStatusEnum::WITHDRAWN) {
                continue;
            }

            $carriedStatus = in_array(
                $sourceEnrollment->status,
                [StudentStatusEnum::PROMOTED, StudentStatusEnum::REPEATED],
                true,
            )
                ? StudentStatusEnum::ACTIVE
                : $sourceEnrollment->status;

            $newEnrollment = StudentCurriculum::firstOrCreate(
                [
                    'student_id' => $sourceEnrollment->student_id,
                    'curriculum_id' => $targetCurriculum->id,
                ],
                [
                    'status' => $carriedStatus,
                ]
            );

            foreach ($sourceEnrollment->activeSubjects as $sourceSubject) {
                $newCurriculumSubject = $subjectMap[$sourceSubject->curriculum_subject_id] ?? null;

                if (! $newCurriculumSubject) {
                    continue;
                }

                StudentSubject::firstOrCreate(
                    [
                        'student_curriculum_id' => $newEnrollment->id,
                        'curriculum_subject_id' => $newCurriculumSubject->id,
                    ],
                    [
                        'status' => StudentSubjectStatus::Active,
                    ]
                );
            }

            $sourceEnrollment->update([
                'status' => StudentStatusEnum::PROMOTED,
                'promoted_to_id' => $newEnrollment->id,
            ]);
        }
    }
}
