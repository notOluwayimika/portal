<?php

namespace App\Notifications\Contracts;

use App\Notifications\DTOs\Recipient;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Models\NotificationDelivery;

/**
 * A transport. Adding one touches no business logic — a call site names a type,
 * never a channel.
 *
 * v1 implements IN_APP only, whose send() is a no-op: persisting the recipient
 * row IS the delivery. The interface exists now, with one implementation, so
 * that adding email in v2 is an implementation rather than a refactor.
 */
interface Channel
{
    public function key(): ChannelKey;

    /**
     * Does this recipient have a usable address for this channel?
     *
     * Returning false produces a SKIPPED delivery row with a reason, never a
     * silent drop.
     */
    public function supports(Recipient $recipient): bool;

    public function send(NotificationDelivery $delivery): void;
}
