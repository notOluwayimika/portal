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
            'curriculum_id' => $curriculum?->id,
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
