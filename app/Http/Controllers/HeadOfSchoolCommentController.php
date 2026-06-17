<?php

namespace App\Http\Controllers;

use App\Concerns\FormatsClassLevelArmName;
use App\Enums\StudentStatusEnum;
use App\Enums\TeacherAssignmentRoleEnum;
use App\Enums\TermStatusEnum;
use App\Http\Resources\GradeBoundaryResource;
use App\Http\Resources\StudentCurriculumResource;
use App\Http\Resources\StudentResource;
use App\Models\ClassLevelArmTeacher;
use App\Models\GradeBoundary;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;

class HeadOfSchoolCommentController extends Controller
{
    use FormatsClassLevelArmName;

    public function index()
    {
        abort_unless(auth()->user()->can('manage_head_of_school_comments'), 403);

        $classLevelArmIds = $this->headOfSchoolClassLevelArmIds();

        if ($classLevelArmIds->isEmpty()) {
            return Response::success([]);
        }

        $currentTerm = Term::where('status', TermStatusEnum::ACTIVE->value)->first();

        if (!$currentTerm) {
            return Response::success([]);
        }

        $studentCurricula = StudentCurriculum::query()
            ->where('status', StudentStatusEnum::ACTIVE->value)
            ->where('head_of_school_comment', null)
            ->whereHas('curriculum', fn($query) => $query
                ->where('term_id', $currentTerm->id)
                ->whereIn('class_level_arm_id', $classLevelArmIds))
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
                'comment' => $studentCurriculum->head_of_school_comment,
            ];
        });

        return Response::success($rows->values());
    }

    public function show(StudentCurriculum $studentCurriculum)
    {
        abort_unless(auth()->user()->can('manage_head_of_school_comments'), 403);

        $classLevelArmIds = $this->headOfSchoolClassLevelArmIds();
        $classLevelArmId = $studentCurriculum->curriculum ? $studentCurriculum->curriculum->class_level_arm_id : null;
        abort_unless($classLevelArmId && $classLevelArmIds->contains($classLevelArmId), 403);

        $studentCurriculum->load([
            'student',
            'curriculum.examType.gradeBoundaries',
            'curriculum.term',
            'studentSubjects' => fn($q) => $q->where('status', 'active'),
            'studentSubjects.curriculumSubject.studentResults.student',
            'studentSubjects.curriculumSubject.resultStatus',
            'studentSubjects.curriculumSubject.subject',
        ]);

        $defaultBoundaries = GradeBoundary::whereNull('exam_type_id')->get();

        return Response::json([
            'studentCurriculum' => new StudentCurriculumResource($studentCurriculum),
            'defaultBoundaries' => GradeBoundaryResource::collection($defaultBoundaries),
        ]);
    }

    public function update(Request $request, StudentCurriculum $studentCurriculum)
    {
        abort_unless(auth()->user()->can('manage_head_of_school_comments'), 403);

        $data = $request->validate([
            'comment' => ['nullable', 'string'],
        ]);

        $classLevelArmIds = $this->headOfSchoolClassLevelArmIds();
        $classLevelArmId = $studentCurriculum->curriculum ? $studentCurriculum->curriculum->class_level_arm_id : null;

        abort_unless($classLevelArmId && $classLevelArmIds->contains($classLevelArmId), 403);

        $studentCurriculum->update(['head_of_school_comment' => $data['comment'] ?? null]);

        return Response::success(['comment' => $studentCurriculum->head_of_school_comment]);
    }

    private function headOfSchoolClassLevelArmIds(): Collection
    {
        $teacher = Teacher::where('user_id', auth()->id())->first();

        if (!$teacher) {
            return new Collection();
        }

        return ClassLevelArmTeacher::where('teacher_id', $teacher->id)
            ->where('role', TeacherAssignmentRoleEnum::HEAD_OF_SCHOOL->value)
            ->pluck('class_level_arm_id');
    }
}
