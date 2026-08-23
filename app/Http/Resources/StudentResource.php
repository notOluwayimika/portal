<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // A NULL student is reachable and must not 500.
        //
        // `students` is soft-deleted and School-scoped, while `student_curricula` rows outlive a
        // withdrawal — so `$enrolment->student` legitimately resolves to null for a student who
        // has left, and six call sites build this resource straight from that relation. Before
        // this guard the first line of the method called studentCurricula() ON NULL and took the
        // whole endpoint down: production hit exactly that on GET /api/form-teacher/students.
        //
        // Callers that can meaningfully drop the row do so (the comment and assessment listings
        // filter withdrawn students out entirely). This is the floor beneath them — losing one
        // student's name is not worth losing the page, and 'Unknown student' is already the
        // codebase's idiom for it (StudentResult::describeForAudit).
        if ($this->resource === null) {
            return [
                'id' => null,
                'full_name' => 'Unknown student',
                'withdrawn' => true,
            ];
        }

        $currentCurriculum = $this->currentCurriculum ?? $this->studentCurricula()->latest('id')->first();
        $curriculum = $currentCurriculum?->curriculum;
        $classLevelArm = $curriculum?->classLevelArm;

        return [
            'id' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'full_name' => $this->last_name.', '.$this->first_name.' '.$this->middle_name,
            'admission_number' => $this->admission_number,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth,
            'photo' => $this->photoFile?->url,
            'status' => $currentCurriculum?->status,
            'class_details' => [
                'level' => $classLevelArm?->classLevel?->name,
                'arm' => $classLevelArm?->arm?->label,
                'stream' => $classLevelArm?->stream?->name,
                'full_class' => $this->student_class ?? 'N/A',
            ],
            // ── LEAKS A RAW AUTO-INCREMENT ID, AND IS LEFT ALONE ANYWAY ───────────────────────────
            // This contradicts the convention StudentCurriculumResource states (uuids on the wire,
            // never database ids), and it is NOT fixed here: whatever consumes it today would break
            // silently, and finding that out is its own change with its own blast radius. The new
            // uuid fields below sit BESIDE it rather than replacing it, and the leak is recorded as
            // its own ticket so "we added fields next to it" does not quietly bless it forever.
            'curriculum_id' => $curriculum?->id,
            // ── THE TWO FIELDS BULK REASSIGNMENT NEEDS ────────────────────────────────────────────
            // `current_episode_id` is the EPISODE, not the pupil, and the bulk endpoint takes these
            // rather than student uuids on purpose: if a pupil is moved between page load and
            // submit, a student uuid would silently re-derive "current" and move whichever episode
            // they now hold — the wrong one, with no error. An episode uuid mismatches instead, and
            // names the pupil.
            'current_episode_id' => $currentCurriculum?->uuid,
            'curriculum_uuid' => $curriculum?->uuid,
            // ── THE COHORT KEY THE BULK LOCK COMPARES ─────────────────────────────────────────────
            // (class level, term, is_ccm) — the same triple CohortSiblings matches on, assembled
            // server-side so the client cannot re-derive it differently. It is NOT `curriculum_uuid`:
            // two pupils in different curricula of one cohort (different arm, or different exam type)
            // are reassignable together, and keying the client on curriculum would disable the button
            // for a selection the server would happily accept.
            //
            // Nor is it the class LABEL, which is the other tempting shortcut: a label collapses exam
            // type and CCM entirely, so two pupils rendering "Year 9 B" can sit in different cohorts.
            // Opaque on purpose — it is an equality token, and nothing should parse it.
            // `$classLevelArm` is reached through `$curriculum?->classLevelArm`, so a non-null arm
            // proves a non-null curriculum — hence plain `->` inside the branch, which is what
            // Larastan requires rather than merely permits.
            'cohort_key' => $classLevelArm?->class_level_id === null
                ? null
                : implode(':', [
                    $classLevelArm->class_level_id,
                    // Nullable, and the placeholder matters: two term-less curricula in one level
                    // must compare EQUAL, which `null` interpolated as '' would also achieve — but
                    // only by accident. Stated so it survives a refactor.
                    $curriculum->term_id ?? '-',
                    (int) (bool) $curriculum->is_ccm,
                ]),
            'student_curricula' => StudentCurriculumResource::collection($this->whenLoaded('studentCurricula')),
            'admission_date' => $this->admission_date?->toDateString(),
            'address' => $this->address,
            'nationality' => $this->nationality,
            'other_nationality' => $this->other_nationality,
            'state_of_origin' => $this->state_of_origin,
            'religion' => $this->religion,
            'previous_school' => $this->previous_school,
            'sport_house_id' => $this->sport_house_id,
            'sport_house' => $this->sportHouse ? new SportHouseResource($this->sportHouse) : null,
            'scholarship_id' => $this->scholarship_id,
            'scholarship' => $this->scholarship ? new ScholarshipResource($this->scholarship) : null,
            'guardians' => $this->whenLoaded('guardians', fn () => $this->guardians->map(fn ($g) => [
                'id' => $g->uuid,
                'full_name' => $g->full_name,
                'first_name' => $g->first_name,
                'last_name' => $g->last_name,
                'phone' => $g->phone,
                'email' => $g->user?->email,
                'occupation' => $g->occupation,
                'photo' => $g->photoFile?->url,
                'gender' => $g->gender,
                'city' => $g->city,
                'country' => $g->country,
                'relationship' => $g->pivot->relationship,
                'is_primary' => (bool) $g->pivot->is_primary,
                'can_login' => (bool) $g->pivot->can_login,
                'deleted_at' => $g->deleted_at,
            ])),
        ];
    }
}
