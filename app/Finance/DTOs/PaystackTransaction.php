<?php

namespace App\Finance\DTOs;

use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * WHAT PAYSTACK SAYS ABOUT ONE TRANSACTION, read from `/transaction/verify/{reference}`.
 *
 * IT IS THE ANSWER FROM THE VERIFY CALL, NOT FROM THE WEBHOOK, and that distinction is the reason
 * this type exists rather than an array off the webhook body. A webhook is a NOTIFICATION: it is
 * unauthenticated until its signature is checked, it can be replayed, and its `amount` is a number an
 * attacker would love us to trust. The verify endpoint is us asking Paystack directly over TLS with
 * our secret. **The webhook says "look again"; this says what is true.** Nothing may record a payment
 * from webhook contents alone.
 *
 * MONEY IS A `Money`, BUILT FROM THE PROVIDER'S OWN MINOR UNITS. Paystack works in kobo and this
 * system works in kobo, so the integer crosses unchanged — no conversion, no float, nowhere for a
 * rounding decision to hide. The currency comes from the response and goes through `Money`'s
 * constructor, which refuses anything failing `^[A-Z]{3}$`, so a malformed currency is refused at the
 * edge rather than reaching a money column.
 *
 * `fee` IS WHAT THE PROVIDER KEPT and is nullable because it is not always present at verify time —
 * it is settled later. `finance_gateway_transactions.fee_minor` is write-once, so the null here means
 * "not reported yet" and never "zero".
 */
final class PaystackTransaction
{
    /**
     * @param  string  $status  Paystack's own vocabulary, unchanged: `success`, `failed`,
     *                          `abandoned`, `reversed`, `ongoing`, `queued`… Deliberately NOT mapped
     *                          onto our enum here. Translating at the boundary would put the mapping
     *                          in two places the day a state we do not model arrives; the caller maps
     *                          explicitly and must decide what an unrecognised value means.
     * @param  string  $reference  OURS — the reference we generated and sent, echoed back.
     * @param  string|null  $providerReference  THEIRS — Paystack's own transaction id, as a string.
     * @param  Money  $amount  What was actually collected, in the provider's minor units.
     * @param  Money|null  $fee  What the provider kept, when it is known yet.
     * @param  Carbon|null  $paidAt  The provider's instant of payment — the value
     *                               `finance_payments.received_at` is to be taken from, because that
     *                               column is append-only and Friday's money is Friday's.
     * @param  string|null  $gatewayResponse  The provider's own words on a failure, for the bursar.
     * @param  string|null  $channel  `card`, `bank_transfer`, … Informational.
     */
    public function __construct(
        public readonly string $status,
        public readonly string $reference,
        public readonly ?string $providerReference,
        public readonly Money $amount,
        public readonly ?Money $fee,
        public readonly ?Carbon $paidAt,
        public readonly ?string $gatewayResponse,
        public readonly ?string $channel,
    ) {}

    /**
     * Did the provider say the money is collected?
     *
     * A STRICT, CASE-SENSITIVE MATCH ON ONE VALUE — an allowlist of exactly one. Anything else,
     * including a state this system does not model, is NOT success. That direction is the safe one:
     * an unrecognised state treated as "not yet settled" leaves the attempt open and re-checkable,
     * while treated as success it writes an irreversible payment row against a transaction nobody
     * understands.
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }
}
