<?php

namespace App\Support;

class PhoneNormalizer
{
    /**
     * Default country dialing code used when normalizing local (leading 0) numbers.
     * Nigeria (+234) for now; if multi-country support is needed later, pull from school settings.
     */
    private const DEFAULT_DIAL_CODE = '234';

    /**
     * Return a canonical form of a phone number suitable for storage and comparison.
     * Strips formatting (spaces, dashes, parens), converts leading 0 to the default
     * country dial code, and ensures a leading + for E.164 output.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $cleaned = preg_replace('/[\s\-\(\)\.]/', '', $raw);
        if ($cleaned === '' || $cleaned === null) {
            return null;
        }

        // Already starts with + → keep digits after.
        if (str_starts_with($cleaned, '+')) {
            $digits = preg_replace('/\D/', '', $cleaned);
            return $digits === '' ? null : '+' . $digits;
        }

        $digits = preg_replace('/\D/', '', $cleaned);
        if ($digits === '') {
            return null;
        }

        // Local "0XXXXXXXXXX" → swap leading 0 for the default country code.
        if (str_starts_with($digits, '0')) {
            $digits = self::DEFAULT_DIAL_CODE . substr($digits, 1);
        }

        return '+' . $digits;
    }

    public static function equals(?string $a, ?string $b): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);

        return $na !== null && $nb !== null && $na === $nb;
    }
}
