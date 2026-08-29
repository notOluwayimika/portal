<?php

namespace App\Finance\Services;

use RuntimeException;

/**
 * IS THIS WEBHOOK ACTUALLY FROM PAYSTACK? The one authenticity check on the inbound gateway path.
 *
 * Paystack signs every webhook as `HMAC-SHA512(raw request body, secret key)` and sends the result
 * in the `x-paystack-signature` header. There is no other proof of origin: the endpoint has to be
 * publicly reachable for Paystack to POST to it, so **anyone on the internet can send it a
 * well-formed body claiming a payment succeeded.** This class is the entire difference between a
 * webhook and an open door onto the payments table.
 *
 * ── THREE THINGS THAT LOOK LIKE DETAILS AND ARE THE WHOLE MECHANISM ───────────────────────────────
 *
 * **1. THE RAW BODY, EXACTLY AS IT ARRIVED — never the re-encoded array.** The HMAC is over bytes.
 * `json_encode(json_decode($body, true))` is a DIFFERENT byte string from `$body` in ordinary cases,
 * not exotic ones: key order is preserved but whitespace is not, `/` becomes `\/`, unicode escapes
 * change, and floats re-render. So a verifier fed `$request->all()` re-encoded computes an HMAC over
 * something Paystack never signed and rejects every genuine webhook — or, far worse, someone
 * "fixes" that by loosening the check. The caller MUST pass `$request->getContent()`.
 *
 * **2. `hash_equals`, NEVER `===`.** A short-circuiting string comparison returns as soon as it hits
 * a differing byte, so the time it takes leaks the position of the first difference, and a signature
 * can be reconstructed one byte at a time by an attacker who can retry. This is the same reasoning
 * and the same function as the notifications module's callback signer, which learned it first.
 * (Named in prose, not as a `@see`: Pint rewrites a fully-qualified `@see` into a real import, and
 * an import of `App\Notifications\Services` from here fails the notifications privacy arch rule.)
 *
 * **3. AN EMPTY SECRET IS REFUSED, LOUDLY, AT CONSTRUCTION.** `hash_hmac` accepts an empty key
 * perfectly happily and returns a well-formed digest — so a misconfigured deployment would verify
 * signatures against a value an attacker can compute themselves, and every forged webhook would pass
 * while the logs showed successful verification. **That is strictly worse than not checking at all**,
 * because the failure is invisible and the system reports itself as authenticated. Misconfiguration
 * must stop the request, not silently downgrade it. Carried directly from the notifications
 * module's callback signer, which learned it first.
 *
 * WHAT THIS CLASS DOES NOT DO, so nobody assumes it: it does not prove the payload is TRUE, only
 * that it came from the holder of the secret. Whether the transaction really succeeded is answered
 * by calling `/transaction/verify` ({@see PaystackClient::verify()}) — a webhook is a
 * notification, not evidence, and the amount in it is not to be trusted as the amount to record.
 */
final class PaystackSignature
{
    /** Paystack's header. Lower-cased because HTTP header names are case-insensitive. */
    public const HEADER = 'x-paystack-signature';

    private const ALGORITHM = 'sha512';

    public function __construct(private readonly ?string $secret)
    {
        if ($this->secret === null || $this->secret === '') {
            throw new RuntimeException(
                'Paystack secret key is not configured (services.paystack.secret_key). Refusing to '
                .'verify webhooks with an empty key — an HMAC over a secret an attacker can guess '
                .'authenticates nothing while appearing to, which is worse than no check at all.'
            );
        }
    }

    /**
     * Compute the signature Paystack would have sent for this exact body.
     *
     * Public because the TESTS need it to produce genuine fixtures: a test that hard-codes a digest
     * asserts that a string equals itself, and would keep passing if the algorithm changed
     * underneath it.
     */
    public function expectedFor(string $rawBody): string
    {
        return hash_hmac(self::ALGORITHM, $rawBody, (string) $this->secret);
    }

    /**
     * @param  string  $rawBody  MUST be `$request->getContent()` — see the class docblock. Passing a
     *                           re-encoded array here breaks every genuine webhook.
     * @param  string|null  $signature  The `x-paystack-signature` header, absent as null.
     */
    public function verify(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            // A missing header is a failed verification, not an exception: an unsigned POST to a
            // public endpoint is the ordinary case of internet noise, and it must be refused
            // quietly rather than paging anyone.
            return false;
        }

        return hash_equals($this->expectedFor($rawBody), $signature);
    }
}
