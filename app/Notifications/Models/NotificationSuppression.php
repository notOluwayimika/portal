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
        'cleared_at', 'cleared_by', 'is_live',
    ];

    protected $casts = [
        'channel' => ChannelKey::class,
        'scope' => SuppressionScope::class,
        'reason' => SuppressionReason::class,
        'expires_at' => 'datetime',
        'cleared_at' => 'datetime',
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
            // MATERIALIZED AT WRITE, not derived at read. The row records the decision
            // AS MADE, so editing ttlHours() later cannot retroactively rewrite when an
            // existing suppression lifts. That is the property a consent ledger needs —
            // and its cost is that a mistaken constant needs a DATA fix, not just a code
            // fix. The two cannot both be true; this is the correct side for this data.
            'expires_at' => $ttl === null ? null : now()->addHours($ttl),
            'cleared_at' => null,
            'is_live' => 1,
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
            // LAPSED **OR** CLEARED, and deliberately REASON-BLIND: the gate asks the
            // ROW, never the taxonomy. That is what let SMS_INVALID_NUMBER inherit real
            // expiry the moment it declared a TTL, with no wiring step to forget — the
            // same instinct as one normalizer rather than one per call site.
            ->whereNotNull('is_live')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /**
     * Overturn a live suppression early, IF this evidence overturns that reason.
     *
     * ⚠️ STAMPED, NEVER DELETED OR OVERWRITTEN. Rewriting `expires_at` to now() would
     * destroy the decision the row exists to record; deleting it makes an errant clear
     * invisible BY CONSTRUCTION. If the reason filter below ever regresses and an
     * opt-out gets cleared by a delivery receipt, the stamped row still carries
     * `cleared_by = 'delivery_success'` — which is precisely the evidence a consent
     * audit needs to catch it. The mechanism that can unmute someone must be able to
     * prove whether it ever unmuted the wrong person.
     *
     * REASON-KEYED on WHETHER to clear; the send-time gate stays reason-blind on
     * WHETHER it is live. Two concerns, two mechanisms, no interaction.
     */
    public static function clearIfClearable(ChannelKey $channel, string $rawAddress, string $evidence): int
    {
        $normalized = self::normalize($channel, $rawAddress);

        if ($normalized === null) {
            return 0;
        }

        $cleared = 0;

        static::query()
            ->where('address_hash', AddressNormalizer::hash($normalized))
            ->where('channel', $channel->value)
            ->whereNotNull('is_live')
            ->get()
            ->each(function (self $suppression) use ($evidence, &$cleared): void {
                if (! $suppression->reason->clearedBy($evidence)) {
                    return;
                }

                $suppression->forceFill([
                    'cleared_at' => now(),
                    'cleared_by' => $evidence,
                    // Releases the unique slot so the address can be suppressed again
                    // later, while the row itself stays as history.
                    'is_live' => null,
                ])->save();

                $cleared++;
            });

        return $cleared;
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
