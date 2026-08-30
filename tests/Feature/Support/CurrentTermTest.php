<?php

/*
 * WHICH TERM IS "CURRENT" WHEN NO TERM IS ACTIVE — the six states, and the two the `order`-only
 * fallback answers wrongly.
 *
 * `terms.status` is an enum of THREE values — active | upcoming | completed, default `upcoming`,
 * declared at database/migrations/2026_05_06_082137_create_terms_table.php:22 (status) — and the
 * resolver only ever tested `active`. Its fallback then took the LAST term by `order`, which cannot
 * tell a term the school has FINISHED from one it has not reached. That is precisely the information
 * the fallback needs, and the two states below are where the difference shows:
 *
 *   Term 1 completed, 2 and 3 upcoming  — mid-session, between terms. The school most recently
 *                                         finished Term 1; the `order`-only fallback answered
 *                                         Term 3, five months away.
 *   All three upcoming                  — the session has been rolled over but not started. The
 *                                         school is about to begin Term 1; the `order`-only
 *                                         fallback answered Term 3.
 *
 * The second is live today: the 2026/2027 session opened with Term 1 starting 2026-09-05, so every
 * term is `upcoming` and the bulk-run screen pre-fills the term that starts in April 2027. A bulk
 * run taken on the pre-filled default would bill every enrolled student against a term seven
 * months away.
 *
 * ORDER IS DELIBERATELY NOT ID HERE. Every fixture below inserts its terms in a SCRAMBLED order —
 * `order` 2, then 3, then 1 — so `terms.id` ascending and `terms.order` ascending disagree. A
 * resolver that reached for "the first row" or "the newest row" instead of ordering by `order`
 * passes on a tidy fixture and reds on this one.
 *
 * AND EVERY FIXTURE CARRIES A DECOY IN A NON-CURRENT SESSION, in the state the arm is looking for.
 * "The last completed term in this school" and "the first upcoming term in this school" are both
 * wrong answers that a session-blind resolver would give, so each arm is only satisfiable by going
 * through `academic_sessions.is_current` first.
 *
 * THE TWO-ACTIVE-TERMS ARM BUILDS ITS FIXTURE BY HAND rather than through ct_terms(), because the
 * scramble that file-wide helper applies is the wrong one for it. That arm needs `terms.id`
 * ascending and `terms.order` ascending to disagree in ONE SPECIFIC DIRECTION: the LOWER-ordered
 * active term must be inserted FIRST. MySQL will often return rows in primary-key order for an
 * unordered `first()`, so a fixture that seeds the higher-ordered term first would be answered
 * correctly BY ACCIDENT and would pass with and without the `orderByDesc` under test — a
 * non-discriminating arm that reads as a guard. Seeding low-then-high makes the unordered query
 * naturally return the wrong row, so the assertion has something to fail on. Bite-proved by
 * removing the `orderByDesc('order')` from step 2 and watching this arm, and only this arm, red.
 */

use App\Models\AcademicSession;
use App\Models\School;
use App\Models\Term;
use App\Support\ActiveSchool;
use App\Support\CurrentTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A school with a current session and a non-current one, and nothing in either yet.
 *
 * @return array{school: School, current: AcademicSession, past: AcademicSession}
 */
function ct_world(): array
{
    $school = School::create([
        'name' => 'CT School '.Str::random(6),
        'slug' => (string) Str::uuid(),
    ]);

    return [
        'school' => $school,
        'current' => ct_session($school, true),
        'past' => ct_session($school, false),
    ];
}

function ct_session(School $school, bool $isCurrent): AcademicSession
{
    return AcademicSession::create([
        'school_id' => $school->id,
        'name' => 'CT '.Str::random(6),
        'slug' => 'ct-sess-'.Str::random(8),
        'is_current' => $isCurrent,
    ]);
}

/** One term, at an explicit `order` and an explicit status. */
function ct_term(AcademicSession $session, int $order, string $status): Term
{
    return Term::create([
        'academic_session_id' => $session->id,
        'school_id' => $session->school_id,
        'name' => "Term {$order}",
        'slug' => 'ct-term-'.Str::random(8),
        'order' => $order,
        // Dates are real but carry NO signal the resolver is allowed to read: `order` and `status`
        // are the whole input. An implementation that reached for start_date would still pass here,
        // which is fine — it is `order` and `status` that this file pins.
        'start_date' => now()->addMonths($order * 3),
        'end_date' => now()->addMonths($order * 3 + 2),
        'status' => $status,
    ]);
}

/**
 * Plant the three terms of a session in SCRAMBLED insertion order, keyed by `order`.
 *
 * @param  array<int, string>  $statuses  order => status
 * @return array<int, Term>
 */
function ct_terms(AcademicSession $session, array $statuses): array
{
    $terms = [];

    foreach ([2, 3, 1] as $order) {
        $terms[$order] = ct_term($session, $order, $statuses[$order]);
    }

    return $terms;
}

/** Resolve inside the school's own context, which is how every real caller reaches this. */
function ct_resolve(School $school): ?Term
{
    return ActiveSchool::runFor($school->id, fn () => CurrentTerm::forSchool($school->id));
}

it('returns the ACTIVE term, whatever state the others are in', function () {
    $w = ct_world();

    // Term 2 is the active one, with a completed term BELOW it and an upcoming term ABOVE it — so
    // neither "last completed" nor "first upcoming" nor "last by order" can produce the right
    // answer by accident.
    $terms = ct_terms($w['current'], [1 => 'completed', 2 => 'active', 3 => 'upcoming']);

    // Decoy: an active term in the school's OTHER, non-current session.
    $decoy = ct_term($w['past'], 1, 'active');

    $resolved = ct_resolve($w['school']);

    expect($resolved?->id)->toBe($terms[2]->id,
        'An active term exists in the current session and the resolver did not return it.');
    expect($resolved?->id)->not->toBe($decoy->id);
});

it('falls back to the LAST COMPLETED term mid-session, not the last term by order', function () {
    $w = ct_world();

    // The live mid-session shape: Term 1 finished, the school is in the holiday after it, Terms 2
    // and 3 not yet reached. The term the school most recently finished is Term 1.
    $terms = ct_terms($w['current'], [1 => 'completed', 2 => 'upcoming', 3 => 'upcoming']);

    $decoy = ct_term($w['past'], 3, 'completed');

    $resolved = ct_resolve($w['school']);

    expect($resolved?->id)->toBe($terms[1]->id,
        'The resolver returned a term the school has NOT reached. With Term 1 completed and Terms '
        .'2 and 3 upcoming, the term the school most recently finished is Term 1; answering Term 3 '
        .'pre-fills the bulk-run screen with a term five months away.');
    expect($resolved?->id)->not->toBe($terms[3]->id);
    expect($resolved?->id)->not->toBe($decoy->id);
});

it('returns the LAST completed term by order when the whole session is finished', function () {
    $w = ct_world();

    // End of session, before the rollover: all three finished. The most recently finished is the
    // highest `order`, so here the corrected fallback and the old one agree — which is the point.
    // Nothing about the fix may change this case.
    $terms = ct_terms($w['current'], [1 => 'completed', 2 => 'completed', 3 => 'completed']);

    $resolved = ct_resolve($w['school']);

    expect($resolved?->id)->toBe($terms[3]->id,
        'With every term completed the school most recently finished the highest-ordered one.');
});

it('returns the FIRST term by order when the session has been rolled over but not started', function () {
    $w = ct_world();

    // The live 2026/2027 shape: nothing active, nothing completed, so the school has not started
    // this session. The term it is about to begin is Term 1.
    $terms = ct_terms($w['current'], [1 => 'upcoming', 2 => 'upcoming', 3 => 'upcoming']);

    $decoy = ct_term($w['past'], 1, 'upcoming');

    $resolved = ct_resolve($w['school']);

    expect($resolved?->id)->toBe($terms[1]->id,
        'Every term is upcoming, so the school has not started this session and the term it is '
        .'about to begin is Term 1. Returning the last term by `order` here is the defect that '
        .'would have billed the 2026/2027 session against a term starting in April 2027.');
    expect($resolved?->id)->not->toBe($terms[3]->id);
    expect($resolved?->id)->not->toBe($decoy->id);
});

it('returns null for a current session with NO terms — a school that has not been set up', function () {
    $w = ct_world();

    // Terms exist in the school, just not in the CURRENT session. So null here is a statement
    // about the session, not about an empty table.
    ct_terms($w['past'], [1 => 'completed', 2 => 'completed', 3 => 'active']);

    expect(ct_resolve($w['school']))->toBeNull(
        'The current session has no terms; the resolver reached outside it.');
});

it('returns null for a school with no current session at all', function () {
    $school = School::create([
        'name' => 'CT School '.Str::random(6),
        'slug' => (string) Str::uuid(),
    ]);

    // A session with terms in every state — but `is_current` is false, so there is no current term.
    $only = ct_session($school, false);
    ct_terms($only, [1 => 'completed', 2 => 'active', 3 => 'upcoming']);

    expect(ct_resolve($school))->toBeNull(
        'No session is current, so no term is; the resolver answered from a non-current session.');
});

it('resolves identically from a School already in hand', function () {
    // Both entry points, one resolution — forSchoolModel() exists only to skip a re-find, and a fix
    // applied to one and not the other is a drift this file refuses.
    $w = ct_world();
    $terms = ct_terms($w['current'], [1 => 'completed', 2 => 'upcoming', 3 => 'upcoming']);

    $viaModel = ActiveSchool::runFor(
        $w['school']->id,
        fn () => CurrentTerm::forSchoolModel($w['school']->fresh()),
    );

    expect($viaModel?->id)->toBe($terms[1]->id);
    expect($viaModel?->id)->toBe(ct_resolve($w['school'])?->id);
});

it('returns null for a school id that does not exist', function () {
    expect(CurrentTerm::forSchool(PHP_INT_MAX))->toBeNull();
});

it('returns the HIGHEST-ORDERED active term when a session somehow has two', function () {
    // NOT A CORRECTNESS ARM. Two active terms in one session is a state that should not exist and
    // nothing prevents it (docs/handoff/tickets/two-active-terms-in-one-session-has-no-constraint.md).
    // What is pinned here is that the resolver is DETERMINISTIC in that state rather than returning
    // whichever row MySQL felt like, and which of the two it settles on.
    $w = ct_world();

    // INSERTION ORDER IS THE WHOLE FIXTURE. The LOWER-ordered term is created first, so `terms.id`
    // ascending and `terms.order` ascending point at different rows. An unordered `first()` takes
    // the low-ordered row — the wrong answer — instead of arriving at the right one by primary-key
    // accident. Reverse these two statements and the arm passes without the fix.
    $lowerOrderedFirst = ct_term($w['current'], 1, 'active');
    $higherOrderedSecond = ct_term($w['current'], 2, 'active');

    // A third term, not active, above both — so "last by order" is also not the right answer here.
    $upcoming = ct_term($w['current'], 3, 'upcoming');

    // Decoy: an active term in the school's OTHER session, at the highest `order` of all, so a
    // session-blind resolver that DOES order by `order` descending answers this one.
    $decoy = ct_term($w['past'], 3, 'active');

    $resolved = ct_resolve($w['school']);

    expect($resolved?->id)->toBe($higherOrderedSecond->id,
        'Two terms in the current session are active. Somebody activated the NEXT term without '
        .'completing the current one, so the later term by `order` is the intended one — and, '
        .'whatever the right answer is, the resolver must not leave it to MySQL to pick a row.');
    expect($resolved?->id)->not->toBe($lowerOrderedFirst->id);
    expect($resolved?->id)->not->toBe($upcoming->id);
    expect($resolved?->id)->not->toBe($decoy->id);
});
