<?php

namespace App\Services\Rollover;

use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\ClassLevelArm;
use App\Models\ClassLevelArmProgression;
use App\Models\ClassLevelExamType;
use App\Models\ClassLevelTermParticipation;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\MarkingScheme;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentCurriculum;
use App\Models\Term;
use Illuminate\Support\Collection;

/**
 * The end-of-year placement rules, lifted out of MoveToNextYearJob so the PLANNER can ask the same
 * question the job answers — see {@see NextYearPlacement} for why a second implementation was not an
 * option.
 *
 * ── TWO MODES, AND EXACTLY ONE DIFFERENCE BETWEEN THEM ──────────────────────────────────────────
 * `$create = true` (the job) resolves the destination with firstOrCreate, as it always did.
 * `$create = false` (the preview) resolves the SAME five keys and LOOKS THEM UP. A preview that
 * wrote would create a year's worth of curricula every time a registrar opened the screen.
 *
 * Everything before the destination — target level, arm, exam type — is shared code, not two
 * parallel paths. So the modes cannot disagree about WHERE a pupil goes, only about whether the
 * destination is brought into existence, which is the whole reason preview/commit parity is
 * provable rather than merely tested.
 *
 * ── SCOPES ARE DROPPED AND THE SCHOOL IS PINNED EXPLICITLY ──────────────────────────────────────
 * Same discipline as NextTermSlot: this is called from a job with no ambient school AND from a
 * request that has one, so every query drops SchoolScope and pins `school_id` from the caller's own
 * `$schoolId` rather than inferring it (Constitution 13).
 *
 * ── ONE CONTEXT, MANY PUPILS ────────────────────────────────────────────────────────────────────
 * The source curriculum, school and target session are constant for every pupil in a curriculum;
 * only the episode varies. Holding them here keeps the per-pupil call to three arguments, and lets
 * the target level and arm list be resolved once per curriculum rather than once per pupil — which
 * matters for the planner, walking a whole year group.
 */
final class NextYearPlacementResolver
{
    /** @var array<int, Collection<int, ClassLevelArm>> keyed by class level id */
    private array $armCache = [];

    /** @var array<int, bool> keyed by curriculum id */
    private array $subjectCache = [];

    /** @var array<int, int|null> keyed by class level id */
    private array $examTypeCache = [];

    /**
     * @var array<string, NextYearPlacement> keyed by "levelId:armId:examTypeId"
     *
     * WRITE-MODE RESULTS ARE NOT CACHED. In read-only mode the destination is a pure function of
     * (level, arm, examType) and the same answer serves every pupil bound for it. The write path
     * firstOrCreates, and caching a create would hide from the second caller that the row now exists
     * — harmless today because firstOrCreate is idempotent, but it is the kind of "cache a write"
     * that stops being harmless the moment anything downstream reads `wasRecentlyCreated`.
     */
    private array $destinationCache = [];

    public function __construct(
        private readonly Curriculum $source,
        private readonly int $schoolId,
        private readonly AcademicSession $targetSession,
    ) {}

    /**
     * NULL means TERMINAL — the graduating year, out of which nobody is promoted. It is a
     * configuration answer, not a missing one, so it is not a warning. Resolved once per curriculum.
     */
    public function targetLevel(ClassLevel $sourceLevel): ?ClassLevel
    {
        if ($sourceLevel->next_class_level_id === null) {
            return null;
        }

        return ClassLevel::withoutGlobalScope(SchoolScope::class)
            ->whereKey($sourceLevel->next_class_level_id)
            ->first();
    }

    /**
     * An ADVANCER: one level up, arm resolved, exam type carried or defaulted.
     */
    public function forAdvancer(
        StudentCurriculum $episode,
        ClassLevelArm $sourceArm,
        ?ClassLevel $targetLevel,
        bool $create,
    ): NextYearPlacement {
        if ($targetLevel === null) {
            return new NextYearPlacement(NextYearPlacement::TERMINAL_LEVEL);
        }

        $arm = $this->resolveArm($episode, $sourceArm, $targetLevel);

        if ($arm instanceof NextYearPlacement) {
            return $arm;
        }

        $examTypeId = $this->resolveExamType($targetLevel);

        if ($examTypeId === null) {
            return new NextYearPlacement(NextYearPlacement::NO_EXAM_TYPE, arm: $arm);
        }

        return $this->destination($targetLevel, $arm, $examTypeId, $create);
    }

    /**
     * A HELD REPEATER: the SAME level, the SAME arm, the source's own exam type — fully
     * deterministic, which is why the job anchors them on firstOrCreate rather than a promotion link.
     *
     * They reach the destination through the identical path, so a repeater whose same-level
     * curriculum does not exist in the target session lands subject-less exactly as an advancer
     * would. That is why the preview must flag their destinations too.
     */
    public function forRepeater(ClassLevelArm $sourceArm, ClassLevel $sourceLevel, bool $create): NextYearPlacement
    {
        return $this->destination($sourceLevel, $sourceArm, (int) $this->source->exam_type_id, $create);
    }

    /**
     * Arm resolution, in strict order: explicit map, then stream-aware label match, then the target
     * level's distribution strategy. Lifted verbatim from MoveToNextYearJob::resolveArm.
     *
     * @return ClassLevelArm|NextYearPlacement the arm, or a refusal carrying its reason
     */
    private function resolveArm(StudentCurriculum $episode, ClassLevelArm $sourceArm, ClassLevel $targetLevel): ClassLevelArm|NextYearPlacement
    {
        // 1 — EXPLICIT MAP, VALIDATED AGAINST THE PROGRESSION TARGET. No FK ties the mapped target to
        // the source level's next_class_level_id, so an operator can map 7A into a Year 9 arm and
        // every constraint accepts it. A mismatched map is REFUSED, not fallen through: falling
        // through would quietly place the pupil somewhere else and hide a misconfiguration.
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

            return new NextYearPlacement(
                NextYearPlacement::MAP_OUTSIDE_TARGET,
                mappedClassLevelId: $mappedArm?->class_level_id === null ? null : (int) $mappedArm->class_level_id,
            );
        }

        // 2 — LABEL MATCH, STREAM-AWARE. class_level_arms is UNIQUE on
        // (class_level_id, arm_id, stream_id), so a label alone can identify two arms in one level
        // that differ only by stream.
        $labelMatch = $this->targetArms($targetLevel)
            ->first(fn (ClassLevelArm $arm) => $arm->arm?->label === $sourceArm->arm?->label
                && (int) $arm->stream_id === (int) $sourceArm->stream_id);

        if ($labelMatch !== null) {
            return $labelMatch;
        }

        // 3 — DISTRIBUTION, GOVERNED BY THE TARGET LEVEL.
        $arms = $this->targetArms($targetLevel);

        if ($arms->isEmpty()) {
            return new NextYearPlacement(NextYearPlacement::NO_ARM);
        }

        if ($targetLevel->arm_distribution_strategy === 'explicit_only') {
            return new NextYearPlacement(NextYearPlacement::EXPLICIT_ONLY_NO_MATCH);
        }

        // PURE FUNCTION OF THE PUPIL — never a counter. This is what makes the placement previewable
        // at all: 7A and 7B both feeding 8D/8E would each start at D under a per-job round-robin, and
        // any shared counter would race between concurrent jobs. student_id % armCount over a fixed
        // order needs no coordination, is stable across re-runs, and gives the preview the same
        // answer the commit will reach.
        return $arms[(int) $episode->student_id % $arms->count()];
    }

    /**
     * The target level's arms in a FIXED, documented order — by id ascending. The order is part of
     * the placement contract: change it and every pupil's modulo lands somewhere else.
     *
     * Cached per level: the planner asks this once per pupil, and it is a property of the level.
     *
     * @return Collection<int, ClassLevelArm>
     */
    private function targetArms(ClassLevel $targetLevel): Collection
    {
        return $this->armCache[(int) $targetLevel->id] ??= ClassLevelArm::withoutGlobalScope(SchoolScope::class)
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
     */
    private function resolveExamType(ClassLevel $targetLevel): ?int
    {
        $levelId = (int) $targetLevel->id;

        if (array_key_exists($levelId, $this->examTypeCache)) {
            return $this->examTypeCache[$levelId];
        }

        return $this->examTypeCache[$levelId] = $this->computeExamType($targetLevel);
    }

    /**
     * `array_key_exists` rather than `??=`, because NULL is a MEANINGFUL answer here — "the target
     * level runs neither this exam type nor a default, so refuse rather than guess". `??=` would
     * treat that as a cache miss and re-query for every pupil in the cohort, which is the one case
     * where the answer is guaranteed identical.
     */
    private function computeExamType(ClassLevel $targetLevel): ?int
    {
        $sourceExamTypeId = $this->source->exam_type_id;

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
     * The destination for a level's FIRST participating slot in the target session.
     *
     * THE FIVE KEYS ARE BUILT ONCE, HERE, and both modes are fed from that one array. Terms are never
     * created: if the class level participates in a slot the target session has no Term row for, this
     * refuses and the pupil is left for a re-run after the calendar is entered.
     */
    private function destination(ClassLevel $level, ClassLevelArm $arm, int $examTypeId, bool $create): NextYearPlacement
    {
        // Memoised for the PREVIEW only — the planner asks once per pupil and the answer is a pure
        // function of (level, arm, examType). Five queries per pupil became five per destination.
        $cacheKey = $level->id.':'.$arm->id.':'.$examTypeId;

        if (! $create && isset($this->destinationCache[$cacheKey])) {
            return $this->destinationCache[$cacheKey];
        }

        $placement = $this->resolveDestination($level, $arm, $examTypeId, $create);

        if (! $create) {
            $this->destinationCache[$cacheKey] = $placement;
        }

        return $placement;
    }

    private function resolveDestination(ClassLevel $level, ClassLevelArm $arm, int $examTypeId, bool $create): NextYearPlacement
    {
        $firstSlot = ClassLevelTermParticipation::withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $this->schoolId)
            ->where('class_level_id', $level->id)
            ->orderBy('term_order')
            ->first();

        if ($firstSlot === null) {
            return new NextYearPlacement(NextYearPlacement::NO_PARTICIPATING_SLOT, arm: $arm, examTypeId: $examTypeId);
        }

        $targetTerm = Term::withoutGlobalScope(SchoolScope::class)
            ->where('academic_session_id', $this->targetSession->id)
            ->where('order', $firstSlot->term_order)
            ->first();

        if ($targetTerm === null) {
            return new NextYearPlacement(
                NextYearPlacement::NO_TERM_AT_SLOT,
                arm: $arm,
                examTypeId: $examTypeId,
                termOrder: (int) $firstSlot->term_order,
            );
        }

        // ── THE ONE CONSTRUCTION. Both the lookup and the firstOrCreate read THIS array. ──────────
        $keys = [
            'school_id' => $this->schoolId,
            'term_id' => (int) $targetTerm->id,
            'class_level_arm_id' => (int) $arm->id,
            'exam_type_id' => $examTypeId,
            'is_ccm' => (bool) $firstSlot->is_ccm,
        ];

        $curriculum = $create
            ? Curriculum::withoutGlobalScope(SchoolScope::class)->firstOrCreate($keys, [
                'min_subjects' => $this->source->min_subjects,
                'status' => 'active',
                // RESOLVED from the target level, never copied — a different class level in a
                // different session is a different context on every axis.
                'grading_scheme_id' => $level->grading_scheme_id,
                'marking_scheme_id' => $this->resolveMarkingSchemeId((bool) $firstSlot->is_ccm),
            ])
            : Curriculum::withoutGlobalScope(SchoolScope::class)->where($keys)->first();

        return new NextYearPlacement(
            NextYearPlacement::OK,
            arm: $arm,
            examTypeId: $examTypeId,
            curriculumKeys: $keys,
            curriculum: $curriculum,
            destinationHasCompulsorySubjects: $this->hasCompulsorySubjects($curriculum),
            termOrder: (int) $firstSlot->term_order,
        );
    }

    /**
     * Does this destination have anything for a pupil to study?
     *
     * The EXACT predicate StudentSubjectService::autoAttachCompulsorySubjects uses when it decides
     * what to attach — `curriculumSubjects()->active()->where('is_compulsory', true)`. Written as the
     * same query rather than as a similar one, because the two agreeing is the entire value of the
     * warning: a screen that flags on a different rule than the attach uses is a screen that lies in
     * one direction or the other.
     *
     * A null destination is false without a query — it cannot have subjects if it does not exist.
     *
     * Memoised per curriculum id: the planner asks once per PUPIL, and this is a property of the
     * CURRICULUM. Same reasoning as the arm cache, and it holds for a run because a preview is a
     * read of one moment.
     */
    private function hasCompulsorySubjects(?Curriculum $curriculum): bool
    {
        if ($curriculum === null) {
            return false;
        }

        return $this->subjectCache[(int) $curriculum->id] ??= CurriculumSubject::withoutGlobalScope(SchoolScope::class)
            ->where('curriculum_id', $curriculum->id)
            ->active()
            ->where('is_compulsory', true)
            ->exists();
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
}
