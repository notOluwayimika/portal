<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentCurriculumResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'student' => new StudentResource($this->whenLoaded('student')),
            'curriculum' => new CurriculumResource($this->whenLoaded('curriculum')),
            'promoted_to' => new CurriculumResource($this->whenLoaded('promotedTo')),
            'subjects' => StudentSubjectResource::collection($this->whenLoaded('studentSubjects')),
            'status' => $this->status,
            'behavioral_assessments' => BehavioralAssessmentResource::collection($this->whenLoaded('behavioralAssessments')),
            'form_teacher' => new TeacherResource($this->whenLoaded('formTeacher')),
            'male_boarding_parent' => new TeacherResource($this->whenLoaded('maleBoardingParent')),
            'female_boarding_parent' => new TeacherResource($this->whenLoaded('femaleBoardingParent')),
            'boarding_parent' => new TeacherResource($this->whenLoaded('boardingParent')),
            'head_of_school' => new TeacherResource($this->whenLoaded('headOfSchool')),
            'form_teacher_comment' => $this->form_teacher_comment,
            'head_of_school_comment' => $this->head_of_school_comment,
        ];
    }
}
