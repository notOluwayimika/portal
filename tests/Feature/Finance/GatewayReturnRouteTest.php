<?php

use Illuminate\Support\Facades\Route;

/**
 * THE TWO HALVES OF THE RETURN WIRING, PINNED TO EACH OTHER ACROSS THE LANGUAGE BOUNDARY.
 *
 * The pay screen sends `callback_url` when it starts a payment; `GatewayReturnController` answers at
 * the other end. **Neither fails if they disagree.** Paystack falls back to the dashboard's default
 * return URL, the parent lands somewhere generic, and no test, lint or type-check notices — the
 * client's string and the server's route are in different languages and nothing compares them.
 *
 * That is a correctness requirement with no local failure signal, which this repository has
 * repeatedly found propagates by memory and therefore does not propagate. So the comparison is a
 * test: read the literal out of the TypeScript module and assert it is the path Laravel registered.
 *
 * READ FROM THE FILE, NOT RESTATED. An assertion that repeated the path here would pin the route
 * against this test's memory of the client rather than against the client.
 */
it('registers the return route at exactly the path the client sends as callback_url', function () {
    $module = file_get_contents(base_path('resources/js/lib/payment-return-url.ts'));

    expect(is_string($module))->toBeTrue();

    preg_match("/PAYMENT_RETURN_PATH\s*=\s*'([^']+)'/", (string) $module, $matches);

    // THE MATCH ITSELF IS ASSERTED. A regex that stopped matching — the constant renamed, the quotes
    // changed — would leave `$matches[1]` unset and every comparison below vacuous, which is the
    // broken-closed shape a scanner fails into silently.
    expect($matches)->toHaveCount(2, 'PAYMENT_RETURN_PATH was not found in payment-return-url.ts — the matcher, not the value, is what failed.');

    $registered = Route::getRoutes()->getByName('parent.finance.payment.return');

    // POSITIVE FORM, because Pest DISCARDS a custom message under `->not->` — the negated-message
    // gate in this suite exists for exactly that, and caught this file before it reached the remote.
    // A message that is silently thrown away is worse than none: it reads as a diagnosis nobody will
    // ever see.
    expect($registered === null)->toBeFalse('The return route is not registered under the name the client depends on.');

    expect('/'.$registered->uri())->toBe($matches[1]);
});
