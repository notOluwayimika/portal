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
use App\Services\GuardianMatcher;
use App\Services\GuardianService;
use App\Support\ActiveSchool;
use App\Support\Authz;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    /**
     * GET /api/guardians/duplicate-check?email=&phone=&whatsapp_number=
     *
     * WARN, DO NOT DECIDE. This is the primary answer to "this person already
     * exists": the operator sees the match BEFORE they submit and chooses between
     * linking the existing record and creating a new one. The server-side dedupe in
     * GuardianService::createGuardianWithUser is only the backstop for a caller that
     * proceeds anyway. The reason it is a warning and not a 422 is the defect that
     * produced this endpoint: `Rule::unique('users','email')` on the create path was
     * a hard block with no way forward, so the school worked around it per-child and
     * manufactured the duplicates.
     *
     * GATED EXACTLY AS `lookup` IS, and by nothing else — same file, same route
     * group, therefore the same `permission:academic_setup.manage` middleware. No
     * in-method Authz call was added: `lookup` carries none, and adding one here
     * would make this endpoint STRICTER than the sibling it was specified to match,
     * which is a change to the access surface that belongs in its own reviewed
     * commit rather than riding along in a defect fix.
     */
    public function duplicateCheck(Request $request, GuardianMatcher $matcher)
    {
        $data = $request->validate([
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
        ]);

        $schoolId = (int) ActiveSchool::id();

        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;
        $whatsapp = $data['whatsapp_number'] ?? null;

        if (! $email && ! $phone && ! $whatsapp) {
            return response()->json(['data' => ['guardians' => [], 'account' => null]]);
        }

        // candidatesInSchool, not findInSchool: a READ surface should show the
        // operator BOTH candidates when email and phone disagree. findInSchool
        // raises there, which is right for a write and wrong for a warning.
        $candidates = $matcher->candidatesInSchool($email, $phone, $whatsapp, $schoolId);

        // by_phone is a COLLECTION now, and every candidate is listed. When a number
        // is shared by several guardians the write refuses rather than picking, so
        // this banner is the only place the operator can see who the refusal is
        // between — listing one of them would make the refusal unactionable.
        $guardians = collect([$candidates['by_email']])
            ->merge($candidates['by_phone'])
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn (Guardian $guardian) => [
                'uuid' => $guardian->uuid,
                'full_name' => $guardian->full_name,
                // MASKED. The operator needs enough to recognise the person they
                // just typed; they do not need the stored address handed back, and
                // this endpoint answers to anyone holding academic_setup.manage.
                'masked_email' => $this->maskEmail($guardian->user?->email),
                'masked_phone' => $this->maskPhone($guardian->phone),
                'student_count' => $guardian->students()
                    ->where('students.school_id', $schoolId)
                    ->count(),
            ]);

        // The email belongs to a real account that is NOT a guardian in this school
        // — staff, or a parent at another school. That is NOT a duplicate guardian,
        // and answering as though it were is how a member of staff silently acquires
        // a guardian role: createGuardianWithUser reuses the users row and
        // grantSchoolAccess then writes a school_user pivot AND a team role for it.
        // Surfaced as its own case so the UI must confirm rather than assume.
        $account = null;

        if ($email && $guardians->isEmpty()) {
            $existingUser = User::whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])->first();

            if ($existingUser) {
                $account = [
                    'exists' => true,
                    'masked_email' => $this->maskEmail($existingUser->email),
                    // Whether that account already reaches THIS school at all. A
                    // false here means proceeding grants it access it does not have.
                    'has_access_to_school' => $existingUser->accessibleSchoolIds()->contains($schoolId),
                ];
            }
        }

        return response()->json(['data' => [
            'guardians' => $guardians,
            'account' => $account,
        ]]);
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('•', max(mb_strlen($local) - 1, 1)).'@'.$domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        return mb_strlen($phone) <= 4
            ? str_repeat('•', mb_strlen($phone))
            : str_repeat('•', mb_strlen($phone) - 4).mb_substr($phone, -4);
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

        $validated = $request->validated();
        // READ FROM validated(), NOT input(). The old code read `student_links` off
        // input() while the key appeared in no rule at all, so nothing about it was
        // ever checked; reading the validated bag is what makes the rules in
        // GuardianRequest load-bearing rather than decorative.
        $links = $validated['student_links'] ?? [];
        $canLogin = (bool) ($validated['can_login'] ?? false);
        // The operator's explicit answer to "this address already belongs to an
        // account that is not a guardian here", carried from the banner's confirm
        // control. Absent means NOT confirmed, so a client that never renders the
        // banner — a stale tab, a script — fails closed rather than binding a
        // guardian to a stranger's account by omission.
        //
        // Read OUT here and passed in, not read inside the closure below: `$validated`
        // is not in that closure's `use` list, so `$validated[...] ?? false` there
        // silently evaluated to false for every caller and the confirmation could
        // never be given. It answered 422 forever, which looks like a working control
        // right up until someone tries to proceed — a green with no way through. The
        // arm that asserts the CONFIRMED path is what caught it.
        $confirmExistingAccount = (bool) ($validated['confirm_existing_account'] ?? false);

        // A CREATE FORM MAY NOT EDIT AN EXISTING LINK. Filled inside the transaction,
        // reported back afterwards. See the guard at the foot of the loop.
        $alreadyLinked = [];

        // ONE TRANSACTION over the guardian AND every attachment. There was none:
        // the guardian was committed by its own inner transaction and the links then
        // failed (or were silently dropped) outside it, leaving a guardian with zero
        // children and a 201 that said it had worked. Nesting inside
        // createGuardianWithUser's own transaction is a savepoint, which is fine.
        //
        // THE NOTIFICATION IS OUTSIDE, and that sentence used to claim more than it
        // could deliver. `notifyGuardian` below is genuinely outside — but
        // `attachToStudent` reaches `reissueCredentialsIfPossible`, which rotates a
        // password AND mails it from INSIDE, so "a rollback can never strand an email
        // in flight" was false for this transaction the moment reuse made that branch
        // reachable. It is true again only because the guard below stops this path
        // entering that branch at all; the general "mail inside a transaction" audit
        // across the other callers is a ticket, not a claim made here.
        $result = DB::transaction(function () use ($request, $schoolId, $canLogin, $links, $confirmExistingAccount, &$alreadyLinked) {
            $result = $this->guardianService->createGuardianWithUser(
                attributes: $request->safe()->only([
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
                canLogin: $canLogin,
                email: $request->validated('email'),
                confirmExistingAccount: $confirmExistingAccount,
            );

            foreach ($links as $i => $link) {
                // The school_id predicate is pinned here TOO, not left to Student's
                // global scope. The validation rule is the isolation guarantee and
                // this is defence in depth behind it; between them there is no
                // arrangement of scope state in which another school's child can be
                // attached.
                $student = Student::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->where('school_id', $schoolId)
                    ->where('admission_number', $link['admission_number'])
                    ->first();

                // Not a silent skip. Validation already proved this row resolves, so
                // reaching here means the student was removed between validation and
                // write — rare, and the one thing it must not do is what the old
                // code did, which was drop the link and answer 201.
                if (! $student) {
                    throw ValidationException::withMessages([
                        "student_links.{$i}.admission_number" => "Student {$link['admission_number']} could not be found in this school. Nothing was saved.",
                    ]);
                }

                // AN ALREADY-LINKED CHILD IS LEFT ALONE. The guard itself now lives in
                // GuardianService::attachUnlessAlreadyLinked, because `attach` below
                // needs the identical rule and carried the defect for a round while
                // this copy sat inline here.
                //
                // Before reuse existed, `store` always minted a fresh Guardian, so a
                // pre-existing pivot on this path was STRUCTURALLY UNREACHABLE and
                // attachToStudent's update branch never ran here. Reuse made it live
                // and nothing was re-examined. Re-entering an existing person plus a
                // child they are already linked to then overwrote `relationship`,
                // `is_primary` and `can_login` from CREATE-FORM DEFAULTS — and the
                // create modal's login checkbox defaults UNTICKED, so a resubmission
                // REVOKED that child's portal login. The false→true direction was
                // worse: it rotated the account's password and emailed it, from a
                // form the operator opened to add somebody.
                //
                // The rule, stated: on the reuse path an existing link is reported,
                // never rewritten. It is the same argument that refused the email
                // write in createGuardianWithUser — a create form is not the place to
                // change a record the operator was never shown, and there is a
                // permissioned, audited path (updatePivot) that exists for exactly
                // this. A downgrade nobody asked for is worse than a refusal.
                $attached = $this->guardianService->attachUnlessAlreadyLinked(
                    guardian: $result['guardian'],
                    student: $student,
                    // No `?? 'other'` fallback: relationship is a required, enum-checked
                    // field now, so the fallback is dead and a dead fallback reads as
                    // the designed behaviour for a case that cannot happen.
                    relationship: $link['relationship'],
                    isPrimary: (bool) ($link['is_primary'] ?? false),
                    canLogin: $canLogin,
                );

                if (! $attached) {
                    $alreadyLinked[] = $link['admission_number'];
                }
            }

            return $result;
        });

        $guardian = $result['guardian'];

        if ($result['plain_password']) {
            $this->guardianService->notifyGuardian(
                user: $result['user'],
                plainPassword: $result['plain_password'],
            );
        }

        return Response::created([
            'data' => GuardianResource::make($guardian),
            // The backstop fired: the operator submitted a person who was already a
            // guardian here and the row was reused rather than duplicated. Told, not
            // hidden — silent reuse leaves them unsure which record they just edited.
            'reused_existing_guardian' => $result['reused'],
            // …and which children were left exactly as they were. Reported rather
            // than silently rewritten; the operator asked to add links, so the ones
            // that already existed are the answer to a question they did ask.
            'already_linked' => $alreadyLinked,
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

        // THE SAME RULE AS `store`, AND IT WAS MISSING HERE FOR A ROUND. This screen
        // reuses a guardian exactly as `store` does — `createGuardianWithUser` returns
        // an existing row on mode=new, and `resolveExistingGuardianForAttachment`
        // returns one by definition on mode=existing — and then rewrote an existing
        // link from this form's defaults: portal login unticked meant a silent
        // revocation, ticked meant a password rotation and an email. The banner this
        // very modal renders (`guardian-duplicate-banner.tsx`, via `GuardianRow`)
        // promises "any child already linked to them is left exactly as it is", so the
        // operator was proceeding on a false statement.
        //
        // Applied to BOTH modes deliberately. mode=existing means "attach this person
        // to this child", not "edit the link you already have with this child"; POST
        // adds, and `updatePivot` (PUT) is where a link is changed.
        $attached = $this->guardianService->attachUnlessAlreadyLinked(
            guardian: $guardian,
            student: $student,
            relationship: $data['relationship'],
            isPrimary: (bool) $data['is_primary'],
            canLogin: (bool) $data['can_login'],
        );

        return Response::created([
            'message' => $attached
                ? 'Guardian attached to student successfully.'
                : 'This guardian is already linked to this student. Nothing was changed — open their record to edit the link.',
            'already_linked' => ! $attached,
        ]);
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

        return response()->json([
            'data' => $this->wardPayload($guardian),
        ]);
    }

    /**
     * GET /api/parent/wards
     * The parent portal's own ward list. Unlike `students()` above it takes NO
     * guardian id from the client: the Guardian is resolved server-side from the
     * authenticated User and the ACTIVE School.
     *
     * That is the whole point of this endpoint. A Guardian is a per-School record
     * (GuardianService::resolveExistingGuardianForAttachment), so a parent with
     * wards in two Schools has two `guardians` rows sharing one `users` row. The
     * portal used to read `auth.user.guardian` — an unordered `hasOne` whose global
     * scope matches on `school_id = active OR user has access to active`, so it
     * could hand back the OTHER School's Guardian row. Its wards were then filtered
     * out by Student's own SchoolScope, and the parent got 200 with an empty list
     * while the admin, sitting in the ward's School, saw the wards fine.
     */
    public function wards(Request $request)
    {
        $guardian = $this->guardianService->forUserInActiveSchool($request->user());

        // No Guardian record in this School is a legitimate empty list, not an
        // error: the parent may have wards in a School they have not switched to.
        return response()->json([
            'data' => $guardian ? $this->wardPayload($guardian) : [],
        ]);
    }

    /**
     * Shared ward/student projection. Scoped to the active School by Student's
     * SchoolScope, via GuardianService::studentsFor.
     */
    private function wardPayload(Guardian $guardian)
    {
        $students = $this->guardianService->studentsFor($guardian);
        $students->load('school', 'currentCurriculum');

        return $students->map(fn ($s) => [
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
        // PAIRED WITH THE MINT. This gate and GuardianService's synthetic-address mint
        // are two halves of one mechanism: the mint guarantees a non-null unique value,
        // this stops the password broker from mailing it. Retiring the mint without
        // this reader migrated would send resets to `{phone}@no-email.local`, which is
        // exactly the failure the sentinel was invented to prevent — so they move
        // together, and routing this through the predicate is what makes that possible.
        abort_unless(
            $user?->hasDeliverableEmail() ?? false,
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
