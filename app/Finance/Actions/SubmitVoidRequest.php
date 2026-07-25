<?php

namespace App\Finance\Actions;

use App\Exceptions\BusinessRuleException;
use App\Finance\Enums\VoidRequestStatus;
use App\Finance\Models\Invoice;
use App\Finance\Models\VoidRequest;
use App\Finance\Services\VoidEligibility;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ph3b maker side — SUBMIT a request to void an invoice, with a required reason. The invoice
 * is NOT touched: it stays 'issued', stays in the balance, and keeps occupying its F7 slot.
 * No money moves. Approval is what voids it ({@see ApproveVoidRequest}).
 *
 * The eligibility check here (no allocated payment, no approved credit note) is a HARD REFUSAL,
 * not advisory — and that is correct because BOTH conditions are MONOTONIC: an allocation and an
 * approved credit note are append-only/terminal, so once either lands the invoice can never
 * become voidable again. A monotonic precondition that only warns at submit would let a maker
 * persist a request guaranteed to fail — noise in the checker's queue that also burns the
 * invoice's single open-request slot (open_key) for nothing. So it refuses here, and the
 * approval re-checks under the lock only to catch a payment that lands in the submit→approve
 * window (the same monotonic condition, observed later). (Decision 5, settlement-state slice.)
 *
 * One open request per invoice: the friendly pre-check below covers the common case; the DB
 * generated-column UNIQUE (open_key) is the real guarantee against a concurrent double submit.
 */
final class SubmitVoidRequest
{
    public function handle(Invoice $invoice, string $reason, User $maker): VoidRequest
    {
        if (trim($reason) === '') {
            throw new BusinessRuleException('A reason is required to request a void.');
        }

        if ($invoice->isVoid()) {
            throw new BusinessRuleException('This invoice is already void.');
        }

        $blocker = VoidEligibility::blocker($invoice);
        if ($blocker !== null) {
            throw new BusinessRuleException($blocker);
        }

        if (VoidRequest::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', VoidRequestStatus::Submitted->value)
            ->exists()
        ) {
            throw new BusinessRuleException('A void request for this invoice is already awaiting approval.');
        }

        return DB::transaction(fn () => VoidRequest::create([
            'school_id' => $invoice->school_id,
            'invoice_id' => $invoice->id,
            'reason' => trim($reason),
            'status' => VoidRequestStatus::Submitted,
            'submitted_by' => $maker->id,
        ]));
    }
}
