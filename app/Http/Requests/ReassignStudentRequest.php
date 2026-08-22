<?php

namespace App\Http\Requests;

use App\Enums\StudentStatusEnum;
use App\Models\Curriculum;
use App\Models\StudentCurriculum;
use App\Services\CohortSiblings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Move one placed pupil into a sibling class — 8B -> 8S — after the migration jobs have run.
 *
 * ── EVERY RULE HERE MIRRORS A GUARD THE OPERATOR CANNOT SEE ───────────────────────────────────────
 * Same discipline as UpdateClassLevelProgressionRequest: the database and the service both refuse
 * bad moves, but they refuse them as a driver error or an exception, on a screen whose entire job is
 * to tell an operator which class they may pick. So each rule restates one downstream guard in a
 * sentence, attached to the field that caused it.
 *
 * ── THE THREE FAILURES ARE SEPARATED ON PURPOSE ───────────────────────────────────────────────────
 * A foreign-school uuid, the pupil's own current class, and a same-school non-sibling are three
 * different mistakes and get three different messages. Collapsing them into one "not a valid
 * destination" would be shorter AND would hide which guard actually fired — the guard-shadowing that
 * made an earlier cross-school test pass with the school guard deleted, because a foreign-school row
 * is necessarily a non-sibling too. Distinct messages are what let each rule be tested where it acts
 * alone.
 *
 * ── WHAT IS DELIBERATELY *NOT* A RULE ─────────────────────────────────────────────────────────────
 * Reassigning a pupil BACK INTO the episode they were promoted out of is legal and needs no guard.
 * CurriculumReassignmentService reads it as a promotion being undone: it captures that the
 * destination was the referrer BEFORE any write and clears the link rather than pointing a row at
 * itself. Blocking it here would forbid the over-promoted-pupil correction the service was
 * specifically built to handle.
 */
class ReassignStudentRequest extends FormRequest
{
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
            'destination_curriculum_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var StudentCurriculum|null $episode */
            $episode = $this->route('studentCurriculum');

            if ($episode === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            // ── ONLY A LIVE PLACEMENT CAN BE MOVED ────────────────────────────────────────────────
            // The service NO-OPS on an ended episode: it returns the episode the pupil actually
            // holds, which is honest at the service layer and misleading at this one — the operator
            // gets a 200 and a success toast for a move that did not happen. It is also what a
            // double-submit looks like, since the first reassignment leaves this row ended and
            // TRANSFERRED. Refusing it here turns a silent no-op into a sentence.
            if ($episode->status !== StudentStatusEnum::ACTIVE || $episode->isEnded()) {
                $validator->errors()->add(
                    'destination_curriculum_id',
                    'This enrollment is no longer active, so there is nothing to move. Reassign the '
                    .'pupil from the class they are currently in.'
                );

                return;
            }

            $uuid = (string) $this->input('destination_curriculum_id');

            // ISOLATION. Curriculum carries the SchoolScope global scope, so a uuid from another
            // school does not resolve at all and never reaches a foreign row — a field message
            // rather than a composite-FK driver error, and the pupil's school is never named back
            // to a caller probing for it.
            $destination = Curriculum::query()->where('uuid', $uuid)->first();

            if ($destination === null) {
                $validator->errors()->add(
                    'destination_curriculum_id',
                    'That class was not found in this school.'
                );

                return;
            }

            // Checked BEFORE the sibling rule, because the pupil's own class is excluded from the
            // sibling set and would otherwise be reported as "not in the same level and term" —
            // which is both false and useless.
            if ((int) $destination->id === (int) $episode->curriculum_id) {
                $validator->errors()->add(
                    'destination_curriculum_id',
                    'A pupil cannot be reassigned to the class they are already in.'
                );

                return;
            }

            // ── THE SIBLING RULE ──────────────────────────────────────────────────────────────────
            // Nothing in the database enforces this. Every foreign key is satisfied by a Year 11
            // curriculum as the destination for a Year 8 pupil, and the service would carry out the
            // move. This is the only place that says a reassignment rearranges what the jobs did
            // instead of inventing a placement no job would produce.
            $siblingIds = CohortSiblings::for($episode)->pluck('id')->all();

            if (! in_array((int) $destination->id, array_map('intval', $siblingIds), true)) {
                $validator->errors()->add(
                    'destination_curriculum_id',
                    'That class is not an alternative arm of this pupil’s current class. A pupil can '
                    .'only be reassigned within the same year group, term and exam type.'
                );

                return;
            }

            $this->destination = $destination;
        });
    }

    /**
     * The destination, resolved school-scoped and sibling-checked. Only valid after validation
     * passes — it is set by the rule that proved it, so it cannot be read for a class that was
     * never accepted.
     */
    public function resolvedDestination(): Curriculum
    {
        if ($this->destination === null) {
            // Unreachable through the HTTP path: validation runs first and every branch above either
            // adds an error or assigns. Stated as an exception rather than a nullable return so a
            // future caller that skips validation fails loudly instead of moving a pupil to null.
            throw new \LogicException('resolvedDestination() read before validation succeeded.');
        }

        return $this->destination;
    }
}
