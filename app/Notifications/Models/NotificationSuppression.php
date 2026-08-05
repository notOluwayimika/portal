<?php

namespace App\Notifications\Models;

use App\Concerns\AddUuid;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Enums\SuppressionReason;
use App\Notifications\Enums\SuppressionScope;
use App\Support\AddressNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An address we must stop contacting.
 *
 * ⚠️ EVERY READ AND EVERY WRITE GOES THROUGH AddressNormalizer, and that is the whole
 * safety property. The suppression WRITE (an inbound bounce) and the send-time CHECK
 * both reduce an address to a comparable form; if they ever disagree — one lowercases
 * and the other does not — a suppressed address sails through the check and mail goes
 * to someone who asked us to stop. Nothing throws, nothing logs, every test stays
 * green. That is the same silent-green signature as a hook that never fires, moved
 * into the send loop.
 *
 * NOT School-scoped, by design — see the migration. A bounce is a fact about an
 * address, and the address does not belong to a tenancy.
 */
class NotificationSuppression extends Model
{
    use AddUuid;

    protected $fillable = [
        'channel', 'scope', 'reason', 'normalized_address', 'address_hash',
        'contact_point_id', 'source', 'notification_delivery_id', 'expires_at',
    ];

    protected $casts = [
        'channel' => ChannelKey::class,
        'scope' => SuppressionScope::class,
        'reason' => SuppressionReason::class,
        'expires_at' => 'datetime',
    ];

    /**
     * Record a suppression, idempotently.
     *
     * `insertOrIgnore` on the UNIQUE (address_hash, channel, scope): a replayed
     * provider event is ROUTINE — SNS redelivers — so a second identical bounce must
     * add nothing rather than duplicate or throw.
     */
    public static function record(
        ChannelKey $channel,
        string $rawAddress,
        SuppressionReason $reason,
        string $source,
        ?int $deliveryId = null,
        ?int $contactPointId = null,
    ): void {
        $normalized = self::normalize($channel, $rawAddress);

        if ($normalized === null) {
            return;
        }

        $ttl = $reason->ttlHours();

        static::query()->insertOrIgnore([
            'uuid' => (string) Str::orderedUuid(),
            'channel' => $channel->value,
            'scope' => $reason->scope()->value,
            'reason' => $reason->value,
            'normalized_address' => $normalized,
            'address_hash' => AddressNormalizer::hash($normalized),
            'contact_point_id' => $contactPointId,
            'source' => $source,
            'notification_delivery_id' => $deliveryId,
            'expires_at' => $ttl === null ? null : now()->addHours($ttl),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Is this address suppressed for this channel RIGHT NOW?
     *
     * Expiry is applied here rather than by a sweeper: a transient suppression that
     * outlives its window because nobody ran a cleanup job is a live parent muted by
     * an unrun command.
     */
    public static function suppresses(ChannelKey $channel, string $rawAddress): bool
    {
        $normalized = self::normalize($channel, $rawAddress);

        if ($normalized === null) {
            return false;
        }

        return static::query()
            ->where('address_hash', AddressNormalizer::hash($normalized))
            ->where('channel', $channel->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /**
     * THE ONE NORMALIZATION, shared by record() and suppresses().
     *
     * Both paths call this rather than each normalizing for itself, so the write and
     * the check cannot disagree about which characters count. Two call sites, one
     * definition — the lesson the synthetic-email check taught at N=2.
     */
    private static function normalize(ChannelKey $channel, string $rawAddress): ?string
    {
        return $channel === ChannelKey::EMAIL
            ? AddressNormalizer::email($rawAddress)
            : AddressNormalizer::phone($rawAddress);
    }
}
