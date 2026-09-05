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
use App\Finance\Services\ActorName;
use App\Models\Curriculum;
use App\Models\Role;
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

// THE MEMO IS PROCESS-LIFETIME, SO THE SUITE RESETS IT — and that is a property something
// now asserts rather than an accident. `ActorName::$memo` is keyed "<schoolId>:<userId>" and
// nothing cleared it between files. It was safe only because no test in this repository uses
// `DatabaseMigrations` (measured: zero occurrences under tests/, against 264 files using
// RefreshDatabase) and MySQL does not roll back AUTO_INCREMENT, so ids never recycle within a
// run. Add one re-migrating file and ids restart at 1 while the memo still holds the previous
// file's `1:1` — a name resolved for a different person, surfacing as a flake.
//
// This also gives `flushMemo()` its first caller, which is what its stated model
// `SchoolFinanceSettings::flushPrefixMemo()` has had all along, in that file's own
// `beforeEach`.
beforeEach(function () {
    (new RbacSeeder)->run();
    ActorName::flushMemo();
});

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

// ═══ THE RETURN ENDPOINT ══════════════════════════════════════════════════════

/** POST the return route for one invoice. */
function ire_return($test, School $school, User $as, string $uuid, array $body)
{
    return $test->actingAs($as)->withSession(['school_id' => $school->id])
        ->postJson('/api/internal-audit/invoices/'.$uuid.'/return', $body);
}

/**
 * A seat holding `finance.invoice.approve` and NOT `finance.invoice.reject`.
 *
 * AN AD-HOC ROLE, AND THE DEVIATION FROM THIS FILE'S HEADER IS THE POINT. Every other seat here is
 * a seeded one, because a hand-picked ability list proves only that the code reads `can()`. NO
 * SEEDED SEAT CAN SERVE THIS ARM: `internal_auditor` is the only role holding either ability and it
 * holds BOTH, by design — `RbacSeeder` grants approve and reject together and withholds both from
 * `admin` and `accounts_officer` on the maker-checker argument.
 *
 * So the seat that would distinguish the route's own gate from its group's gate does not exist in
 * the grants map, and constructing one is the only way to ask the question. That is a statement
 * about the map being correct, not about this fixture being loose.
 */
function ire_approve_only_seat(School $school): User
{
    $previous = getPermissionsTeamId();
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'ia_approve_only', 'guard_name' => 'web'])
        ->givePermissionTo('finance.invoice.approve');
    setPermissionsTeamId($previous);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, 'ia_approve_only');
    $user->flushSchoolAccessCache();

    return $user;
}

// ── (k) THE HAPPY PATH ────────────────────────────────────────────────────────

it('k — internal_auditor returns a bill: 200, three columns set, reviewed_at still NULL', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    $invoice = ire_invoice($school);

    $body = ire_return($this, $school, $auditor, $invoice->uuid, ['reason' => 'Tuition rate is stale'])
        ->assertOk()->json();

    expect($body['uuid'])->toBe($invoice->uuid)
        ->and($body['return_reason'])->toBe('Tuition rate is stale')
        ->and((int) $body['returned_by_user_id'])->toBe((int) $auditor->getKey())
        ->and($body['returned_at'])->not->toBeNull();

    // Read from the database, not the response: an echoed payload the write never persisted would
    // satisfy the expectations above.
    $row = DB::table('finance_invoices')->where('id', $invoice->id)->first();
    expect($row->returned_at)->not->toBeNull()
        ->and((int) $row->returned_by_user_id)->toBe((int) $auditor->getKey())
        ->and($row->return_reason)->toBe('Tuition rate is stale')
        // THE SECOND AXIS IS UNTOUCHED. A returned bill stays unreleased, so it stays invisible to
        // the payer — the safe direction and the point of the design.
        ->and($row->reviewed_at)->toBeNull();
});

// ── (l) THE GATE ARM — THIS COMMIT'S POINT ───────────────────────────────────

it('l — a seat with approve but NOT reject is refused the return route and allowed the feed', function () {
    $school = School::factory()->create();
    $seat = ire_approve_only_seat($school);
    $invoice = ire_invoice($school);

    // The discriminating precondition, read inside the school's team context because spatie scopes
    // grants per team and `can()` outside one answers about the wrong school.
    [$approve, $reject] = ActiveSchool::runFor($school->id, fn () => [
        $seat->can('finance.invoice.approve'),
        $seat->can('finance.invoice.reject'),
    ]);
    expect($approve)->toBeTrue()->and($reject)->toBeFalse();

    // BOTH HALVES, OR THE ARM CANNOT TELL WHICH GATE ANSWERED. 403 alone would also be produced by
    // the enclosing group refusing this seat outright; the 200 on pending proves the group let them
    // through and the route's OWN middleware is what refused.
    ire_return($this, $school, $seat, $invoice->uuid, ['reason' => 'wrong fee line'])->assertForbidden();
    ire_get($this, $school, $seat)->assertOk();

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();
});

// ── (m) THE OUTER GATE STILL ANSWERS FIRST FOR A SEAT HOLDING NEITHER ────────

it('m — accounts_officer, holding neither checker ability, is refused by the GROUP gate', function () {
    $school = School::factory()->create();
    $officer = ire_seat($school, 'accounts_officer');
    $invoice = ire_invoice($school);

    // The MAKER seat: holds thirteen finance abilities including finance.invoice.generate, so a
    // refusal here can only be the checker gate and not "the user holds nothing".
    [$generate, $approve] = ActiveSchool::runFor($school->id, fn () => [
        $officer->can('finance.invoice.generate'),
        $officer->can('finance.invoice.approve'),
    ]);
    expect($generate)->toBeTrue()->and($approve)->toBeFalse();

    // Refused on BOTH, which is what shows the two gates are LAYERED rather than swapped: arm (l)
    // passes the group and fails the route, this one fails the group and so never reaches it.
    ire_return($this, $school, $officer, $invoice->uuid, ['reason' => 'wrong fee line'])->assertForbidden();
    ire_get($this, $school, $officer)->assertForbidden();
});

// ── (n) THE REQUEST LAYER — a field error, not the action's sentence ─────────

it('n — a missing reason is refused by the REQUEST, with a field error on reason', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    $invoice = ire_invoice($school);

    $response = ire_return($this, $school, $auditor, $invoice->uuid, [])->assertStatus(422);

    // A FIELD error, which is what a form can highlight — not ReturnInvoice's sentence. The two
    // layers refuse differently on purpose and this arm pins which one answered.
    $response->assertJsonValidationErrors('reason');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();
});

it('o — a 256-character reason is refused by the REQUEST max, not by the action', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    $invoice = ire_invoice($school);

    // A LITERAL 256, never derived from REASON_MAX: a payload built as `REASON_MAX + 1` submits
    // "cap + 1" whatever the cap is and is structurally incapable of noticing the cap loosening.
    $response = ire_return($this, $school, $auditor, $invoice->uuid, ['reason' => str_repeat('x', 256)])
        ->assertStatus(422);

    $response->assertJsonValidationErrors('reason');

    // AND IT IS NOT THE ACTION'S SENTENCE. Both layers refuse a 256, and they say different things;
    // without this the arm could not tell which one did, and the request's `max:` could be deleted
    // with nothing going red.
    expect($response->json('message'))->not->toContain('Shorten it rather than');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();
});

it('p — a whitespace-only reason is refused, and this arm records WHICH layer did it', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    $invoice = ire_invoice($school);

    // MEASURED, NOT ASSUMED. `bootstrap/app.php` does not DECLARE TrimStrings; it arrives from the
    // framework's default global stack together with ConvertEmptyStringsToNull, so three spaces are
    // trimmed to '' and converted to null before the FormRequest sees them, and `required` refuses
    // with a field error. A framework default is a premise until something posts one — this is the
    // post. If the default stack ever changes, this arm reds and ReturnInvoice's own trim-and-refuse
    // stops being a backstop and becomes the only guard.
    $response = ire_return($this, $school, $auditor, $invoice->uuid, ['reason' => '   '])->assertStatus(422);

    $response->assertJsonValidationErrors('reason');
    expect($response->json('message'))->not->toContain('the reason cannot be empty');

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->returned_at)->toBeNull();
});

// ── (q, r) THE ACTION'S SENTENCES REACH THE CLIENT VERBATIM ─────────────────

it('q — an already-returned bill is refused with the action\'s sentence, naming the first returner', function () {
    $school = School::factory()->create();
    $first = ire_seat($school, 'internal_auditor');
    $second = ire_seat($school, 'internal_auditor');
    $invoice = ire_invoice($school);

    ire_return($this, $school, $first, $invoice->uuid, ['reason' => 'first reason'])->assertOk();

    $message = ire_return($this, $school, $second, $invoice->uuid, ['reason' => 'second reason'])
        ->assertStatus(422)->json('message');

    // THE NAME CROSSES THE WIRE, AND THE ID DOES NOT. This endpoint is the surface the ticket is
    // about — the sentence is relayed verbatim into a 422 body an auditor reads on screen.
    expect($message)->toContain('was already returned to Finance on ')
        ->and($message)->toContain(' by '.$first->full_name.'. It is awaiting correction.')
        ->and($message)->not->toContain('user#')
        ->and($message)->not->toContain($invoice->uuid);

    expect(DB::table('finance_invoices')->where('id', $invoice->id)->first()->return_reason)->toBe('first reason');
});

it('r — a released bill is refused with the void-and-credit-note remedy', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    $reviewer = ire_seat($school, 'internal_auditor');
    $invoice = ire_invoice($school);

    ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($invoice, $reviewer));

    $message = ire_return($this, $school, $auditor, $invoice->uuid, ['reason' => 'wrong fee line'])
        ->assertStatus(422)->json('message');

    // THE REMEDY IS PART OF THE ASSERTION: an auditor told "no" with no route forward will find one
    // that is not audited.
    expect($message)->toContain('already released to its payer by '.$reviewer->full_name)
        ->and($message)->toContain('void it and issue a credit note instead')
        ->and($message)->not->toContain('user#')
        ->and($message)->not->toContain($invoice->uuid);
});

// ── (q2) THE BATCH RESOLVES EACH DISTINCT NAME ONCE, NOT ONCE PER REFUSED ITEM ──────────────────

it('q2 — a batch where every item refuses resolves the reviewer and the prefix once each', function () {
    $school = School::factory()->create();
    $reviewer = ire_seat($school, 'internal_auditor');
    $second = ire_seat($school, 'internal_auditor');

    // SIX, NOT TWO. `approve()` catches BusinessRuleException PER ITEM inside a loop, so a naive
    // resolver issues one user read and one settings read for every refused item. Two items cannot
    // tell a constant apart from a linear cost; six can, and the assertion is a LITERAL rather than
    // a formula in $count — an expectation derived from the batch size would restate whatever the
    // implementation does.
    $invoices = collect(range(1, 6))->map(fn () => ire_invoice($school));

    foreach ($invoices as $invoice) {
        ActiveSchool::runFor($school->id, fn () => app(ApproveInvoice::class)->handle($invoice, $reviewer));
    }

    $users = 0;
    $settings = 0;

    DB::listen(function ($query) use (&$users, &$settings) {
        if (str_contains($query->sql, '`users`')) {
            $users++;
        }

        if (str_contains($query->sql, 'finance_school_settings')) {
            $settings++;
        }
    });

    $response = ire_post($this, $school, $second, $invoices->pluck('uuid')->all());

    // Every item refused, and with the sentence this commit is about — so the reads counted below
    // are the ones the refusal path made, not an empty measurement.
    $response->assertStatus(207)
        ->assertJsonPath('approved', 0)
        ->assertJsonPath('refused', 6);

    expect($response->json('results.0.message'))
        ->toContain('was already released by '.$reviewer->full_name)
        ->and($response->json('results.5.message'))
        ->toContain('was already released by '.$reviewer->full_name);

    // MEASURED, NOT ASSUMED. `ActorName` memoises on "<schoolId>:<userId>" and
    // SchoolFinanceSettings::invoiceNumberPrefixFor() memoises on school_id, so six refusals naming
    // one reviewer cost one read of each. The `users` figure counts EVERY users read in the request
    // — the authenticated actor's included — which is why the ceiling is 2 rather than 1.
    expect($users)->toBeLessThanOrEqual(2)
        ->and($settings)->toBe(1);
});

// ── (s) ISOLATION — UNKNOWN, NOT FORBIDDEN ───────────────────────────────────

it('s — another school\'s invoice is UNKNOWN, not forbidden', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');

    $other = School::factory()->create();
    $foreign = ire_invoice($other);

    $response = ire_return($this, $school, $auditor, $foreign->uuid, ['reason' => 'wrong fee line'])
        ->assertNotFound();

    // The house convention, and the reason the controller resolves manually inside the tenant
    // rather than by route model binding: a 403 would confirm the row exists somewhere.
    expect($response->json('message'))->toBe('No such invoice in this School.');

    expect(DB::table('finance_invoices')->where('id', $foreign->id)->first()->returned_at)->toBeNull();
});

// ── (t) THE INTEGRATION ARM — the two halves of the slice meeting ────────────

it('t — a return moves the bill between counts and leaves unreleased_total UNCHANGED', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    ire_invoice($school);
    ire_invoice($school);
    $target = ire_invoice($school);

    $before = ire_get($this, $school, $auditor)->assertOk()->json();
    expect(array_column($before['data'], 'uuid'))->toContain($target->uuid)
        ->and($before['counts'])->toBe([
            'awaiting_review' => 3, 'returned_to_finance' => 0, 'unreleased_total' => 3,
        ]);

    ire_return($this, $school, $auditor, $target->uuid, ['reason' => 'Tuition rate is stale'])->assertOk();

    $after = ire_get($this, $school, $auditor)->assertOk()->json();

    // The row leaves the page...
    expect(array_column($after['data'], 'uuid'))->not->toContain($target->uuid);

    // ...and the counts move as one whole object, asserted together rather than field by field: a
    // per-field assertion passes a change that silently added a fourth key or dropped one.
    //
    // THE CLAUSE THAT MATTERS IS `unreleased_total` STAYING 3. It is the entire argument for that
    // field existing: the omission detector must NOT narrow when a bill is returned, or returning
    // bills would quietly shrink the number that exists to reveal bills nobody has dealt with.
    expect($after['counts'])->toBe([
        'awaiting_review' => 2, 'returned_to_finance' => 1, 'unreleased_total' => 3,
    ]);
});

// ── (u) THE TRAIL IS WRITTEN ON THE HTTP PATH ───────────────────────────────

it('u — the HTTP return writes finance.invoice.returned carrying the reason', function () {
    $school = School::factory()->create();
    $auditor = ire_seat($school, 'internal_auditor');
    $invoice = ire_invoice($school);

    ire_return($this, $school, $auditor, $invoice->uuid, ['reason' => 'Tuition rate is stale'])->assertOk();

    $row = DB::table('activity_log')
        ->where('log_name', 'finance')->where('event', 'invoice.returned')
        ->orderByDesc('id')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->causer_id)->toBe((int) $auditor->getKey());

    $properties = json_decode($row->properties, true);

    // The reason is the payload of the act — a row saying a bill was returned without saying what
    // was wrong with it records the event and loses the event.
    expect($properties['return_reason'])->toBe('Tuition rate is stale')
        ->and($properties['invoice_uuid'])->toBe($invoice->uuid);
});
