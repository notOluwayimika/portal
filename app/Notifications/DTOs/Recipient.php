<?php

namespace App\Notifications\DTOs;

use App\Models\User;
use App\Notifications\Enums\RecipientReason;

/**
 * One resolved recipient. A value, not a model — resolvers return these without
 * hydrating anything, so a 400-parent fan-out is one query, not 400.
 */
final class Recipient
{
    public function __construct(
        public readonly string $notifiableType,
        public readonly int $notifiableId,
        public readonly RecipientReason $reason,
    ) {}

    public static function user(int $userId, RecipientReason $reason): self
    {
        return new self(User::class, $userId, $reason);
    }

    /** Stable identity for de-duplicating a recipient list in memory. */
    public function key(): string
    {
        return $this->notifiableType.':'.$this->notifiableId;
    }
}
