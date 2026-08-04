<?php

namespace App\Notifications\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Models\User;
use App\Notifications\Enums\NotificationActionOutcome;
use App\Notifications\Enums\NotificationActionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tappable action, and the row whose conditional UPDATE is the exactly-once lock.
 *
 * BelongsToSchool IS PRESENT AND IS NOT THE AUTHORIZATION. The global scope is a
 * convenience for ordinary reads; the trust boundary scopes `school_id` EXPLICITLY,
 * because there is no row-level security under this application and a scope that can
 * be bypassed by `withoutGlobalScopes()` — or silently not applied on a query builder
 * — is not a security control. Belt is not braces when both are the same belt.
 *
 * @property NotificationActionStatus $status
 * @property ?NotificationActionOutcome $outcome
 * @property int $school_id
 */
class NotificationAction extends Model
{
    use AddUuid, BelongsToSchool;

    protected $fillable = [
        'school_id', 'notification_id', 'label', 'status', 'outcome',
        'expires_at', 'resolved_by', 'resolved_at',
        'callback_url', 'callback_payload', 'last_error',
    ];

    protected $casts = [
        'status' => NotificationActionStatus::class,
        'outcome' => NotificationActionOutcome::class,
        'expires_at' => 'datetime',
        'resolved_at' => 'datetime',
        'callback_payload' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Notification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Is this action still tappable RIGHT NOW?
     *
     * Advisory only — for rendering. It is deliberately NOT consulted before the
     * claim: checking here and then updating would be a read-then-write with a race
     * in the gap, which is precisely what the conditional UPDATE exists to avoid.
     */
    public function isClaimable(): bool
    {
        return $this->status === NotificationActionStatus::PENDING
            && $this->expires_at->isFuture();
    }
}
