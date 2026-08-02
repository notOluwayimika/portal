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
