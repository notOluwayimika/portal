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
        // Tag each subject row with the owning student's id so
        // StudentSubjectResource can look up this student's own result from
        // the shared curriculumSubject.studentResults collection, instead of
        // the frontend needing the whole class's raw scores per row.
        if ($this->relationLoaded('student') && $this->relationLoaded('studentSubjects')) {
            $studentId = $this->student?->id;

            if ($studentId) {
                $this->studentSubjects->each(
                    fn ($studentSubject) => $studentSubject->setAttribute('_result_student_id', $studentId),
                );
            }
        }

        return [
            'id' => $this->uuid,
            'student' => new StudentResource($this->whenLoaded('student')),
            // withoutSubjects(): each subject is already carried per-row
            // under 'subjects' below (with this student's own result); the
            // curriculum's full subject list here would just repeat that
            // same data once per student for no reason.
            'curriculum' => (new CurriculumResource($this->whenLoaded('curriculum')))->withoutSubjects(),
            'promoted_to' => (new CurriculumResource($this->whenLoaded('promotedTo')))->withoutSubjects(),
            'subjects' => StudentSubjectResource::collection($this->whenLoaded('studentSubjects')),
            'status' => $this->status,
            'principal_approval' => (bool) $this->principal_approval,
            'behavioral_assessments' => BehavioralAssessmentResource::collection($this->whenLoaded('behavioralAssessments')),
            'psychomotor_skills' => PsychomotorSkillResource::collection($this->whenLoaded('psychomotorSkills')),
            'form_teacher' => new TeacherResource($this->whenLoaded('formTeacher')),
            'male_boarding_parent' => new TeacherResource($this->whenLoaded('maleBoardingParent')),
            'female_boarding_parent' => new TeacherResource($this->whenLoaded('femaleBoardingParent')),
            'boarding_parent' => new TeacherResource($this->whenLoaded('boardingParent')),
            'head_of_school' => new TeacherResource($this->whenLoaded('headOfSchool')),
            'form_teacher_comment' => $this->form_teacher_comment,
            'head_of_school_comment' => $this->head_of_school_comment,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
