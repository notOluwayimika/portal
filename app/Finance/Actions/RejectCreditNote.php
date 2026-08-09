<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Models\CreditNote;
use App\Models\User;
use App\Support\SchoolContext;

/**
 * Ph3 checker side — REJECT a pending credit note with a reason. NEVER any ledger effect:
 * a rejected proposal was never in the ledger and never will be. The row stays for audit
 * (paper-trail philosophy, §3 VOID) in a TERMINAL `rejected` state — the maker submits a
 * fresh note rather than resurrecting this one.
 *
 * A reason is required at the domain layer (CreditNote::transitionTo throws on an empty
 * reason), so no path can persist a reasonless rejection. Maker ≠ checker holds the same
 * three ways as approval (Policy / this guard / DB CHECK).
 */
final class RejectCreditNote
{
    public function handle(CreditNote $creditNote, User $checker, string $reason): CreditNote
    {
        // Rule 13: no context, no financial governance act (App\Support\SchoolContext).
        SchoolContext::assertOwns($creditNote, 'credit note', 'rejected');

        if (! $creditNote->isPending()) {
            throw new BusinessRuleException('Only a pending credit note can be rejected.');
        }

        if ((string) $creditNote->submitted_by === (string) $checker->id) {
            throw new BusinessRuleException('A credit note cannot be rejected by its submitter (maker ≠ checker).');
        }

        // transitionTo records decided_by = checker (DB CHECK enforces ≠ maker), stamps
        // decided_at, and refuses an empty reason. No ledger post, ever.
        $creditNote->transitionTo(CreditNoteStatus::Rejected, (int) $checker->id, $reason);

        return $creditNote->refresh();
    }
}
