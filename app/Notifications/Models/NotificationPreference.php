<?php

namespace App\Notifications\Models;

use App\Concerns\BelongsToSchool;
use App\Models\User;
use App\Notifications\Enums\ChannelKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-user, per-school, per-type, per-channel OVERRIDE.
 *
 * SPARSE: a row exists only where the user has departed from the type's default,
 * so introducing a notification type never means backfilling a row per user, and
 * changing a default takes effect for everyone who never expressed an opinion.
 *
 * `type` accepts the sentinel `*` — the whole-channel switch ("no SMS ever"),
 * which a per-type row then overrides.
 */
class NotificationPreference extends Model
{
    use BelongsToSchool;

    public const ALL_TYPES = '*';

    protected $fillable = ['user_id', 'school_id', 'type', 'channel', 'enabled'];

    protected $casts = [
        'channel' => ChannelKey::class,
        'enabled' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
