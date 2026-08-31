<?php

namespace App\Finance\DTOs;

/**
 * WHERE TO SEND THE PAYER — the result of `/transaction/initialize`.
 *
 * `reference` IS ECHOED BACK RATHER THAN ASSUMED. We generate the reference and send it, so the
 * value returned should be the value we sent — and reading it from the RESPONSE rather than
 * remembering what we passed is what makes a divergence visible instead of silent. If Paystack ever
 * substitutes its own, the caller compares and finds out; a caller that reuses its local variable
 * never would, and would then poll a reference the provider does not know.
 */
final class PaystackCheckout
{
    public function __construct(
        /** The hosted checkout page to redirect the payer to. */
        public readonly string $authorizationUrl,
        /** Paystack's handle for the initialised transaction. */
        public readonly string $accessCode,
        /** OUR reference, as the provider echoed it. */
        public readonly string $reference,
    ) {}
}
