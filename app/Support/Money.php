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
 * ROUNDING BOUNDARY (hard constraint, §12.3): the only multiplication offered is
 * times(int) — exact integer scaling (quantity × unit price). Scalar/percentage
 * multiplication and any division are deliberately absent because they require a
 * rounding policy (banker's vs half-up, remainder absorption) that must be
 * co-signed by Brookstone Finance before the first Finance migration.
 * accounting-policy.md is unsigned, so that policy does not exist yet and MUST
 * NOT be guessed at here. Adding a rounding-bearing operation before the policy
 * is signed is a Constitution violation, not a feature.
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
     * Exact integer scaling only (quantity × unit price). No scalar, percentage or
     * fractional multiplication — those need the unsigned §12.3 rounding policy.
     */
    public function times(int $multiplier): self
    {
        return new self($this->minorUnits * $multiplier, $this->currency);
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
