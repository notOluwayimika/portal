<?php

namespace App\Http\Resources;

use App\Models\StudentCurriculum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StudentCurriculum
 *
 * JsonResource proxies every unknown property and method to the wrapped model via
 * __get/__call, which PHPStan cannot see — so each `$this->some_column` here was an
 * "undefined property" and nine of them sat in the baseline. Declaring the mixin
 * states the proxy explicitly and resolves them all, including the newly added
 * key_stage_coordinator_comment that would otherwise have been a tenth.
 */
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
            // promotedTo is an EPISODE (S1 commit 5), not a curriculum. Serialise the episode's uuid plus its
            // curriculum — a { id: uuid, curriculum } wrapper. The shape is correct because it matches the
            // `promoted_to` TypeScript type this resource feeds (models.ts), never a raw auto-increment id.
            'promoted_to' => $this->whenLoaded('promotedTo', function () {
                // $this->resource (the wrapped StudentCurriculum) is typed mixed, so this reads the loaded
                // episode without the undefined-property noise a resource gets accessing $this->promotedTo.
                $episode = $this->resource->promotedTo;

                return [
                    'id' => $episode->uuid,
                    'curriculum' => $episode->curriculum
                        ? (new CurriculumResource($episode->curriculum))->withoutSubjects()
                        : null,
                ];
            }),
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
            // The result card reads this to print the Key Stage Coordinator's
            // comment. Its ABSENCE is why the coordinator's NAME appeared on the
            // sheet while their comment did not: the name comes from the
            // `keyStageCoordinator` object on the details payload, the comment from
            // the enrollment — and only the first had been wired.
            'key_stage_coordinator_comment' => $this->key_stage_coordinator_comment,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
