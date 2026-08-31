<?php

/*
 * THE TWO CALLS TO PAYSTACK, against RECORDED FIXTURES.
 *
 * ── NO LIVE CREDENTIAL IS USED OR NEEDED, AND THAT IS A CONSTRAINT RATHER THAN A CONVENIENCE ─────
 *
 * The sandbox keys live in `.env` and nothing here reads them. Every response below is a fixture,
 * served through `Http::fake()`. A suite that reaches the internet fails for reasons that have
 * nothing to do with the change under test, leaks a credential into CI output the first time
 * somebody dumps a request, and cannot exercise the failure modes that matter — you cannot ask
 * Paystack to time out on demand.
 *
 * ── WHAT `Http::fake()` CANNOT PROVE, STATED RATHER THAN ASSUMED AWAY ────────────────────────────
 *
 * This project has been bitten by trusting a Laravel fake: `BusFake::batch()` skips
 * `ensureJobIsBatchable()`, so a fully-faked suite was green about the FAKE while `--commit` had
 * never once worked. The same caution applies here, so the residual is named:
 *
 *   · **The fake does not speak HTTP.** TLS, redirects, chunked encoding, proxies and Paystack's
 *     real error pages are all absent. A body that is not JSON is simulated here; a WAF block in
 *     front of the real host is not.
 *   · **The fixtures are my transcription of Paystack's documented shape**, not captures from the
 *     live API. If their response drifts, these stay green. The first real sandbox call is what
 *     tests that, and it belongs to the step that has an endpoint to call it from.
 *   · **`Http::fake()` does not enforce the timeout.** The `ConnectionException` arm below proves
 *     the HANDLING is right, not that 15 seconds is the number that elapses.
 *
 * What it DOES prove is everything this class actually decides: which outcome each status maps to,
 * that an ambiguous result is never reported as a definite one, that money crosses in minor units
 * without conversion, and that an absent fee stays null rather than becoming zero.
 */

use App\Finance\DTOs\PaystackCheckout;
use App\Finance\DTOs\PaystackTransaction;
use App\Finance\Exceptions\PaystackUnavailable;
use App\Finance\Services\PaystackClient;
use App\Finance\Services\PaystackSignature;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const PC_BASE = 'https://api.paystack.test';

function pcClient(): PaystackClient
{
    return new PaystackClient('sk_test_not_a_real_key', PC_BASE);
}

/** Paystack's documented verify shape, trimmed to the fields this client reads. */
function pcVerifyBody(array $over = []): array
{
    return ['status' => true, 'message' => 'Verification successful', 'data' => array_merge([
        'id' => 302961,
        'status' => 'success',
        'reference' => 'REF-ABC-123',
        'amount' => 500000,          // kobo — the provider's minor units ARE ours
        'currency' => 'NGN',
        'fees' => 7500,
        'paid_at' => '2026-08-29T10:15:00.000Z',
        'gateway_response' => 'Successful',
        'channel' => 'card',
    ], $over)];
}

// ── initialize ───────────────────────────────────────────────────────────────────────────────────

it('initialises a checkout and returns where to send the payer', function () {
    Http::fake([PC_BASE.'/transaction/initialize' => Http::response([
        'status' => true,
        'data' => [
            'authorization_url' => 'https://checkout.paystack.com/abc123',
            'access_code' => 'abc123',
            'reference' => 'REF-ABC-123',
        ],
    ], 200)]);

    $checkout = pcClient()->initialize(
        Money::fromKobo(500000),
        'REF-ABC-123',
        'payer@example.test',
        'https://portal.test/return',
    );

    expect($checkout)->toBeInstanceOf(PaystackCheckout::class)
        ->and($checkout->authorizationUrl)->toBe('https://checkout.paystack.com/abc123')
        ->and($checkout->reference)->toBe('REF-ABC-123');
});

it('sends the amount in MINOR UNITS, unconverted, with our reference and currency', function () {
    // The money property that matters: kobo in, kobo out, no float and no division anywhere. A
    // client that sent naira would under-charge by 100x and the fixture would still look plausible.
    Http::fake([PC_BASE.'/*' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'u', 'access_code' => 'a', 'reference' => 'REF-1'],
    ], 200)]);

    pcClient()->initialize(Money::fromKobo(123456), 'REF-1', 'payer@example.test');

    Http::assertSent(function ($request) {
        return $request['amount'] === '123456'
            && $request['currency'] === 'NGN'
            && $request['reference'] === 'REF-1'
            && $request['email'] === 'payer@example.test'
            // Omitted rather than sent null — Paystack falls back to the dashboard callback.
            && ! array_key_exists('callback_url', $request->data());
    });
});

it('authenticates with the secret as a bearer token', function () {
    Http::fake([PC_BASE.'/*' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'u', 'access_code' => 'a', 'reference' => 'R'],
    ], 200)]);

    pcClient()->initialize(Money::fromKobo(100), 'R', 'p@example.test');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_not_a_real_key'));
});

// ── verify: the authority ────────────────────────────────────────────────────────────────────────

it('reads a successful verification into the shape the caller records from', function () {
    Http::fake([PC_BASE.'/transaction/verify/*' => Http::response(pcVerifyBody(), 200)]);

    $txn = pcClient()->verify('REF-ABC-123');

    expect($txn)->toBeInstanceOf(PaystackTransaction::class)
        ->and($txn->isSuccessful())->toBeTrue()
        ->and($txn->reference)->toBe('REF-ABC-123')
        ->and($txn->providerReference)->toBe('302961')
        ->and($txn->amount->toKobo())->toBe(500000)
        ->and($txn->amount->currency)->toBe('NGN')
        ->and($txn->fee?->toKobo())->toBe(7500)
        ->and($txn->paidAt?->toDateString())->toBe('2026-08-29')
        ->and($txn->channel)->toBe('card');
});

it('treats an ABSENT fee as "not reported yet", never as zero', function () {
    // Settlement is a later event, and `finance_gateway_transactions.fee_minor` is write-once — so a
    // null that became 0 here would be permanently wrong and unfixable, and would silently claim the
    // provider charged nothing.
    Http::fake([PC_BASE.'/transaction/verify/*' => Http::response(pcVerifyBody(['fees' => null]), 200)]);

    expect(pcClient()->verify('REF-ABC-123')->fee)->toBeNull();
});

it('does not translate the provider vocabulary — an unknown status is NOT success', function (string $status) {
    // The safe direction: an unmodelled state leaves the attempt open and re-checkable. Treated as
    // success it would write an irreversible payment row against a transaction nobody understands.
    //
    // A DATASET, NOT A `foreach`, AND THE FIRST DRAFT WAS THE LOOP. `Http::fake()` ACCUMULATES stubs
    // and the FIRST match wins, so re-faking the same URL inside a loop does nothing after the first
    // iteration: all six passes were really testing `failed` six times. It surfaced only because
    // this arm asserts `status` is echoed through unchanged — had it checked `isSuccessful()` alone,
    // every iteration would have passed for the same wrong reason and the test would have read as
    // covering six states while covering one. A dataset gives each case its own fake and its own
    // named failure.
    Http::fake([PC_BASE.'/transaction/verify/*' => Http::response(pcVerifyBody(['status' => $status]), 200)]);

    $txn = pcClient()->verify('REF-ABC-123');

    expect($txn->status)->toBe($status)
        ->and($txn->isSuccessful())->toBeFalse();
})->with(['failed', 'abandoned', 'reversed', 'ongoing', 'Success', 'SUCCESS']);

// ── the three outcomes: the property this class exists to get right ──────────────────────────────

it('a 5xx is UNAVAILABLE, not a failure — we could not find out', function () {
    // Collapsing this into "failed" marks a paid parent's attempt failed and leaves their money
    // visible only on a bank statement. The exception is what forces the caller to handle it.
    //
    // THE BODY IS DELIBERATELY WELL-FORMED JSON, and the first draft's was not. With a plain-text
    // 502 body this arm passed via the UNREADABLE-BODY branch instead — so deleting the `>= 500`
    // check entirely left it green, which a mutation proved. Two branches collapsed onto one
    // fixture, and the test's name claimed the one it was not exercising. A valid JSON body makes
    // the status the ONLY thing that can produce the refusal.
    Http::fake([PC_BASE.'/transaction/verify/*' => Http::response(
        ['status' => false, 'message' => 'Server error'], 502,
    )]);

    expect(fn () => pcClient()->verify('REF-ABC-123'))
        ->toThrow(PaystackUnavailable::class);
});

it('a connection failure is UNAVAILABLE', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    expect(fn () => pcClient()->verify('REF-ABC-123'))
        ->toThrow(PaystackUnavailable::class);
});

it('an unreadable body is UNAVAILABLE — a WAF page is not a verdict', function () {
    Http::fake([PC_BASE.'/transaction/verify/*' => Http::response('<html>Access denied</html>', 200)]);

    expect(fn () => pcClient()->verify('REF-ABC-123'))
        ->toThrow(PaystackUnavailable::class);
});

it('a 200 missing the fields we read is UNAVAILABLE, not a silent default', function () {
    Http::fake([PC_BASE.'/transaction/verify/*' => Http::response(['status' => true, 'data' => []], 200)]);

    expect(fn () => pcClient()->verify('REF-ABC-123'))
        ->toThrow(PaystackUnavailable::class);
});

it('a 4xx is a REFUSAL, not unavailability — they answered and said no', function () {
    // The other side of the split, and the arm that keeps our own malformed requests from hiding
    // behind "the provider is down". A 4xx is a bug on our side and must read as one.
    Http::fake([PC_BASE.'/transaction/verify/*' => Http::response([
        'status' => false, 'message' => 'Invalid reference',
    ], 400)]);

    expect(fn () => pcClient()->verify('REF-ABC-123'))
        ->toThrow(RuntimeException::class, 'Paystack refused')
        // And specifically NOT the ambiguous one — asserted because PaystackUnavailable IS a
        // RuntimeException, so the arm above would pass on either.
        ->and(fn () => pcClient()->verify('REF-ABC-123'))
        ->not->toThrow(PaystackUnavailable::class);
});

// ── misconfiguration ─────────────────────────────────────────────────────────────────────────────

it('REFUSES TO EXIST without a secret, rather than 401ing as if the provider were down', function () {
    expect(fn () => new PaystackClient(null, PC_BASE))
        ->toThrow(RuntimeException::class, 'Refusing to call the gateway unauthenticated')
        ->and(fn () => new PaystackClient('', PC_BASE))
        ->toThrow(RuntimeException::class);
});

// ── the container wiring ─────────────────────────────────────────────────────────────────────────

it('resolves from the container when a key is configured', function () {
    // THE BINDING IS NOT COVERED BY ANY ARM ABOVE — every one of them constructs the client directly.
    // It also MOVED during this change (out of AppServiceProvider, into FinanceServiceProvider, because
    // App\Finance\Services is private to the module), and a binding that resolves nowhere would not be
    // discovered until step 4 tried to inject it.
    config(['services.paystack.secret_key' => 'sk_test_container', 'services.paystack.base_url' => PC_BASE]);

    expect(app(PaystackClient::class))->toBeInstanceOf(PaystackClient::class)
        ->and(app(PaystackSignature::class))
        ->toBeInstanceOf(PaystackSignature::class);
});

it('FAILS TO RESOLVE when no key is configured, rather than handing back a broken client', function () {
    // The deployment-misconfiguration path, end to end through the container. It must throw here —
    // where the message names the config key — and not later as a 401 that reads like a provider
    // outage, or as an HMAC over an empty secret that authenticates forged webhooks.
    config(['services.paystack.secret_key' => null]);

    expect(fn () => app(PaystackClient::class))->toThrow(RuntimeException::class)
        ->and(fn () => app(PaystackSignature::class))->toThrow(RuntimeException::class);
});
