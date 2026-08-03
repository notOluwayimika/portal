<?php

use App\Support\AddressNormalizer;
use Tests\TestCase;

// The application TestCase, because `phone()` reads its default calling code from
// config — and tests/Unit is not bound to it (tests/Pest.php extends TestCase in
// `Feature` only). No RefreshDatabase: this suite touches no database, and adding
// one would turn a millisecond parsing suite into a migration per test.
uses(TestCase::class);

/**
 * Adversarial input for the one primitive both the suppression write and the
 * send-time check depend on.
 *
 * These are a UNIT suite on purpose, kept apart from the backfill's row-shape
 * fixtures: what is being tested here is PARSING, and the input that makes it hard
 * is real. Guardian phone numbers were captured with NO validation — the synthetic
 * `{phone}@no-email.local` address they fed was never sent to, so nothing in the
 * system ever had to parse them. The column therefore holds free text.
 */

/*
|--------------------------------------------------------------------------
| Phone — E.164, or null
|--------------------------------------------------------------------------
*/

it('normalises every ordinary way a Nigerian number gets typed', function (string $raw) {
    expect(AddressNormalizer::phone($raw))->toBe('+2348031234567');
})->with([
    'national with trunk prefix' => '08031234567',
    'spaced national' => '0803 123 4567',
    'punctuated national' => '0803-123-4567',
    'explicit international' => '+2348031234567',
    'spaced international' => '+234 803 123 4567',
    'international access code' => '002348031234567',
    'country code, no plus' => '2348031234567',
    'bare subscriber number' => '8031234567',
    'parenthesised' => '(0803) 123 4567',
    'trailing whitespace' => "  08031234567\n",
]);

/**
 * THE VALUES THAT MAKE A PHONE-STRING COUNT A CEILING RATHER THAN A FIGURE.
 *
 * Every one of these satisfies `TRIM(phone) <> ''` in SQL and mints NO contact
 * point, which is precisely why the authoritative no-contact number comes from a
 * backfill dry-run and not from a column count.
 */
it('rejects free text that a SQL emptiness check would count as a phone', function (string $raw) {
    expect(AddressNormalizer::phone($raw))->toBeNull();
})->with([
    'not applicable' => 'n/a',
    'dash' => '-',
    'none' => 'none',
    'a name in the phone field' => 'Mrs Adeyemi',
    'nil' => 'NIL',
    'whitespace only' => '   ',
    'punctuation only' => '---',
]);

/**
 * CORRECT LENGTH, WRONG NUMBER — the class the first cut left open.
 *
 * Rejecting `12345` for being short closed one INSTANCE, not the class. A digit
 * count validates FORMATTING; whether the number exists is a different axis. Each
 * of these has a plausible length and no owner, and each one minted a contact point
 * until validity was checked against the region's actual number plan.
 */
it('rejects numbers of a plausible length that no carrier issues', function (string $raw) {
    expect(AddressNormalizer::phone($raw))->toBeNull();
})->with([
    // 13 digits after prefixing, in any length range, a `123` prefix nobody issues.
    'unissued prefix' => '1234567890',
    // Produced `+000000000` before — a country code of `0`, not assignable in E.164
    // at all. My own code emitting a structurally invalid address.
    'all zeroes' => '00000000000',
    // Truncated capture.
    'too short' => '12345',
    'far too long' => '080312345678901234',
]);

/**
 * VALID IS NOT REACHABLE, and this is the case that surprised me.
 *
 * `08000000000` is what gets typed when a phone field is required and unknown — and
 * libphonenumber says it is a genuinely VALID Nigerian number, because `0800` is an
 * assignable toll-free range. Validity alone would mint a contact point for it and
 * report the row as successfully rerouted, inflating the reroute count with an
 * address no SMS can reach.
 */
it('rejects numbers that are valid but cannot receive a message', function (string $raw) {
    expect(AddressNormalizer::phone($raw))->toBeNull();
})->with([
    'toll-free placeholder' => '08000000000',
    // A real London landline. Valid, dialable, and not messageable.
    'foreign fixed line' => '+442079460958',
]);

it('does not re-home an explicitly international number into the default region', function () {
    // A `+` means the caller already said which country. Treating it as national
    // would produce `+2344479…` — plausible-looking and wrong, which is worse than a
    // rejection because it silently sends somewhere else entirely.
    expect(AddressNormalizer::phone('+447911123456'))->toBe('+447911123456');
});

it('takes the REGION from config, so a second country is genuinely a config change', function () {
    config(['notifications.default_region' => 'GB']);

    // Validated against GB's plan, not merely prefixed with GB's calling code —
    // which is the difference between the promise the old docblock made and the one
    // the code can now keep.
    expect(AddressNormalizer::phone('07911123456'))->toBe('+447911123456');
});

/*
|--------------------------------------------------------------------------
| Email — conservative on purpose
|--------------------------------------------------------------------------
*/

it('lowercases and trims', function () {
    expect(AddressNormalizer::email('  Parent@Example.TEST '))->toBe('parent@example.test');
});

/**
 * THE OVER-SUPPRESSION TRAP, pinned so nobody "improves" it later.
 *
 * Gmail treats these as one mailbox. A suppression key must not: fold them and a
 * single bounce on `foo+fees@` mutes `foo+safeguarding@` too. Plus-addressing is
 * how an organised parent separates school mail, so provider-aware canonicalization
 * would land its failure on exactly the people using the system best.
 */
it('keeps plus-addressed and dotted variants distinct', function () {
    expect(AddressNormalizer::email('foo+fees@example.test'))
        ->not->toBe(AddressNormalizer::email('foo@example.test'))
        ->and(AddressNormalizer::email('first.last@example.test'))
        ->not->toBe(AddressNormalizer::email('firstlast@example.test'));
});

it('normalises Unicode so one mailbox cannot be both suppressed and deliverable', function () {
    // Composed vs decomposed forms of the same accented domain are different byte
    // strings; without NFC they hash differently, so a suppression written from one
    // form would not match a send from the other.
    $composed = AddressNormalizer::email("parent@ex\u{00E9}mple.test");
    $decomposed = AddressNormalizer::email("parent@exe\u{0301}mple.test");

    expect($composed)->toBe($decomposed)
        ->and(AddressNormalizer::hash($composed))->toBe(AddressNormalizer::hash($decomposed));
});

it('rejects values that are structurally not addresses', function (string $raw) {
    expect(AddressNormalizer::email($raw))->toBeNull();
})->with([
    'free text' => 'n/a',
    'no at sign' => 'parent.example.test',
    'no domain' => 'parent@',
    'no local part' => '@example.test',
    'no dot in domain' => 'parent@localhost',
    'empty' => '',
]);

/**
 * The synthetic sentinel is a STRUCTURALLY VALID address — that is the entire
 * reason the codebase needed a separate predicate to recognise it, and why the
 * normalizer is not the place to filter it. Recognising it belongs to
 * User::hasDeliverableEmail(); the backfill excludes it before it ever gets here.
 */
it('does not treat the synthetic sentinel as invalid, because it is not the normalizer\'s job', function () {
    expect(AddressNormalizer::email('08031234567@no-email.local'))
        ->toBe('08031234567@no-email.local');
});

/*
|--------------------------------------------------------------------------
| Hash
|--------------------------------------------------------------------------
*/

it('hashes deterministically, which is what makes lookup work and privacy impossible', function () {
    $hash = AddressNormalizer::hash('parent@example.test');

    expect($hash)->toBe(AddressNormalizer::hash('parent@example.test'))
        ->and(strlen($hash))->toBe(64);
});
