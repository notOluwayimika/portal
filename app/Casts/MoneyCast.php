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
 * A null money is BOTH columns null (symmetric). A partially-populated row —
 * amount without currency, or currency without amount — is not a valid Money
 * (Constitution rule 10: money is minor units + EXPLICIT currency), so get()
 * fails loudly rather than silently manufacturing a default currency. set()
 * always writes both or neither, so it can never create a partial row.
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
        $currency = $attributes[$this->currencyColumn] ?? null;

        // Symmetric null: a null money is both columns null.
        if ($amount === null && $currency === null) {
            return null;
        }

        // A partial row is not a valid money — fail loudly, never default.
        if ($amount === null || $currency === null) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] money attribute is partially populated (%s=%s, %s=%s); '
                .'a money must have both integer minor units and an explicit currency.',
                $key,
                $this->amountColumn, var_export($amount, true),
                $this->currencyColumn, var_export($currency, true),
            ));
        }

        // Money's constructor validates the currency format (three uppercase letters).
        return Money::fromKobo((int) $amount, $currency);
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
