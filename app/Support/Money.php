<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable money value object (Constitution rule 10; §12.3).
 *
 * Money is ALWAYS stored as integer minor units (kobo for NGN) plus an explicit
 * ISO 4217 currency — never a float, never a decimal cast. This is what keeps
 * every amount exact end-to-end (§12.1 ledger schema carries an amount column
 * and a currency column; MoneyCast maps a Money to both).
 *
 * Currency defaults to NGN and NGN is the only currency written today. The
 * currency field is not multi-currency *support* — it is the invariant that a
 * Money knows what it is, so cross-currency arithmetic is impossible by
 * construction: plus(), minus() and equals() throw on a currency mismatch.
 *
 * ROUNDING (§1, signed): exact integer scaling is `times(int)` (quantity × unit
 * price), and the DIVIDING ops are `percentage(int)` and `allocate(int)`. Both round
 * per the signed accounting policy — **banker's rounding (round-half-to-even)**, with
 * the indivisible remainder on the **final** part so parts reconcile to the original
 * exactly, no penny created or lost. All of it stays in integer minor units; no float
 * ever enters (see roundedDiv).
 *
 * HISTORY: these ops were deliberately absent for three slices while
 * accounting-policy.md's §1 rounding rule was unsigned — a rounding-bearing operation
 * built ahead of a signed policy would have been a Constitution violation and, worse,
 * a guess. They land now, with their first real consumer (percentage waivers/discounts),
 * which is exactly the "not before" moment the earlier docblock reserved.
 */
final class Money implements Arrayable, JsonSerializable
{
    public const DEFAULT_CURRENCY = 'NGN';

    private function __construct(
        public readonly int $minorUnits,
        public readonly string $currency,
    ) {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code [{$currency}].");
        }
    }

    /**
     * Build from integer minor units (kobo for NGN).
     */
    public static function fromKobo(int $kobo, string $currency = self::DEFAULT_CURRENCY): self
    {
        return new self($kobo, $currency);
    }

    /**
     * Build from a naira-major amount. Accepts a whole-naira integer or a string
     * with up to two decimal places ("1234", "1234.5", "-1234.56"). More than two
     * decimals is rejected rather than rounded — rounding is not permitted until
     * the §12.3 policy is signed (see class docblock). Naira is an NGN concept, so
     * this constructor is NGN-only.
     */
    public static function fromNaira(string|int $naira): self
    {
        $value = (string) $naira;

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException(
                "Invalid NGN amount [{$naira}]: expected an integer or up to two decimal places (no rounding is performed)."
            );
        }

        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '0');
        $kobo = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');

        return new self($negative ? -$kobo : $kobo, self::DEFAULT_CURRENCY);
    }

    /**
     * The amount in integer minor units (kobo for NGN).
     */
    public function toKobo(): int
    {
        return $this->minorUnits;
    }

    /**
     * The amount as an exact naira-major decimal string, e.g. "1234.56", "-12.05".
     */
    public function toNaira(): string
    {
        $kobo = abs($this->minorUnits);
        $major = intdiv($kobo, 100);
        $minor = $kobo % 100;

        return ($this->minorUnits < 0 ? '-' : '').$major.'.'.str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
    }

    /**
     * THE server-side money renderer: "₦1,234.56", "-₦12.05", "₦0.00".
     *
     * The counterpart of the UI's formatNaira (resources/js/lib/format.ts) and shaped to
     * match it exactly — ₦ symbol, comma-grouped naira, always two kobo digits, and the
     * SIGN BEFORE THE SYMBOL. The two renderings of one figure sit inches apart on an
     * operator screen (a refusal message beside the table column it refuses), so a
     * difference in shape between them reads as a difference in amount.
     *
     * SYMBOL, NOT ISO CODE. `NGN 125000.00` is what the server used to emit; six unbroken
     * digits in a sentence about money someone is about to commit irreversibly is
     * precisely the magnitude error grouping exists to prevent.
     *
     * FLOAT-FREE GROUPING, deliberately NOT number_format. THE ARGUMENT IS ABOUT THE DECLARED
     * TYPE, not about what any one engine build happens to do:
     *
     *   - number_format's first parameter is declared `float` (ReflectionFunction confirms it).
     *   - The largest naira-major value this type can hold is intdiv(PHP_INT_MAX, 100) =
     *     92,233,720,368,547,758 — about 9.22e16.
     *   - float's exact-integer limit is 2^53 = 9,007,199,254,740,992 — about 9.01e15.
     *
     * The domain's top exceeds float's exact range by an order of magnitude, so at the top of
     * the range the exactness of a grouped figure is one coercion away. Measured, that costs
     * real digits: number_format((float) 9007199254740993) is 9,007,199,254,740,992.
     *
     * This is unreachable at school-fee magnitudes — a term bill is around 1.25e7 kobo, nine
     * orders of magnitude below the boundary — so the practical stake here is small and is
     * named as small. What is NOT small is that a formatter is the last thing that should be
     * able to alter the figure it displays. This method takes the exact decimal string
     * toNaira() already produced and only punctuates it: split off the sign, reverse, comma
     * every three digits, reverse back. No float appears anywhere in the path, so the property
     * is structural rather than contingent.
     *
     * A NOTE ON THE CLAIM THIS REPLACES. OpeningBalanceInterpretation::naira(), which
     * introduced this technique, said number_format "casts to float and would lose precision".
     * On PHP 8.3.32 no coercion is OBSERVABLE — number_format(9007199254740993) returns the
     * exact ...993, which a coerce-then-format path could not produce, since the double for
     * that value is ...992. That is an observation about one build, not a mechanism, and the
     * decision above deliberately does not rest on it either way.
     *
     * NGN-ONLY, and that is a constraint rather than an omission: ₦ is a naira mark, so a
     * foreign currency rendered through here would be mislabelled rather than merely
     * mis-styled. Refuse it, the way the constructor refuses a bad currency code.
     */
    public function format(): string
    {
        if ($this->currency !== self::DEFAULT_CURRENCY) {
            throw new InvalidArgumentException(
                "format() expects an NGN amount, got [{$this->currency}]."
            );
        }

        [$whole, $fraction] = explode('.', $this->toNaira());

        $sign = str_starts_with($whole, '-') ? '-' : '';
        $digits = ltrim($whole, '-');
        $grouped = strrev(implode(',', str_split(strrev($digits), 3)));

        return $sign.'₦'.$grouped.'.'.$fraction;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Exact integer scaling only (quantity × unit price) — no rounding. The dividing,
     * rounding-bearing ops are percentage() and allocate() below.
     */
    public function times(int $multiplier): self
    {
        return new self($this->minorUnits * $multiplier, $this->currency);
    }

    /**
     * `$percent`% of this amount, banker's-rounded (round-half-to-even) to the penny.
     *
     * The FIRST dividing operation on Money, and the reason the rounding policy had to
     * be signed before it could exist (§1). The division is done ENTIRELY in integer
     * minor units — `minorUnits * percent` is exact, and roundedDiv() rounds the /100
     * without any float ever entering — so ADR 0002's no-float invariant holds through
     * a division.
     *
     * Half-to-even, NOT half-up: a result landing exactly on .5 goes to the even
     * neighbour (5 kobo × 50% = 2.5 → 2, not 3). This is the distinguishing behaviour;
     * a half-up implementation passes every non-boundary case and fails only this one.
     */
    public function percentage(int $percent): self
    {
        return new self(self::roundedDiv($this->minorUnits * $percent, 100), $this->currency);
    }

    /**
     * Split this amount into `$parts` equal pieces whose sum is EXACTLY the original —
     * no penny created or lost. The indivisible remainder lands on the FINAL part (§1),
     * so `SUM(allocate($n)) === $this` for every $n and every amount.
     *
     * The general split primitive installments will reuse. This slice does not build
     * installment logic — only percentage reductions — but the primitive is written
     * generally because getting a divided-money op right once is the point.
     *
     * @return array<int, self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException("Cannot allocate into {$parts} parts; expected at least 1.");
        }

        // intdiv truncates toward zero, which is what we want: the first n-1 parts are
        // the truncated base and the last carries whatever is left, so the sum is exact
        // for negative amounts too.
        $base = intdiv($this->minorUnits, $parts);
        $pieces = [];
        for ($i = 0; $i < $parts - 1; $i++) {
            $pieces[] = new self($base, $this->currency);
        }
        $pieces[] = new self($this->minorUnits - $base * ($parts - 1), $this->currency);

        return $pieces;
    }

    /**
     * Integer division with banker's rounding (round-half-to-even). No float.
     *
     * Sign is factored out so the rounding is applied to the magnitude and reapplied —
     * half-to-even is symmetric about zero, and PHP's intdiv/% truncate toward zero,
     * which would otherwise make the boundary behaviour depend on sign.
     */
    private static function roundedDiv(int $numerator, int $denominator): int
    {
        $sign = $numerator < 0 ? -1 : 1;
        $n = abs($numerator);

        $quotient = intdiv($n, $denominator);
        $twiceRemainder = 2 * ($n % $denominator);

        // Past the halfway point → round away from zero. Exactly halfway → round to the
        // even quotient (bump only if the current quotient is odd).
        if ($twiceRemainder > $denominator
            || ($twiceRemainder === $denominator && $quotient % 2 === 1)) {
            $quotient++;
        }

        return $sign * $quotient;
    }

    public function equals(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits === $other->minorUnits;
    }

    /**
     * The canonical wire contract (Constitution rule 10): integer minor units +
     * currency string, NEVER a decimal. Implementing it on the VO fixes the shape
     * once, so every API Resource / response()->json() serialises Money the same
     * way by default instead of each consumer inventing its own shape.
     *
     * The key is `amount_minor` — the spec's vocabulary for a standalone money
     * amount (§12.9) — so the unit is explicit on the wire and a frontend can
     * never misread kobo as naira-major. Display divides by 100.
     *
     * @return array{amount_minor: int, currency: string}
     */
    public function toArray(): array
    {
        return ['amount_minor' => $this->minorUnits, 'currency' => $this->currency];
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: cannot combine {$this->currency} with {$other->currency}."
            );
        }
    }
}
