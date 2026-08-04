<?php

namespace App\Finance\Approval;

use App\Notifications\Contracts\Notifier;
use App\Notifications\Types\ApprovalRequested;
use App\Support\ActiveSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tell the checkers there is something to decide — from the ACTION, never a controller.
 *
 * ⚠️ WHY NOT THE CONTROLLER, WHICH IS WHERE THIS STARTED. The void dispatch lived in
 * VoidRequestController, so any submit that did NOT come through that controller
 * notified nobody: DriveFinanceStates calls the actions directly, and so would any
 * future job or command. A request moved to pending-approval in silence. Moving the
 * dispatch into the action makes "submitted" and "checkers told" the same event, and
 * retroactively fixes the console path.
 *
 * ⚠️ WHY NOT `ApprovalRequirement`, WHICH IS THE OTHER OBVIOUS SEAM. That is the
 * DECISION choke point — "does this need a second signature" — and it is the wrong
 * KIND: a pure, static, final-readonly factory called BEFORE the row exists, so it has
 * no subject to notify about. Putting I/O in it would also break the signature
 * stability its own docblock exists to defend. Decision seam and effect seam are
 * different places.
 *
 * ONE DEFINITION FOR FOUR CALLERS, because four hand-written dispatches is four
 * chances to forget one — which is the very gap this closes, reappearing a level down.
 * ApprovalCheckerNotificationTest enumerates every `Submit*` action and fails when one
 * does not use this, so a FIFTH family added later cannot ship silent.
 *
 * THE SUMMARY IS BUILT HERE AND STORED, not hydrated at read time. That diverges from
 * ResultReady deliberately: a pupil's name is PII that must not sit in a JSON column,
 * so it is resolved on read — whereas "credit note for ₦12,500 on invoice INV-0042" is
 * an IMMUTABLE FACT about a decision, exactly what `rendered_fallback` was added for.
 * It also survives the subject being deleted, and avoids four subject-shaped branches
 * in the hydrator to render four one-line strings.
 */
trait NotifiesApprovalCheckers
{
    /**
     * @param  string  $checkerAbility  the ability that DECIDES this — ApprovalAbility
     *                                  derives the matching maker from it by convention, so a new family
     *                                  needs no new notification type
     */
    protected function notifyApprovalCheckers(
        Model $subject,
        string $checkerAbility,
        int $submittedBy,
        string $summary,
    ): void {
        app(Notifier::class)->send(new ApprovalRequested(
            checkerAbility: $checkerAbility,
            subject: $subject,
            // The subject's OWN school, not the ambient context: an action can run
            // off-request (console, job) where ActiveSchool is whatever the caller
            // established, and the notification belongs to the row's tenant.
            schoolId: (int) ($subject->getAttribute('school_id') ?? ActiveSchool::getOrFail()->id),
            submittedBy: $submittedBy,
            summary: $summary,
        ));
    }
}
