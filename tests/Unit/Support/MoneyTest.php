<?php

use App\Support\Money;

it('builds from kobo and reads back the exact minor units and currency', function () {
    $m = Money::fromKobo(123456);

    expect($m->toKobo())->toBe(123456)
        ->and($m->minorUnits)->toBe(123456)
        ->and($m->currency)->toBe('NGN');
});

it('builds from naira exactly, without floats', function () {
    expect(Money::fromNaira('1234.56')->toKobo())->toBe(123456)
        ->and(Money::fromNaira('1234.5')->toKobo())->toBe(123450) // .5 naira = 50 kobo
        ->and(Money::fromNaira('1234.05')->toKobo())->toBe(123405)
        ->and(Money::fromNaira('1234')->toKobo())->toBe(123400)
        ->and(Money::fromNaira(1000)->toKobo())->toBe(100000)
        ->and(Money::fromNaira('-12.05')->toKobo())->toBe(-1205);
});

it('round-trips naira -> kobo -> naira exactly', function () {
    expect(Money::fromNaira('1234.56')->toNaira())->toBe('1234.56')
        ->and(Money::fromNaira('0.05')->toNaira())->toBe('0.05')
        ->and(Money::fromKobo(0)->toNaira())->toBe('0.00')
        ->and(Money::fromKobo(-1205)->toNaira())->toBe('-12.05');
});

it('rejects a naira amount with more than two decimals rather than rounding', function () {
    // Rounding is forbidden until the §12.3 policy is signed.
    expect(fn () => Money::fromNaira('12.345'))->toThrow(InvalidArgumentException::class);
});

it('rejects a non-numeric or malformed naira amount', function () {
    expect(fn () => Money::fromNaira('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromNaira('1,234'))->toThrow(InvalidArgumentException::class);
});

it('rejects an invalid ISO 4217 currency code', function () {
    expect(fn () => Money::fromKobo(100, 'naira'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromKobo(100, 'US'))->toThrow(InvalidArgumentException::class);
});

it('adds and subtracts immutably, returning new instances', function () {
    $a = Money::fromNaira('100.00');
    $b = Money::fromNaira('25.50');

    $sum = $a->plus($b);
    $diff = $a->minus($b);

    expect($sum->toKobo())->toBe(12550)
        ->and($diff->toKobo())->toBe(7450)
        // originals are unchanged (immutability)
        ->and($a->toKobo())->toBe(10000)
        ->and($b->toKobo())->toBe(2550);
});

it('scales by an exact integer multiplier (quantity × unit price)', function () {
    $unit = Money::fromNaira('150.00');

    expect($unit->times(3)->toKobo())->toBe(45000)
        ->and($unit->times(0)->isZero())->toBeTrue()
        ->and($unit->times(-2)->toKobo())->toBe(-30000)
        ->and($unit->toKobo())->toBe(15000); // original unchanged
});

it('compares equality within the same currency', function () {
    expect(Money::fromNaira('10.00')->equals(Money::fromKobo(1000)))->toBeTrue()
        ->and(Money::fromNaira('10.00')->equals(Money::fromKobo(1001)))->toBeFalse();
});

it('reports zero and negative correctly', function () {
    expect(Money::fromKobo(0)->isZero())->toBeTrue()
        ->and(Money::fromKobo(-1)->isNegative())->toBeTrue()
        ->and(Money::fromKobo(1)->isNegative())->toBeFalse();
});

it('throws on currency mismatch in plus, minus and equals', function () {
    $ngn = Money::fromKobo(1000, 'NGN');
    $usd = Money::fromKobo(1000, 'USD');

    expect(fn () => $ngn->plus($usd))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $ngn->minus($usd))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $ngn->equals($usd))->toThrow(InvalidArgumentException::class);
});

it('formats naira with the symbol, thousands separators and two decimals', function () {
    // The ONE server-side renderer (ADR 0054), and the exact shape resources/js/lib/format.ts
    // produces — the two sit inches apart on an operator screen.
    expect(Money::fromNaira('1234.56')->format())->toBe('₦1,234.56')
        ->and(Money::fromNaira('1000000')->format())->toBe('₦1,000,000.00');
});

it('formats zero, sub-naira and kobo-remainder amounts without losing a digit', function () {
    // Below ₦1 the whole part is a bare "0" and must still carry the symbol and both kobo
    // digits; a remainder must not be truncated or padded away.
    expect(Money::fromKobo(0)->format())->toBe('₦0.00')
        ->and(Money::fromKobo(5)->format())->toBe('₦0.05')
        ->and(Money::fromKobo(99)->format())->toBe('₦0.99')
        ->and(Money::fromNaira('1234.05')->format())->toBe('₦1,234.05')
        ->and(Money::fromKobo(150099)->format())->toBe('₦1,500.99');
});

it('puts the sign BEFORE the symbol on a negative amount', function () {
    // -₦12.05, never ₦-12.05: the UI formatNaira does the same, and a school in net credit
    // reads this figure on the opening-balance interpretation sentence.
    expect(Money::fromKobo(-1205)->format())->toBe('-₦12.05')
        ->and(Money::fromKobo(-347640000)->format())->toBe('-₦3,476,400.00');
});

it('groups an amount large enough to need two separators', function () {
    // Seven digits is exactly the run a reader cannot count by eye, which is why grouping is
    // the point of this method rather than a decoration on it.
    expect(Money::fromKobo(347640000)->format())->toBe('₦3,476,400.00')
        ->and(Money::fromNaira('12345678.90')->format())->toBe('₦12,345,678.90');
});

it('groups a figure past 2^53 without losing a digit', function () {
    // The property number_format's declared `float` parameter cannot promise. This value's naira
    // part is ~9.22e16, an order of magnitude past float's exact-integer limit (2^53 ~ 9.01e15):
    // number_format((float) 92233720368547758) yields ...760. This asserts the GUARANTEE, not the
    // engine — it says nothing about what number_format does with an int today, because the
    // formatter is written so that the answer cannot matter (ADR 0054 §3).
    $huge = Money::fromKobo(PHP_INT_MAX);

    // Strip the punctuation back off and every digit of the original integer is still there —
    // which is the property number_format() cannot promise at this magnitude.
    expect(str_replace(['₦', ',', '.'], '', $huge->format()))->toBe((string) PHP_INT_MAX)
        ->and($huge->format())->toBe('₦92,233,720,368,547,758.07');
});

it('refuses to format a non-NGN Money as naira', function () {
    // ₦ is a naira mark; rendering USD through it would MISLABEL the amount, not merely
    // mis-style it. Single currency is a constraint, deliberately, not an omission.
    expect(fn () => Money::fromKobo(1000, 'USD')->format())->toThrow(InvalidArgumentException::class);
});

it('serialises to the canonical wire contract: integer minor units + currency, never a decimal', function () {
    $money = Money::fromNaira('1234.56');

    $expected = ['amount_minor' => 123456, 'currency' => 'NGN'];

    expect($money->toArray())->toBe($expected)
        ->and($money->jsonSerialize())->toBe($expected)
        // json_encode goes through jsonSerialize() -> stable shape for API Resources
        ->and(json_encode($money))->toBe('{"amount_minor":123456,"currency":"NGN"}');
});

it('keeps amount_minor an integer (not a decimal string) on the wire', function () {
    $json = json_decode(json_encode(Money::fromNaira('0.05')), true);

    expect($json['amount_minor'])->toBe(5)
        ->and($json['amount_minor'])->toBeInt()
        ->and($json['currency'])->toBe('NGN');
});
