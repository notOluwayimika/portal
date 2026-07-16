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
 * get() contract for the two configured columns (money is minor units + EXPLICIT
 * currency, Constitution rule 10), distinguishing "not selected" from "NULL at
 * rest" via array_key_exists:
 *   1. Either column NOT selected -> throw a query-construction error naming the
 *      unselected column. NEVER returns null on a partial select — a silently
 *      null money in a Phase-2 report is worse than an exception.
 *   2. Both selected and NULL      -> return null (a legitimate absence of value).
 *   3. Both selected, exactly one NULL -> throw a data-integrity error (corrupt
 *      row). Its message differs from case 1 so the reader looks at the data, not
 *      the query.
 * set() always writes both columns or neither, so it can never create a case-3 row.
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
        // array_key_exists (not ??) so "not selected" is distinguished from
        // "selected and NULL" — they have different causes and different fixes.
        $hasAmount = array_key_exists($this->amountColumn, $attributes);
        $hasCurrency = array_key_exists($this->currencyColumn, $attributes);

        // Case 1: a configured column was not selected, so the value cannot be
        // reconstructed. This is a query-construction error upstream — never a
        // silent null (a null money in an aged-debtors/collections report is worse
        // than an exception). Name the unselected column(s) so the fix is the
        // select(), not a corruption hunt.
        if (! $hasAmount || ! $hasCurrency) {
            $unselected = array_values(array_filter([
                $hasAmount ? null : $this->amountColumn,
                $hasCurrency ? null : $this->currencyColumn,
            ]));

            throw new InvalidArgumentException(sprintf(
                'Cannot reconstruct the [%s] money attribute: column(s) [%s] were not selected. '
                .'Select both [%s] and [%s], or omit [%s] from the query.',
                $key,
                implode(', ', $unselected),
                $this->amountColumn,
                $this->currencyColumn,
                $key,
            ));
        }

        $amount = $attributes[$this->amountColumn];
        $currency = $attributes[$this->currencyColumn];

        // Case 2: both selected and NULL — a legitimate absence of value.
        if ($amount === null && $currency === null) {
            return null;
        }

        // Case 3: both selected, exactly one NULL — a data-integrity violation at
        // rest (a corrupt row). Distinct message from case 1 so the reader looks at
        // the data, not the query.
        if ($amount === null || $currency === null) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] money attribute is corrupt at rest (%s=%s, %s=%s): '
                .'a stored money must have both integer minor units and currency, or both NULL.',
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
