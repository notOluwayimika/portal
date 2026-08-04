<?php

namespace App\Notifications\Enums;

/**
 * The lifecycle of a tappable action on a notification.
 *
 * FIVE OF THE SIX ARE TERMINAL. Only `PENDING` and `RESOLVING` are transient, and
 * `RESOLVING` exists solely as the claim marker — the state a row is in between
 * winning the atomic claim and hearing back from the external service. A row left in
 * `RESOLVING` means the process died mid-relay; reconciling those is the harden pass,
 * and until then it is deliberately distinguishable from `UNCONFIRMED` (which means
 * we relayed and did not hear back) rather than collapsed into it.
 *
 * `UNCONFIRMED` IS NOT AN ERROR. The callback timed out, so we genuinely do not know
 * whether the external side acted. Rendering it as a failure would be a lie in the
 * more dangerous direction — telling a parent their revoke did not happen when it may
 * have. The client reconciles it later.
 */
enum NotificationActionStatus: string
{
    /** Tappable. The only state from which a claim can succeed. */
    case PENDING = 'pending';

    /** Claimed by one user; the callback is in flight. Transient. */
    case RESOLVING = 'resolving';

    /** The external service accepted. See the outcome for what it did. */
    case RESOLVED = 'resolved';

    /** The window closed before anyone tapped. Nobody claimed it. */
    case EXPIRED = 'expired';

    /** Relayed, but the callback did not answer in time. Truthfully unknown. */
    case UNCONFIRMED = 'unconfirmed';

    /** The external service answered and refused. See the outcome for why. */
    case REJECTED = 'rejected';

    /**
     * Is this a settled state that no further tap can change?
     *
     * Used to answer a losing claimant honestly rather than re-attempting: a second
     * tap on a resolved action is not an error, it is a request for the current state.
     */
    public function isTerminal(): bool
    {
        return $this !== self::PENDING && $this !== self::RESOLVING;
    }
}
