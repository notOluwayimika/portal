<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;

/**
 * CONSTITUTION 13, AS A CALLABLE — the School-context guard every finance maker-checker Action runs
 * before it does anything.
 *
 * WHAT IT IS FOR, and the failure it exists to make impossible. `SchoolScope` filters every read on a
 * `BelongsToSchool` model by the active School. That is isolation when the context is right; when the
 * context is WRONG it is something else entirely — every read finds nothing, and **an action that
 * reads nothing does not refuse, it succeeds on an empty set**. No exception, no log, no refusal: a
 * governance act that reports success and did nothing. That is not a theoretical hazard, it is what
 * the twelve unguarded actions did before this commit, and it is why the guard must be an explicit
 * refusal rather than something left to the scope.
 *
 * AND FAIL-CLOSED SCOPING DOES NOT REPLACE THIS GUARD, WHICH IS WORTH SAYING NOW THAT IT IS ON.
 * `config/rbac.php` ships the finance transactional models in `fail_closed_models` as a versioned
 * default, so `SchoolScope` now THROWS on a read with no context. That closes a different hole from
 * this one, and the two do not overlap: fail-closed governs the NULL-context branch
 * (`SchoolScope::apply`, the `elseif` after the scoped branch), while this guard exists for the
 * WRONG-context branch — a maker in School A acting on School B's record, where a context is present,
 * the scope filters happily, and the read returns nothing. The scope cannot refuse that, because from
 * its side nothing is wrong. Fail-closed also only fires for an authenticated principal, so it is
 * silent off-request. Both of those are why this refusal stays explicit.
 *
 * TWO ENTRY POINTS, ONE IMPLEMENTATION, AND THE SPLIT IS THE POINT.
 *
 *   require()    — refuses a null context and returns the School id.
 *   assertOwns() — calls require(), then refuses a record belonging to another School.
 *
 * A single method taking a NULLABLE record would have been shorter and is the shape this deliberately
 * refuses: it makes the full guard and the weaker one identical at every call site, identical to the
 * lint that checks them, and indistinguishable to a reader. The next action that passes a nullable by
 * accident would get half the coverage and a green gate — a check that cannot be shown to be doing
 * what it claims. Two names put the weaker case in the SOURCE, where the lint can require the
 * stronger one everywhere except the one place that has argued for the weaker.
 *
 * WHY `App\Support` AND NOT A TRAIT. A trait would be the only reason most of these classes used one,
 * and `use SomeTrait;` at the top of fifteen files is a worse grep target than one qualified static
 * call inside each method — the lint reads call sites, not class definitions, so the guard has to be
 * visible where it runs. It sits beside `ActiveSchool` because it is that primitive's enforcement
 * half, and it stays out of `ActiveSchool` itself so that reading the context and refusing to act
 * without one remain separately greppable.
 *
 * THE MESSAGES ARE THE CALLER'S, and that is a requirement rather than a nicety. The three actions
 * that already guarded said "That opening-balance batch belongs to another School." — a generic
 * "That record belongs to another School." is a downgrade an operator feels at exactly the moment
 * they are trying to work out what went wrong. The noun comes from the call site; the article is
 * derived so a caller passes one string rather than two that can disagree.
 */
final class SchoolContext
{
    /**
     * Refuse to act without a School context, and return the id of the one we have.
     *
     * THE WHOLE GUARD, on a path where nothing school-owned exists yet to compare against. That is a
     * narrow case and it is named rather than assumed: see `SubmitDiscountPolicyChange`, whose
     * `create` kind carries no target policy by design, so the context is the only school-sensitive
     * surface it has and this closes all of it.
     *
     * Every other caller wants {@see self::assertOwns} — and the boundary lint enforces that, with
     * exactly one allowlisted exception, so "I only needed the context" cannot quietly become the
     * default.
     *
     * @param  string  $noun  the thing being acted on, WITHOUT an article: 'opening-balance batch'
     * @param  string  $verb  the past participle of the act: 'submitted', 'approved', 'rejected'
     *
     * @throws BusinessRuleException
     */
    public static function require(string $noun, string $verb): int
    {
        $schoolId = ActiveSchool::id();

        if ($schoolId === null) {
            throw new BusinessRuleException(
                'No active School context: '.self::article($noun).' '.$noun.' cannot be '.$verb.'.'
            );
        }

        return $schoolId;
    }

    /**
     * Refuse to act without a context, AND refuse to act on a record belonging to another School.
     *
     * The second half is what the scope cannot give you. A mismatched context makes a read find
     * nothing — which an action reports as success — while a record handed in directly (a console
     * run, a queued job, a unit test, anything that did not resolve it through route binding) is
     * acted on whether or not it belongs here.
     *
     * @param  Model  $record  a `BelongsToSchool` model, or the subject a governance row will be created from
     * @param  string  $noun  the thing being acted on, WITHOUT an article: 'fee-schedule change'
     * @param  string  $verb  the past participle of the act: 'submitted', 'approved', 'rejected'
     *
     * @throws BusinessRuleException
     */
    public static function assertOwns(Model $record, string $noun, string $verb): int
    {
        $schoolId = self::require($noun, $verb);

        // Compared as ints, and the cast is on the record's side because an attribute arriving from a
        // raw query or a factory can be a numeric string while ActiveSchool::id() is typed int. A `!==`
        // between the two would report the SAME school as different and refuse a legitimate act —
        // the direction that fails loudly rather than dangerously, but still wrongly.
        if ((int) $record->getAttribute('school_id') !== $schoolId) {
            throw new BusinessRuleException('That '.$noun.' belongs to another School.');
        }

        return $schoolId;
    }

    /**
     * 'a' or 'an', so a caller passes ONE noun rather than two strings that can disagree.
     *
     * Deliberately the crude vowel rule and not a linguistics library: the nouns are a closed set
     * written into fifteen call sites in this repository, and SchoolContextGuardTest pins the three
     * pre-existing messages byte-for-byte, so a noun this rule got wrong would fail there rather than
     * reach an operator.
     */
    private static function article(string $noun): string
    {
        return in_array(mb_strtolower(mb_substr($noun, 0, 1)), ['a', 'e', 'i', 'o', 'u'], true)
            ? 'an'
            : 'a';
    }
}
