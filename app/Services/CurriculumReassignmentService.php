<?php

namespace App\Services;

use App\Enums\StudentStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Models\Curriculum;
use App\Models\StudentCurriculum;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Move a placed pupil from one curriculum to another, after the migration jobs have run.
 *
 * ONE OPERATION, THREE USES: correcting a wrong arm (8B -> 8S), rebalancing a distributed cohort, and
 * sending an over-promoted pupil back a level. All three vacate one episode, land the pupil in
 * another, and keep the promotion chain pointing at where the pupil actually is.
 *
 * ── THIS SERVICE IS THE SIXTH WRITER OF promoted_to_id, AND ITS WRITE IS A REPOINT ────────────────
 * The other five (StudentCurriculumController::promote, BackfillPastTermJob, MoveFromCcmJob,
 * MoveFromTermJob, MoveToNextYearJob) all write a FRESH promotion — status and link together on a row
 * that had neither. That is where the ordering rule bites: the promoted_requires_link trigger
 * (2026_08_20_140000, live on 5.7 where the CHECK it replaced never ran) rejects a row that becomes
 * `promoted` while its link is still NULL.
 *
 * THIS write is not that. It updates `promoted_to_id` ALONE, on a row that is already `promoted` with
 * a non-null link before and after — so the row never passes through the state the trigger forbids,
 * and the link-before-status ordering the other five must observe simply does not apply here. Stated
 * explicitly because "the sixth writer of promoted_to_id" invites the assumption that it needs the
 * same ceremony, and adopting ceremony that does not apply is how a rule stops being understood.
 *
 * ── WHY enroll() CANNOT BE REUSED, AND WHY THE REVIVE EXISTS ──────────────────────────────────────
 * CurriculumEnrollmentService::enroll() throws on an existing ACTIVE enrollment, needs the caller to
 * own the transaction boundary, and — the trap — guards with `whereNull('ended_at')`, which PASSES
 * for a soft-ended episode and then collides with the `(student_id, curriculum_id)` UNIQUE, which has
 * no `ended_at` in it. A pupil moved 12S -> 12B -> 12S is exactly that case: their old 12S episode is
 * soft-ended, not absent. So the destination is found-or-REVIVED rather than created.
 *
 * ── WHAT A REVIVE RESURRECTS, WHICH IS MORE THAN THE ROW ──────────────────────────────────────────
 * The UNIQUE forces reuse of the prior row, so reviving clears `ended_at` on the SAME episode — and
 * everything hanging off it comes back with it: the pupil's previous `student_subjects` for that
 * curriculum, their statuses, AND ANY SCORES ENTERED DURING THE EARLIER STINT. For a pupil returning
 * to a class they were in, that continuity is the point. It is also the strongest reason the
 * pre-results assumption below has to hold: a resurrected score is a documented consequence here, not
 * a surprise discovered later in a broadsheet.
 *
 * The compulsory attach is therefore ADDITIVE — it fills in what the destination curriculum now
 * requires without disturbing what the pupil already had there.
 *
 * ── LIMITATION, STATED NOT FIXED: MARKS DO NOT FOLLOW THE PUPIL ───────────────────────────────────
 * Scores and optional-subject selections on the VACATED episode stay on it. The episode is
 * soft-ended, never deleted, so that work is preserved as history — but it does not travel to the
 * destination. Reassignment is expected to happen before results are entered; a mid-term move leaves
 * marks behind in the old arm by design.
 */
class CurriculumReassignmentService
{
    public function __construct(
        private StudentSubjectService $subjectService,
    ) {}

    /**
     * @return StudentCurriculum the episode the pupil now holds
     *
     * @throws BusinessRuleException on a cross-school target
     */
    public function reassign(
        StudentCurriculum $current,
        Curriculum $newCurriculum,
        User $performedBy,
        ?string $reason = null,
    ): StudentCurriculum {
        // ISOLATION FIRST, and by id rather than by anything the caller passed alongside. The
        // composite FKs already make a cross-school episode unwritable; refusing here turns an opaque
        // engine error into a business rule, and covers the destination curriculum, which no FK on
        // this path constrains against the PUPIL's school.
        if ((int) $newCurriculum->school_id !== (int) $current->school_id) {
            throw new BusinessRuleException('The target curriculum belongs to a different school.');
        }

        // IDEMPOTENCY, BY NO-OP, BEFORE ANY WRITE.
        //
        // Already in the destination: "move X to 12S" when X is in 12S must not vacate 12S — the
        // naive implementation soft-ends the episode and then revives it, which churns ended_at and
        // (worse) briefly leaves the pupil placed nowhere inside the transaction.
        if ((int) $current->curriculum_id === (int) $newCurriculum->id) {
            return $current;
        }

        // Already vacated: softEnd() throws on an ended episode, so a re-run of a completed move would
        // fail rather than no-op. Returning the episode the pupil actually holds is the honest answer.
        if ($current->isEnded()) {
            return $this->existingEpisodeFor($current, $newCurriculum) ?? $current;
        }

        return DB::transaction(function () use ($current, $newCurriculum, $performedBy, $reason) {
            $destination = $this->findOrRevive($current, $newCurriculum);

            $this->repointIncomingPromotion($current, $destination);

            // The vacated episode ends TRANSFERRED — the pupil did not leave the school, which is why
            // `withdrawn` could not be reused (2026_08_21_100000 adds the value). softEnd sets status
            // and ended_at together, which is safe here: this row is a promotion TARGET, so the
            // trigger's subject (`status = 'promoted'`) is not in play.
            app(CurriculumEnrollmentService::class)
                ->softEnd($current, $performedBy, StudentStatusEnum::TRANSFERRED, $reason);

            return $destination->refresh();
        });
    }

    /**
     * The destination episode: revived if the pupil has been here before, created if not.
     *
     * Deliberately queries WITHOUT filtering on ended_at — an ended row is the case this exists for,
     * and filtering it out is precisely the mistake enroll() makes before hitting the UNIQUE.
     */
    private function findOrRevive(StudentCurriculum $current, Curriculum $newCurriculum): StudentCurriculum
    {
        $existing = StudentCurriculum::where('student_id', $current->student_id)
            ->where('curriculum_id', $newCurriculum->id)
            ->first();

        if ($existing === null) {
            $destination = StudentCurriculum::create([
                'student_id' => $current->student_id,
                'curriculum_id' => $newCurriculum->id,
                'status' => StudentStatusEnum::ACTIVE,
            ]);
        } else {
            // REVIVE. Clearing ended_at/ended_by/end_reason together with the status is what makes the
            // row read as current again; leaving any of them set would reproduce the "ended but reads
            // as active" shape softEnd was written to close, in mirror image.
            $existing->update([
                'status' => StudentStatusEnum::ACTIVE,
                'ended_at' => null,
                'ended_by_user_id' => null,
                'end_reason' => null,
            ]);

            $destination = $existing;
        }

        // Additive: fills in what the destination requires without disturbing subjects (and scores)
        // the pupil already had there from an earlier stint.
        $this->subjectService->autoAttachCompulsorySubjects($destination);

        return $destination;
    }

    /**
     * Repoint the promotion that pointed AT the vacated episode, so the chain follows the pupil.
     *
     * A single-column update on a row that is `promoted` with a non-null link throughout — see the
     * class docblock on why the trigger's ordering rule does not apply.
     *
     * NO REFERRER IS A NORMAL CASE, NOT A MISSING ONE. A held repeater's episode carries no incoming
     * link by design (MoveToNextYearJob leaves their source `repeated` with a NULL promoted_to_id, so
     * that `promoted_to_id` keeps meaning "was promoted"), and a first enrollment has no predecessor
     * at all. Both simply have nothing to repoint — which is also why this service takes the current
     * episode explicitly instead of deriving it from a link that may not exist.
     *
     * ── SENDING A PUPIL BACK A LEVEL REVERSES A PROMOTION; IT DOES NOT REDIRECT ONE ────────────────
     * When the destination IS the referrer — the over-promoted pupil moved back into the very episode
     * they were promoted OUT of — a plain repoint would set that row's promoted_to_id to its own id.
     * Every constraint accepts it: the composite FK is satisfied (same pupil, same school, the id
     * exists) and the trigger is not in play (the revive has already made the row `active`, and it
     * only guards `promoted` rows). So nothing would have rejected a row that claims to have been
     * promoted into itself; it would simply have sat there, read as a promotion by the promotedTo
     * relation and by promotion reporting, forever.
     *
     * The correct reading is that the promotion is being UNDONE: the pupil is back where they started,
     * so the link is cleared rather than pointed at itself. Caught by the back-a-level test, which is
     * why that case is worth having as a first-class test and not an afterthought.
     */
    private function repointIncomingPromotion(StudentCurriculum $current, StudentCurriculum $destination): void
    {
        // CAPTURED BEFORE ANY WRITE, deliberately. Reading this AFTER the mass update below would be
        // correct only because the in-memory model happens to be stale — the update is a query-builder
        // write that does not refresh it. Depending on staleness is the kind of accident that survives
        // until someone adds a refresh() and silently reintroduces the self-link.
        $destinationWasTheReferrer = (int) $destination->promoted_to_id === (int) $current->id;

        // The exclusion keeps a self-link from ever being written, even transiently inside the
        // transaction. It is defence in depth: the clear below is what actually carries the
        // invariant, and a mutation removing this line alone does NOT fail the suite. Kept anyway,
        // because "written then corrected" and "never written" differ under a partial failure.
        StudentCurriculum::where('promoted_to_id', $current->id)
            ->where('id', '!=', $destination->id)
            ->update(['promoted_to_id' => $destination->id]);

        if ($destinationWasTheReferrer) {
            $destination->update(['promoted_to_id' => null]);
        }
    }

    private function existingEpisodeFor(StudentCurriculum $current, Curriculum $newCurriculum): ?StudentCurriculum
    {
        return StudentCurriculum::where('student_id', $current->student_id)
            ->where('curriculum_id', $newCurriculum->id)
            ->first();
    }
}
