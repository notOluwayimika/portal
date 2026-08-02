<?php

namespace App\Notifications\DTOs;

use App\Notifications\Enums\ChannelKey;
use App\Notifications\Enums\NotificationType;

/**
 * What the system knows about a notification type, held in code rather than in a
 * table.
 *
 * A DATABASE-BACKED CATALOGUE WOULD BE WORSE HERE. A type whose resolver class is
 * missing or misspelled is a runtime exception on a row nobody looks at until it
 * fires; in the registry it is a failing arch test on the commit that introduced
 * it. Per-school OVERRIDES belong in the database (v2); the catalogue does not.
 */
final class TypeDefinition
{
    /**
     * @param  class-string  $resolver
     * @param  list<ChannelKey>  $defaultChannels
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $resolver,
        public readonly array $defaultChannels,
        /**
         * FALSE = transactional: the recipient cannot opt out. Password changes,
         * invoices and approval requests are obligations, not subscriptions.
         *
         * This is honest rather than convenient — the preferences UI shows these
         * locked with the reason, instead of offering a toggle that is silently
         * ignored. It does NOT reach through a legal suppression: a school can
         * force the message onto in-app and email, never onto a channel the
         * recipient has legally opted out of (v2).
         */
        public readonly bool $userConfigurable = true,
        /**
         * Excluded from its own notification. On by default because the actor
         * already knows; approval flows additionally REQUIRE it, since
         * `submitted_by <> decided_by` means the submitter cannot act on their
         * own request and notifying them invites a refused action.
         */
        public readonly bool $excludeActor = true,
    ) {}
}
