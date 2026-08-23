<?php

namespace App\Http\Controllers;

use App\DTOs\StudentDto;
use App\Enums\CurriculaStatusEnum;
use App\Enums\GenderTypeEnum;
use App\Enums\GuardianRelationshipEnum;
use App\Enums\StudentStatusEnum;
use App\Enums\TermStatusEnum;
use App\Exports\StudentsExport;
use App\Http\Requests\ExportSelectedStudentsRequest;
use App\Http\Requests\ImportStudentRequest;
use App\Http\Requests\StudentRequest;
use App\Http\Resources\CurriculumOptionResource;
use App\Http\Resources\ScholarshipResource;
use App\Http\Resources\SportHouseResource;
use App\Http\Resources\StudentCurriculumResource;
use App\Http\Resources\StudentResource;
use App\Models\Curriculum;
use App\Models\FileUpload;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\StudentResult;
use App\Models\User;
use App\Repositories\ClassLevelArmRepository;
use App\Services\FileUploadService;
use App\Services\GuardianService;
use App\Services\StudentService;
use App\Support\ActiveSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class StudentController extends Controller
{
    public function __construct(
        protected StudentService $studentService,
        protected ClassLevelArmRepository $classLevelArmRepository,
        protected FileUploadService $fileUploadService,
        protected GuardianService $guardianService,
    ) {}

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
        $data['school_id'] = ActiveSchool::id();
        $data['photo_id'] = $this->uploadPhoto($request);
        unset($data['photo']);

        $guardianEntries = $data['guardians'] ?? [];
        unset($data['guardians']);

        $studentDto = StudentDto::fromArray($data);

        // Atomic: student + all guardians + pivot rows in one transaction.
        // If any guardian processing fails, the student is rolled back too — no orphans.
        $deferredNotifications = [];

        $reusedGuardians = [];

        $student = DB::transaction(function () use ($studentDto, $guardianEntries, &$deferredNotifications, &$reusedGuardians) {
            $student = $this->studentService->store($studentDto->toArray());
            $schoolId = (int) ActiveSchool::id();

            foreach ($guardianEntries as $index => $entry) {
                $this->processGuardianEntry($student, $entry, $schoolId, $deferredNotifications, (int) $index, $reusedGuardians);
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
            // WHICH GUARDIAN ROWS WERE REUSED RATHER THAN CREATED. The service has
            // reported this since the dedupe backstop landed and this caller threw it
            // away, so a registration that silently reused an existing record — and
            // therefore kept the STORED name over the one just typed, per
            // fillBlankGuardianFields — gave the operator no signal at all. Indexed by
            // the guardian entry's position so the form can say which row it means.
            return Response::created([
                'message' => 'Student created successfully.',
                'reused_guardians' => $reusedGuardians,
            ]);
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
        $data['school_id'] = ActiveSchool::id();
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
            // 'promoted' is NOT settable here (S1 promotion-link closure) — the sibling of the same rule on
            // UpdateStudentCurriculumStatusRequest. A student ARRIVES at 'promoted' via promote(), which
            // writes the link; asserting it manually would leave a status='promoted' row with a NULL link
            // (updateStatus never sets one), so a manual 'promoted' would fail the CHECK and surface as an
            // opaque database error the client cannot act on. Derived from the enum (every case except
            // PROMOTED), so a new status stays settable.
            'status' => ['required', 'string', Rule::enum(StudentStatusEnum::class)->except([StudentStatusEnum::PROMOTED])],
        ]);

        $this->studentService->updateStatus($student, $data['status']);

        if ($request->wantsJson()) {
            return Response::success('Student status updated successfully.');
        }

        return redirect()->route('students.index');
    }

    /**
     * The toolbar export: the CURRENT FILTER SET, computed server-side.
     *
     * Selection is ignored here by design — this button's scope is "what the filters select", and
     * the footer's "Export selected (N)" is the other scope. See StudentsExport.
     */
    public function export(Request $request)
    {
        $filename = 'students-'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(new StudentsExport($request), $filename);
    }

    /**
     * The footer export: EXACTLY the ticked pupils, whatever the filters behind the page say.
     *
     * POST rather than GET because a selection is a body, not an identity: a few hundred uuids do
     * not belong in a query string that a proxy log will keep.
     */
    public function exportSelected(ExportSelectedStudentsRequest $request)
    {
        $filename = 'students-selected-'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new StudentsExport($request, $request->uuids()),
            $filename,
        );
    }

    public function import(ImportStudentRequest $request)
    {
        $data = $request->validated();
        $schoolId = ActiveSchool::id();

        $result = $this->studentService->import(
            $data['students'],
            (int) $data['curriculum_id'],
            $schoolId,
        );

        if (! empty($result['errors'])) {
            return response()->json([
                'message' => "{$result['saved']} student(s) imported. ".count($result['errors']).' row(s) had errors and were skipped.',
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
            'classLevelArm.stream',
        ])
            ->whereHas('term', fn ($query) => $query->where('status', TermStatusEnum::ACTIVE))
            ->where('status', CurriculaStatusEnum::ACTIVE->value)->get();

        $genders = GenderTypeEnum::options();
        $school = ActiveSchool::getOrFail();

        return Response::success([
            'curricula' => CurriculumOptionResource::collection($curricula),
            'genders' => $genders,
            'guardian_relationships' => GuardianRelationshipEnum::options(),
            'sport_houses' => SportHouseResource::collection($school->sportHouses),
            'scholarships' => ScholarshipResource::collection($school->scholarships),
            // Distinct class levels and arms for the student-index filters. The
            // uuid is the value the /api/students filter matches against.
            'class_levels' => $school->classLevels()->orderBy('order')->get()
                ->map(fn ($cl) => ['id' => $cl['uuid'], 'name' => $cl['name']])->values(),
            'arms' => $school->arms()->get()
                ->map(fn ($arm) => ['id' => $arm['uuid'], 'label' => $arm['label']])->values(),
            // Which arms exist for each class level, so the arm filter can narrow
            // to the selected class level. Query-builder join (School predicate is
            // EXPLICIT since this bypasses the model scope); distinct because a
            // class-level/arm pair repeats once per stream.
            'class_level_arms' => DB::table('class_level_arms as cla')
                ->join('class_levels as cl', 'cl.id', '=', 'cla.class_level_id')
                ->join('arms as a', 'a.id', '=', 'cla.arm_id')
                ->where('cla.school_id', $school->id)
                ->orderBy('a.label')
                ->distinct()
                ->get(['cl.uuid as class_level', 'a.uuid as arm', 'a.label as label']),
        ]);
    }

    /**
     * Process a single guardian entry from the student registration form.
     *
     * $index and $reusedGuardians exist for the FORM, not for this method: the
     * registration screen renders errors per guardian row, so a refusal raised deep
     * inside GuardianService has to arrive keyed to the row that caused it.
     */
    private function processGuardianEntry(
        Student $student,
        array $entry,
        int $schoolId,
        array &$deferredNotifications,
        int $index = 0,
        array &$reusedGuardians = [],
    ): void {
        if (($entry['mode'] ?? null) === 'existing') {
            $guardian = $this->guardianService->resolveExistingGuardianForAttachment($entry, $schoolId);
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
            if ($entry['can_login'] && (! $existingPivot || ! $existingPivot->can_login)) {
                $user = $guardian->user;
                if ($user?->hasDeliverableEmail()) {
                    // The service handles credential reissue inside attachToStudent for existing pivots;
                    // for first-time can_login=true on a brand-new link we don't have a fresh password,
                    // so the guardian uses their existing credentials. No-op here.
                }
            }

            return;
        }

        // mode === 'new'
        //
        // RE-KEYED ONTO THE ROW. GuardianService's refusals are raised on the flat
        // field name — `email` — because the service has no idea which caller it is
        // serving or what that caller's payload looks like. This form addresses its
        // guardians as `guardians.{index}.{field}`, so without this the message lands
        // on a key the screen does not read and the operator sees a rolled-back
        // registration with no explanation.
        //
        // THIS IS PRECISION, NOT THE SAFETY NET. The net is the flat fallback in
        // guardian-sub-form.tsx — because a re-key here regresses silently the next
        // time a validator is added and nobody remembers this catch, whereas the
        // fallback catches every future flat key without anyone remembering. If this
        // block ever falls out of date the error is shown on the wrong row, which is
        // recoverable; the failure mode it exists to prevent — shown nowhere — is not.
        try {
            $result = $this->createGuardianForEntry($entry, $schoolId);
        } catch (ValidationException $e) {
            $rekeyed = [];
            foreach ($e->errors() as $field => $messages) {
                $rekeyed[str_contains($field, '.') ? $field : "guardians.{$index}.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($rekeyed);
        }

        if ($result['reused']) {
            $reusedGuardians[] = $index;
        }

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
     * @return array{guardian: Guardian, user: User, plain_password: ?string, reused: bool}
     */
    private function createGuardianForEntry(array $entry, int $schoolId): array
    {
        return $this->guardianService->createGuardianWithUser(
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
    }

    /**
     * Upload a new photo and return the file_uploads.id, or null if no file present.
     */
    private function uploadPhoto(Request $request): ?int
    {
        if (! $request->hasFile('photo')) {
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
        if (! $request->hasFile('photo')) {
            return $existingPhotoId;
        }

        if ($existingPhotoId) {
            $old = FileUpload::find($existingPhotoId);
            if ($old) {
                $this->fileUploadService->unlinkFileUpload($old->folder_path.'/'.$old->name, null);
                $this->fileUploadService->deleteFileUpload($existingPhotoId);
            }
        }

        return $this->fileUploadService->storeAndUploadFile($request, 'photo', 'students/photos');
    }

    public function activeResultStatus(Student $student)
    {
        $activeCurriculum = $student->currentCurriculum;
        $isAvailable = true;
        $subjectsOffered = $activeCurriculum->activeSubjects;
        foreach ($subjectsOffered as $subject) {
            $result = StudentResult::where('student_id', $student->id)->where('curriculum_subject_id', $subject->curriculum_subject_id)->first();
            if (! $result) {
                $isAvailable = false;
                break;
            }
        }
        if ($subjectsOffered->isEmpty()) {
            $isAvailable = false;

        }

        if ($isAvailable && auth()->user()->hasRole('guardian') && $activeCurriculum->status === StudentStatusEnum::ACTIVE) {
            if (! $activeCurriculum->principal_approval) {
                $isAvailable = false;
            }

            $deadline = $activeCurriculum->curriculum?->term?->result_visible_at;
            if ($isAvailable && $deadline && ! now()->greaterThan($deadline)) {
                $isAvailable = false;
            }
        }

        if (! $isAvailable) {
            // Fall back to the chronologically latest past enrollment (by its
            // term's end date, not created_at — backdated enrollments created
            // by BackfillPastTermJob are newer rows for older terms).
            $activeCurriculum = StudentCurriculum::where('student_id', $student->id)
                ->where('status', 'promoted')
                ->with('curriculum.term')
                ->get()
                ->sortByDesc(fn ($sc) => $sc->curriculum?->term?->end_date)
                ->first();
        }

        return response()->json([
            'available' => $isAvailable,
            'latest_available_result' => $activeCurriculum ? new StudentCurriculumResource($activeCurriculum) : null,
        ]);

    }
}
