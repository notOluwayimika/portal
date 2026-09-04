<?php

/*
 * INTERNAL AUDIT'S TWO ENDPOINTS — the pending feed and the batch release.
 *
 * SEEDED SEATS ONLY. `internal_auditor` really holds `finance.invoice.approve` and nothing else in
 * finance; `accounts_officer` really holds thirteen finance abilities and not this one, and is the
 * MAKER the pair exists to keep out. `admin` is used nowhere as a refused seat — it holds
 * effectively everything, so a negative arm built on it cannot fail on its own axis.
 */

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

function ire_seat(School $school, string $role): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $role);
    $user->flushSchoolAccessCache();

    return $user;
}

/** One issued, unreleased invoice for a fresh student of $school. */
function ire_invoice(School $school): Invoice
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

function ire_get($test, School $school, User $as, string $query = '')
{
    return $test->actingAs($as)->withSession(['school_id' => $school->id])
        ->getJson('/api/internal-audit/invoices/pending'.$query);
}

function ire_post($test, School $school, User $as, array $uuids)
{
    return $test->actingAs($as)->withSession(['school_id' => $school->id])
        ->postJson('/api/internal-audit/invoices/approve', ['uuids' => $uuids]);
}

// ── (a) THE FEED IS THE PENDING SET ───────────────────────────────────────────

it('a — the feed includes a pending invoice and excludes a released one', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');

    $pending = ire_invoice($school);
    $released = ire_invoice($school);

    ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($released, $auditor));

    $uuids = collect(ire_get($this, $school, $auditor)->assertOk()->json('data'))->pluck('uuid');

    // BOTH directions. Asserting only the exclusion would pass on a feed that returns nothing at
    // all, which is the failure this endpoint would produce most easily.
    expect($uuids)->toContain($pending->uuid)
        ->and($uuids)->not->toContain($released->uuid);
});

// ── (b) THE MAKER IS REFUSED BY BOTH ──────────────────────────────────────────

it('b — accounts_officer, the MAKER, is refused by both endpoints with a message', function () {
    $school = School::factory()->create();
    $officer = ire_seat($school, 'accounts_officer');
    $invoice = ire_invoice($school);

    // The discriminating precondition, read in the school's team context: this seat holds the
    // maker ability, so a 403 cannot be "the user holds nothing".
    [$generate, $approve] = ActiveSchool::runFor($school->id, fn () => [
        $officer->can('finance.invoice.generate'),
        $officer->can('finance.invoice.approve'),
    ]);
    expect($generate)->toBeTrue()->and($approve)->toBeFalse();

    $feed = ire_get($this, $school, $officer)->assertForbidden();
    $batch = ire_post($this, $school, $officer, [$invoice->uuid])->assertForbidden();

    // Non-empty on both. A bare abort reaches the client as {"message": ""} and the panels read it
    // with `??`, which does not substitute for an empty string.
    expect($feed->json('message'))->not->toBeNull()->not->toBe('')
        ->and($batch->json('message'))->not->toBeNull()->not->toBe('');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->reviewed_at)->toBeNull();
});

// ── (c) ANOTHER SCHOOL'S BILL IS ABSENT, AND THE SCOPE IS WHY ────────────────

it('c — a bill in another school is absent from the feed, and the scope is what excludes it', function () {
    $mine = School::factory()->create();
    $theirs = School::factory()->create();

    $auditor = ire_seat($mine, 'internal_auditor');
    $mineInvoice = ire_invoice($mine);
    $theirsInvoice = ire_invoice($theirs);

    $rows = ire_get($this, $mine, $auditor)->assertOk()->json();
    $uuids = collect($rows['data'])->pluck('uuid');

    // NOT AN EMPTY FIXTURE — proven twice. My own bill is present, so the feed is working; and the
    // foreign bill really exists and is really pending, read WITHOUT the scope, so its absence is
    // the scope refusing rather than nothing having been created.
    expect($uuids)->toContain($mineInvoice->uuid)
        ->and($uuids)->not->toContain($theirsInvoice->uuid)
        ->and($rows['pagination']['total'])->toBe(1);

    $foreign = Invoice::withoutGlobalScopes()->whereKey($theirsInvoice->id)->first();
    expect($foreign)->not->toBeNull()
        ->and($foreign->{Invoice::RELEASE_STAMP_COLUMN})->toBeNull()
        ->and((int) $foreign->school_id)->toBe($theirs->id);
});

// ── (d) THE COUNT IS THE TRUE TOTAL, NOT THE PAGE LENGTH ─────────────────────

it('d — the pending total exceeds the returned rows when there is more than one page', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');

    foreach (range(1, 4) as $ignored) {
        ire_invoice($school);
    }

    $rows = ire_get($this, $school, $auditor, '?per_page=2')->assertOk()->json();

    // The whole point of the endpoint reporting a total at all: a page of 2 must still say 4.
    // Asserting `total > count(data)` rather than `total === 4` alone would pass on a page size
    // that silently equalled the total, so both are asserted.
    expect($rows['data'])->toHaveCount(2)
        ->and($rows['pagination']['total'])->toBe(4)
        ->and($rows['pagination']['total'])->toBeGreaterThan(count($rows['data']));
});

// ── (e) A PARTIAL BATCH IS NOT A SUCCESS ─────────────────────────────────────

it('e — a batch with one void bill approves the other two and reports the refusal', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');

    $first = ire_invoice($school);
    $void = ire_invoice($school);
    $second = ire_invoice($school);

    DB::table('finance_invoices')->where('id', $void->id)
        ->update(['status' => InvoiceStatus::Void->value]);

    $response = ire_post($this, $school, $auditor, [$first->uuid, $void->uuid, $second->uuid]);

    // 207, NOT 200. The status line is the first thing a client branches on, and a partial batch
    // answering 200 is what leaves an unreviewed bill looking reviewed.
    $response->assertStatus(207);

    $body = $response->json();
    expect($body['approved'])->toBe(2)->and($body['refused'])->toBe(1);

    $byUuid = collect($body['results'])->keyBy('uuid');
    expect($byUuid[$first->uuid]['outcome'])->toBe('approved')
        ->and($byUuid[$second->uuid]['outcome'])->toBe('approved')
        ->and($byUuid[$void->uuid]['outcome'])->toBe('refused')
        // Named, not merely flagged: the reason must say WHY, or the operator cannot act on it.
        ->and($byUuid[$void->uuid]['message'])->toContain('void');

    // THE VALID ATTESTATIONS SURVIVED THE REFUSAL — the reason each invoice is its own
    // transaction. A batch wrapped in one would roll these back.
    expect(DB::table('finance_invoices')->where('id', $first->id)->first()->reviewed_at)->not->toBeNull()
        ->and(DB::table('finance_invoices')->where('id', $second->id)->first()->reviewed_at)->not->toBeNull()
        ->and(DB::table('finance_invoices')->where('id', $void->id)->first()->reviewed_at)->toBeNull();
});

// ── (f) per_page IS CLAMPED, NOT HONOURED ────────────────────────────────────

it('f — per_page above the ceiling is clamped', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    ire_invoice($school);

    $rows = ire_get($this, $school, $auditor, '?per_page=5000')->assertOk()->json();

    // CLAMPED, not refused — the roster's shape: a client asking for more gets the most it may
    // have. Asserting the echoed per_page is what distinguishes a clamp from a coincidence, since
    // one invoice would fit any page size.
    expect($rows['pagination']['per_page'])->toBe(100);
});

// ── (g) THE BATCH CAP IS REFUSED, NAMING ITSELF ──────────────────────────────

it('g — a batch above the cap is refused before anything is released', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    $invoice = ire_invoice($school);

    // A LITERAL payload, never derived from the constant: a cap test written as
    // `while (count($uuids) <= MAX_BATCH)` submits "cap + 1" whatever the cap is, so it proves a
    // limit exists and is structurally incapable of noticing that limit loosening.
    $uuids = array_map(fn (int $n) => 'not-a-real-uuid-'.$n, range(1, 101));
    $uuids[0] = $invoice->uuid;

    $response = ire_post($this, $school, $auditor, $uuids)->assertStatus(422);

    expect($response->json('message'))->toContain('100');

    // Refused BEFORE anything was released — the real invoice in the payload is untouched.
    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->reviewed_at)->toBeNull();
});

// ── (h) THE RETURN AXIS LEAVES THE QUEUE ──────────────────────────────────────

it('h — a returned bill is absent from the pending rows', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    $pending = ire_invoice($school);
    $returned = ire_invoice($school);

    // THROUGH THE ACTION, NEVER A RAW UPDATE. The pairing trigger requires all three return columns
    // in one statement, so a piecemeal fixture write is refused as 1644 and the arm would fail for
    // a reason that has nothing to do with the filter under test.
    ActiveSchool::runFor($school->id, fn () => app(ReturnInvoice::class)->handle($returned, $auditor, 'Tuition rate is stale'));

    // The precondition: returning leaves the release axis untouched, so what removes the bill from
    // the feed below can only be the new filter and not the release one.
    expect(DB::table('finance_invoices')->where('id', $returned->id)->first()->reviewed_at)->toBeNull();

    $rows = ire_get($this, $school, $auditor)->assertOk()->json();

    expect(array_column($rows['data'], 'uuid'))->toBe([$pending->uuid]);
});

// ── (i) pagination.total FOLLOWS THE ROWS ────────────────────────────────────

it('i — pagination.total counts only the awaiting-review set, not every unreleased bill', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    ire_invoice($school);
    ire_invoice($school);
    $returned = ire_invoice($school);

    ActiveSchool::runFor($school->id, fn () => app(ReturnInvoice::class)->handle($returned, $auditor, 'wrong fee line'));

    $rows = ire_get($this, $school, $auditor)->assertOk()->json();

    // `paginate()` derives last_page from this number, so a total describing a different set than
    // the rows would make the PAGER lie. It is the filtered subset by necessity, and the
    // unfiltered number moved to counts.unreleased_total — asserted here TOGETHER, because
    // asserting only the total would pass a change that quietly dropped the count that replaced it.
    expect($rows['pagination']['total'])->toBe(2)
        ->and($rows['counts']['unreleased_total'])->toBe(3);
});

// ── (j) THE INVARIANT, OVER ALL FOUR STATES PLUS A VOID ──────────────────────

it('j — unreleased_total equals awaiting_review plus returned_to_finance, and a void bill is in none', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    $reviewer = ire_seat($school, 'internal_auditor');

    // ALL FOUR STATES FROM 2026_09_04_100000'S DOCBLOCK, so the invariant is evaluated over the
    // whole space rather than over the two arms that happen to be easy to build. A fixture holding
    // only pending and returned rows would satisfy the arithmetic without ever exercising the
    // predicates that exclude the other two.
    $pendingOne = ire_invoice($school);
    $pendingTwo = ire_invoice($school);

    // 1. released BY A NAMED PERSON.
    $released = ire_invoice($school);
    ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($released, $reviewer));

    // 2. GRANDFATHERED — a release stamp with a NULL actor, which the 31 August backfill created
    //    and which no action can produce. Written raw ON PURPOSE: there is no writer for it, and
    //    it is a legitimate state the counts must exclude for the same reason as the one above.
    $grandfathered = ire_invoice($school);
    DB::table('finance_invoices')->where('id', $grandfathered->id)
        ->update(['reviewed_at' => now(), 'reviewed_by_user_id' => null]);

    // 3. RETURNED — through the action, per arm (h).
    $returned = ire_invoice($school);
    ActiveSchool::runFor($school->id, fn () => app(ReturnInvoice::class)->handle($returned, $auditor, 'wrong fee line'));

    // 4. VOID and unreleased — must be counted by NONE of the three. This is the arm that fails if
    //    an excludingVoid() is dropped anywhere.
    $void = ire_invoice($school);
    DB::table('finance_invoices')->where('id', $void->id)->update(['status' => InvoiceStatus::Void->value]);

    $counts = ire_get($this, $school, $auditor)->assertOk()->json('counts');

    // The exact numbers first, so the invariant cannot be satisfied by three wrong values.
    expect($counts['awaiting_review'])->toBe(2)
        ->and($counts['returned_to_finance'])->toBe(1)
        ->and($counts['unreleased_total'])->toBe(3);

    // THE INVARIANT. A break means a FOURTH unreleased state has appeared and nobody updated these
    // counts — not that the arithmetic is wrong.
    expect($counts['unreleased_total'])->toBe($counts['awaiting_review'] + $counts['returned_to_finance']);

    // And the void bill is in none of them: 2 + 1 = 3 only holds because it was excluded from all
    // three. Named explicitly so the reason this fixture carries a void row survives.
    expect(DB::table('finance_invoices')->where('id', $void->id)->first()->status)
        ->toBe(InvoiceStatus::Void->value);
    expect([$pendingOne->uuid, $pendingTwo->uuid])->not->toContain($void->uuid);
});
