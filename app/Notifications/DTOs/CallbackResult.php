<?php

namespace App\Notifications\DTOs;

use App\Notifications\Enums\NotificationActionOutcome;
use App\Notifications\Enums\NotificationActionStatus;

/**
 * A DECISION from the external service — never "we could not reach it".
 *
 * Unreachability is CallbackUnconfirmed, an exception, because a result type that can
 * represent "unknown" invites callers to treat unknown as a soft failure.
 */
final class CallbackResult
{
    private function __construct(
        public readonly NotificationActionStatus $status,
        public readonly NotificationActionOutcome $outcome,
    ) {}

    /** The service honoured the request. */
    public static function revoked(): self
    {
        return new self(NotificationActionStatus::RESOLVED, NotificationActionOutcome::REVOKED);
    }

    /**
     * Reached, understood, and declined — THEIR window had closed.
     *
     * Distinct from our own EXPIRED: a tap can be comfortably inside our expiry and
     * still be refused, because the two deadlines belong to different systems. A
     * client that conflated them would tell a parent "you were too slow" when the
     * truth is "the pickup had already happened".
     */
    public static function tooLate(): self
    {
        return new self(NotificationActionStatus::REJECTED, NotificationActionOutcome::TOO_LATE);
    }
}
