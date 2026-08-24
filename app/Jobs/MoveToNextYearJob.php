<?php

namespace App\Jobs;

use App\Enums\StudentStatusEnum;
use App\Jobs\Middleware\SchoolAware;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmProgression;
use App\Models\ClassLevelExamType;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\MarkingScheme;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentCurriculum;
use App\Models\Term;
use App\Models\User;
use App\Services\StudentSubjectService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
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
        if ($sourceLevel->next_class_level_id === null) {
            Log::info('MoveToNextYearJob: terminal class level, nobody advances', [
                'curriculum_id' => $this->curriculum->id,
                'class_level_id' => $sourceLevel->id,
            ]);

            return null;
        }

        return ClassLevel::withoutGlobalScope(SchoolScope::class)
            ->whereKey($sourceLevel->next_class_level_id)
            ->first();
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
        $target = $this->resolveTargetCurriculum($sourceLevel, $sourceArm, (int) $this->curriculum->exam_type_id);

        if ($target === null) {
            Log::warning('MoveToNextYearJob: cannot hold repeater — no target curriculum for their own level', [
                'curriculum_id' => $this->curriculum->id,
                'student_id' => $sourceEnrollment->student_id,
            ]);

            return false;
        }

        // HELD-REPEATER ANCHOR — sound here because their target is fully deterministic.
        $this->createEpisode((int) $sourceEnrollment->student_id, $target);

        return true;
    }

    private function advance(StudentCurriculum $sourceEnrollment, ClassLevelArm $sourceArm, ClassLevel $targetLevel): bool
    {
        $targetArm = $this->resolveArm($sourceEnrollment, $sourceArm, $targetLevel);

        if ($targetArm === null) {
            return false;
        }

        $examTypeId = $this->resolveExamType($targetLevel);

        if ($examTypeId === null) {
            Log::warning('MoveToNextYearJob: no resolvable exam type for the target level — refusing to guess', [
                'curriculum_id' => $this->curriculum->id,
                'student_id' => $sourceEnrollment->student_id,
                'target_class_level_id' => $targetLevel->id,
            ]);

            return false;
        }

        $target = $this->resolveTargetCurriculum($targetLevel, $targetArm, $examTypeId);

        if ($target === null) {
            return false;
        }

        $newEpisode = $this->createEpisode((int) $sourceEnrollment->student_id, $target);

        // Link AND status in ONE update. The promoted_requires_link trigger (live on 5.7 since
        // 2026_08_20_140000) refuses status-before-link; one statement cannot trip it.
        $sourceEnrollment->update([
            'status' => StudentStatusEnum::PROMOTED,
            'promoted_to_id' => $newEpisode->id,
        ]);

        return true;
    }

    /**
     * Arm resolution, in strict order: explicit map, then stream-aware label match, then the target
     * level's distribution strategy.
     */
    private function resolveArm(StudentCurriculum $sourceEnrollment, ClassLevelArm $sourceArm, ClassLevel $targetLevel): ?ClassLevelArm
    {
        // 1 — EXPLICIT MAP, VALIDATED AGAINST THE PROGRESSION TARGET. The schema guarantees the map's
        // two arms share a school and nothing more: no FK ties the mapped target to the source level's
        // next_class_level_id, so an operator can map 7A into a Year 9 arm and every constraint
        // accepts it. Following that would promote a pupil into the wrong year — silently, since the
        // write succeeds. A mismatched map is REFUSED, not fallen through: falling through would
        // quietly place the pupil somewhere else and hide a misconfiguration the school needs told.
        $mapped = ClassLevelArmProgression::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $this->schoolId)
            ->where('source_class_level_arm_id', $sourceArm->id)
            ->first();

        if ($mapped !== null) {
            $mappedArm = ClassLevelArm::withoutGlobalScope(SchoolScope::class)
                ->whereKey($mapped->target_class_level_arm_id)
                ->first();

            if ($mappedArm !== null && (int) $mappedArm->class_level_id === (int) $targetLevel->id) {
                return $mappedArm;
            }

            Log::warning('MoveToNextYearJob: arm map points outside the progression target level — refusing it', [
                'curriculum_id' => $this->curriculum->id,
                'student_id' => $sourceEnrollment->student_id,
                'source_class_level_arm_id' => $sourceArm->id,
                'mapped_class_level_id' => $mappedArm?->class_level_id,
                'expected_class_level_id' => $targetLevel->id,
            ]);

            return null;
        }

        // 2 — LABEL MATCH, STREAM-AWARE. class_level_arms is UNIQUE on
        // (class_level_id, arm_id, stream_id), so a label alone can identify two arms in one level
        // that differ only by stream. Every stream_id is NULL across both schools today, so a
        // label-only match happens to be unambiguous — and would stop being so the first time a
        // school configures streams, silently placing pupils in the wrong stream.
        $labelMatch = $this->targetArms($targetLevel)
            ->first(fn (ClassLevelArm $arm) => $arm->arm?->label === $sourceArm->arm?->label
                && (int) $arm->stream_id === (int) $sourceArm->stream_id);

        if ($labelMatch !== null) {
            return $labelMatch;
        }

        // 3 — DISTRIBUTION, GOVERNED BY THE TARGET LEVEL. The target owns its arms and any future
        // streams, so its strategy decides how they are filled; a source level's preference has no
        // standing over a level it is feeding.
        $arms = $this->targetArms($targetLevel);

        if ($arms->isEmpty()) {
            Log::warning('MoveToNextYearJob: target class level has no arms', [
                'curriculum_id' => $this->curriculum->id,
                'target_class_level_id' => $targetLevel->id,
            ]);

            return null;
        }

        if ($targetLevel->arm_distribution_strategy === 'explicit_only') {
            Log::warning('MoveToNextYearJob: target level is explicit_only and no map or label matched — leaving the pupil unplaced', [
                'curriculum_id' => $this->curriculum->id,
                'student_id' => $sourceEnrollment->student_id,
                'target_class_level_id' => $targetLevel->id,
            ]);

            return null;
        }

        // PURE FUNCTION OF THE PUPIL — never a counter. Placement must not depend on which source
        // curriculum's job computed it: 7A and 7B both feeding 8D/8E would each start at D under a
        // per-job round-robin and skew the arms, and any shared counter would race between concurrent
        // jobs. student_id % armCount over a fixed order needs no coordination and is stable across
        // re-runs, which is also what makes a re-run's recomputation land where the first one did.
        return $arms[(int) $sourceEnrollment->student_id % $arms->count()];
    }

    /**
     * The target level's arms in a FIXED, documented order — by id ascending. The order is part of
     * the placement contract: change it and every pupil's modulo lands somewhere else.
     *
     * @return Collection<int, ClassLevelArm>
     */
    private function targetArms(ClassLevel $targetLevel): Collection
    {
        return ClassLevelArm::withoutGlobalScope(SchoolScope::class)
            ->with('arm')
            ->where('class_level_id', $targetLevel->id)
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * Carry the pupil's exam type if the target level runs it, else the target's default.
     *
     * NULL is a HARD STOP, not a fallback: which certificate a pupil sits is not something to guess.
     * This is the Year 11 -> Year 12 case the set-plus-default schema exists for — Year 11 runs BSS
     * and WAEC, Year 12 runs WAEC alone, so a BSS pupil resolves through the default.
     */
    private function resolveExamType(ClassLevel $targetLevel): ?int
    {
        $sourceExamTypeId = $this->curriculum->exam_type_id;

        $inTargetSet = ClassLevelExamType::withoutGlobalScope(SchoolScope::class)
            ->where('class_level_id', $targetLevel->id)
            ->where('exam_type_id', $sourceExamTypeId)
            ->exists();

        if ($inTargetSet) {
            return (int) $sourceExamTypeId;
        }

        return $targetLevel->default_exam_type_id === null
            ? null
            : (int) $targetLevel->default_exam_type_id;
    }

    /**
     * Find (or create) the curriculum for a level's FIRST participating slot in the target session.
     *
     * Terms are never created — if the class level participates in a slot the target session has no
     * Term row for, this returns null and the pupil is left unresolved for a re-run after the calendar
     * is entered. Fabricating an academic calendar as a side effect of a rollover is not this job's
     * business, and the same discipline governs MoveFromTermJob.
     */
    private function resolveTargetCurriculum(ClassLevel $level, ClassLevelArm $arm, ?int $examTypeId): ?Curriculum
    {
        $firstSlot = ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $this->schoolId)
            ->where('class_level_id', $level->id)
            ->orderBy('term_order')
            ->first();

        if ($firstSlot === null) {
            Log::warning('MoveToNextYearJob: class level has no participating term slots', [
                'curriculum_id' => $this->curriculum->id,
                'class_level_id' => $level->id,
            ]);

            return null;
        }

        $targetTerm = Term::withoutGlobalScope(SchoolScope::class)
            ->where('academic_session_id', $this->targetSession->id)
            ->where('order', $firstSlot->term_order)
            ->first();

        if ($targetTerm === null) {
            Log::warning('MoveToNextYearJob: target session has no Term row for the first participating slot', [
                'curriculum_id' => $this->curriculum->id,
                'target_session_id' => $this->targetSession->id,
                'term_order' => $firstSlot->term_order,
            ]);

            return null;
        }

        $isCcm = (bool) $firstSlot->is_ccm;

        return Curriculum::withoutGlobalScope(SchoolScope::class)->firstOrCreate(
            [
                'school_id' => $this->schoolId,
                'term_id' => $targetTerm->id,
                'class_level_arm_id' => $arm->id,
                'exam_type_id' => $examTypeId,
                'is_ccm' => $isCcm,
            ],
            [
                'min_subjects' => $this->curriculum->min_subjects,
                'status' => 'active',
                // RESOLVED from the target level, never copied — see the class docblock.
                'grading_scheme_id' => $level->grading_scheme_id,
                'marking_scheme_id' => $this->resolveMarkingSchemeId($isCcm),
            ]
        );
    }

    /**
     * (school, is_ccm, latest version) — the same shape MoveFromCcmJob::attachMarkingComponents uses.
     * NULL is legitimate and drops the curriculum onto the legacy per-subject component path.
     */
    private function resolveMarkingSchemeId(bool $isCcm): ?int
    {
        return MarkingScheme::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->active()
            ->where('school_id', $this->schoolId)
            ->where('is_ccm', $isCcm)
            ->latest('version')
            ->first()?->id;
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
