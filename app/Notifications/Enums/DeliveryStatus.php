<?php

namespace App\Notifications\Enums;

/**
 * Per-channel delivery state.
 *
 * SKIPPED IS A FIRST-CLASS OUTCOME, not an absence. A notification refused by
 * preference, suppression, quiet hours or a missing address is recorded with a
 * skip_reason rather than dropped, so the question "why did this parent not get
 * it?" is answerable from a row.
 */
enum DeliveryStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case BOUNCED = 'bounced';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
