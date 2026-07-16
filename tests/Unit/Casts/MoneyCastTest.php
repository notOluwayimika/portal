<?php

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

it('sets a Money to its amount and currency columns exactly', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');

    $stored = $cast->set(new class extends Model {}, 'balance', Money::fromNaira('1234.56'), []);

    expect($stored)->toBe([
        'balance_kobo' => 123456,
        'balance_currency' => 'NGN',
    ]);
});

it('gets a Money back from the stored columns exactly', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');

    $money = $cast->get(new class extends Model {}, 'balance', null, [
        'balance_kobo' => 123456,
        'balance_currency' => 'NGN',
    ]);

    expect($money)->toBeInstanceOf(Money::class)
        ->and($money->toKobo())->toBe(123456)
        ->and($money->currency)->toBe('NGN');
});

it('round-trips a Money through set then get with no loss', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');
    $model = new class extends Model {};
    $original = Money::fromNaira('-99999999.99');

    $stored = $cast->set($model, 'balance', $original, []);
    $restored = $cast->get($model, 'balance', null, $stored);

    expect($restored->toKobo())->toBe($original->toKobo())
        ->and($restored->currency)->toBe($original->currency);
});

it('round-trips null as a null Money', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');
    $model = new class extends Model {};

    $stored = $cast->set($model, 'balance', null, []);

    expect($stored)->toBe(['balance_kobo' => null, 'balance_currency' => null])
        ->and($cast->get($model, 'balance', null, $stored))->toBeNull();
});

it('rejects setting a non-Money value', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');

    expect(fn () => $cast->set(new class extends Model {}, 'balance', 1000, []))
        ->toThrow(InvalidArgumentException::class);
});

it('gets null only when BOTH columns are null (symmetric null)', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');

    expect($cast->get(new class extends Model {}, 'balance', null, [
        'balance_kobo' => null,
        'balance_currency' => null,
    ]))->toBeNull();
});

it('fails loudly on a partially populated money row', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');
    $model = new class extends Model {};

    // amount without currency
    expect(fn () => $cast->get($model, 'balance', null, [
        'balance_kobo' => 123456,
        'balance_currency' => null,
    ]))->toThrow(InvalidArgumentException::class);

    // currency without amount
    expect(fn () => $cast->get($model, 'balance', null, [
        'balance_kobo' => null,
        'balance_currency' => 'NGN',
    ]))->toThrow(InvalidArgumentException::class);
});

it('fails loudly when the currency column is missing entirely rather than defaulting', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');

    // amount present, currency column absent from the row -> not a valid money.
    expect(fn () => $cast->get(new class extends Model {}, 'balance', null, [
        'balance_kobo' => 123456,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects a malformed currency in storage (format validated via the VO)', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');

    expect(fn () => $cast->get(new class extends Model {}, 'balance', null, [
        'balance_kobo' => 123456,
        'balance_currency' => 'naira',
    ]))->toThrow(InvalidArgumentException::class);
});
