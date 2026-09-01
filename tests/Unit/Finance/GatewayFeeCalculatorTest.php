<?php

use App\Finance\Services\GatewayFeeCalculator;
use App\Support\Money;

/**
 * The gross-up, checked against the PROPERTY it must hold rather than against restated arithmetic.
 *
 * An expectation computed the way the code computes it asserts that the implementation equals
 * itself. So the central arm asserts the property the ruling actually requires — **the school
 * receives at least the bill** — and derives nothing from the regime formulas.
 */
it('always recovers at least the bill, across every regime and both boundaries', function (int $billKobo) {
    $calc = new GatewayFeeCalculator;
    $bill = Money::fromKobo($billKobo);

    $gross = $calc->grossFor($bill);
    $net = $gross->minus($calc->feeOn($gross));

    // AT LEAST, never exactly — the kobo residual is deliberate and lands on the credit side.
    expect($net->toKobo())->toBeGreaterThanOrEqual($billKobo)
        // And it is never MORE than a kobo over, or the payer is being over-charged rather than
        // rounded. This is the arm that would catch a gross-up that recovered by overshooting.
        ->and($net->toKobo())->toBeLessThanOrEqual($billKobo + 1)
        ->and($gross->toKobo())->toBeGreaterThan($billKobo);
})->with([
    'one kobo' => 1,
    'small, flat waived' => 50_000,
    'just under the waiver boundary' => 246_249,
    'at the waiver boundary' => 246_250,
    'just over the waiver boundary' => 246_251,
    'the dead band' => 250_000,
    'break-even with the step' => 256_249,
    'an ordinary term bill' => 10_000_000,
    'just under the cap boundary' => 12_466_666,
    'at the cap boundary' => 12_466_667,
    'just over the cap boundary' => 12_466_668,
    'large, capped' => 100_000_000,
]);

it('crosses the waiver discontinuity without a gap — the measured rows either side', function (int $billKobo, int $expectedGross, int $expectedFee) {
    $calc = new GatewayFeeCalculator;
    $gross = $calc->grossFor(Money::fromKobo($billKobo));

    // THE DISCONTINUITY IS WHERE A SCHEDULE CHANGE WOULD FIRST SHOW. Paystack waives its flat below
    // a NGN 2,500 GROSS, so the gross jumps NGN 101.54 as the bill crosses NGN 2,462.50 — there is a
    // dead band on the GROSS axis (no bill produces a charge between 2,499.99 and 2,601.53) and NO
    // gap on the bill axis. These two rows are one kobo apart and land in different regimes.
    //
    // The numbers are MEASURED, pasted from a run of the built calculator and checked against the
    // sandbox calibration — not recomputed here from the same formulas the code uses, which would
    // assert only that the implementation equals itself.
    expect($gross->toKobo())->toBe($expectedGross)
        ->and($calc->feeOn($gross)->toKobo())->toBe($expectedFee)
        ->and($gross->minus($calc->feeOn($gross))->toKobo())->toBe($billKobo);
})->with([
    'last bill in the waived band' => [246_249, 249_999, 3_750],
    'first bill in the flat band' => [246_250, 260_153, 13_903],
]);

it('rounds the gross UP — the residual never falls on the debt side', function () {
    $calc = new GatewayFeeCalculator;

    // A bill chosen so the division does not come out even. Rounding DOWN here nets 1 kobo short,
    // which on an append-only table leaves the invoice permanently unpayable and renders to the
    // parent as still owing. This arm reds if divideUp() becomes intdiv().
    $bill = Money::fromKobo(10_000_001);
    $gross = $calc->grossFor($bill);

    expect($gross->minus($calc->feeOn($gross))->toKobo())->toBeGreaterThanOrEqual(10_000_001);
});

it('matches the calibration measured against three live sandbox charges', function () {
    $calc = new GatewayFeeCalculator;

    // ₦100,000.00 bill -> charge ₦101,624.37, fee ₦1,624.37, school receives exactly ₦100,000.00.
    // AN INDEPENDENT PATH: this number came off a live Paystack sandbox charge, not from this class.
    $gross = $calc->grossFor(Money::fromKobo(10_000_000));

    expect($gross->toKobo())->toBe(10_162_437)
        ->and($calc->feeOn($gross)->toKobo())->toBe(162_437);
});

it('refuses a zero or negative bill', function (int $kobo) {
    expect(fn () => (new GatewayFeeCalculator)->grossFor(Money::fromKobo($kobo)))
        ->toThrow(InvalidArgumentException::class);
})->with(['zero' => 0, 'negative' => -100]);
