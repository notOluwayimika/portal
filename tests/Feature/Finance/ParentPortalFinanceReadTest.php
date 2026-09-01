<?php

/*
 * THE PARENT PORTAL'S ONE FINANCE READ — GET /api/parent/finance/wards.
 *
 * The guardian-facing payment portal is being built against the shape this endpoint returns, so the
 * shape freezes when this lands. Four properties are load-bearing and each is planted-and-broken
 * below rather than merely asserted:
 *
 *   0. INTERNAL AUDIT HAS RELEASED IT. Since 31 August 2026 every bill is reviewed by an Internal
 *      Auditor before it reaches a parent (docs/handoff/brookstone-answers-31-august.md §6), and
 *      the bill COUNTS AGAINST THE BALANCE the whole time it is waiting. The gate therefore had to
 *      land on BOTH keys of this response or the screen would print a positive balance above the
 *      words "Nothing outstanding" — the compliance gate manufacturing a falsehood of its own. §2b
 *      below holds both halves, and the staff surface's indifference to all of it.
 *
 *   1. OWN WARDS ONLY, derived server-side. There is no identifier on the request, so there is no
 *      uuid to tamper with — the test that matters is that a second guardian's child in the same
 *      School never appears.
 *   2. THE FIGURE IS THE REMAINING AMOUNT. InvoiceSettlement reads the settlement aggregates off
 *      the model and treats an ABSENT one as zero, so any invoice that does not come through
 *      InvoiceReadModel serialises `outstanding` equal to its FULL TOTAL, silently. On a payer
 *      surface that is a parent asked twice for the same money, with no error anywhere. The
 *      part-paid case below asserts a KNOWN figure for exactly that reason.
 *   3. NO STAFF FIELDS. can_record_payment / can_submit_credit_note / can_request_void /
 *      void_blocked_reason / lines are the bursar's answers and a payer must never receive them.
 *      Asserted by ABSENCE, explicitly, because a field nobody asserts about is a field that
 *      reappears the first time someone reuses InvoiceResource here.
 *
 * Fixture helpers are `ppf_`-prefixed: Pest hoists test-file functions into one global namespace, so
 * an unprefixed `world()` or `invoice()` collides with another file's identically-named helper.
 */

use App\Finance\Actions\ApproveCreditNote;
use App\Finance\Actions\ApproveVoidRequest;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\Actions\RecordPayment;
use App\Finance\Actions\SubmitCreditNote;
use App\Finance\Actions\SubmitVoidRequest;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Models\Invoice;
use App\Finance\Services\GuardianPaymentAuthorisation;
use App\Models\Curriculum;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use App\Support\SchoolDay;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/*
|--------------------------------------------------------------------------
| Fixture
|--------------------------------------------------------------------------
*/

/** A student in $school. */
function ppf_student(School $school, string $first): Student
{
    return Student::create([
        'school_id' => $school->id,
        'first_name' => $first,
        'last_name' => 'Child',
        'gender' => 'female',
        'admission_number' => 'ADM-'.Str::random(8),
    ]);
}

/**
 * A user holding the `guardian` role and a Guardian ROW in $school, with $students attached as
 * wards. Both halves are required: the role carries `parent_portal.access` (RbacSeeder grantsMap),
 * the row is what forUserInActiveSchool() resolves.
 *
 * @param  array<int, Student>  $students
 * @return array{0: User, 1: Guardian}
 */
function ppf_guardian(School $school, array $students): array
{
    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('guardian');
    setPermissionsTeamId(null);
    $user->schools()->syncWithoutDetaching([$school->id]);

    $guardian = al_makeGuardian($school->id, $user->id);
    foreach ($students as $student) {
        $guardian->students()->attach($student->id, [
            'relationship' => 'mother', 'is_primary' => true, 'can_login' => true,
        ]);
    }

    return [$user, $guardian];
}

/**
 * An issued invoice for a FRESH enrollment episode of $student, for $kobo, **NOT yet reviewed by
 * Internal Audit** — which since 31 August 2026 is what `GenerateInvoice` actually produces.
 *
 * Every arm about the parent's LIST or the parent's BALANCE must state which side of the review gate
 * its fixture sits on, so this is deliberately the raw production state and `ppf_invoice()` below is
 * the one that releases. Naming the unreleased case after the Action is what keeps a future arm from
 * acquiring visibility it never asked for.
 */
function ppf_unreviewed(School $school, Student $student, int $kobo): Invoice
{
    return ActiveSchool::runFor($school->id, function () use ($school, $student, $kobo) {
        $enrollment = StudentCurriculum::create([
            'student_id' => $student->id,
            'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
            'status' => 'active',
        ]);

        return app(GenerateInvoice::class)->handle(
            $enrollment->uuid,
            [new InvoiceLineSpec('Tuition', Money::fromKobo($kobo), bankAccountId: testBankAccountId())],
            InvoiceKind::Scheduled,
        );
    });
}

/**
 * Release a bill to parents — what an Internal Auditor's review will stamp when that action is
 * built. Written as a direct column write ON PURPOSE: there is no review Action in the tree yet
 * (this commit is the gate, not the feature), and inventing a helper that pretends otherwise would
 * be a fixture asserting a capability the application does not have.
 */
function ppf_release(Invoice $invoice): Invoice
{
    Invoice::withoutGlobalScopes()->whereKey($invoice->getKey())->update([
        'reviewed_at' => now(),
        'reviewed_by_user_id' => null,
    ]);

    return $invoice->refresh();
}

/**
 * An issued invoice that Internal Audit HAS released — a bill the parent may see.
 *
 * THIS IS WHAT EVERY ARM WRITTEN BEFORE 31 AUGUST MEANT BY "an invoice", which is why the name kept
 * the plain spelling: those arms are about settlement, isolation, wire shape and gating, and each
 * one needs a bill that reaches the payer for its assertion to be about anything. Making the review
 * gate their precondition rather than re-pointing them at the unreleased state keeps each arm
 * testing the axis it was written for.
 */
function ppf_invoice(School $school, Student $student, int $kobo): Invoice
{
    return ppf_release(ppf_unreviewed($school, $student, $kobo));
}

function ppf_pay(School $school, Invoice $invoice, int $kobo): void
{
    ActiveSchool::runFor($school->id, fn () => app(RecordPayment::class)->handle(
        $invoice,
        Money::fromKobo($kobo),
        'Payer',
        al_makeUser($school->id),
        SchoolDay::today(),
        testBankAccountId($school->id),
    ));
}

function ppf_void(School $school, Invoice $invoice): void
{
    ActiveSchool::runFor($school->id, function () use ($school, $invoice) {
        $request = app(SubmitVoidRequest::class)->handle($invoice, 'entered in error', al_makeUser($school->id));
        app(ApproveVoidRequest::class)->handle($request, al_makeUser($school->id));
    });
}

/** Settle an invoice with an APPROVED credit note — settled without a payment. */
function ppf_creditOff(School $school, Invoice $invoice, int $kobo): void
{
    ActiveSchool::runFor($school->id, function () use ($school, $invoice, $kobo) {
        $note = app(SubmitCreditNote::class)->handle(
            $invoice, Money::fromKobo($kobo), CreditNoteKind::CreditNote, null,
            al_makeUser($school->id), testBankAccountId($school->id),
        );
        app(ApproveCreditNote::class)->handle($note, al_makeUser($school->id));
    });
}

/** Drive the endpoint as $user in $school. */
function ppf_hit(School $school, User $user)
{
    return test()->actingAs($user)
        ->withSession(['school_id' => $school->id])
        ->getJson('/api/parent/finance/wards');
}

/*
|--------------------------------------------------------------------------
| 1 · Own wards, and only own wards
|--------------------------------------------------------------------------
*/

it('returns the guardian OWN wards and no other parent child in the same school', function () {
    $school = al_makeSchool();
    $mine = ppf_student($school, 'Ada');
    $theirs = ppf_student($school, 'Zoe');

    // Both children are billed identically, so the only difference between them is the pivot row.
    ppf_invoice($school, $mine, 300000);
    ppf_invoice($school, $theirs, 300000);

    [$me] = ppf_guardian($school, [$mine]);
    ppf_guardian($school, [$theirs]);

    $response = ppf_hit($school, $me)->assertOk();

    $ids = collect($response->json('data'))->pluck('student.id')->all();
    expect($ids)->toBe([$mine->uuid])
        ->and($ids)->not->toContain($theirs->uuid);

    // …and the other parent gets the mirror image. Without this control the assertion above could
    // pass on an endpoint that returns the first student in the school.
    [$them] = ppf_guardian($school, [$theirs]);
    expect(collect(ppf_hit($school, $them)->assertOk()->json('data'))->pluck('student.id')->all())
        ->toBe([$theirs->uuid]);
});

it('returns BOTH wards for a guardian with two', function () {
    $school = al_makeSchool();
    $one = ppf_student($school, 'Ada');
    $two = ppf_student($school, 'Bim');
    ppf_invoice($school, $one, 100000);
    ppf_invoice($school, $two, 250000);

    [$me] = ppf_guardian($school, [$one, $two]);

    $data = collect(ppf_hit($school, $me)->assertOk()->json('data'));

    expect($data)->toHaveCount(2)
        ->and($data->pluck('student.id')->sort()->values()->all())
        ->toBe(collect([$one->uuid, $two->uuid])->sort()->values()->all());
});

it('returns an EMPTY collection, not an error, for a guardian with no wards', function () {
    $school = al_makeSchool();
    ppf_student($school, 'Ada');            // a child exists in the school; it is not theirs
    [$me] = ppf_guardian($school, []);      // guardian ROW, no pivot rows

    ppf_hit($school, $me)->assertOk()->assertExactJson(['data' => []]);
});

it('returns an EMPTY collection for a guardian-role user with no guardian row in this school', function () {
    $school = al_makeSchool();
    ppf_student($school, 'Ada');

    $user = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $user->assignRole('guardian');
    setPermissionsTeamId(null);
    $user->schools()->syncWithoutDetaching([$school->id]);

    ppf_hit($school, $user)->assertOk()->assertExactJson(['data' => []]);
});

/*
|--------------------------------------------------------------------------
| 2 · Which invoices appear
|--------------------------------------------------------------------------
*/

it('keeps a ward with NO outstanding invoices, carrying an empty invoice list', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $data = ppf_hit($school, $me)->assertOk()->json('data');

    // Absence of debt is information — "paid up" must not read as "not your child".
    expect($data)->toHaveCount(1)
        ->and($data[0]['student']['id'])->toBe($ward->uuid)
        ->and($data[0]['invoices'])->toBe([]);
});

it('EXCLUDES a fully settled invoice — by payment, and by approved credit note', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $paidOff = ppf_invoice($school, $ward, 300000);
    ppf_pay($school, $paidOff, 300000);

    $creditedOff = ppf_invoice($school, $ward, 150000);
    ppf_creditOff($school, $creditedOff, 150000);

    $open = ppf_invoice($school, $ward, 80000);

    $invoices = ppf_hit($school, $me)->assertOk()->json('data.0.invoices');

    // The open invoice is the control: without it an empty list would also pass a broken read.
    expect(collect($invoices)->pluck('id')->all())->toBe([$open->uuid]);
});

it('EXCLUDES a void invoice', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $voided = ppf_invoice($school, $ward, 300000);
    ppf_void($school, $voided);
    $open = ppf_invoice($school, $ward, 80000);

    $invoices = ppf_hit($school, $me)->assertOk()->json('data.0.invoices');

    expect(collect($invoices)->pluck('id')->all())->toBe([$open->uuid]);
});

/*
|--------------------------------------------------------------------------
| 2b · The Internal Audit review gate (Brookstone, 31 August 2026 — §2, §6)
|--------------------------------------------------------------------------
|
| Every bill is reviewed by an Internal Auditor before it is released to parents. The bill is
| created and COUNTS AGAINST THE BALANCE immediately; only its visibility to the payer is gated.
|
| So there are two claims here and they are not the same claim:
|
|   1. an unreleased bill does not reach the parent — the list AND the total;
|   2. the staff side is untouched by any of it.
|
| The second is what the whole one-predicate design rests on, and it is the one a reader would
| otherwise have to take on trust from a docblock.
*/

it('WITHHOLDS a bill Internal Audit has not reviewed, and shows one it has', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $reviewed = ppf_invoice($school, $ward, 300000);
    $pending = ppf_unreviewed($school, $ward, 80000);

    $invoices = ppf_hit($school, $me)->assertOk()->json('data.0.invoices');

    // THE REVIEWED INVOICE IS THE CONTROL, and it is doing real work: an empty list would also pass
    // an endpoint that had simply broken, and a list of both would pass one where the predicate was
    // never applied. Only this exact pair distinguishes "withheld" from either.
    expect(collect($invoices)->pluck('id')->all())->toBe([$reviewed->uuid])
        ->and(collect($invoices)->pluck('id')->all())->not->toContain($pending->uuid);
});

it('SHOWS the same bill once it is released — the gate opens, it does not merely close', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $invoice = ppf_unreviewed($school, $ward, 300000);

    // Withheld first, so the transition is asserted rather than the endpoint's two endpoints. A
    // predicate that hides EVERYTHING passes the arm above; nothing but this catches it.
    expect(ppf_hit($school, $me)->assertOk()->json('data.0.invoices'))->toBe([]);

    ppf_release($invoice);

    $invoices = ppf_hit($school, $me)->assertOk()->json('data.0.invoices');

    expect(collect($invoices)->pluck('id')->all())->toBe([$invoice->uuid])
        ->and($invoices[0]['outstanding'])->toBe(['amount_minor' => 300000, 'currency' => 'NGN']);
});

it('EXCLUDES a withheld bill from the balance too, so "nothing outstanding" is TRUE', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    ppf_unreviewed($school, $ward, 850000);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0');

    // THE FALSEHOOD THIS ARM EXISTS TO FORBID. The `WardCard` component in
    // resources/js/pages/parent/finance.tsx renders a green tick and
    // "Nothing outstanding for Ada right now" whenever `invoices` is empty, beside `account.balance`
    // in the header. Withhold the bill from the list alone and this screen reads:
    //
    //     Ada Child                        Account balance
    //                                            ₦850,000
    //     ✅ Nothing outstanding for Ada right now.
    //
    // Both keys are gated together, so the pair is asserted together.
    expect($row['invoices'])->toBe([])
        ->and($row['account']['balance'])->toBe(['amount_minor' => 0, 'currency' => 'NGN'])
        ->and($row['account']['available_credit'])->toBe(['amount_minor' => 0, 'currency' => 'NGN']);
});

/**
 * THE INVARIANT, DERIVED FROM THE RESPONSE AND NOT FROM THE RULE.
 *
 * The expectation here is summed out of the `invoices` array the endpoint actually returned — an
 * INDEPENDENT path. It never spells `balance − Σ(withheld charges)`, which is the implementation and
 * would only assert that the code equals itself. What it says is the property a payer screen must
 * have: **the total you are shown equals the bills you are shown.**
 *
 * The fixture is deliberately not degenerate. Two released bills rather than one (so a balance that
 * tracked only the newest, or only the first, is visible), one part-paid (so the sum is over
 * OUTSTANDING and not over totals — an implementation reading `total` passes with one unpaid
 * invoice and fails here), and two withheld at different amounts (so an adjustment that subtracted
 * a single invoice, or a fixed one, cannot survive).
 */
it('the balance a parent is shown EQUALS the sum of the invoices a parent is shown', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $partPaid = ppf_invoice($school, $ward, 300000);
    ppf_pay($school, $partPaid, 120000);   // 180000 remains
    ppf_invoice($school, $ward, 90000);    // 90000 remains

    ppf_unreviewed($school, $ward, 850000);
    ppf_unreviewed($school, $ward, 45000);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0');

    $visible = collect($row['invoices'])->sum(fn (array $invoice) => $invoice['outstanding']['amount_minor']);

    expect($row['invoices'])->toHaveCount(2)
        ->and($row['account']['balance']['amount_minor'])->toBe($visible)
        // Named too, so a reader can see the arm is not vacuously comparing two zeros.
        ->and($visible)->toBe(270000);
});

/**
 * THE CLAIM THE WHOLE DESIGN RESTS ON. `outstandingForStudent()` has exactly one caller and it is
 * the parent controller; the staff statement reads `forStudent()` and `accountPositionForStudent()`,
 * neither of which may acquire the predicate. Per Brookstone the bill is real and the balance keeps
 * counting it — a staff surface that stopped showing an unreviewed bill would hide it from the
 * bursar and from the Auditor who is supposed to review it.
 *
 * Driven over HTTP as a bursar rather than against the read model, because the claim is about the
 * SURFACE. A read-model arm would stay green if someone moved the filter into the controller.
 */
it('leaves the STAFF statement showing the withheld bill, and counting it', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $reviewed = ppf_invoice($school, $ward, 300000);
    $pending = ppf_unreviewed($school, $ward, 850000);

    // The parent sees one bill and ₦300,000 …
    $parent = ppf_hit($school, $me)->assertOk()->json('data.0');
    expect(collect($parent['invoices'])->pluck('id')->all())->toBe([$reviewed->uuid])
        ->and($parent['account']['balance'])->toBe(['amount_minor' => 300000, 'currency' => 'NGN']);

    $bursar = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $bursar->assignRole('accounts_officer');
    setPermissionsTeamId(null);
    $bursar->schools()->syncWithoutDetaching([$school->id]);

    $statement = test()->actingAs($bursar)
        ->withSession(['school_id' => $school->id])
        ->getJson("/api/v1/finance/students/{$ward->uuid}/invoices")
        ->assertOk()
        ->json();

    // … while the bursar sees BOTH, and the full ₦1,150,000. Same student, same instant.
    expect(collect($statement['invoices'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$reviewed->uuid, $pending->uuid])->sort()->values()->all())
        ->and($statement['account']['balance'])->toBe(['amount_minor' => 1150000, 'currency' => 'NGN'])
        ->and($statement['billed_total'])->toBe(['amount_minor' => 1150000, 'currency' => 'NGN']);
});

/**
 * THE DOCUMENTED RESIDUAL, PINNED SO IT CANNOT DRIFT SILENTLY.
 *
 * A payment is NOT subtracted with the bill it was taken against, and that is a decision rather than
 * an oversight: `RecordPayment` posts one ledger row per PAYMENT carrying the full amount received,
 * while its allocations may span several invoices, so there is no per-invoice row to remove — and
 * the money genuinely arrived. Under "as if the bill did not exist" it becomes unapplied credit,
 * which is what a parent who handed over money and can see no bill is in fact owed.
 *
 * Reachable today: a bursar may record a payment against any issued invoice with something
 * outstanding, and an unreviewed invoice is issued. It is asserted here so that whoever changes the
 * derivation has to decide about this case deliberately rather than discover it in production.
 */
it('treats money taken against a withheld bill as CREDIT, not as a hidden reduction', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $pending = ppf_unreviewed($school, $ward, 300000);
    ppf_pay($school, $pending, 120000);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0');

    // Balance = 300000 − 120000 = 180000; the withheld charge of 300000 comes out; −120000 remains.
    expect($row['invoices'])->toBe([])
        ->and($row['account']['balance'])->toBe(['amount_minor' => -120000, 'currency' => 'NGN'])
        ->and($row['account']['available_credit'])->toBe(['amount_minor' => 120000, 'currency' => 'NGN']);
});

/**
 * A CREDIT NOTE AGAINST A WITHHELD BILL IS WITHHELD WITH IT, and this is the arm that separates the
 * two halves of the adjustment. A credit note is not money that moved — it is the school forgiving
 * part of one named invoice. Leave its ledger row in and the parent is told the school owes them
 * money it does not, which is the same falsehood as the balance arm above pointing the other way.
 */
it('withholds a credit note written against a withheld bill', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $pending = ppf_unreviewed($school, $ward, 300000);
    ppf_creditOff($school, $pending, 50000);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0');

    // Balance = 300000 − 50000 = 250000. Subtracting the charge alone leaves −50000 and the parent
    // is shown ₦50,000 of credit that does not exist; subtracting both leaves zero.
    expect($row['invoices'])->toBe([])
        ->and($row['account']['balance'])->toBe(['amount_minor' => 0, 'currency' => 'NGN'])
        ->and($row['account']['available_credit'])->toBe(['amount_minor' => 0, 'currency' => 'NGN']);
});

/**
 * THE BACKFILL — the half that decides whether 6 September works.
 *
 * SEEDED IN THE PRE-MIGRATION SHAPE AND THEN MIGRATED, which is the only way to say anything about
 * the rows that already exist in production. An arm starting from post-migration rows would be
 * describing rows the migration created, not rows it had to survive.
 *
 * THE PRE-MIGRATION SHAPE IS RECONSTRUCTED BY DROPPING THE COLUMNS, not by `migrate:rollback
 * --step=N`: `--step` counts from the branch's latest migrations, so a sibling landing on top would
 * be rolled back instead and this would pass having tested nothing — the failure this repository has
 * already been bitten by once (docs/testing.md). The precedent is
 * ScholarshipKindAndRunExclusionTest's own migration arm.
 *
 * DDL COMMITS IMPLICITLY, so RefreshDatabase's transaction will not undo the drop. The `finally`
 * re-runs `up()`, which is idempotent by construction, so a failed assertion cannot leave the schema
 * broken for the rest of the run.
 */
it('keeps a PRE-EXISTING invoice visible to its parent after the migration runs', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $invoice = ppf_unreviewed($school, $ward, 300000);

    $migration = require database_path('migrations/2026_08_31_100000_finance_invoices_internal_audit_review.php');

    try {
        // ── Back to the pre-migration shape: an invoice with no review concept at all ──────────
        Schema::table('finance_invoices', function ($table) {
            $table->dropIndex('finance_invoices_school_student_reviewed_index');
            $table->dropColumn(['reviewed_at', 'reviewed_by_user_id']);
        });

        expect(Schema::hasColumn('finance_invoices', 'reviewed_at'))->toBeFalse();

        $migration->up();

        // ── The row is stamped from its OWN created_at, never from the migration's clock ───────
        $row = DB::table('finance_invoices')->where('id', $invoice->id)
            ->first(['reviewed_at', 'reviewed_by_user_id', 'created_at']);

        expect($row->reviewed_at)->not->toBeNull()
            ->and($row->reviewed_at)->toBe($row->created_at)
            // Nobody reviewed it. A fabricated reviewer is the one thing an audit column must never
            // carry, so the grandfathered state is "released, by no one".
            ->and($row->reviewed_by_user_id)->toBeNull();

        // ── And the parent can still see it, which is the whole point of the backfill ──────────
        $parent = ppf_hit($school, $me)->assertOk()->json('data.0');

        expect(collect($parent['invoices'])->pluck('id')->all())->toBe([$invoice->uuid])
            ->and($parent['account']['balance'])->toBe(['amount_minor' => 300000, 'currency' => 'NGN']);
    } finally {
        $migration->up();
    }
});

/*
|--------------------------------------------------------------------------
| 3 · The figure — the forStudent() trap
|--------------------------------------------------------------------------
*/

it('reports the REMAINING amount on a part-paid invoice, not the full total', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $invoice = ppf_invoice($school, $ward, 300000);
    ppf_pay($school, $invoice, 120000);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0.invoices.0');

    // 300000 − 120000 = 180000. THE known figure: an invoice that skips the read model's settlement
    // aggregates reports 300000 here, 200 OK, and nothing else in the response changes.
    expect($row['id'])->toBe($invoice->uuid)
        ->and($row['total'])->toBe(['amount_minor' => 300000, 'currency' => 'NGN'])
        ->and($row['outstanding'])->toBe(['amount_minor' => 180000, 'currency' => 'NGN']);
});

it('reports the remaining amount when BOTH a payment and an approved credit note have landed', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $invoice = ppf_invoice($school, $ward, 300000);
    ppf_pay($school, $invoice, 120000);
    ppf_creditOff($school, $invoice, 30000);

    // 300000 − 120000 − 30000 = 150000.
    expect(ppf_hit($school, $me)->assertOk()->json('data.0.invoices.0.outstanding'))
        ->toBe(['amount_minor' => 150000, 'currency' => 'NGN']);
});

/*
|--------------------------------------------------------------------------
| 4 · The shape, and what it must never carry
|--------------------------------------------------------------------------
*/

it('carries NONE of the staff eligibility flags or internal state', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);
    ppf_invoice($school, $ward, 300000);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0.invoices.0');

    foreach (['can_record_payment', 'can_submit_credit_note', 'can_request_void', 'void_blocked_reason', 'lines'] as $leak) {
        expect($row)->not->toHaveKey($leak);
    }

    // Pinned as an exact key set, so a field ADDED later fails here rather than reaching the portal
    // unreviewed — the shape is a contract a second developer is building against.
    expect(array_keys($row))->toBe(['id', 'display_number', 'kind', 'academic_context', 'total', 'outstanding']);
});

it('carries ward identity as uuid and name ONLY', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0');

    expect(array_keys($row))->toBe(['student', 'invoices', 'account'])
        ->and(array_keys($row['student']))->toBe(['id', 'name'])
        ->and($row['student']['name'])->toBe('Ada Child');

    foreach (['date_of_birth', 'admission_number', 'current_class', 'gender', 'photo'] as $leak) {
        expect($row['student'])->not->toHaveKey($leak);
    }
});

it('carries the account position in the Money wire shape', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    // Overpay: 300000 billed, 350000 paid — the invoice settles and 50000 carries on the ACCOUNT,
    // where an invoice-only response would report the parent's position as zero.
    $invoice = ppf_invoice($school, $ward, 300000);
    ppf_pay($school, $invoice, 350000);

    $row = ppf_hit($school, $me)->assertOk()->json('data.0');

    expect($row['invoices'])->toBe([])
        ->and($row['account']['balance'])->toBe(['amount_minor' => -50000, 'currency' => 'NGN'])
        ->and($row['account']['available_credit'])->toBe(['amount_minor' => 50000, 'currency' => 'NGN']);
});

/*
|--------------------------------------------------------------------------
| 5 · The gate
|--------------------------------------------------------------------------
*/

it('refuses a user who is not a guardian', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    [$me] = ppf_guardian($school, [$ward]);

    // Control: the guardian is served, so the refusal below is about the caller and not the URL.
    ppf_hit($school, $me)->assertOk();

    $teacher = al_makeUser($school->id);
    setPermissionsTeamId($school->id);
    $teacher->assignRole('teacher');
    setPermissionsTeamId(null);
    $teacher->schools()->syncWithoutDetaching([$school->id]);

    ppf_hit($school, $teacher)->assertForbidden();
});

it('refuses an unauthenticated caller', function () {
    $school = al_makeSchool();

    test()->withSession(['school_id' => $school->id])
        ->getJson('/api/parent/finance/wards')
        ->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| 6 · The write-side authorisation helper
|--------------------------------------------------------------------------
*/

it('mayPay() is TRUE for a ward invoice and FALSE for another parent child invoice', function () {
    $school = al_makeSchool();
    $mine = ppf_student($school, 'Ada');
    $theirs = ppf_student($school, 'Zoe');

    $mineInvoice = ppf_invoice($school, $mine, 300000);
    $theirsInvoice = ppf_invoice($school, $theirs, 300000);

    [$me] = ppf_guardian($school, [$mine]);
    ppf_guardian($school, [$theirs]);

    ActiveSchool::runFor($school->id, function () use ($me, $mineInvoice, $theirsInvoice) {
        $authorisation = app(GuardianPaymentAuthorisation::class);

        expect($authorisation->mayPay($me, $mineInvoice))->toBeTrue()
            ->and($authorisation->mayPay($me, $theirsInvoice))->toBeFalse();
    });
});

it('mayPay() is FALSE for a user with no guardian row in the active school', function () {
    $school = al_makeSchool();
    $ward = ppf_student($school, 'Ada');
    $invoice = ppf_invoice($school, $ward, 300000);
    ppf_guardian($school, [$ward]);

    $stranger = al_makeUser($school->id);

    ActiveSchool::runFor($school->id, fn () => expect(
        app(GuardianPaymentAuthorisation::class)->mayPay($stranger, $invoice)
    )->toBeFalse());
});
