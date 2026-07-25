<?php

namespace App\Finance\Services;

use App\Finance\Enums\CreditNoteStatus;
use App\Finance\Models\CreditNote;
use App\Finance\Models\Invoice;
use App\Finance\Models\PaymentAllocation;

/**
 * Whether an invoice may be voided (Fork-2). Void reverses the WHOLE charge, so it is only
 * clean when nothing has settled against the invoice: no payment has been allocated to it,
 * and no approved credit note reduces it. Either would make a full-total reversal the wrong
 * number (a paid invoice would leave the payment stranded as credit; a partially-credited one
 * would double-count). Those cases are handled by their own instruments (refund / the credit),
 * not by void.
 *
 * Used twice: advisory at submit (a friendly message), and AUTHORITATIVE at approval under
 * the invoice-row lock — a payment can land between the two, so approval re-checks.
 */
final class VoidEligibility
{
    /** The reason this invoice cannot be voided, or null when it can. */
    public static function blocker(Invoice $invoice): ?string
    {
        if (PaymentAllocation::query()->where('invoice_id', $invoice->id)->exists()) {
            return 'This invoice has a payment allocated to it and cannot be voided — reverse or refund the payment instead.';
        }

        if (CreditNote::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', CreditNoteStatus::Approved->value)
            ->exists()
        ) {
            return 'This invoice has an approved credit note against it and cannot be voided.';
        }

        return null;
    }
}
