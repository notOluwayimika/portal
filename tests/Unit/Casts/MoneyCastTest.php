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

// Case 2: both columns selected and NULL -> null.
it('gets null only when BOTH columns are selected and null (case 2)', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');

    expect($cast->get(new class extends Model {}, 'balance', null, [
        'balance_kobo' => null,
        'balance_currency' => null,
    ]))->toBeNull();
});

// Case 1: a configured column was not selected -> query-construction error.
it('throws a query-construction error naming the unselected column (case 1)', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');
    $model = new class extends Model {};

    // Both columns absent (money attribute not selected at all) — must NOT return null.
    expect(fn () => $cast->get($model, 'balance', null, ['id' => 1]))
        ->toThrow(InvalidArgumentException::class, 'were not selected');

    // One selected, one not (e.g. select('balance_kobo') without the currency).
    expect(fn () => $cast->get($model, 'balance', null, ['balance_kobo' => 123456]))
        ->toThrow(InvalidArgumentException::class, 'balance_currency');
});

// Case 3: both selected, exactly one NULL -> data-integrity error.
it('throws a data-integrity error on a corrupt (partial-NULL) row (case 3)', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');
    $model = new class extends Model {};

    expect(fn () => $cast->get($model, 'balance', null, [
        'balance_kobo' => 123456,
        'balance_currency' => null,
    ]))->toThrow(InvalidArgumentException::class, 'corrupt at rest');

    expect(fn () => $cast->get($model, 'balance', null, [
        'balance_kobo' => null,
        'balance_currency' => 'NGN',
    ]))->toThrow(InvalidArgumentException::class, 'corrupt at rest');
});

// Cases 1 and 3 must be diagnosably different, not an identical message.
it('gives distinct messages for a not-selected column vs a corrupt row', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');
    $model = new class extends Model {};

    $notSelected = null;
    try {
        $cast->get($model, 'balance', null, ['balance_kobo' => 123456]); // currency not selected
    } catch (InvalidArgumentException $e) {
        $notSelected = $e->getMessage();
    }

    $corrupt = null;
    try {
        $cast->get($model, 'balance', null, ['balance_kobo' => 123456, 'balance_currency' => null]);
    } catch (InvalidArgumentException $e) {
        $corrupt = $e->getMessage();
    }

    expect($notSelected)->toContain('not selected')
        ->and($corrupt)->toContain('corrupt at rest')
        ->and($notSelected)->not->toBe($corrupt);
});

it('rejects a malformed currency in storage (format validated via the VO)', function () {
    $cast = new MoneyCast('balance_kobo', 'balance_currency');

    expect(fn () => $cast->get(new class extends Model {}, 'balance', null, [
        'balance_kobo' => 123456,
        'balance_currency' => 'naira',
    ]))->toThrow(InvalidArgumentException::class);
});
