<?php

namespace App\Finance\Services;

use Illuminate\Support\Str;

/**
 * The reference this system hands the gateway, and the ONLY thing that reads it back.
 *
 * ── WHY THE SCHOOL IS IN THE REFERENCE ──
 *
 * A webhook arrives with no session, no user and therefore no `ActiveSchool`. Something has to
 * decide which school's transaction a delivery belongs to, and there are only two ways to do it:
 *
 *   1. Look the reference up ACROSS ALL SCHOOLS with the global scope removed, then adopt whatever
 *      school the row names.
 *   2. Carry the school in the reference, enter that school's context, and look the reference up
 *      WITH the scope intact.
 *
 * This is (2), and the difference is not stylistic. Under (1) the isolation boundary is switched
 * off for the lookup and re-established from data the lookup itself returned — the boundary is
 * trusting the row it was meant to be guarding. Under (2) `SchoolScope` is never disabled: a
 * delivery naming school A cannot read a row belonging to school B even if it tries, because the
 * query is scoped before it runs. `bin/ci-boundary-lint.php` forbids `withoutGlobalScope` inside
 * `app/Finance` precisely to stop (1) being reached for, and it was right to.
 *
 * THE SCHOOL ID IS NOT A SECRET AND IS NOT A CREDENTIAL. A forged reference naming school B buys
 * nothing: the lookup still requires the full reference to exist within that school, and the
 * delivery still has to carry a valid HMAC over the whole body. What the id does is tell a scoped
 * query WHERE to look. Treating it as sensitive would be the wrong reading — it is a routing
 * segment, and the parent never sees it in any case.
 *
 * ── ONE MINTER, ONE PARSER ──
 *
 * The format is a contract between the initialise call that mints a reference and the webhook that
 * reads it back, and those two are written months apart by different hands. Both go through here.
 * A second place that builds a reference by string concatenation is how the webhook comes to
 * receive references it cannot route, and the symptom would be silent: an unroutable reference is
 * acknowledged with 200 and no payment, which looks exactly like a delivery for a transaction we
 * never issued.
 */
final class GatewayReference
{
    /**
     * Distinguishes our references from anything else that could appear in the field, and gives the
     * parser something to refuse on.
     */
    public const PREFIX = 'bpsk';

    private const SEPARATOR = '-';

    /**
     * Paystack accepts alphanumerics plus `-`, `.` and `=` in a reference. Only `-` is used, so the
     * format survives being pasted into a URL, a CSV and a shell without quoting.
     */
    public static function mint(int $schoolId): string
    {
        return implode(self::SEPARATOR, [self::PREFIX, $schoolId, Str::lower(Str::random(24))]);
    }

    /**
     * The school a reference routes to, or NULL if this reference did not come from us.
     *
     * NULL IS A ROUTING ANSWER, NOT AN ERROR. A webhook may legitimately receive a reference this
     * system never minted — a test delivery from the Paystack dashboard, a replay from another
     * integration on the same account — and the caller acknowledges those with 200. Throwing here
     * would turn someone else's test button into a 500 and a retry storm.
     */
    public static function schoolIdFrom(string $reference): ?int
    {
        $parts = explode(self::SEPARATOR, $reference);

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        // ctype_digit, not is_numeric or a cast: `(int) '1e3'` is 1000 and `(int) '2 '` is 2, so a
        // cast would route a malformed reference to a real school rather than refusing it.
        if (! ctype_digit($parts[1]) || $parts[1] === '0') {
            return null;
        }

        return (int) $parts[1];
    }
}
