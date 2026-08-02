<?php

namespace App\Notifications\Services;

use App\Notifications\Contracts\Channel;
use App\Notifications\Enums\ChannelKey;
use App\Notifications\Services\Channels\InAppChannel;
use RuntimeException;

/**
 * The channels this deployment has wired.
 *
 * Separate from `TypeDefinition::$defaultChannels`, which says what a type WANTS.
 * This says what EXISTS. A type declaring EMAIL before the email channel ships
 * simply produces no email delivery row rather than a crash — which is what lets
 * the v2 types be defined ahead of their transport.
 */
class ChannelRegistry
{
    /** @var array<string, Channel>|null */
    private ?array $channels = null;

    /** @return array<string, Channel> */
    public function all(): array
    {
        return $this->channels ??= [
            ChannelKey::IN_APP->value => app(InAppChannel::class),
            // EMAIL → v2, SMS → v3.
        ];
    }

    public function has(ChannelKey $key): bool
    {
        return isset($this->all()[$key->value]);
    }

    public function get(ChannelKey $key): Channel
    {
        return $this->all()[$key->value] ?? throw new RuntimeException(
            "Notification channel [{$key->value}] is not wired in this deployment."
        );
    }

    public function inApp(): Channel
    {
        return $this->get(ChannelKey::IN_APP);
    }
}
