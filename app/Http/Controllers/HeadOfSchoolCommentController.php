<?php

namespace App\Http\Controllers;

use App\Concerns\FormatsClassLevelArmName;
use App\Concerns\ResolvesTermFilter;
use App\Enums\TeacherAssignmentRoleEnum;
use App\Http\Resources\GradeBoundaryResource;
use App\Http\Resources\StudentCurriculumResource;
use App\Http\Resources\StudentResource;
use App\Models\ClassLevelArmTeacher;
use App\Models\GradeBoundary;
use App\Models\StudentCurriculum;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;

class HeadOfSchoolCommentController extends Controller
{
    use FormatsClassLevelArmName, ResolvesTermFilter;

    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('manage_head_of_school_comments'), 403);

        $classLevelArmIds = $this->headOfSchoolClassLevelArmIds();

        if ($classLevelArmIds->isEmpty()) {
            return Response::success([]);
        }

        $term = $this->resolveTermFilter($request);

        if (!$term) {
            return Response::success([]);
        }

        $studentCurricula = StudentCurriculum::query()
            ->whereIn('status', $this->enrollmentStatusesFor($term))
            // ->where('head_of_school_comment', null)
            ->whereHas('curriculum', fn($query) => $query
                ->where('term_id', $term->id)
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
            // The boarding parent's comment lives on the enrollment's
            // behavioral assessment for the curriculum's own term.
            'behavioralAssessments' => fn($q) => $q->where('assessment_term_id', $studentCurriculum->curriculum?->term_id),
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
            'comment' => ['sometimes', 'nullable', 'string'],
            'form_teacher_comment' => ['sometimes', 'nullable', 'string'],
            'boarding_parent_comment' => ['sometimes', 'nullable', 'string'],
        ]);

        $classLevelArmIds = $this->headOfSchoolClassLevelArmIds();
        $classLevelArmId = $studentCurriculum->curriculum ? $studentCurriculum->curriculum->class_level_arm_id : null;

        abort_unless($classLevelArmId && $classLevelArmIds->contains($classLevelArmId), 403);

        $updates = [];

        if (array_key_exists('comment', $data)) {
            $updates['head_of_school_comment'] = $data['comment'];
        }

        if (array_key_exists('form_teacher_comment', $data)) {
            $updates['form_teacher_comment'] = $data['form_teacher_comment'];
        }

        if ($updates) {
            $studentCurriculum->update($updates);
        }

        // The boarding parent comment lives on the behavioral assessment for
        // the enrollment's term. The pillar grades are required columns, so
        // the comment can only be edited once the boarding parent has
        // recorded an assessment — there is no row to attach it to before.
        $assessment = $studentCurriculum->behavioralAssessments()
            ->where('assessment_term_id', $studentCurriculum->curriculum?->term_id)
            ->first();

        if (array_key_exists('boarding_parent_comment', $data)) {
            if ($assessment) {
                $assessment->update(['comment' => $data['boarding_parent_comment']]);
            } elseif ($data['boarding_parent_comment'] !== null) {
                return response()->json([
                    'message' => 'No behavioral assessment exists for this student yet — the boarding parent must record one first.',
                ], 422);
            }
        }

        return Response::success([
            'comment' => $studentCurriculum->head_of_school_comment,
            'form_teacher_comment' => $studentCurriculum->form_teacher_comment,
            'boarding_parent_comment' => $assessment?->comment,
        ]);
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
