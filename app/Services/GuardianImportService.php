<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\Validators\GuardianImportRowValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates per-row processing for a guardian bulk import:
 *   1. validate, 2. locate student, 3. dedup, 4. create or attach, 5. primary enforcement.
 *
 * Reuses GuardianService for all creation/attachment/login work — never duplicates that logic.
 */
class GuardianImportService
{
    /**
     * Map of normalized identifier → Guardian to handle in-file duplicates
     * (e.g. one parent listed across multiple sibling rows).
     */
    private array $rowCache = [];

    /** Deferred notifications keyed so each fires only after its row's transaction commits. */
    private array $deferredNotifications = [];

    public function __construct(
        private GuardianImportRowValidator $validator,
        private GuardianService $guardianService,
    ) {}

    /**
     * Reset per-import in-file state. Call at the start of each import run.
     */
    public function reset(): void
    {
        $this->rowCache = [];
        $this->deferredNotifications = [];
    }

    /**
     * Process one row.
     *
     * @return array{status: 'success'|'failed'|'skipped', message: string, guardian_id: ?int}
     */
    public function processRow(array $row, int $schoolId, bool $updateExistingLinks): array
    {
        // Step 1: Validate.
        $result = $this->validator->validate($row);
        if (!empty($result['errors'])) {
            return $this->failed(implode(' ', $result['errors']));
        }
        $data = $result['normalized'];

        // Step 2: Locate student (soft-deleted = not found).
        $student = Student::query()
            ->where('school_id', $schoolId)
            ->where('admission_number', $data['admission_number'])
            ->first();

        if (!$student) {
            return $this->failed("Student with admission number {$data['admission_number']} not found.");
        }

        // Step 3: Check existing guardian — in-file cache first, then DB.
        $cached = $this->lookupRowCache($data['email'], $data['phone'], $data['whatsapp_number']);
        if ($cached) {
            return $this->attachExisting($cached, $student, $data, $schoolId, $updateExistingLinks);
        }

        try {
            $existing = $this->lookupExistingInDb($data['email'], $data['phone'], $data['whatsapp_number'], $schoolId);
        } catch (ImportConflictException $e) {
            return $this->failed($e->getMessage());
        }

        if ($existing) {
            $this->cacheRow($existing, $data);
            return $this->attachExisting($existing, $student, $data, $schoolId, $updateExistingLinks);
        }

        // Step 4a: Case A — create new.
        return $this->createAndAttach($student, $data, $schoolId);
    }

    /**
     * Flush deferred notifications collected across rows. Should be called by the caller
     * after each chunk / at the end of the import.
     */
    public function flushNotifications(): void
    {
        foreach ($this->deferredNotifications as $notify) {
            try {
                $this->guardianService->notifyGuardian(
                    $notify['user'],
                    $notify['plain_password'],
                    $notify['student_names'],
                );
            } catch (\Throwable $e) {
                Log::error('Guardian import: deferred notification failed', [
                    'user_id' => $notify['user']->id ?? null,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
        $this->deferredNotifications = [];
    }

    /**
     * Case A: create Guardian + User in a transaction, then attach + (optionally) enable login.
     */
    private function createAndAttach(Student $student, array $data, int $schoolId): array
    {
        try {
            $created = DB::transaction(function () use ($student, $data, $schoolId) {
                $attributes = $this->guardianAttributes($data);

                $result = $this->guardianService->createGuardianWithUser(
                    attributes: $attributes,
                    schoolId:   $schoolId,
                    canLogin:   $data['can_login'],
                    email:      $data['email'],
                );

                $this->guardianService->attachToStudent(
                    guardian:     $result['guardian'],
                    student:      $student,
                    relationship: $data['relationship'],
                    isPrimary:    $data['is_primary'],
                    canLogin:     $data['can_login'],
                );

                return $result;
            });
        } catch (\Throwable $e) {
            Log::error('Guardian import: create failed', ['error' => $e->getMessage()]);
            return $this->failed('Failed to create guardian: ' . $e->getMessage());
        }

        $this->cacheRow($created['guardian'], $data);

        // Defer login notification — only after the transaction has committed.
        if ($data['can_login'] && $created['plain_password']) {
            $this->deferredNotifications[] = [
                'user'           => $created['user'],
                'plain_password' => $created['plain_password'],
                'student_names'  => [$student->full_name],
            ];
        }

        $message = $data['can_login']
            ? 'Guardian created and login enabled.'
            : 'Guardian created and attached.';

        return $this->ok($message . $this->primaryWarning($student, $data['is_primary']), $created['guardian']->id);
    }

    /**
     * Case B: existing guardian. Either skip (already linked), update pivot, or attach.
     */
    private function attachExisting(
        Guardian $guardian,
        Student $student,
        array $data,
        int $schoolId,
        bool $updateExistingLinks
    ): array {
        $existingPivot = DB::table('guardian_student')
            ->where('guardian_id', $guardian->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existingPivot) {
            if (!$updateExistingLinks) {
                return $this->skipped('Already linked — skipped.', $guardian->id);
            }

            // Update pivot only — never touch guardian details from import (spec §4b.2).
            try {
                $this->guardianService->updatePivot($student, $guardian, [
                    'relationship' => $data['relationship'],
                    'is_primary'   => $data['is_primary'],
                    'can_login'    => $data['can_login'],
                ]);
            } catch (\Throwable $e) {
                return $this->failed('Failed to update existing link: ' . $e->getMessage());
            }

            return $this->ok('Existing link updated.' . $this->primaryWarning($student, $data['is_primary']), $guardian->id);
        }

        // Not linked — attach.
        try {
            DB::transaction(function () use ($guardian, $student, $data) {
                $this->guardianService->attachToStudent(
                    guardian:     $guardian,
                    student:      $student,
                    relationship: $data['relationship'],
                    isPrimary:    $data['is_primary'],
                    canLogin:     $data['can_login'],
                );

                // If row asks for login and guardian's user has no login (or is disabled), promote.
                if ($data['can_login']) {
                    $user = $guardian->user;
                    if (!$user || $user->isDisabled()) {
                        $this->guardianService->enableLogin($guardian, [$student->full_name]);
                    }
                }
            });
        } catch (\Throwable $e) {
            return $this->failed('Failed to attach guardian: ' . $e->getMessage());
        }

        $msg = $data['can_login']
            ? 'Existing guardian attached and login ensured.'
            : 'Existing guardian attached.';

        return $this->ok($msg . $this->primaryWarning($student, $data['is_primary']), $guardian->id);
    }

    /**
     * @throws ImportConflictException when email and phone match different guardians.
     */
    private function lookupExistingInDb(?string $email, ?string $phone, ?string $whatsapp, int $schoolId): ?Guardian
    {
        $byEmail = null;
        $byPhone = null;

        if ($email) {
            $byEmail = Guardian::query()
                ->where('school_id', $schoolId)
                ->whereHas('user', fn($q) => $q->where('email', $email))
                ->first();
        }

        if ($phone) {
            $byPhone = Guardian::query()
                ->where('school_id', $schoolId)
                ->where(function ($q) use ($phone) {
                    $q->where('phone', $phone)->orWhere('whatsapp_number', $phone);
                })
                ->first();
        }

        // Whatsapp fallback only if phone didn't match anything.
        if (!$byPhone && $whatsapp) {
            $byPhone = Guardian::query()
                ->where('school_id', $schoolId)
                ->where(function ($q) use ($whatsapp) {
                    $q->where('phone', $whatsapp)->orWhere('whatsapp_number', $whatsapp);
                })
                ->first();
        }

        if ($byEmail && $byPhone && $byEmail->id !== $byPhone->id) {
            throw new ImportConflictException(sprintf(
                'Conflicting match: email belongs to %s, phone belongs to %s. Resolve manually.',
                $byEmail->full_name,
                $byPhone->full_name,
            ));
        }

        return $byEmail ?: $byPhone;
    }

    private function lookupRowCache(?string $email, ?string $phone, ?string $whatsapp): ?Guardian
    {
        foreach ([$email, $phone, $whatsapp] as $key) {
            if ($key && isset($this->rowCache[$key])) {
                return $this->rowCache[$key];
            }
        }
        return null;
    }

    private function cacheRow(Guardian $guardian, array $data): void
    {
        foreach ([$data['email'], $data['phone'], $data['whatsapp_number']] as $key) {
            if ($key) {
                $this->rowCache[$key] = $guardian;
            }
        }
    }

    /**
     * Returns the guardian-only attributes (the subset that maps to the guardians table).
     */
    private function guardianAttributes(array $data): array
    {
        return [
            'first_name'        => $data['first_name'],
            'middle_name'       => $data['middle_name'],
            'last_name'         => $data['last_name'],
            'gender'            => $data['gender'],
            'phone'             => $data['phone'],
            'whatsapp_number'   => $data['whatsapp_number'],
            'city'              => $data['city'],
            'state'             => $data['state'],
            'country'           => $data['country'],
            'postal_code'       => $data['postal_code'],
            'occupation'        => $data['occupation'],
            'employer_name'     => $data['employer_name'],
            'marital_status'    => $data['marital_status'],
            'emergency_contact' => $data['emergency_contact'],
            'id_type'           => $data['id_type'],
            'id_number'         => $data['id_number'],
            'id_expiry_date'    => $data['id_expiry_date'],
            'status'            => $data['status'],
            // photo_id left null — bulk import never sets photos.
        ];
    }

    /**
     * Warn when a row leaves the student with no primary guardian.
     */
    private function primaryWarning(Student $student, bool $isPrimary): string
    {
        if ($isPrimary) {
            return '';
        }

        $hasPrimary = DB::table('guardian_student')
            ->where('student_id', $student->id)
            ->where('is_primary', true)
            ->exists();

        return $hasPrimary ? '' : ' Warning: student now has no primary guardian. Set one manually.';
    }

    private function ok(string $message, int $guardianId): array
    {
        return ['status' => 'success', 'message' => $message, 'guardian_id' => $guardianId];
    }

    private function skipped(string $message, ?int $guardianId): array
    {
        return ['status' => 'skipped', 'message' => $message, 'guardian_id' => $guardianId];
    }

    private function failed(string $message): array
    {
        return ['status' => 'failed', 'message' => $message, 'guardian_id' => null];
    }
}
