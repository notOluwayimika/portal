<?php

namespace App\Finance\Services;

use App\Finance\DTOs\PaystackCheckout;
use App\Finance\DTOs\PaystackTransaction;
use App\Finance\Exceptions\PaystackUnavailable;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * THE TWO CALLS THIS SYSTEM MAKES TO PAYSTACK — start a checkout, and ask what happened.
 *
 * It is an HTTP wrapper and nothing more: no database, no domain rules, no decision about what a
 * result means. Deciding is the caller's (§6 steps 4-6), and keeping the decision out of here is
 * what lets every branch of it be tested without a network.
 *
 * ── WHY `verify()` IS THE AUTHORITY AND THE WEBHOOK IS NOT ───────────────────────────────────────
 *
 * The webhook endpoint is public — it has to be, for Paystack to POST to it. A signature check
 * ({@see PaystackSignature}) proves a body came from the secret-holder, and that is all it proves.
 * The AMOUNT, the STATUS and the CURRENCY inside a webhook are still just numbers in a request. So
 * the flow is: signature-check the webhook, then **call `verify()` and record from THAT**. A handler
 * that writes a payment from webhook contents is trusting the wire for the one field that becomes an
 * irreversible money row.
 *
 * ── THREE OUTCOMES, NOT TWO ──────────────────────────────────────────────────────────────────────
 *
 * `verify()` returns a transaction when Paystack answered, and throws {@see PaystackUnavailable}
 * when it did not. **"We could not find out" is not "it failed"** — collapsing them marks a paid
 * parent's attempt as failed and leaves their money visible only on a bank statement. This mirrors
 * the notifications module's `CallbackUnconfirmed`, which learned the same shape on the outbound
 * path: an ambiguous outcome reported as a definite one is worse than an error.
 *
 * AND IT CREATES A CASE THE WEBHOOK HANDLER MUST ANSWER, decided in advance rather than discovered
 * half-way through writing it: a genuine, correctly-signed webhook arrives and `verify()` is
 * unreachable. Recording from the webhook body is forbidden (no truth), a 4xx or 5xx makes the
 * provider retry harder, and dropping it loses money nobody will look for again. The answer —
 * acknowledge 200, persist the delivery, leave the transaction `pending`, let verify-on-return or
 * the discrepancy report recover it — is written up with its reasoning in
 * `docs/handoff/decisions/webhook-arrives-but-verify-is-unreachable.md`, and belongs in §7's failure
 * table as a fifth row.
 *
 * ── IT DOES NOT RETRY ────────────────────────────────────────────────────────────────────────────
 *
 * `verify()` is safe to retry (it is a GET and changes nothing), but retrying HERE would hide the
 * ambiguity from the caller and blow the caller's own time budget. `initialize()` must not be
 * retried blind at all — a second initialise is a second checkout. Retry policy belongs to whoever
 * knows the context; this class reports and stops.
 *
 * ── NO CREDENTIAL IS READ IN A TEST ──────────────────────────────────────────────────────────────
 *
 * Everything here is exercised against recorded fixtures through `Http::fake()`. Nothing in this
 * step needs a live sandbox key, and the tests must never acquire one — a suite that reaches the
 * internet is a suite that fails for reasons that have nothing to do with the change. What
 * `Http::fake()` therefore CANNOT prove is stated in the test file rather than assumed away.
 */
final class PaystackClient
{
    /**
     * The payer is waiting on `initialize()` — this is a redirect they are staring at — so the
     * ceiling is a product constraint rather than a network default. `verify()` inherits it because
     * the return path is equally interactive.
     */
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly ?string $secretKey,
        private readonly string $baseUrl,
    ) {
        if ($this->secretKey === null || $this->secretKey === '') {
            // Same refusal as PaystackSignature, for the outbound half: a client that sends
            // `Authorization: Bearer ` gets a 401 from Paystack and reports it as a gateway failure,
            // which reads as "the provider is down" rather than "we are misconfigured". Fail where
            // the cause is legible.
            throw new RuntimeException(
                'Paystack secret key is not configured (services.paystack.secret_key). Refusing to '
                .'call the gateway unauthenticated — the 401 would surface as a provider outage.'
            );
        }
    }

    /**
     * Start a checkout and get the URL to send the payer to.
     *
     * @param  Money  $amount  Sent in the provider's minor units, which are ours — no conversion.
     * @param  string  $reference  OUR reference. Must be the one stored on the attempt row, because
     *                             it is the only key tying every later webhook and verify back to it.
     * @param  string  $email  Paystack requires a payer email; it is the receipt address.
     * @param  string|null  $callbackUrl  Where Paystack returns the payer. Null uses the dashboard's.
     *
     * @throws PaystackUnavailable when we could not tell whether the call landed.
     * @throws RuntimeException when Paystack answered and refused.
     */
    public function initialize(
        Money $amount,
        string $reference,
        string $email,
        ?string $callbackUrl = null,
    ): PaystackCheckout {
        $payload = array_filter([
            'amount' => (string) $amount->toKobo(),
            'currency' => $amount->currency,
            'reference' => $reference,
            'email' => $email,
            'callback_url' => $callbackUrl,
        ], fn ($value) => $value !== null);

        $body = $this->post('/transaction/initialize', $payload);
        $data = $body['data'] ?? null;

        if (! is_array($data)
            || ! isset($data['authorization_url'], $data['access_code'], $data['reference'])) {
            // A 200 whose shape we cannot read is NOT a success we can act on: we would have no URL
            // to send the payer to and no confirmation the reference is live. Ambiguous, so it
            // throws the ambiguous exception rather than a refusal.
            throw new PaystackUnavailable(
                'Paystack initialize returned a body without authorization_url/access_code/reference.'
            );
        }

        return new PaystackCheckout(
            authorizationUrl: (string) $data['authorization_url'],
            accessCode: (string) $data['access_code'],
            reference: (string) $data['reference'],
        );
    }

    /**
     * Ask Paystack what actually happened to a reference. THE AUTHORITY — see the class docblock.
     *
     * @throws PaystackUnavailable when Paystack did not answer, or answered unreadably.
     */
    public function verify(string $reference): PaystackTransaction
    {
        $body = $this->get('/transaction/verify/'.rawurlencode($reference));
        $data = $body['data'] ?? null;

        if (! is_array($data) || ! isset($data['status'], $data['reference'], $data['amount'])) {
            throw new PaystackUnavailable(
                'Paystack verify returned a body without status/reference/amount.'
            );
        }

        // The currency goes through Money's constructor, which refuses anything failing ^[A-Z]{3}$.
        // Malformed provider data is stopped at the edge rather than reaching a money column.
        $currency = is_string($data['currency'] ?? null) && $data['currency'] !== ''
            ? $data['currency']
            : Money::DEFAULT_CURRENCY;

        return new PaystackTransaction(
            status: (string) $data['status'],
            reference: (string) $data['reference'],
            providerReference: isset($data['id']) ? (string) $data['id'] : null,
            amount: Money::fromKobo((int) $data['amount'], $currency),
            // `fees` absent means NOT REPORTED YET (settlement is a later event), never zero — and
            // the column it lands in is write-once, so the distinction has to survive to the caller.
            // `isset()` is already false for a null `fees`, so the extra `!== null` was dead — and
            // Larastan said so. Absent AND explicit-null both mean NOT REPORTED YET.
            fee: isset($data['fees'])
                ? Money::fromKobo((int) $data['fees'], $currency)
                : null,
            paidAt: $this->instant($data['paid_at'] ?? null),
            gatewayResponse: isset($data['gateway_response']) ? (string) $data['gateway_response'] : null,
            channel: isset($data['channel']) ? (string) $data['channel'] : null,
        );
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->request()->post($this->baseUrl.$path, $payload);
        } catch (ConnectionException $e) {
            throw new PaystackUnavailable('Paystack unreachable: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new PaystackUnavailable('Paystack call failed: '.$e->getMessage(), previous: $e);
        }

        return $this->readBody($response->status(), $response->body(), $path);
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        try {
            $response = $this->request()->get($this->baseUrl.$path);
        } catch (ConnectionException $e) {
            throw new PaystackUnavailable('Paystack unreachable: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new PaystackUnavailable('Paystack call failed: '.$e->getMessage(), previous: $e);
        }

        return $this->readBody($response->status(), $response->body(), $path);
    }

    private function request(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::TIMEOUT_SECONDS)
            ->withToken((string) $this->secretKey)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Turn a status + body into either an array or the right exception.
     *
     * THE 5xx / 4xx SPLIT IS THE POINT. A 5xx is Paystack failing to answer — ambiguous, so
     * `PaystackUnavailable`. A 4xx is Paystack ANSWERING and declining: it understood us and said no,
     * which is a definite result and a bug on our side (bad reference, bad amount, bad key), so it
     * raises a plain RuntimeException carrying their message. Reporting a 4xx as "unavailable" would
     * hide our own malformed request behind "the provider is down".
     *
     * @return array<string, mixed>
     */
    private function readBody(int $status, string $raw, string $path): array
    {
        if ($status >= 500) {
            throw new PaystackUnavailable("Paystack returned {$status} for {$path}.");
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            // Not JSON. A proxy error page, a WAF block, an empty body — we do not know what
            // happened to the request, which is the definition of the ambiguous outcome.
            throw new PaystackUnavailable("Paystack returned an unreadable body for {$path}.");
        }

        if ($status >= 400) {
            $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'no message';

            throw new RuntimeException("Paystack refused {$path} with {$status}: {$message}");
        }

        return $decoded;
    }

    /**
     * Paystack sends ISO-8601. An unparseable or absent instant is null rather than "now" — guessing
     * would put a made-up date on the column `finance_payments.received_at` is taken from, and that
     * column is append-only.
     */
    private function instant(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
