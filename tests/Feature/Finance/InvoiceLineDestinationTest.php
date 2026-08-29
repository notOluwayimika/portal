<?php

use App\Enums\TermStatusEnum;
use App\Finance\Actions\CreateFeeSchedule;
use App\Finance\Actions\GenerateInvoice;
use App\Finance\DTOs\InvoiceLineSpec;
use App\Finance\Enums\FeeScheduleStatus;
use App\Finance\Enums\InvoiceKind;
use App\Finance\Exceptions\LedgerImmutableException;
use App\Finance\Models\BankAccount;
use App\Finance\Models\DiscountPolicy;
use App\Finance\Models\FeeSchedule;
use App\Finance\Models\InvoiceLine;
use App\Finance\Services\AllocationProposal;
use App\Finance\Services\FeeScheduleLineMapper;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\Term;
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
 * S11 commit 1 — `finance_invoice_lines.bank_account_id`: where this line's money was destined, said
 * by the line itself instead of derived through a live join.
 *
 * ── WHAT THE FIXTURES ARE SHAPED TO MAKE IMPOSSIBLE ──────────────────────────────────────────────
 *
 * Every arm below could be passed by a WRONG implementation given a lazier fixture, so each one is
 * built so the property it names is the only thing that can produce the pass:
 *
 *   · TWO accounts per School, never one. With one account, "reads the school's only account",
 *     "reads the first account", and "reads the item's account" are indistinguishable — and so are
 *     the snapshot and the lookup, because there is nothing to move to.
 *   · TWO mandatory items pointing at DIFFERENT accounts. With both items on one account, a mapper
 *     that read the first item's account for every line would pass arm (i) unchanged.
 *   · The repoint in arm (ii) moves the item from account A to account B, so a LOOKUP implementation
 *     and a SNAPSHOT implementation land on genuinely different values rather than agreeing.
 *   · Arm (iii)'s manual line SELECTS THE SECOND account, so a resolution that ignored the uuid and
 *     took the first row still fails.
 *
 * ── ONE PREMISE IN THE BRIEF IS NARROWER THAN IT READS, AND IT IS RECORDED HERE ──────────────────
 *
 * The ticket and {@see AllocationProposal}'s docblock both say a fee item's
 * account "can be edited" after billing. MEASURED, IT CANNOT — not through the application.
 * `finance_fee_items_parent_state_guard_upd` refuses any UPDATE whose parent schedule is not a
 * `draft`, only `active` schedules are billable ({@see FeeScheduleStatus::billable()}),
 * and nothing returns an active schedule to draft (`RejectFeeScheduleChange` restores only
 * `pending_approval`). Arm (ii) proves that too, as its own assertion, rather than leaving it read.
 *
 * That does NOT make this column unnecessary, and the reason is worth stating so nobody "simplifies"
 * it back out:
 *
 *   1. THE LARGER HALF OF THE PROBLEM IS ABSENCE, NOT MUTATION. `fee_item_id` is nullable with NO
 *      foreign key, and every line the bursar's modal writes today has none. For those lines the
 *      lookup cannot answer at all — AllocationProposal reports `unrecorded` — and no amount of
 *      immutability fixes that.
 *   2. THE FREEZE IS A COINCIDENCE OF TWO INDEPENDENT RULES, not a stated one. The trigger's rule is
 *      "the parent must be a draft"; nothing anywhere ties it to "cited by an invoice". It holds
 *      only while `billable()` is exactly `[active]` — and that method's own docblock says the set
 *      MOVES — and while no correction path returns a schedule to draft. Either change turns a
 *      silent history rewrite back on, with no test failing, because no test asserts the two rules
 *      as one property. This is the same shape as the mapper's `isBillable` vs
 *      `where('status', 'active')` pair, which were "one rule, not two" in two docblocks and two
 *      rules that happened to agree in fact.
 *   3. THE DATABASE IS STILL REACHABLE. A migration, a raw UPDATE or tinker meets no such guard, and
 *      arm (ii) demonstrates precisely that: the repoint is only possible by moving the parent to
 *      draft first, which is a thing only the database can do here.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * A named ACTIVE bank account in $school.
 *
 * NOT `testBankAccountId()` — that helper is keyed on (school_id, 'TEST-'.$schoolId) and so returns
 * ONE account per school by construction. Every arm here needs two, and the one-account fixture is
 * exactly the degeneracy this file is written against.
 */
function ild_account(School $school, string $label): BankAccount
{
    return BankAccount::withoutGlobalScopes()->create([
        'school_id' => $school->id,
        'label' => $label,
        'bank_name' => 'Test Bank',
        'account_number' => 'ILD-'.$school->id.'-'.Str::random(8),
    ]);
}

/**
 * A School with two accounts, an admin holding generate + reduction.apply, and a billable enrollment.
 *
 * @return array{school: School, admin: User, enrollment: StudentCurriculum, accountA: BankAccount, accountB: BankAccount}
 */
function ild_setup(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create(['school_id' => $school->id]);

    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => 'ild_admin', 'guard_name' => 'web']);
    $abilities = ['finance.access', 'finance.invoice.generate', 'finance.invoice.reduction.apply'];
    foreach ($abilities as $ability) {
        Permission::firstOrCreate(['name' => $ability, 'guard_name' => 'web']);
    }
    $role->syncPermissions($abilities);
    $admin->assignRole('ild_admin');
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
        'accountA' => ild_account($school, 'Account A'),
        'accountB' => ild_account($school, 'Account B'),
    ];
}

/**
 * An ACTIVE fee schedule whose two mandatory items point at two DIFFERENT accounts.
 *
 * The status is moved by a raw write, the way the rest of this suite moves a lifecycle it is not the
 * subject of: CreateFeeSchedule always authors a draft, because the parent-state triggers admit item
 * inserts into nothing else.
 */
function ild_schedule(School $school, BankAccount $first, BankAccount $second): FeeSchedule
{
    $session = AcademicSession::create([
        'school_id' => $school->id, 'name' => '2026/2027', 'slug' => 'sess-'.Str::random(8), 'is_current' => true,
    ]);
    $term = Term::create([
        'academic_session_id' => $session->id, 'school_id' => $school->id, 'name' => 'First Term',
        'slug' => 'term-'.Str::random(8), 'order' => 1, 'start_date' => now()->subMonth(),
        'end_date' => now()->addMonths(2), 'status' => TermStatusEnum::ACTIVE->value,
    ]);
    $level = ClassLevel::create(['school_id' => $school->id, 'name' => 'JSS 1', 'order' => 1]);

    $schedule = ActiveSchool::runFor($school->id, fn () => app(CreateFeeSchedule::class)->handle(
        $term->id, $level->id, 'v1',
        [
            ['description' => 'Tuition', 'amount_minor' => 100000, 'sort_order' => 0, 'bank_account_id' => $first->uuid],
            ['description' => 'Transport', 'amount_minor' => 50000, 'sort_order' => 1, 'bank_account_id' => $second->uuid],
        ],
    ));

    DB::table('finance_fee_schedules')->where('id', $schedule->id)->update(['status' => 'active']);

    return $schedule->refresh();
}

function ild_post($test, School $school, User $admin, StudentCurriculum $enrollment, array $lines)
{
    return $test->actingAs($admin)->withSession(['school_id' => $school->id])
        ->postJson('/api/v1/finance/invoices', ['enrollment_id' => $enrollment->uuid, 'lines' => $lines]);
}

// ── (i) A SCHEDULE-DERIVED LINE CARRIES ITS FEE ITEM'S ACCOUNT ────────────────────────────────────

it('snapshots each schedule-derived line onto the account its OWN fee item names', function () {
    $f = ild_setup();
    $schedule = ild_schedule($f['school'], $f['accountA'], $f['accountB']);

    $specs = ActiveSchool::runFor(
        $f['school']->id,
        fn () => app(FeeScheduleLineMapper::class)->linesFor($schedule, $f['school']->id),
    );

    // PER LINE, NOT PER INVOICE. The two items name two different accounts, so an implementation
    // that resolved one destination for the whole schedule — the first item's, or the school's only
    // one — produces a pair that is equal here and fails.
    expect(array_map(fn ($spec) => $spec->bankAccountId, $specs))
        ->toBe([$f['accountA']->id, $f['accountB']->id]);

    $invoice = ActiveSchool::runFor(
        $f['school']->id,
        fn () => app(GenerateInvoice::class)->handle($f['enrollment']->uuid, $specs, InvoiceKind::Scheduled),
    );

    // And it survives the Action, which is a separate claim from the mapper producing it: the spec
    // travels through resolveDiscountability() and resolvePercentages() before anything is written.
    $stored = DB::table('finance_invoice_lines')->where('invoice_id', $invoice->id)
        ->orderBy('id')->pluck('bank_account_id')->all();

    expect($stored)->toBe([$f['accountA']->id, $f['accountB']->id]);
});

// ── (ii) THE ARM THIS WHOLE COMMIT EXISTS FOR ────────────────────────────────────────────────────

it('does NOT move a billed line when the fee item is repointed afterwards — it is a snapshot, not a lookup', function () {
    $f = ild_setup();
    $schedule = ild_schedule($f['school'], $f['accountA'], $f['accountB']);

    $invoice = ActiveSchool::runFor($f['school']->id, function () use ($f, $schedule) {
        $specs = app(FeeScheduleLineMapper::class)->linesFor($schedule, $f['school']->id);

        return app(GenerateInvoice::class)->handle($f['enrollment']->uuid, $specs, InvoiceKind::Scheduled);
    });

    $tuitionItemId = (int) DB::table('finance_fee_items')
        ->where('fee_schedule_id', $schedule->id)->where('description', 'Tuition')->value('id');

    // THE APPLICATION CANNOT DO THIS AT ALL, and that is asserted rather than assumed — see this
    // file's docblock, premise (2)/(3). `finance_fee_items_parent_state_guard_upd` refuses the
    // repoint while the parent is active, and an active schedule never returns to draft.
    expect(fn () => DB::table('finance_fee_items')->where('id', $tuitionItemId)
        ->update(['bank_account_id' => $f['accountB']->id]))
        ->toThrow(QueryException::class);

    // So the repoint is forced through the one door that is left: the parent goes to draft, the item
    // moves, the parent comes back. A migration or a raw session can do exactly this.
    DB::table('finance_fee_schedules')->where('id', $schedule->id)->update(['status' => 'draft']);
    DB::table('finance_fee_items')->where('id', $tuitionItemId)->update(['bank_account_id' => $f['accountB']->id]);
    DB::table('finance_fee_schedules')->where('id', $schedule->id)->update(['status' => 'active']);

    // THE MUTATION WAS APPLIED. Without this the arm is green whenever the repoint silently fails,
    // which is the failure mode that makes a mutation test agree with itself instead of with reality.
    expect((int) DB::table('finance_fee_items')->where('id', $tuitionItemId)->value('bank_account_id'))
        ->toBe($f['accountB']->id);

    // READ THROUGH THE MODEL, and that is what makes this arm able to fail on its own axis. A raw
    // column read can only ever say "the stored bytes did not change", which is a claim about the
    // append-only triggers, not about the destination being a snapshot. Reading the way the
    // application reads is what a LOOKUP implementation would have to satisfy — and cannot: an
    // accessor deriving `bank_account_id` through `fee_item_id`, which is exactly the shape
    // AllocationProposal uses today, resolves to B here while the snapshot resolves to A.
    $line = ActiveSchool::runFor($f['school']->id, fn () => InvoiceLine::query()
        ->where('invoice_id', $invoice->id)->where('description', 'Tuition')->firstOrFail());

    $raw = DB::table('finance_invoice_lines')
        ->where('invoice_id', $invoice->id)->where('description', 'Tuition')->first();

    expect((int) $line->bank_account_id)->toBe($f['accountA']->id)
        // The stored bytes as well as the read, so a model that answered A over a row holding B —
        // or the reverse — cannot pass. The two claims are separate and both are needed.
        ->and((int) $raw->bank_account_id)->toBe($f['accountA']->id)
        // THE FIXTURE'S OWN PRECONDITION, asserted rather than assumed: if the two accounts were the
        // same row, every implementation above would agree and this arm would prove nothing.
        ->and($f['accountA']->id)->not->toBe($f['accountB']->id)
        // The provenance still points at the item that moved, which is the whole reason the live
        // lookup could not be trusted: this id resolves to a row whose account is now B.
        ->and((int) $line->fee_item_id)->toBe($tuitionItemId);
});

// ── (iii) A MANUAL LINE CARRIES THE SELECTED ACCOUNT ─────────────────────────────────────────────

it('writes the account the operator SELECTED onto a manually entered charge line', function () {
    $f = ild_setup();

    // THE SECOND account, deliberately: a resolution that ignored the uuid and took the first row —
    // or the school's `inDisplayOrder()` head — passes with the first and fails with this.
    ild_post($this, $f['school'], $f['admin'], $f['enrollment'], [
        ['description' => 'Damaged textbook', 'amount_minor' => 25000, 'bank_account_id' => $f['accountB']->uuid],
    ])->assertStatus(201);

    expect((int) DB::table('finance_invoice_lines')->value('bank_account_id'))->toBe($f['accountB']->id)
        // No fee item behind it — this is the free-text line the live lookup could never answer for,
        // and it now states its destination anyway. That is the case the column exists for.
        ->and(DB::table('finance_invoice_lines')->value('fee_item_id'))->toBeNull();
});

it('refuses a bank_account_id belonging to ANOTHER School at the edge, indistinguishably from a nonexistent one', function () {
    $f = ild_setup();
    $foreign = ild_account(School::factory()->create(), 'Someone else’s account');

    $other = ild_post($this, $f['school'], $f['admin'], $f['enrollment'], [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'bank_account_id' => $foreign->uuid],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.0.bank_account_id');

    $absent = ild_post($this, $f['school'], $f['admin'], $f['enrollment'], [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'bank_account_id' => (string) Str::uuid()],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.0.bank_account_id');

    // THE SAME BYTES. Telling a caller that an id they cannot see nevertheless exists is the leak,
    // so the two refusals must not be tellable apart — the same property InvoiceWireIdsTest pins for
    // the other two wire ids.
    expect($other->json('errors'))->toBe($absent->json('errors'));

    expect(DB::table('finance_invoice_lines')->count())->toBe(0);
});

// ── (iv) A REDUCTION LINE CARRIES NULL, AND IS ACCEPTED ──────────────────────────────────────────

it('accepts a reduction line with NO destination and stores null — a reduction sends money nowhere', function () {
    $f = ild_setup();
    $policy = ActiveSchool::runFor($f['school']->id, fn () => DiscountPolicy::create([
        'school_id' => $f['school']->id, 'name' => 'Sibling', 'basis' => 'percent', 'percent' => 10,
        'requires_approval' => false, 'status' => 'active',
    ]));

    ild_post($this, $f['school'], $f['admin'], $f['enrollment'], [
        ['description' => 'Tuition', 'amount_minor' => 100000, 'bank_account_id' => $f['accountA']->uuid],
        ['description' => 'Sibling discount', 'amount_minor' => -10000, 'kind' => 'discount', 'discount_policy_id' => $policy->uuid],
    ])->assertStatus(201);

    $rows = DB::table('finance_invoice_lines')->orderBy('id')->get(['kind', 'bank_account_id']);

    // BOTH HALVES IN ONE ARM, on purpose. Asserting only the null would also pass if the column were
    // never written at all; the charge beside it carrying A is what makes the reduction's null a
    // decision rather than an absence.
    expect($rows->firstWhere('kind', 'charge')->bank_account_id)->toBe($f['accountA']->id)
        ->and($rows->firstWhere('kind', 'discount')->bank_account_id)->toBeNull();
});

// ── (v) THE COMPOSITE FOREIGN KEY, AT THE DATABASE ───────────────────────────────────────────────

it('refuses a line naming ANOTHER School’s account at the DATABASE, not merely at the edge', function () {
    $f = ild_setup();
    $foreign = ild_account(School::factory()->create(), 'Someone else’s account');

    $invoice = ActiveSchool::runFor($f['school']->id, fn () => app(GenerateInvoice::class)->handle(
        $f['enrollment']->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo(100000), bankAccountId: $f['accountA']->id)],
        InvoiceKind::Scheduled,
    ));

    // A RAW INSERT — no model, no scope, no FormRequest. This is the guarantee holding for a job, a
    // migration or tinker, which is the standing reason every control in this module lives at the
    // database. The single-column FK that this composite replaces would have ACCEPTED this row.
    $insert = fn () => DB::table('finance_invoice_lines')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $f['school']->id,
        'invoice_id' => $invoice->id,
        'description' => 'Smuggled',
        'kind' => 'charge',
        'amount_minor' => 1,
        'amount_currency' => 'NGN',
        'bank_account_id' => $foreign->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($insert)->toThrow(QueryException::class);

    try {
        $insert();
    } catch (QueryException $e) {
        // 1452 — "Cannot add or update a child row: a foreign key constraint fails". Asserted by
        // DRIVER CODE and by constraint NAME, because a 1452 from some other foreign key on this
        // row would satisfy a bare toThrow() while proving nothing about the composite.
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1452)
            ->and($e->getMessage())->toContain('finance_invoice_lines_bank_account_school_foreign');
    }

    // The SAME id under its OWN school is accepted, which is what makes the refusal above about the
    // PAIR rather than about the account being unusable.
    expect(fn () => DB::table('finance_invoice_lines')->insert([
        'uuid' => (string) Str::uuid(),
        'school_id' => $f['school']->id,
        'invoice_id' => $invoice->id,
        'description' => 'Legitimate',
        'kind' => 'charge',
        'amount_minor' => 1,
        'amount_currency' => 'NGN',
        'bank_account_id' => $f['accountB']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->not->toThrow(QueryException::class);
});

// ── (vi) HISTORY IS UNTOUCHED ────────────────────────────────────────────────────────────────────

it('leaves a line with NO recorded destination null and fully readable — there is no backfill', function () {
    $f = ild_setup();

    // A line written the way every line before this column was written: no destination supplied.
    // This is what every existing production row looks like, and NULL is its permanent, honest state.
    $invoice = ActiveSchool::runFor($f['school']->id, fn () => app(GenerateInvoice::class)->handle(
        $f['enrollment']->uuid,
        [new InvoiceLineSpec('Legacy tuition', Money::fromKobo(100000))],
        InvoiceKind::Scheduled,
    ));

    $line = ActiveSchool::runFor($f['school']->id, fn () => InvoiceLine::query()
        ->where('invoice_id', $invoice->id)->firstOrFail());

    expect($line->bank_account_id)->toBeNull()
        // READABLE, not merely present: the invoice still renders and the money is intact. A column
        // added with a bad default, or a relation that assumed non-null, breaks here.
        ->and($line->description)->toBe('Legacy tuition')
        ->and($line->amount->toKobo())->toBe(100000)
        ->and($line->bankAccount)->toBeNull();

    // AND OVER HTTP, because "readable" is a claim about the read path and not about the model. The
    // student-scoped list is the invoice read surface this platform has (there is no per-invoice show
    // route), and it serialises the lines.
    $studentUuid = (string) Student::query()->whereKey($f['enrollment']->student_id)->value('uuid');

    $this->actingAs($f['admin'])->withSession(['school_id' => $f['school']->id])
        ->getJson('/api/v1/finance/students/'.$studentUuid.'/invoices')
        ->assertStatus(200)
        ->assertJsonPath('invoices.0.lines.0.description', 'Legacy tuition');
});

// ── (vii) THE APPEND-ONLY GUARD STILL BITES, ON THE NEW COLUMN ───────────────────────────────────

it('refuses to UPDATE the destination after issue — at the model AND at the database', function () {
    $f = ild_setup();

    $invoice = ActiveSchool::runFor($f['school']->id, fn () => app(GenerateInvoice::class)->handle(
        $f['enrollment']->uuid,
        [new InvoiceLineSpec('Tuition', Money::fromKobo(100000), bankAccountId: $f['accountA']->id)],
        InvoiceKind::Scheduled,
    ));

    $line = ActiveSchool::runFor($f['school']->id, fn () => InvoiceLine::query()
        ->where('invoice_id', $invoice->id)->firstOrFail());

    // LAYER 1 — the AppendOnly trait, which throws on `updating` with no column list at all. That it
    // has no column list is why this commit added nothing to it, and this arm is what says so.
    expect(fn () => $line->update(['bank_account_id' => $f['accountB']->id]))
        ->toThrow(LedgerImmutableException::class);

    // LAYER 2 — `finance_invoice_lines_no_update`, reached by going around the model entirely. The
    // trigger is the guarantee; the trait is the fast, legible failure on the Eloquent path.
    try {
        DB::table('finance_invoice_lines')->where('id', $line->id)
            ->update(['bank_account_id' => $f['accountB']->id]);
        $this->fail('The raw UPDATE was not refused — finance_invoice_lines_no_update did not fire.');
    } catch (QueryException $e) {
        // 1644 is SIGNAL SQLSTATE '45000'. Asserted by code AND message, so a 1644 raised by some
        // other trigger on this table cannot satisfy the arm.
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1644)
            ->and($e->getMessage())->toContain('immutable snapshot');
    }

    // AND THE VALUE DID NOT MOVE. Two refusals that both left the row rewritten would be a worse
    // outcome than either failing, and neither exception alone proves the row is intact.
    expect((int) DB::table('finance_invoice_lines')->where('id', $line->id)->value('bank_account_id'))
        ->toBe($f['accountA']->id);
});
