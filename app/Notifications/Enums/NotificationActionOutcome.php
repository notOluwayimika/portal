<?php

namespace App\Notifications\Enums;

/**
 * What the external service actually DID, as distinct from whether we reached it.
 *
 * Status answers "did the relay complete"; outcome answers "and what happened".
 * Keeping them apart is what lets `REJECTED` + `TOO_LATE` say something useful — the
 * service was reached, understood the request, and declined it because the moment had
 * passed — rather than collapsing into an undifferentiated failure that a client
 * cannot render honestly.
 *
 * Null outcome is meaningful: EXPIRED and UNCONFIRMED have none, because in neither
 * case did the external service reach a decision.
 */
enum NotificationActionOutcome: string
{
    /** The request was honoured. */
    case REVOKED = 'revoked';

    /** Reached and understood, but declined — the window on THEIR side had closed. */
    case TOO_LATE = 'too_late';
}
