<?php

namespace App\Notifications\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Models\User;
use App\Notifications\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row per EVENT — one subject, many recipients.
 *
 * Named `App\Notifications\Models\Notification` alongside Laravel's
 * `Illuminate\Notifications\Notification`, which the five legacy mail-only
 * classes in `App\Notifications\` still extend. Different namespaces, no
 * collision; those classes migrate onto this system in v2.
 *
 * @property int $school_id
 * @property NotificationType $type
 * @property array<string, mixed> $payload
 */
class Notification extends Model
{
    use AddUuid, BelongsToSchool;

    // Written once, never updated: an event is a fact. `updated_at` would be a
    // column that can only ever lie.
    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id', 'type', 'actor_id', 'subject_type', 'subject_id',
        'payload', 'rendered_fallback', 'dedup_key',
    ];

    protected $casts = [
        'type' => NotificationType::class,
        'payload' => 'array',
    ];

    /** @return HasMany<NotificationRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
