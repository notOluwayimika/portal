<?php

namespace App\Notifications\Services\Channels;

use App\Notifications\Contracts\Channel;
use App\Notifications\DTOs\Recipient;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\NotificationDelivery;

/**
 * The in-app feed.
 *
 * SENDING IS A NO-OP, AND THAT IS THE DESIGN. The recipient row IS the delivery —
 * it is already in the database before this channel is consulted, so there is
 * nothing to transmit, no provider, no failure mode and no retry. The delivery
 * row is written `delivered` at fan-out purely so the audit table has one uniform
 * shape across channels; without it, "which channels carried this?" would need a
 * special case for the only channel that always works.
 *
 * NO BROADCAST HAPPENS HERE. `BROADCAST_CONNECTION=log`, `config/broadcasting.php`
 * is not published, and there is no Reverb or Echo — v1's real-time story is the
 * client polling `/api/notifications/unread-count`. A broadcast call here would
 * be a line that looks like push and delivers nothing, so it is absent until the
 * transport actually exists.
 */
class InAppChannel implements Channel
{
    public function key(): ChannelKey
    {
        return ChannelKey::IN_APP;
    }

    /**
     * Always. The address is the feed row itself, so unlike email and SMS there
     * is no such thing as a recipient without one.
     */
    public function supports(Recipient $recipient): bool
    {
        return true;
    }

    public function send(NotificationDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::DELIVERED,
            'delivered_at' => now(),
        ])->save();
    }
}
