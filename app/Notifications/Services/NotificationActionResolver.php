<?php

namespace App\Notifications\Services;

use App\Notifications\Contracts\CallbackTransport;
use App\Notifications\Enums\NotificationActionStatus;
use App\Notifications\Exceptions\CallbackUnconfirmed;
use App\Notifications\Models\NotificationAction;
use Illuminate\Support\Facades\DB;

/**
 * Exactly-once resolution of a tapped action.
 *
 * THE CLAIM IS ONE CONDITIONAL UPDATE, AND THAT IS THE ENTIRE CONCURRENCY DESIGN:
 *
 *     UPDATE notification_actions
 *        SET status = 'resolving', resolved_by = ?
 *      WHERE id = ? AND status = 'pending' AND expires_at > ?
 *
 * The affected-row count IS the answer to "did I win". No SELECT precedes it, no
 * transaction wraps it, no lock is taken. Two co-guardians tapping the same action in
 * the same millisecond both reach the database; MySQL serialises the two updates on
 * the row; the first matches `status = 'pending'` and the second does not. One winner,
 * decided by the storage engine rather than by application logic.
 *
 * WHY NOT A READ-THEN-WRITE, WHICH IS THE OBVIOUS SHAPE. `if ($action->isClaimable())
 * { $action->update(...); }` has a window between the check and the write in which
 * another request can do both. It passes every single-threaded test and fires the
 * callback twice under real concurrency — meaning a pickup revoked twice, or worse,
 * two conflicting decisions relayed to a service that assumed one. The model exposes
 * `isClaimable()` for RENDERING only, and this class deliberately does not call it.
 *
 * WHY NOT A TRANSACTION AROUND THE CALLBACK. The relay is a synchronous HTTP call
 * that can take up to ten seconds; holding a row lock across it would serialise every
 * tap in the school behind one slow external service, and a transaction that rolls
 * back after the callback has already been delivered would erase the record of
 * something that really happened. The claim commits first, alone; the outcome is
 * written after.
 *
 * WHY THE CLAIM WRITES `resolved_by` IMMEDIATELY rather than after the callback: a
 * process that dies mid-relay must leave a row that says who was acting, or the
 * reconciliation pass has a claimed action with no claimant and no way to attribute
 * it.
 */
class NotificationActionResolver
{
    public function __construct(private readonly CallbackTransport $transport) {}

    /**
     * Resolve a tap. Returns the action in its resulting state.
     *
     * NEVER THROWS FOR A LOSING CLAIM. Losing is a normal outcome — the other parent
     * tapped first, or the window closed — and the caller's job is to render the
     * current state, not to handle an exception. The only thing this surfaces as a
     * terminal state rather than a result is the callback timeout, which becomes
     * UNCONFIRMED.
     */
    public function resolve(NotificationAction $action, int $userId): NotificationAction
    {
        $claimed = DB::table('notification_actions')
            ->where('id', $action->id)
            ->where('status', NotificationActionStatus::PENDING->value)
            ->where('expires_at', '>', now())
            ->update([
                'status' => NotificationActionStatus::RESOLVING->value,
                'resolved_by' => $userId,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return $this->settleLostClaim($action);
        }

        return $this->relay($action->refresh(), $userId);
    }

    /**
     * We did not win. Say why, truthfully.
     *
     * Two distinct reasons produce the same zero: somebody else claimed it, or the
     * window closed with nobody claiming it. Re-reading is what separates them, and
     * the distinction matters to the person tapping — "your co-parent already handled
     * this" and "you were too late" are different pieces of news.
     */
    private function settleLostClaim(NotificationAction $action): NotificationAction
    {
        $action->refresh();

        // Somebody else got there first, or it had already settled. Return their
        // result as-is; a second tap is a request for the current state, not an error.
        if ($action->status !== NotificationActionStatus::PENDING) {
            return $action;
        }

        // Still PENDING but unclaimable means the clock beat everyone. Record it, so
        // the expiry is a fact in the row rather than something every reader has to
        // re-derive from `expires_at` — and so the audit shows an action nobody took.
        //
        // Conditional on the status again: between the failed claim above and this
        // write, a competing request could have won. Writing EXPIRED unconditionally
        // would overwrite a legitimate resolution with a lie.
        DB::table('notification_actions')
            ->where('id', $action->id)
            ->where('status', NotificationActionStatus::PENDING->value)
            ->update([
                'status' => NotificationActionStatus::EXPIRED->value,
                'updated_at' => now(),
            ]);

        return $action->refresh();
    }

    /**
     * Relay the claimed action and persist the terminal state.
     *
     * The claim is already committed, so a failure here cannot un-claim the row —
     * which is correct: the tap DID happen, and the record of who made it must
     * survive whatever the external service does or fails to do.
     */
    private function relay(NotificationAction $action, int $userId): NotificationAction
    {
        try {
            $result = $this->transport->send($action);
        } catch (CallbackUnconfirmed $e) {
            // Genuinely unknown, and recorded as such. NOT retried: the request may
            // have landed, and a retry would be a second revocation attempt.
            $action->forceFill([
                'status' => NotificationActionStatus::UNCONFIRMED,
                'resolved_by' => $userId,
                'resolved_at' => now(),
                'last_error' => $e->getMessage(),
            ])->save();

            return $action;
        }

        $action->forceFill([
            'status' => $result->status,
            'outcome' => $result->outcome,
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'last_error' => null,
        ])->save();

        return $action;
    }
}
