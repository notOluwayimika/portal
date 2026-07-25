<?php

namespace App\Finance\Services;

use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\Invoice;
use App\Support\Money;

/**
 * Per-invoice SETTLEMENT — derived, never stored (fork 1: no is_paid column). Settlement has
 * three writers (payment allocation, credit-note approval, void approval); a stored flag from
 * three places is drift with a schedule, and it would put mutable money-adjacent state back on
 * the one table the module keeps money-immutable. So it is computed on read:
 *
 *   outstanding = total − Σ(allocations) − Σ(approved credit notes)
 *
 * Both sums are SQL aggregates the read model loads via withSum (`allocated_minor`,
 * `approved_credit_minor`); this class does only integer minor-unit arithmetic on them (never
 * float, never a JS-side sum — money-lint). A fresh invoice with neither aggregate loaded reads
 * both as 0 → fully unpaid, which is correct.
 *
 * TWO ORTHOGONAL AXES, never one badge (Decision 2): document state (Issued/Void) is
 * `$invoice->status`; SETTLEMENT state (unpaid / part_paid / settled) is derived here. A void
 * invoice has NO meaningful settlement state — its charge is reversed — so it returns null.
 *
 * ELIGIBILITY is the server's to own (the can_approve lesson): the UI renders these flags, it
 * never re-derives them by comparing amounts. Per-button (Decision 3):
 *   • record payment      — meaningless once settled → suppressed when settled or void;
 *   • submit credit note  — a real correction of a paid charge (pushes to credit), and the
 *                           designated instrument once void is ineligible → ALWAYS offered on an
 *                           issued invoice, settled or not;
 *   • request void        — refused by a RULE once money has settled → the flag is false with a
 *                           void_blocked_reason, so the UI disables-with-reason rather than hides.
 *
 * The void-blocked derivation reads the SAME sums (an allocation only exists with amount > 0, an
 * approved credit note only with amount > 0), so it agrees with VoidEligibility by construction
 * without an N+1 existence query per invoice.
 */
final class InvoiceSettlement
{
    /**
     * @return array{
     *   outstanding: Money,
     *   settlement_state: 'unpaid'|'part_paid'|'settled'|null,
     *   can_record_payment: bool,
     *   can_submit_credit_note: bool,
     *   can_request_void: bool,
     *   void_blocked_reason: string|null,
     * }
     */
    public function for(Invoice $invoice): array
    {
        $currency = $invoice->total->currency;
        $totalKobo = $invoice->total->toKobo();

        // The SQL aggregates the read model loads; absent (fresh invoice) → 0.
        $allocatedKobo = (int) ($invoice->getAttribute('allocated_minor') ?? 0);
        $approvedCreditKobo = (int) ($invoice->getAttribute('approved_credit_minor') ?? 0);

        // Signed outstanding (can go negative when a paid invoice is then credit-noted). The
        // DISPLAYED figure floors at zero; the account balance carries the true credit position.
        $outstandingKobo = $totalKobo - $allocatedKobo - $approvedCreditKobo;
        $displayedOutstanding = Money::fromKobo(max(0, $outstandingKobo), $currency);

        $isVoid = $invoice->status === InvoiceStatus::Void;
        $isIssued = $invoice->status === InvoiceStatus::Issued;

        // Settlement state — null for a void invoice (no meaningful settlement).
        $state = match (true) {
            $isVoid => null,
            $outstandingKobo <= 0 => 'settled',
            $allocatedKobo === 0 && $approvedCreditKobo === 0 => 'unpaid',
            default => 'part_paid',
        };

        // Void-blocked reason — same conditions as VoidEligibility, derived from the sums.
        $voidBlockedReason = match (true) {
            $allocatedKobo > 0 => 'This invoice has a payment allocated to it and cannot be voided — reverse or refund the payment instead.',
            $approvedCreditKobo > 0 => 'This invoice has an approved credit note against it and cannot be voided.',
            default => null,
        };

        return [
            'outstanding' => $displayedOutstanding,
            'settlement_state' => $state,
            // Suppress on a settled or void invoice; allocating more is meaningless (and the #94
            // guard makes it safe regardless — this is convenience, never the control).
            'can_record_payment' => $isIssued && $outstandingKobo > 0,
            // ALWAYS available on an issued invoice — a credit note against a paid charge is a
            // real operation, and the designated instrument once void is ineligible (Decision 3).
            'can_submit_credit_note' => $isIssued,
            // Refused by the eligibility rule once money has settled; the reason teaches it.
            'can_request_void' => $isIssued && $voidBlockedReason === null,
            'void_blocked_reason' => $isIssued ? $voidBlockedReason : null,
        ];
    }
}
