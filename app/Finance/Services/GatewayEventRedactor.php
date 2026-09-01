<?php

namespace App\Finance\Services;

/**
 * Removes payment CREDENTIALS from a provider delivery before it is stored.
 *
 * `finance_gateway_transaction_events` is append-only and DELETE is denied on it, so anything
 * written there is written for good. Paystack's `charge.success` body carries, under
 * `data.authorization`:
 *
 *   · `authorization_code` — alongside `"reusable": true`. It is a token that can initiate a
 *     FUTURE charge against the payer's card without the payer present. It is a credential, not a
 *     record of a payment.
 *   · `signature` — a stable per-card fingerprint. It is not a charging credential, but it
 *     correlates a card across every school and every payer on this platform, which is a
 *     cross-school identifier in a system whose only isolation boundary is `school_id`.
 *
 * Neither is needed to reconcile a payment: the amount, the fee, the reference, the status and the
 * paid-at timestamp are what reconciliation reads, and all of them survive this.
 *
 * STRIPPED AT WRITE, NOT REDACTED LATER — and those are different operations with different
 * signals. `redacted_at` is retention redaction and means `payload IS NULL` (the events
 * biconditional enforces exactly that). This never sets it. A stripped row is a stored row.
 *
 * THE REMOVAL IS RECORDED, which is the half that is easy to skip. A payload with no
 * `authorization_code` and nothing saying why is indistinguishable from a payload the provider
 * never put one in — so a reader would take OUR removal as a FACT ABOUT THE PAYMENT. The returned
 * field list goes into `redacted_fields` so the absence is an act on the record rather than a
 * silence to be guessed at.
 *
 * DOT PATHS, NOT A RECURSIVE KEY SWEEP. A sweep for any key named `signature` anywhere in the body
 * would also strip a field that means something else at a path nobody examined, and would silently
 * widen every time Paystack adds one. The paths are named, so the set is the thing being
 * maintained and a new arrival is a visible one-line edit.
 */
final class GatewayEventRedactor
{
    /**
     * The paths removed from every stored delivery, as dot paths into the decoded body.
     *
     * ADDING TO THIS LIST IS SAFE; REMOVING FROM IT IS A DECISION. A path dropped here starts
     * persisting a credential on the next delivery, and the table it persists into cannot be
     * deleted from.
     */
    public const STRIPPED_PATHS = [
        'data.authorization.authorization_code',
        'data.authorization.signature',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: list<string>} the payload to store, and the paths
     *                                                         actually removed from it
     */
    public function strip(array $payload): array
    {
        $removed = [];

        foreach (self::STRIPPED_PATHS as $path) {
            if ($this->forget($payload, explode('.', $path))) {
                $removed[] = $path;
            }
        }

        return [$payload, $removed];
    }

    /**
     * Removes one dot path, reporting whether anything was actually there.
     *
     * The boolean is load-bearing rather than convenience: `redacted_fields` must list what was
     * REMOVED, not what was LOOKED FOR. Listing a path that was never present would assert that
     * the provider sent a credential it did not send — a false statement about the provider in the
     * one column whose job is to be trusted about absences.
     *
     * Written by hand rather than with `Arr::forget` because that helper reports nothing back.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     */
    private function forget(array &$node, array $segments): bool
    {
        $head = array_shift($segments);

        if (! array_key_exists($head, $node)) {
            return false;
        }

        if ($segments === []) {
            unset($node[$head]);

            return true;
        }

        if (! is_array($node[$head])) {
            return false;
        }

        return $this->forget($node[$head], $segments);
    }
}
