<?php

namespace App\Http\Controllers;

use App\Enums\GenderTypeEnum;
use App\Enums\GuardianIdTypeEnum;
use App\Enums\GuardianRelationshipEnum;
use App\Enums\GuardianStatusEnum;
use App\Enums\MaritalStatusEnum;
use App\Exports\GuardiansExport;
use App\Http\Requests\GuardianRequest;
use App\Http\Requests\GuardianUpdateRequest;
use App\Http\Requests\PivotUpdateRequest;
use App\Http\Resources\GuardianResource;
use App\Http\Resources\StudentCurriculumResource;
use App\Jobs\BulkEnableGuardianLoginJob;
use App\Jobs\BulkMessageGuardiansJob;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\GuardianService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class GuardianController extends Controller
{
    public function __construct(
        protected GuardianService $guardianService,
    ) {
    }

    public function index(Request $request)
    {
        $guardians = $this->guardianService->paginate($request);

        return response()->json([
            'data' => GuardianResource::collection($guardians),
            'pagination' => [
                'total' => $guardians->total(),
                'per_page' => $guardians->perPage(),
                'current_page' => $guardians->currentPage(),
                'last_page' => $guardians->lastPage(),
                'prev_page_url' => $guardians->previousPageUrl(),
                'next_page_url' => $guardians->nextPageUrl(),
            ],
        ]);
    }

    public function show(Guardian $guardian)
    {
        return response()->json(GuardianResource::make($this->guardianService->show($guardian)));
    }

    public function destroy(Guardian $guardian)
    {
        $this->guardianService->delete($guardian);
        return response()->noContent();
    }

    /**
     * GET /api/guardians/lookup?identifier=...
     * Scoped to current school. Returns guardian details or 404.
     */
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $schoolId = (int) (session('school_id') ?? $request->user()->school_id);

        $guardian = $this->guardianService->findInSchoolByIdentifier($data['identifier'], $schoolId);

        if (!$guardian) {
            return response()->json([
                'message' => 'No guardian found for this identifier in your school.',
            ], 404);
        }

        return response()->json(['data' => GuardianResource::make($guardian)]);
    }

    public function resources()
    {
        return Response::success([
            'genders' => GenderTypeEnum::options(),
            'statuses' => GuardianStatusEnum::options(),
            'id_types' => GuardianIdTypeEnum::options(),
            'relationships' => GuardianRelationshipEnum::options(),
            'marital_statuses' => MaritalStatusEnum::options(),
        ]);
    }

    /**
     * POST /api/guardians
     * Standalone guardian creation (no student context required).
     * Optionally links to one or more students via student_links[].
     */
    public function store(GuardianRequest $request)
    {
        // abort_unless($request->user()?->can('guardian.create'), 403);

        $schoolId = (int) (session('school_id') ?? $request->user()->school_id);

        $result = $this->guardianService->createGuardianWithUser(
            attributes: $request->only([
                'first_name',
                'middle_name',
                'last_name',
                'gender',
                'phone',
                'whatsapp_number',
                'city',
                'state',
                'country',
                'postal_code',
                'occupation',
                'employer_name',
                'marital_status',
                'emergency_contact',
                'id_type',
                'id_number',
                'id_expiry_date',
            ]),
            schoolId: $schoolId,
            canLogin: (bool) $request->input('can_login', false),
            email: $request->input('email'),
        );

        $guardian = $result['guardian'];

        if ($result['plain_password']) {
            $this->guardianService->notifyGuardian(
                user: $result['user'],
                plainPassword: $result['plain_password'],
            );
        }

        foreach ($request->input('student_links', []) as $link) {
            $student = Student::where('admission_number', $link['admission_number'])->where('school_id', $schoolId)->first();
            if ($student) {
                $this->guardianService->attachToStudent(
                    guardian: $guardian,
                    student: $student,
                    relationship: $link['relationship'] ?? 'other',
                    isPrimary: (bool) ($link['is_primary'] ?? false),
                    canLogin: (bool) $request->input('can_login', false),
                );
            }
        }

        return Response::created([
            'data' => GuardianResource::make($guardian),
            'redirect' => "/guardians/{$guardian->uuid}",
        ]);
    }

    /**
     * GET /api/guardians/export
     * Downloads a CSV of guardians using the same filters as the index.
     */
    public function export(Request $request)
    {
        // abort_unless($request->user()?->can('guardian.export'), 403);

        return Excel::download(new GuardiansExport($request), 'guardians.csv', \Maatwebsite\Excel\Excel::CSV);
    }

    /**
     * POST /api/students/{student:uuid}/guardians
     * Attach a guardian (new or existing) to a student post-registration.
     */
    public function attach(Request $request, Student $student)
    {
        $data = $request->validate([
            'mode' => ['required', 'in:new,existing'],
            'relationship' => ['required', 'string', Rule::in(GuardianRelationshipEnum::values())],
            'is_primary' => ['required', 'boolean'],
            'can_login' => ['required', 'boolean'],
            'guardian_id' => ['nullable', 'required_if:mode,existing', 'uuid'],
            'identifier' => ['nullable', 'string'],
            'first_name' => ['required_if:mode,new', 'string', 'max:255'],
            'last_name' => ['required_if:mode,new', 'string', 'max:255'],
            'phone' => ['required_if:mode,new', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'required_if:can_login,true'],
        ]);

        $schoolId = (int) (session('school_id') ?? $request->user()->school_id);

        if ($data['mode'] === 'existing') {
            $guardian = $this->guardianService->resolveExistingGuardian($data, $schoolId);
        } else {
            $result = $this->guardianService->createGuardianWithUser(
                attributes: $request->only([
                    'first_name',
                    'middle_name',
                    'last_name',
                    'gender',
                    'phone',
                    'whatsapp_number',
                    'city',
                    'state',
                    'country',
                    'postal_code',
                    'occupation',
                    'employer_name',
                    'marital_status',
                    'emergency_contact',
                    'id_type',
                    'id_number',
                    'id_expiry_date',
                ]),
                schoolId: $schoolId,
                canLogin: (bool) $data['can_login'],
                email: $data['email'] ?? null,
            );
            $guardian = $result['guardian'];

            if ($result['plain_password']) {
                $this->guardianService->notifyGuardian(
                    user: $result['user'],
                    plainPassword: $result['plain_password'],
                    studentNames: [$student->full_name],
                );
            }
        }

        $this->guardianService->attachToStudent(
            guardian: $guardian,
            student: $student,
            relationship: $data['relationship'],
            isPrimary: (bool) $data['is_primary'],
            canLogin: (bool) $data['can_login'],
        );

        return Response::created('Guardian attached to student successfully.');
    }

    /**
     * DELETE /api/students/{student:uuid}/guardians/{guardian:uuid}
     *
     * Guards:
     *  - Student must keep at least one guardian (422 otherwise).
     *  - If the detached guardian was primary, `replacement_primary_guardian_uuid` is required.
     */
    public function detach(Request $request, Student $student, Guardian $guardian)
    {
        // abort_unless($request->user()?->can('guardian.detach'), 403);

        $data = $request->validate([
            'replacement_primary_guardian_uuid' => ['nullable', 'uuid'],
        ]);

        $this->guardianService->detachFromStudent(
            $student,
            $guardian,
            $data['replacement_primary_guardian_uuid'] ?? null,
        );

        return response()->noContent();
    }

    /**
     * PUT /api/guardians/{guardian:uuid}
     * Returns the impact: how many students will see the change.
     */
    public function update(GuardianUpdateRequest $request, Guardian $guardian)
    {
        $updated = $this->guardianService->update($guardian, $request->validated());

        return Response::success([
            'message' => 'Guardian updated successfully.',
            'affected_student_count' => $updated->students()->count(),
            'data' => GuardianResource::make($updated->load('user', 'photoFile')),
        ]);
    }

    /**
     * GET /api/guardians/{guardian:uuid}/students
     * Lists students attached to this guardian (used by the impact-confirmation modal).
     */
    public function students(Guardian $guardian)
    {
        // abort_unless(request()->user()?->can('guardian.view'), 403);

        $students = $this->guardianService->studentsFor($guardian);
        $students->load('school', 'currentCurriculum');
        return response()->json([
            'data' => $students->map(fn($s) => [
                'id' => $s->uuid,
                'full_name' => $s->full_name,
                'admission_number' => $s->admission_number,
                'relationship' => $s->pivot->relationship,
                'is_primary' => (bool) $s->pivot->is_primary,
                'can_login' => (bool) $s->pivot->can_login,
                'first_name' => $s->first_name,
                'middle_name' => $s->middle_name,
                'last_name' => $s->last_name,
                'gender' => $s->gender,
                'date_of_birth' => $s->date_of_birth,
                'photo' => $s->photo,
                'school' => $s->school ? [
                    'id' => $s->school->id,
                    'name' => $s->school->name,
                ] : null,
                'current_class' => new StudentCurriculumResource($s->currentCurriculum->load(['curriculum'])),
            ]),
        ]);
    }

    /**
     * PUT /api/students/{student:uuid}/guardians/{guardian:uuid}
     * Update pivot-only fields (relationship, is_primary, can_login).
     */
    public function updatePivot(PivotUpdateRequest $request, Student $student, Guardian $guardian)
    {
        $pivot = $this->guardianService->updatePivot($student, $guardian, $request->validated());

        return Response::success([
            'message' => 'Guardian relationship updated.',
            'pivot' => [
                'relationship' => $pivot->relationship,
                'is_primary' => (bool) $pivot->is_primary,
                'can_login' => (bool) $pivot->can_login,
            ],
        ]);
    }

    /**
     * POST /api/guardians/{guardian:uuid}/enable-login
     * Explicit admin-triggered login enablement, independent of any pivot edit.
     */
    public function enableLogin(Request $request, Guardian $guardian)
    {
        // abort_unless($request->user()?->can('guardian.enable_login'), 403);

        $this->guardianService->enableLogin($guardian, $guardian->students()->pluck('first_name')->toArray());

        return response()->json(GuardianResource::make($guardian->fresh(['user', 'photoFile'])));
    }

    /**
     * POST /api/guardians/{guardian:uuid}/disable-login
     * Admin-triggered login disable (sets User.disabled_at regardless of pivot state).
     */
    public function disableLogin(Request $request, Guardian $guardian)
    {
        // abort_unless($request->user()?->can('guardian.enable_login'), 403);

        $this->guardianService->disableLogin($guardian);

        return response()->json(GuardianResource::make($guardian->fresh(['user', 'photoFile'])));
    }

    /**
     * POST /api/guardians/{guardian:uuid}/reset-password
     * Sends a password-reset link to the guardian's registered email.
     */
    public function resetPassword(Request $request, Guardian $guardian)
    {
        // abort_unless($request->user()?->can('guardian.update_credentials'), 403);

        $guardian->load('user');
        $user = $guardian->user;

        // abort_unless(
        //     $user && $user->email && !str_ends_with($user->email, '@no-email.local'),
        //     422,
        //     'This guardian has no valid email address for a password reset.'
        // );

        Password::broker()->sendResetLink(['email' => $user->email]);

        return Response::success(['message' => 'Password reset link sent to guardian\'s email.']);
    }

    /**
     * POST /api/guardians/{guardian:uuid}/resend-invitation
     * Re-sends the initial login invitation to a guardian who has never activated their account.
     */
    public function resendInvitation(Request $request, Guardian $guardian)
    {
        // abort_unless($request->user()?->can('guardian.enable_login'), 403);

        $studentNames = $guardian->students()->pluck('first_name')->toArray();

        $this->guardianService->resendInvitation($guardian, $studentNames);

        return Response::success(['message' => 'Invitation resent to guardian.']);
    }

    /**
     * GET /api/guardians/{guardian:uuid}/activity
     * Returns the last 10 activity log entries for this guardian.
     */
    public function activity(Request $request, Guardian $guardian)
    {
        // abort_unless($request->user()?->can('guardian.view'), 403);

        $logs = $guardian->activities()
            ->with('causer')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'event' => $a->event,
                'description' => $a->description,
                'properties' => $a->properties,
                'causer_name' => $a->causer?->full_name ?? $a->causer?->name,
                'created_at' => $a->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $logs]);
    }

    /**
     * GET /api/guardians/{guardian:uuid}/audit
     * Full paginated audit history with optional event/date filters.
     */
    public function auditHistory(Request $request, Guardian $guardian)
    {
        // abort_unless($request->user()?->can('guardian.view_audit'), 403);

        $data = $request->validate([
            'event' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $paginated = $guardian->activities()
            ->with('causer')
            ->when($data['event'] ?? null, fn($q) => $q->where('event', $data['event']))
            ->when($data['date_from'] ?? null, fn($q) => $q->whereDate('created_at', '>=', $data['date_from']))
            ->when($data['date_to'] ?? null, fn($q) => $q->whereDate('created_at', '<=', $data['date_to']))
            ->latest()
            ->paginate($data['per_page'] ?? 50);

        return response()->json([
            'data' => collect($paginated->items())->map(fn($a) => [
                'id' => $a->id,
                'event' => $a->event,
                'description' => $a->description,
                'properties' => $a->properties,
                'causer_name' => $a->causer?->full_name ?? $a->causer?->name,
                'created_at' => $a->created_at->toIso8601String(),
            ]),
            'pagination' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/guardians/bulk-message
     * Queues announcement emails to a set of guardians.
     */
    public function bulkMessage(Request $request)
    {
        // abort_unless($request->user()?->can('guardian.message'), 403);

        $data = $request->validate([
            'guardian_ids' => ['required', 'array'],
            'guardian_ids.*' => ['integer', 'exists:guardians,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'channels' => ['required', 'array', 'min:1'],
        ]);

        BulkMessageGuardiansJob::dispatch(
            $data['guardian_ids'],
            $request->user()->school_id,
            $data['subject'],
            $data['body'],
            $data['channels'],
        );

        return Response::success('Bulk message queued successfully.');
    }

    /**
     * POST /api/guardians/bulk-enable-login
     * Queues login-enablement for a set of guardians.
     */
    public function bulkEnableLogin(Request $request)
    {
        // abort_unless($request->user()?->can('guardian.enable_login'), 403);

        $data = $request->validate([
            'guardian_ids' => ['required', 'array'],
            'guardian_ids.*' => ['integer', 'exists:guardians,id'],
        ]);

        BulkEnableGuardianLoginJob::dispatch(
            $data['guardian_ids'],
            $request->user()->school_id,
            $request->user()->id,
        );

        return Response::success('Bulk login enable queued successfully.');
    }

    /**
     * POST /api/guardians/bulk-disable-login
     * Synchronously disables login for a set of guardians.
     */
    public function bulkDisableLogin(Request $request)
    {
        // abort_unless($request->user()?->can('guardian.enable_login'), 403);

        $data = $request->validate([
            'guardian_ids' => ['required', 'array'],
            'guardian_ids.*' => ['integer', 'exists:guardians,id'],
        ]);

        $this->guardianService->bulkDisableLogin($data['guardian_ids'], $request->user()->school_id);

        return Response::success('Bulk login disable processed successfully.');
    }

    /**
     * POST /api/guardians/bulk-status
     * Updates the status of a set of guardians.
     */
    public function bulkStatus(Request $request)
    {
        // abort_unless($request->user()?->can('guardian.update'), 403);

        $data = $request->validate([
            'guardian_ids' => ['required', 'array'],
            'guardian_ids.*' => ['integer', 'exists:guardians,id'],
            'status' => ['required', 'string', Rule::in(GuardianStatusEnum::values())],
        ]);

        $this->guardianService->bulkUpdateStatus($data['guardian_ids'], $data['status'], $request->user()->school_id);

        return Response::success('Bulk status update processed successfully.');
    }
}
