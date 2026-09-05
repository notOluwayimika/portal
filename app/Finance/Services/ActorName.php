<?php

namespace App\Finance\Services;

use App\Models\User;

/**
 * THE PERSON WHO DID IT, BY NAME — for a refusal sentence an operator has to act on.
 *
 * `docs/handoff/tickets/the-fold-refusal-names-ids-where-the-gate-names-the-class.md` is the
 * argument and it is not re-derived here: a remedy is "correct and unactionable in the same breath"
 * when it names the thing in a vocabulary the operator does not have. `user#7` is that vocabulary.
 * There is no screen in this product where a bursar or an auditor looks a colleague up by integer
 * id, so "already released by user#7" tells them a release happened and nothing they can act on.
 * `ReturnedInvoiceQueueController`'s docblock already made the same call for a whole screen — "THE
 * BILL BY ITS NUMBER, THE RETURNER BY THEIR NAME" — and explicitly deferred the refusal sentences
 * inside `ApproveInvoice` and `ReturnInvoice` to "its own commit". This is that commit.
 *
 * ─── IT IS SCOPED, AND WHY THAT IS NOT PARANOIA ─────────────────────────────────────────────────
 *
 * `SchoolScope` does not apply to `User` AT ALL. `SchoolScope::apply() (app/Models/Scopes/SchoolScope.php:24)`
 * returns early on a User instance, deferring per-school access to `SetSchoolContext`. So a bare
 * `User::find($id)` resolves a user in ANY School, and a refusal message that renders whatever name
 * an id happens to resolve to is a name-disclosure oracle: it needs only one bug upstream putting a
 * foreign id in `reviewed_by_user_id` to start reading another School's staff list out loud, one
 * name per refusal.
 *
 * Nothing reaches it today. Both writers assert `SchoolContext::assertOwns($invoice)` and then
 * `$actor->can('finance.invoice.approve'|'…reject')` under that School's permissions team, so the
 * id on the row belongs to someone with standing in the School. The scope here is the second
 * expression of that fact, placed where the DISCLOSURE happens rather than where the write does.
 *
 * WHAT ENFORCES IT: `User::hasStandingInSchool()`, which reads BOTH access sources rather than
 * whichever `rbac.single_source_access` currently returns. See that method for why the narrower
 * `canAccessSchool()` is the wrong instrument here — its false negative is a sentence that tells an
 * auditor a real colleague's account no longer exists.
 *
 * ─── IT NEVER THROWS, AND IT NEVER FALLS BACK TO `user#<id>` ────────────────────────────────────
 *
 * Every caller is already handling the unhappy case; a resolver that threw would replace a
 * refusal an operator can act on with a 500 they cannot. So the failure mode is a NULL, and the
 * caller renders a sentence that still names the bill, the act and the date.
 *
 * The null is reachable. `reviewed_by_user_id` and `returned_by_user_id` are LOOKUP columns, not
 * foreign keys — plain nullable `unsignedBigInteger`, no `constrained()`, stated outright in both
 * migrations (`2026_08_31_100000:50-53`, `2026_09_04_100000:125-128`) and confirmed by there being
 * no `foreign`/`constrained`/`references` clause naming either column anywhere in
 * `database/migrations`. Nothing cascades and nothing restricts, so a user row can be removed from
 * underneath one and the id will still be sitting on the invoice. This is the same reading
 * `ReturnedInvoiceQueueController::returnerNames()` made for the queue payload, and it renders the
 * same distinction: the absence of a name is "we cannot tell you who", never "nobody".
 *
 * Degrading to `user#<id>` would reintroduce exactly what this class removes, so it is not an
 * option the callers are given — this returns a name or nothing.
 *
 * ─── THE MEMO IS KEYED BY SCHOOL, AND THAT IS THE POINT ─────────────────────────────────────────
 *
 * `ReturnedInvoiceQueueController` argues against a static helper for its own page: "a static would
 * survive into the next request under a long-running worker and serve one school's names to
 * another". That hazard is real and it is closed the way `SchoolFinanceSettings::$prefixMemo` closes
 * the same one — the key is `<schoolId>:<userId>`, so a memo entry can only ever be returned for the
 * School it was resolved under. What survives a request boundary is a name that may since have been
 * edited; `flushMemo()` is there for the worker and the test that needs it, exactly as
 * `flushPrefixMemo()` is.
 *
 * The memo is also what keeps `InvoiceReviewController::approve()`'s batch honest. It catches
 * `BusinessRuleException` PER ITEM inside a loop, so a batch where every item refuses would
 * otherwise resolve the same reviewer once per item.
 */
final class ActorName
{
    /** @var array<string, string|null> keyed "<schoolId>:<userId>" */
    private static array $memo = [];

    /**
     * The display name of $userId as seen from $schoolId, or null when it cannot be told.
     *
     * Null means one of two things and deliberately does not distinguish them, because the caller's
     * sentence is the same either way: no user row with that id, or a user row with no standing in
     * this School.
     */
    public static function forSchool(?int $userId, int $schoolId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $key = $schoolId.':'.$userId;

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        // NO SCHOOL PREDICATE ON THE QUERY ITSELF — and the honest reason is not the one this
        // comment used to give. It said "Constitution 13 forbids reading access off
        // `users.school_id`", which reads as though the column plays no part; it does.
        // `hasStandingInSchool()` (app/Models/User.php:404) defers to
        // `legacyAccessibleSchoolIds()` (app/Models/User.php:324), whose last act is
        // `$ids->push($this->school_id)` at :336. Declining the filter here and then admitting the
        // column one call later is a description contradicting its own artifact.
        //
        // WHAT IS TRUE: the legacy union is a deliberately WIDER standing test than the
        // single-source path — the `school_user` pivot, guardian records AND the home-school
        // column — and that widening is the whole point. It is what avoids the S7 false negative
        // `hasStandingInSchool()`'s own docblock is about: under the current default a user whose
        // standing comes only from a ROLE resolves to `[]` on the narrow path, and this class would
        // then tell an auditor that a present colleague's account no longer exists.
        //
        // So the scope is wider than a `where('school_id', …)` would be, not narrower, and a
        // per-column filter here would be the narrow test wearing a broad one's clothes. Behaviour
        // unchanged; nothing reachable exercises the difference, because both writers assert
        // `SchoolContext::assertOwns()` and then `can()` under the School's permissions team before
        // any name is rendered.
        $user = User::query()->whereKey($userId)->first();

        if ($user === null || ! $user->hasStandingInSchool($schoolId)) {
            return self::$memo[$key] = null;
        }

        // `full_name` is the accessor (`User::getFullNameAttribute()`), not a second concatenation
        // of the two columns. Trimmed because a user with no last name would otherwise render with
        // a trailing space that reads as a rendering bug.
        $name = trim((string) $user->full_name);

        return self::$memo[$key] = $name === '' ? null : $name;
    }

    /**
     * The `by …` clause for a refusal sentence: the person's name when it can be told, and an
     * honest statement that it cannot when it cannot.
     *
     * ONE HELPER RATHER THAN A BRANCH PER MESSAGE. Four of the callers' sentences differ only in
     * what surrounds this clause, and spelling the fallback four times is four places for the two
     * halves to drift apart.
     */
    public static function byClauseFor(?int $userId, int $schoolId): string
    {
        $name = self::forSchool($userId, $schoolId);

        return $name === null
            ? 'by someone whose user account can no longer be found'
            : 'by '.$name;
    }

    /** Drop the memo. For a long-running worker, and for a test that renames a user mid-request. */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }
}
