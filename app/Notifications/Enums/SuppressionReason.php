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
    case SMS_INVALID_NUMBER = 'sms_invalid_number';

    /**
     * A HARD fact about the mailbox belongs to the ADDRESS — it is equally dead for
     * every school and every co-guardian sharing it. A statement by a PERSON belongs
     * to their contact point, or one parent's unsubscribe silences the other.
     */
    public function scope(): SuppressionScope
    {
        return match ($this) {
            self::HARD_BOUNCE, self::COMPLAINT, self::SMS_STOP, self::SMS_INVALID_NUMBER => SuppressionScope::ADDRESS,
            self::SOFT_BOUNCE, self::UNSUBSCRIBE => SuppressionScope::CONTACT_POINT,
        };
    }

    /**
     * Does this evidence overturn the suppression before its TTL?
     *
     * ⚠️ THE ONE PLACE STOP AND INVALID-NUMBER MUST DIFFER, and conflating them lets a
     * delivered message quietly undo an opt-out.
     *
     *   INVALID NUMBER is an INFERENCE about the number. A later successful delivery
     *   to that MSISDN is positive evidence the inference was wrong, so clearing it
     *   early is correct.
     *
     *   SMS_STOP is an EXPRESSED INTENT. A successful delivery says nothing about
     *   consent — only an explicit re-opt-in (START) reverses it. A delivery receipt
     *   must never clear one.
     *
     * Email's permanent reasons are cleared by nothing: a hard bounce is a fact about
     * a mailbox, and there is no positive evidence short of the address working again,
     * which is what a fresh contact point represents rather than a delivery receipt.
     */
    public function clearedBy(string $evidence): bool
    {
        return match ($this) {
            self::SMS_INVALID_NUMBER => $evidence === 'delivery_success',
            self::SMS_STOP => $evidence === 'explicit_optin',
            self::SOFT_BOUNCE => $evidence === 'delivery_success',
            self::HARD_BOUNCE, self::COMPLAINT, self::UNSUBSCRIBE => false,
        };
    }

    /** Null = permanent. Never null for an SMS reason — see below. */
    public function ttlHours(): ?int
    {
        return match ($this) {
            // The mailbox does not exist, and the recipient asked to stop. Permanent.
            self::HARD_BOUNCE, self::COMPLAINT, self::UNSUBSCRIBE => null,
            // TRANSIENT — a full inbox clears. Permanently muting a live parent over
            // one is a worse failure than retrying tomorrow.
            self::SOFT_BOUNCE => 72,
            // ⚠️ NO SMS SUPPRESSION IS EVER PERMANENT, and this is the first place the
            // email and SMS models genuinely diverge rather than wearing different
            // labels. A dead mailbox stays dead; a dead NUMBER does not. MSISDNs are
            // recycled, so a number that is unassigned today belongs to a different
            // family within months — and an eternal number-scoped suppression would
            // mute a household that never opted out and has no way to discover why.
            //
            // Do NOT "fix the inconsistency" by giving these a null TTL to match
            // email. The asymmetry is a fact about the world, not an oversight.
            self::SMS_STOP => 8760,
            // Shorter than a STOP: this is an INFERENCE about the number from a
            // carrier reject, not an expressed intent, so it should lapse sooner and
            // can also be cleared early by contrary evidence — see clearedBy().
            self::SMS_INVALID_NUMBER => 2160,
        };
    }
}
