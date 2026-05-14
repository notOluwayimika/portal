<?php

namespace App\Services;

use App\DTOs\GuardianDto;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Notifications\GuardianAccountCreatedNotification;
use App\Repositories\GuardianRepository;
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
        return Guardian::query()
            ->when($request->search, function ($q) use ($request) {
                $term = '%' . $request->search . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('first_name', 'LIKE', $term)
                          ->orWhere('last_name', 'LIKE', $term)
                          ->orWhere('phone', 'LIKE', $term);
                });
            })
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->with(['photoFile', 'user'])
            ->latest()
            ->paginate($request->integer('per_page', 25));
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

    /**
     * Resolve a guardian to attach for Case B (existing). Accepts either uuid or identifier.
     * Throws ValidationException if not found in the given school.
     */
    public function resolveExistingGuardian(array $entry, int $schoolId): Guardian
    {
        $guardian = null;

        if (!empty($entry['guardian_id'])) {
            $guardian = $this->guardianRepository->findByUuidInSchool($entry['guardian_id'], $schoolId);
        } elseif (!empty($entry['identifier'])) {
            $guardian = $this->guardianRepository->findByIdentifierInSchool($entry['identifier'], $schoolId);
        }

        if (!$guardian) {
            throw ValidationException::withMessages([
                'guardians' => 'An existing guardian could not be found for the provided identifier in this school.',
            ]);
        }

        return $guardian;
    }

    /**
     * Case A: create a brand-new User + Guardian + assign `parent` role.
     * Wrapped in a DB::transaction. The notification is queued AFTER the transaction
     * commits so that a rollback never leaves a stranded email in flight.
     *
     * @return array{guardian: Guardian, user: User, plain_password: ?string}
     */
    public function createGuardianWithUser(array $attributes, int $schoolId, bool $canLogin, ?string $email): array
    {
        $plainPassword = $this->passwordGenerator->generate();

        return DB::transaction(function () use ($attributes, $schoolId, $canLogin, $email, $plainPassword) {
            $userEmail = $email ?: $this->syntheticEmail($schoolId);

            $user = User::create([
                'first_name' => $attributes['first_name'],
                'last_name'  => $attributes['last_name'],
                'email'      => $userEmail,
                'school_id'  => $schoolId,
                'password'   => $plainPassword,
            ]);

            $user->assignRole('parent');

            $guardian = $user->guardian()->create(array_merge($attributes, [
                'school_id' => $schoolId,
                'user_id'   => $user->id,
                'status'    => $attributes['status'] ?? 'active',
            ]));

            return [
                'guardian'       => $guardian->fresh(['user', 'photoFile']),
                'user'           => $user,
                'plain_password' => $canLogin && $email ? $plainPassword : null,
            ];
        });
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
        $existingPivot = DB::table('guardian_student')
            ->where('guardian_id', $guardian->id)
            ->where('student_id', $student->id)
            ->first();

        $student->guardians()->syncWithoutDetaching([
            $guardian->id => [
                'relationship' => $relationship,
                'is_primary'   => $isPrimary,
                'can_login'    => $canLogin,
            ],
        ]);

        // Already attached — just update pivot fields if anything changed.
        if ($existingPivot) {
            $student->guardians()->updateExistingPivot($guardian->id, [
                'relationship' => $relationship,
                'is_primary'   => $isPrimary,
                'can_login'    => $canLogin,
            ]);

            // can_login was upgraded from false → true for an existing link — re-issue creds.
            if (!$existingPivot->can_login && $canLogin) {
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
        if (!$user->email || $this->isSyntheticEmail($user->email)) {
            return;
        }

        try {
            $schoolName = $user->school?->name ?? config('app.name');
            $user->notify(new GuardianAccountCreatedNotification(
                plainPassword: $plainPassword,
                schoolName:    $schoolName,
                loginUrl:      url('/login'),
                studentNames:  $studentNames,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send guardian account notification', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function reissueCredentialsIfPossible(Guardian $guardian, Student $student): void
    {
        $user = $guardian->user;
        if (!$user || !$user->email || $this->isSyntheticEmail($user->email)) {
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
                fn($v) => !is_null($v),
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

            if (!$existing) {
                throw ValidationException::withMessages([
                    'guardian_id' => 'This guardian is not attached to the specified student.',
                ]);
            }

            $merged = [
                'relationship' => $changes['relationship'] ?? $existing->relationship,
                'is_primary'   => array_key_exists('is_primary', $changes) ? (bool) $changes['is_primary'] : (bool) $existing->is_primary,
                'can_login'    => array_key_exists('can_login', $changes) ? (bool) $changes['can_login'] : (bool) $existing->can_login,
            ];

            $student->guardians()->updateExistingPivot($guardian->id, $merged);

            if ($merged['is_primary']) {
                DB::table('guardian_student')
                    ->where('student_id', $student->id)
                    ->where('guardian_id', '!=', $guardian->id)
                    ->update(['is_primary' => false]);
            }

            $oldCanLogin = (bool) $existing->can_login;
            $newCanLogin = $merged['can_login'];

            if (!$oldCanLogin && $newCanLogin) {
                $this->enableLogin($guardian, [$student->full_name]);
                $this->logPivotEvent($guardian, $student, 'login_enabled');
            } elseif ($oldCanLogin && !$newCanLogin) {
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

            if (!$existing) {
                throw ValidationException::withMessages([
                    'guardian_id' => 'This guardian is not attached to the specified student.',
                ]);
            }

            if ((bool) $existing->is_primary) {
                if (!$replacementPrimaryUuid) {
                    throw ValidationException::withMessages([
                        'replacement_primary_guardian_uuid' =>
                            'The guardian you are detaching is marked primary. Choose another linked guardian to promote first.',
                    ]);
                }

                $replacement = Guardian::where('uuid', $replacementPrimaryUuid)
                    ->where('school_id', $student->school_id)
                    ->first();

                if (!$replacement) {
                    throw ValidationException::withMessages([
                        'replacement_primary_guardian_uuid' => 'The replacement guardian could not be found.',
                    ]);
                }

                $replacementLinked = DB::table('guardian_student')
                    ->where('student_id', $student->id)
                    ->where('guardian_id', $replacement->id)
                    ->exists();

                if (!$replacementLinked) {
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

        // Scenario 1: shouldn't happen with current schema (user_id is NOT NULL),
        // but guard anyway in case of future changes.
        if (!$user) {
            // No user exists — we need an email to create one. The caller (controller)
            // should have validated that an identifier is available. Fall back to phone
            // synthetic email so the row can be created; the notifier will skip delivery.
            $email = $guardian->phone ? "{$guardian->phone}@no-email.local" : sprintf('guardian+%s@no-email.local', Str::random(12));
            $plainPassword = $this->passwordGenerator->generate();
            $newUser = User::create([
                'first_name' => $guardian->first_name,
                'last_name'  => $guardian->last_name,
                'email'      => $email,
                'school_id'  => $guardian->school_id,
                'password'   => $plainPassword,
            ]);
            $newUser->assignRole('parent');
            $guardian->update(['user_id' => $newUser->id]);
            $this->notifyGuardian($newUser, $plainPassword, $studentNames);
            return;
        }

        // Scenario 2: user exists but disabled — re-enable + regenerate password + notify.
        if ($user->isDisabled()) {
            $plainPassword = $this->passwordGenerator->generate();
            $user->update([
                'disabled_at' => null,
                'password'    => $plainPassword,
            ]);

            // Make sure the role is still attached for the current team.
            if (!$user->hasRole('parent')) {
                $user->assignRole('parent');
            }

            $this->notifyGuardian($user, $plainPassword, $studentNames);
            return;
        }

        // Scenario 3: already active — make sure the role is in place, then no-op.
        if (!$user->hasRole('parent')) {
            $user->assignRole('parent');
        }
    }

    /**
     * Explicitly disable a guardian's login access (admin-triggered, not pivot-cascaded).
     * Sets disabled_at on the User regardless of pivot state.
     */
    public function disableLogin(Guardian $guardian): void
    {
        $user = $guardian->user;

        if (!$user || $user->isDisabled()) {
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

        if (!$user || $user->email_verified_at !== null) {
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

        if ($stillHasLogin || !$guardian->user) {
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

    private function logPivotEvent(Guardian $guardian, Student $student, string $event, array $properties = []): void
    {
        activity('guardian')
            ->performedOn($guardian)
            ->causedBy(auth()->user())
            ->withProperties(array_merge([
                'student_id'   => $student->id,
                'student_uuid' => $student->uuid,
            ], $properties))
            ->event($event)
            ->log("Guardian {$event} for student {$student->full_name}");
    }

    /**
     * Build a synthetic email when an admin creates a guardian without can_login (no email provided).
     * Required because users.email is unique-not-null. The local part is unguessable and the domain
     * is not deliverable.
     */
    private function syntheticEmail(int $schoolId): string
    {
        return sprintf('guardian+%s+%d@no-email.local', Str::random(12), $schoolId);
    }

    private function isSyntheticEmail(string $email): bool
    {
        return str_ends_with($email, '@no-email.local');
    }
}
