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
