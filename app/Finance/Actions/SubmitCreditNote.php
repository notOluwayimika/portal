<?php

namespace App\Finance\Actions;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Finance\Approval\ApprovalRequirement;
use App\Finance\Approval\NotifiesApprovalCheckers;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Models\User;
use App\Support\Money;
use App\Support\SchoolContext;
use App\Support\Sequences\Sequences;
use Illuminate\Support\Facades\DB;

/**
 * Ph3 maker side — SUBMIT a credit note for approval. Creates the note in `submitted`
 * with `submitted_by = maker` and NOTHING else: no ledger entry, no balance change, no
 * ceiling consumed. It is a PROPOSAL. The money is forgiven only when a checker ≠ maker
 * approves it ({@see ApproveCreditNote}).
 *
 * The amount-positive and not-against-a-void-invoice guards stay here (fail fast at
 * submit). The over-credit ceiling deliberately does NOT run at submit — a pending
 * proposal consumes no ceiling; two proposals that individually fit but jointly exceed
 * the total both submit, and the SECOND to be approved is the one blocked (at approval).
 */
final class SubmitCreditNote
{
    use NotifiesApprovalCheckers;

    public function handle(Invoice $invoice, Money $amount, CreditNoteKind $kind, ?string $note, User $maker): CreditNote
    {
        // The INVOICE is the school-owned subject: the credit note does not exist yet and is stamped
        // from the context this call returns.
        // Rule 13: no context, no financial governance act (App\Support\SchoolContext).
        SchoolContext::assertOwns($invoice, 'invoice', 'credited');

        if ($amount->isZero() || $amount->isNegative()) {
            throw new BusinessRuleException('A credit note amount must be positive.');
        }

        if ($invoice->isVoid()) {
            throw new BusinessRuleException('Cannot submit a credit note against a void invoice.');
        }

        // A credit note must be in the invoice's currency. Refused here (422) so a mismatch is not the DB
        // insert_guard's 1644 → 500 (backstop-reachability audit); the trigger stays as the backstop.
        if ($amount->currency !== $invoice->total->currency) {
            throw new BusinessRuleException("A credit note must be in the invoice's currency ({$invoice->total->currency}).");
        }

        // The maker-checker seam (ADR 0051): today always requires a checker; when finance_approval_rules
        // lands, a straight-through row is built HERE. The other arm throws until that path is real and
        // tested — an unreachable half-implemented arm is worse than an honest marker.
        if (! ApprovalRequirement::for(Permission::FINANCE_CREDIT_NOTE_SUBMIT->value, $amount)->required) {
            throw new \LogicException('Straight-through submission is not implemented — see ADR 0051.');
        }

        $submitted = DB::transaction(function () use ($invoice, $amount, $kind, $note, $maker) {
            $number = Sequences::next('finance_credit_note', (string) $invoice->school_id);

            // Created directly in `submitted` — no ledger post. The DB insert_guard still
            // asserts the currency matches the invoice; the ceiling does not fire for a
            // `submitted` row.
            return CreditNote::create([
                'school_id' => $invoice->school_id,
                'student_id' => $invoice->student_id,
                'invoice_id' => $invoice->id,
                'number' => $number,
                'amount' => $amount,
                'kind' => $kind,
                'note' => $note,
                'status' => CreditNoteStatus::Submitted,
                'submitted_by' => $maker->id,
                'created_by_user_id' => $maker->id,
            ]);
        });

        // AFTER the commit, never inside it.
        $this->notifyApprovalCheckers(
            subject: $submitted,
            checkerAbility: Permission::FINANCE_CREDIT_NOTE_APPROVE->value,
            submittedBy: (int) $maker->id,
            // Every identifier here was verified against the schema rather than assumed.
            // The first draft used Money::format() and Invoice::$display_number — neither
            // exists; Larastan caught both, and the Finance suite caught the first as 30
            // red tests. Plausible-looking attribute names are exactly what static
            // analysis is for.
            summary: 'Credit note of '.$amount->currency.' '.$amount->toNaira()
                .' on invoice '.$invoice->number,
        );

        return $submitted;
    }
}
