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
