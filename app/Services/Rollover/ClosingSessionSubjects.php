<?php

namespace App\Services\Rollover;

use App\Jobs\MoveToNextYearJob;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Scopes\SchoolScope;

/**
 * WHERE A FRESHLY-ROLLED DESTINATION GETS ITS SUBJECTS FROM — one lookup, read by the job that seeds
 * and by the preview that promises it will.
 *
 * ── WHY THIS IS A CLASS AND NOT A PRIVATE METHOD ────────────────────────────────────────────────
 * It was a private method on {@see MoveToNextYearJob} when the seeding shipped, which was fine while
 * only the job asked the question. The preview now asks it too — to tell an operator whether a
 * destination with no subjects will be POPULATED at commit or will genuinely land empty — and a
 * screen that flags on a different rule than the commit acts on is a screen that lies in one
 * direction or the other. Two implementations could not be kept honest by testing them separately;
 * only one construction can. Same reasoning as {@see NextYearPlacementResolver}, which is shared
 * between the job and the planner precisely so preview/commit parity is a property of the code.
 *
 * ── IT KEYS ON THE IDENTITY, NOT ON A MODEL, AND THAT IS FORCED ─────────────────────────────────
 * The job holds a real destination `Curriculum`. The preview does NOT — the destinations it is
 * warning about are exactly the ones with no row yet, which is why {@see NextYearPlacement} carries
 * `curriculumKeys` rather than an id. So the shared surface takes the four identity parts both
 * callers can supply, and neither is privileged.
 *
 * ── READ-ONLY, AND THAT IS A SAFETY CLAIM, NOT A DESCRIPTION ────────────────────────────────────
 * Nothing here writes. The preview runs this for every unconfigured destination on every render, so
 * a write would build a year's worth of curricula from a registrar opening the screen — the same
 * defect the resolver's `create: false` mode exists to prevent, one layer along. The seeding itself
 * (the clone) stays in the job.
 */
final class ClosingSessionSubjects
{
    /**
     * Last year's instance of THIS destination's level: same `class_level_arm_id`, `exam_type_id`
     * and `is_ccm`, with its term inside the CLOSING session, and which actually HAS subjects.
     *
     * The keys are the DESTINATION's, never the source curriculum's — the source is a Year 11 row
     * and would seed Year 12 with Year 11's subjects, which is the cross-level carry the design
     * correctly forbids. `is_ccm` is part of the key so a CCM destination seeds from the prior CCM
     * curriculum and never from its non-CCM sibling, whose weights mean something different.
     *
     * `whereHas` rather than a plain match: a closing session can hold a bare row of its own (created
     * by the year before's rollover and never configured), and inheriting emptiness from it would
     * silently shadow a term that does have the list.
     *
     * DETERMINISTIC BY THE TERM'S ORDER, DESCENDING — the latest term of the closing session, that
     * being the most recently edited statement of what the level teaches. The id tie-break makes the
     * answer total rather than merely usually-unique.
     */
    public static function candidate(
        int $schoolId,
        int $classLevelArmId,
        int $examTypeId,
        bool $isCcm,
        ?int $closingSessionId,
    ): ?Curriculum {
        if ($closingSessionId === null) {
            return null;
        }

        return Curriculum::withoutGlobalScope(SchoolScope::class)
            ->select('curricula.*')
            ->join('terms', 'terms.id', '=', 'curricula.term_id')
            ->where('terms.academic_session_id', $closingSessionId)
            ->where('curricula.school_id', $schoolId)
            ->where('curricula.class_level_arm_id', $classLevelArmId)
            ->where('curricula.exam_type_id', $examTypeId)
            ->where('curricula.is_ccm', $isCcm)
            ->whereHas('curriculumSubjects')
            ->orderByDesc('terms.order')
            ->orderByDesc('curricula.id')
            ->first();
    }

    /**
     * WOULD THE COMMIT SEED THIS DESTINATION? The preview's question, and it is deliberately the
     * CONJUNCTION the job actually applies rather than merely "a prior exists".
     *
     * ── THE HALF THAT IS EASY TO MISS ───────────────────────────────────────────────────────────
     * The job's guard is `CurriculumSubject::where('curriculum_id', ...)->exists()` — ANY subject
     * row, not any COMPULSORY one. But the flag that surfaces a destination to the operator,
     * {@see NextYearPlacement::destinationIsUnconfigured()}, tests active COMPULSORY subjects. The
     * two differ on a real state: a destination holding one non-compulsory subject IS flagged and is
     * NOT seeded, because the "seed only when empty" guard refuses to clobber a destination somebody
     * has already touched.
     *
     * Answering "does a prior exist?" alone would therefore tell the operator that such a destination
     * will inherit and needs no action, while it lands with no compulsory subjects — the same class
     * of lie this shared lookup exists to prevent, pointing the other way. So existence of the prior
     * is necessary and not sufficient, and both halves are evaluated here rather than at the call
     * site, where only one of them would have been remembered.
     *
     * @param  int|null  $destinationCurriculumId  null when the destination has no row yet, which
     *                                             trivially satisfies the "no subjects" half
     */
    public static function willSeed(
        int $schoolId,
        int $classLevelArmId,
        int $examTypeId,
        bool $isCcm,
        ?int $closingSessionId,
        ?int $destinationCurriculumId,
    ): bool {
        if ($destinationCurriculumId !== null
            && CurriculumSubject::where('curriculum_id', $destinationCurriculumId)->exists()
        ) {
            return false;
        }

        return self::candidate($schoolId, $classLevelArmId, $examTypeId, $isCcm, $closingSessionId) !== null;
    }
}
