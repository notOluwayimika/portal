<?php

namespace App\Http\Controllers;

use App\Enums\GenderTypeEnum;
use App\Enums\GuardianIdTypeEnum;
use App\Enums\GuardianRelationshipEnum;
use App\Enums\GuardianStatusEnum;
use App\Enums\MaritalStatusEnum;
use App\Http\Requests\GuardianUpdateRequest;
use App\Http\Requests\PivotUpdateRequest;
use App\Http\Resources\GuardianResource;
use App\Http\Resources\StudentResource;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\GuardianService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;

class GuardianController extends Controller
{
    public function __construct(
        protected GuardianService $guardianService,
    ) {}

    public function index(Request $request)
    {
        $guardians = $this->guardianService->paginate($request);

        return response()->json([
            'data'       => GuardianResource::collection($guardians),
            'pagination' => [
                'total'         => $guardians->total(),
                'per_page'      => $guardians->perPage(),
                'current_page'  => $guardians->currentPage(),
                'last_page'     => $guardians->lastPage(),
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
            'genders'          => GenderTypeEnum::options(),
            'statuses'         => GuardianStatusEnum::options(),
            'id_types'         => GuardianIdTypeEnum::options(),
            'relationships'    => GuardianRelationshipEnum::options(),
            'marital_statuses' => MaritalStatusEnum::options(),
        ]);
    }

    /**
     * POST /api/students/{student:uuid}/guardians
     * Attach a guardian (new or existing) to a student post-registration.
     */
    public function attach(Request $request, Student $student)
    {
        $data = $request->validate([
            'mode'         => ['required', 'in:new,existing'],
            'relationship' => ['required', 'string', Rule::in(GuardianRelationshipEnum::values())],
            'is_primary'   => ['required', 'boolean'],
            'can_login'    => ['required', 'boolean'],
            'guardian_id'  => ['nullable', 'required_if:mode,existing', 'uuid'],
            'identifier'   => ['nullable', 'string'],
            'first_name'   => ['required_if:mode,new', 'string', 'max:255'],
            'last_name'    => ['required_if:mode,new', 'string', 'max:255'],
            'phone'        => ['required_if:mode,new', 'string', 'max:50'],
            'email'        => ['nullable', 'email', 'required_if:can_login,true'],
        ]);

        $schoolId = (int) (session('school_id') ?? $request->user()->school_id);

        if ($data['mode'] === 'existing') {
            $guardian = $this->guardianService->resolveExistingGuardian($data, $schoolId);
        } else {
            $result   = $this->guardianService->createGuardianWithUser(
                attributes: $request->only([
                    'first_name', 'middle_name', 'last_name', 'gender', 'phone', 'whatsapp_number',
                    'city', 'state', 'country', 'postal_code', 'occupation', 'employer_name',
                    'marital_status', 'emergency_contact', 'id_type', 'id_number', 'id_expiry_date',
                ]),
                schoolId:   $schoolId,
                canLogin:   (bool) $data['can_login'],
                email:      $data['email'] ?? null,
            );
            $guardian = $result['guardian'];

            if ($result['plain_password']) {
                $this->guardianService->notifyGuardian(
                    user:          $result['user'],
                    plainPassword: $result['plain_password'],
                    studentNames:  [$student->full_name],
                );
            }
        }

        $this->guardianService->attachToStudent(
            guardian:     $guardian,
            student:      $student,
            relationship: $data['relationship'],
            isPrimary:    (bool) $data['is_primary'],
            canLogin:     (bool) $data['can_login'],
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
        abort_unless($request->user()?->can('guardian.detach'), 403);

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
            'message'                => 'Guardian updated successfully.',
            'affected_student_count' => $updated->students()->count(),
            'data'                   => GuardianResource::make($updated->load('user', 'photoFile')),
        ]);
    }

    /**
     * GET /api/guardians/{guardian:uuid}/students
     * Lists students attached to this guardian (used by the impact-confirmation modal).
     */
    public function students(Guardian $guardian)
    {
        abort_unless(request()->user()?->can('guardian.view'), 403);

        $students = $this->guardianService->studentsFor($guardian);

        return response()->json([
            'data' => $students->map(fn($s) => [
                'id'           => $s->uuid,
                'full_name'    => $s->full_name,
                'admission_number' => $s->admission_number,
                'relationship' => $s->pivot->relationship,
                'is_primary'   => (bool) $s->pivot->is_primary,
                'can_login'    => (bool) $s->pivot->can_login,
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
            'pivot'   => [
                'relationship' => $pivot->relationship,
                'is_primary'   => (bool) $pivot->is_primary,
                'can_login'    => (bool) $pivot->can_login,
            ],
        ]);
    }

    /**
     * POST /api/guardians/{guardian:uuid}/enable-login
     * Explicit admin-triggered login enablement, independent of any pivot edit.
     */
    public function enableLogin(Request $request, Guardian $guardian)
    {
        abort_unless($request->user()?->can('guardian.enable_login'), 403);

        $this->guardianService->enableLogin($guardian, $guardian->students()->pluck('first_name')->toArray());

        return Response::success([
            'message' => 'Login enabled for guardian.',
            'data'    => GuardianResource::make($guardian->fresh(['user', 'photoFile'])),
        ]);
    }
}
