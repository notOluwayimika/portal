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
    expect(formatNaira(Money::fromNaira('1234.56')))->toBe('₦1,234.56')
        ->and(formatNaira(Money::fromKobo(0)))->toBe('₦0.00')
        ->and(formatNaira(Money::fromNaira('1000000')))->toBe('₦1,000,000.00')
        ->and(formatNaira(Money::fromKobo(-1205)))->toBe('-₦12.05');
});

it('refuses to format a non-NGN Money as naira', function () {
    expect(fn () => formatNaira(Money::fromKobo(1000, 'USD')))->toThrow(InvalidArgumentException::class);
});
