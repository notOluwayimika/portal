<?php

namespace App\Support;

use App\Concerns\ResolvesAssessmentAccess;
use App\Enums\TeacherAssignmentRoleEnum;
use App\Models\ClassLevelArmTeacher;

/**
 * Whether the active School runs boarding at all.
 *
 * ONE predicate, deliberately, because it answers two questions that must never
 * disagree:
 *
 *  1. WHO MAY AUTHOR a behavioral assessment — boarding parents where the school
 *     has them, otherwise the arm's form teacher takes over
 *     ({@see ResolvesAssessmentAccess::canRecordAssessmentFor}).
 *  2. HOW THE RESULT SHEET LABELS that assessment's comment.
 *
 * They were previously answered by different things: authorship by the predicate
 * below, the label by nothing at all. So a day school's form tutor wrote the
 * comment and the printed result attributed it to a "Boarding Parent" who does
 * not exist. Reading both off this one function is what makes that class of
 * mislabelling unrepresentable — resist adding a second source of truth (a
 * `schools.has_boarding` column was considered and rejected for exactly this
 * reason: a flag set false while assignments exist would hide a comment the
 * authorship rule still permits).
 *
 * DERIVED FROM ASSIGNMENTS, NOT ROLES. A user merely holding the
 * `boarding_parent` role is not boarding provision — the result sheet resolves a
 * boarding parent through `class_level_arm_teacher` (per arm, per gender), so
 * that same table is what "has boarding" has to mean.
 */
final class Boarding
{
    /**
     * Does the ACTIVE school have any boarding-parent assignment?
     *
     * School-scoped through `inActiveSchool()`: `class_level_arm_teacher` carries
     * no `school_id` of its own, and a teacher visible in several schools
     * (`school_user`) would otherwise make one school's boarding provision look
     * like every school's.
     */
    public static function schoolHasParents(): bool
    {
        return ClassLevelArmTeacher::where('role', TeacherAssignmentRoleEnum::BOARDING_PARENT->value)
            ->inActiveSchool()
            ->exists();
    }
}
