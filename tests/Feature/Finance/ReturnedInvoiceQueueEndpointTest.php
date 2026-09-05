<?php

/*
 * FINANCE'S RETURNED-BILLS QUEUE — GET /api/v1/finance/invoices/returned.
 *
 * SEEDED SEATS ONLY, and the refused seats are chosen so each one refuses for a DIFFERENT reason.
 * `accounts_officer` really holds `finance.invoice.generate`; `internal_auditor` really holds
 * `finance.invoice.approve` and `finance.invoice.reject` and no `finance.access` at all;
 * `executive_director` really holds `finance.access` and five checker pairs and NOT
 * `finance.invoice.generate`. That last seat is the one that makes the gate arm bite — see arm d.
 */

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\ApproveInvoice;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\ReturnInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceStatus;
use App\Finance\Models\Invoice;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

function rbq_seat(School $school, string $role): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $role);
    $user->flushSchoolAccessCache();

    return $user;
}

/** One issued, unreleased invoice for a fresh student of $school. */
function rbq_invoice(School $school): Invoice
{
    return ActiveSchool::runFor($school->id, function () use ($school) {
        $student = Student::factory()->create(['school_id' => $school->id]);
        $enrolment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        return app(GenerateInvoice::class)->handle(
            $enrolment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo(150000), bankAccountId: testBankAccountId($school->id))],
            InvoiceKind::Scheduled,
        );
    });
}

/**
 * Return a bill THROUGH THE REAL ACTION, never by writing the three columns.
 *
 * The pairing trigger from `2026_09_04_100000` refuses a `returned_at` without a
 * `returned_by_user_id` and a `return_reason`, so a row-write fixture would either be refused or
 * would have to reproduce the action's own invariant — and a fixture that reproduces the rule under
 * test cannot fail on it.
 */
function rbq_return(School $school, Invoice $invoice, User $auditor, string $reason = 'The development levy is billed twice on this one.'): Invoice
{
    return ActiveSchool::runFor($school->id, fn () => app(ReturnInvoice::class)->handle($invoice, $auditor, $reason));
}

function rbq_get($test, School $school, User $as, string $query = '')
{
    return $test->actingAs($as)->withSession(['school_id' => $school->id])
        ->getJson('/api/v1/finance/invoices/returned'.$query);
}

// ── (a) THE FEED IS THE RETURNED SET, AND ONLY IT ─────────────────────────────

it('a — a returned bill appears; a pending, a released and a void one do not', function () {
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');
    $bursar = rbq_seat($school, 'accounts_officer');

    $returned = rbq_return($school, rbq_invoice($school), $auditor);
    $pending = rbq_invoice($school);
    $released = rbq_invoice($school);
    $voided = rbq_invoice($school);

    ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($released, $auditor));

    // A RETURNED BILL THAT WAS THEN VOIDED. Returned first so the row really carries `returned_at`
    // — a void bill that was never returned would be excluded by the return filter alone and could
    // not tell us whether `excludingVoid()` is doing anything.
    $returnedThenVoided = rbq_return($school, $voided, $auditor);
    DB::table('finance_invoices')->where('id', $returnedThenVoided->id)
        ->update(['status' => InvoiceStatus::Void->value]);

    $numbers = collect(rbq_get($this, $school, $bursar)->assertOk()->json('data'))->pluck('number');

    // BOTH DIRECTIONS. Asserting only the exclusions would pass on a feed that returns nothing at
    // all, which is the failure this endpoint would produce most easily.
    expect($numbers)->toContain($returned->number)
        ->and($numbers)->not->toContain($pending->number)
        ->and($numbers)->not->toContain($released->number)
        ->and($numbers)->not->toContain($returnedThenVoided->number)
        ->and($numbers)->toHaveCount(1);
});

it('a1 — released-AND-returned is unreachable in BOTH directions, so the release filter is a belt', function () {
    /*
     * MEASURED, AND IT CHANGES WHAT THE ENDPOINT'S `whereNull(RELEASE_STAMP_COLUMN)` CLAIMS.
     *
     * The first draft of arm (a) tried to build a bill that was returned and THEN released, to show
     * the filter excluding it. `ApproveInvoice` refuses that — "was returned to Finance by user#N …
     * it cannot be released until Finance resubmits it" — which is Phase A commit 3's
     * approve-over-a-return ruling doing exactly its job. And `ReturnInvoice` refuses the other
     * order just as flatly.
     *
     * SO THE COMBINATION `reviewed_at IS NOT NULL AND returned_at IS NOT NULL` CANNOT BE REACHED
     * THROUGH THE REAL ACTIONS AT ALL, and the endpoint's release filter is therefore a BELT rather
     * than a working exclusion today. That is written down here rather than implied by a fixture,
     * because a filter whose justification is "it excludes X" is wrong when nothing can be X — and
     * the honest justification is that it costs nothing and survives a future writer that does not
     * carry these two guards.
     *
     * This arm is what keeps that statement true: if either guard is ever relaxed, the state becomes
     * reachable and this arm reds, at which point the filter stops being a belt and somebody should
     * know.
     */
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');

    $returnedFirst = rbq_return($school, rbq_invoice($school), $auditor);
    expect(fn () => ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($returnedFirst, $auditor)))
        ->toThrow(BusinessRuleException::class);

    $releasedFirst = rbq_invoice($school);
    ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($releasedFirst, $auditor));
    expect(fn () => rbq_return($school, $releasedFirst, $auditor))
        ->toThrow(BusinessRuleException::class);

    // And neither ended up in the queue.
    $bursar = rbq_seat($school, 'accounts_officer');
    $numbers = collect(rbq_get($this, $school, $bursar)->assertOk()->json('data'))->pluck('number');
    expect($numbers)->toContain($returnedFirst->number)
        ->and($numbers)->not->toContain($releasedFirst->number);
});

it('a2 — the payload carries NO uuid and no user# anywhere in it', function () {
    // THE DISPLAY RULING, ASSERTED ON THE WIRE RATHER THAN ON THE SCREEN. A page cannot render an
    // internal identifier it was never sent, so this is the cheapest place to hold that line — and
    // it holds it against a future field added to the payload without thinking, not only against
    // today's.
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');
    $bursar = rbq_seat($school, 'accounts_officer');

    $bill = rbq_return($school, rbq_invoice($school), $auditor);

    $body = rbq_get($this, $school, $bursar)->assertOk()->getContent();

    expect($body)->not->toContain($bill->uuid)
        ->and($body)->not->toContain('uuid')
        ->and($body)->not->toContain('user#')
        ->and($body)->not->toContain('"student_id"');

    // And the things that ARE there: the number, and the returner's NAME.
    expect($body)->toContain('"number":'.$bill->number)
        ->and($body)->toContain(trim($auditor->first_name.' '.$auditor->last_name));
});

// ── (b) ISOLATION — ANOTHER SCHOOL'S RETURNED BILL IS ABSENT, NOT FORBIDDEN ───

it('b — another school\'s returned bill is absent from the feed, and the request still succeeds', function () {
    $mine = School::factory()->create();
    $theirs = School::factory()->create();

    $myAuditor = rbq_seat($mine, 'internal_auditor');
    $theirAuditor = rbq_seat($theirs, 'internal_auditor');
    $bursar = rbq_seat($mine, 'accounts_officer');

    rbq_return($mine, rbq_invoice($mine), $myAuditor, 'MINE — correct the levy');
    rbq_return($theirs, rbq_invoice($theirs), $theirAuditor, 'THEIRS — correct the levy');

    $response = rbq_get($this, $mine, $bursar)->assertOk();

    /*
     * ASSERTED ON THE REASON, NOT ON THE NUMBER, AND THAT IS A FINDING RATHER THAN A STYLE CHOICE.
     *
     * `finance_invoices.number` is a PER-SCHOOL SEQUENCE. The first bill of school A and the first
     * bill of school B are both `1`, so the obvious `expect($numbers)->not->toContain($theirs->number)`
     * FAILS on a perfectly isolated feed — it did, on the first run of this file — and would just as
     * happily PASS on a leaking one whenever the two sequences happened to differ. It is an
     * assertion that cannot distinguish the states it is named for.
     *
     * ABSENT, NOT A 403 — the SchoolContext boundary, not an authorization one. Naming which
     * negative this is: a 403 would mean the seat may not read the queue, which is a different
     * (and false) statement about a bursar looking at their own school's queue.
     */
    $reasons = collect($response->json('data'))->pluck('return_reason');

    expect($reasons)->toContain('MINE — correct the levy')
        ->and($reasons)->not->toContain('THEIRS — correct the levy')
        ->and($response->json('counts.returned_total'))->toBe(1);
});

// ── (c) THE SEATS THAT HOLD THE MAKER ABILITY ────────────────────────────────

it('c — both seats holding finance.invoice.generate can open it', function (string $role) {
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');
    $seat = rbq_seat($school, $role);

    rbq_return($school, rbq_invoice($school), $auditor);

    rbq_get($this, $school, $seat)->assertOk()->assertJsonCount(1, 'data');
})->with(['accounts_officer', 'admin']);
// A DATASET, NOT A LOOP. Each seat gets its own case, its own name and its own failure — the house
// rule this repository records against `foreach` over fixtures.

// ── (d) THE GATE ARM ─────────────────────────────────────────────────────────

it('d — internal_auditor is REFUSED: this is the maker\'s screen, not the checker\'s', function () {
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');

    rbq_return($school, rbq_invoice($school), $auditor);

    rbq_get($this, $school, $auditor)->assertForbidden();
});

it('d2 — and it is refused by the ROUTE\'S OWN GATE, which arm d cannot show', function () {
    /*
     * WHICH NEGATIVE, NAMED. Arm d is TRUE and it does not prove what it looks like it proves.
     *
     * `internal_auditor` holds NO `finance.access` at all (measured: RbacSeeder gives it
     * `finance.invoice.approve` and `finance.invoice.reject` and nothing else in finance), and this
     * route lives inside the group gated on `finance.access`. So the auditor is refused by the
     * GROUP, one layer before this route's own middleware is ever consulted — and mutating
     * `permission:finance.invoice.generate` to anything at all leaves arm d green.
     *
     * `executive_director` is the seat that makes the gate bite: it holds `finance.access`, so it
     * clears the group, and it does NOT hold `finance.invoice.generate`, so only this route's own
     * middleware can refuse it. It is also a CHECKER seat — five finance checker pairs — which is
     * exactly the mistake the ruling forbids: gating Finance's correction desk on the auditor's
     * ability would admit checkers and lock the bursar out.
     *
     * Widening this route's gate to the group's own `finance.access` reds THIS arm and no other.
     */
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');
    $checker = rbq_seat($school, 'executive_director');

    rbq_return($school, rbq_invoice($school), $auditor);

    /*
     * THE SEAT REALLY DOES CLEAR THE GROUP'S GATE — otherwise this arm would refuse for arm d's
     * reason and be no better than it.
     *
     * INSIDE THE TEAM. Spatie's permissions are team-scoped on `school_id`, so a bare `can()` with
     * no team set answers about the WRONG school — it returns false for everything and this
     * pre-check would "pass" by asserting nothing, leaving the arm resting on an unverified claim.
     */
    setPermissionsTeamId($school->id);
    $fresh = $checker->fresh();
    expect($fresh->can('finance.access'))->toBeTrue()
        ->and($fresh->can('finance.invoice.generate'))->toBeFalse();
    setPermissionsTeamId(null);

    rbq_get($this, $school, $checker)->assertForbidden();
});

// ── (e) OLDEST RETURNED FIRST ────────────────────────────────────────────────

it('e — the queue is ordered oldest-returned-first', function () {
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');
    $bursar = rbq_seat($school, 'accounts_officer');

    // THREE bills at three DISTINCT times, returned OUT OF ORDER so insertion order, id order and
    // returned_at order all disagree. Two would leave `id` and `returned_at` able to agree by
    // accident on a 50/50 draw; returning the middle one last makes every other ordering wrong.
    $a = rbq_invoice($school);
    $b = rbq_invoice($school);
    $c = rbq_invoice($school);

    $this->travelTo(now()->subDays(9));
    rbq_return($school, $a, $auditor, 'nine days ago');
    $this->travelTo(now()->addDays(6));  // three days ago
    rbq_return($school, $c, $auditor, 'three days ago');
    $this->travelTo(now()->addDays(2));  // one day ago
    rbq_return($school, $b, $auditor, 'one day ago');
    $this->travelBack();

    $reasons = collect(rbq_get($this, $school, $bursar)->assertOk()->json('data'))
        ->pluck('return_reason')->all();

    expect($reasons)->toBe(['nine days ago', 'three days ago', 'one day ago']);
});

// ── (f) THE AGE — THE INSTRUMENT ─────────────────────────────────────────────

it('f — the reported age is the OLDEST bill\'s, in whole days, on the server clock', function () {
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');
    $bursar = rbq_seat($school, 'accounts_officer');

    $old = rbq_invoice($school);
    $recent = rbq_invoice($school);

    $this->travelTo(now()->subDays(23));
    rbq_return($school, $old, $auditor);
    $this->travelTo(now()->addDays(21));  // two days ago
    rbq_return($school, $recent, $auditor);
    $this->travelBack();

    $counts = rbq_get($this, $school, $bursar)->assertOk()->json('counts');

    // 23, NOT 2. The number describes the OLDEST bill in the queue, which is the whole reason it is
    // reported: a queue whose newest arrival is recent tells you nothing about whether it is worked.
    expect($counts['oldest_waiting_days'])->toBe(23)
        ->and($counts['returned_total'])->toBe(2);
});

it('f2 — the age is NULL on an empty queue, never 0', function () {
    // An empty queue has no oldest bill. 0 would claim there is one that arrived today, and a
    // screen reading it would render "today" under a heading that should read "—".
    $school = School::factory()->create();
    $bursar = rbq_seat($school, 'accounts_officer');

    rbq_invoice($school); // one pending bill, so the table is not empty for the wrong reason

    $counts = rbq_get($this, $school, $bursar)->assertOk()->json('counts');

    expect($counts['oldest_waiting_days'])->toBeNull()
        ->and($counts['returned_total'])->toBe(0);
});

it('f3 — the age is derived from the WHOLE queue, not from the page', function () {
    /*
     * The failure this arm exists for is silent and reassuring: reading the age off `data[0]` is
     * correct on page 1 and wrong on every other page. Asked for page 2, the endpoint must still
     * report the age of the oldest bill in the school.
     */
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');
    $bursar = rbq_seat($school, 'accounts_officer');

    $oldest = rbq_invoice($school);
    $this->travelTo(now()->subDays(40));
    rbq_return($school, $oldest, $auditor);
    $this->travelBack();

    foreach (range(1, 3) as $i) {
        rbq_return($school, rbq_invoice($school), $auditor, 'recent '.$i);
    }

    $page2 = rbq_get($this, $school, $bursar, '?per_page=1&page=2')->assertOk();

    expect($page2->json('counts.oldest_waiting_days'))->toBe(40)
        ->and($page2->json('counts.returned_total'))->toBe(4)
        ->and($page2->json('pagination.total'))->toBe(4)
        ->and($page2->json('data'))->toHaveCount(1);
});

// ── (g) THE REASON, IN FULL ──────────────────────────────────────────────────

it('g — the reason is returned in full, including one at the maximum length', function () {
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');
    $bursar = rbq_seat($school, 'accounts_officer');

    // AT the cap, measured against the action's own constant rather than a literal 255 — the two
    // cannot drift, and if the column is ever widened this arm follows it.
    $atMax = str_repeat('a', ReturnInvoice::REASON_MAX);
    $short = 'Wrong term.';

    $long = rbq_invoice($school);
    $this->travelTo(now()->subDay());
    rbq_return($school, $long, $auditor, $atMax);
    $this->travelBack();
    rbq_return($school, rbq_invoice($school), $auditor, $short);

    $rows = rbq_get($this, $school, $bursar)->assertOk()->json('data');

    expect($rows[0]['return_reason'])->toBe($atMax)
        ->and(mb_strlen($rows[0]['return_reason']))->toBe(ReturnInvoice::REASON_MAX)
        ->and($rows[1]['return_reason'])->toBe($short);
});

it('g2 — the returner is a NAME, and the returned_at stamp is present', function () {
    $school = School::factory()->create();
    $auditor = rbq_seat($school, 'internal_auditor');
    $bursar = rbq_seat($school, 'accounts_officer');

    $bill = rbq_return($school, rbq_invoice($school), $auditor);

    $row = rbq_get($this, $school, $bursar)->assertOk()->json('data.0');

    expect($row['returned_by'])->toBe(trim($auditor->first_name.' '.$auditor->last_name))
        ->and($row['returned_at'])->not->toBeNull()
        ->and($row['billed_to'])->toBe($bill->billed_to_name)
        ->and($row)->not->toHaveKey('uuid');
});
