<?php

namespace App\Notifications\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Notifications\Enums\RecipientReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row per (event, person) — THE FEED TABLE, and the only place read state
 * lives.
 *
 * Read state is deliberately absent from `notification_deliveries`: an email
 * cannot be "unread", and duplicating `read_at` per channel would make "is this
 * read?" a question with three possible answers.
 *
 * @property int $school_id
 * @property ?\Illuminate\Support\Carbon $read_at
 */
class NotificationRecipient extends Model
{
    use AddUuid, BelongsToSchool;

    protected $fillable = [
        'notification_id', 'school_id', 'notifiable_type', 'notifiable_id', 'reason',
        'seen_at', 'read_at',
    ];

    protected $casts = [
        'reason' => RecipientReason::class,
        'seen_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /** @return BelongsTo<Notification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The feed scope. Column order matches the `notif_recipients_feed` index, so
     * both the list and the unread count are served without a filesort.
     *
     * @param  Builder<NotificationRecipient>  $query
     * @return Builder<NotificationRecipient>
     */
    public function scopeFor(Builder $query, string $notifiableType, int $notifiableId, int $schoolId): Builder
    {
        return $query->where('notifiable_type', $notifiableType)
            ->where('notifiable_id', $notifiableId)
            ->where('school_id', $schoolId);
    }
}
