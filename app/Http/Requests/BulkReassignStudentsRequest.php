<?php

namespace App\Http\Requests;

use App\Enums\StudentStatusEnum;
use App\Models\Curriculum;
use App\Models\StudentCurriculum;
use App\Services\CohortSiblings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Move a whole class-cohort into a sibling arm in one action.
 *
 * ── EVERY RULE HERE MIRRORS A GUARD THE OPERATOR CANNOT SEE ───────────────────────────────────────
 * Same discipline as ReassignStudentRequest, for the same reason: the service and the database both
 * refuse bad moves, but they refuse them as an exception or a driver error, on a screen whose job is
 * to tell an operator which pupils they may move and where. Each rule restates one downstream guard
 * as a sentence.
 *
 * ── WHY THIS VALIDATES BEFORE THE TRANSACTION, AND WHY THE CONTROLLER HAS NO try/catch ────────────
 * Predictable failures are 422s from here, raised BEFORE any write opens. The controller's single
 * `DB::transaction` therefore only ever wraps writes that have already passed every rule, and it
 * catches nothing — see StudentBulkReassignmentController's docblock. The neighbouring student
 * controllers' `catch (\Throwable)` is a bug, not the house style: ValidationException is a
 * Throwable, so it would be swallowed and re-emitted as a generic 500, destroying the per-field 422
 * this screen exists to produce.
 *
 * ── THE COHORT LOCK IS THE RULE THAT MATTERS ──────────────────────────────────────────────────────
 * ONE destination is chosen for the whole batch, so every selected pupil must be one for whom that
 * destination is legal. That holds iff they all share the cohort key CohortSiblings matches on:
 * (class level, term, is_ccm). The batch is refused otherwise.
 *
 * IT USED TO DEMAND ONE SHARED `curriculum_id`, which was right while exam type was part of the
 * eligibility key and is too tight now that it is not: two pupils in Year 10 B/WAEC and Year 10 S/BSS
 * share a cohort and share their legal destinations, so refusing to move them together forbade a
 * batch exactly as safe as the single moves it decomposes into. Recorded rather than silently
 * rewritten, because the older rule reads as more careful and is not.
 *
 * The UI disabling the button when the selection spans cohorts is convenience; THIS is the
 * enforcement. Note what that means for a test: a fixture built from one arm passes against a lock
 * keyed on almost anything, so the arms that discriminate are the ones that cross an axis — a
 * selection spanning two LEVELS must refuse, and a selection spanning two exam types within one
 * level must now SUCCEED.
 */
class BulkReassignStudentsRequest extends FormRequest
{
    /**
     * Measured class maximum in the data is 24. The cap keeps a future three-form-entry school
     * honest without inventing async infrastructure for what is a sub-second synchronous operation.
     */
    public const MAX_BATCH = 60;

    /** @var EloquentCollection<int, StudentCurriculum> */
    private ?EloquentCollection $episodes = null;

    private ?Curriculum $destination = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // EPISODE uuids, not student uuids. A student uuid would silently re-derive "current"
            // at submit time, so a pupil moved by someone else between page load and click would
            // have the WRONG episode moved, with no error. An episode uuid mismatches instead.
            'episode_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'episode_ids.*' => ['required', 'uuid'],
            'destination_curriculum_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'episode_ids.required' => 'Select at least one pupil to reassign.',
            'episode_ids.min' => 'Select at least one pupil to reassign.',
            'episode_ids.max' => 'You can reassign up to '.self::MAX_BATCH
                .' pupils at once. Reassign the class in smaller groups.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var array<int, string> $uuids */
            $uuids = array_values(array_unique((array) $this->input('episode_ids')));

            // ── RESOLUTION IS THE ISOLATION GUARD ────────────────────────────────────────────────
            // StudentCurriculum carries SchoolScope, so an episode uuid from another school does not
            // resolve at all. That is a "we could not find" message rather than a composite-FK
            // driver error, and it never names another school's data back to a caller probing for
            // it. This is the refusal that is isolatable and tested — not the school predicate
            // inside CohortSiblings, which the class-level match already shadows.
            $episodes = StudentCurriculum::query()
                ->whereIn('uuid', $uuids)
                ->with(['curriculum.classLevelArm', 'student'])
                ->get();

            if ($episodes->count() !== count($uuids)) {
                $missing = count($uuids) - $episodes->count();

                $validator->errors()->add(
                    'episode_ids',
                    $missing.' of the selected '.count($uuids).' enrolments could not be found in '
                    .'this school. Refresh the list and try again.'
                );

                return;
            }

            // ── ONLY LIVE PLACEMENTS CAN BE MOVED, AND A STALE ONE NAMES ITS PUPIL ───────────────
            // The service NO-OPS on an ended episode: it returns the episode the pupil actually
            // holds, which is honest at the service layer and misleading here — the operator would
            // get a 200 for a pupil who did not move. This is also exactly what a double-submit
            // looks like, and what a pupil moved out from under the operator looks like.
            //
            // The message NAMES the pupil, because "one of your 24 selections is stale" is not
            // actionable on a screen showing 24 identical-looking rows.
            $stale = $episodes->filter(
                fn (StudentCurriculum $episode) => $episode->status !== StudentStatusEnum::ACTIVE
                    || $episode->isEnded()
            );

            if ($stale->isNotEmpty()) {
                $validator->errors()->add(
                    'episode_ids',
                    'These pupils have moved since this list was loaded, so nothing was changed: '
                    .$stale->map(fn (StudentCurriculum $e) => $this->describePupil($e))
                        ->sort()
                        ->implode(', ')
                    .'. Refresh and select them again.'
                );

                return;
            }

            // ── THE COHORT LOCK ─────────────────────────────────────────────────────────────────
            // Keyed on the COHORT — (class level, term, is_ccm) — not on curriculum_id and never on
            // the labels the screen renders.
            //
            // IT USED TO REQUIRE ONE SHARED curriculum_id, and that was too tight once exam type
            // left the eligibility key: two pupils in Year 10 B/WAEC and Year 10 S/BSS are in the
            // same cohort and have the same legal destinations, so refusing to move them together
            // forbade a batch that is exactly as safe as the single moves it decomposes into.
            //
            // WHAT THE LOCK IS ACTUALLY FOR is unchanged: one destination is chosen for the whole
            // batch, so every selected pupil must be one for whom that destination is legal. That is
            // true iff they share the cohort key CohortSiblings itself matches on — which is why
            // this triple and not some other grouping.
            $cohortKeys = $episodes->map(fn (StudentCurriculum $episode) => implode(':', [
                // Via the arm, because class level is not a column on curricula.
                $episode->curriculum?->classLevelArm?->class_level_id,
                $episode->curriculum?->term_id,
                (int) (bool) $episode->curriculum?->is_ccm,
            ]))->unique()->values();

            if ($cohortKeys->count() !== 1) {
                $validator->errors()->add(
                    'episode_ids',
                    'Reassign works within one class level and term; your selection spans '
                    .$cohortKeys->count().' cohorts. Select pupils from a single year group and term.'
                );

                return;
            }

            /** @var StudentCurriculum $reference */
            $reference = $episodes->first();

            $uuid = (string) $this->input('destination_curriculum_id');

            // Same isolation reasoning as the episodes above: a foreign uuid does not resolve.
            $destination = Curriculum::query()->where('uuid', $uuid)->first();

            if ($destination === null) {
                $validator->errors()->add(
                    'destination_curriculum_id',
                    'That class was not found in this school.'
                );

                return;
            }

            // ── ALREADY THERE IS ONLY A REFUSAL WHEN IT IS TRUE OF EVERYONE ─────────────────────
            // It used to be `destination === the one shared curriculum`, which was exact while the
            // lock demanded a single curriculum_id. With mixed sources allowed it would refuse a
            // legitimate batch: selecting 10B and 10S pupils and moving them all into 10B is real
            // work for the 10S half, and the 10B half no-ops in the service (idempotent, by design).
            //
            // So this fires only when the batch would move NOBODY — which is the case actually worth
            // a message, because it is the one where a success toast would be a lie.
            $alreadyThere = $episodes->every(
                fn (StudentCurriculum $episode) => (int) $episode->curriculum_id === (int) $destination->id
            );

            if ($alreadyThere) {
                $validator->errors()->add(
                    'destination_curriculum_id',
                    'These pupils are already in that class.'
                );

                return;
            }

            // ── THE ELIGIBILITY RULE, READ FROM M3's DEFINITION ─────────────────────────────────
            // Reusing CohortSiblings rather than restating it is what stops single and bulk
            // reassignment drifting into two different ideas of which move is legal.
            //
            // PLUS THE REFERENCE'S OWN CURRICULUM, and that addition is not a loosening. Siblings
            // are computed for ONE episode and deliberately exclude that episode's own curriculum —
            // correct for a single move, wrong for a batch, because another pupil in the selection
            // may legitimately be moving INTO the class the reference is already sitting in. The
            // union is exactly "the cohort", expressed through the one definition rather than by
            // re-deriving the triple here and giving it a second place to drift.
            $validIds = CohortSiblings::for($reference)
                ->pluck('id')
                ->push($reference->curriculum_id)
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! in_array((int) $destination->id, $validIds, true)) {
                $validator->errors()->add(
                    'destination_curriculum_id',
                    'That class is not in this cohort. Pupils can only be reassigned within the '
                    .'same year group and term. Exam type and arm may change; moving between CCM '
                    .'and non-CCM cannot be done here.'
                );

                return;
            }

            $this->episodes = $episodes;
            $this->destination = $destination;
        });
    }

    /**
     * The episodes, resolved school-scoped, proven active and proven to share one curriculum.
     *
     * @return EloquentCollection<int, StudentCurriculum>
     */
    public function resolvedEpisodes(): EloquentCollection
    {
        if ($this->episodes === null) {
            // Unreachable through the HTTP path: validation runs first and every branch above either
            // adds an error or assigns. An exception rather than a nullable return so a future
            // caller that skips validation fails loudly instead of moving nobody, silently.
            throw new \LogicException('resolvedEpisodes() read before validation succeeded.');
        }

        return $this->episodes;
    }

    public function resolvedDestination(): Curriculum
    {
        if ($this->destination === null) {
            throw new \LogicException('resolvedDestination() read before validation succeeded.');
        }

        return $this->destination;
    }

    /**
     * "Ada Obi (BRK/2024/019)" — enough for an operator to find the row among two dozen identical
     * class labels. Falls back to the episode uuid when the pupil record is gone, which is
     * reachable: student_curricula rows outlive a soft-deleted student.
     */
    private function describePupil(StudentCurriculum $episode): string
    {
        $student = $episode->student;

        if ($student === null) {
            return 'enrolment '.$episode->uuid;
        }

        $name = trim($student->first_name.' '.$student->last_name);

        return $student->admission_number ? $name.' ('.$student->admission_number.')' : $name;
    }
}
