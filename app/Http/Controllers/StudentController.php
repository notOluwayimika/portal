<?php

namespace App\Http\Controllers;

use App\DTOs\StudentDto;
use App\Enums\CurriculaStatusEnum;
use App\Enums\GenderTypeEnum;
use App\Enums\GuardianRelationshipEnum;
use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Exports\StudentsExport;
use App\Http\Requests\ImportStudentRequest;
use App\Http\Requests\StudentRequest;
use App\Http\Resources\ClassLevelArmOptionsResource;
use App\Http\Resources\CurriculumOptionResource;
use App\Http\Resources\CurriculumResource;
use App\Http\Resources\StudentResource;
use App\Models\Curriculum;
use App\Models\FileUpload;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentResult;
use App\Repositories\ClassLevelArmRepository;
use App\Repositories\CurriculumRepository;
use App\Services\FileUploadService;
use App\Services\GuardianService;
use App\Services\StudentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class StudentController extends Controller
{
    public function __construct(
        protected StudentService $studentService,
        protected ClassLevelArmRepository $classLevelArmRepository,
        protected FileUploadService $fileUploadService,
        protected GuardianService $guardianService,
    ) {
    }

    public function index(Request $request)
    {
        $students = $this->studentService->paginate($request);

        return response()->json([
            'data' => StudentResource::collection($students),
            'pagination' => [
                'total' => $students->total(),
                'per_page' => $students->perPage(),
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'prev_page_url' => $students->previousPageUrl(),
                'next_page_url' => $students->nextPageUrl(),
            ],
        ]);
    }

    public function store(StudentRequest $request)
    {
        $data = $request->validated();
        $data['school_id'] = $request->user()->school_id;
        $data['photo_id'] = $this->uploadPhoto($request);
        unset($data['photo']);

        $guardianEntries = $data['guardians'] ?? [];
        unset($data['guardians']);

        $studentDto = StudentDto::fromArray($data);

        // Atomic: student + all guardians + pivot rows in one transaction.
        // If any guardian processing fails, the student is rolled back too — no orphans.
        $deferredNotifications = [];

        $student = DB::transaction(function () use ($studentDto, $guardianEntries, &$deferredNotifications, $request) {
            $student = $this->studentService->store($studentDto->toArray());
            $schoolId = (int) $request->user()->school_id;

            foreach ($guardianEntries as $entry) {
                $this->processGuardianEntry($student, $entry, $schoolId, $deferredNotifications);
            }

            return $student;
        });

        // Notifications run after the transaction commits so a rollback can't strand emails.
        foreach ($deferredNotifications as $job) {
            $this->guardianService->notifyGuardian(
                user: $job['user'],
                plainPassword: $job['plain_password'],
                studentNames: [$student->full_name],
            );
        }

        if ($request->wantsJson()) {
            return Response::created('Student created successfully.');
        }

        return redirect()->route('students.index');
    }

    public function show(Student $student)
    {
        return Response::json(StudentResource::make($this->studentService->show($student)));
    }

    public function update(StudentRequest $request, Student $student)
    {
        $data = $request->validated();
        $data['school_id'] = $request->user()->school_id;
        $data['photo_id'] = $this->replacePhoto($request, $student->photo_id);
        unset($data['photo'], $data['guardians']);

        $dto = StudentDto::fromArray($data);
        $this->studentService->update($student, $dto->toArray());

        if ($request->wantsJson()) {
            return Response::success('Student updated successfully.');
        }

        return redirect()->route('students.index');
    }

    public function updateStatus(Request $request, Student $student)
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::enum(StudentStatusEnum::class)],
        ]);

        $this->studentService->updateStatus($student, $data['status']);

        if ($request->wantsJson()) {
            return Response::success('Student status updated successfully.');
        }

        return redirect()->route('students.index');
    }

    public function export(Request $request)
    {
        $filename = 'students-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new StudentsExport($request), $filename);
    }

    public function import(ImportStudentRequest $request)
    {
        $data = $request->validated();
        $schoolId = $request->user()->school_id;

        $result = $this->studentService->import(
            $data['students'],
            (int) $data['curriculum_id'],
            $schoolId,
        );

        if (!empty($result['errors'])) {
            return response()->json([
                'message' => "{$result['saved']} student(s) imported. " . count($result['errors']) . " row(s) had errors and were skipped.",
                'saved' => $result['saved'],
                'errors' => $result['errors'],
            ], 422);
        }

        return Response::success("{$result['saved']} student(s) imported successfully.");
    }

    public function destroy(Student $student)
    {
        $this->studentService->delete($student);
        return response()->noContent();
    }

    public function resources()
    {
        $curricula = Curriculum::with([
            'term',
            'classLevelArm.classLevel',
            'classLevelArm.arm',
            'classLevelArm.stream'
        ])
            ->whereHas('term', fn($query) => $query->where('status', TermStatusEnum::ACTIVE))
            ->where('status', CurriculaStatusEnum::ACTIVE->value)->get();

        $genders = GenderTypeEnum::options();

        return Response::success([
            'curricula' => CurriculumOptionResource::collection($curricula),
            'genders' => $genders,
            'guardian_relationships' => GuardianRelationshipEnum::options(),
        ]);
    }

    /**
     * Process a single guardian entry from the student registration form.
     */
    private function processGuardianEntry(Student $student, array $entry, int $schoolId, array &$deferredNotifications): void
    {
        if (($entry['mode'] ?? null) === 'existing') {
            $guardian = $this->guardianService->resolveExistingGuardian($entry, $schoolId);
            $existingPivot = DB::table('guardian_student')
                ->where('guardian_id', $guardian->id)
                ->where('student_id', $student->id)
                ->first();

            $this->guardianService->attachToStudent(
                guardian: $guardian,
                student: $student,
                relationship: $entry['relationship'],
                isPrimary: (bool) $entry['is_primary'],
                canLogin: (bool) $entry['can_login'],
            );

            // If can_login is being raised from false→true and guardian has a real email, queue a re-notify.
            if ($entry['can_login'] && (!$existingPivot || !$existingPivot->can_login)) {
                $user = $guardian->user;
                if ($user && $user->email && !str_ends_with($user->email, '@no-email.local')) {
                    // The service handles credential reissue inside attachToStudent for existing pivots;
                    // for first-time can_login=true on a brand-new link we don't have a fresh password,
                    // so the guardian uses their existing credentials. No-op here.
                }
            }

            return;
        }

        // mode === 'new'
        $result = $this->guardianService->createGuardianWithUser(
            attributes: [
                'first_name' => $entry['first_name'],
                'middle_name' => $entry['middle_name'] ?? null,
                'last_name' => $entry['last_name'],
                'gender' => $entry['gender'] ?? null,
                'phone' => $entry['phone'],
                'whatsapp_number' => $entry['whatsapp_number'] ?? null,
                'city' => $entry['city'] ?? null,
                'state' => $entry['state'] ?? null,
                'country' => $entry['country'] ?? null,
                'postal_code' => $entry['postal_code'] ?? null,
                'occupation' => $entry['occupation'] ?? null,
                'employer_name' => $entry['employer_name'] ?? null,
                'marital_status' => $entry['marital_status'] ?? null,
                'emergency_contact' => $entry['emergency_contact'] ?? null,
                'id_type' => $entry['id_type'] ?? null,
                'id_number' => $entry['id_number'] ?? null,
                'id_expiry_date' => $entry['id_expiry_date'] ?? null,
            ],
            schoolId: $schoolId,
            canLogin: (bool) $entry['can_login'],
            email: $entry['email'] ?? null,
        );

        $this->guardianService->attachToStudent(
            guardian: $result['guardian'],
            student: $student,
            relationship: $entry['relationship'],
            isPrimary: (bool) $entry['is_primary'],
            canLogin: (bool) $entry['can_login'],
        );

        if ($result['plain_password']) {
            $deferredNotifications[] = [
                'user' => $result['user'],
                'plain_password' => $result['plain_password'],
            ];
        }
    }

    /**
     * Upload a new photo and return the file_uploads.id, or null if no file present.
     */
    private function uploadPhoto(Request $request): ?int
    {
        if (!$request->hasFile('photo')) {
            return null;
        }

        return $this->fileUploadService->storeAndUploadFile($request, 'photo', 'students/photos');
    }

    /**
     * Upload a new photo, delete the old one, and return the new file_uploads.id.
     * Returns the existing ID unchanged if no new file is provided.
     */
    private function replacePhoto(Request $request, ?int $existingPhotoId): ?int
    {
        if (!$request->hasFile('photo')) {
            return $existingPhotoId;
        }

        if ($existingPhotoId) {
            $old = FileUpload::find($existingPhotoId);
            if ($old) {
                $this->fileUploadService->unlinkFileUpload($old->folder_path . '/' . $old->name, null);
                $this->fileUploadService->deleteFileUpload($existingPhotoId);
            }
        }

        return $this->fileUploadService->storeAndUploadFile($request, 'photo', 'students/photos');
    }

    public function activeResultStatus(Guardian $guardian, Student $student)
    {
        $activeCurriculum = $student->currentCurriculum;
        $isAvailable = true;
        $subjectsOffered = $activeCurriculum->activeSubjects;
        foreach ($subjectsOffered as $subject) {
            $result = StudentResult::where('student_id', $student->id)->where('curriculum_subject_id', $subject->curriculum_subject_id)->first();
            if (!$result) {
                $isAvailable = false;
                break;
            }
        }
        if ($subjectsOffered->isEmpty()) {
            $isAvailable = false;
        }
        return response()->json(['available' => $isAvailable]);


    }
}
