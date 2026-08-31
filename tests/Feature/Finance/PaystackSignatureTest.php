<?php

/*
 * THE WEBHOOK AUTHENTICITY CHECK — the only thing standing between a public endpoint and an open
 * door onto the payments table.
 *
 * NO NETWORK, NO CREDENTIAL, NO DOUBLE. This is pure computation, so every arm below runs against
 * the real class with a made-up secret. There is nothing here a fake could misrepresent, which makes
 * it the one part of the gateway client that can be proven outright rather than merely exercised.
 *
 * THE FIXTURES ARE GENERATED, NOT TRANSCRIBED. A test that hard-codes a digest asserts that a string
 * equals itself: change `sha512` to `sha256` in the class and a hard-coded expectation goes red for
 * the right reason, but change the SIGNED MATERIAL and it goes red too while telling you nothing
 * about which. Every expectation below is computed through the class's own `expectedFor()`, and the
 * algorithm itself is pinned separately, once, against an independent `hash_hmac` call.
 */

use App\Finance\Services\PaystackSignature;

const PS_SECRET = 'sk_test_not_a_real_key_0123456789';

/** A body with the shapes that break naive verifiers: a slash, unicode, and nested JSON. */
const PS_BODY = '{"event":"charge.success","data":{"reference":"REF/1","customer":{"name":"Ada Ọbí"}}}';

it('accepts a body signed with the shared secret', function () {
    $sig = new PaystackSignature(PS_SECRET);

    expect($sig->verify(PS_BODY, $sig->expectedFor(PS_BODY)))->toBeTrue();
});

it('computes HMAC-SHA512 over the raw body — pinned against an INDEPENDENT hash', function () {
    // The one arm that does not use the class to check the class. Derived the other way round, from
    // PHP's own hash_hmac, so switching the algorithm or the signed material inside the class reds
    // here rather than moving both sides of the comparison together.
    $sig = new PaystackSignature(PS_SECRET);

    expect($sig->expectedFor(PS_BODY))
        ->toBe(hash_hmac('sha512', PS_BODY, PS_SECRET))
        ->and(strlen($sig->expectedFor(PS_BODY)))->toBe(128); // sha512 hex
});

it('refuses a body that was altered after signing — one byte is enough', function () {
    $sig = new PaystackSignature(PS_SECRET);
    $signature = $sig->expectedFor(PS_BODY);

    // The attack this exists to stop: take a genuine webhook, change the amount, replay it.
    $tampered = str_replace('charge.success', 'charge.failed', PS_BODY);

    expect($tampered)->not->toBe(PS_BODY)
        ->and($sig->verify($tampered, $signature))->toBeFalse();
});

it('refuses a signature made with a DIFFERENT secret', function () {
    $theirs = new PaystackSignature('sk_test_some_other_key');
    $ours = new PaystackSignature(PS_SECRET);

    // Someone who knows the algorithm and the body but not our key.
    expect($ours->verify(PS_BODY, $theirs->expectedFor(PS_BODY)))->toBeFalse();
});

it('refuses a missing or empty header rather than throwing', function () {
    // An unsigned POST to a public endpoint is ordinary internet noise. It must be refused quietly —
    // a 500 here would page someone every time a scanner finds the URL.
    $sig = new PaystackSignature(PS_SECRET);

    expect($sig->verify(PS_BODY, null))->toBeFalse()
        ->and($sig->verify(PS_BODY, ''))->toBeFalse();
});

it('is CASE-SENSITIVE on the signature — a hex-case variant is not the same signature', function () {
    $sig = new PaystackSignature(PS_SECRET);
    $upper = strtoupper($sig->expectedFor(PS_BODY));

    // `hash_equals` is a byte comparison, so this is refused. Worth an arm because a "helpful"
    // case-insensitive compare is exactly the kind of leniency someone adds while debugging.
    expect($upper)->not->toBe($sig->expectedFor(PS_BODY))
        ->and($sig->verify(PS_BODY, $upper))->toBeFalse();
});

it('REFUSES TO EXIST with an empty secret — the failure that would authenticate everything', function () {
    // THE MOST IMPORTANT ARM IN THIS FILE. `hash_hmac` accepts an empty key and returns a
    // well-formed digest, so a misconfigured deployment would verify forged webhooks successfully
    // and log them as authentic. Strictly worse than no check: the system reports itself secure.
    // Refused at CONSTRUCTION, so it cannot be reached by any code path at all.
    expect(fn () => new PaystackSignature(null))
        ->toThrow(RuntimeException::class, 'Refusing to verify webhooks with an empty key')
        ->and(fn () => new PaystackSignature(''))
        ->toThrow(RuntimeException::class);
});

it('names the header Paystack actually sends, lower-cased', function () {
    // Pinned because a typo here fails OPEN in the most deceptive way: the handler would read null
    // from a header that is present, refuse every genuine webhook, and look like a provider problem.
    expect(PaystackSignature::HEADER)->toBe('x-paystack-signature');
});

it('uses hash_equals — a property no behavioural test can reach, so it is asserted structurally', function () {
    // THE ONE ARM HERE THAT READS SOURCE, and it exists because a mutation proved the alternative is
    // untestable: swapping `hash_equals` for `===` changes NO observable behaviour, so all 25 tests
    // stayed green. Timing-safety is invisible to assertions about return values.
    //
    // The class docblock says "hash_equals, NEVER ===". Left there it is a description asserting a
    // property nothing checks — the exact class this codebase keeps paying for. So it is made
    // executable. Crude, and better than a sentence: a reviewer replacing the call reds here and
    // reads why.
    $source = file_get_contents(app_path('Finance/Services/PaystackSignature.php'));

    expect($source)->toContain('hash_equals(')
        // And the comparison is not ALSO done the unsafe way somewhere. `===` on the digest is what
        // leaks the position of the first differing byte, one retry at a time.
        ->and($source)->not->toContain('expectedFor($rawBody) === ');
});
