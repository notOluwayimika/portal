<?php

namespace App\Http\Controllers;

use App\Http\Requests\RejectSubjectResultRequest;
use App\Http\Requests\UpsertScoreRequest;
use App\Http\Resources\CurriculumSubjectResource;
use App\Http\Resources\MarkingComponentResource;
use App\Http\Resources\SubjectResultStatusResource;
use App\Http\Resources\TeacherCurriculumSubjectResource;
use App\Models\CurriculumSubject;
use App\Models\GradeBoundary;
use App\Models\GradingSchemeItem;
use App\Models\Score;
use App\Models\Student;
use App\Models\StudentResult;
use App\Models\StudentSubject;
use App\Models\SubjectResultStatus;
use App\Models\Teacher;
use App\Models\TeacherCurriculumSubject;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CurriculumSubjectController extends Controller
{
    protected function handleStudentResults(CurriculumSubject $curriculumSubject, string $status = 'approved')
    {
        $curriculumSubject->load([
            'studentResults',
            'scores.markingComponent',
            'studentAssignments.studentCurriculum.student',
            'curriculum.examType',
        ]);

        $gradeBoundaries = GradeBoundary::where(
            'exam_type_id',
            $curriculumSubject->curriculum->exam_type_id
        )->get();

        if ($gradeBoundaries->count() < 1) {
            $gradeBoundaries = GradeBoundary::whereNull('exam_type_id')->get();
        }

        $studentAssignments = $curriculumSubject->studentAssignments()
            ->where('status', 'active')
            ->get();

        // Get active student IDs
        $activeStudentIds = $studentAssignments
            ->pluck('studentCurriculum.student.id')
            ->filter()
            ->unique()
            ->values();

        // Delete results for students no longer assigned
        StudentResult::where('curriculum_subject_id', $curriculumSubject->id)
            ->whereNotIn('student_id', $activeStudentIds)
            ->delete();

        if ($curriculumSubject->curriculum->usesCategoricalGrading()) {
            foreach ($studentAssignments as $assignment) {
                $studentId = $assignment->studentCurriculum->student->id;
                StudentResult::where('student_id', $studentId)
                    ->where('curriculum_subject_id', $curriculumSubject->id)
                    ->whereNotNull('grading_scheme_item_id')
                    ->update([
                        'status' => $status,
                        'approved_by' => Auth::id(),
                        'approved_at' => now(),
                    ]);
            }

            return;
        }
        $markingComponents = $curriculumSubject->effectiveMarkingComponents();
        foreach ($studentAssignments as $assignment) {
            $studentId = $assignment->studentCurriculum->student->id;

            $scores = $curriculumSubject->scores()
                ->where('student_id', $studentId)
                ->get();

            $total = $scores->sum('score');

            $grade = $gradeBoundaries
                ->where('min_score', '<=', floor($total))
                ->where('max_score', '>=', floor($total))
                ->first();
            if (count($scores) === count($markingComponents)) {
                StudentResult::updateOrCreate(
                    [
                        'student_id' => $studentId,
                        'curriculum_subject_id' => $curriculumSubject->id,
                    ],
                    [
                        'status' => $status,
                        'total_score' => $total,
                        'grade' => $grade ? $grade->grade : null,
                        'approved_by' => Auth::id(),
                        'approved_at' => now(),
                    ]
                );
            } else {
                // Delete the student result for that component if it exists
                try {
                    StudentResult::where('student_id', $studentId)
                        ->where('curriculum_subject_id', $curriculumSubject->id)
                        ->delete();
                } catch (\Throwable $th) {
                    // throw $th;
                }

            }
        }
    }

    protected function isTeacher($user): bool
    {
        return $user && $user->hasRole('teacher');
    }

    protected function isReviewer($user): bool
    {
        return $user && ($user->hasRole('admin') || $user->hasRole('head_of_school'));
    }

    protected function present(SubjectResultStatus $s): array
    {
        return [
            'status' => $s->status,
            'rejection_reason' => $s->rejection_reason,
            'updated_at' => optional($s->updated_at)->toIso8601String(),
            'updated_by' => $s->updatedBy
                ? [
                    'id' => $s->updatedBy->id,
                    'name' => trim(($s->updatedBy->name ?? '')) ?: $s->updatedBy->email,
                    'role' => $s->updatedBy->role ?? null,
                ]
                : null,
        ];
    }

    public function update(Request $request, CurriculumSubject $curriculumSubject)
    {
        try {
            $request->validate([
                'is_compulsory' => 'sometimes|boolean',
                'display_order' => 'sometimes|numeric|max:255',
            ]);

            $curriculumSubject->update($request->all());
            if ($request->is_compulsory) {
                $students = $curriculumSubject->curriculum->studentCurricula;
                foreach ($students as $student) {
                    StudentSubject::updateOrCreate([
                        'student_curriculum_id' => $student->id,
                        'curriculum_subject_id' => $curriculumSubject->id,
                    ], [
                        'status' => 'active',
                    ]);
                }
            }

            return response()->json($curriculumSubject, 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to update curriculum subject'], 500);
        }

    }

    public function assignMarkingComponent(Request $request, CurriculumSubject $curriculumSubject)
    {
        if ($curriculumSubject->curriculum()->whereNotNull('marking_scheme_id')->exists()) {
            return response()->json([
                'error' => 'This curriculum uses a shared marking scheme. Update the school marking setup instead.',
            ], 409);
        }

        try {
            $request->validate([
                'name' => 'required|string',
                'weight' => 'required|numeric|min:0',
            ]);
            $markingComponent = $curriculumSubject->markingComponents()->create($request->all());

            return response()->json(['message' => 'Marking component created successfully', 'data' => new MarkingComponentResource($markingComponent)], 200);

        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to create marking component'], 500);

        }
    }

    public function assignTeacher(Request $request, CurriculumSubject $curriculumSubject)
    {
        try {
            $request->validate([
                'teacher_id' => 'required|exists:teachers,uuid',
            ]);
            $teacher = Teacher::where('uuid', $request->teacher_id)->first();

            $curriculumSubject->teacherAssignments()->create(['teacher_id' => $teacher->id]);

            return response()->json(['message' => 'Teacher assigned successfully'], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to assign teacher'], 500);
        }
    }

    public function getTeachers(CurriculumSubject $curriculumSubject)
    {
        $curriculumSubject->load('teacherAssignments.teacher');

        return response()->json(TeacherCurriculumSubjectResource::collection($curriculumSubject->teacherAssignments));
    }

    public function unassignTeacher(CurriculumSubject $curriculumSubject, Teacher $teacher)
    {
        try {
            $curriculumSubject->teacherAssignments()->where('teacher_id', $teacher->id)->delete();

            return response()->json(['message' => 'Teacher unassigned successfully'], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to unassign teacher'], 500);
        }
    }

    public function destroy(CurriculumSubject $curriculumSubject)
    {
        try {
            $curriculumSubject->delete();

            return response()->json(['message' => 'Curriculum subject deleted successfully'], 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to delete curriculum subject'], 500);
        }
    }

    public function assignScore(UpsertScoreRequest $upsertScoreRequest)
    {
        try {
            $data = $upsertScoreRequest->validated();

            // Authorize: the TCS must belong to the authenticated teacher, AND
            // the marking_component must belong to the same curriculum_subject.
            $cs = CurriculumSubject::with(['markingComponents', 'curriculum.markingScheme.components', 'resultStatus'])
                ->where('uuid', $data['curriculum_subject_id'])
                ->first();

            if ($cs->resultStatus) {
                if ($cs->resultStatus->status === 'submitted' || $cs->resultStatus->status === 'approved') {
                    return response()->json(['error' => 'Scores submitted, contact administrator'], 422);
                }
            }

            $curriculumSubjectId = $cs->id;

            // abort_unless(
            //     $cs->markingComponents
            //         ->contains(fn($mc) => $mc->uuid === $data['marking_component_id']),
            //     422,
            //     'Marking component does not belong to this subject.'
            // );

            // Ensure the student is actually enrolled in this curriculum subject.
            $isEnrolled = Student::where('uuid', $data['student_id'])
                ->whereHas('studentCurricula.studentSubjects', function ($q) use ($curriculumSubjectId) {
                    $q->where('curriculum_subject_id', $curriculumSubjectId);
                })
                ->exists();

            if (! $isEnrolled) {
                return response()->json(['error' => 'Student is not enrolled in this subject.'], 422);
            }

            $student = Student::where('uuid', $data['student_id'])->first();
            $markingComponent = $cs->effectiveMarkingComponents()
                ->first(fn ($mc) => $mc->uuid === $data['marking_component_id']);

            if (! $markingComponent) {
                return response()->json(['error' => 'Marking component does not belong to this curriculum subject.'], 422);
            }

            $score = Score::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'curriculum_subject_id' => $curriculumSubjectId,
                    'marking_component_id' => $markingComponent->id,
                ],
                [
                    'curriculum_subject_id' => $curriculumSubjectId,
                    'score' => $data['score'],
                    'created_by' => Auth::id(),
                ]
            );
            if ($score->score == 0) {
                $score->delete();
            } elseif (abs($score->score) < 0.5) {
                $score->update(['score' => 0]);
            }

            return response()->json([
                'id' => $score->id,
                'score' => (float) $score->score,
            ]);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to assign score'], 500);
        }

    }

    public function assignCategoricalResult(Request $request, CurriculumSubject $curriculumSubject, Student $student)
    {
        $data = $request->validate([
            'grading_scheme_item_id' => ['required', 'uuid', 'exists:grading_scheme_items,uuid'],
        ]);

        $curriculumSubject->loadMissing('curriculum.gradingScheme.items', 'resultStatus');
        if (! $curriculumSubject->curriculum->usesCategoricalGrading()) {
            return response()->json(['error' => 'This curriculum uses numerical grading.'], 422);
        }
        if (in_array($curriculumSubject->resultStatus?->status, ['submitted', 'approved'], true)) {
            return response()->json(['error' => 'Results are locked. Contact an administrator.'], 422);
        }

        $isEnrolled = $curriculumSubject->studentAssignments()
            ->where('status', 'active')
            ->whereHas('studentCurriculum', fn ($query) => $query->where('student_id', $student->id))
            ->exists();
        if (! $isEnrolled) {
            return response()->json(['error' => 'Student is not enrolled in this subject.'], 422);
        }

        $item = GradingSchemeItem::where('uuid', $data['grading_scheme_item_id'])
            ->where('grading_scheme_id', $curriculumSubject->curriculum->grading_scheme_id)
            ->first();
        if (! $item) {
            return response()->json(['error' => 'Rating does not belong to this grading scheme.'], 422);
        }

        $result = StudentResult::updateOrCreate([
            'student_id' => $student->id,
            'curriculum_subject_id' => $curriculumSubject->id,
        ], [
            'grading_scheme_item_id' => $item->id,
            'total_score' => null,
            'grade' => $item->code,
            'status' => 'draft',
            'computed_at' => now(),
        ]);

        return response()->json([
            'id' => $result->uuid,
            'grading_item' => ['id' => $item->uuid, 'code' => $item->code, 'label' => $item->label],
        ]);
    }

    public function submit(Request $request, CurriculumSubject $curriculumSubject): JsonResponse
    {
        $user = $request->user();
        // abort_unless($this->isTeacher($user), 403);

        // Ensure the teacher actually owns this curriculum_subject via teacher_curriculum_subjects.
        // abort_unless(
        //     TeacherCurriculumSubject::where('teacher_id', optional($user->teacher)->id)
        //         ->where('curriculum_subject_id', $curriculumSubject->id)
        //         ->exists(),
        //     403,
        // );

        $status = DB::transaction(function () use ($curriculumSubject, $user) {
            $this->handleStudentResults($curriculumSubject, 'submitted');

            return SubjectResultStatus::updateOrCreate(
                ['curriculum_subject_id' => $curriculumSubject->id],
                [
                    'status' => 'submitted',
                    'rejection_reason' => null,
                    'updated_by' => $user->id,
                ],
            )->fresh(['updatedBy']);
        });

        return response()->json(['status' => $this->present($status)]);
    }

    public function approve(Request $request, CurriculumSubject $curriculumSubject): JsonResponse
    {
        $user = $request->user();
        // abort_unless($this->isReviewer($user), 403);

        $status = DB::transaction(function () use ($curriculumSubject, $user) {
            $this->handleStudentResults($curriculumSubject, 'approved');

            return SubjectResultStatus::updateOrCreate(
                ['curriculum_subject_id' => $curriculumSubject->id],
                [
                    'status' => 'approved',
                    'rejection_reason' => null,
                    'updated_by' => $user->id,
                ],
            )->fresh(['updatedBy']);
        });

        return response()->json(['status' => $this->present($status)]);
    }

    public function reject(
        RejectSubjectResultRequest $request,
        CurriculumSubject $curriculumSubject
    ): JsonResponse {
        $user = $request->user();
        // abort_unless($this->isReviewer($user), 403);

        $status = DB::transaction(function () use ($curriculumSubject, $user, $request) {
            $this->handleStudentResults($curriculumSubject, 'rejected');

            return SubjectResultStatus::updateOrCreate(
                ['curriculum_subject_id' => $curriculumSubject->id],
                [
                    'status' => 'rejected',
                    'rejection_reason' => $request->validated('rejection_reason'),
                    'updated_by' => $user->id,
                ],
            )->fresh(['updatedBy']);
        });

        return response()->json(['status' => $this->present($status)]);
    }

    public function getResultStatus(CurriculumSubject $curriculumSubject)
    {
        $status = $curriculumSubject->resultStatus;

        return response()->json(new SubjectResultStatusResource($status), 200);
    }

    /**
     * PATCH /api/curriculum-subjects/{curriculumSubject}/archive
     * Soft-archive a curriculum subject so it cannot be added to new enrollments.
     * Existing student_subjects rows are unaffected.
     */
    public function archive(Request $request, CurriculumSubject $curriculumSubject): JsonResponse
    {
        // abort_unless($request->user()->can('curriculum_subject.archive'), 403);
        abort_if($curriculumSubject->isArchived(), 409, 'This subject is already archived.');

        $curriculumSubject->update([
            'active' => false,
            'archived_at' => now(),
            'archived_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Subject archived successfully.']);
    }

    /**
     * PATCH /api/curriculum-subjects/{curriculumSubject}/unarchive
     * Restore an archived curriculum subject so it can be added to enrollments again.
     */
    public function unarchive(Request $request, CurriculumSubject $curriculumSubject): JsonResponse
    {
        // abort_unless($request->user()->can('curriculum_subject.restore'), 403);
        // abort_unless($curriculumSubject->isArchived(), 409, 'This subject is not archived.');

        $curriculumSubject->update([
            'active' => true,
            'archived_at' => null,
            'archived_by_user_id' => null,
        ]);

        return response()->json(['message' => 'Subject restored successfully.']);
    }

    public function show(CurriculumSubject $curriculumSubject)
    {
        $curriculumSubject->load('markingComponents', 'curriculum.markingScheme.components');

        return response()->json(new CurriculumSubjectResource($curriculumSubject));
    }

    public function index(Request $request)
    {
        $schoolId = ActiveSchool::id();
        $classLevelArms = $request->class_level_arms;
        $curriculumSubjects = CurriculumSubject::with(['teacherAssignments.teacher', 'subject', 'resultStatus', 'markingComponents', 'curriculum.markingScheme.components', 'curriculum.classLevelArm'])->whereHas('curriculum', function ($query) use ($schoolId) {
            $query->where('school_id', $schoolId);
        })->when(! empty($classLevelArms), function ($query) use ($classLevelArms) {
            $query->whereHas('curriculum.classLevelArm', function ($q) use ($classLevelArms) {
                $q->whereIn('uuid', $classLevelArms);
            });
        })->get();

        return response()->json(CurriculumSubjectResource::collection($curriculumSubjects));
    }

    public function getYearAverage(CurriculumSubject $curriculumSubject)
    {
        $curriculum = $curriculumSubject->curriculum;
        $classLevelId = $curriculum->classLevelArm->class_level_id;

        $curriculumSubjects = CurriculumSubject::where('subject_id', $curriculumSubject->subject_id)
            ->whereHas('curriculum', function ($query) use ($curriculum, $classLevelId) {
                $query->where('term_id', $curriculum->term_id)
                    ->where('exam_type_id', $curriculum->exam_type_id)
                    ->where('is_ccm', $curriculum->is_ccm)
                    ->whereHas('classLevelArm', function ($q) use ($classLevelId) {
                        $q->where('class_level_id', $classLevelId);
                    });
            })
            ->with('studentResults')
            ->get();
        $classAverages = $curriculumSubjects
            ->map(fn ($cs) => $cs->studentResults->avg('total_score'))
            ->filter(fn ($avg) => $avg !== null);

        // to 1 dp
        $yearAverage = $classAverages->avg();
        $yearAverage = $yearAverage !== null ? number_format($yearAverage, 1) : null;

        return response()->json(['year_average' => $yearAverage]);
    }
}
