<?php

namespace App\Repositories;

use App\Models\Guardian;

class GuardianRepository
{
    public function __construct(private Guardian $model) {}

    /**
     * Find a guardian within a specific school by email (on users table) or phone/whatsapp.
     * Returns null on miss. Respects the soft-delete default scope.
     */
    public function findByIdentifierInSchool(string $identifier, int $schoolId, array $with = ['user', 'photoFile']): ?Guardian
    {
        $identifier = trim($identifier);

        return $this->model
            ->withoutGlobalScope(\App\Models\Scopes\SchoolScope::class)
            ->with($with)
            ->where('school_id', $schoolId)
            ->where(function ($q) use ($identifier) {
                $q->where('phone', $identifier)
                  ->orWhere('whatsapp_number', $identifier)
                  ->orWhereHas('user', fn($u) => $u->where('email', $identifier));
            })
            ->first();
    }

    public function findByUuidInSchool(string $uuid, int $schoolId, array $with = []): ?Guardian
    {
        return $this->model
            ->withoutGlobalScope(\App\Models\Scopes\SchoolScope::class)
            ->with($with)
            ->where('school_id', $schoolId)
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
