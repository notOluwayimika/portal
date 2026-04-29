<?php
// app/Models/AuditLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    // Append-only: disable updated_at
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'payload',
        'ip_address',
    ];

    protected $casts = ['payload' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Convenience factory for logging actions.
     */
    public static function record(
        string $userId,
        string $action,
        Model $entity,
        array $payload = [],
        ?string $ipAddress = null,
    ): static {
        return static::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => get_class($entity),
            'entity_id' => $entity->getKey(),
            'payload' => $payload,
            'ip_address' => $ipAddress,
        ]);
    }
}
