<?php

namespace App\Concerns;

use App\Enums\TeacherAssignmentRoleEnum;
use App\Models\ClassLevelArmTeacher;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use App\Models\Term;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared access rules for behavioral / psychomotor assessments. Boarding
 * parents record assessments for students of their gender within their
 * assigned arms; when the school has no boarding parents at all, the arm's
 * form teacher takes over.
 *
 * Hosts must also use ResolvesTermFilter (for enrollmentStatusesFor()).
 */
trait ResolvesAssessmentAccess
{
    protected function boardingParentVisibleStudentCurricula(?Term $term): Collection
    {
        $teacher = Teacher::where('user_id', auth()->id())->first();

        if (!$teacher) {
            return new Collection();
        }

        $assignments = ClassLevelArmTeacher::where('teacher_id', $teacher->id)
            ->where('role', TeacherAssignmentRoleEnum::BOARDING_PARENT->value)
            ->inActiveSchool()
            ->get(['class_level_arm_id', 'gender']);

        if ($assignments->isEmpty()) {
            return new Collection();
        }

        if (!$term) {
            return new Collection();
        }

        return StudentCurriculum::query()
            ->whereIn('status', $this->enrollmentStatusesFor($term))
            ->whereHas('curriculum', fn($query) => $query->where('term_id', $term->id))
            ->where(function ($query) use ($assignments) {
                foreach ($assignments as $assignment) {
                    $query->orWhere(function ($groupQuery) use ($assignment) {
                        $groupQuery
                            ->whereHas('curriculum', fn($q) => $q->where('class_level_arm_id', $assignment->class_level_arm_id))
                            ->whereHas('student', fn($q) => $q->where('gender', $assignment->gender?->value));
                    });
                }
            })
            ->with([
                'student',
                'curriculum.classLevelArm.classLevel',
                'curriculum.classLevelArm.arm',
                'curriculum.classLevelArm.stream',
                'behavioralAssessments' => fn($query) => $query->where('assessment_term_id', $term->id),
                'psychomotorSkills' => fn($query) => $query->where('assessment_term_id', $term->id),
            ])
            ->get();
    }

    protected function formTeacherAssignment(): ?ClassLevelArmTeacher
    {
        $teacher = Teacher::where('user_id', auth()->id())->first();

        if (!$teacher) {
            return null;
        }

        return ClassLevelArmTeacher::where('teacher_id', $teacher->id)
            ->where('role', TeacherAssignmentRoleEnum::FORM_TEACHER->value)
            ->inActiveSchool()
            ->first();
    }

    protected function schoolHasBoardingParents(): bool
    {
        return ClassLevelArmTeacher::where('role', TeacherAssignmentRoleEnum::BOARDING_PARENT->value)
            ->inActiveSchool()
            ->exists();
    }

    /**
     * A user may record an assessment for an enrollment when they are a
     * boarding parent who can see it, or — only in schools with no boarding
     * parents at all — when they are the form teacher of its arm.
     */
    protected function canRecordAssessmentFor(StudentCurriculum $studentCurriculum, Term $term): bool
    {
        if ($this->boardingParentVisibleStudentCurricula($term)->pluck('id')->contains($studentCurriculum->id)) {
            return true;
        }

        if ($this->schoolHasBoardingParents()) {
            return false;
        }

        $assignment = $this->formTeacherAssignment();

        return $assignment
            && (int) $studentCurriculum->curriculum?->class_level_arm_id === (int) $assignment->class_level_arm_id;
    }
}
