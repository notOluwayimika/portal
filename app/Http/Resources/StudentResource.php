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
        $currentCurriculum = $this->currentCurriculum ?? $this->studentCurricula()->latest('id')->first();
        $curriculum = $currentCurriculum?->curriculum;
        $classLevelArm = $curriculum?->classLevelArm;

        return [
            'id' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'full_name' => $this->full_name,
            'admission_number' => $this->admission_number,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth,
            'photo' => $this->photo,
            'status' => $currentCurriculum?->status,
            'class_details' => [
                'level' => $classLevelArm?->classLevel?->name,
                'arm' => $classLevelArm?->arm?->label,
                'stream' => $classLevelArm?->stream?->name,
                'full_class' => $this->student_class ?? 'N/A',
            ],
            'curriculum_id' => $curriculum?->id,
            'promoted_to_id' => $currentCurriculum?->promoted_to_id,
        ];
    }
}
