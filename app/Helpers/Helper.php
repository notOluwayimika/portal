<?php

use App\Support\Money;
use Carbon\Carbon;

if (! function_exists('normalizeDate')) {
    /**
     * Normalize a date value that may be an Excel serial integer or a date string.
     * Always returns a Y-m-d string or null.
     */
    function normalizeDate(mixed $value): ?string
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        // Excel stores dates as days since 1900-01-00 (with the Lotus 1-2-3 leap-year bug).
        // Subtract 25569 to get Unix days, multiply by 86400 for seconds.
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp(((int) $value - 25569) * 86400)
                ->utc()
                ->format('Y-m-d');
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Exception) {
            return null;
        }
    }
}

if (! function_exists('isValidDate')) {
    /**
     * Check whether a raw value can be resolved to a valid calendar date.
     */
    function isValidDate(mixed $value): bool
    {
        if (is_numeric($value)) {
            // Any positive Excel serial is considered a valid date representation.
            return (int) $value > 0;
        }

        try {
            Carbon::parse($value);

            return true;
        } catch (Exception) {
            return false;
        }
    }
}

if (! function_exists('encodeData')) {
    /**
     * Encoding Hashids
     */
    function encodeData(int $id): string
    {
        $hashIds = new Hashids;
        $id = $hashIds->encode($id);

        return $id;
    }
}

if (! function_exists('decodeData')) {
    /**
     * Decoding Hashids
     */
    function decodeData(string $data): array
    {
        $hashIds = new Hashids;
        $id = $hashIds->decode($data);

        return $id;
    }
}

if (! function_exists('formatNaira')) {
    /**
     * Format a Money value as a Naira string, e.g. "₦1,234.56", "-₦12.05".
     *
     * NGN-specific by design (the ₦ symbol): rejects non-NGN Money so a foreign
     * currency can never be mislabelled as naira. Formatting is exact — it reads
     * the integer minor units, never a float.
     */
    function formatNaira(Money $amount): string
    {
        if ($amount->currency !== Money::DEFAULT_CURRENCY) {
            throw new InvalidArgumentException(
                "formatNaira() expects an NGN amount, got [{$amount->currency}]."
            );
        }

        $kobo = abs($amount->toKobo());
        $major = number_format(intdiv($kobo, 100));
        $minor = str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);

        return ($amount->isNegative() ? '-₦' : '₦')."{$major}.{$minor}";
    }
}
