<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class StudentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentCurriculum = $this->currentCurriculum ?? $this->studentCurricula()->latest('id')->first();
        $curriculum = $currentCurriculum?->curriculum;
        $classLevelArm = $curriculum?->classLevelArm;

        return [
            'id' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'full_name' => $this->last_name . ', ' . $this->first_name . ' ' . $this->middle_name,
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
            'promoted_to_id' => $currentCurriculum?->promoted_to_id,
            'guardians' => $this->whenLoaded('guardians', fn() => $this->guardians->map(fn($g) => [
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
