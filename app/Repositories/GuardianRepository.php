<?php

namespace App\Repositories;

use App\Models\Guardian;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Str;

class GuardianRepository
{
    public function __construct(private Guardian $model) {}

    /**
     * Find a guardian within a specific school by email (on users table) or phone/whatsapp.
     * Returns null on miss. Respects the soft-delete default scope.
     *
     * Phones are normalized before comparison so `+2348000000000` and `08000000000`
     * resolve to the same record. Email comparison is case-insensitive.
     */
    public function findByIdentifierInSchool(string $identifier, int $schoolId, array $with = ['user', 'photoFile']): ?Guardian
    {
        $identifier      = trim($identifier);
        $normalizedPhone = PhoneNormalizer::normalize($identifier);
        $emailLower      = Str::lower($identifier);

        return $this->model
            ->withoutGlobalScope(\App\Models\Scopes\SchoolScope::class)
            ->with($with)
            ->where(function ($query) use ($schoolId) {
                $query->where('guardians.school_id', $schoolId)
                    ->orWhereHas('user.schools', fn ($schoolQuery) => $schoolQuery->where('schools.id', $schoolId));
            })
            ->where(function ($q) use ($identifier, $normalizedPhone, $emailLower) {
                $q->where('phone', $identifier)
                  ->orWhere('whatsapp_number', $identifier);

                if ($normalizedPhone) {
                    $q->orWhere('phone', $normalizedPhone)
                      ->orWhere('whatsapp_number', $normalizedPhone);
                }

                $q->orWhereHas('user', fn($u) => $u->whereRaw('LOWER(email) = ?', [$emailLower]));
            })
            ->first();
    }

    public function findByUuidInSchool(string $uuid, int $schoolId, array $with = []): ?Guardian
    {
        return $this->model
            ->withoutGlobalScope(\App\Models\Scopes\SchoolScope::class)
            ->with($with)
            ->where(function ($query) use ($schoolId) {
                $query->where('guardians.school_id', $schoolId)
                    ->orWhereHas('user.schools', fn ($schoolQuery) => $schoolQuery->where('schools.id', $schoolId));
            })
            ->where('uuid', $uuid)
            ->first();
    }

    public function findByIdentifierGlobally(string $identifier, array $with = ['user', 'photoFile']): ?Guardian
    {
        $identifier = trim($identifier);
        $normalizedPhone = PhoneNormalizer::normalize($identifier);
        $emailLower = Str::lower($identifier);

        return $this->model
            ->withoutGlobalScopes()
            ->whereNull('guardians.deleted_at')
            ->with($with)
            ->where(function ($query) use ($identifier, $normalizedPhone, $emailLower) {
                $query->where('phone', $identifier)
                    ->orWhere('whatsapp_number', $identifier)
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->whereRaw('LOWER(email) = ?', [$emailLower]));

                if ($normalizedPhone) {
                    $query->orWhere('phone', $normalizedPhone)
                        ->orWhere('whatsapp_number', $normalizedPhone);
                }
            })
            ->first();
    }

    public function findByUuidGlobally(string $uuid, array $with = ['user', 'photoFile']): ?Guardian
    {
        return $this->model
            ->withoutGlobalScopes()
            ->whereNull('guardians.deleted_at')
            ->with($with)
            ->where('uuid', $uuid)
            ->first();
    }

    public function emailExistsForOtherUser(string $email, ?int $exceptUserId = null): bool
    {
        return \App\Models\User::query()
            ->where('email', $email)
            ->when($exceptUserId, fn($q) => $q->where('id', '!=', $exceptUserId))
            ->exists();
    }
}
