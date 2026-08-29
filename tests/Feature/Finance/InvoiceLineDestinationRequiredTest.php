<?php

use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Enums\InvoiceLineKind;
use App\Finance\Models\BankAccount;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\InvoiceLine;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use App\Support\Money;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * S11 commit 2 — a CHARGE line must record where its money is destined, and a REDUCTION line must
 * still be allowed not to.
 *
 * ── TWO LAYERS, AND THEY ARE NOT THE SAME CLAIM ──────────────────────────────────────────────────
 *
 * `finance_invoice_lines_destination_guard` (`2026_08_29_120000`) is the AUTHORITY: it makes the rule
 * true of a job, a raw insert, a migration or tinker. `GenerateInvoiceRequest::assertDestinationsChosen()`
 * is the SAME rule one layer up, and it exists for one reason — to say WHICH LINE.
 *
 * WHAT THE TRIGGER ALONE PRODUCES OVER HTTP IS A 500, MEASURED. GenerateInvoice's 1644 catch
 * (`isReductionGuardViolation`) matches only messages containing "discount policy", deliberately, so
 * this guard's SIGNAL is not caught and an uncaught 1644 falls through to a generic 500. Commenting
 * the pre-check out of both controller methods and re-running this file was measured at 500 on arms
 * (i) and (vi), not 422. So the pre-check is not softening an unattachable error the way U8 commit 3
 * did on the reduction path — it is standing between the bursar and a server error.
 *
 * So arm (i) asserts THE KEY, not merely a 422. A refusal satisfying a bare `assertStatus(422)` is
 * the failure this commit exists to prevent, which makes a status-only assertion here worse than no
 * assertion: it would read as covered.
 *
 * ── WHAT THE FIXTURE IS SHAPED TO MAKE IMPOSSIBLE ────────────────────────────────────────────────
 *
 *   · TWO accounts per School. With one, "the account the operator picked" and "the school's only
 *     account" are indistinguishable, and arm (ii) could pass against an implementation that
 *     defaulted the destination — which is precisely the fabrication `2026_08_10_120000` refused
 *     this column for.
 *   · Arm (vi) posts TWO bad lines at DIFFERENT indices with a GOOD line between them, so a
 *     pre-check that stopped at the first mistake, or one that keyed every error to line 0, fails.
 *     A single bad line cannot tell those apart from the correct behaviour.
 *   · Arm (iii) carries a charge line WITH a destination beside the reduction line without one, so
 *     the reduction's NULL is a decision rather than a column nothing ever writes.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/** A named ACTIVE bank account in $school. Two are needed per school — see this file's docblock. */
function ildr_account(School $school, string $label): BankAccount
{
    return BankAccount::withoutGlobalScopes()->create([
        'school_id' => $school->id,
        'label' => $label,
        'bank_name' => 'Test Bank',
        'account_number' => 'ILDR-'.$school->id.'-'.Str::random(8),
    ]);
}

/**
 * A School with two accounts, an admin holding generate + reduction.apply, and a billable enrollment.
 *
 * Its own helpers rather than InvoiceLineDestinationTest's `ild_*`: a Pest global function is only
 * defined once the file declaring it has been loaded, so running THIS file alone would hit
 * "Call to undefined function" against a helper that lives next door. Prefixed names, the same
 * discipline as the `sr_` / `rc_` fixtures.
 *
 * @return array{school: School, admin: User, enrollment: StudentCurriculum, accountA: BankAccount, accountB: BankAccount}
 */
function ildr_setup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);

    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'ildr_admin', 'guard_name' => 'web']);
    $abilities = ['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply'];
    foreach ($abilities as $ability) {
        Permission::firstOrCreate(['name' => $ability, 'guard_name' => 'web']);
    }
    $role->syncPermissions($abilities);
    $admin->assignRole('ildr_admin');
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $student = Student::factory()->create(['school_id' => $school->id]);
    $enrollment = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    return [
        'school' => $school,
        'admin' => $admin,
        'enrollment' => $enrollment,
        'accountA' => ildr_account($school, 'Account A'),
        'accountB' => ildr_account($school, 'Account B'),
    ];
}

function ildr_post($test, array $f, array $lines)
{
    return $test->actingAs($f['admin'])->withSession(['school_id' => $f['school']->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $f['enrollment']->uuid, 'lines' => $lines]);
}

/** An ACTIVE, non-approval-requiring policy, so a reduction line is legal to the reduction guard. */
function ildr_policy(School $school): DiscountPolicy
{
    return ActiveSchool::runFor($school->id, fn () => DiscountPolicy::create([
        'school_id' => $school->id, 'name' => 'Sibling', 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => false, 'status' => 'active',
    ]));
}

// ── (i) THE PRE-CHECK REFUSES, AND NAMES THE FIELD ───────────────────────────────────────────────

it('refuses a charge line with no destination BEFORE the insert, keyed to lines.0.bank_account_id', function () {
    $f = ildr_setup();

    $response = ildr_post($this, $f, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
    ])->assertStatus(422)
        // THE KEY, not the status. With the pre-check removed this request answers 500 (see the file
        // docblock), so a bare `assertStatus(422)` would already red — but the key is what the modal
        // needs and the key is therefore what is asserted: a refusal that named no field would still
        // leave the operator unable to see which of five lines is wrong.
        ->assertJsonValidationErrors('lines.0.bank_account_id');

    // And it is the ONLY error, so the arm cannot be satisfied by some other rule refusing first and
    // this key riding along.
    expect(array_keys($response->json('errors')))->toBe(['lines.0.bank_account_id']);

    // NOTHING WAS WRITTEN. "Before the insert" is a claim about the database, not about the status
    // code, and a pre-check that ran after the Action's transaction would still answer 422 here.
    expect(DB::table('finance_invoices')->count())->toBe(0)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(0);
});

// ── (ii) A CHARGE LINE WITH A DESTINATION IS ACCEPTED ────────────────────────────────────────────

it('accepts a charge line that names a destination, and stores the account the operator picked', function () {
    $f = ildr_setup();

    ildr_post($this, $f, [
        // THE SECOND account. With the first, "stored what was chosen" and "stored the school's head
        // account" are the same value and this arm would pass against a defaulting implementation.
        ['description' => 'Tuition', 'amount_minor' => 100000, 'bank_account_id' => $f['accountB']->uuid],
    ])->assertStatus(201);

    expect((int) DB::table('finance_invoice_lines')->value('bank_account_id'))->toBe($f['accountB']->id)
        ->and($f['accountB']->id)->not->toBe($f['accountA']->id);
});

// ── (iii) A REDUCTION LINE WITH NULL IS STILL ACCEPTED ───────────────────────────────────────────

it('accepts a reduction line with NO destination — a reduction sends money nowhere', function () {
    $f = ildr_setup();
    $policy = ildr_policy($f['school']);

    ildr_post($this, $f, [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'bank_account_id' => $f['accountA']->uuid],
        ['description' => 'Sibling discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(201);

    $rows = DB::table('finance_invoice_lines')->orderBy('id')->get(['kind', 'bank_account_id']);

    // BOTH HALVES. The reduction's null alone would also pass if the column were never written at
    // all; the charge beside it carrying A is what makes the null a decision rather than an absence.
    expect($rows->firstWhere('kind', 'discount')->bank_account_id)->toBeNull()
        ->and((int) $rows->firstWhere('kind', 'charge')->bank_account_id)->toBe($f['accountA']->id);
});

// ── (iv) THE TRIGGER, WITH THE PRE-CHECK OUT OF THE PATH ─────────────────────────────────────────

it('refuses a charge line with NULL at the DATABASE when the pre-check is bypassed', function () {
    $f = ildr_setup();

    // A legal invoice first, so the raw insert below has a parent and fails for its own reason
    // rather than on `invoice_id`.
    $invoice = ActiveSchool::runFor($f['school']->id, fn () => app(GenerateInvoice::class)->handle(
        $f['enrollment']->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo(100000), bankAccountId: $f['accountA']->id)],
        InvoiceKind::Scheduled,
    ));

    // A RAW INSERT — no model, no FormRequest, no Action. The pre-check is the REACHABLE path and
    // this is the backstop, which is exactly the write a job, a migration or tinker performs.
    $row = [
        'uuid' => (string) Str::uuid(),
        'school_id' => $f['school']->id,
        'invoice_id' => $invoice->id,
        'description' => 'Smuggled',
        'kind' => 'charge',
        'amount_minor' => 1,
        'amount_currency' => 'NGN',
        'bank_account_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    try {
        DB::table('finance_invoice_lines')->insert($row);
        $this->fail('The raw INSERT was not refused — finance_invoice_lines_destination_guard did not fire.');
    } catch (QueryException $e) {
        // 1644 is SIGNAL SQLSTATE '45000'. Asserted by DRIVER CODE and by the guard's own sentence,
        // because this table carries a second BEFORE INSERT trigger (the reduction guard) whose
        // refusals are also 1644 — a bare toThrow() cannot tell them apart.
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1644)
            ->and($e->getMessage())->toContain('A charge line must record the bank account its money is destined for.');
    }

    // THE SAME ROW WITH A DESTINATION IS ACCEPTED, which is what makes the refusal about the NULL
    // rather than about the row being malformed in some other way.
    expect(fn () => DB::table('finance_invoice_lines')->insert(
        [...$row, 'uuid' => (string) Str::uuid(), 'bank_account_id' => $f['accountB']->id],
    ))->not->toThrow(QueryException::class);

    // AND EVERY NON-CHARGE KIND WITH NULL IS ACCEPTED AT THE SAME LAYER. Without this the trigger
    // could be "no line may have a null destination" and every other arm would still pass — the
    // `kind` branch is the whole design and this is the only arm that crosses it at the database.
    //
    // EVERY OTHER KIND, NOT ONE OF THEM, and that is the cold review's finding rather than
    // thoroughness for its own sake. This arm crossed the branch with `discount` alone, and
    // `InvoiceLineKind` has THREE cases: a trigger body written `NEW.kind IN ('charge','waiver')`
    // — or `BINARY NEW.kind <> BINARY 'discount'` — refuses a WAIVER line with no destination while
    // passing this arm and arm (v) unchanged. That is precisely the case the migration docblock
    // declares must stay permitted, because whether a waiver inherits the account of the charge it
    // offsets is unmodelled. One value cannot distinguish "not a charge" from "this one other kind".
    //
    // DERIVED FROM THE ENUM, NOT FROM THE TRIGGER. The set is every case that is not `Charge`, so a
    // fourth kind added later is covered the day it exists rather than the day someone remembers
    // this file. That is independent of the thing under test — the enum does not know what the
    // trigger's branch says — so it is not a test restating its own subject.
    $policy = ildr_policy($f['school']);

    $nonCharge = array_values(array_filter(
        InvoiceLineKind::cases(),
        fn (InvoiceLineKind $kind) => $kind !== InvoiceLineKind::Charge,
    ));

    // THE LOOP'S OWN PRECONDITION. A single-value set would collapse this back to the arm the review
    // found, and a foreach over it would still be green — the degeneracy would be invisible.
    expect(count($nonCharge))->toBeGreaterThan(1);

    // try/catch AND NOT `->not->toThrow(QueryException::class, "…")`, because that reads the second
    // argument as the EXPECTED EXCEPTION MESSAGE, not as a description of the failure. Written that
    // way this loop stayed GREEN under the very mutation it exists to kill — the waiver WAS refused,
    // and the negated expectation passed because the thrown message did not contain the annotation.
    // Measured, and left as a comment rather than a scar: an instrument that agrees with itself
    // instead of with reality is how a mutation is reported dead while it is working.
    foreach ($nonCharge as $kind) {
        try {
            DB::table('finance_invoice_lines')->insert([
                ...$row,
                'uuid' => (string) Str::uuid(),
                'kind' => $kind->value,
                'amount_minor' => -1,
                'discount_policy_id' => $policy->id,
            ]);
        } catch (QueryException $e) {
            $this->fail(
                "A {$kind->value} line with no destination was refused at the database: {$e->getMessage()}. "
                .'2026_08_29_120000 requires a destination on CHARGE lines only — every other kind may '
                .'carry NULL, because a reduction sends money nowhere and whether a waiver inherits the '
                .'account of the charge it offsets is deliberately unmodelled.'
            );
        }
    }
});

// ── (v) HISTORY IS VALID, AND THE GUARD IS BEFORE INSERT ONLY ────────────────────────────────────

it('leaves a pre-column charge line with NULL readable and valid — the guard does not reach backwards', function () {
    $f = ildr_setup();

    // WRITTEN WITH THE GUARD TEMPORARILY ABSENT, because that is literally what "issued before the
    // guard existed" means. The table is append-only, so there is no legal insert that could be
    // edited into this shape afterwards; every other route to a historical row is closed. The guard
    // is restored before a single assertion runs, so everything below reads against a schema that
    // HAS it.
    $invoice = withoutDatabaseTrigger(
        'finance_invoice_lines_destination_guard',
        fn () => ActiveSchool::runFor($f['school']->id, fn () => app(GenerateInvoice::class)->handle(
            $f['enrollment']->uuid,
            [new InvoiceLineSpec('Legacy tuition', Money::fromKobo(100000))],
            InvoiceKind::Scheduled,
        )),
    );

    $line = ActiveSchool::runFor($f['school']->id, fn () => InvoiceLine::query()
        ->where('invoice_id', $invoice->id)->firstOrFail());

    expect($line->bank_account_id)->toBeNull()
        // READABLE, not merely present: the row still carries what was billed, and the relation
        // resolves to null rather than exploding on a non-null assumption.
        ->and($line->description)->toBe('Legacy tuition')
        ->and($line->amount->toKobo())->toBe(100000)
        ->and($line->bankAccount)->toBeNull();

    // AND OVER HTTP, because "readable" is a claim about the read path and not about the model.
    $studentUuid = (string) Student::query()->whereKey($f['enrollment']->student_id)->value('uuid');

    $this->actingAs($f['admin'])->withSession(['school_id' => $f['school']->id])
        ->getJson('/api/v1/finance/students/'.$studentUuid.'/invoices')
        ->assertStatus(200)
        ->assertJsonPath('invoices.0.lines.0.description', 'Legacy tuition');
});

it('carries the destination rule on a BEFORE INSERT trigger and NOTHING else on this table', function () {
    // THE MUTATION THIS ARM EXISTS TO KILL is "BEFORE INSERT" becoming "INSERT and UPDATE". An
    // UPDATE arm would retro-refuse every pre-column line the moment anything touched it, and it
    // cannot be caught behaviourally today because `finance_invoice_lines_no_update` denies UPDATE
    // outright — so the added arm would be dead code that reads like a live control and becomes a
    // live defect the day the append-only guard is relaxed for a repair path. The decision is
    // therefore pinned STRUCTURALLY, which is the only place it is visible.
    //
    // THE WHOLE SET, not a LIKE '%destination%' filter. A second trigger under any other name is the
    // same defect, and a filter chosen to match the thing it is guarding can only ever restate it.
    $triggers = collect(DB::select(
        "SELECT TRIGGER_NAME AS name, ACTION_TIMING AS timing, EVENT_MANIPULATION AS event
           FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'finance_invoice_lines'
          ORDER BY TRIGGER_NAME"
    ))->map(fn ($t) => $t->name.' '.$t->timing.' '.$t->event)->all();

    expect($triggers)->toBe([
        'finance_invoice_lines_destination_guard BEFORE INSERT',
        'finance_invoice_lines_no_delete BEFORE DELETE',
        'finance_invoice_lines_no_update BEFORE UPDATE',
        'finance_invoice_lines_reduction_guard BEFORE INSERT',
    ], 'A trigger on finance_invoice_lines was added, removed or re-timed. If that is a destination '
        .'rule on UPDATE, it is the change 2026_08_29_120000 argues against: every line issued before '
        .'the column existed carries NULL legitimately, and an UPDATE arm retro-refuses all of them.');
});

// ── (vi) TWO BAD LINES PRODUCE TWO KEYED ERRORS ──────────────────────────────────────────────────

it('reports EVERY charge line missing a destination, each keyed to its own wire index', function () {
    $f = ildr_setup();

    $response = ildr_post($this, $f, [
        ['description' => 'Tuition', 'amount_minor' => 100000],
        // A GOOD LINE BETWEEN THE TWO BAD ONES. Without it, "collects every bad line" and "collects a
        // contiguous run from index 0" are the same answer, and the indices below would be 0 and 1
        // whether the keys were real or invented.
        ['description' => 'Boarding', 'amount_minor' => 50000, 'bank_account_id' => $f['accountA']->uuid],
        ['description' => 'PTA levy', 'amount_minor' => 25000],
    ])->assertStatus(422);

    // TWO KEYS, AND THEY ARE 0 AND 2. A pre-check that threw on the first mistake would report one;
    // one that keyed errors to its own loop counter would report 0 and 1.
    expect(array_keys($response->json('errors')))
        ->toBe(['lines.0.bank_account_id', 'lines.2.bank_account_id']);

    expect(DB::table('finance_invoice_lines')->count())->toBe(0);
});

it('keys the error to the ORIGINAL wire index when lines arrive as a keyed object', function () {
    $f = ildr_setup();

    // The `lines` array posted with non-sequential keys. lineSpecs() array_values()s its result, so a
    // pre-check reading its own loop index would answer `lines.0` / `lines.1` — errors the form
    // cannot find, which is the defect the whole pre-check exists to avoid.
    $response = ildr_post($this, $f, [
        3 => ['description' => 'Tuition', 'amount_minor' => 100000],
        7 => ['description' => 'PTA levy', 'amount_minor' => 25000],
    ])->assertStatus(422);

    expect(array_keys($response->json('errors')))
        ->toBe(['lines.3.bank_account_id', 'lines.7.bank_account_id']);
});
