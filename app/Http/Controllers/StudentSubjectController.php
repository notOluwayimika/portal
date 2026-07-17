<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Requests\StudentSubject\DropSubjectRequest;
use App\Http\Requests\StudentSubject\RestoreSubjectRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\StudentSubjectResource;
use App\Models\CurriculumSubject;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentSubject;
use App\Services\StudentSubjectService;
use App\Support\Authz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class StudentSubjectController extends Controller
{
    public function __construct(private StudentSubjectService $service) {}

    /**
     * GET /api/students/{student}/enrollments/{enrollment}/subjects
     * Returns subjects grouped: compulsory_active, optional_active, optional_dropped, optional_available.
     */
    public function index(Request $request, Student $student, StudentCurriculum $studentCurriculum): JsonResponse
    {
        Authz::abilityCheck(request()->user(), 'student_subject.view', 'StudentSubjectController@index');
        $this->authorizeEnrollmentBelongsToStudent($student, $studentCurriculum);

        $studentCurriculum->load([
            'studentSubjects.curriculumSubject.subject',
            'studentSubjects.droppedBy',
            'studentSubjects.restoredBy',
        ]);

        $allSubjects = $studentCurriculum->studentSubjects;

        $compulsoryActive = $allSubjects->filter(fn ($s) => $s->curriculumSubject->is_compulsory && $s->status->value === 'active');
        $optionalActive = $allSubjects->filter(fn ($s) => ! $s->curriculumSubject->is_compulsory && $s->status->value === 'active');
        $optionalDropped = $allSubjects->filter(fn ($s) => ! $s->curriculumSubject->is_compulsory && $s->status->value === 'dropped');

        $available = $studentCurriculum->availableOptionalSubjects();

        return response()->json([
            'data' => [
                'enrollment' => [
                    'id' => $studentCurriculum->uuid,
                    'ended_at' => $studentCurriculum->ended_at?->toIso8601String(),
                    'is_ended' => $studentCurriculum->isEnded(),
                ],
                'compulsory_active' => StudentSubjectResource::collection($compulsoryActive->values()),
                'optional_active' => StudentSubjectResource::collection($optionalActive->values()),
                'optional_dropped' => StudentSubjectResource::collection($optionalDropped->values()),
                'optional_available' => $available->map(fn ($cs) => [
                    'id' => $cs->uuid,
                    'subject_name' => $cs->subject->name,
                    'subject_code' => $cs->subject->code,
                    'is_compulsory' => false,
                    'active' => $cs->active,
                ]),
            ],
        ]);
    }

    /**
     * POST /api/students/{student}/enrollments/{enrollment}/subjects
     * Accepts curriculum_subject_id (single) or curriculum_subject_ids[] (bulk).
     */
    public function store(Request $request, Student $student, StudentCurriculum $studentCurriculum): JsonResponse
    {
        Authz::abilityCheck(request()->user(), 'student_subject.add_optional', 'StudentSubjectController@store');
        $this->authorizeEnrollmentBelongsToStudent($student, $studentCurriculum);

        if ($request->has('curriculum_subject_ids')) {
            $validated = $request->validate([
                'curriculum_subject_ids' => ['required', 'array', 'min:1'],
                'curriculum_subject_ids.*' => ['required', 'string', 'exists:curriculum_subjects,uuid'],
            ]);
            $csids = [];
            foreach ($validated['curriculum_subject_ids'] as $id) {
                $cs = CurriculumSubject::where('uuid', $id)->firstOrFail();
                $csids[] = $cs->id;
            }
            try {
                $results = $this->service->bulkAddOptionalSubjects(
                    $studentCurriculum,
                    $csids,
                    $request->user()
                );

                return response()->json([
                    'message' => 'Subjects added successfully.',
                    'data' => StudentSubjectResource::collection($results),
                ], 201);
            } catch (BusinessRuleException $e) {
                return response()->json(['message' => $e->getMessage()], 409);
            }
        }

        $validated = $request->validate([
            'curriculum_subject_id' => [
                'required',
                'integer',
                'exists:curriculum_subjects,id',
            ],
        ]);

        $cs = CurriculumSubject::findOrFail($validated['curriculum_subject_id']);

        try {
            $subject = $this->service->addOptionalSubject($studentCurriculum, $cs, $request->user());

            return response()->json([
                'message' => 'Subject added successfully.',
                'data' => new StudentSubjectResource($subject),
            ], 201);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * PATCH /api/students/{student}/enrollments/{enrollment}/subjects/{studentSubject}/drop
     */
    public function drop(
        DropSubjectRequest $request,
        Student $student,
        StudentCurriculum $studentCurriculum,
        StudentSubject $studentSubject
    ): JsonResponse {
        $this->authorizeEnrollmentBelongsToStudent($student, $studentCurriculum);
        Authz::ensure((int) $studentSubject->student_curriculum_id === (int) $studentCurriculum->id, 'student_subject.belongs_to_enrollment', 'ownership', 'StudentSubjectController@drop', 404);

        try {
            $updated = $this->service->dropOptionalSubject(
                $studentSubject,
                $request->user(),
                $request->validated('reason')
            );

            return response()->json([
                'message' => 'Subject dropped successfully.',
                'data' => new StudentSubjectResource($updated->load(['curriculumSubject.subject', 'droppedBy'])),
            ]);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * PATCH /api/students/{student}/enrollments/{enrollment}/subjects/{studentSubject}/restore
     */
    public function restore(
        RestoreSubjectRequest $request,
        Student $student,
        StudentCurriculum $studentCurriculum,
        StudentSubject $studentSubject
    ): JsonResponse {
        $this->authorizeEnrollmentBelongsToStudent($student, $studentCurriculum);
        Authz::ensure((int) $studentSubject->student_curriculum_id === (int) $studentCurriculum->id, 'student_subject.belongs_to_enrollment', 'ownership', 'StudentSubjectController@restore', 404);

        try {
            $updated = $this->service->restoreDroppedSubject($studentSubject, $request->user());

            return response()->json([
                'message' => 'Subject restored successfully.',
                'data' => new StudentSubjectResource($updated->load(['curriculumSubject.subject', 'restoredBy'])),
            ]);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * GET /api/students/{student}/enrollments/{enrollment}/subjects/history
     */
    public function history(Request $request, Student $student, StudentCurriculum $studentCurriculum): JsonResponse
    {
        Authz::abilityCheck(request()->user(), 'student_subject.view_history', 'StudentSubjectController@history');
        $this->authorizeEnrollmentBelongsToStudent($student, $studentCurriculum);

        $subjectIds = $studentCurriculum->studentSubjects()->pluck('id');

        $log = Activity::query()
            ->where('subject_type', StudentSubject::class)
            ->whereIn('subject_id', $subjectIds)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => ActivityResource::collection($log),
        ]);
    }

    public function storeComment(Request $request, StudentSubject $studentSubject): JsonResponse
    {
        $studentSubject->loadMissing('curriculumSubject.resultStatus');
        if ($studentSubject->curriculumSubject?->resultStatus?->status === 'approved') {
            return response()->json([
                'message' => 'Comments cannot be changed after the subject result is approved.',
            ], 422);
        }

        $request->validate([
            'comment' => 'required|string|max:50',
        ]);
        Authz::abilityCheck(request()->user(), 'student_subject.view', 'StudentSubjectController@storeComment');
        $this->service->storeComment($studentSubject, $request->user(), $request->comment);

        return response()->json([
            'message' => 'Comment stored successfully.',
        ], 201);
    }

    private function authorizeEnrollmentBelongsToStudent(Student $student, StudentCurriculum $enrollment): void
    {
        // Nested-route integrity (the enrollment must belong to the student in the
        // URL). Object-ownership guard — observed via Authz until enforcement.
        Authz::ensure((int) $enrollment->student_id === (int) $student->id, 'enrollment.belongs_to_student', 'ownership', 'StudentSubjectController@enrollmentGuard', 404);

        // Ownership-by-users.school_id is intentionally NOT restored: it is redundant
        // under SchoolScope (the student/enrollment are already School-scoped) and would
        // reintroduce the users.school_id fallback (ADR 0042 debt). Awaiting §7 decision
        // (see S5 classification report) — recommend deletion, not restoration.
        // abort_unless($student->school_id === auth()->user()->school_id, 403);
    }
}
