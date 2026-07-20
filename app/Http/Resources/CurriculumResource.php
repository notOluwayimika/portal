<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumResource extends JsonResource
{
    protected $includeSubjects = true;

    public function withoutSubjects()
    {
        $this->includeSubjects = false;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'term' => new TermResource($this->term),
            'academic_session' => new SessionResource($this->academicSession),
            'class_level_arm' => new ClassLevelArmResource($this->classLevelArm),
            'exam_type' => new ExamTypeResource($this->examType),
            'min_subjects' => $this->min_subjects,
            'status' => $this->status,
            'is_ccm' => $this->is_ccm,
            'grading_mode' => $this->grading_scheme_id ? 'categorical' : 'numeric',
            'grading_scheme' => $this->gradingScheme ? new GradingSchemeResource($this->gradingScheme) : null,
            'full_name' => $this->fullName(),

            'curriculum_subjects' => $this->when(
                $this->includeSubjects,
                CurriculumSubjectResource::collection($this->curriculumSubjects)
            ),
            'student_curricula' => StudentCurriculumResource::collection($this->whenLoaded('studentCurricula')),
        ];
    }

    /**
     * Human-readable curriculum label, e.g. "2025/2026 JSS 1 A Science Midterm First Term".
     *
     * Every hop is nullsafe, matching the `?->` idiom already used across the
     * codebase for exactly this chain (cf. GuardianResource, StudentResource).
     * The previous version chained six dereferences raw and only guarded `stream`,
     * so a single missing link — academicSession, classLevelArm, its classLevel or
     * arm, examType or term — threw "Attempt to read property on null" and **500'd
     * every response carrying a curriculum**, including the guardian students list.
     * Same null-deref family as the `students:343` bug (`currentCurriculum->load()`).
     *
     * These are all FK-backed and non-nullable in schema, so this should not happen
     * in production — but "should not" is what the students-list bug also assumed,
     * and a label is never worth a 500. Absent parts are OMITTED rather than
     * rendered as empty strings, so a partial label degrades to a shorter one
     * instead of a run of stray spaces.
     */
    private function fullName(): string
    {
        $arm = $this->classLevelArm;

        return implode(' ', array_filter([
            $this->academicSession?->name,
            $arm?->classLevel?->name,
            $arm?->arm?->label,
            $arm?->stream?->name,
            $this->examType?->name,
            $this->term?->name,
            $this->is_ccm ? '(CCM)' : null,
        ]));
    }
}
