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

    /** The transaction vanished between the lookup and the lock. Recorded, not settled. */
    case Unknown = 'unknown';
}
