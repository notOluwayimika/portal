<?php

namespace App\Notifications\Enums;

enum SuppressionScope: string
{
    /** A fact about the mailbox or number itself — mutes every contact point on it. */
    case ADDRESS = 'address';

    /** A statement by one person — mutes only their own contact point. */
    case CONTACT_POINT = 'contact_point';
}
