<?php

namespace App\Notifications\Models;

use App\Concerns\AddUuid;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (recipient, channel) — delivery state, and nothing else.
 *
 * NO BelongsToSchool. This table has no `school_id` column: it is reached only
 * through its recipient, which carries the boundary. Adding the trait would make
 * the global SchoolScope filter on a column that does not exist.
 *
 * The `uuid` doubles as the PROVIDER IDEMPOTENCY KEY from v2, so a retry that
 * reaches the vendor twice is deduplicated vendor-side by the same value.
 *
 * @property DeliveryStatus $status
 * @property ChannelKey $channel
 */
class NotificationDelivery extends Model
{
    use AddUuid;

    protected $fillable = [
        'notification_recipient_id', 'channel', 'status', 'skip_reason',
        'provider', 'provider_message_id', 'attempts', 'last_error',
        'queued_at', 'sent_at', 'delivered_at', 'failed_at',
    ];

    protected $casts = [
        'channel' => ChannelKey::class,
        'status' => DeliveryStatus::class,
        'attempts' => 'integer',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /** @return BelongsTo<NotificationRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'notification_recipient_id');
    }
}
