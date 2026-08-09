<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * What day is it, for a school?
 *
 * NOT the same question as `now()->toDateString()`, which answers what day it is for the SERVER.
 * `config/app.php` sets `timezone => 'UTC'`, so between 00:00 and 01:00 West Africa Time the server
 * is still on the previous day — and every business date derived from it is a day early for one hour
 * out of every twenty-four.
 *
 * That is not hypothetical. It shipped: RecordPaymentRequest required a back-dating REASON whenever
 * `received_at` differed from the server's today, so a bursar recording a payment at 00:30 in Lagos
 * had their own current date rejected as back-dated, for a field the modal had not rendered because
 * the browser (correctly) thought it was today. One hour a day, the money-in path refused work.
 *
 * WHY THIS IS A CONSTANT AND NOT A PER-SCHOOL COLUMN, argued rather than defaulted:
 *
 *   1. A column would need a default, and the default would be this value — so the constant has to
 *      exist either way. The column adds a way to CHANGE it, not a way to KNOW it.
 *   2. finance_school_settings carries one substantive column today and no screen to set it from.
 *      A setting nobody can reach through a UI is another unreachable surface, and this platform has
 *      produced four of those in a fortnight.
 *   3. Both schools are Brookstone, in Nigeria. One timezone is not a simplification here, it is the
 *      truth.
 *
 * The condition under which that flips is recorded as a ticket: the first school outside West Africa
 * makes this wrong, and the fix then is a per-school column with this value as its default — the
 * primitive arriving when its consumer does, rather than ahead of it.
 *
 * DELIBERATELY NOT `config('app.timezone')`. Reading the app timezone would make this helper agree
 * with the server rather than with the school, which is the entire bug. It is also not
 * `config()`-driven for its own key: a business date that a deploy can change by forgetting an
 * environment variable is the failure mode RBAC_FAIL_CLOSED_MODELS was moved into the repository to
 * end.
 *
 * WHAT THIS DOES NOT FIX. The stored timestamps are offset by the database server's own timezone —
 * dev's MySQL runs +01:00 and production's +05:30, neither pinned by anything in this repository —
 * so SQL-side date functions (`whereDate`, raw `DATE()`) file rows under the wrong day independently
 * of anything here. That is a separate, larger defect; see the ticket. This helper governs dates the
 * APPLICATION derives, which is the half that can be fixed without a maintenance window.
 */
final class SchoolDay
{
    /**
     * The schools' timezone. Nigeria observes no daylight saving, so this is a fixed +01:00 —
     * which is why a fixed offset would also have worked and is still the wrong thing to write:
     * the name survives a rule change, an offset does not.
     */
    public const TIMEZONE = 'Africa/Lagos';

    /** Today's date in the school's timezone, as the `Y-m-d` every business-date column stores. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /**
     * The current moment, expressed in the school's timezone.
     *
     * The instant is the same one `now()` returns — only the wall-clock reading differs, which is
     * the whole point: the same moment is "23:30 on the 9th" to the server and "00:30 on the 10th"
     * to the school, and the school's answer is the one a ledger means.
     */
    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }
}
