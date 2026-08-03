<?php

namespace App\Notifications\Enums;

/**
 * Transports. PUBLIC — type definitions declare their channels in this vocabulary.
 *
 * v1 ships IN_APP only. EMAIL and SMS are declared now because the type
 * definitions and the preferences table are keyed by channel, and a value that
 * appears later would mean rewriting stored preference rows.
 */
enum ChannelKey: string
{
    case IN_APP = 'in_app';
    case EMAIL = 'email';
    case SMS = 'sms';
    /**
     * A DISTINCT TRANSPORT TO THE SAME NUMBER. `guardians.whatsapp_number` already
     * exists and is frequently the same digits as `guardians.phone`, so the backfill
     * legitimately produces two contact points differing only by channel. That is
     * not a dedup miss: the two are suppressed independently (a STOP to the SMS
     * carrier says nothing about WhatsApp), and Termii bills them differently.
     */
    case WHATSAPP = 'whatsapp';

    /**
     * Is this channel intrusive — does it reach the recipient rather than wait
     * for them?
     *
     * Drives quiet hours, bundling and rate limits (all v2). IN_APP is not
     * intrusive: it wakes nobody and costs nothing, which is precisely why v1
     * needs none of that machinery.
     */
    public function isIntrusive(): bool
    {
        return $this !== self::IN_APP;
    }
}
