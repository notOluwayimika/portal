<?php

namespace App\Http\Controllers;

use App\Concerns\FormatsClassLevelArmName;
use App\Concerns\ResolvesTermFilter;
use App\Enums\TeacherAssignmentRoleEnum;
use App\Http\Resources\TeacherResource;
use App\Models\BehavioralAssessment;
use App\Models\ClassLevelArmTeacher;
use App\Models\Curriculum;
use App\Models\StudentCurriculum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class OutstandingCommentController extends Controller
{
    use FormatsClassLevelArmName, ResolvesTermFilter;

    public function index(Request $request)
    {
        $term = $this->resolveTermFilter($request);

        if (!$term) {
            return Response::success([
                'form_teachers' => [],
                'boarding_parents' => [],
                'head_of_schools' => [],
                'term' => null,
            ]);
        }

        $curricula = Curriculum::where('term_id', $term->id)
            ->get(['id', 'class_level_arm_id']);

        $armIds = $curricula->pluck('class_level_arm_id')->unique();
        $curriculumIds = $curricula->pluck('id')->toArray();

        $assignments = ClassLevelArmTeacher::with([
            'teacher',
            'classLevelArm.classLevel',
            'classLevelArm.arm',
            'classLevelArm.stream',
        ])->whereIn('class_level_arm_id', $armIds)->get();

        $studentCurricula = StudentCurriculum::query()
            ->whereIn('status', $this->enrollmentStatusesFor($term))
            ->whereIn('curriculum_id', $curriculumIds)
            ->with(['student:id,gender', 'curriculum:id,class_level_arm_id'])
            ->get();

        $studentsByArm = $studentCurricula->groupBy(fn($sc) => $sc->curriculum->class_level_arm_id);

        $assessedIds = BehavioralAssessment::where('assessment_term_id', $term->id)
            ->whereIn('student_curriculum_id', $studentCurricula->pluck('id'))
            ->pluck('student_curriculum_id')
            ->flip();

        $formTeachers = [];
        $boardingParents = [];
        $headOfSchools = [];

        foreach ($assignments as $assignment) {
            $classLevelArm = $assignment->classLevelArm;
            $teacher = $assignment->teacher;

            if (!$teacher || !$classLevelArm) {
                continue;
            }

            $students = $studentsByArm->get($classLevelArm->id, collect());
            $className = $this->classLevelArmName($classLevelArm);

            if ($assignment->role === TeacherAssignmentRoleEnum::FORM_TEACHER) {
                $total = $students->count();
                $completed = $students->filter(fn($sc) => $sc->form_teacher_comment !== null)->count();

                $formTeachers[] = [
                    'teacher' => new TeacherResource($teacher),
                    'class_name' => $className,
                    'total' => $total,
                    'completed' => $completed,
                    'outstanding' => $total - $completed,
                ];
            }

            if ($assignment->role === TeacherAssignmentRoleEnum::BOARDING_PARENT) {
                $genderStudents = $assignment->gender
                    ? $students->filter(fn($sc) => $sc->student && $sc->student->gender === $assignment->gender->value)
                    : $students;

                $total = $genderStudents->count();
                $completed = $genderStudents->filter(fn($sc) => $assessedIds->has($sc->id))->count();

                $boardingParents[] = [
                    'teacher' => new TeacherResource($teacher),
                    'class_name' => $className,
                    'gender' => $assignment->gender?->value,
                    'total' => $total,
                    'completed' => $completed,
                    'outstanding' => $total - $completed,
                ];
            }

            if ($assignment->role === TeacherAssignmentRoleEnum::HEAD_OF_SCHOOL) {
                $total = $students->count();
                $completed = $students->filter(fn($sc) => $sc->head_of_school_comment !== null)->count();

                $headOfSchools[] = [
                    'teacher' => new TeacherResource($teacher),
                    'class_name' => $className,
                    'total' => $total,
                    'completed' => $completed,
                    'outstanding' => $total - $completed,
                ];
            }
        }

        return Response::success([
            'form_teachers' => $formTeachers,
            'boarding_parents' => $boardingParents,
            'head_of_schools' => $headOfSchools,
            'term' => $term->name,
        ]);
    }
}
