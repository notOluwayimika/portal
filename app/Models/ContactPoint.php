<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Notifications\Enums\ChannelKey;
use App\Support\AddressNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reachable address for one person on one channel.
 *
 * NO BelongsToSchool, deliberately — see the migration. An address belongs to a
 * human, not a tenancy; the same person is a parent at one school and staff at
 * another with one phone.
 *
 * NORMALIZATION IS ENFORCED ON THE MODEL, not left to callers. `normalized_address`
 * and `address_hash` are derived from `address` on every save, so there is no path
 * — factory, seeder, backfill, admin form — that can write a row whose normalized
 * form disagrees with its raw one. A row like that is invisible: it looks correct
 * in every listing and simply never matches a suppression.
 *
 * @property string $address
 * @property string $normalized_address
 * @property ChannelKey $channel
 */
class ContactPoint extends Model
{
    use AddUuid;

    protected $fillable = [
        'user_id', 'channel', 'address', 'is_primary', 'verified_at', 'source',
    ];

    protected $casts = [
        'channel' => ChannelKey::class,
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Block closure, not an arrow fn: `saving` is a halting event dispatched
        // through until(), so returning a value stops the rest of the chain — the
        // same trap App\Concerns\AddUuid documents and bin/ci-boundary-lint.php
        // enforces.
        static::saving(function (self $contactPoint): void {
            $normalized = $contactPoint->normalizeOwnAddress();

            $contactPoint->normalized_address = $normalized;
            $contactPoint->address_hash = AddressNormalizer::hash($normalized);
        });
    }

    /**
     * @throws \InvalidArgumentException when the address cannot be normalized —
     *                                   which is the correct outcome for `n/a`, `-`, or a name typed into a phone
     *                                   field. A contact point that cannot be sent to is not a contact point, and
     *                                   storing one makes "has no contact path" permanently false for exactly the
     *                                   people for whom it is true.
     */
    private function normalizeOwnAddress(): string
    {
        // The enum cast resolves on access, so this is a ChannelKey even mid-`saving`
        // and even when the attribute was assigned as a raw string via create().
        // An instanceof guard here reads as defensive and is dead code.
        $channel = $this->channel;

        $normalized = match ($channel) {
            ChannelKey::EMAIL => AddressNormalizer::email($this->address),
            // WhatsApp and SMS are different transports to the SAME number, so they
            // share the phone form. Two rows on one number differing only by channel
            // is correct, not a dedup miss — v3 suppresses them independently.
            ChannelKey::SMS, ChannelKey::WHATSAPP => AddressNormalizer::phone($this->address),
            default => null,
        };

        return $normalized ?? throw new \InvalidArgumentException(
            "[{$this->address}] is not a usable {$channel->value} address."
        );
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every contact point on one address, across people.
     *
     * The address-scoped suppression path: a hard bounce is a fact about the
     * mailbox, so it has to reach both co-guardians who share it — which is only
     * expressible against the hash, never against a single contact point id.
     *
     * @param  Builder<ContactPoint>  $query
     * @return Builder<ContactPoint>
     */
    public function scopeSharingAddress(Builder $query, string $normalizedAddress, ChannelKey $channel): Builder
    {
        return $query->where('address_hash', AddressNormalizer::hash($normalizedAddress))
            ->where('channel', $channel->value);
    }
}
