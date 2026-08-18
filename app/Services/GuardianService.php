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
use Illuminate\Support\Collection;
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

    /*
    |--------------------------------------------------------------------------
    | Merge — the remediation half of the duplicate-guardian defect
    |--------------------------------------------------------------------------
    |
    | createGuardianWithUser dedupes the USER by email and then calls
    | Guardian::create() unconditionally, so a second `guardians` row against the
    | same (user_id, school_id) is normal rather than exceptional — and with no
    | email at all, `User::where('email', null)->first()` never matches under
    | MySQL, so every email-less submission mints a fresh user AND a fresh
    | guardian. Nothing at the schema level forbids either: `guardians` carries
    | non-unique indexes on user_id and school_id and no unique key beyond uuid.
    |
    | This is the engine that collapses the rows that already exist. It does not
    | touch the creation path and it does not add the constraint; both are
    | separate changes, and the constraint cannot land until this has run.
    */

    /**
     * Collapse $absorbed into $keeper. Returns the same plan shape whether or not
     * $apply, so a dry run and an applied run print identically.
     *
     * NOTHING IS HARD-DELETED AND NO `users` ROW IS TOUCHED. `guardians.user_id`
     * is NOT NULL with cascadeOnDelete, so deleting a user hard-deletes that
     * person's guardian records in EVERY OTHER SCHOOL. Absorbed guardians are
     * soft-deleted; a user left backing no live guardian anywhere is reported and
     * not acted on, because "this account should go" is a decision, not a
     * consequence of a merge.
     *
     * Every query here drops the global scopes and pins `school_id` and
     * `deleted_at` explicitly. Guardian::applySchoolScope matches
     * `school_id = active OR user_id has access to active`, so under the default
     * scope a multi-school parent's rows from OTHER schools are visible — which
     * for a merge would be an isolation breach, not a convenience.
     *
     * @param  Collection<int, Guardian>  $absorbed
     * @return array<string, mixed>
     */
    public function merge(Guardian $keeper, Collection $absorbed, bool $apply, bool $consolidateLogin = false): array
    {
        /** @var Collection<int, Guardian> $absorbed */
        $absorbed = $absorbed->unique('id')->values();

        // Carried OUT of the transaction so the parent is emailed only once the
        // merge has actually committed. StudentController::store uses the same
        // shape (a `&$deferredNotifications` array filled inside the closure and
        // drained after it) for the same reason: a rollback must never leave an
        // email in flight, and a password nobody can be told is worse than none.
        $deferred = null;

        $plan = DB::transaction(function () use ($keeper, $absorbed, $apply, $consolidateLogin, &$deferred) {
            $this->assertMergeable($keeper, $absorbed);

            // ORDERED BEFORE THE PLANNER, not merely before the apply. A refusal
            // that runs after the pivots have been classified is still correct,
            // but it invites the next reader to move it one line further down.
            $decision = $this->planLoginDecision($keeper, $absorbed, $consolidateLogin);
            $this->assertLoginDecisionAllowed($keeper, $decision);

            $plan = $this->buildMergePlan($keeper, $absorbed);
            $plan['login_decision'] = $decision;

            if ($apply) {
                $this->applyMergePlan($keeper, $absorbed, $plan);
                $deferred = $this->applyLoginConsolidation($keeper, $absorbed, $decision);
            }

            return array_merge(['applied' => $apply], $plan);
        });

        if ($deferred !== null) {
            $this->notifyGuardian($deferred['user'], $deferred['plain_password'], $deferred['student_names']);
        }

        return $plan;
    }

    /**
     * Refusals. All of them run before anything is written.
     *
     * The cross-school one is the load-bearing check: the same-school triggers
     * `guardian_student_same_school_bi`/`_bu` SIGNAL SQLSTATE '45000' mid-write,
     * which surfaces as a 500 with no useful message rather than a refusal.
     *
     * @param  Collection<int, Guardian>  $absorbed
     */
    private function assertMergeable(Guardian $keeper, Collection $absorbed): void
    {
        if ($absorbed->isEmpty()) {
            throw ValidationException::withMessages([
                'absorb' => 'Nothing to merge: no absorbed guardians were given.',
            ]);
        }

        if ($keeper->deleted_at !== null) {
            throw ValidationException::withMessages([
                'keep' => "Merge aborted: guardian#{$keeper->id} is already deleted.",
            ]);
        }

        // THE KEEPER MUST BE IN THE ACTIVE SCHOOL, not merely in the same school
        // as the rows it absorbs. Those are different claims and only the second
        // one was being made: two guardians could match each other perfectly and
        // both belong to a school the caller has no context for.
        //
        // It is not hypothetical. Guardian::applySchoolScope matches
        // `school_id = active OR user_id has access to active`, so a route-model
        // -bound Guardian from ANOTHER school resolves inside a request scoped to
        // this one — which is precisely the defect every query in this method
        // drops the global scopes to avoid. The console command takes its context
        // FROM the keeper, so this is a tautology there and a real boundary for
        // the next caller. A docblock promising the admin UI will behave is a
        // wish; this is the mechanism.
        //
        // Read through ActiveSchool::id() rather than getOrFail(): getOrFail
        // raises an HTTP 403 abort, which is a sensible response on a request and
        // a useless one in a console. Absent context is refused here explicitly,
        // with the same shape as every other refusal here, so the command exits
        // non-zero with a message a human can act on.
        $activeSchoolId = (int) ActiveSchool::id();

        if ($activeSchoolId === 0) {
            throw ValidationException::withMessages([
                'keep' => 'Merge aborted: no active school context. Off-request callers must wrap the '
                    .'merge in ActiveSchool::runFor() using the keeper guardian\'s own school_id.',
            ]);
        }

        if ((int) $keeper->school_id !== $activeSchoolId) {
            throw ValidationException::withMessages([
                'keep' => "Merge aborted: keeper guardian#{$keeper->id} belongs to school#{$keeper->school_id} "
                    ."but the active context is school#{$activeSchoolId}.",
            ]);
        }

        foreach ($absorbed as $guardian) {
            if ((int) $guardian->id === (int) $keeper->id) {
                throw ValidationException::withMessages([
                    'absorb' => "Merge aborted: guardian#{$keeper->id} cannot absorb itself.",
                ]);
            }

            if ((int) $guardian->school_id !== (int) $keeper->school_id) {
                throw ValidationException::withMessages([
                    'absorb' => "Merge aborted: guardian#{$guardian->id} belongs to school#{$guardian->school_id} "
                        ."but keeper guardian#{$keeper->id} belongs to school#{$keeper->school_id}. "
                        .'A guardian record is per-school; merging across schools is not a merge.',
                ]);
            }

            if ($guardian->deleted_at !== null) {
                throw ValidationException::withMessages([
                    'absorb' => "Merge aborted: guardian#{$guardian->id} is already deleted.",
                ]);
            }
        }
    }

    /**
     * WHO CAN SIGN IN, AND WHAT THE MERGE IS ABOUT TO DO ABOUT IT.
     *
     * ⚠️ THE PREDICATE IS NOT `can_login`. That was the first version of this
     * guard and it was keyed on the wrong signal — which is the more useful fact
     * here than the fix. Authentication never reads the pivot: Fortify resolves
     * `User::where('email', …)`, then checks `isDisabled()` and the password hash
     * (FortifyServiceProvider). `disableLogin` writes `users.disabled_at` and
     * never touches the pivot; `enableLogin` clears it and re-issues a password,
     * also never touching the pivot. `can_login` governs what the ADMIN UI offers
     * and what the invariant test pins; it does not govern who gets in.
     *
     * Keyed on the flag, this guard refused 1 of the 14 duplicate groups in the
     * production copy and waved 13 through — every one of which would have left
     * an enabled, deliverable account backing a soft-deleted guardian row. The
     * parent signs in, `forUserInActiveSchool` finds nothing, and
     * GuardianController::wards answers 200 with an empty list. Portal blank, no
     * email, no error.
     *
     * SO THE QUESTION IS "CAN THIS ACCOUNT AUTHENTICATE", derived from what
     * Fortify actually checks — `isDisabled()` (read through the model, not by
     * inlining `disabled_at`) and a password being set — and asked of every
     * absorbed record whose user is not the keeper's. Same `user_id` on both
     * sides is the certain duplicate: one human, one account, nothing to
     * consolidate.
     *
     * @param  Collection<int, Guardian>  $absorbed
     * @return array<string, mixed>
     */
    private function planLoginDecision(Guardian $keeper, Collection $absorbed, bool $consolidateLogin): array
    {
        $keeperUser = $keeper->user;

        $donors = [];

        foreach ($absorbed as $guardian) {
            $user = $guardian->user;
            $sameAccount = (int) $guardian->user_id === (int) $keeper->user_id;

            $canAuthenticate = ! $sameAccount
                && $user !== null
                && ! $user->isDisabled()
                && trim((string) $user->password) !== '';

            $donors[] = [
                'guardian_id' => (int) $guardian->id,
                'user_id' => (int) $guardian->user_id,
                'same_user_as_keeper' => $sameAccount,
                'can_authenticate' => $canAuthenticate,
                'deliverable' => $user?->hasDeliverableEmail() ?? false,
                'can_login_students' => DB::table('guardian_student')
                    ->where('guardian_id', $guardian->id)
                    ->where('can_login', true)
                    ->orderBy('student_id')
                    ->pluck('student_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
                // The action the merge will take on this account, printed in the
                // dry run. 'disable' is the only one that ends a login.
                'action' => $canAuthenticate ? ($consolidateLogin ? 'disable' : 'refuse') : 'none',
            ];
        }

        $crossAccountLogins = array_values(array_filter($donors, fn (array $d) => $d['can_authenticate']));

        return [
            'keeper_user_id' => (int) $keeper->user_id,
            'keeper_deliverable' => $keeperUser?->hasDeliverableEmail() ?? false,
            'keeper_disabled' => $keeperUser?->isDisabled() ?? false,
            'consolidate_requested' => $consolidateLogin,
            'consolidating' => $consolidateLogin && $crossAccountLogins !== [],
            'will_notify' => $consolidateLogin && $crossAccountLogins !== [],
            'donors' => $donors,
            'cross_account_login_guardian_ids' => array_column($crossAccountLogins, 'guardian_id'),
        ];
    }

    /**
     * The two refusals that hang off that decision.
     *
     * ONE. A donor account that can authenticate is not migrated silently. The
     * first design refused this outright and stopped there; keyed correctly that
     * refuses the entire working set and the command does nothing for the
     * population it was built for. So it is opt-in instead: `--consolidate-login`
     * is the operator saying "yes, end that account and tell the parent". Without
     * it the merge refuses and the message names the flag — and names ONLY the
     * flag, because the two remedies this message used to prescribe were both
     * wrong. "Disable it on the absorbed record" sets `users.disabled_at` and
     * leaves the pivot flag alone, so the old check refused identically on the
     * re-run and the parent was locked out for nothing; "enable it on the keeper"
     * changed nothing the check read at all.
     *
     * TWO. Consolidation into an account the parent cannot be told about is the
     * original defect wearing a flag: the donor's password stops working and
     * nobody can be sent the replacement. A disabled keeper account is fine —
     * consolidation re-enables it — but an undeliverable one is refused, because
     * the notification is the whole reason the consolidation is safe.
     *
     * @param  array<string, mixed>  $decision
     */
    private function assertLoginDecisionAllowed(Guardian $keeper, array $decision): void
    {
        if ($decision['cross_account_login_guardian_ids'] === []) {
            return;
        }

        $named = implode(', ', array_map(
            fn (array $d) => "guardian#{$d['guardian_id']} on user#{$d['user_id']}",
            array_values(array_filter($decision['donors'], fn (array $d) => $d['can_authenticate'])),
        ));

        if (! $decision['consolidate_requested']) {
            throw ValidationException::withMessages([
                'absorb' => "Merge aborted: {$named} can sign in today, and the keeper is on "
                    ."user#{$decision['keeper_user_id']}. Absorbing the record does not move the login — the "
                    .'password, the sign-in address and the reset link all live on the users row — so that '
                    .'account would keep working and show an empty portal. Re-run with --consolidate-login to '
                    .'disable it and issue the parent fresh credentials for the keeper account.',
            ]);
        }

        if (! $decision['keeper_deliverable']) {
            throw ValidationException::withMessages([
                'keep' => "Merge aborted: --consolidate-login would end the login on {$named}, but keeper "
                    ."guardian#{$keeper->id} is on user#{$decision['keeper_user_id']}, which has no deliverable "
                    .'email address — so the parent could not be sent credentials for the account that '
                    .'survives. Give the keeper a real email address first.',
            ]);
        }
    }

    /**
     * Execute the consolidation, and hand the notification BACK to the caller
     * rather than sending it here.
     *
     * The donor is ended through `disableLogin`, and the keeper is re-enabled and
     * re-credentialled with the same `login_enabled` event every other transition
     * uses — the trail must read like a login transition, because that is what it
     * is. Nothing new is invented for the merge's benefit.
     *
     * The password IS written here, inside the transaction: it is state, and it
     * must roll back with everything else. Only the email is deferred.
     *
     * @param  Collection<int, Guardian>  $absorbed
     * @param  array<string, mixed>  $decision
     * @return array{user: User, plain_password: string, student_names: array<int, string>}|null
     */
    private function applyLoginConsolidation(Guardian $keeper, Collection $absorbed, array $decision): ?array
    {
        if (! $decision['consolidating']) {
            return null;
        }

        $ending = $decision['cross_account_login_guardian_ids'];

        foreach ($absorbed as $guardian) {
            if (in_array((int) $guardian->id, $ending, true)) {
                // Writes users.disabled_at and logs `login_disabled` on the record
                // that is going away — where an auditor asking "when did this
                // account stop working" would look.
                $this->disableLogin($guardian);
            }
        }

        $user = $keeper->user;

        if (! $user) {
            throw ValidationException::withMessages([
                'keep' => "Merge aborted: keeper guardian#{$keeper->id} has no user account to consolidate into.",
            ]);
        }

        $plainPassword = $this->passwordGenerator->generate();

        $user->update([
            'disabled_at' => null,
            'password' => $plainPassword,
        ]);

        if (! $user->hasRole('guardian')) {
            $user->assignRole('guardian');
        }

        activity('guardian')
            ->performedOn($keeper)
            ->causedBy(auth()->user())
            ->withProperties([
                'via' => 'merge',
                'consolidated_from_user_ids' => array_values(array_map(
                    fn (array $d) => $d['user_id'],
                    array_filter($decision['donors'], fn (array $d) => $d['can_authenticate']),
                )),
            ])
            ->event('login_enabled')
            ->log('Login consolidated onto this guardian by merge');

        return [
            'user' => $user,
            'plain_password' => $plainPassword,
            'student_names' => $keeper->students()->get()->map(fn (Student $s) => $s->full_name)->all(),
        ];
    }

    /**
     * The plan, computed by simulation against the current rows — no writes.
     *
     * The simulation is sequential and order-dependent on purpose: when two
     * absorbed guardians both link the same student, the first is a move (the
     * keeper gains the row) and the second is therefore a collision. Computing
     * both against the ORIGINAL keeper rows would classify the second as a move
     * too, and the apply would then hit `unique(guardian_id, student_id)`.
     *
     * @param  Collection<int, Guardian>  $absorbed
     * @return array<string, mixed>
     */
    private function buildMergePlan(Guardian $keeper, Collection $absorbed): array
    {
        $absorbedIds = $absorbed->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Final state of the keeper's pivot per student, seeded from what it holds today.
        $state = [];
        foreach (DB::table('guardian_student')->where('guardian_id', $keeper->id)->get() as $row) {
            $state[(int) $row->student_id] = [
                'is_primary' => (bool) $row->is_primary,
                'can_login' => (bool) $row->can_login,
                'touched' => false,
            ];
        }

        $moves = [];
        $collisions = [];

        foreach ($absorbed as $guardian) {
            $rows = DB::table('guardian_student')
                ->where('guardian_id', $guardian->id)
                ->orderBy('student_id')
                ->get();

            foreach ($rows as $row) {
                $studentId = (int) $row->student_id;
                $isPrimary = (bool) $row->is_primary;
                $canLogin = (bool) $row->can_login;

                if (! isset($state[$studentId])) {
                    // THE MOVE. No keeper row for this student, so the pivot is
                    // re-pointed and keeps its own relationship/is_primary/can_login.
                    if ($canLogin) {
                        $this->assertMergedLoginIsDeliverable($keeper, $guardian, $studentId);
                    }

                    $moves[] = [
                        'pivot_id' => (int) $row->id,
                        'student_id' => $studentId,
                        'from_guardian_id' => (int) $guardian->id,
                        'relationship' => (string) $row->relationship,
                        'is_primary' => $isPrimary,
                        'can_login' => $canLogin,
                    ];

                    $state[$studentId] = [
                        'is_primary' => $isPrimary,
                        'can_login' => $canLogin,
                        'touched' => true,
                    ];

                    continue;
                }

                // THE COLLISION. Both are linked to this student and the pivot is
                // unique on (guardian_id, student_id), so a blind re-point raises a
                // duplicate key. The keeper's row survives — with its own
                // relationship — and the two booleans are OR-merged into it.
                $before = $state[$studentId];
                $after = [
                    'is_primary' => $before['is_primary'] || $isPrimary,
                    'can_login' => $before['can_login'] || $canLogin,
                    'touched' => true,
                ];

                // ON EVERY WRITE THAT LEAVES THE FLAG TRUE, not only on the
                // false→true raise. The narrower condition skipped the case where
                // the keeper is undeliverable, its own row already carries the
                // flag, and the absorbed side holds the login that actually works
                // — the merge would then keep the dead flag and delete the live
                // one. The cross-account pre-flight now refuses that case before
                // this line is reached, which is the point: the narrow condition
                // must not be the thing relied on.
                if ($after['can_login']) {
                    $this->assertMergedLoginIsDeliverable($keeper, $guardian, $studentId);
                }

                $collisions[] = [
                    'pivot_id' => (int) $row->id,
                    'student_id' => $studentId,
                    'from_guardian_id' => (int) $guardian->id,
                    'is_primary_before' => $before['is_primary'],
                    'is_primary_after' => $after['is_primary'],
                    'can_login_before' => $before['can_login'],
                    'can_login_after' => $after['can_login'],
                    // The ABSORBED row's own values, which the *_before pair does
                    // not carry — that pair is the keeper's state. These are what
                    // the deleted row held, and once it is gone this entry is the
                    // only record of it.
                    'absorbed_is_primary' => $isPrimary,
                    'absorbed_can_login' => $canLogin,
                    'absorbed_relationship' => (string) $row->relationship,
                    'resolution' => "keeper row kept (relationship unchanged); absorbed pivot#{$row->id} deleted",
                ];

                $state[$studentId] = $after;
            }
        }

        // Single-primary is enforced in code only, so a move or an OR-merge that
        // leaves the keeper primary has to demote the student's OTHER guardians.
        // Absorbed rows are excluded: they are the rows being moved or deleted.
        $primaryDemotions = [];
        foreach ($state as $studentId => $final) {
            if (! $final['touched'] || ! $final['is_primary']) {
                continue;
            }

            $others = DB::table('guardian_student')
                ->where('student_id', $studentId)
                ->where('is_primary', true)
                ->where('guardian_id', '!=', $keeper->id)
                ->whereNotIn('guardian_id', $absorbedIds)
                ->pluck('guardian_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($others !== []) {
                $primaryDemotions[] = ['student_id' => $studentId, 'guardian_ids' => $others];
            }
        }

        [$backfillValues, $backfilled] = $this->planBackfill($keeper, $absorbed);

        return [
            'keeper_id' => (int) $keeper->id,
            'keeper_user_id' => (int) $keeper->user_id,
            'school_id' => (int) $keeper->school_id,
            'absorbed_ids' => $absorbedIds,
            'pivot_moves' => $moves,
            'pivot_collisions' => $collisions,
            'pivot_final_state' => array_map(
                fn (array $final) => ['is_primary' => $final['is_primary'], 'can_login' => $final['can_login']],
                array_filter($state, fn (array $final) => $final['touched']),
            ),
            'primary_demotions' => $primaryDemotions,
            'backfill_values' => $backfillValues,
            'backfilled' => $backfilled,
            'soft_deleted_ids' => $absorbedIds,
            'orphaned_user_ids' => $this->orphanedUserIdsAfterMerge($absorbed, $absorbedIds),
        ];
    }

    /**
     * `can_login = true` may not arrive on a keeper who cannot receive mail.
     *
     * Routed through the existing single enforcement point rather than a second
     * copy of the predicate; the message is re-raised with the ids so an operator
     * reading a console table knows which link caused the refusal. Aborting is
     * deliberate: silently downgrading the flag would remove a parent's portal
     * access as a side effect of a cleanup, and silently proceeding would mint
     * exactly the state GuardianLoginInvariantTest pins as unreachable.
     *
     * IT FIRES ON EVERY WRITE THAT LEAVES THE FLAG TRUE, not only on the ones
     * that raise it. Firing only on the raise looked equivalent and was not: it
     * skipped the collision where the keeper is undeliverable, its own row
     * already carries the flag, and the absorbed row holds the login that
     * actually works — the merge kept the dead flag and deleted the live one. The
     * lesson is not about that one condition. A guard scoped to "did this change
     * make it worse" cannot see a change that makes a bad state permanent.
     */
    private function assertMergedLoginIsDeliverable(Guardian $keeper, Guardian $from, int $studentId): void
    {
        try {
            $this->assertLoginRequiresDeliverableEmail($keeper, true);
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'can_login' => "Merge aborted: guardian#{$from->id} has login enabled for student#{$studentId}, "
                    ."but keeper guardian#{$keeper->id} has no deliverable email address, so that access cannot move. "
                    .'Give the keeper a real email address first, or clear can_login on the absorbed link.',
            ]);
        }
    }

    /**
     * Back-fill BLANKS ONLY — the keeper's own values always win.
     *
     * The field list is read off the keeper's own $fillable rather than restated
     * here, so a column added to the model is covered without a second list to
     * drift. school_id and user_id are identity (copying either would move the
     * record); status is a decision an operator made about the keeper.
     *
     * @param  Collection<int, Guardian>  $absorbed
     * @return array{0: array<string, mixed>, 1: list<array{field: string, from_guardian_id: int}>}
     */
    private function planBackfill(Guardian $keeper, Collection $absorbed): array
    {
        $fields = array_diff($keeper->getFillable(), ['school_id', 'user_id', 'uuid', 'status']);

        $values = [];
        $taken = [];

        foreach ($fields as $field) {
            $current = $keeper->getAttribute($field);

            if (! ($current === null || $current === '')) {
                continue;
            }

            foreach ($absorbed as $guardian) {
                $candidate = $guardian->getAttribute($field);

                if ($candidate === null || $candidate === '') {
                    continue;
                }

                $values[$field] = $candidate;
                $taken[] = ['field' => $field, 'from_guardian_id' => (int) $guardian->id];

                break;
            }
        }

        return [$values, $taken];
    }

    /**
     * Absorbed users left backing no live guardian in ANY school once the merge
     * lands. Reported, never acted on — see the merge() docblock.
     *
     * @param  Collection<int, Guardian>  $absorbed
     * @param  list<int>  $absorbedIds
     * @return list<int>
     */
    private function orphanedUserIdsAfterMerge(Collection $absorbed, array $absorbedIds): array
    {
        $orphans = [];

        foreach ($absorbed->pluck('user_id')->unique() as $userId) {
            $remaining = Guardian::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('user_id', $userId)
                ->whereNotIn('id', $absorbedIds)
                ->count();

            if ($remaining === 0) {
                $orphans[] = (int) $userId;
            }
        }

        return $orphans;
    }

    /**
     * @param  Collection<int, Guardian>  $absorbed
     * @param  array<string, mixed>  $plan
     */
    private function applyMergePlan(Guardian $keeper, Collection $absorbed, array $plan): void
    {
        $now = now();

        foreach ($plan['pivot_moves'] as $move) {
            DB::table('guardian_student')
                ->where('id', $move['pivot_id'])
                ->update(['guardian_id' => $keeper->id, 'updated_at' => $now]);
        }

        foreach ($plan['pivot_collisions'] as $collision) {
            DB::table('guardian_student')->where('id', $collision['pivot_id'])->delete();
        }

        // Written from the SIMULATED FINAL state rather than per-step, so a student
        // touched by two absorbed rows lands on one value instead of the last one.
        foreach ($plan['pivot_final_state'] as $studentId => $final) {
            DB::table('guardian_student')
                ->where('guardian_id', $keeper->id)
                ->where('student_id', $studentId)
                ->update([
                    'is_primary' => $final['is_primary'],
                    'can_login' => $final['can_login'],
                    'updated_at' => $now,
                ]);
        }

        foreach ($plan['primary_demotions'] as $demotion) {
            DB::table('guardian_student')
                ->where('student_id', $demotion['student_id'])
                ->whereIn('guardian_id', $demotion['guardian_ids'])
                ->update(['is_primary' => false, 'updated_at' => $now]);
        }

        if ($plan['backfill_values'] !== []) {
            $keeper->fill($plan['backfill_values'])->save();
        }

        foreach ($absorbed as $guardian) {
            $guardian->delete();
        }

        $this->logMergedLinks($keeper, $absorbed, $plan);

        activity('guardian')
            ->performedOn($keeper)
            ->causedBy(auth()->user())
            ->withProperties([
                'absorbed_guardian_ids' => $plan['absorbed_ids'],
                'school_id' => $plan['school_id'],
                'pivots_moved' => count($plan['pivot_moves']),
                'pivot_collisions' => count($plan['pivot_collisions']),
                // THE IDS, NOT ONLY THE COUNTS. A colliding pivot row is HARD
                // deleted and is not recoverable afterwards, so a count is the
                // point at which "which child did this adult stop being able to
                // see, and when" stops having an answer.
                'moved_student_ids' => array_column($plan['pivot_moves'], 'student_id'),
                'collision_student_ids' => array_column($plan['pivot_collisions'], 'student_id'),
                'backfilled_fields' => array_column($plan['backfilled'], 'field'),
                'orphaned_user_ids' => $plan['orphaned_user_ids'],
            ])
            ->event('merged')
            ->log('Guardian merged: '.count($plan['absorbed_ids']).' record(s) absorbed');
    }

    /**
     * Per-LINK audit entries, in the same vocabulary every other pivot writer
     * uses.
     *
     * attachToStudent's own comment records why this is not optional: the attach
     * side was the one unlogged pivot transition, so "who gave this adult access
     * to this child, and when" had no answer while "who took it away" did. A merge
     * that emitted one summary entry on the keeper would reopen exactly that hole
     * — worse, in fact, because the colliding rows it deletes are gone and cannot
     * be re-derived from anything.
     *
     * `attached` is performed on the KEEPER (it gained the link); `detached` on the
     * ABSORBED record (it lost it), which is where an auditor looking at that
     * guardian's trail would go.
     *
     * @param  Collection<int, Guardian>  $absorbed
     * @param  array<string, mixed>  $plan
     */
    private function logMergedLinks(Guardian $keeper, Collection $absorbed, array $plan): void
    {
        $absorbedById = $absorbed->keyBy('id');

        $studentIds = array_unique(array_merge(
            array_column($plan['pivot_moves'], 'student_id'),
            array_column($plan['pivot_collisions'], 'student_id'),
        ));

        if ($studentIds === []) {
            return;
        }

        $students = Student::withoutGlobalScopes()->whereIn('id', $studentIds)->get()->keyBy('id');

        foreach ($plan['pivot_moves'] as $move) {
            $student = $students->get($move['student_id']);

            if (! $student) {
                continue;
            }

            $this->logPivotEvent($keeper, $student, 'attached', [
                'relationship' => $move['relationship'],
                'is_primary' => $move['is_primary'],
                'can_login' => $move['can_login'],
                'via' => 'merge',
                'from_guardian_id' => $move['from_guardian_id'],
            ]);
        }

        foreach ($plan['pivot_collisions'] as $collision) {
            $student = $students->get($collision['student_id']);
            $from = $absorbedById->get($collision['from_guardian_id']);

            if (! $student || ! $from) {
                continue;
            }

            $this->logPivotEvent($from, $student, 'detached', [
                'via' => 'merge',
                'merged_into_guardian_id' => (int) $keeper->id,
                'relationship' => $collision['absorbed_relationship'],
                'is_primary' => $collision['absorbed_is_primary'],
                'can_login' => $collision['absorbed_can_login'],
            ]);
        }
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
