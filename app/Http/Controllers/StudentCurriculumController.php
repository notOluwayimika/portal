<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatusEnum;
use App\Exceptions\BusinessRuleException;
use App\Http\Requests\PromoteStudentRequest;
use App\Http\Requests\RegisterStudentCurriculumRequest;
use App\Http\Requests\StudentSubject\UnenrollStudentRequest;
use App\Http\Requests\UpdateStudentCurriculumStatusRequest;
use App\Http\Resources\CurriculumSubjectResource;
use App\Http\Resources\ScoreResource;
use App\Http\Resources\StudentCurriculumResource;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Score;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Services\CurriculumEnrollmentService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StudentCurriculumController extends Controller
{
    public function __construct(private CurriculumEnrollmentService $enrollmentService)
    {
    }

    public function getTeacherDetails(StudentCurriculum $studentCurriculum)
    {
        $formTeacher = $studentCurriculum->formTeacher();
        $boardingParent = $studentCurriculum->boardingParent();
        $headOfSchool = $studentCurriculum->headOfSchool();
        $behavioralAssessments = $studentCurriculum->behavioralAssessments;
        $psychomotorSkills = $studentCurriculum->psychomotorSkills()
            ->where('assessment_term_id', $studentCurriculum->curriculum?->term_id)
            ->get();
        return response()->json([
            "studentCurriculum" => new StudentCurriculumResource($studentCurriculum),
            "formTeacher" => $formTeacher,
            "boardingParent" => $boardingParent,
            "headOfSchool" => $headOfSchool,
            "behavioralAssessments" => $behavioralAssessments,
            "psychomotorSkills" => $psychomotorSkills,
        ]);
    }

    /**
     * PATCH /api/students/{student}/enrollments/{studentCurriculum}/end
     * End (unenroll) a student from a curriculum. Does NOT delete student_subjects.
     */
    public function unenroll(
        UnenrollStudentRequest $request,
        Student $student,
        StudentCurriculum $studentCurriculum
    ): JsonResponse {
        // abort_unless($studentCurriculum->student_id === $student->id, 404);
        // abort_unless($student->school_id === $request->user()->school_id, 403);

        try {
            $enrollment = $this->enrollmentService->unenroll(
                $studentCurriculum,
                $request->user(),
                $request->validated('reason')
            );

            $enrollment->load(['curriculum.examType', 'curriculum.classLevelArm.classLevel', 'curriculum.academicSession']);

            return response()->json([
                'message' => 'Enrollment ended successfully.',
                'data' => new StudentCurriculumResource($enrollment),
            ]);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Update the student_curricula.status field (active|promoted|repeated|withdrawn).
     */
    public function updateStatus(
        UpdateStudentCurriculumStatusRequest $request,
        StudentCurriculum $studentCurriculum,
    ): JsonResponse {
        $this->authorizeReviewer($request);


        $studentCurriculum->status = $request->validated('status');

        // If admin manually moves a row off "promoted", clear the link.
        if ($studentCurriculum->status !== StudentStatusEnum::PROMOTED) {
            $studentCurriculum->promoted_to_id = null;
        }
        if ($studentCurriculum->status === StudentStatusEnum::ACTIVE) {
            abort_if(StudentCurriculum::where('student_id', $studentCurriculum->student->id)->where('status', 'active')->exists(), 422, 'Student is already enrolled in a curriculum.');
        }


        $studentCurriculum->save();
        if ($request->validated('status') === 'withdrawn') {
            $sc = new StudentCurriculumResource($studentCurriculum);
            $studentCurriculum->delete();
            return response()->json($sc);
        }
        $studentCurriculum->load(['curriculum.examType', 'curriculum.classLevelArm.classLevel', 'curriculum.academicSession', 'promotedTo']);
        return response()->json(new StudentCurriculumResource($studentCurriculum));
    }

    /**
     * Promote a student from one student_curricula row into a new curriculum.
     *
     * - Creates a new student_curricula row (status = 'active') for the target curriculum.
     * - Marks the source row as status = 'promoted' and sets promoted_to_id to the new row.
     */
    public function promote(
        PromoteStudentRequest $request,
        Student $student,
    ): JsonResponse {
        $this->authorizeReviewer($request);

        $data = $request->validated();

        $from = StudentCurriculum::where('uuid', $data['from_student_curriculum_id'])
            ->where('student_id', $student->id)
            ->firstOrFail();

        abort_if(
            $from->status != StudentStatusEnum::ACTIVE,
            422,
            'Only active enrollments can be promoted.',
        );

        $target = Curriculum::where('uuid', $data['to_curriculum_id'])->firstOrFail();
        abort_if(
            $target->school_id !== $student->school_id,
            422,
            'Target curriculum belongs to a different school.',
        );

        abort_if(
            StudentCurriculum::where('student_id', $student->id)
                ->where('curriculum_id', $target->id)
                ->exists(),
            422,
            'Student is already enrolled in the target curriculum.',
        );

        [$from, $new] = DB::transaction(function () use ($student, $from, $target) {

            $new = StudentCurriculum::create([
                'student_id' => $student->id,
                'curriculum_id' => $target->id,
                'status' => 'active',
            ]);

            $from->update([
                'status' => StudentStatusEnum::PROMOTED,
                'promoted_to_id' => $new->id,
            ]);

            return [$from, $new];
        });
        $from->load(['curriculum.examType', 'curriculum.classLevelArm.classLevel', 'curriculum.academicSession', 'promotedTo']);
        $new->load(['curriculum.examType', 'curriculum.classLevelArm.classLevel', 'curriculum.academicSession', 'promotedTo']);


        return response()->json([
            'from' => new StudentCurriculumResource($from),
            'new' => new StudentCurriculumResource($new),
        ]);
    }

    /**
     * Register the student in a brand-new curriculum (no source row, no link).
     * Creates a student_curricula record with status = 'active'.
     */
    public function register(
        RegisterStudentCurriculumRequest $request,
        Student $student,
    ): JsonResponse {
        $this->authorizeReviewer($request);

        $data = $request->validated();

        $target = Curriculum::where('uuid', $data['curriculum_id'])->firstOrFail();

        abort_if(
            $target->school_id !== $student->school_id,
            422,
            'Target curriculum belongs to a different school.',
        );

        abort_if(
            StudentCurriculum::where('student_id', $student->id)
                ->where('curriculum_id', $target->id)
                ->exists(),
            422,
            'Student is already enrolled in this curriculum.',
        );

        abort_if(StudentCurriculum::where('student_id', $student->id)->where('status', 'active')->exists(), 422, 'Student is already enrolled in a curriculum.');
        return DB::transaction(function () use ($student, $target) {
            $sc = StudentCurriculum::create([
                'student_id' => $student->id,
                'curriculum_id' => $target->id,
                'status' => 'active',
            ]);
            $sc->load(['curriculum.examType', 'curriculum.classLevelArm.classLevel', 'curriculum.academicSession', 'promotedTo']);

            return response()->json([
                'student_curriculum' => new StudentCurriculumResource($sc),
            ]);
        });
    }

    public function getScoresWithMarkingComponents(StudentCurriculum $studentCurriculum, CurriculumSubject $curriculumSubject)
    {
        return ScoreResource::collection(Score::with('markingComponent')->where('student_id', $studentCurriculum->student_id)->where('curriculum_subject_id', $curriculumSubject->id)->get());
    }

    // ---------- Helpers ----------

    protected function authorizeReviewer(Request $request): void
    {
        $user = $request->user();
        // abort_unless($user && ($user->hasRole('admin') || $user->hasRole('head_of_school')), 403);
    }

    protected function presentStudentCurriculum(StudentCurriculum $sc): array
    {
        return [
            'id' => $sc->uuid,
            'status' => $sc->status,
            'created_at' => optional($sc->created_at)->toIso8601String(),
            'curriculum' => $sc->curriculum
                ? $this->presentCurriculum($sc->curriculum)
                : null,
            'promoted_to' => $sc->promotedTo
                ? [
                    'id' => $sc->promotedTo->uuid,
                    'curriculum' => $sc->promotedTo->curriculum
                        ? $this->presentCurriculum($sc->promotedTo->curriculum)
                        : null,
                ]
                : null,
        ];
    }

    protected function presentCurriculum(Curriculum $c): array
    {
        return [
            'id' => $c->id,
            'term' => $c->term,
            'status' => $c->status,
            'class_label' => optional($c->classLevelArm)->name,
            'exam_type' => optional($c->examType)->name,
            'session' => optional($c->academicSession)->name,
        ];
    }
}
