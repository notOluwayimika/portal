<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use App\Notifications\GuardianAccountCreatedNotification;
use App\Repositories\GuardianRepository;
use App\Support\ActiveSchool;
use App\Support\PhoneNormalizer;
use App\Support\SchoolAccess;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GuardianService
{
    public function __construct(
        private FileUploadService $fileUploadService,
        private PasswordGeneratorService $passwordGenerator,
        private GuardianRepository $guardianRepository,
    ) {}

    public function paginate(Request $request): LengthAwarePaginator
    {
        $schoolId = (int) ActiveSchool::id();
        // 'login' comes from main's guardian-login sort; the rest from staging. The
        // two sides changed different things here, so the merge keeps both.
        $sortBy = in_array($request->sort_by, ['name', 'phone', 'students_count', 'created_at', 'login']) ? $request->sort_by : 'created_at';
        $sortDir = $request->sort_dir === 'asc' ? 'asc' : 'desc';
        $column = match ($sortBy) {
            'name' => 'last_name',
            'students_count' => 'students_count',
            'login' => null,
            default => "guardians.{$sortBy}",
        };

        return Guardian::withoutGlobalScope(SchoolScope::class)
            ->join('users', 'users.id', '=', 'guardians.user_id')
            // Only guardians whose User has access to this School. Flag-gated via
            // SchoolAccess (school_user pivot today, model_has_roles under the S7
            // single-source flag) — replaces the former INNER JOIN on school_user
            // with an equivalent existence filter (no row duplication).
            ->whereIn('users.id', SchoolAccess::userIdsWithAccessTo($schoolId))
            // A Guardian is a per-School record (§6.2): list only this School's rows,
            // not every Guardian whose (shared) User can access this School.
            ->where('guardians.school_id', $schoolId)
            ->select('guardians.*')
            ->withCount([
                'students as students_count' => fn ($query) => $query->where('students.school_id', $schoolId),
            ])
            ->when($request->search, function ($q) use ($request) {
                $term = '%'.$request->search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('guardians.first_name', 'LIKE', $term)
                        ->orWhere('guardians.last_name', 'LIKE', $term)
                        ->orWhere('guardians.phone', 'LIKE', $term)
                        ->orWhere('guardians.whatsapp_number', 'LIKE', $term)
                        ->orWhere('users.email', 'LIKE', $term);
                });
            })
            ->when($request->status, fn ($q) => $q->where('guardians.status', $request->status))
            ->when($request->login_access === 'has_login', fn ($q) => $q->whereNotNull('users.id')->whereNull('users.disabled_at'))
            ->when($request->login_access === 'no_login', fn ($q) => $q->where(fn ($inner) => $inner->whereNull('users.id')->orWhereNotNull('users.disabled_at')))
            ->when($request->children_count === '1', fn ($q) => $q->havingRaw('students_count = 1'))
            ->when($request->children_count === '2-3', fn ($q) => $q->havingRaw('students_count BETWEEN 2 AND 3'))
            ->when($request->children_count === '4+', fn ($q) => $q->havingRaw('students_count >= 4'))
            ->when($request->date_from, fn ($q) => $q->whereDate('guardians.created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('guardians.created_at', '<=', $request->date_to))
            ->with(['photoFile', 'user'])
            // 'login' sorts by whether the User is enabled, so it has no $column
            // ($column is null for it) — hence the two mutually-exclusive branches
            // rather than a single orderBy.
            ->when($sortBy === 'login', fn ($q) => $q->orderByRaw('(users.disabled_at IS NULL) '.$sortDir))
            ->when($sortBy !== 'login', fn ($q) => $q->orderBy($column, $sortDir))
            ->paginate($request->integer('per_page', 25));
    }

    public function bulkUpdateStatus(array $ids, string $status, int $schoolId): int
    {
        $guardians = Guardian::whereIn('id', $ids)->get();

        foreach ($guardians as $guardian) {
            $guardian->update(['status' => $status]);
            activity('guardian')
                ->performedOn($guardian)
                ->causedBy(auth()->user())
                ->event('status_updated')
                ->log("Status changed to {$status} via bulk action");
        }

        return $guardians->count();
    }

    public function bulkDisableLogin(array $ids, int $schoolId): int
    {
        $guardians = Guardian::whereIn('id', $ids)->with('user')->get();
        $count = 0;

        foreach ($guardians as $guardian) {
            if ($guardian->user && ! $guardian->user->isDisabled()) {
                $this->disableLogin($guardian);
                $count++;
            }
        }

        return $count;
    }

    public function show(Guardian $guardian): Guardian
    {
        return $guardian->load(['photoFile', 'user', 'students']);
    }

    public function delete(Guardian $guardian): bool
    {
        return (bool) $guardian->delete();
    }

    /**
     * Look up an existing guardian by email or phone within the given school.
     * Returns null on miss.
     */
    public function findInSchoolByIdentifier(string $identifier, int $schoolId): ?Guardian
    {
        return $this->guardianRepository->findByIdentifierInSchool($identifier, $schoolId);
    }

    public function findGloballyByIdentifier(string $identifier): ?Guardian
    {
        return $this->guardianRepository->findByIdentifierGlobally($identifier);
    }

    /**
     * Resolve a guardian to attach for Case B (existing). Accepts either uuid or identifier.
     * Throws ValidationException if not found in the given school.
     */
    public function resolveExistingGuardian(array $entry, int $schoolId): Guardian
    {
        $guardian = null;

        if (! empty($entry['guardian_id'])) {
            $guardian = $this->guardianRepository->findByUuidInSchool($entry['guardian_id'], $schoolId);
        } elseif (! empty($entry['identifier'])) {
            $guardian = $this->guardianRepository->findByIdentifierInSchool($entry['identifier'], $schoolId);
        }

        if (! $guardian) {
            throw ValidationException::withMessages([
                'guardians' => 'An existing guardian could not be found for the provided identifier in this school.',
            ]);
        }

        return $guardian;
    }

    public function resolveExistingGuardianForAttachment(array $entry, int $schoolId): Guardian
    {
        $guardian = ! empty($entry['guardian_id'])
            ? $this->guardianRepository->findByUuidGlobally($entry['guardian_id'])
            : $this->guardianRepository->findByIdentifierGlobally($entry['identifier'] ?? '');

        if (! $guardian) {
            throw ValidationException::withMessages([
                'guardian_id' => 'The selected guardian could not be found.',
            ]);
        }

        $user = $guardian->user;

        if (! $user) {
            throw ValidationException::withMessages([
                'guardian_id' => 'The selected guardian has no user account.',
            ]);
        }

        $user->grantSchoolAccess(School::findOrFail($schoolId), 'guardian');

        // A Guardian is a per-School record (§6.2): if the resolved Guardian
        // belongs to another School, resolve (or create) the same User's Guardian
        // in the target School so the eventual student link is never cross-School.
        if ((int) $guardian->school_id !== $schoolId) {
            $guardian = $this->resolveOrCreateGuardianForUserInSchool($user, $guardian, $schoolId);
        }

        return $guardian;
    }

    /**
     * The target-School Guardian for a User, creating one (cloned from an
     * existing-School Guardian) if none exists yet. Preserves the shared User.
     */
    private function resolveOrCreateGuardianForUserInSchool(User $user, Guardian $template, int $schoolId): Guardian
    {
        $existing = Guardian::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('school_id', $schoolId)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $guardian = $template->replicate(['uuid']);
        $guardian->school_id = $schoolId;
        $guardian->save();

        return $guardian->fresh(['user']);
    }

    /**
     * Case A: create a brand-new User + Guardian + assign `guardian` role.
     * Wrapped in a DB::transaction. The notification is queued AFTER the transaction
     * commits so that a rollback never leaves a stranded email in flight.
     *
     * @return array{guardian: Guardian, user: User, plain_password: ?string}
     */
    public function createGuardianWithUser(array $attributes, int $schoolId, bool $canLogin, ?string $email): array
    {
        $plainPassword = $this->passwordGenerator->generate();

        // Normalize at the storage boundary so import/lookup/registration all share one canonical form.
        if (isset($attributes['phone'])) {
            $attributes['phone'] = PhoneNormalizer::normalize($attributes['phone']) ?? $attributes['phone'];
        }
        if (isset($attributes['whatsapp_number'])) {
            $attributes['whatsapp_number'] = PhoneNormalizer::normalize($attributes['whatsapp_number']) ?? $attributes['whatsapp_number'];
        }
        if (isset($attributes['emergency_contact'])) {
            $attributes['emergency_contact'] = PhoneNormalizer::normalize($attributes['emergency_contact']) ?? $attributes['emergency_contact'];
        }
        $email = $email ? Str::lower($email) : null;

        return DB::transaction(function () use ($attributes, $schoolId, $canLogin, $email, $plainPassword) {
            // THE SYNTHETIC MINT IS RETIRED. `users.email` is nullable for accounts
            // that cannot log in, so a guardian on record with no address simply has
            // none — rather than a `{phone}@no-email.local` placeholder that is
            // structurally a valid address, needs a predicate to recognise, and was
            // minted solely to satisfy a NOT NULL + UNIQUE column.
            //
            // #203's invariant is what keeps this safe: `can_login ⟹ deliverable
            // email`, enforced at every transition, so a LOGIN account can never
            // reach here with a null address — which matters because
            // Password::sendResetLink resolves the user BY that column.
            $userEmail = $email ?: null;

            // One human = one User (§6.2). Reuse the existing account when the same
            // email is already registered (e.g. this guardian exists at another
            // School); create a fresh User only for a genuinely new person.
            $user = User::where('email', $userEmail)->first();
            $isNewUser = $user === null;

            if ($isNewUser) {
                $user = User::create([
                    'first_name' => $attributes['first_name'],
                    'last_name' => $attributes['last_name'],
                    'email' => $userEmail,
                    'school_id' => $schoolId,
                    'password' => $plainPassword,
                ]);
            }

            $user->grantSchoolAccess(School::findOrFail($schoolId), 'guardian');

            // Create the Guardian directly (not via the hasOne relation): one User
            // may back one Guardian per School.
            $guardian = Guardian::create(array_merge($attributes, [
                'school_id' => $schoolId,
                'user_id' => $user->id,
                'status' => $attributes['status'] ?? 'active',
            ]));

            return [
                'guardian' => $guardian->fresh(['user', 'photoFile']),
                'user' => $user,
                // Surface a password only for a newly created, login-enabled account.
                'plain_password' => $isNewUser && $canLogin && $email ? $plainPassword : null,
            ];
        });
    }

    /**
     * `can_login = true` REQUIRES a deliverable email. The single enforcement point.
     *
     * WHY THIS IS AN INVARIANT AND NOT A PREFERENCE. Login is email-only —
     * FortifyServiceProvider authenticates with `User::where('email', …)` and there is
     * no phone or username path — so `users.email` is the sole auth key. A guardian
     * flagged `can_login` without a deliverable address is an account that CANNOT be
     * logged into and CANNOT be told its password: enableLogin regenerates the
     * password, notifyGuardian early-returns on the undeliverable address, and the
     * flip reports success. A parent is recorded as having portal access they do not
     * have, silently.
     *
     * IT WAS ENFORCED AT CREATION ONLY. GuardianController's store rules require an
     * email when `can_login` is true for a NEW guardian, and PivotUpdateRequest
     * validates `can_login` as a bare boolean — so the false→true TRANSITION had no
     * check at all, and has been producing violations in production.
     *
     * THE CHECK LIVES AT THE PIVOT WRITE, not in the side-effect handlers, because
     * `can_login` is a column on `guardian_student` rather than on `users`: "is a
     * login account" is an aggregate over pivots, so no CHECK constraint on `users`
     * can express it. Both writers (attachToStudent, updatePivot) call this, and
     * GuardianLoginInvariantTest pins that no third writer appears elsewhere.
     *
     * It also underwrites the password-reset path. `Password::broker()->sendResetLink`
     * uses the address as an IDENTITY KEY before it is ever a delivery target, so a
     * login account without a real email is the one row where the broker resolves
     * nobody. This invariant is what makes that state unreachable.
     */
    private function assertLoginRequiresDeliverableEmail(Guardian $guardian, bool $canLogin): void
    {
        if (! $canLogin) {
            return;
        }

        if ($guardian->user?->hasDeliverableEmail()) {
            return;
        }

        throw ValidationException::withMessages([
            'can_login' => 'This guardian has no deliverable email address, so login cannot be enabled. '
                .'Add a real email address for them first.',
        ]);
    }

    /**
     * Attach a guardian to a student via the pivot. Idempotent on (guardian_id, student_id).
     * If can_login is being raised from false to true and the guardian has a real email,
     * re-issue credentials (generate a fresh password and notify).
     */
    public function attachToStudent(
        Guardian $guardian,
        Student $student,
        string $relationship,
        bool $isPrimary,
        bool $canLogin,
    ): void {
        $this->assertLoginRequiresDeliverableEmail($guardian, $canLogin);

        $existingPivot = DB::table('guardian_student')
            ->where('guardian_id', $guardian->id)
            ->where('student_id', $student->id)
            ->first();

        $student->guardians()->syncWithoutDetaching([
            $guardian->id => [
                'relationship' => $relationship,
                'is_primary' => $isPrimary,
                'can_login' => $canLogin,
            ],
        ]);

        // THE ATTACH SIDE WAS THE ONE UNLOGGED PIVOT TRANSITION. `detached`,
        // `login_enabled`, `login_disabled` and `pivot_updated` were all already
        // recorded; creating the link was not — so "who gave this adult access to
        // this child, and when" had no answer, while "who took it away" did.
        //
        // Spatie logs MODEL attributes and cannot see a pivot write at all, so this
        // has to be an explicit call; there is no configuration that would have
        // covered it.
        if (! $existingPivot) {
            $this->logPivotEvent($guardian, $student, 'attached', [
                'relationship' => $relationship,
                'is_primary' => $isPrimary,
                'can_login' => $canLogin,
            ]);
        }

        // Already attached — just update pivot fields if anything changed.
        if ($existingPivot) {
            $student->guardians()->updateExistingPivot($guardian->id, [
                'relationship' => $relationship,
                'is_primary' => $isPrimary,
                'can_login' => $canLogin,
            ]);

            // can_login was upgraded from false → true for an existing link — re-issue creds.
            if (! $existingPivot->can_login && $canLogin) {
                $this->reissueCredentialsIfPossible($guardian, $student);
            }
        }

        if ($isPrimary) {
            // Enforce single-primary at the row level: clear other rows for this student.
            DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', '!=', $guardian->id)
                ->update(['is_primary' => false]);
        }
    }

    /**
     * Send guardian login credentials. Best-effort: failures are logged but do not abort the request.
     */
    public function notifyGuardian(User $user, string $plainPassword, array $studentNames = []): void
    {
        if (! $user->hasDeliverableEmail()) {
            return;
        }

        try {
            $schoolName = optional($user->school)->name ?? config('app.name');
            $user->notify(new GuardianAccountCreatedNotification(
                plainPassword: $plainPassword,
                schoolName: $schoolName,
                loginUrl: url('/login'),
                studentNames: $studentNames,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send guardian account notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function reissueCredentialsIfPossible(Guardian $guardian, Student $student): void
    {
        $user = $guardian->user;
        if (! $user?->hasDeliverableEmail()) {
            return;
        }

        $plainPassword = $this->passwordGenerator->generate();
        $user->update(['password' => $plainPassword]);

        $this->notifyGuardian($user, $plainPassword, [$student->full_name]);
    }

    /**
     * Update a guardian's own details. Spatie LogsActivity records the diff on save.
     *
     * Caller (form request) is responsible for permission gating sensitive fields
     * (email, phone) when the guardian has an active login. If `email` is present
     * we also update the underlying User row.
     */
    public function update(Guardian $guardian, array $attributes): Guardian
    {
        return DB::transaction(function () use ($guardian, $attributes) {
            // Update users.email atomically when present (and not stripped by the request).
            if (array_key_exists('email', $attributes) && $attributes['email']) {
                $guardian->user?->update(['email' => $attributes['email']]);
            }
            unset($attributes['email']);

            $guardian->update(array_filter(
                $attributes,
                fn ($v) => ! is_null($v),
            ));

            return $guardian->fresh(['user', 'photoFile']);
        });
    }

    /**
     * Update only pivot fields between a student and a guardian. Returns the updated pivot row.
     *
     * Side-effects:
     *   - Setting is_primary=true on this pivot clears it on all other pivots for the student.
     *   - Flipping can_login false→true triggers login enablement (Scenarios 1, 2, 3 per spec).
     *   - Flipping can_login true→false triggers cascade-disable check across all of the
     *     guardian's pivots; the User account is disabled only if no pivot still has can_login=true.
     */
    public function updatePivot(Student $student, Guardian $guardian, array $changes): object
    {
        return DB::transaction(function () use ($student, $guardian, $changes) {
            $existing = DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $guardian->id)
                ->first();

            if (! $existing) {
                throw ValidationException::withMessages([
                    'guardian_id' => 'This guardian is not attached to the specified student.',
                ]);
            }

            $merged = [
                'relationship' => $changes['relationship'] ?? $existing->relationship,
                'is_primary' => array_key_exists('is_primary', $changes) ? (bool) $changes['is_primary'] : (bool) $existing->is_primary,
                'can_login' => array_key_exists('can_login', $changes) ? (bool) $changes['can_login'] : (bool) $existing->can_login,
            ];

            $this->assertLoginRequiresDeliverableEmail($guardian, $merged['can_login']);

            $student->guardians()->updateExistingPivot($guardian->id, $merged);

            if ($merged['is_primary']) {
                DB::table('guardian_student')
                    ->where('student_id', $student->id)
                    ->where('guardian_id', '!=', $guardian->id)
                    ->update(['is_primary' => false]);
            }

            $oldCanLogin = (bool) $existing->can_login;
            $newCanLogin = $merged['can_login'];

            if (! $oldCanLogin && $newCanLogin) {
                $this->enableLogin($guardian, [$student->full_name]);
                $this->logPivotEvent($guardian, $student, 'login_enabled');
            } elseif ($oldCanLogin && ! $newCanLogin) {
                $this->cascadeDisableIfNoLoginPivots($guardian);
                $this->logPivotEvent($guardian, $student, 'login_disabled');
            } else {
                $this->logPivotEvent($guardian, $student, 'pivot_updated', $merged);
            }

            return DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $guardian->id)
                ->first();
        });
    }

    /**
     * Detach a guardian from a student. Guards:
     *   - The student must always retain at least one guardian.
     *   - If the detached row was primary, a replacement_primary_guardian_uuid must be supplied
     *     so the student keeps exactly one primary guardian.
     *
     * The guardian record is never deleted from here (it may belong to siblings). Orphans
     * are left for the admin guardian-management page to handle.
     */
    public function detachFromStudent(Student $student, Guardian $guardian, ?string $replacementPrimaryUuid = null): void
    {
        DB::transaction(function () use ($student, $guardian, $replacementPrimaryUuid) {
            $linkCount = $student->guardians()->count();
            if ($linkCount <= 1) {
                throw ValidationException::withMessages([
                    'guardian_id' => 'A student must have at least one guardian. Add another guardian before detaching this one.',
                ]);
            }

            $existing = DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $guardian->id)
                ->first();

            if (! $existing) {
                throw ValidationException::withMessages([
                    'guardian_id' => 'This guardian is not attached to the specified student.',
                ]);
            }

            if ((bool) $existing->is_primary) {
                if (! $replacementPrimaryUuid) {
                    throw ValidationException::withMessages([
                        'replacement_primary_guardian_uuid' => 'The guardian you are detaching is marked primary. Choose another linked guardian to promote first.',
                    ]);
                }

                $replacement = Guardian::where('uuid', $replacementPrimaryUuid)
                    ->where('school_id', $student->school_id)
                    ->first();

                if (! $replacement) {
                    throw ValidationException::withMessages([
                        'replacement_primary_guardian_uuid' => 'The replacement guardian could not be found.',
                    ]);
                }

                $replacementLinked = DB::table('guardian_student')
                    ->where('student_id', $student->id)
                    ->where('guardian_id', $replacement->id)
                    ->exists();

                if (! $replacementLinked) {
                    throw ValidationException::withMessages([
                        'replacement_primary_guardian_uuid' => 'The replacement guardian must already be linked to this student.',
                    ]);
                }

                $student->guardians()->updateExistingPivot($replacement->id, ['is_primary' => true]);
            }

            $student->guardians()->detach($guardian->id);

            // If can_login was true on this pivot, the guardian may now have zero login-grants
            // across all linked students — cascade-disable check.
            if ((bool) $existing->can_login) {
                $this->cascadeDisableIfNoLoginPivots($guardian);
            }

            $this->logPivotEvent($guardian, $student, 'detached');
        });
    }

    /**
     * Enable login for a guardian, handling all three scenarios from the spec:
     *  1. No User: create one + assign role + send credentials.
     *  2. User exists but is disabled (disabled_at not null): re-enable + regenerate + re-send.
     *  3. User exists and active: no-op.
     */
    public function enableLogin(Guardian $guardian, array $studentNames = []): void
    {
        $user = $guardian->user;

        // SCENARIO 1 DELETED (was: mint a synthetic email and create the account).
        //
        // It was guarded by `if (! $user)` and labelled "shouldn't happen with current
        // schema" — `guardians.user_id` is NOT NULL and production holds none. But it
        // MINTED A SYNTHETIC EMAIL FOR A LOGIN-ENABLED ACCOUNT, which is a direct,
        // executable contradiction of the invariant this method now upholds, parked in
        // a branch that reads as an intended fallback.
        //
        // An exception cannot live inside the method whose job is to have none. A dead
        // branch that would be wrong if it ran is worse than an absent one, because the
        // next reader takes it as the designed behaviour for that case. If a null
        // user_id ever becomes reachable, the correct response is to fail loudly here,
        // not to manufacture an account nobody can log into.
        if (! $user) {
            throw ValidationException::withMessages([
                'guardian_id' => 'This guardian has no user account, so login cannot be enabled.',
            ]);
        }

        // Scenario 2: user exists but disabled — re-enable + regenerate password + notify.
        if ($user->isDisabled()) {
            $plainPassword = $this->passwordGenerator->generate();
            $user->update([
                'disabled_at' => null,
                'password' => $plainPassword,
            ]);

            if (! $user->hasRole('guardian')) {
                $user->assignRole('guardian');
            }

            $this->notifyGuardian($user, $plainPassword, $studentNames);

            activity('guardian')
                ->performedOn($guardian)
                ->causedBy(auth()->user())
                ->event('login_enabled')
                ->log('Login re-enabled by admin');

            return;
        }

        // Scenario 3: already active — make sure the role is in place, then no-op.
        if (! $user->hasRole('guardian')) {
            $user->assignRole('guardian');
        }

        activity('guardian')
            ->performedOn($guardian)
            ->causedBy(auth()->user())
            ->event('login_enabled')
            ->log('Login enabled by admin');
    }

    /**
     * Explicitly disable a guardian's login access (admin-triggered, not pivot-cascaded).
     * Sets disabled_at on the User regardless of pivot state.
     */
    public function disableLogin(Guardian $guardian): void
    {
        $user = $guardian->user;

        if (! $user || $user->isDisabled()) {
            return;
        }

        $user->update(['disabled_at' => now()]);

        activity('guardian')
            ->performedOn($guardian)
            ->causedBy(auth()->user())
            ->event('login_disabled')
            ->log('Login disabled by admin');
    }

    /**
     * Re-send the initial invitation to a guardian whose account has never been activated.
     * Generates a fresh password and re-queues the notification.
     *
     * @throws ValidationException if the guardian has already activated their account.
     */
    public function resendInvitation(Guardian $guardian, array $studentNames = []): void
    {
        $user = $guardian->user;

        if (! $user || $user->email_verified_at !== null) {
            throw ValidationException::withMessages([
                'guardian_id' => 'Invitation can only be resent to guardians who have never activated their account.',
            ]);
        }

        $plainPassword = $this->passwordGenerator->generate();
        $user->update(['password' => $plainPassword]);

        $this->notifyGuardian($user, $plainPassword, $studentNames);

        activity('guardian')
            ->performedOn($guardian)
            ->causedBy(auth()->user())
            ->event('login_resent')
            ->log('Invitation resent by admin');
    }

    /**
     * Disable the guardian's User account only if no remaining pivot has can_login=true.
     */
    private function cascadeDisableIfNoLoginPivots(Guardian $guardian): void
    {
        $stillHasLogin = DB::table('guardian_student')
            ->where('guardian_id', $guardian->id)
            ->where('can_login', true)
            ->exists();

        if ($stillHasLogin || ! $guardian->user) {
            return;
        }

        $guardian->user->update(['disabled_at' => now()]);
    }

    /**
     * List all students currently attached to the given guardian (with pivot data).
     */
    public function studentsFor(Guardian $guardian)
    {
        return $guardian->students()->withPivot(['relationship', 'is_primary', 'can_login'])->get();
    }

    /**
     * The Guardian record for this User in the ACTIVE School, or null when the
     * User has no Guardian row there.
     *
     * `$user->guardian` cannot be used for this. It is an unordered `hasOne`, and
     * Guardian::applySchoolScope matches on `school_id = active OR user_id has
     * access to active` — so for a parent with a Guardian row in more than one
     * School (the per-School Guardian record, §6.2) the OR branch makes EVERY one
     * of their rows visible and the relation returns whichever the database
     * returns first. Both predicates are pinned explicitly here instead, and the
     * global scopes dropped, so the row is the active School's or there is none.
     *
     * `orderBy('id')` because nothing at the schema level enforces one Guardian
     * row per (user, school): `guardians` has only non-unique indexes on
     * `user_id` and `school_id`. resolveOrCreateGuardianForUserInSchool is the
     * one creation path that guards it, in code. Narrowing the candidate set
     * would silently inherit the same nondeterminism this method exists to
     * remove if a second row ever appeared, so the choice is stated, not left to
     * the database.
     */
    public function forUserInActiveSchool(User $user): ?Guardian
    {
        $schoolId = ActiveSchool::id();

        if (! $schoolId) {
            return null;
        }

        return Guardian::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('school_id', $schoolId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();
    }

    private function logPivotEvent(Guardian $guardian, Student $student, string $event, array $properties = []): void
    {
        activity('guardian')
            ->performedOn($guardian)
            ->causedBy(auth()->user())
            ->withProperties(array_merge([
                'student_id' => $student->id,
                'student_uuid' => $student->uuid,
            ], $properties))
            ->event($event)
            ->log("Guardian {$event} for student {$student->full_name}");
    }
}
