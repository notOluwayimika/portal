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
use App\Models\Activity;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\GuardianService;
use App\Support\ActiveSchool;
use App\Support\Authz;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class GuardianController extends Controller
{
    public function __construct(
        protected GuardianService $guardianService,
    ) {}

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
     * Searches all schools and returns ward-school context without exposing
     * the identities of students outside the active school.
     */
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $schoolId = (int) ActiveSchool::id();

        $guardian = $this->guardianService->findGloballyByIdentifier($data['identifier']);

        if (! $guardian) {
            return response()->json([
                'message' => 'No guardian found with that identifier.',
            ], 404);
        }

        $wardSchools = DB::table('guardian_student')
            ->join('students', 'students.id', '=', 'guardian_student.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->where('guardian_student.guardian_id', $guardian->id)
            ->whereNull('students.deleted_at')
            ->groupBy('schools.id', 'schools.name')
            ->orderBy('schools.name')
            ->get([
                'schools.id',
                'schools.name',
                DB::raw('COUNT(DISTINCT students.id) as wards_count'),
            ])
            ->map(fn ($school) => [
                'name' => $school->name,
                'wards_count' => (int) $school->wards_count,
                'is_current_school' => (int) $school->id === $schoolId,
            ])
            ->values();

        return response()->json(['data' => [
            ...(new GuardianResource($guardian))->resolve($request),
            'ward_schools' => $wardSchools,
            'has_wards_in_other_schools' => $wardSchools->contains(fn ($school) => ! $school['is_current_school']),
        ]]);
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
        Authz::abilityCheck(request()->user(), 'guardian.create', 'GuardianController@store');

        $schoolId = (int) ActiveSchool::id();

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
            // Student is tenant-scoped (SchoolScope) — no explicit filter needed.
            $student = Student::where('admission_number', $link['admission_number'])->first();
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
        Authz::abilityCheck(request()->user(), 'guardian.export', 'GuardianController@export');

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
            // `email` is required only for mode=new AND can_login=true — the one case
            // that actually consumes it (createGuardianWithUser provisions the login).
            //
            // It used to be `required_if:can_login,true` regardless of mode, which was
            // a LIVE BUG, not just a mis-scope: on mode=existing the submitted email is
            // never read — resolveExistingGuardianForAttachment keys off
            // guardian_id/identifier, and a can_login upgrade re-issues credentials from
            // the guardian's OWN user->email via reissueCredentialsIfPossible. Meanwhile
            // add-guardian-modal.tsx sends only guardian_id + identifier for existing
            // mode, so "attach an existing guardian and give them login" 422'd from the
            // real UI on a field the backend then ignores.
            //
            // NOT `required_if:mode,new` (the shape the roadmap suggested): that would
            // over-require, demanding an email for every new guardian even when
            // can_login is false and no login is provisioned. The condition is the
            // CONJUNCTION, which required_if cannot express — hence the explicit build.
            'email' => $request->input('mode') === 'new'
                ? ['nullable', 'email', 'required_if:can_login,true']
                : ['nullable', 'email'],
        ]);

        $schoolId = (int) ActiveSchool::id();

        if ($data['mode'] === 'existing') {
            $guardian = $this->guardianService->resolveExistingGuardianForAttachment($data, $schoolId);
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
        Authz::abilityCheck(request()->user(), 'guardian.detach', 'GuardianController@detach');

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
        // S5 observe mode: records a would-be denial and continues; enforces
        // (abort 403) only when config('authz.enforce') is on. Restores this gate
        // as live code (clearing the commented-authz debt) without yet blocking.
        Authz::abilityCheck(request()->user(), 'guardian.view', 'GuardianController@students');

        $students = $this->guardianService->studentsFor($guardian);
        $students->load('school', 'currentCurriculum');

        return response()->json([
            'data' => $students->map(fn ($s) => [
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
                // A student between/without enrollments (or withdrawn) has no
                // current curriculum — null-guard so one such student does not 500
                // the guardian's entire student list.
                'current_class' => $s->currentCurriculum
                    ? new StudentCurriculumResource($s->currentCurriculum->load(['curriculum']))
                    : null,
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
        Authz::abilityCheck(request()->user(), 'guardian.enable_login', 'GuardianController@enableLogin');

        $this->guardianService->enableLogin($guardian, $guardian->students()->pluck('first_name')->toArray());

        return response()->json(GuardianResource::make($guardian->fresh(['user', 'photoFile'])));
    }

    /**
     * POST /api/guardians/{guardian:uuid}/disable-login
     * Admin-triggered login disable (sets User.disabled_at regardless of pivot state).
     */
    public function disableLogin(Request $request, Guardian $guardian)
    {
        Authz::abilityCheck(request()->user(), 'guardian.enable_login', 'GuardianController@disableLogin');

        $this->guardianService->disableLogin($guardian);

        return response()->json(GuardianResource::make($guardian->fresh(['user', 'photoFile'])));
    }

    /**
     * POST /api/guardians/{guardian:uuid}/reset-password
     * Sends a password-reset link to the guardian's registered email.
     */
    public function resetPassword(Request $request, Guardian $guardian)
    {
        Authz::abilityCheck(request()->user(), 'guardian.update_credentials', 'GuardianController@resetPassword');

        $guardian->load('user');
        $user = $guardian->user;

        // Restored 2026-07-20. Commented out by 883ff6c ("feat: phase 1 updates"), a
        // 62-file sweep that blanket-disabled 47 guards at once. a27b0a3's S5 rollout
        // put the AUTHORIZATION check above back as Authz::abilityCheck, but that sweep
        // was scoped to authorization by design — this is a precondition check, so it
        // was left orphaned and no lint covers it (ci-authz-lint reads authz only).
        //
        // Without it the endpoint dereferences $user->email with no null check and asks
        // the broker to mail a synthetic `@no-email.local` address that cannot receive
        // it — reporting success for a reset link nobody will ever get.
        //
        // 422 via abort(), not a ValidationException, so this is an HttpException and
        // is NOT affected by the pending 422-vs-400 business-rule convention decision.
        abort_unless(
            $user && $user->email && ! str_ends_with($user->email, '@no-email.local'),
            422,
            'This guardian has no valid email address for a password reset.'
        );

        Password::broker()->sendResetLink(['email' => $user->email]);

        return Response::success(['message' => 'Password reset link sent to guardian\'s email.']);
    }

    /**
     * POST /api/guardians/{guardian:uuid}/resend-invitation
     * Re-sends the initial login invitation to a guardian who has never activated their account.
     */
    public function resendInvitation(Request $request, Guardian $guardian)
    {
        Authz::abilityCheck(request()->user(), 'guardian.enable_login', 'GuardianController@resendInvitation');

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
        Authz::abilityCheck(request()->user(), 'guardian.view', 'GuardianController@activity');

        // `latest()` alone orders by created_at only. Activity rows written in the
        // same second share a timestamp, and MySQL's ordering among ties is
        // UNSPECIFIED — so "the last 10, most recent first" was not actually
        // guaranteed: which 10 came back, and in what order, varied run to run.
        // activity_log is append-only, so id is a monotonic tie-break that makes the
        // contract deterministic.
        //
        // This surfaced as a FLAKY GATE: the covering test sat in the ratchet baseline
        // and flipped between pass and fail on tie ordering, so the shrink-lock
        // randomly blocked pushes with "a baselined test now passes".
        $logs = $this->guardianAuditQuery($guardian)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn ($a) => $this->serializeActivity($a));

        return response()->json(['data' => $logs]);
    }

    /**
     * GET /api/guardians/{guardian:uuid}/audit
     * Full paginated audit history with optional event/date filters.
     */
    public function auditHistory(Request $request, Guardian $guardian)
    {
        Authz::abilityCheck(request()->user(), 'guardian.view_audit', 'GuardianController@auditHistory');

        $data = $request->validate([
            'event' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $paginated = $this->guardianAuditQuery($guardian)
            ->when($data['event'] ?? null, fn ($q) => $q->where('event', $data['event']))
            ->when($data['date_from'] ?? null, fn ($q) => $q->whereDate('created_at', '>=', $data['date_from']))
            ->when($data['date_to'] ?? null, fn ($q) => $q->whereDate('created_at', '<=', $data['date_to']))
            ->latest()
            ->paginate($data['per_page'] ?? 50);

        return response()->json([
            'data' => collect($paginated->items())->map(fn ($a) => $this->serializeActivity($a)),
            'pagination' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Audit trail for a guardian: activity on the guardian record itself,
     * plus everything involving their linked user account — both actions
     * done TO the account (e.g. password changed) and actions the account
     * performed itself (e.g. logins, logouts, password resets).
     */
    private function guardianAuditQuery(Guardian $guardian)
    {
        $userId = $guardian->user_id;

        return Activity::query()
            ->with('causer')
            ->where(function ($q) use ($guardian, $userId) {
                $q->where(fn ($sub) => $sub
                    ->where('subject_type', Guardian::class)
                    ->where('subject_id', $guardian->id));

                if ($userId) {
                    // actions on the linked user account (password set/changed, disabled, ...)
                    $q->orWhere(fn ($sub) => $sub
                        ->where('subject_type', User::class)
                        ->where('subject_id', $userId));

                    // actions performed by the account itself (login, logout, password reset, ...)
                    $q->orWhere(fn ($sub) => $sub
                        ->where('causer_type', User::class)
                        ->where('causer_id', $userId));
                }
            });
    }

    private function serializeActivity($a): array
    {
        return [
            'id' => $a->id,
            'event' => $a->event,
            'description' => $a->description,
            'properties' => $a->properties,
            'causer_name' => $a->causer?->full_name ?? $a->causer?->name,
            'created_at' => $a->created_at->toIso8601String(),
        ];
    }

    /**
     * POST /api/guardians/bulk-message
     * Queues announcement emails to a set of guardians.
     */
    public function bulkMessage(Request $request)
    {
        Authz::abilityCheck(request()->user(), 'guardian.message', 'GuardianController@bulkMessage');

        $data = $request->validate([
            'guardian_ids' => ['required', 'array'],
            'guardian_ids.*' => ['integer', 'exists:guardians,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'channels' => ['required', 'array', 'min:1'],
        ]);

        BulkMessageGuardiansJob::dispatch(
            $data['guardian_ids'],
            ActiveSchool::id(),
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
        Authz::abilityCheck(request()->user(), 'guardian.enable_login', 'GuardianController@bulkEnableLogin');

        $data = $request->validate([
            'guardian_ids' => ['required', 'array'],
            'guardian_ids.*' => ['integer', 'exists:guardians,id'],
        ]);

        BulkEnableGuardianLoginJob::dispatch(
            $data['guardian_ids'],
            ActiveSchool::id(),
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
        Authz::abilityCheck(request()->user(), 'guardian.enable_login', 'GuardianController@bulkDisableLogin');

        $data = $request->validate([
            'guardian_ids' => ['required', 'array'],
            'guardian_ids.*' => ['integer', 'exists:guardians,id'],
        ]);

        $this->guardianService->bulkDisableLogin($data['guardian_ids'], ActiveSchool::id());

        return Response::success('Bulk login disable processed successfully.');
    }

    /**
     * POST /api/guardians/bulk-status
     * Updates the status of a set of guardians.
     */
    public function bulkStatus(Request $request)
    {
        Authz::abilityCheck(request()->user(), 'guardian.update', 'GuardianController@bulkStatus');

        $data = $request->validate([
            'guardian_ids' => ['required', 'array'],
            'guardian_ids.*' => ['integer', 'exists:guardians,id'],
            'status' => ['required', 'string', Rule::in(GuardianStatusEnum::values())],
        ]);

        $this->guardianService->bulkUpdateStatus($data['guardian_ids'], $data['status'], ActiveSchool::id());

        return Response::success('Bulk status update processed successfully.');
    }

    public function setPassword(Request $request, Guardian $guardian)
    {
        Authz::abilityCheck(request()->user(), 'guardian.update', 'GuardianController@setPassword');

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $guardian->user;
        $user->update(['password' => Hash::make($data['password'])]);

        return Response::success('Password updated successfully.');
    }
}
