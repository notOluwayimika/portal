<?php

namespace App\Notifications\Enums;

/**
 * Why an address is suppressed — and, from that, for how long and at what scope.
 *
 * PERMANENCE AND SCOPE ARE PROPERTIES OF THE REASON, not free choices per row. That
 * is what stops "hard = forever" being applied to a full mailbox, which would mute a
 * live parent permanently over one transient condition.
 */
enum SuppressionReason: string
{
    case HARD_BOUNCE = 'hard_bounce';
    case COMPLAINT = 'complaint';
    case SOFT_BOUNCE = 'soft_bounce';
    case UNSUBSCRIBE = 'unsubscribe';
    case SMS_STOP = 'sms_stop';

    /**
     * A HARD fact about the mailbox belongs to the ADDRESS — it is equally dead for
     * every school and every co-guardian sharing it. A statement by a PERSON belongs
     * to their contact point, or one parent's unsubscribe silences the other.
     */
    public function scope(): SuppressionScope
    {
        return match ($this) {
            self::HARD_BOUNCE, self::COMPLAINT, self::SMS_STOP => SuppressionScope::ADDRESS,
            self::SOFT_BOUNCE, self::UNSUBSCRIBE => SuppressionScope::CONTACT_POINT,
        };
    }

    /** Null = permanent. */
    public function ttlHours(): ?int
    {
        return match ($this) {
            // The mailbox does not exist, and the recipient asked to stop. Permanent.
            self::HARD_BOUNCE, self::COMPLAINT, self::UNSUBSCRIBE => null,
            // TRANSIENT — a full inbox clears. Permanently muting a live parent over
            // one is a worse failure than retrying tomorrow.
            self::SOFT_BOUNCE => 72,
            // A recycled MSISDN belongs to a different family within months, so an
            // eternal number-scoped stop mutes someone who never opted out (v3).
            self::SMS_STOP => 8760,
        };
    }
}
