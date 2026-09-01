<?php

namespace App\Finance\Enums;

/**
 * What one delivery did. Every case answers HTTP 200 — the distinction is for the log, the tests
 * and step 7's discrepancy report, not for the provider.
 *
 * 200 FOR ALL OF THEM IS DELIBERATE. A webhook endpoint's status code is an instruction to the
 * provider about retrying, not a verdict on the payment. Paystack retries a non-2xx, so answering
 * 409 to a duplicate delivery — which is Paystack behaving exactly as documented — would ask it to
 * deliver the duplicate again, and again. The only thing that should refuse is a delivery that
 * failed its signature, which never reaches this enum.
 */
enum GatewaySettlementOutcome: string
{
    /** A payment was written and the transaction claimed. The only case that moves money. */
    case Settled = 'settled';

    /**
     * The transaction already carried a payment. A replayed or raced delivery — normal, and the
     * case the compare-and-swap exists to make harmless.
     */
    case AlreadySettled = 'already_settled';

    /**
     * The provider reported success without reporting its fee, so the net amount is unknowable.
     * §7's fifth case: the row stays `pending`, the delivery is on file, and the discrepancy report
     * is what surfaces it. Nothing is guessed.
     */
    case FeeNotReported = 'fee_not_reported';

    /**
     * The reported fee is at or above the amount charged, so there is nothing left to record as a
     * payment. Not expected from a healthy provider; recorded rather than clamped, because a zero
     * or negative payment written to an append-only table cannot be taken back.
     */
    case FeeExceedsAmount = 'fee_exceeds_amount';

    /**
     * The delivery was recorded but is not one this system settles on. The set of settling events is
     * NAMED rather than "anything that is not a failure": a future Paystack event type must arrive
     * with a deliberate decision about what it means instead of inheriting the success path.
     */
    case NotASettlementEvent = 'not_a_settlement_event';

    /**
     * The money arrived and could NOT be booked against its invoice — the invoice is void, or its
     * currency does not match. `RecordPayment` refuses both, and it is right to.
     *
     * THIS IS THE SERIOUS ONE. A payer has been charged and the system holds no payment. It answers
     * 200 because redelivery cannot fix it — the refusal is about our data, not about the delivery
     * — and it is logged at ERROR, left `pending`, and left for step 7's discrepancy report. It
     * must never be silently folded in with the ordinary outcomes.
     */
    case CouldNotBook = 'could_not_book';

    /**
     * The provider reported an amount or a currency that is not what this transaction asked for.
     *
     * Nothing is booked. The delivery is on file, the row stays `pending`, and it is logged at
     * ERROR — a provider charging a different amount from the one we initiated is either a defect
     * or a reference collision, and neither should be absorbed silently into a payer's balance.
     */
    case AmountMismatch = 'amount_mismatch';

    /**
     * The provider reported a NEGATIVE fee. Refused, because the alternative is crediting the
     * invoice MORE than the payer was charged, on a table no UPDATE can correct.
     */
    case FeeIsNegative = 'fee_is_negative';

    /**
     * The delivery was recorded, and `verify()` — the authority — could not be reached.
     *
     * **"We could not find out" is not "it failed."** Nothing is written, the status is not moved,
     * and the row stays `pending` for a later delivery, the return path, or step 7's report to
     * recover. This is §7's fifth failure row, decided on 2026-08-29 in
     * `docs/handoff/decisions/webhook-arrives-but-verify-is-unreachable.md` before the handler
     * existed — and unreachable in the shipped code until the verify call it depends on was
     * actually made.
     *
     * It answers 200 like everything else here: what failed was our call OUT, not the provider's
     * call in, so asking Paystack to deliver it again buys nothing.
     */
    case VerifyUnavailable = 'verify_unavailable';

    /**
     * The webhook announced a settlement and `verify()` did not agree the money is collected.
     *
     * THIS CASE IS THE REASON THE VERIFY CALL EXISTS. A signature proves the body came from the
     * holder of the secret; it says nothing about whether the contents match Paystack's own ledger.
     * Only asking Paystack directly can tell a genuine `charge.success` from a signed assertion that
     * one happened. Nothing is booked, the row stays `pending`, and it is logged at ERROR — a
     * notification the provider will not corroborate is either a defect or a forged body, and both
     * want a human.
     */
    case NotSuccessfulAtProvider = 'not_successful_at_provider';

    /** The transaction vanished between the lookup and the lock. Recorded, not settled. */
    case Unknown = 'unknown';
}
