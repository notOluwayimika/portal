<?php

namespace App\Finance\Services;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Solves for the GROSS a payer must be charged so the school receives the amount they chose.
 *
 * ── THE RULING THIS IMPLEMENTS ──
 *
 * Developer 1, 30 August 2026 (`docs/handoff/payments-decisions-30-august.md` §2): the parent is
 * charged bill + fee, the school receives the full bill, and **the fee is known BEFORE the parent is
 * charged** rather than read off the settlement. That second clause is why this class exists at
 * initiation rather than a subtraction existing at settlement.
 *
 * ── THE FEE IS PIECEWISE: THREE REGIMES, TWO BOUNDARIES ──
 *
 * Paystack charges 1.5% + ₦100, the ₦100 waived below a ₦2,500 GROSS, the whole fee capped at
 * ₦2,000. Measured against three live sandbox charges
 * (`docs/handoff/decisions/paystack-fee-is-solve-for-gross.md`), and measured on the GROSS — the
 * amount actually charged, not the amount the school wanted to net. That is what makes this an
 * inversion rather than an addition: adding `fee(B)` to `B` under-recovers, because the fee is then
 * recomputed on the larger gross.
 *
 * ── ROUNDING IS UP, AND THE DIRECTION IS THE WHOLE POINT ──
 *
 * `G = (B + flat) / 0.985` does not divide evenly, so a kobo has to fall somewhere. Rounding DOWN
 * puts it on the debt side: the school receives one kobo less than the bill, the invoice is left
 * permanently a kobo short — on an append-only table, uncorrectable by UPDATE — and it renders to
 * the parent as still owing. A bill that cannot be cleared by paying it is the worst outcome
 * available here.
 *
 * Rounding UP puts it on the credit side, where `RecordPayment` caps the allocation at outstanding
 * and the excess banks as account credit under a rule that already exists. Harmless.
 *
 * ── DERIVED FROM THE FORWARD FUNCTION, NOT RESTATED ALONGSIDE IT ──
 *
 * {@see feeOn()} is the measured thing. {@see grossFor()} computes a closed-form candidate and then
 * VERIFIES it against `feeOn()`, walking up if the candidate under-recovers. So the two halves
 * cannot drift apart: if the fee schedule changes, the inversion follows it or the verification
 * fails. An inverse restated independently of its forward is two sources of truth for one fact.
 */
final class GatewayFeeCalculator
{
    /** 1.5%, as a rational so the arithmetic stays in integers. */
    private const RATE_NUMERATOR = 15;

    private const RATE_DENOMINATOR = 1000;

    /** ₦100, in kobo. Waived below {@see WAIVER_GROSS}. */
    private const FLAT_KOBO = 10_000;

    /** The GROSS below which the flat fee is waived — ₦2,500. Not a threshold on the bill. */
    private const WAIVER_GROSS_KOBO = 250_000;

    /** The fee ceiling — ₦2,000. */
    private const CAP_KOBO = 200_000;

    /**
     * What Paystack takes on a given gross. The MEASURED function, and the authority here.
     *
     * ROUNDS THE PERCENTAGE UP. Paystack's own rounding was observed as half-up on one sample, which
     * is not enough to pin it; rounding our ESTIMATE up is the safe direction, because
     * {@see grossFor()} uses this to decide whether a candidate recovers the bill, and
     * over-estimating the fee can only make it charge more, never less.
     *
     * The fee actually recorded against a settlement is always the provider's REPORTED `fees`, never
     * this — recomputing at settlement would erase the disagreement the discrepancy report exists to
     * surface. This function exists solely to choose a gross up front.
     */
    public function feeOn(Money $gross): Money
    {
        $kobo = $gross->toKobo();

        $percentage = intdiv($kobo * self::RATE_NUMERATOR + self::RATE_DENOMINATOR - 1, self::RATE_DENOMINATOR);
        $fee = $percentage + ($kobo < self::WAIVER_GROSS_KOBO ? 0 : self::FLAT_KOBO);

        return Money::fromKobo(min($fee, self::CAP_KOBO), $gross->currency);
    }

    /**
     * The smallest gross whose net is at least the bill.
     *
     * "At least", never "exactly" — see the rounding note on the class. The residual is at most one
     * kobo and it lands as account credit.
     */
    public function grossFor(Money $bill): Money
    {
        if ($bill->isZero() || $bill->isNegative()) {
            throw new InvalidArgumentException('A gateway payment must be for a positive amount.');
        }

        $b = $bill->toKobo();

        // Closed forms per regime, each rounded UP. Which one applies is decided by VERIFICATION
        // below rather than by comparing the bill against a boundary constant — boundary constants
        // are a second statement of the fee schedule and drift from it silently.
        $candidates = [
            // R1, flat waived: G = B / 0.985
            $this->divideUp($b * self::RATE_DENOMINATOR, self::RATE_DENOMINATOR - self::RATE_NUMERATOR),
            // R2, flat applies: G = (B + flat) / 0.985
            $this->divideUp(($b + self::FLAT_KOBO) * self::RATE_DENOMINATOR, self::RATE_DENOMINATOR - self::RATE_NUMERATOR),
            // R3, capped: the division disappears
            $b + self::CAP_KOBO,
        ];

        // THE SMALLEST CANDIDATE THAT RECOVERS — not the first.
        //
        // Taking the first was wrong and the "never more than a kobo over" arm caught it. A regime's
        // formula can RECOVER outside its own range while charging far too much: for a ₦1,000,000
        // bill the R1 form yields a gross whose fee is capped, so it verifies — and over-charges the
        // payer by ₦13,228. "Recovers the bill" and "is the right gross" are different predicates,
        // and only the first was being checked.
        $recovering = [];

        foreach ($candidates as $candidate) {
            $gross = Money::fromKobo($candidate, $bill->currency);

            if ($gross->minus($this->feeOn($gross))->toKobo() >= $b) {
                $recovering[] = $candidate;
            }
        }

        if ($recovering !== []) {
            return Money::fromKobo(min($recovering), $bill->currency);
        }

        // THE ENFORCED PRECONDITION, not a defensive tail.
        //
        // Every bill maps to exactly one consistent regime TODAY: above the waiver boundary the R1
        // candidate stops recovering (its net falls short once the flat applies), so R2 is both
        // consistent and smallest; and R3 adds the maximum possible fee, so its net is always at
        // least the bill. There is no gap on the bill axis, which is why there is no dead-band
        // branch here — a branch no input can reach is untestable code that reads as coverage.
        //
        // BUT THAT IS A PROPERTY OF PAYSTACK'S CURRENT SCHEDULE, NOT OF ARITHMETIC. If the waiver
        // threshold moves, or the flat changes, or the cap does, "no input reaches this line" stops
        // being true — and it would stop being true SILENTLY, because nothing else in this class
        // checks it. So the precondition is asserted rather than reasoned about: reaching here means
        // the regimes no longer cover their own range, and the loud failure is the point.
        throw new InvalidArgumentException(
            'No gross recovers a bill of '.$bill->toKobo().' kobo under the current fee schedule. '
            .'The regimes in GatewayFeeCalculator no longer cover their own range.'
        );
    }

    /**
     * Ceiling division on positive integers. `intdiv` truncates toward zero, which for positive
     * operands is a floor — the wrong direction for every use here.
     */
    private function divideUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
