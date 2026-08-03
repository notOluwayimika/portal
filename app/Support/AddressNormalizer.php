<?php

namespace App\Support;

use Normalizer;

/**
 * The single definition of "the same address".
 *
 * ONE PRIMITIVE, TWO CALLERS, AND THAT IS THE WHOLE POINT. The suppression WRITE
 * (a bounce webhook, an inbound STOP) and the send-time CHECK both reduce an
 * address to a comparable form. If they ever disagree — one lowercases and the
 * other does not — a suppressed address sails through the check and mail goes to
 * someone who asked us to stop. Nothing fails, nothing logs, every test stays
 * green. That is the same silent-green signature as a hook that never fires,
 * relocated from the audit log into the send loop.
 *
 * DELIBERATELY CONSERVATIVE — NO PROVIDER-AWARE CANONICALIZATION. Gmail treats
 * `foo+school@` and `foo@` as one mailbox and ignores dots; this does not, and
 * must not. In a SUPPRESSION KEY, over-canonicalizing OVER-SUPPRESSES: fold
 * `foo+a@` into `foo@` and one bounce mutes a mailbox the recipient deliberately
 * kept separate. Plus-addressing is exactly how a parent separates school mail, so
 * the failure would land on the most organised users first. Under-normalizing
 * costs a duplicate row; over-normalizing costs a silenced parent.
 *
 * NULL MEANS "NOT AN ADDRESS", and callers must treat it as such rather than
 * storing the raw value. Guardian phone numbers were captured with no validation
 * at all — the synthetic `{phone}@no-email.local` address was never sent to, so
 * nothing ever had to parse them — which means the column contains `n/a`, `-`,
 * and names typed into the wrong field. Those normalize to null and mint no
 * contact point, which is why a count of `TRIM(phone) <> ''` is a CEILING on how
 * many rows will actually reroute, never the figure.
 */
final class AddressNormalizer
{
    /** E.164 permits at most 15 digits; fewer than 7 is not a routable subscriber number. */
    private const MIN_DIGITS = 7;

    private const MAX_DIGITS = 15;

    /**
     * Lowercase, trimmed, Unicode-normalised. Null when it is not an address.
     *
     * NFC matters because a composed and a decomposed form of the same accented
     * domain are different byte strings and would hash differently — so the same
     * mailbox could be suppressed and still deliverable at once.
     */
    public static function email(?string $raw): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        $value = mb_strtolower($value, 'UTF-8');

        // Structural check only — deliverability is the provider's answer, not
        // ours. This rejects the values that are plainly not addresses (`n/a`, a
        // phone number typed into the email field) without pretending to validate.
        if (! str_contains($value, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $value, 2);

        if ($local === '' || $domain === '' || ! str_contains($domain, '.')) {
            return null;
        }

        return $value;
    }

    /**
     * E.164, or null.
     *
     * The default region exists because this population writes national-format
     * numbers: `08031234567` is the ordinary way a Nigerian number is typed, and
     * it is meaningless without knowing the country. Configurable rather than
     * hard-coded so a second school in another country is a config change.
     */
    public static function phone(?string $raw, ?string $callingCode = null): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        $callingCode = ltrim($callingCode ?? (string) config('notifications.default_calling_code', '234'), '+');

        // An explicit `+` is the only prefix that means "already international".
        // Captured BEFORE stripping, because the strip removes it.
        $isExplicitlyInternational = str_starts_with($value, '+');

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            // `n/a`, `-`, `none`, a name. The reason a phone-string count
            // overstates how many rows reroute.
            return null;
        }

        // THE MINIMUM APPLIES TO WHAT WAS TYPED, not to what we synthesise.
        // Checking only the final E.164 lets the prefixing rule RESCUE garbage:
        // `12345` has no trunk prefix and no country code, so it would gain one and
        // become `+23412345` — eight digits, structurally valid, and a number nobody
        // has. Rejecting a short input is right; inventing a country for it is not.
        if (strlen($digits) < self::MIN_DIGITS) {
            return null;
        }

        if (! $isExplicitlyInternational) {
            if (str_starts_with($digits, '00')) {
                // International access code — `00234…` is `+234…`.
                $digits = substr($digits, 2);
            } elseif (str_starts_with($digits, '0')) {
                // National trunk prefix: drop the 0, prepend the country.
                $digits = $callingCode.substr($digits, 1);
            } elseif (! str_starts_with($digits, $callingCode)) {
                // A bare subscriber number with neither trunk prefix nor country.
                $digits = $callingCode.$digits;
            }
        }

        $length = strlen($digits);

        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            // Truncated entries, extensions typed into the field, and the
            // occasional date. Rejecting is correct: minting a contact point for
            // an unroutable number produces sends that fail forever.
            return null;
        }

        return '+'.$digits;
    }

    /**
     * The lookup key for an ADDRESS-scoped suppression.
     *
     * ⚠️ THIS BUYS UNIQUENESS AND FIXED WIDTH, NOT PRIVACY, and the distinction is
     * worth stating because a hash column invites the opposite assumption. It
     * cannot be salted — a suppression lookup has to be deterministic from the
     * address alone — so anyone holding this table and this class recovers common
     * addresses by brute force trivially. It is an index key, not a protection.
     *
     * Which is exactly why `normalized_address` is stored ALONGSIDE it: a
     * hash-only table cannot answer "why did this not send?" without already
     * knowing the address, and that is the single most common support question
     * this machinery will be asked.
     */
    public static function hash(string $normalizedAddress): string
    {
        return hash('sha256', $normalizedAddress);
    }
}
