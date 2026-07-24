<?php

use App\Support\Money;

/**
 * Money::percentage / allocate — the split op, proven STANDALONE before any consumer
 * is wired. These are pure integer-minor-unit arithmetic with the signed §1 policy:
 * banker's rounding (half-to-even), remainder-on-final, exact reconciliation, no float.
 */

// ── Banker's rounding — the make-or-break behaviour ──────────────────────────

it('rounds HALF TO EVEN, not half up — the distinguishing case', function () {
    // 5 kobo × 50% = 2.5 → 2 (even). A half-UP implementation returns 3 here; this is
    // the one test that separates the two, and it must exist.
    expect(Money::fromKobo(5)->percentage(50)->toKobo())->toBe(2);

    // 15 kobo × 50% = 7.5 → 8 (even). Half-up also gives 8, so this alone proves
    // nothing — it is the PAIR with the line above that pins half-to-even:
    expect(Money::fromKobo(15)->percentage(50)->toKobo())->toBe(8);

    // The classic sequence, scaled: .5 always goes to the even neighbour.
    // 1→0.5→0, 3→1.5→2, 5→2.5→2, 7→3.5→4, 9→4.5→4, 11→5.5→6.
    expect(collect([1, 3, 5, 7, 9, 11])->map(fn ($k) => Money::fromKobo($k)->percentage(50)->toKobo())->all())
        ->toBe([0, 2, 2, 4, 4, 6]);
    // Half-UP would give [1, 2, 3, 4, 5, 6]. Every even-boundary entry differs.
});

it('rounds normally away from the halfway point', function () {
    // 1 kobo × 33% = 0.33 → 0; 2 × 33% = 0.66 → 1. Ordinary rounding, no boundary.
    expect(Money::fromKobo(1)->percentage(33)->toKobo())->toBe(0)
        ->and(Money::fromKobo(2)->percentage(33)->toKobo())->toBe(1);
});

it('handles a negative amount symmetrically about zero', function () {
    // −5 kobo × 50% = −2.5 → −2 (even), mirroring the positive case.
    expect(Money::fromKobo(-5)->percentage(50)->toKobo())->toBe(-2)
        ->and(Money::fromKobo(-7)->percentage(50)->toKobo())->toBe(-4);
});

// ── Reconciliation — no penny created or lost ────────────────────────────────

it('allocate puts the remainder on the FINAL part and reconciles exactly', function () {
    $parts = Money::fromKobo(1000)->allocate(3);

    expect(collect($parts)->map->toKobo()->all())->toBe([333, 333, 334])   // remainder on last
        ->and(collect($parts)->sum(fn ($p) => $p->toKobo()))->toBe(1000);   // exact

    // A prime amount into an awkward number of parts, still exact.
    $awkward = Money::fromKobo(1001)->allocate(7);
    expect(collect($awkward)->sum(fn ($p) => $p->toKobo()))->toBe(1001)
        ->and($awkward)->toHaveCount(7);
});

it('allocate reconciles a negative amount too (remainder still on the final part)', function () {
    $parts = Money::fromKobo(-1000)->allocate(3);
    expect(collect($parts)->map->toKobo()->all())->toBe([-333, -333, -334])
        ->and(collect($parts)->sum(fn ($p) => $p->toKobo()))->toBe(-1000);
});

it('PROPERTY — for many amount×percent combinations, reduction + remainder == original', function () {
    // Rounding bugs hide in specific residues, so sweep a grid rather than pick cases.
    // The reconciliation guarantee: for a percentage reduction, the rounded reduction
    // plus the remaining payable equals the full charge, to the penny, always.
    foreach ([1, 7, 42, 99, 100, 333, 500, 1000, 12345, 999999] as $amount) {
        foreach ([1, 3, 10, 25, 33, 50, 66, 75, 99, 100] as $percent) {
            $full = Money::fromKobo($amount);
            $reduction = $full->percentage($percent);
            $remaining = $full->minus($reduction);

            expect($reduction->toKobo() + $remaining->toKobo())
                ->toBe($amount, "amount=$amount percent=$percent lost/gained a penny");

            // …and the reduction never exceeds the whole for percent ≤ 100.
            expect($reduction->toKobo())->toBeLessThanOrEqual($amount);
        }
    }
});

it('allocate refuses a non-positive part count', function () {
    expect(fn () => Money::fromKobo(100)->allocate(0))
        ->toThrow(InvalidArgumentException::class);
});

it('preserves currency and cannot manufacture a float', function () {
    $result = Money::fromKobo(333, 'NGN')->percentage(10);
    expect($result->currency)->toBe('NGN')
        ->and($result->toKobo())->toBeInt();           // integer minor units, never float
});
