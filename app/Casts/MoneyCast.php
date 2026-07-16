<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a Money value object to/from its two storage columns (§12.1): an integer
 * minor-units (kobo) column and an ISO 4217 currency column. The round-trip is
 * exact because storage is integer + string, never a float or a `decimal:` cast
 * (which the M1.5 arch/lint gate will ban).
 *
 * The column names are cast arguments, so a model declares e.g.
 *   protected $casts = ['balance' => MoneyCast::class.':balance_kobo,balance_currency'];
 * and default to 'amount' / 'currency' when omitted.
 *
 * A null amount round-trips as a null Money (both columns null), so nullable
 * money attributes behave normally.
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly string $amountColumn = 'amount',
        private readonly string $currencyColumn = 'currency',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $amount = $attributes[$this->amountColumn] ?? null;

        if ($amount === null) {
            return null;
        }

        return Money::fromKobo(
            (int) $amount,
            $attributes[$this->currencyColumn] ?? Money::DEFAULT_CURRENCY,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$this->amountColumn => null, $this->currencyColumn => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                sprintf('The [%s] attribute must be a %s instance.', $key, Money::class)
            );
        }

        return [
            $this->amountColumn => $value->minorUnits,
            $this->currencyColumn => $value->currency,
        ];
    }
}
