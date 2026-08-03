<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
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
    /**
     * Number types that can actually receive a message.
     *
     * VALID IS NOT THE SAME AS REACHABLE, and this is where the first cut of this
     * class was wrong twice over. `08000000000` — what gets typed when a phone field
     * is required and unknown — is a genuinely VALID Nigerian number: `0800` is an
     * assignable toll-free range. It is simply not one you can send SMS to. Checking
     * validity alone would mint a contact point for it and report the row as
     * successfully rerouted.
     *
     * FIXED_LINE_OR_MOBILE is included because number plans that do not distinguish
     * the two return it for ordinary mobiles; excluding it would reject real
     * recipients in those countries.
     */
    private const REACHABLE_TYPES = [
        PhoneNumberType::MOBILE,
        PhoneNumberType::FIXED_LINE_OR_MOBILE,
    ];

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
     * E.164, or null — validated against the REGION'S ACTUAL NUMBER PLAN.
     *
     * WHY NOT A LENGTH RANGE, which is what this did first. A digit-count check
     * validates FORMATTING, not existence, and the two are different axes. The first
     * cut rejected `12345` for being short and happily minted `+2341234567890` from
     * `1234567890` — thirteen digits, in range, a `123` prefix no carrier issues. It
     * even emitted `+000000000` for `00000000000`, reading the leading zeroes as an
     * international access code and producing a country code of `0`, which is not
     * assignable in E.164 at all. Same class of defect as the five-digit case,
     * surviving the fix that was supposed to close it.
     *
     * A hand-maintained prefix list would be the other way to close it, and would be
     * the denylist-drift antipattern this codebase has already rejected twice (see
     * ApprovalAbility: convention, never a list). Carriers gain prefixes; the list
     * would rot silently. libphonenumber IS the maintained plan, versioned with the
     * dependency.
     *
     * IT ALSO MAKES THE REGION PROMISE TRUE. The docblock used to claim the calling
     * code was "configurable so a second country is a config change", while
     * MIN_DIGITS/MAX_DIGITS were region-BLIND and could not validate any country's
     * plan. The config now names a REGION, and validity is evaluated against it.
     */
    public static function phone(?string $raw, ?string $region = null): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        // `00` → `+` BEFORE parsing, and this is formatting, not a validity claim.
        // libphonenumber correctly knows Nigeria's international prefix is `009`, so
        // it rejects `00234…` as not-dialable-from-NG. But this column holds STORED
        // CONTACT DATA, not a dialling sequence: `00` is the ITU-recommended
        // international prefix and is simply how people write a foreign number.
        // Rejecting it would drop real numbers the previous implementation accepted.
        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        $region = strtoupper($region ?? (string) config('notifications.default_region', 'NG'));
        $util = PhoneNumberUtil::getInstance();

        try {
            // Parses national (`08031234567`), international (`+234…`) and
            // access-coded (`00234…`) forms; the region only supplies the country
            // for the national case, so an explicit `+` is never re-homed.
            $parsed = $util->parse($value, $region);
        } catch (NumberParseException) {
            // `n/a`, `-`, a name typed into the phone field. The reason a SQL count
            // of `TRIM(phone) <> ''` overstates how many rows will reroute.
            return null;
        }

        if (! $util->isValidNumber($parsed)) {
            return null;
        }

        if (! in_array($util->getNumberType($parsed), self::REACHABLE_TYPES, true)) {
            // Valid, but not messageable — a toll-free, premium-rate or fixed line.
            return null;
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
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
