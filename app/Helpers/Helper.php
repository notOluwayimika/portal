<?php

use Carbon\Carbon;

if (!function_exists('normalizeDate')) {
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
        } catch (\Exception) {
            return null;
        }
    }
}

if (!function_exists('isValidDate')) {
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
        } catch (\Exception) {
            return false;
        }
    }
}

if (!function_exists('encodeData')) {
    /**
     * Encoding Hashids
     */
    function encodeData(int $id): String
    {
        $hashIds = new Hashids;
        $id = $hashIds->encode($id);

        return $id;
    }
}

if (!function_exists('decodeData')) {
    /**
     * Decoding Hashids
     */
    function decodeData(String $data): array
    {
        $hashIds = new Hashids;
        $id = $hashIds->decode($data);

        return $id;
    }
}