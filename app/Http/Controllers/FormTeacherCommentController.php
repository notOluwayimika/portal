<?php

namespace App\Http\Controllers;

use App\Concerns\FormatsClassLevelArmName;
use App\Concerns\ResolvesTermFilter;
use App\Enums\TeacherAssignmentRoleEnum;
use App\Http\Resources\StudentResource;
use App\Models\ClassLevelArmTeacher;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class FormTeacherCommentController extends Controller
{
    use FormatsClassLevelArmName, ResolvesTermFilter;

    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('manage_form_teacher_comments'), 403);

        $assignment = $this->formTeacherAssignment();

        if (!$assignment) {
            return Response::success([]);
        }

        $term = $this->resolveTermFilter($request);

        if (!$term) {
            return Response::success([]);
        }

        $studentCurricula = StudentCurriculum::query()
            ->whereIn('status', $this->enrollmentStatusesFor($term))
            ->whereHas('curriculum', fn($query) => $query
                ->where('term_id', $term->id)
                ->where('class_level_arm_id', $assignment->class_level_arm_id))
            ->with([
                'student',
                'curriculum.classLevelArm.classLevel',
                'curriculum.classLevelArm.arm',
                'curriculum.classLevelArm.stream',
            ])
            ->get();

        $rows = $studentCurricula->map(function (StudentCurriculum $studentCurriculum) {
            $classLevelArm = $studentCurriculum->curriculum ? $studentCurriculum->curriculum->classLevelArm : null;

            return [
                'student_curriculum_id' => $studentCurriculum->uuid,
                'student' => new StudentResource($studentCurriculum->student),
                'class_name' => $classLevelArm ? $this->classLevelArmName($classLevelArm) : null,
                'comment' => $studentCurriculum->form_teacher_comment,
            ];
        });

        return Response::success($rows->values());
    }

    public function update(Request $request, StudentCurriculum $studentCurriculum)
    {
        abort_unless(auth()->user()->can('manage_form_teacher_comments'), 403);

        $data = $request->validate([
            'comment' => ['nullable', 'string'],
        ]);

        $assignment = $this->formTeacherAssignment();
        $classLevelArmId = $studentCurriculum->curriculum ? $studentCurriculum->curriculum->class_level_arm_id : null;

        abort_unless($assignment && $classLevelArmId === $assignment->class_level_arm_id, 403);

        $studentCurriculum->update(['form_teacher_comment' => $data['comment'] ?? null]);

        return Response::success(['comment' => $studentCurriculum->form_teacher_comment]);
    }

    private function formTeacherAssignment(): ?ClassLevelArmTeacher
    {
        $teacher = Teacher::where('user_id', auth()->id())->first();

        if (!$teacher) {
            return null;
        }

        return ClassLevelArmTeacher::where('teacher_id', $teacher->id)
            ->where('role', TeacherAssignmentRoleEnum::FORM_TEACHER->value)
            ->first();
    }
}
